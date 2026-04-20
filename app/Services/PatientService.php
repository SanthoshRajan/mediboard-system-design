<?php

namespace App\Services;

use DB;
use Log;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;
use App\Services\LookupService;

/**
 * PatientService
 *
 * All patient-domain business logic lives here.
 * Controllers are kept thin - they validate input and delegate here.
 *
 * Key design decisions:
 *   - Multi-tenant: all queries use a tenant-scoped DB connection resolved
 *     at runtime by middleware. Common reference data (countries, provinces,
 *     cities) is read from a shared read-only connection.
 *   - Redis cache-aside: lookup data that rarely changes (history metadata,
 *     ref-number → patient_id mappings) is cached with a configurable TTL.
 *     All Redis calls are wrapped in try/catch so a cache failure degrades
 *     gracefully to a DB read rather than a 500.
 *   - Ref tags: facility-scoped patient identifiers (e.g. registration numbers,
 *     book numbers) are stored in a polymorphic ref_tags table keyed by meta_id,
 *     allowing each facility to maintain its own numbering scheme without
 *     schema changes.
 */
class PatientService
{
    /**
     * Fetch one or more patient profiles with joined location and history data.
     *
     * Lookup priority:
     *   1. Direct patient ID  - exact match, used for detail views
     *   2. Facility ref number - resolved via Redis cache → ref_tags table
     *   3. Search fields (name / dob / email / mobile) - OR-combined for
     *      flexible patient search at reception
     *
     * A single query joins:
     *   - ref_tags (×2, facility-scoped) for registration numbers
     *   - countries / provinces / cities (shared DB) for address display
     *   - patient_history (tenant DB) for medical background
     *
     * GROUP_CONCAT on ref_tags handles the edge case where a patient has
     * been registered under more than one ref number at the same facility.
     */
    public static function getPatientProfile(array $inputs): array
    {
        $output       = [];
        $where_clause = [];

        if (!empty($inputs['id'])) {
            $where_clause['p.id'] = $inputs['id'];
        } elseif (!empty($inputs['ref_id'])) {
            $where_clause['p.id'] = self::resolvePatientIdFromRef(
                $inputs['ref_id'],
                $inputs['facility_id']
            );
        } else {
            // Mobile search: check both primary and alternate fields
            if (!empty($inputs['mobile_id'])) {
                $inputs['alt_mobile_id'] = $inputs['mobile_id'];
            }

            foreach (['name', 'dob', 'email_id', 'mobile_id', 'alt_mobile_id'] as $field) {
                if (!empty($inputs[$field])) {
                    $where_clause[$field] = $inputs[$field];
                }
            }
        }

        $query = DB::connection('tenant')
            ->table('patients AS p')
            ->leftJoin('ref_tags AS tag1', function ($join) use ($inputs) {
                $join->on('tag1.patient_id', '=', 'p.id')
                     ->where('tag1.facility_id', '=', $inputs['facility_id'])
                     ->where('tag1.meta_id', '=', 1);    // primary registration ref
            })
            ->leftJoin('ref_tags AS tag2', function ($join) use ($inputs) {
                $join->on('tag2.patient_id', '=', 'p.id')
                     ->where('tag2.facility_id', '=', $inputs['facility_id'])
                     ->where('tag2.meta_id', '=', 2);    // secondary ref (e.g. book number)
            })
            ->leftJoin('shared.countries AS patient_country', 'patient_country.id', '=', 'p.country_id')
            ->leftJoin('shared.provinces AS patient_province', function ($join) {
                $join->on('patient_province.id', '=', 'p.province_id')
                     ->on('patient_province.country_id', '=', 'patient_country.id');
            })
            ->leftJoin('shared.cities AS patient_city', function ($join) {
                $join->on('patient_city.id', '=', 'p.city_id')
                     ->on('patient_city.province_id', '=', 'patient_province.id');
            })
            ->leftJoin('patient_history AS ph', function ($join) {
                $join->on('ph.patient_id', '=', 'p.id')
                     ->where('ph.is_active', '=', 1);
            })
            ->select([
                'p.id AS patient_id',
                'p.salutation AS patient_salutation',
                'p.name AS patient_name',
                'p.gender AS patient_gender',
                'p.dob AS patient_dob',
                'p.email_id AS patient_email',
                'p.mobile_id AS patient_mobile',
                'p.alt_mobile_id AS patient_alt_mobile',
                'p.occup_cat_id AS patient_occup_cat_id',
                'p.occup_desc AS patient_occup_desc',
                'p.address AS patient_address',
                'p.pincode AS patient_pincode',
                'p.photo AS patient_photo',
                DB::raw("GROUP_CONCAT(DISTINCT tag1.ref_ids ORDER BY tag1.facility_id SEPARATOR ', ') AS patient_ref1"),
                DB::raw("GROUP_CONCAT(DISTINCT tag2.ref_ids ORDER BY tag2.facility_id) AS patient_ref2"),
                'p.dr_ref AS patient_dr_ref',
                'p.membership AS patient_membership',
                'patient_city.id AS patient_city_id',
                'patient_city.name AS patient_city',
                'patient_province.name AS patient_province',
                'patient_province.id AS patient_province_id',
                'patient_province.code AS patient_province_code',
                'patient_country.name AS patient_country',
                'patient_country.id AS patient_country_id',
                'ph.details AS ph_details',
            ])
            ->where('p.is_active', 1)
            ->groupBy('p.id');

        // Search mode: OR across fields; detail mode: exact match by id
        if (!empty($where_clause) && empty($inputs['id'])) {
            $query->where(function ($q) use ($where_clause) {
                $first = true;
                foreach ($where_clause as $key => $value) {
                    $column  = in_array($key, ['name']) ? "p.{$key}" : $key;
                    $compare = in_array($key, ['name']) ? 'like' : '=';
                    $value   = in_array($key, ['name']) ? "%{$value}%" : $value;

                    $first
                        ? $q->where($column, $compare, $value)
                        : $q->orWhere($column, $compare, $value);

                    $first = false;
                }
            });
        } else {
            $query->where($where_clause);
        }

        $patients  = $query->orderBy('p.name')->get();
        $occup_cat = LookupService::getOccupationCategoryKeyPair();

        foreach ($patients as $patient) {
            $patient['patient_age'] = self::calculateAge($patient['patient_dob']);

            $patient['patient_membership'] = empty($patient['patient_membership'])
                ? []
                : json_decode($patient['patient_membership']);

            if (!empty($patient['ph_details'])) {
                $patient['ph_details'] = json_decode($patient['ph_details']);
            }

            // ref2 is omitted from list views; only included in single-patient detail
            $refs = array_filter([
                $patient['patient_ref1'] ?? null,
                (!empty($inputs['id']) ? $patient['patient_ref2'] : null) ?? null,
            ]);

            $patient['patient_ref_ids'] = implode(', ', $refs);

            $patient['patient_occup_cat'] = $occup_cat[$patient['patient_occup_cat_id']] ?? null;

            $patient['patient_dp'] = $patient['patient_photo'];
            unset($patient['patient_photo']);

            $output[] = $patient;
        }

        return $output;
    }

