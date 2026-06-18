<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkHour;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleWorkHoursSeeder extends Seeder
{
    public function run()
    {
        // Get employees and clients
        $employees = User::where('role', 'member')->get();
        $clients = Client::take(5)->get();

        if ($employees->count() === 0 || $clients->count() === 0) {
            echo "No employees or clients found! Please create some first.\n";

            return;
        }

        // Clear existing work hours for the past week to avoid duplicates
        WorkHour::whereDate('date', '>=', Carbon::now()->subDays(7))->delete();

        // Create work hours for the past 7 days
        for ($i = 0; $i < 7; $i++) {
            $date = Carbon::now()->subDays($i);

            // Skip weekends for more realistic data
            if ($date->isWeekend()) {
                continue;
            }

            foreach ($employees as $employee) {
                // Each employee works on 1-3 clients per day
                $clientsToday = $clients->random(rand(1, 3));

                foreach ($clientsToday as $client) {
                    $hours = rand(2, 4); // 2-4 hours per client

                    WorkHour::create([
                        'user_id' => $employee->id,
                        'client_id' => $client->id,
                        'date' => $date->format('Y-m-d'),
                        'hours' => $hours,
                        'description' => "Sample work for {$client->name} on ".$date->format('M j'),
                    ]);
                }
            }
        }

        echo "Sample work hours created successfully!\n";
        echo 'Created data for '.$employees->count().' employees across '.$clients->count()." clients.\n";
    }
}
