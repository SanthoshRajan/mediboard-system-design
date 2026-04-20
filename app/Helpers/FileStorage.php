<?php

namespace App\Helpers;

use DB;
use Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class FileStorage
{

    private static function tenantPath($folder, $file = null)
    {
        $base = CLIENT_SLUG . '/' . $folder;
        return $file ? "$base/$file" : $base;
    }

    public static function avatarPath($file)
    {
        return Storage::disk('tenant_storage')
            ->path(self::tenantPath('avatars', $file));
    }

    public static function signaturePath($file)
    {
        return Storage::disk('tenant_storage')
            ->path(self::tenantPath('signatures', $file));
    }

    public static function reportPath($file)
    {
        return Storage::disk('tenant_storage')
            ->path(self::tenantPath('reports', $file));
    }

    public static function consentPath($file)
    {
        return Storage::disk('tenant_storage')
            ->path(self::tenantPath('consents', $file));
    }

    public static function uploadAvatar($file, $fileName)
    {
        $sizeBytes = $file->getSize();

        self::checkQuota($sizeBytes);

        Cache::forget("avatar_" . CLIENT_SLUG . "_" . $fileName);

        $path = Storage::disk('tenant_storage')
            ->putFileAs(self::tenantPath('avatars'), $file, $fileName);

        self::incrementUsage($sizeBytes);

        return $path;
    }

    public static function uploadAvatarRaw($fileName, $content)
    {
        Cache::forget("avatar_" . CLIENT_SLUG . "_" . $fileName);

        $sizeBytes = strlen($content);

        self::checkQuota($sizeBytes);

        $result = Storage::disk('tenant_storage')
                    ->put(self::tenantPath('avatars', $fileName), $content);

        self::incrementUsage($sizeBytes);

        return $result;
    }

    public static function exists($folder, $file)
    {
        return Storage::disk('tenant_storage')
            ->exists(self::tenantPath($folder, $file));
    }

    public static function read($folder, $file)
    {
        return Storage::disk('tenant_storage')
            ->get(self::tenantPath($folder, $file));
    }

    public static function put($folder, $file, $content)
    {
        $sizeBytes = strlen($content);

        self::checkQuota($sizeBytes);

        $result = Storage::disk('tenant_storage')
            ->put(self::tenantPath($folder, $file), $content);

        self::incrementUsage($sizeBytes);

        return $result;
    }

    public static function avatarBase64($file)
    {
        $cacheKey = "avatar_" . CLIENT_SLUG . "_" . $file;

        return Cache::remember($cacheKey, 3600, function () use ($file) {

            $path = self::avatarPath($file);

            if (!file_exists($path)) {
                return null;
            }

            return base64_encode(file_get_contents($path));
        });
    }

    private static function checkQuota($newFileBytes)
    {
        $tenant = self::getTenant();

        if (empty($tenant)) {
            Log::error('Tenant not found for quota check', ['CLIENT_GROUP_ID' => CLIENT_GROUP_ID]);
            throw new \Exception("Storage configuration error");
        }

        $used = $tenant['storage_used_mb'];
        $quota = $tenant['storage_quota_mb'];

        $newSizeMb = $newFileBytes / 1024 / 1024;

        if (($used + $newSizeMb) > $quota) {
            throw new \Exception("Storage quota exceeded");
        }
    }

    private static function incrementUsage($bytes)
    {
        Cache::forget("tenant_" . CLIENT_GROUP_ID);

        $mb = $bytes / 1024 / 1024;

        DB::connection('mysql_common')
            ->table('corporate_groups')
            ->where('id', CLIENT_GROUP_ID)
            ->whereRaw('storage_used_mb + ? <= storage_quota_mb', [$mb])
            ->increment('storage_used_mb', $mb);
    }

    private static function getTenant()
    {
        return Cache::remember("tenant_" . CLIENT_GROUP_ID, 300, function () {
            return DB::connection('mysql_common')
                ->table('corporate_groups')
                ->where('id', CLIENT_GROUP_ID)
                ->first();
        });
    }

    public static function delete($folder, $file)
    {
        $path = self::tenantPath($folder, $file);

        if (Storage::disk('tenant_storage')->exists($path)) {

            $size = Storage::disk('tenant_storage')->size($path) ?? 0;

            Storage::disk('tenant_storage')->delete($path);

            self::decrementUsage($size);
        }
    }

    private static function decrementUsage($bytes)
    {
        Cache::forget("tenant_" . CLIENT_GROUP_ID);

        $mb = $bytes / 1024 / 1024;

        DB::connection('mysql_common')
            ->table('corporate_groups')
            ->where('id', CLIENT_GROUP_ID)
            ->decrement('storage_used_mb', $mb);
    }

    public static function brandingPath($file)
    {
        return Storage::disk('tenant_storage')
            ->path(self::tenantPath('branding', $file));
    }

    public static function uploadBranding($file, $fileName)
    {
        $sizeBytes = $file->getSize();
        self::checkQuota($sizeBytes);

        $result = Storage::disk('tenant_storage')
            ->putFileAs(self::tenantPath('branding'), $file, $fileName);

        self::incrementUsage($sizeBytes);

        return $result;
    }

}