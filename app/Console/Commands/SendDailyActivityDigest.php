<?php

namespace App\Console\Commands;

use App\Services\ActivityDigestService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;

class SendDailyActivityDigest extends Command
{
    protected $signature = 'activity:digest-slack {--date=} {--start=} {--end=}';

    protected $description = 'Send a Slack activity digest summarising team tracking for a day (defaults to yesterday)';

    public function handle(ActivityDigestService $service): int
    {
        $timezone = config('services.slack_reports.timezone', 'Asia/Karachi');

        if ($this->option('start') || $this->option('end')) {
            $start = Carbon::parse($this->option('start') ?: $this->option('end'), $timezone)->startOfDay();
            $end = Carbon::parse($this->option('end') ?: $this->option('start'), $timezone)->endOfDay();
        } elseif ($this->option('date')) {
            $start = Carbon::parse($this->option('date'), $timezone)->startOfDay();
            $end = $start->copy()->endOfDay();
        } else {
            $start = Carbon::now($timezone)->subDay()->startOfDay();
            $end = $start->copy()->endOfDay();
        }

        try {
            $summary = $service->sendRange($start, $end);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Activity digest sent for %s: %d people, %ds tracked.',
            $summary['range']['label'],
            $summary['people'],
            $summary['total_seconds'],
        ));

        return self::SUCCESS;
    }
}
