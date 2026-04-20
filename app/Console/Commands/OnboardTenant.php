<?php

namespace App\Console\Commands;

use DB;
use Log;
use Exception;
use App\Jobs\CreateTenantJob;
use Illuminate\Console\Command;

/**
 * OnboardTenant - Artisan command to provision a new tenant.
 *
 * Multi-tenant onboarding flow:
 *   1. Validate tenant name and facility name inputs
 *   2. Check for duplicate tenant / database
 *   3. Insert corporate group and facility records into the shared DB
 *   4. Dispatch CreateTenantJob to the provisioning queue
 *      (async: creates tenant DB, runs schema, sets up storage)
 *
 * Usage:
 *   php artisan tenant:onboard {name} --facility="{Facility Full Name}"
 *
 * Design decision - async dispatch:
 *   DB creation and schema migration are expensive operations. Dispatching
 *   to a dedicated 'tenant-provisioning' queue keeps the command fast and
 *   allows the job to be retried independently if it fails mid-way.
 *   The corporate_group record is written synchronously first so the job
 *   always has a valid group_id to work with.
 *
 * See: docs/QUEUE_ARCHITECTURE.md
 */
class OnboardTenant extends Command
{
    protected $signature = 'tenant:onboard
                            {name : Tenant name - alphanumeric only, becomes DB prefix}
                            {--facility= : Facility full name (defaults to ucfirst of name)}';

    protected $description = 'Onboard a new tenant and dispatch async provisioning job';

    public function handle(): int
    {
        $name         = trim($this->argument('name'));
        $facilityName = $this->option('facility') ?? ucfirst($name);
        $nameRegex    = '/^[a-zA-Z0-9]+$/';

        // ── Input validation ──────────────────────────────────────────────
        if (!preg_match($nameRegex, $name)) {
            $this->error('Invalid tenant name. Only alphanumeric characters are allowed.');
            return 1;
        }

        if (strlen($name) < 3) {
            $this->error('Tenant name must be at least 3 characters.');
            return 1;
        }

        if ($facilityName && !preg_match($nameRegex, str_replace(' ', '', $facilityName))) {
            $this->error('Invalid facility name. Only alphanumeric characters are allowed.');
            return 1;
        }

        $name    = strtolower($name);
        $db_name = "{$name}_tenant";

        try {
            $this->info("🚀 Onboarding tenant: {$name}");

            // ── Duplicate checks ──────────────────────────────────────────
            $existing = DB::connection('shared')
                ->table('corporate_groups')
                ->where('name', $name)
                ->exists();

            if ($existing) {
                throw new Exception("Tenant '{$name}' already exists.");
            }

            $db_exists = DB::connection('admin')
                ->select("SHOW DATABASES LIKE ?", [$db_name]);

            if (!empty($db_exists)) {
                throw new Exception("Tenant database '{$db_name}' already exists.");
            }

            // ── Shared DB records ─────────────────────────────────────────
            // Written synchronously so CreateTenantJob always has a valid group_id
            $group_id = DB::connection('shared')
                ->table('corporate_groups')
                ->insertGetId(['name' => $name]);

            $this->info("✅ Corporate group created (ID: {$group_id})");

            DB::connection('shared')
                ->table('facilities')
                ->insert([
                    'name'      => $name,
                    'full_name' => $facilityName,
                    'group_id'  => $group_id,
                    'type'      => 'clinic',
                ]);

            $this->info("🏥 Facility record created");

            // ── Async provisioning ────────────────────────────────────────
            // CreateTenantJob handles: DB creation, schema migration, storage setup
            dispatch(new CreateTenantJob($group_id))->onQueue('tenant-provisioning');

            $this->info("📦 Provisioning job dispatched to queue");
            $this->info("🎉 Tenant onboarding initiated - provisioning running in background.");

        } catch (Exception $e) {
            $this->error("❌ Failed: " . $e->getMessage());
            Log::error('OnboardTenant failed', [
                'tenant' => $name,
                'error'  => $e->getMessage(),
            ]);
            return 1;
        }

        return 0;
    }
}