<?php

namespace App\Services;

use DB;
use Log;
use Config;
use Storage;
use Exception;
use Illuminate\Support\Facades\Redis;

/**
 * FacilityService
 *
 * Two responsibilities:
 *   1. Tenant resolution - identify the facility from a request slug and
 *      configure the per-tenant DB connection for the lifetime of the request.
 *   2. Facility data access - fetch and cache facility profile data used
 *      throughout the request (name, address, logo, group etc.).
 *
 * Key design decisions:
 *   - Cache-aside with Redis: facility data is looked up once per TTL window.
 *     Dual-keyed (by name AND by id) so both middleware and service-layer
 *     callers hit the cache regardless of which identifier they hold.
 *   - Runtime DB switching: the 'tenant' connection starts with no database
 *     set. configureTenant() selects the correct per-client database and
 *     calls DB::purge() + DB::reconnect() to apply it cleanly.
 *   - Constants: COMMON_DB, CLIENT_DB etc. are defined once per request by
 *     configureTenant() and used throughout the service layer for cross-DB joins.
 *
 * See: docs/TENANT_RESOLUTION.md
 */
class FacilityService
{
    /**
     * Validate a facility slug and return its full profile.
     *
     * Called by ValidateFacility middleware on every inbound request.
     * Dual-writes to Redis under both the name key and the id key so
     * subsequent calls by facility_id also hit cache.
     *
     * Returns false if the facility is not found or inactive.
     */
    public static function validateFacility(string $facility_name): array|bool
    {
        $facility_name = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($facility_name));
        $cache_key     = $facility_name . '_info';

