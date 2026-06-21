<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Platform\PortalController as PlatformPortalController;
use App\Http\Controllers\Platform\SiteBuilderController;
use App\Http\Controllers\PlatformController;
use App\Http\Middleware\SharePlatformUser;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesktopDownloadController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchedulerController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Site\PageController as SitePageController;
use App\Http\Controllers\SlackReportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamManagementController;
use App\Http\Controllers\ThemeController;
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

// ── Operator / landlord portal (platform.satimetracker.com) ──────────────────
// Bound to its own host + the `platform` auth guard, fully separate from the
// customer site. Declared FIRST so platform paths win on the platform host;
// these routes never match on the customer host (Route::domain), so customers
// can't reach the portal.
Route::domain(config('platform.host'))->group(function () {
    Route::get('/login', [PlatformAuthController::class, 'showLogin'])->name('platform.login');
    Route::post('/login', [PlatformAuthController::class, 'login'])->middleware('throttle:8,1');

    Route::middleware(['auth:platform', SharePlatformUser::class])->group(function () {
        Route::post('/logout', [PlatformAuthController::class, 'logout'])->name('platform.logout');
        Route::get('/', fn () => redirect()->route('platform.dashboard'));
        Route::get('/dashboard', [PlatformPortalController::class, 'dashboard'])->name('platform.dashboard');
    });
});

// Public marketing home — rendered from the builder-driven content (Site\PageController).
Route::get('/', [SitePageController::class, 'home'])->name('home');

// Builder-uploaded images + the marketing-site logo (public, cached).
Route::get('/site/media/{media}', [SitePageController::class, 'media'])->name('site.media');
Route::get('/site/logo', [SitePageController::class, 'logo'])->name('site.media.logo');

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
    Route::put('/preferences/theme', [ThemeController::class, 'update'])->name('theme.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/avatar/{user}', [\App\Http\Controllers\AvatarController::class, 'show'])->name('avatar.show');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');

    Route::get('/help', [\App\Http\Controllers\HelpController::class, 'index'])->name('help.index');

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

    // Customizable teams (groups). Org-scoped; mutations require user management.
    Route::get('/teams', [TeamManagementController::class, 'index'])->middleware('permission:users.view')->name('teams.index');
    Route::post('/teams', [TeamManagementController::class, 'store'])->middleware('permission:users.manage')->name('teams.store');
    Route::match(['put', 'patch'], '/teams/{team}', [TeamManagementController::class, 'update'])->middleware('permission:users.manage')->name('teams.update');
    Route::delete('/teams/{team}', [TeamManagementController::class, 'destroy'])->middleware('permission:users.manage')->name('teams.destroy');

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

    // Developer / system panel. PLATFORM operator only — it edits platform-wide
    // .env secrets shared across every tenant, so it's gated by the `platform`
    // middleware (and re-checked in the controller). A tenant owner cannot reach it.
    Route::prefix('developer')->middleware('platform')->group(function () {
        Route::get('/', [DeveloperController::class, 'index'])->name('developer.index');
        Route::post('/run', [DeveloperController::class, 'run'])->name('developer.run');
        Route::put('/env', [DeveloperController::class, 'updateEnv'])->name('developer.env.update');
        Route::get('/logs', [DeveloperController::class, 'logs'])->name('developer.logs');
    });

    // Tracking / monitoring settings (scrin.io-style). Super Admin only by
    // default via the monitoring.settings permission.
    Route::prefix('settings')->middleware('permission:monitoring.settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/team', [SettingsController::class, 'updateTeam'])->name('settings.team.update');
        Route::put('/attendance', [SettingsController::class, 'updateAttendance'])->name('settings.attendance.update');
        Route::put('/users/{user}', [SettingsController::class, 'updateUser'])->name('settings.user.update');

        // White-label branding: name + accent (saveBranding) and the logo upload
        // (update). These belong to the tenant, so they live here — gated by the
        // tenant's monitoring.settings permission, NOT the platform gate.
        Route::get('/branding', [BrandingController::class, 'edit'])->name('settings.branding');
        Route::put('/branding', [BrandingController::class, 'saveBranding'])->name('settings.branding.save');
        Route::post('/branding/logo', [BrandingController::class, 'update'])->name('settings.branding.logo');

        // Per-org Slack connection (write-only bot token).
        Route::put('/slack', [SettingsController::class, 'updateSlack'])->name('settings.slack.update');
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

// Platform (landlord) console — the SaaS operator, across every tenant.
Route::middleware(['auth', 'verified', 'platform'])->prefix('platform')->group(function () {
    Route::get('/', [PlatformController::class, 'index'])->name('platform.index');
    // Plan editor — declared before the /{organization} wildcard so "plans"
    // isn't treated as a tenant slug.
    Route::get('/plans', [PlatformController::class, 'plans'])->name('platform.plans');
    Route::put('/plans/{plan}', [PlatformController::class, 'updatePlan'])->name('platform.plans.update');

    // No-code marketing-site builder (declared before the /{organization} wildcard).
    Route::prefix('site')->group(function () {
        Route::get('/', [SiteBuilderController::class, 'index'])->name('platform.site.index');
        Route::put('/theme', [SiteBuilderController::class, 'updateTheme'])->name('platform.site.theme');
        Route::post('/logo', [SiteBuilderController::class, 'setLogo'])->name('platform.site.logo');
        Route::post('/image', [SiteBuilderController::class, 'uploadImage'])->name('platform.site.image');
        Route::post('/pages', [SiteBuilderController::class, 'createPage'])->name('platform.site.pages.create');
        Route::get('/pages/{page}', [SiteBuilderController::class, 'edit'])->name('platform.site.edit');
        Route::put('/pages/{page}', [SiteBuilderController::class, 'saveDraft'])->name('platform.site.save');
        Route::post('/pages/{page}/publish', [SiteBuilderController::class, 'publish'])->name('platform.site.publish');
        Route::delete('/pages/{page}', [SiteBuilderController::class, 'destroyPage'])->name('platform.site.pages.destroy');
    });

    Route::get('/{organization}', [PlatformController::class, 'show'])->name('platform.show');
    Route::post('/{organization}/suspend', [PlatformController::class, 'suspend'])->name('platform.suspend');
    Route::post('/{organization}/resume', [PlatformController::class, 'resume'])->name('platform.resume');
    Route::post('/{organization}/payment', [PlatformController::class, 'recordPayment'])->name('platform.payment');
    Route::put('/{organization}/entitlements', [PlatformController::class, 'updateTenant'])->name('platform.tenant.update');
    Route::post('/{organization}/impersonate', [PlatformController::class, 'impersonate'])->name('platform.impersonate');
});

// Return from impersonation — auth only (the impersonated user isn't a platform admin).
Route::post('/stop-impersonating', [PlatformController::class, 'stopImpersonating'])
    ->middleware('auth')->name('platform.stop-impersonating');

require __DIR__.'/auth.php';

// Published marketing pages by slug (e.g. /pricing, /privacy). Declared LAST so
// it can never shadow an app route — only unmatched lowercase slugs reach it,
// and the controller 404s when there's no published page.
Route::get('/{slug}', [SitePageController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')
    ->name('site.page');
