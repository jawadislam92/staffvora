<?php

namespace App\Console\Commands;

use App\Services\SlackReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;

class SendWeeklySlackReport extends Command
{
    protected $signature = 'reports:send-weekly-slack {--start=} {--end=}';

    protected $description = 'Send the completed weekly work-hours report to Slack';

    public function handle(SlackReportService $slack): int
    {
        $timezone = config('services.slack_reports.timezone', 'Asia/Karachi');
        $end = $this->option('end')
            ? Carbon::parse($this->option('end'), $timezone)->endOfDay()
            : Carbon::now($timezone)->subDay()->endOfDay();
        $start = $this->option('start')
            ? Carbon::parse($this->option('start'), $timezone)->startOfDay()
            : $end->copy()->subDays(6)->startOfDay();

        try {
            $summary = $slack->sendRange($start, $end);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Slack report sent for {$summary['start_date']} through {$summary['end_date']} "
            ."({$summary['total_hours']} total hours)."
        );

        return self::SUCCESS;
    }
}
