<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

class SchedulerController extends Controller
{
    /**
     * Run the Laravel scheduler over HTTP, authenticated by a shared token.
     *
     * Fallback for hosts where the panel cron is unreliable: an external
     * pinger (cron-job.org, UptimeRobot, ...) hits this URL every minute and
     * the scheduler decides what is actually due. Idempotent and safe to call
     * repeatedly — scheduled tasks use withoutOverlapping mutexes.
     */
    public function run(string $token)
    {
        $expected = config('services.scheduler.token');

        abort_unless(
            is_string($expected) && $expected !== '' && hash_equals($expected, $token),
            404
        );

        Artisan::call('schedule:run');

        return response(trim(Artisan::output()) ?: 'No scheduled commands are ready to run.', 200)
            ->header('Content-Type', 'text/plain');
    }
}
