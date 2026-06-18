<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\UpworkProfile;
use App\Models\User;
use App\Models\WorkHour;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'Demo@12345';

    public function run(): void
    {
        DB::transaction(function () {
            $profiles = $this->createProfiles();
            $clients = $this->createClients($profiles);
            $users = $this->createUsers();

            $this->createWorkHours($users, $clients, $profiles);
            $this->createAttendance($users);
        });

        $this->command?->info('Demo data created: 3 users, 6 clients, 4 profiles, 36 work entries, and attendance history.');
        $this->command?->line('Demo password: '.self::PASSWORD);
    }

    private function createProfiles(): array
    {
        $definitions = [
            ['name' => 'Sparking Asia - Web', 'email' => 'web.demo@sparkingasia.test', 'description' => 'Web application and dashboard projects.'],
            ['name' => 'Sparking Asia - Mobile', 'email' => 'mobile.demo@sparkingasia.test', 'description' => 'Mobile application and API projects.'],
            ['name' => 'Sparking Asia - Design', 'email' => 'design.demo@sparkingasia.test', 'description' => 'UI, UX, and product design projects.'],
            ['name' => 'Sparking Asia - Support', 'email' => 'support.demo@sparkingasia.test', 'description' => 'Maintenance and operational support projects.'],
        ];

        return collect($definitions)->mapWithKeys(function (array $definition) {
            $profile = UpworkProfile::updateOrCreate(
                ['name' => $definition['name']],
                [...$definition, 'is_active' => true]
            );

            return [$profile->name => $profile];
        })->all();
    }

    private function createClients(array $profiles): array
    {
        $definitions = [
            [
                'name' => 'Northstar Analytics',
                'work_type' => 'tracker_manual',
                'tags' => ['analytics', 'dashboard'],
                'profiles' => ['Sparking Asia - Web'],
            ],
            [
                'name' => 'Cedar Health',
                'work_type' => 'tracker_manual',
                'tags' => ['healthcare', 'portal'],
                'profiles' => ['Sparking Asia - Web', 'Sparking Asia - Design'],
            ],
            [
                'name' => 'Orbit Commerce',
                'work_type' => 'fixed',
                'tags' => ['ecommerce', 'mobile'],
                'profiles' => ['Sparking Asia - Mobile'],
            ],
            [
                'name' => 'Harbor Logistics',
                'work_type' => 'tracker_manual',
                'tags' => ['logistics', 'api'],
                'profiles' => ['Sparking Asia - Web', 'Sparking Asia - Support'],
            ],
            [
                'name' => 'Brightline Studio',
                'work_type' => 'fixed',
                'tags' => ['branding', 'design'],
                'profiles' => ['Sparking Asia - Design'],
            ],
            [
                'name' => 'Local Operations',
                'work_type' => 'outside_of_upwork',
                'tags' => ['local', 'operations'],
                'profiles' => [],
            ],
        ];

        return collect($definitions)->mapWithKeys(function (array $definition) use ($profiles) {
            $profileIds = collect($definition['profiles'])
                ->map(fn (string $name) => $profiles[$name]->id)
                ->all();

            $client = Client::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'work_type' => $definition['work_type'],
                    'tags' => $definition['tags'],
                    'upwork_profile_id' => $profileIds[0] ?? null,
                ]
            );
            $client->upworkProfiles()->sync($profileIds);

            return [$client->name => $client];
        })->all();
    }

    private function createUsers(): array
    {
        $definitions = [
            [
                'name' => 'Ayesha Khan',
                'email' => 'ayesha.demo@example.com',
                'designation' => 'Morning Shift',
            ],
            [
                'name' => 'Bilal Ahmed',
                'email' => 'bilal.demo@example.com',
                'designation' => 'Evening Shift',
            ],
            [
                'name' => 'Sara Ali',
                'email' => 'sara.demo@example.com',
                'designation' => 'Flexible Shift',
            ],
        ];

        return collect($definitions)->mapWithKeys(function (array $definition) {
            $user = User::updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'member',
                    'permissions' => [],
                    'designation' => $definition['designation'],
                ]
            );

            return [$user->email => $user];
        })->all();
    }

    private function createWorkHours(array $users, array $clients, array $profiles): void
    {
        $clientList = array_values($clients);
        $descriptions = [
            'Implemented dashboard improvements and verified responsive behavior.',
            'Reviewed client feedback and completed the requested revisions.',
            'Built API integration and tested error handling.',
            'Updated user interface components and resolved visual issues.',
            'Prepared project documentation and deployment notes.',
            'Investigated reported defects and shipped fixes.',
        ];

        foreach (array_values($users) as $userIndex => $user) {
            WorkHour::where('user_id', $user->id)->delete();

            for ($entryIndex = 0; $entryIndex < 12; $entryIndex++) {
                $client = $clientList[($entryIndex + $userIndex) % count($clientList)];
                $date = Carbon::today('Asia/Karachi')->subDays($entryIndex);
                $workType = match ($client->work_type) {
                    'fixed' => 'fixed',
                    'outside_of_upwork' => 'outside_of_upwork',
                    default => $entryIndex % 2 === 0 ? 'tracker' : 'manual',
                };
                $clientProfileNames = $client->upworkProfiles()->pluck('name')->all();
                $tracker = $workType === 'outside_of_upwork'
                    ? null
                    : $clientProfileNames[($entryIndex + $userIndex) % count($clientProfileNames)];

                WorkHour::create([
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'date' => $date->toDateString(),
                    'hours' => [2.5, 3, 3.5, 4, 4.5][$entryIndex % 5],
                    'description' => $descriptions[($entryIndex + $userIndex) % count($descriptions)],
                    'work_type' => $workType,
                    'tracker' => $tracker,
                ]);
            }
        }
    }

    private function createAttendance(array $users): void
    {
        $today = Carbon::today('Asia/Karachi');
        $historyDates = collect(range(1, 10))
            ->map(fn (int $daysAgo) => $today->copy()->subDays($daysAgo))
            ->reject(fn (Carbon $date) => $date->isWeekend())
            ->take(6);

        foreach (array_values($users) as $userIndex => $user) {
            TimeEntry::where('user_id', $user->id)->delete();

            foreach ($historyDates as $dayIndex => $date) {
                $clockIn = $date->copy()->setTime(9 + $userIndex, 5 + ($dayIndex * 3));
                $breakStart = $clockIn->copy()->addHours(4);
                $breakEnd = $breakStart->copy()->addMinutes(35 + ($userIndex * 5));
                $clockOut = $clockIn->copy()->addHours(8)->addMinutes(30);

                $this->createAttendanceEntry($user, 'clock_in', $clockIn, 'Started scheduled shift');
                $this->createAttendanceEntry($user, 'break_start', $breakStart, 'Lunch break');
                $this->createAttendanceEntry($user, 'break_end', $breakEnd, 'Returned from lunch');
                $this->createAttendanceEntry($user, 'clock_out', $clockOut, 'Completed scheduled shift');
            }
        }

        $now = Carbon::now('Asia/Karachi');
        $todayUsers = array_values($users);

        $this->createAttendanceEntry($todayUsers[0], 'clock_in', $now->copy()->subHours(8), 'Started morning shift');
        $this->createAttendanceEntry($todayUsers[0], 'break_start', $now->copy()->subHours(4), 'Lunch break');
        $this->createAttendanceEntry($todayUsers[0], 'break_end', $now->copy()->subHours(3)->subMinutes(25), 'Returned from lunch');
        $this->createAttendanceEntry($todayUsers[0], 'clock_out', $now->copy()->subMinutes(20), 'Finished for today');

        $this->createAttendanceEntry($todayUsers[1], 'clock_in', $now->copy()->subHours(6), 'Started evening shift');
        $this->createAttendanceEntry($todayUsers[1], 'break_start', $now->copy()->subHours(2), 'Short break');
        $this->createAttendanceEntry($todayUsers[1], 'break_end', $now->copy()->subHours(1)->subMinutes(30), 'Back to work');

        $this->createAttendanceEntry($todayUsers[2], 'clock_in', $now->copy()->subHours(5), 'Started flexible shift');
        $this->createAttendanceEntry($todayUsers[2], 'break_start', $now->copy()->subMinutes(30), 'Current break');
    }

    private function createAttendanceEntry(User $user, string $type, Carbon $timestamp, string $notes): void
    {
        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => $type,
            'action_timestamp' => $timestamp,
            'action_date' => $timestamp->toDateString(),
            'action_time' => $timestamp->toTimeString(),
            'notes' => $notes,
        ]);
    }
}
