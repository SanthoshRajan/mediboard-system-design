<?php

namespace App\Services;

use DB;
use Log;
use Exception;
use Illuminate\Support\Facades\Redis;

/**
 * LabService
 *
 * Read-only service for lab catalog data - categories, sub-categories,
 * test definitions, and panel mappings.
 *
 * Key design decision - aggressive Redis caching:
 *   All catalog data is configuration-level (changes only when an admin
 *   updates the test catalog, not on every patient visit). It is fetched
 *   once from the DB, serialised to Redis, and served from cache for the
 *   remainder of the TTL window.
 *
 *   All Redis calls degrade gracefully - a cache miss falls through to DB;
 *   a Redis failure logs a warning and continues without cache.
 *
 * Data access pattern:
 *   - getLabXxxObj()          → raw array, cached
 *   - getLabXxxKeyPair()      → id-keyed map, derived from Obj
 *   - getLabXxxBy{Something}()→ grouped map, derived from Obj
 *
 *   Callers always go through the Obj methods so caching is centralised
 *   and the DB is queried at most once per TTL per tenant.
 */
class LabService
{
    /**
     * Fetch all active lab categories.
     *
     * Optionally filter to a single category by $id.
     * Returns null on DB failure so callers can distinguish empty vs error.
     */
    public static function getLabCategoriesObj(int $id = 0): array|null
    {
        $cache_key = CLIENT_SLUG . ':lab_categories';
        $data      = self::fromCache($cache_key);

        if (empty($data)) {
            try {
                $data = DB::connection('tenant')
                    ->table('lab_categories')
                    ->select(['id', 'name', 'description', 'icon', 'color_code'])
                    ->where('is_active', 1)
                    ->orderBy('display_order')
                    ->get()
                    ->toArray();

                if (empty($data)) {
                    Log::warning('No active lab categories found');
                    return null;
                }

                self::toCache($cache_key, $data);
            } catch (Exception $e) {
                Log::error('LabService::getLabCategoriesObj DB query failed', ['error' => $e->getMessage()]);
                return null;
            }
        }

        if (!empty($id)) {
            return array_values(array_filter($data, fn($row) => $row['id'] == $id));
        }

        return $data;
    }

    /** id → name map of lab categories. */
    public static function getLabCategoriesKeyPair(): array
    {
        $data = self::getLabCategoriesObj() ?? [];
        return array_column($data, 'name', 'id');
    }

    /**
     * Fetch all active lab sub-categories (tests and panels).
     *
     * is_panel = 0 → individual test group
     * is_panel = 1 → panel (composite of multiple sub-categories)
     *
     * Returns false on DB failure (distinguishable from empty result).
     */
    public static function getLabSubCategoriesObj(): array|bool
    {
        $cache_key = CLIENT_SLUG . ':lab_sub_categories';
        $data      = self::fromCache($cache_key);

        if (empty($data)) {
            try {
                $rows = DB::connection('tenant')
                    ->table('lab_sub_categories')
                    ->select(['id', 'name', 'is_panel', 'category_id'])
                    ->where('is_active', 1)
                    ->get();

                if ($rows->isEmpty()) {
                    Log::warning('No active lab sub-categories found');
                    return false;
                }

                $data = $rows->toArray();
                self::toCache($cache_key, $data);
            } catch (Exception $e) {
                Log::error('LabService::getLabSubCategoriesObj DB query failed', ['error' => $e->getMessage()]);
                return false;
            }
        }

        return $data;
    }

    /** id → row map of all sub-categories. */
    public static function getLabSubCategoriesKeyPair(): array
    {
        $data = self::getLabSubCategoriesObj() ?: [];
        return array_column($data, null, 'id');
    }

    /** category_id → [sub-categories] grouped map. */
    public static function getLabSubCategoriesByCategoryId(): array
    {
        $data   = self::getLabSubCategoriesObj() ?: [];
        $output = [];

        foreach ($data as $row) {
            $cat_id = $row['category_id'];
            unset($row['category_id']);
            $output[$cat_id][] = $row;
        }

        return $output;
    }

