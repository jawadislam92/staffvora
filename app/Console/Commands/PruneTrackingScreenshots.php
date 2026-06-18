<?php

namespace App\Console\Commands;

use App\Models\MonitoringSetting;
use App\Models\TrackingScreenshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneTrackingScreenshots extends Command
{
    protected $signature = 'monitoring:prune-screenshots {--days= : Override the configured retention period} {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Permanently delete screenshots (files and rows) older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: MonitoringSetting::current()->retention_days);

        if ($days <= 0) {
            $this->warn('Retention is disabled (retention_days is 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = TrackingScreenshot::withTrashed()->where('captured_at', '<', $cutoff);
        $total = (int) $query->count();

        if ($total === 0) {
            $this->info("No screenshots older than {$days} days.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$total} screenshots captured before {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $disk = Storage::disk('screenshots');
        $deletedRows = 0;
        $deletedFiles = 0;

        $query->orderBy('id')->chunkById(200, function ($screenshots) use ($disk, &$deletedRows, &$deletedFiles) {
            foreach ($screenshots as $screenshot) {
                foreach ([$screenshot->image_path, $screenshot->thumbnail_path] as $path) {
                    if ($path && $disk->exists($path)) {
                        $disk->delete($path);
                        $deletedFiles++;
                    }
                }

                $screenshot->forceDelete();
                $deletedRows++;
            }
        });

        $this->info("Pruned {$deletedRows} screenshots ({$deletedFiles} files) captured before {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
