<?php

use App\Http\Controllers\Api\Desktop\ActivityController as DesktopActivityController;
use App\Http\Controllers\Api\Desktop\AuthController as DesktopAuthController;
use App\Http\Controllers\Api\Desktop\ConsentController as DesktopConsentController;
use App\Http\Controllers\Api\Desktop\DiagnosticsController as DesktopDiagnosticsController;
use App\Http\Controllers\Api\Desktop\MachineReportController as DesktopMachineReportController;
use App\Http\Controllers\Api\Desktop\MetaController as DesktopMetaController;
use App\Http\Controllers\Api\Desktop\ScreenshotController as DesktopScreenshotController;
use App\Http\Controllers\Api\Desktop\SessionController as DesktopSessionController;
use App\Http\Controllers\Api\Desktop\TimeClockController as DesktopTimeClockController;
use App\Http\Controllers\SlackInteractionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['auth:sanctum', 'tenant.usable'])->get('/user', function (Request $request) {
    return $request->user();
});

// Slack interactive button clicks (the "still working?" attendance check).
// No auth middleware — authenticity is proven by the Slack signing-secret HMAC
// verified inside the controller.
Route::post('/slack/interact', [SlackInteractionController::class, 'handle'])->middleware('throttle:30,1')->name('slack.interact');

/*
|--------------------------------------------------------------------------
| Desktop Tracker API (Sanctum token auth)
|--------------------------------------------------------------------------
*/
Route::prefix('desktop')->group(function () {
    // Coarse per-IP ceiling on top of the per-account + per-email + per-IP
    // budgets enforced inside DesktopAuthController::login. Inline limits are
    // used deliberately: the deploy script runs `php artisan route:cache`, and a
    // named limiter registered from a route file is not present in a cached
    // build. Never remove the in-controller limiter — this one alone is
    // defeated by IP rotation, which the wildcard CORS config makes free.
    Route::post('/login', [DesktopAuthController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('desktop.login');

    Route::middleware(['auth:sanctum', 'tenant.usable'])->group(function () {
        Route::get('/me', [DesktopAuthController::class, 'me'])->name('desktop.me');
        Route::post('/logout', [DesktopAuthController::class, 'logout'])->name('desktop.logout');

        // Monitoring consent. The agent asks on every launch which document
        // version is required and whether THIS user has accepted it, and posts
        // the acceptance when they tick the box.
        //
        // Deliberately NOT entitlement-gated: consent is a legal precondition of
        // using the product, not a paid feature, so a workspace on any plan must
        // be able to obtain and prove it.
        //
        // THE THIRD THROTTLE ARGUMENT IS LOAD-BEARING. Laravel's inline
        // `throttle:max,decay` keys on sha1(user id) and NOTHING ELSE — not the
        // route — so every inline-throttled route a given user touches shares
        // ONE counter, and the effective ceiling becomes the smallest `max`
        // among them. Without a prefix, an agent asking about consent on launch
        // would be spending the same budget its screenshot uploads need, and the
        // 6/min write limit here would silently become the ceiling for the whole
        // desktop API. The prefix gives each of these its own bucket. Inline
        // rather than a named limiter because the deploy script runs
        // `route:cache`, where a limiter declared in a route file is absent.
        Route::get('/consent', [DesktopConsentController::class, 'show'])
            ->middleware('throttle:20,1,consent-read')->name('desktop.consent.show');
        Route::post('/consent', [DesktopConsentController::class, 'store'])
            ->middleware('throttle:6,1,consent-write')->name('desktop.consent.store');

        Route::get('/clients', [DesktopMetaController::class, 'clients'])->name('desktop.clients');
        Route::get('/work-types', [DesktopMetaController::class, 'workTypes'])->name('desktop.work-types');
        Route::get('/upwork-profiles', [DesktopMetaController::class, 'upworkProfiles'])->name('desktop.upwork-profiles');
        Route::get('/settings', [DesktopMetaController::class, 'settings'])->name('desktop.settings');

        Route::post('/sessions/start', [DesktopSessionController::class, 'start'])->name('desktop.sessions.start');
        Route::patch('/sessions/{session}/heartbeat', [DesktopSessionController::class, 'heartbeat'])->name('desktop.sessions.heartbeat');
        Route::post('/sessions/{session}/stop', [DesktopSessionController::class, 'stop'])->name('desktop.sessions.stop');
        Route::get('/sessions/today', [DesktopSessionController::class, 'today'])->name('desktop.sessions.today');
        Route::get('/sessions/week', [DesktopSessionController::class, 'week'])->name('desktop.sessions.week');
        Route::get('/sessions/recent-clients', [DesktopSessionController::class, 'recentClients'])->name('desktop.sessions.recent-clients');

        Route::get('/time-clock', [DesktopTimeClockController::class, 'status'])->name('desktop.time-clock.status');
        Route::post('/time-clock', [DesktopTimeClockController::class, 'act'])->name('desktop.time-clock.act');

        // Materially tighter than the shared 60/min api bucket. A legitimate
        // agent takes a handful of shots an hour; the shared bucket allowed ~86k
        // uploads a day of up to 8 MB each into the tenant's object storage,
        // which the operator pays for. Keyed per-user by the `throttle`
        // middleware, so one tampered agent cannot spend another's budget.
        Route::post('/screenshots', [DesktopScreenshotController::class, 'store'])
            ->middleware(['entitlement:monitoring', 'throttle:12,1'])
            ->name('desktop.screenshots.store');
        Route::post('/activity/batch', [DesktopActivityController::class, 'batch'])
            ->middleware(['entitlement:activity_tracking', 'throttle:20,1'])
            ->name('desktop.activity.batch');

        // Client-side error / telemetry the tracker uploads (capture blocked by
        // antivirus, upload failures, crashes) → the 'desktop' diagnostics
        // category. Deliberately NOT entitlement-gated: knowing an agent is
        // broken is a correctness signal for the hours themselves, not a paid
        // monitoring feature, and a tenant on the cheapest plan still needs its
        // payroll to be right. Throttled per-user well below the shared api
        // bucket — a legitimate agent batches a handful of events a minute, and
        // each request may carry up to 50 of them.
        Route::post('/diagnostics', [DesktopDiagnosticsController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('desktop.diagnostics.store');

        // Machine Compliance inventory (browser extensions, installed programs,
        // running processes, network adapters) → blocklist-matched into the
        // per-machine current state + finding ledger.
        //
        // Gated on the `machine_compliance` entitlement key ONLY — never on plan
        // name and never on workspace_type — so the operator can grant it to any
        // company from the panel. A workspace without it must not be able to
        // stream endpoint inventory into tables it can never read.
        //
        // Throttled far below the shared 60/min api bucket: a legitimate agent
        // uploads its full inventory a handful of times a day, and each request
        // may carry up to ~1,700 items across four categories. Keyed per-user by
        // the `throttle` middleware, so one tampered agent cannot spend another's
        // budget. The inline limit is deliberate — the deploy script runs
        // `php artisan route:cache`, and a named limiter registered from a route
        // file is not present in a cached build.
        Route::post('/machine-report', [DesktopMachineReportController::class, 'store'])
            ->middleware(['entitlement:machine_compliance', 'throttle:6,1'])
            ->name('desktop.machine-report.store');
    });
});
