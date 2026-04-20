<?php

namespace App\Jobs;

use DB;
use Log;
use Config;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CreateTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $groupId;
    public $tries = 3;
    public $timeout = 300;
    public $backoff = [30, 120]; // wait 30s before retry 1, 120s before retry 2

    // php artisan queue:work --queue=tenant-provisioning

    public function __construct($groupId)
    {
        $this->groupId = $groupId;
    }

    public function handle(): void
    {
        $group = DB::connection('mysql_common')
            ->table('corporate_groups')
            ->where('id', $this->groupId)
            ->first();

        if (!$group) {
            throw new Exception("Corporate group not found");
        }

        $tenant = strtolower($group['name']);
        $dbName = "{$tenant}_tenant";
        $basePath = Storage::disk('tenant_storage')->path($tenant);

        DB::purge('mysql_admin');
        Config::set('database.connections.mysql_admin.database', env('ADMIN_DB', 'template_db'));
        DB::reconnect('mysql_admin');

        try {
            $this->updateStatus('creating_db', $dbName, $basePath);

            $exists = DB::connection('mysql_admin')->select("SHOW DATABASES LIKE '{$dbName}'");

            if (empty($exists)) {

                DB::connection('mysql_admin')
                    ->statement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                Log::info("Tenant database created");
            }

            $this->updateStatus('creating_schema');

            DB::purge('mysql_admin');
            Config::set('database.connections.mysql_admin.database', $dbName);
            DB::reconnect('mysql_admin');

            $schemaPath = database_path(env('TENANT_SCHEMA_PATH', 'schema/tenant.sql'));

            if (!file_exists($schemaPath)) {
                throw new Exception("Schema file not found");
            }

            $sql = file_get_contents($schemaPath);
            $sql = ltrim($sql, "\xEF\xBB\xBF"); // strip UTF-8 BOM if present

            DB::connection('mysql_admin')->unprepared($sql);

            $this->updateStatus('creating_storage');

            $folders = ['avatars', 'reports', 'signatures', 'consents', 'branding'];

            foreach ($folders as $folder) {
                Storage::disk('tenant_storage')->makeDirectory("{$tenant}/{$folder}");
            }

            $this->updateStatus('completed');

            Log::info("Tenant setup completed", ['tenant' => $tenant]);

        } catch (Exception $e) {

            // ❗ Cleanup (only if failed early)
            try {
                DB::connection('mysql_admin')->statement("DROP DATABASE IF EXISTS `{$dbName}`");
            } catch (Exception $cleanupEx) {
                Log::error("Cleanup failed", ['error' => $cleanupEx->getMessage()]);
            }

            Storage::disk('tenant_storage')->deleteDirectory($tenant);

            DB::connection('mysql_common')->table('corporate_groups')
                ->where('id', $this->groupId)
                ->update([
                    'status' => 'failed',
                    'error' => 'Tenant setup failed'
                ]);

            Log::error("Tenant creation failed", [
                'tenant' => $tenant,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function updateStatus($status, $dbName = null, $path = null, $error = null)
    {
        $data = ['status' => $status];

        if ($dbName) {
            $data['db_name'] = $dbName;
        }

        if ($path) {
            $data['storage_path'] = $path;
        }

        if ($error) {
            $data['error'] = $error;
        }

        DB::connection('mysql_common')
            ->table('corporate_groups')
            ->where('id', $this->groupId)
            ->update($data);
    }
}