    /**
     * Fetch the full lab test catalog (test names, ref values, pricing).
     *
     * ref_value_structured stores machine-readable reference ranges as JSON,
     * allowing age/gender-specific normals without additional tables.
     *
     * Returns false on DB failure.
     */
    public static function getLabCatalogObj(): array|bool
    {
        $cache_key = CLIENT_SLUG . ':lab_test_catalog';
        $data      = self::fromCache($cache_key);

        if (empty($data)) {
            try {
                $rows = DB::connection('tenant')
                    ->table('lab_test_catalog')
                    ->select([
                        'id', 'name', 'sub_category_id', 'parent_id',
                        'price', 'ref_value', 'ref_value_structured',
                        'ref_units', 'rep_display', 'inv_display',
                    ])
                    ->where('is_active', 1)
                    ->orderBy('sub_category_id')
                    ->orderBy('name')
                    ->get();

                if ($rows->isEmpty()) {
                    Log::warning('No active lab test catalog entries found');
                    return false;
                }

                $data = $rows->toArray();
                self::toCache($cache_key, $data);
            } catch (Exception $e) {
                Log::error('LabService::getLabCatalogObj DB query failed', ['error' => $e->getMessage()]);
                return false;
            }
        }

        return $data;
    }

    /** id → full row map of catalog entries. */
    public static function getLabCatalogKeyPair(): array
    {
        $data = self::getLabCatalogObj() ?: [];
        return array_column($data, null, 'id');
    }

    /**
     * sub_category_id → [tests] grouped map.
     *
     * Optionally filter to a single sub-category by $id.
     */
    public static function getLabCatalogBySubCategoryId(?int $id = null): array
    {
        $data   = self::getLabCatalogObj() ?: [];
        $output = [];

        foreach ($data as $row) {
            $sub_id = $row['sub_category_id'];
            unset($row['sub_category_id']);
            $output[$sub_id][] = $row;
        }

        return $id !== null ? ($output[$id] ?? []) : $output;
    }

    /**
     * Fetch panel → component sub-category mappings.
     *
     * Panels are virtual sub-categories (is_panel = 1) that group multiple
     * real sub-categories. This mapping is used when building lab report
     * templates to expand a panel into its constituent test groups.
     *
     * Returns false on DB failure.
     */
    public static function getLabPanelSubcategories(): array|bool
    {
        $cache_key = CLIENT_SLUG . ':lab_panel_subcategories';
        $data      = self::fromCache($cache_key);

        if (empty($data)) {
            try {
                $rows = DB::connection('tenant')
                    ->table('lab_panel_subcategories')
                    ->select(['panel_subcategory_id', 'component_subcategory_id'])
                    ->where('is_active', 1)
                    ->orderBy('panel_subcategory_id')
                    ->orderBy('display_order')
                    ->get();

                if ($rows->isEmpty()) {
                    Log::warning('No lab panel subcategory mappings found');
                    return false;
                }

                $data = $rows->toArray();
                self::toCache($cache_key, $data);
            } catch (Exception $e) {
                Log::error('LabService::getLabPanelSubcategories DB query failed', ['error' => $e->getMessage()]);
                return false;
            }
        }

        return $data;
    }

    /**
     * panel_subcategory_id → [component_subcategory_ids] map.
     *
     * Used by the report engine to resolve a panel selection into the
     * individual sub-categories it contains.
     */
    public static function getPanelComponentMapping(): array
    {
        $data   = self::getLabPanelSubcategories() ?: [];
        $output = [];

        foreach ($data as $row) {
            $output[$row['panel_subcategory_id']][] = $row['component_subcategory_id'];
        }

        return $output;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private cache helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Read from Redis cache. Returns null on miss or failure.
     * Failures are logged as warnings - not errors - since the DB fallback handles them.
     */
    private static function fromCache(string $key): array|null
    {
        try {
            $cached = Redis::get($key);
            return $cached ? json_decode($cached, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (Exception $e) {
            Log::warning('Redis read failed - falling through to DB', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Write to Redis cache. Failures are swallowed - data was already served from DB.
     */
    private static function toCache(string $key, array $data): void
    {
        try {
            Redis::set($key, json_encode($data, JSON_THROW_ON_ERROR), 'EX', config('mediboard.cache.ttl_standard'));
        } catch (Exception $e) {
            Log::warning('Redis write failed - cache not populated', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}