<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\User;
use App\Models\WorkHour;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Per-user daily hour sums for the last six months, loaded with a single
     * GROUP BY query. Every chart and stat on both dashboards is an aggregate
     * over this dataset; before this preload the admin dashboard issued one
     * SUM query per employee per day/week/month bucket (~400 queries).
     *
     * @var array<int, array<string, float>> [userId][Y-m-d] => hours
     */
    private array $userDateSums = [];

    /** @var array<string, float> [Y-m-d] => hours across all users */
    private array $dateSums = [];

    private bool $sumsLoaded = false;

    private ?Collection $members = null;

    public function index()
    {
        $user = auth()->user();

        if ($user->hasPermission('dashboard.view_team')) {
            return $this->adminDashboard();
        } else {
            return $this->employeeDashboard();
        }
    }

    private function employeeDashboard()
    {
        $user = auth()->user();
        $analytics = $this->getEmployeeAnalytics($user->id);

        return Inertia::render('Dashboard', [
            'userRole' => 'member',
            'analytics' => $analytics,
            'stats' => $this->getEmployeeStats($user->id),
        ]);
    }

    private function adminDashboard()
    {
        $employees = $this->members();
        $analytics = $this->getAdminAnalytics();

        return Inertia::render('Dashboard', [
            'userRole' => 'team',
            'analytics' => $analytics,
            'employees' => $employees,
            'stats' => $this->getAdminStats(),
        ]);
    }

    private function members(): Collection
    {
        return $this->members ??= User::where('role', 'member')->get();
    }

    private function loadSums(): void
    {
        if ($this->sumsLoaded) {
            return;
        }

        $from = Carbon::today()->subMonths(6)->startOfMonth()->format('Y-m-d');

        WorkHour::where('date', '>=', $from)
            ->groupBy('user_id', 'date')
            ->get([DB::raw('user_id'), DB::raw('date as d'), DB::raw('SUM(hours) as h')])
            ->each(function ($row) {
                $date = substr((string) $row->d, 0, 10);
                $hours = (float) $row->h;
                $this->userDateSums[$row->user_id][$date] = $hours;
                $this->dateSums[$date] = ($this->dateSums[$date] ?? 0) + $hours;
            });

        $this->sumsLoaded = true;
    }

    private function userHoursOn(int $userId, Carbon|string $date): float
    {
        $this->loadSums();
        $key = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        return $this->userDateSums[$userId][$key] ?? 0.0;
    }

    private function userHoursBetween(int $userId, Carbon $start, Carbon $end): float
    {
        $this->loadSums();
        $sum = 0.0;

        foreach ($this->userDateSums[$userId] ?? [] as $date => $hours) {
            if ($date >= $start->format('Y-m-d') && $date <= $end->format('Y-m-d')) {
                $sum += $hours;
            }
        }

        return $sum;
    }

    private function teamHoursOn(Carbon|string $date): float
    {
        $this->loadSums();
        $key = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        return $this->dateSums[$key] ?? 0.0;
    }

    private function teamHoursBetween(Carbon $start, Carbon $end): float
    {
        $this->loadSums();
        $sum = 0.0;

        foreach ($this->dateSums as $date => $hours) {
            if ($date >= $start->format('Y-m-d') && $date <= $end->format('Y-m-d')) {
                $sum += $hours;
            }
        }

        return $sum;
    }

    private function getEmployeeAnalytics($userId)
    {
        return [
            'today' => [
                'hourly' => $this->getTodayHourlyData($userId),
            ],
            'week' => [
                'daily' => $this->getWeekDailyData($userId),
            ],
            'month' => [
                'weekly' => $this->getMonthWeeklyData($userId),
            ],
            'clientDistribution' => $this->getClientDistribution($userId),
            'metrics' => $this->getEmployeeMetrics($userId),
            'summary' => [
                'today' => $this->formatHours($this->getTodayHours($userId)),
                'week' => $this->formatHours($this->getWeekHours($userId)),
                'month' => $this->formatHours($this->getMonthHours($userId)),
            ],
        ];
    }

    private function getAdminAnalytics()
    {
        $employees = $this->members();

        // Team data for different time periods
        $teamData = [
            'daily' => $this->getTeamDailyData(),
            'weekly' => $this->getTeamWeeklyData(),
            'monthly' => $this->getTeamMonthlyData(),
        ];

        // Individual employee data
        $employeeData = [];
        foreach ($employees as $employee) {
            $employeeData[$employee->id] = [
                'daily' => $this->getEmployeeDailyData($employee->id),
                'weekly' => $this->getEmployeeWeeklyData($employee->id),
                'monthly' => $this->getEmployeeMonthlyData($employee->id),
            ];
        }

        return [
            'teamData' => $teamData,
            'employeeData' => $employeeData,
            'employeeComparison' => $this->getEmployeeComparison(),
            'topPerformers' => $this->getTopPerformers(),
            'summary' => [
                'totalToday' => $this->formatHours($this->getTotalTeamHoursToday()),
                'activeEmployees' => $this->getActiveEmployeesToday(),
                'averageHours' => $this->formatHours($this->getAverageEmployeeHours()),
                'teamEfficiency' => $this->getTeamEfficiency(),
            ],
        ];
    }

    private function getTodayHourlyData($userId)
    {
        // Get today's work entries and create hourly breakdown
        $hours = range(8, 18); // 8 AM to 6 PM
        $labels = array_map(fn ($h) => sprintf('%02d:00', $h), $hours);

        $todayHours = $this->userHoursOn($userId, Carbon::today());

        // Distribute hours across the day (simplified)
        $data = array_fill(0, count($hours), 0);
        if ($todayHours > 0) {
            $hoursPerSlot = $todayHours / min(8, $todayHours); // Spread across 8 hours max
            for ($i = 0; $i < min(8, $todayHours); $i++) {
                $data[$i] = round($hoursPerSlot, 1);
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    private function getWeekDailyData($userId)
    {
        $weekStart = Carbon::today()->startOfWeek(MonitoringSetting::weekStartDay());
        $days = [];
        $data = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $days[] = $date->format('D');
            $data[] = round($this->userHoursOn($userId, $date), 1);
        }

        return [
            'labels' => $days,
            'data' => $data,
        ];
    }

    private function getMonthWeeklyData($userId)
    {
        $monthStart = Carbon::today()->startOfMonth();
        $weeks = [];
        $data = [];

        $currentWeekStart = $monthStart->copy()->startOfWeek(MonitoringSetting::weekStartDay());
        $weekNumber = 1;

        while ($currentWeekStart->month <= Carbon::today()->month && $weekNumber <= 5) {
            $weekEnd = $currentWeekStart->copy()->endOfWeek(MonitoringSetting::weekEndDay());
            $weeks[] = "Week {$weekNumber}";
            $data[] = round($this->userHoursBetween($userId, $currentWeekStart, $weekEnd), 1);

            $currentWeekStart->addWeek();
            $weekNumber++;
        }

        return [
            'labels' => $weeks,
            'data' => $data,
        ];
    }

    private function getClientDistribution($userId)
    {
        $monthStart = Carbon::today()->startOfMonth();

        $clientHours = WorkHour::where('user_id', $userId)
            ->whereBetween('date', [$monthStart->format('Y-m-d'), Carbon::today()->format('Y-m-d')])
            ->join('clients', 'work_hours.client_id', '=', 'clients.id')
            ->select('clients.name', DB::raw('SUM(work_hours.hours) as total_hours'))
            ->groupBy('clients.id', 'clients.name')
            ->orderBy('total_hours', 'desc')
            ->take(6)
            ->get();

        return [
            'labels' => $clientHours->pluck('name')->toArray(),
            'data' => $clientHours->pluck('total_hours')->map(fn ($h) => round($h, 1))->toArray(),
        ];
    }

    private function getEmployeeMetrics($userId)
    {
        $monthStart = Carbon::today()->startOfMonth();
        $today = Carbon::today();

        // Average daily hours
        $totalHours = $this->userHoursBetween($userId, $monthStart, $today);
        $workDays = $this->getWorkDaysInMonth($monthStart, $today);
        $avgDailyHours = $workDays > 0 ? round($totalHours / $workDays, 1) : 0;

        // Total clients
        $totalClients = WorkHour::where('user_id', $userId)
            ->whereBetween('date', [$monthStart->format('Y-m-d'), $today->format('Y-m-d')])
            ->distinct('client_id')
            ->count('client_id');

        // Consistency (days worked vs work days)
        $daysWorked = $this->daysWorkedBetween($userId, $monthStart, $today);
        $consistency = $workDays > 0 ? round(($daysWorked / $workDays) * 100) : 0;

        // Productivity (hours vs expected hours)
        $expectedHours = $workDays * 8; // Assuming 8 hours per day
        $productivity = $expectedHours > 0 ? round(($totalHours / $expectedHours) * 100) : 0;

        return [
            'avgDailyHours' => $avgDailyHours,
            'totalClients' => $totalClients,
            'consistency' => $consistency,
            'productivity' => min(100, $productivity), // Cap at 100%
        ];
    }

    private function daysWorkedBetween(int $userId, Carbon $start, Carbon $end): int
    {
        $this->loadSums();
        $count = 0;

        foreach ($this->userDateSums[$userId] ?? [] as $date => $hours) {
            if ($hours > 0 && $date >= $start->format('Y-m-d') && $date <= $end->format('Y-m-d')) {
                $count++;
            }
        }

        return $count;
    }

    private function getTeamDailyData()
    {
        $days = [];
        $totalHours = [];
        $averageHours = [];
        $employeeCount = $this->members()->count();

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $days[] = $date->format('M j');

            $dayTotal = $this->teamHoursOn($date);
            $totalHours[] = round($dayTotal, 1);
            $averageHours[] = $employeeCount > 0 ? round($dayTotal / $employeeCount, 1) : 0;
        }

        return [
            'labels' => $days,
            'totalHours' => $totalHours,
            'averageHours' => $averageHours,
        ];
    }

    private function getTeamWeeklyData()
    {
        $weeks = [];
        $data = [];

        for ($i = 3; $i >= 0; $i--) {
            $weekStart = Carbon::today()->subWeeks($i)->startOfWeek(MonitoringSetting::weekStartDay());
            $weekEnd = $weekStart->copy()->endOfWeek(MonitoringSetting::weekEndDay());
            $weeks[] = $weekStart->format('M j').'-'.$weekEnd->format('j');
            $data[] = round($this->teamHoursBetween($weekStart, $weekEnd), 1);
        }

        return [
            'labels' => $weeks,
            'data' => $data,
        ];
    }

    private function getTeamMonthlyData()
    {
        $months = [];
        $data = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::today()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $months[] = $monthStart->format('M Y');
            $data[] = round($this->teamHoursBetween($monthStart, $monthEnd), 1);
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    private function getEmployeeComparison()
    {
        $monthStart = Carbon::today()->startOfMonth();
        $today = Carbon::today();

        $employeeHours = $this->members()
            ->map(fn (User $employee) => [
                'name' => $employee->name,
                'total' => $this->userHoursBetween($employee->id, $monthStart, $today),
            ])
            ->sortByDesc('total')
            ->values();

        return [
            'labels' => $employeeHours->pluck('name')->toArray(),
            'data' => $employeeHours->pluck('total')->map(fn ($h) => round($h, 1))->toArray(),
        ];
    }

    private function getTopPerformers()
    {
        $monthStart = Carbon::today()->startOfMonth();
        $today = Carbon::today();
        $workDays = $this->getWorkDaysInMonth($monthStart, $today);
        $expectedHours = $workDays * 8;

        return $this->members()
            ->map(function (User $employee) use ($monthStart, $today, $expectedHours) {
                $hours = $this->userHoursBetween($employee->id, $monthStart, $today);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'designation' => $employee->designation ?: 'Employee',
                    'hoursThisMonth' => round($hours, 1),
                    'efficiency' => $expectedHours > 0 ? min(100, round(($hours / $expectedHours) * 100)) : 0,
                ];
            })
            ->sortByDesc('hoursThisMonth')
            ->take(6)
            ->values();
    }

    // Helper methods for getting individual metrics
    private function getTodayHours($userId)
    {
        return $this->userHoursOn($userId, Carbon::today());
    }

    private function getWeekHours($userId)
    {
        $weekStart = Carbon::today()->startOfWeek(MonitoringSetting::weekStartDay());
        $weekEnd = Carbon::today()->endOfWeek(MonitoringSetting::weekEndDay());

        return $this->userHoursBetween($userId, $weekStart, $weekEnd);
    }

    private function getMonthHours($userId)
    {
        return $this->userHoursBetween($userId, Carbon::today()->startOfMonth(), Carbon::today());
    }

    private function getTotalTeamHoursToday()
    {
        return $this->teamHoursOn(Carbon::today());
    }

    private function getActiveEmployeesToday()
    {
        $this->loadSums();
        $today = Carbon::today()->format('Y-m-d');

        return count(array_filter(
            $this->userDateSums,
            fn (array $dates) => ($dates[$today] ?? 0) > 0
        ));
    }

    private function getAverageEmployeeHours()
    {
        $totalHours = $this->teamHoursBetween(Carbon::today()->startOfMonth(), Carbon::today());
        $employeeCount = $this->members()->count();

        return $employeeCount > 0 ? $totalHours / $employeeCount : 0;
    }

    private function getTeamEfficiency()
    {
        $monthStart = Carbon::today()->startOfMonth();
        $today = Carbon::today();
        $workDays = $this->getWorkDaysInMonth($monthStart, $today);
        $employeeCount = $this->members()->count();
        $expectedTotalHours = $workDays * $employeeCount * 8;

        $actualTotalHours = $this->teamHoursBetween($monthStart, $today);

        return $expectedTotalHours > 0 ? round(($actualTotalHours / $expectedTotalHours) * 100) : 0;
    }

    // Helper methods for individual employee data (for admin view)
    private function getEmployeeDailyData($employeeId)
    {
        $days = [];
        $data = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $days[] = $date->format('M j');
            $data[] = round($this->userHoursOn($employeeId, $date), 1);
        }

        return [
            'labels' => $days,
            'data' => $data,
        ];
    }

    private function getEmployeeWeeklyData($employeeId)
    {
        $weeks = [];
        $data = [];

        for ($i = 3; $i >= 0; $i--) {
            $weekStart = Carbon::today()->subWeeks($i)->startOfWeek(MonitoringSetting::weekStartDay());
            $weekEnd = $weekStart->copy()->endOfWeek(MonitoringSetting::weekEndDay());
            $weeks[] = $weekStart->format('M j').'-'.$weekEnd->format('j');
            $data[] = round($this->userHoursBetween($employeeId, $weekStart, $weekEnd), 1);
        }

        return [
            'labels' => $weeks,
            'data' => $data,
        ];
    }

    private function getEmployeeMonthlyData($employeeId)
    {
        $months = [];
        $data = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::today()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $months[] = $monthStart->format('M Y');
            $data[] = round($this->userHoursBetween($employeeId, $monthStart, $monthEnd), 1);
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    // Legacy methods for basic stats
    private function getEmployeeStats($userId)
    {
        $today = Carbon::today();
        $weekStart = Carbon::today()->startOfWeek(MonitoringSetting::weekStartDay());
        $weekEnd = Carbon::today()->endOfWeek(MonitoringSetting::weekEndDay());
        $monthStart = Carbon::today()->startOfMonth();

        $thisWeekHours = $this->userHoursBetween($userId, $weekStart, $weekEnd);

        $thisMonthClients = WorkHour::where('user_id', $userId)
            ->whereBetween('date', [$monthStart->format('Y-m-d'), $today->format('Y-m-d')])
            ->distinct('client_id')
            ->count('client_id');

        $workDaysThisMonth = $this->getWorkDaysInMonth($monthStart, $today);
        $daysWithHours = $this->daysWorkedBetween($userId, $monthStart, $today);

        $efficiency = $workDaysThisMonth > 0 ? round(($daysWithHours / $workDaysThisMonth) * 100) : 0;

        return [
            'weekHours' => [
                'value' => $this->formatHours($thisWeekHours),
                'label' => 'This Week',
            ],
            'activeClients' => [
                'value' => $thisMonthClients,
                'label' => 'Active Clients',
            ],
            'efficiency' => [
                'value' => "{$efficiency}%",
                'label' => 'Efficiency',
            ],
        ];
    }

    private function getAdminStats()
    {
        $today = Carbon::today();
        $weekStart = Carbon::today()->startOfWeek(MonitoringSetting::weekStartDay());
        $monthStart = Carbon::today()->startOfMonth();

        $totalHoursToday = $this->teamHoursOn($today);
        $activeEmployees = $this->members()->count();
        $totalClients = Client::count();
        $teamEfficiency = $this->getTeamEfficiency();

        // Work Hours Report Statistics
        $totalHoursThisWeek = $this->teamHoursBetween($weekStart, $today);

        $totalEntriesThisMonth = WorkHour::whereBetween('date', [
            $monthStart->format('Y-m-d'),
            $today->format('Y-m-d'),
        ])->count();

        $activeUsersToday = $this->getActiveEmployeesToday();

        $activeClientsThisMonth = WorkHour::whereBetween('date', [
            $monthStart->format('Y-m-d'),
            $today->format('Y-m-d'),
        ])
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');

        return [
            'totalHours' => [
                'value' => $this->formatHours($totalHoursToday),
                'label' => 'Total Hours Today',
            ],
            'activeEmployees' => [
                'value' => $activeEmployees,
                'label' => 'Total Employees',
            ],
            'totalClients' => [
                'value' => $totalClients,
                'label' => 'Total Clients',
            ],
            'teamEfficiency' => [
                'value' => "{$teamEfficiency}%",
                'label' => 'Team Efficiency',
            ],
            // Work Hours Report Statistics
            'totalHoursWeek' => [
                'value' => $this->formatHours($totalHoursThisWeek),
                'label' => 'Total Hours This Week',
                'rawValue' => round($totalHoursThisWeek, 2),
            ],
            'totalEntriesMonth' => [
                'value' => $totalEntriesThisMonth,
                'label' => 'Total Entries This Month',
            ],
            'activeUsersToday' => [
                'value' => $activeUsersToday,
                'label' => 'Active Users Today',
            ],
            'activeClientsMonth' => [
                'value' => $activeClientsThisMonth,
                'label' => 'Active Clients This Month',
            ],
        ];
    }

    private function formatHours($decimal)
    {
        if ($decimal == 0) {
            return '0h';
        }

        $hours = floor($decimal);
        $minutes = round(($decimal - $hours) * 60);

        if ($minutes == 0) {
            return $hours.'h';
        } elseif ($minutes == 30) {
            return $hours.'.5h';
        } else {
            return $hours.'h '.$minutes.'m';
        }
    }

    private function getWorkDaysInMonth($start, $end)
    {
        $workDays = 0;
        $current = $start->copy();

        while ($current->lte($end)) {
            // Count Monday to Friday as work days
            if ($current->isWeekday()) {
                $workDays++;
            }
            $current->addDay();
        }

        return $workDays;
    }
}
