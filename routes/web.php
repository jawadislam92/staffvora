<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesktopDownloadController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchedulerController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SlackReportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\UpworkProfileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkHourController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/storage/avatars/{filename}', function (string $filename) {
    $path = 'avatars/'.$filename;

    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path, null, [
        'Cache-Control' => 'public, max-age=86400',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('filename', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('avatars.show');

// HTTP scheduler trigger for external cron pingers (see SchedulerController).
// 404s unless the shared token matches; throttled to a sane ping rate.
Route::get('/cron/run/{token}', [SchedulerController::class, 'run'])
    ->where('token', '[A-Za-z0-9]{32,128}')
    ->middleware('throttle:12,1')
    ->name('scheduler.run');

// Uploaded app logo — public because it renders on the login/welcome pages.
Route::get('/branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');

// Desktop auto-update feed (latest.yml + artifacts) — public, the updater
// has no web session. File safety is enforced inside the controller.
Route::get('/desktop-updates/{file}', [DesktopDownloadController::class, 'updates'])->name('desktop-updates.file');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/avatar/{user}', [\App\Http\Controllers\AvatarController::class, 'show'])->name('avatar.show');

    Route::get('/desktop-downloads', [DesktopDownloadController::class, 'index'])->name('desktop-downloads.index');
    Route::get('/desktop-downloads/windows', [DesktopDownloadController::class, 'windows'])->name('desktop-downloads.windows');
    Route::get('/desktop-downloads/mac', [DesktopDownloadController::class, 'mac'])->name('desktop-downloads.mac');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->middleware('permission:users.manage')->name('users.create');
    Route::post('/users/bulk-update', [UserController::class, 'bulkUpdate'])->middleware('permission:users.manage')->name('users.bulk-update');
    Route::post('/users/designations', [UserController::class, 'storeDesignation'])->middleware('permission:users.manage')->name('users.designations.store');
    Route::delete('/users/designations/{designation}', [UserController::class, 'destroyDesignation'])->middleware('permission:users.manage')->name('users.designations.destroy');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage')->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.manage')->name('users.edit');
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage')->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->name('users.destroy');

    Route::get('/report', [WorkHourController::class, 'report'])
        ->middleware('permission:reports.view')
        ->name('work-hours.report');
    Route::get('/work-hours/export', [WorkHourController::class, 'export'])
        ->middleware('permission:reports.export')
        ->name('work-hours.export');
    Route::post('/report/slack', [SlackReportController::class, 'store'])
        ->middleware('permission:reports.send_slack')
        ->name('work-hours.slack');

    Route::get('/clients/export', [ClientController::class, 'export'])
        ->middleware('permission:clients.import_export')
        ->name('clients.export');
    Route::post('/clients/import', [ClientController::class, 'import'])
        ->middleware('permission:clients.import_export')
        ->name('clients.import');
    Route::delete('/clients/bulk-destroy', [ClientController::class, 'bulkDestroy'])
        ->middleware('permission:clients.manage')
        ->name('clients.bulk-destroy');
    Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view')->name('clients.index');
    Route::get('/clients/create', [ClientController::class, 'create'])->middleware('permission:clients.manage')->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.manage')->name('clients.store');
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->middleware('permission:clients.manage')->name('clients.edit');
    Route::match(['put', 'patch'], '/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage')->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('permission:clients.manage')->name('clients.destroy');

    Route::get('/upwork-profiles', [UpworkProfileController::class, 'index'])->middleware('permission:profiles.view')->name('upwork-profiles.index');
    Route::get('/upwork-profiles/create', [UpworkProfileController::class, 'create'])->middleware('permission:profiles.manage')->name('upwork-profiles.create');
    Route::post('/upwork-profiles', [UpworkProfileController::class, 'store'])->middleware('permission:profiles.manage')->name('upwork-profiles.store');
    Route::get('/upwork-profiles/{upwork_profile}', [UpworkProfileController::class, 'show'])->middleware('permission:profiles.view')->name('upwork-profiles.show');
    Route::get('/upwork-profiles/{upwork_profile}/edit', [UpworkProfileController::class, 'edit'])->middleware('permission:profiles.manage')->name('upwork-profiles.edit');
    Route::match(['put', 'patch'], '/upwork-profiles/{upwork_profile}', [UpworkProfileController::class, 'update'])->middleware('permission:profiles.manage')->name('upwork-profiles.update');
    Route::delete('/upwork-profiles/{upwork_profile}', [UpworkProfileController::class, 'destroy'])->middleware('permission:profiles.manage')->name('upwork-profiles.destroy');

    Route::get('/employee-attendance', [EmployeeAttendanceController::class, 'index'])->middleware('permission:attendance.view')->name('employee-attendance.index');
    Route::get('/employee-attendance/summary', [EmployeeAttendanceController::class, 'getSummary'])->middleware('permission:attendance.view')->name('employee-attendance.summary');
    Route::get('/employee-attendance/detailed', [EmployeeAttendanceController::class, 'getDetailed'])->middleware('permission:attendance.view')->name('employee-attendance.detailed');
    Route::get('/employee-attendance/timeline', [EmployeeAttendanceController::class, 'getTimeline'])->middleware('permission:attendance.view')->name('employee-attendance.timeline');
    Route::get('/employee-attendance/monthly', [EmployeeAttendanceController::class, 'getMonthlyGrid'])->middleware('permission:attendance.view')->name('employee-attendance.monthly');
    Route::get('/employee-attendance/manual-history', [EmployeeAttendanceController::class, 'getManualHistory'])->middleware('permission:attendance.view')->name('employee-attendance.manual-history');
    Route::patch('/employee-attendance/manual-status', [EmployeeAttendanceController::class, 'updateManualStatus'])->middleware('permission:attendance.view')->name('employee-attendance.manual-status');
    Route::post('/employee-attendance/calendar', [EmployeeAttendanceController::class, 'updateCalendar'])->middleware('permission:attendance.view')->name('employee-attendance.calendar');
    Route::post('/employee-attendance/slack', [EmployeeAttendanceController::class, 'sendSlack'])->middleware('permission:reports.send_slack')->name('employee-attendance.slack');
    Route::get('/employee-attendance/export', [EmployeeAttendanceController::class, 'export'])->middleware('permission:attendance.export')->name('employee-attendance.export');

    Route::resource('work-hours', WorkHourController::class)->except(['show']);
    Route::get('/work-hours-export', [WorkHourController::class, 'exportPersonal'])->name('work-hours.export-personal');
    Route::post('/work-hours/bulk-delete', [WorkHourController::class, 'bulkDelete'])->name('work-hours.bulk-delete');

    // Time tracking routes
    Route::prefix('time-entries')->group(function () {
        Route::get('/', [TimeEntryController::class, 'index'])->name('time-entries.index');
        Route::post('/', [TimeEntryController::class, 'store'])->name('time-entries.store');
        Route::get('/today', [TimeEntryController::class, 'getTodaysEntries'])->name('time-entries.today');
        Route::get('/today-summary', [TimeEntryController::class, 'getTodaysSummary'])->name('time-entries.today-summary');
        Route::get('/export', [TimeEntryController::class, 'export'])->name('time-entries.export');
    });

    // Daily timeline view (scrin.io-style). Anyone can see their own; viewing
    // other users requires the timeline.view_others permission.
    Route::prefix('timeline')->group(function () {
        Route::get('/', [TimelineController::class, 'index'])->name('timeline.index');
        Route::get('/data', [TimelineController::class, 'data'])->name('timeline.data');
        Route::get('/history', [TimelineController::class, 'history'])->name('timeline.history');
        Route::get('/ai-summary', [TimelineController::class, 'aiSummary'])->name('timeline.ai-summary');
    });

    // Manager chat over aggregated team work data (last 14 days).
    Route::get('/ai-assistant', [\App\Http\Controllers\AiAssistantController::class, 'index'])->middleware('permission:ai.assistant')->name('ai.assistant');
    Route::post('/ai-assistant/ask', [\App\Http\Controllers\AiAssistantController::class, 'ask'])->middleware(['permission:ai.assistant', 'throttle:20,1'])->name('ai.assistant.ask');
    Route::get('/ai-assistant/config', [\App\Http\Controllers\AiAssistantController::class, 'config'])->middleware('permission:ai.assistant')->name('ai.assistant.config');
    Route::post('/ai-assistant/questions', [\App\Http\Controllers\AiAssistantController::class, 'saveQuestions'])->name('ai.assistant.questions');


    // Team day snapshot. Anyone with timeline.view_others sees the team
    // leaderboard; live "currently tracking" indicators are visible to all
    // permitted viewers regardless of monitoring.view_screenshots.
    Route::get('/team', [TeamController::class, 'index'])
        ->middleware('permission:timeline.view_others')
        ->name('team.index');
    Route::get('/team/apps', [TeamController::class, 'apps'])
        ->middleware('permission:timeline.view_others')
        ->name('team.apps');
    Route::post('/team/slack-digest', [TeamController::class, 'sendDigest'])
        ->middleware('permission:reports.send_slack')
        ->name('team.slack-digest');

    // Developer / system panel. Authorisation is enforced inside the
    // controller (Super Admin only); no grantable permission on purpose.
    Route::prefix('developer')->group(function () {
        Route::get('/', [DeveloperController::class, 'index'])->name('developer.index');
        Route::post('/run', [DeveloperController::class, 'run'])->name('developer.run');
        Route::put('/env', [DeveloperController::class, 'updateEnv'])->name('developer.env.update');
        Route::get('/logs', [DeveloperController::class, 'logs'])->name('developer.logs');
        Route::post('/branding', [BrandingController::class, 'update'])->name('developer.branding.update');
    });

    // Tracking / monitoring settings (scrin.io-style). Super Admin only by
    // default via the monitoring.settings permission.
    Route::prefix('settings')->middleware('permission:monitoring.settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/team', [SettingsController::class, 'updateTeam'])->name('settings.team.update');
        Route::put('/users/{user}', [SettingsController::class, 'updateUser'])->name('settings.user.update');
    });

    // Monitoring (screenshot tracker) routes
    Route::prefix('monitoring')->group(function () {
        Route::get('/sessions', [MonitoringController::class, 'sessions'])->name('monitoring.sessions');
        Route::get('/sessions/{session}', [MonitoringController::class, 'showSession'])->name('monitoring.sessions.show');
        Route::get('/screenshots/{screenshot}/image', [MonitoringController::class, 'screenshotImage'])->name('monitoring.screenshots.image');
        Route::get('/screenshots/{screenshot}/thumbnail', [MonitoringController::class, 'screenshotThumbnail'])->name('monitoring.screenshots.thumbnail');
        Route::patch('/screenshots/{screenshot}/flag', [MonitoringController::class, 'flagScreenshot'])->name('monitoring.screenshots.flag');
        Route::post('/sessions/{session}/delete', [MonitoringController::class, 'deleteSession'])
            ->middleware('permission:monitoring.delete_screenshots')
            ->name('monitoring.sessions.delete-session');
        Route::post('/screenshots/bulk-delete', [MonitoringController::class, 'bulkDeleteScreenshots'])
            ->middleware('permission:monitoring.delete_screenshots')
            ->name('monitoring.screenshots.bulk-delete');
        Route::delete('/screenshots/{screenshot}', [MonitoringController::class, 'deleteScreenshot'])
            ->middleware('permission:monitoring.delete_screenshots')
            ->name('monitoring.screenshots.delete');
    });
});

require __DIR__.'/auth.php';
