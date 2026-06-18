<?php

namespace Database\Seeders;

use App\Models\UpworkProfile;
use Illuminate\Database\Seeder;

class UpworkProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $trackers = config('workhours.trackers', []);

        foreach ($trackers as $tracker) {
            UpworkProfile::firstOrCreate(
                ['name' => $tracker],
                [
                    'name' => $tracker,
                    'is_active' => true,
                    'description' => 'Migrated from config',
                ]
            );
        }
    }
}
