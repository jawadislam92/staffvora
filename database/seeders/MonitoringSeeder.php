<?php

namespace Database\Seeders;

use App\Models\MonitoringSetting;
use Illuminate\Database\Seeder;

class MonitoringSeeder extends Seeder
{
    /**
     * Ensures a monitoring_settings row exists with sane defaults. Idempotent.
     *
     * Note: super_admin role already implicitly receives every permission
     * (see User::effectivePermissions), so no explicit assignment is needed
     * for the new monitoring.* permissions added in config/access.php.
     */
    public function run(): void
    {
        MonitoringSetting::current();
        $this->command?->info('Monitoring settings ensured.');
    }
}