    /**
     * Create or update a patient record within a transaction.
     *
     * Also handles:
     *   - patient_history upsert (medical / habit / allergy / vaccine)
     *   - facility-scoped ref tag upserts (registration numbers)
     *
     * Photo upload is intentionally excluded from this transaction -
     * storage writes are handled post-commit by the controller so a
     * storage failure does not roll back the patient record.
     *
     * Returns the patient ID (new or existing).
     */
    public static function createOrUpdate(array $inputs): int
    {
        $accepted = [
            'salutation', 'name', 'gender', 'dob', 'occup_cat_id', 'occup_desc',
            'email_id', 'mobile_id', 'alt_mobile_id', 'address',
            'city_id', 'province_id', 'country_id', 'pincode',
            'dr_ref', 'membership',
        ];

        $patient_data = array_filter(
            array_intersect_key($inputs, array_flip($accepted)),
            fn($v) => $v !== null && $v !== ''
        );

        DB::connection('tenant')->beginTransaction();

        try {
            if (empty($inputs['id'])) {
                $patient_id = DB::connection('tenant')
                    ->table('patients')
                    ->insertGetId($patient_data);
            } else {
                $patient_id = (int) $inputs['id'];
                DB::connection('tenant')
                    ->table('patients')
                    ->where('id', $patient_id)
                    ->update($patient_data);
            }

            if (!empty($inputs['patient_history'])) {
                DB::connection('tenant')
                    ->table('patient_history')
                    ->updateOrInsert(
                        ['patient_id' => $patient_id, 'is_active' => 1],
                        ['details'    => json_encode($inputs['patient_history'])]
                    );
            }

            // Upsert facility-scoped ref tags
            foreach (['ref1' => 1, 'ref2' => 2] as $field => $meta_id) {
                if (!empty($inputs[$field])) {
                    DB::connection('tenant')
                        ->table('ref_tags')
                        ->updateOrInsert(
                            [
                                'facility_id' => $inputs['facility_id'],
                                'patient_id'  => $patient_id,
                                'meta_id'     => $meta_id,
                            ],
                            ['ref_ids' => $inputs[$field]]
                        );
                }
            }

            DB::connection('tenant')->commit();
        } catch (\Exception $e) {
            DB::connection('tenant')->rollBack();
            throw $e;
        }

        return $patient_id;
    }

