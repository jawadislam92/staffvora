<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Product default: seed ONLY the global plan catalogue. Tenants get their
        // own owner, settings and default team from TenantProvisioner (signup /
        // tenant:create), so a fresh product install must not create any org-less
        // users or internal Sparking-Asia clients/profiles.
        $this->call([
            PlanSeeder::class,
        ]);

        // Internal-only seeders stay runnable on purpose, but only when asked for
        // explicitly — never on a fresh product install:
        //   php artisan db:seed --class=AdminUserSeeder      (env-gated admin)
        //   php artisan db:seed --class=ClientSeeder         (legacy agency demo clients)
        //   php artisan db:seed --class=UpworkProfileSeeder  (legacy agency demo profiles)
        //   php artisan db:seed --class=MonitoringSeeder     (global settings row)
        //   php artisan db:seed --class=DemoDataSeeder       (demo tenant data)
    }
}