        try {
            $cached = Redis::get($cache_key);
            if ($cached) {
                return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (Exception $e) {
            Log::warning('Redis unavailable in validateFacility - falling through to DB', [
                'facility' => $facility_name,
                'error'    => $e->getMessage(),
            ]);
        }

        $facility = self::fetchFacilityFromDb(['fac.name' => $facility_name]);

        if (empty($facility['id'])) {
            return false;
        }

        $ttl    = config('mediboard.cache.ttl_standard');
        $id_key = 'facility_' . $facility['id'] . '_info';
        $json   = json_encode($facility, JSON_THROW_ON_ERROR);

        // Dual-key write: name key + id key - both resolve to the same data
        foreach ([$cache_key, $id_key] as $key) {
            try {
                Redis::set($key, $json, 'EX', $ttl);
            } catch (Exception $e) {
                Log::warning('Redis write failed in validateFacility', [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $facility;
    }

    /**
     * Fetch facility profile by ID.
     *
     * Used by service-layer callers (e.g. ConsultationService) that already
     * have a facility_id but need display data (name, logo, address, etc.).
     */
    public static function getFacilityInfo(int $facility_id): array|bool
    {
        $cache_key = 'facility_' . $facility_id . '_info';

        try {
            $cached = Redis::get($cache_key);
            if ($cached) {
                return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (Exception $e) {
            Log::warning('Redis unavailable in getFacilityInfo - falling through to DB', [
                'facility_id' => $facility_id,
                'error'       => $e->getMessage(),
            ]);
        }

        $facility = self::fetchFacilityFromDb(['fac.id' => $facility_id]);

        if (empty($facility)) {
            return false;
        }

        try {
            Redis::set($cache_key, json_encode($facility, JSON_THROW_ON_ERROR), 'EX', config('mediboard.cache.ttl_standard'));
        } catch (Exception $e) {
            Log::warning('Redis write failed in getFacilityInfo', [
                'facility_id' => $facility_id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $facility;
    }

    /**
     * Return facility data re-keyed for use as a merge array in consultation
     * and report responses (prefixed keys expected by the frontend).
     */
    public static function getFacilityInfoKeyPair(int $facility_id): array
    {
        $data = self::getFacilityInfo($facility_id);

        if (empty($data)) {
            return [];
        }

        // Map internal DB keys → frontend-facing response keys
        $key_map = [
            'facility_prefix'       => 'prefix',
            'facility_name'         => 'full_name',
            'group_id'              => 'group_id',
            'facility_type'         => 'type',
            'facility_group_name'   => 'group_name',
            'facility_address'      => 'address',
            'facility_city'         => 'city',
            'facility_state'        => 'province',
            'facility_state_code'   => 'province_code',
            'facility_pincode'      => 'pincode',
            'facility_header_image' => 'header_image',
            'facility_logo'         => 'logo',
            'facility_email'        => 'email_id',
            'facility_phone_num'    => 'phone_num',
        ];

        $output = [];
        foreach ($key_map as $response_key => $data_key) {
            $output[$response_key] = $data[$data_key] ?? '';
        }

        return $output;
    }

    /**
     * Configure the tenant DB connection for the current request.
     *
     * Called once per request by ValidateFacility middleware after the
     * facility is identified. Does the following:
     *   1. Derives the per-client database name from the group slug
     *   2. Defines PHP constants (CLIENT_DB, CLIENT_ID, CLIENT_SLUG, etc.)
     *      used across the service layer for cross-database joins
     *   3. Purges and reconnects the 'tenant' DB connection if the database
     *      has changed (handles multiple tenants in a single worker process)
     *
     * Constants are defined once per process; on reconnect the Config is
     * updated so Laravel's query builder targets the correct database.
     *
     * See: docs/TENANT_RESOLUTION.md
     */
    public static function configureTenant(int $facility_id): array
    {
        $facility = self::getFacilityInfo($facility_id);

        if (empty($facility)) {
            throw new \RuntimeException("Facility {$facility_id} not found");
        }

        if (empty($facility['group_name'])) {
            throw new \RuntimeException("Facility {$facility_id} has no associated group");
        }

        // Derive tenant DB name from group slug - sanitised to prevent injection
        $tenant_db = preg_replace('/[^a-zA-Z0-9_]/', '', $facility['group_name'] . '_tenant');

        $constants = [
            'SHARED_DB'       => 'shared',
            'CLIENT_DB'       => $tenant_db,
            'CLIENT_ID'       => $facility_id,
            'CLIENT_GROUP_ID' => $facility['group_id'],
            'CLIENT_SLUG'     => $facility['group_name'],
            'FACILITY_NAME'   => $facility['facility_name'],
            'FACILITY_PREFIX' => $facility['prefix'],
        ];

        foreach ($constants as $name => $value) {
            defined($name) || define($name, $value);
        }

        // Only purge/reconnect if the target database has actually changed -
        // avoids unnecessary connection overhead on same-tenant requests
        if (Config::get('database.connections.tenant.database') !== $tenant_db) {
            DB::purge('tenant');
            Config::set('database.connections.tenant.database', $tenant_db);
            DB::reconnect('tenant');
            Log::info('Tenant DB connection switched', ['database' => $tenant_db]);
        }

        return $facility;
    }

    /**
     * Upload or reset a facility logo to S3.
     *
     * Accepts a base64-encoded PNG data URI. If the image data is missing,
     * resets to the default demo logo via S3 copy.
     *
     * Returns an md5 hash of the uploaded file (used as a cache-buster),
     * or false on failure.
     */
    public static function uploadLogo(string $image, string $client_prefix): string|bool
    {
        $image_parts     = explode(',', $image);
        $logo_path       = 'logos/logo_' . $client_prefix . '.png';
        $default_logo    = 'logos/logo_default.png';

        if (!empty($image_parts[1])) {
            try {
                $tmp_path = '/tmp/' . uniqid('logo_', true);
                $resource = imagecreatefromstring(base64_decode($image_parts[1]));

                imagealphablending($resource, false);
                imagesavealpha($resource, true);
                imagepng($resource, $tmp_path);
                imagedestroy($resource);

                Storage::disk('s3')->put($logo_path, file_get_contents($tmp_path));
                $hash = md5_file($tmp_path);
                unlink($tmp_path);

                return $hash;
            } catch (Exception $e) {
                Log::error('Logo upload failed', ['error' => $e->getMessage()]);
                return false;
            }
        }

        // No image supplied - reset to default logo
        try {
            if (Storage::disk('s3')->exists($logo_path)) {
                Storage::disk('s3')->delete($logo_path);
            }
            Storage::disk('s3')->copy($default_logo, $logo_path);
            return uniqid();
        } catch (Exception $e) {
            Log::error('Logo reset failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Execute the facility DB query against the shared connection.
     *
     * Joins corporate_groups (for group name), provinces, and cities
     * to assemble a complete facility profile in a single round-trip.
     *
     * Private - all callers go through validateFacility() or getFacilityInfo()
     * which handle caching, so this is always a cache-miss path.
     */
    private static function fetchFacilityFromDb(array $filter): array|null
    {
        try {
            $row = DB::connection('shared')
                ->table('facilities AS fac')
                ->join('corporate_groups AS cg', 'cg.id', '=', 'fac.group_id')
                ->join('provinces AS f_pr', 'f_pr.id', '=', 'fac.province_id')
                ->join('cities AS f_ct', function ($join) {
                    $join->on('f_ct.id', '=', 'fac.city_id')
                         ->on('f_ct.province_id', '=', 'f_pr.id');
                })
                ->where($filter)
                ->where('fac.is_active', 1)
                ->select([
                    'fac.id',
                    'fac.name',
                    'fac.prefix',
                    'fac.full_name',
                    'fac.group_id',
                    'cg.name AS group_name',
                    'fac.type',
                    'fac.address',
                    'f_ct.name AS city',
                    'f_pr.name AS province',
                    'f_pr.code AS province_code',
                    'fac.pincode',
                    'fac.header_image',
                    'fac.logo',
                    'fac.email_id',
                    'fac.phone_num',
                    'fac.is_active',
                    DB::raw("CONCAT(fac.address, ', ', f_ct.name, ' - ', fac.pincode, ', ', f_pr.code) AS full_address"),
                ])
                ->first();

            if (empty($row['group_name'])) {
                Log::error('Facility has no associated group', ['filter' => $filter]);
                return null;
            }

            $row['group_name']    = strtolower($row['group_name']);
            $row['facility_name'] = strtolower($row['name']);

            return $row;

        } catch (Exception $e) {
            Log::error('DB query failed in fetchFacilityFromDb', [
                'filter' => $filter,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }
}