    /**
     * Update patient photo filename after successful storage write.
     * Separated from createOrUpdate so storage failures don't trigger rollback.
     */
    public static function updatePhoto(int $patient_id, string $file_name): void
    {
        DB::connection('tenant')
            ->table('patients')
            ->where('id', $patient_id)
            ->update(['photo' => $file_name]);
    }

    /**
     * Soft-delete a patient. Hard deletes are not supported -
     * all patient data is retained for clinical audit purposes.
     */
    public static function deactivatePatient(int $patient_id): int
    {
        return DB::connection('tenant')
            ->table('patients')
            ->where('id', $patient_id)
            ->update(['is_active' => 0]);
    }

    /**
     * Calculate patient age as a human-readable string (e.g. "34y 7m").
     *
     * Accepts an optional $end_date to calculate age at a historical point
     * in time - used in clinical records where age-at-consultation matters.
     *
     * Returns null for missing, zero, or sentinel dates (0000-00-00).
     */
    public static function calculateAge(?string $dob, ?string $end_date = null): ?string
    {
        $sentinel = ['0000-00-00', '1900-00-00'];

        if (empty($dob) || in_array($dob, $sentinel)) {
            return null;
        }

        $dob = substr($dob, 0, 10);

        if (in_array($dob, $sentinel)) {
            return null;
        }

        $reference = (!empty($end_date) && !in_array($end_date, $sentinel))
            ? Carbon::createFromFormat('Y-m-d', $end_date)
            : Carbon::now();

        return $reference->diff(Carbon::createFromFormat('Y-m-d', $dob))->format('%yy %mm');
    }

    /**
     * Resolve a facility ref number to a patient ID.
     *
     * Uses a Redis cache-aside pattern:
     *   1. Check Redis (TTL-bounded, tenant-namespaced key)
     *   2. On miss: query ref_tags, populate cache, return result
     *   3. On Redis failure: log and fall through to DB - no exception thrown
     *
     * This is on the hot path (every patient lookup by ref number at
     * reception), so the cache hit rate matters for perceived performance.
     */
    private static function resolvePatientIdFromRef(string $ref_no, int $facility_id): int
    {
        $ref_no    = trim($ref_no);
        $cache_key = 'tenant:ref:' . $ref_no;   // namespaced per tenant at runtime

        try {
            $cached = Redis::get($cache_key);
            if (!empty($cached)) {
                return (int) $cached;
            }
        } catch (Exception $e) {
            Log::warning('Redis unavailable in resolvePatientIdFromRef - falling through to DB', [
                'error' => $e->getMessage(),
            ]);
        }

        $tag = DB::connection('tenant')
            ->table('ref_tags')
            ->where(['facility_id' => $facility_id, 'meta_id' => 1])
            ->where('ref_ids', 'like', "%{$ref_no}%")
            ->value('patient_id');

        if (empty($tag)) {
            return 0;
        }

        try {
            Redis::set($cache_key, $tag, 'EX', config('mediboard.cache.ttl_standard'));
        } catch (Exception $e) {
            Log::warning('Redis set failed in resolvePatientIdFromRef - continuing without cache', [
                'error' => $e->getMessage(),
            ]);
        }

        return (int) $tag;
    }

    /**
     * Fetch patient history metadata (field definitions for the history form).
     *
     * This is configuration-level data that changes very infrequently,
     * making it a good candidate for aggressive caching. A cache miss
     * reads from the patient_history_meta table and repopulates Redis.
     *
     * Returns false on hard failure so callers can handle gracefully.
     */
    public static function getPatientHistoryMeta(): array|bool
    {
        $cache_key = 'tenant:patient_history_meta';

        try {
            $cached = Redis::get($cache_key);
            if (!empty($cached)) {
                return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (Exception $e) {
            Log::warning('Redis unavailable in getPatientHistoryMeta - falling through to DB', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $row = DB::connection('tenant')
                ->table('patient_history_meta')
                ->where('is_active', 1)
                ->value('details');

            if (empty($row)) {
                Log::warning('No active patient history meta found in DB');
                return false;
            }

            $data = json_decode($row, true, 512, JSON_THROW_ON_ERROR);

            Redis::set(
                $cache_key,
                json_encode($data, JSON_THROW_ON_ERROR),
                'EX',
                config('mediboard.cache.ttl_standard')
            );

            return $data;
        } catch (Exception $e) {
            Log::error('Failed to fetch patient history metadata', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}