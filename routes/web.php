<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Platform\AccountController as PlatformAccountController;
use App\Http\Controllers\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Platform\InvitationController as PlatformInvitationController;
use App\Http\Controllers\Platform\PortalController as PlatformPortalController;
use App\Http\Controllers\Platform\SiteBuilderController;
use App\Http\Controllers\Platform\StaffController as PlatformStaffController;
use App\Http\Controllers\PlatformController;
use App\Http\Middleware\SharePlatformUser;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesktopDownloadController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchedulerController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftOverrideController;
use App\Http\Controllers\Site\PageController as SitePageController;
use App\Http\Controllers\SlackReportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamManagementController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\TimesheetApprovalController;
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

// Internal design-kit gallery — LOCAL ONLY, for building/verifying the shared UI
// kit (resources/js/Pages/DesignKit.jsx). Not shipped to production.
if (app()->environment('local')) {
    Route::get('/_kit', fn () => Inertia::render('DesignKit'))->name('design.kit');
    Route::get('/_dashboard', fn () => Inertia::render('Dashboard', ['preview' => true]))->name('design.dashboard');
    Route::get('/_attendance', fn () => Inertia::render('EmployeeAttendance', ['preview' => true]))->name('design.attendance');
    Route::get('/_billing', fn () => Inertia::render('Billing/Index', ['preview' => true]))->name('design.billing');
    Route::get('/_branding', fn () => Inertia::render('Settings/Branding', ['preview' => true]))->name('design.branding');
    Route::get('/_teams', fn () => Inertia::render('Teams/Index', ['preview' => true]))->name('design.teams');
    Route::get('/_profiles', fn () => Inertia::render('UpworkProfiles/Index', ['preview' => true]))->name('design.profiles');
    Route::get('/_reports', fn () => Inertia::render('WorkHoursReport', ['preview' => true]))->name('design.reports');
    Route::get('/_users', fn () => Inertia::render('UsersList', ['preview' => true]))->name('design.users');
    Route::get('/_clients', fn () => Inertia::render('ClientsList', ['preview' => true]))->name('design.clients');
    Route::get('/_timeline', fn () => Inertia::render('Timeline/Index', ['preview' => true]))->name('design.timeline');
    Route::get('/_team', fn () => Inertia::render('Team/Index', ['preview' => true]))->name('design.team');
    Route::get('/_team-apps', fn () => Inertia::render('Team/Apps', ['preview' => true]))->name('design.team-apps');
    Route::get('/_work-hours', fn () => Inertia::render('WorkHoursList', ['preview' => true]))->name('design.work-hours');
    Route::get('/_wh-create', fn () => Inertia::render('WorkHourCreate', ['preview' => true]))->name('design.wh-create');
    Route::get('/_wh-edit', fn () => Inertia::render('WorkHourEdit', ['preview' => true]))->name('design.wh-edit');
    Route::get('/_settings', fn () => Inertia::render('Settings/Index', ['preview' => true]))->name('design.settings');
    Route::get('/_sessions', fn () => Inertia::render('Monitoring/SessionsList', ['preview' => true]))->name('design.sessions');
    Route::get('/_session', fn () => Inertia::render('Monitoring/SessionDetail', ['preview' => true]))->name('design.session');
    Route::get('/_desktop', fn () => Inertia::render('DesktopDownloads', ['preview' => true]))->name('design.desktop');
    Route::get('/_profile', fn () => Inertia::render('Profile/Edit', ['preview' => true]))->name('design.profile');
    Route::get('/_ai', fn () => Inertia::render('AiAssistant', ['preview' => true]))->name('design.ai');
    Route::get('/_feedback', fn () => Inertia::render('Feedback/Index', ['preview' => true]))->name('design.feedback');
    Route::get('/_my-schedule', fn () => Inertia::render('MySchedule', ['preview' => true]))->name('design.my-schedule');
    Route::get('/_work-categories', fn () => Inertia::render('Settings/WorkCategories', ['preview' => true]))->name('design.work-categories');
    Route::get('/_help', fn () => Inertia::render('Help/Index', ['preview' => true]))->name('design.help');
    Route::get('/_compliance', fn () => Inertia::render('Compliance/Index', ['preview' => true]))->name('design.compliance');
    Route::get('/_timesheets', fn () => Inertia::render('Timesheets/Index', ['preview' => true]))->name('design.timesheets');
    Route::get('/_timesheet', fn () => Inertia::render('Timesheets/Show', ['preview' => true]))->name('design.timesheet');
    Route::get('/_insights', fn () => Inertia::render('Insights/Index', ['preview' => true]))->name('design.insights');
    Route::get('/_locked', fn () => Inertia::render('Upgrade/Locked', ['preview' => true]))->name('design.locked');
    // Operator portal previews (render on the customer host with a mock platform user).
    Route::get('/_p-dashboard', fn () => Inertia::render('PlatformPortal/Dashboard', ['preview' => true]))->name('design.p-dashboard');
    Route::get('/_p-tenants', fn () => Inertia::render('Platform/Index', ['preview' => true]))->name('design.p-tenants');
    Route::get('/_p-tenant', fn () => Inertia::render('Platform/Show', ['preview' => true]))->name('design.p-tenant');
    Route::get('/_p-plans', fn () => Inertia::render('Platform/Plans', ['preview' => true]))->name('design.p-plans');
    Route::get('/_p-staff', fn () => Inertia::render('PlatformPortal/Staff', ['preview' => true]))->name('design.p-staff');
    Route::get('/_p-developer', fn () => Inertia::render('Developer/Index', ['preview' => true]))->name('design.p-developer');
    Route::get('/_p-account', fn () => Inertia::render('PlatformPortal/Account', ['preview' => true]))->name('design.p-account');
    Route::get('/_p-leads', fn () => Inertia::render('Platform/Leads/Index', ['preview' => true]))->name('design.p-leads');
    Route::get('/_p-regions', fn () => Inertia::render('Platform/Regions', ['preview' => true]))->name('design.p-regions');
}

// ── Operator / landlord portal (platform.staffvora.com) ──────────────────
// Bound to its own host + the `platform` auth guard, fully separate from the
// customer site. Declared FIRST so platform paths win on the platform host;
// these routes never match on the customer host (Route::domain), so customers
// can't reach the portal.
Route::domain(config('platform.host'))->group(function () {
    Route::get('/login', [PlatformAuthController::class, 'showLogin'])->name('platform.login');
    Route::post('/login', [PlatformAuthController::class, 'login'])->middleware('throttle:8,1');

    // Invitation accept — guest (the invitee has no account yet); authorized by
    // the single-use token. Throttled so the token space can't be brute-forced.
    Route::get('/invite/{token}', [PlatformInvitationController::class, 'show'])
        ->middleware('throttle:20,1')->name('platform.invite.show');
    Route::post('/invite/{token}', [PlatformInvitationController::class, 'accept'])
        ->middleware('throttle:20,1')->name('platform.invite.accept');

    Route::middleware(['auth:platform', SharePlatformUser::class])->group(function () {
        Route::post('/logout', [PlatformAuthController::class, 'logout'])->name('platform.logout');
        Route::get('/', fn () => redirect()->route('platform.dashboard'));
        Route::get('/dashboard', [PlatformPortalController::class, 'dashboard'])->name('platform.dashboard');

        // The signed-in operator's own account (any role).
        Route::get('/account', [PlatformAccountController::class, 'edit'])->name('platform.account');
        Route::put('/account', [PlatformAccountController::class, 'update'])->name('platform.account.update');

        // Tenants — all roles can view; Support can also resume + impersonate to
        // help a customer (the gentle, non-destructive actions).
        Route::get('/tenants', [PlatformController::class, 'index'])->name('platform.index');
        Route::get('/tenants/{organization}', [PlatformController::class, 'show'])->name('platform.show');
        Route::post('/tenants/{organization}/resume', [PlatformController::class, 'resume'])->name('platform.resume');
        Route::post('/tenants/{organization}/impersonate', [PlatformController::class, 'impersonate'])->name('platform.impersonate');

        // Admin+ — the management surface: suspend/billing/entitlements, plans,
        // the website builder, and the developer panel.
        Route::middleware('platform.role:admin')->group(function () {
            Route::post('/tenants/{organization}/suspend', [PlatformController::class, 'suspend'])->name('platform.suspend');
            Route::post('/tenants/{organization}/trial', [PlatformController::class, 'setTrial'])->name('platform.trial');
            Route::post('/tenants/{organization}/payment', [PlatformController::class, 'recordPayment'])->name('platform.payment');
            Route::put('/tenants/{organization}/entitlements', [PlatformController::class, 'updateTenant'])->name('platform.tenant.update');

            Route::get('/plans', [\App\Http\Controllers\Platform\PlanController::class, 'index'])->name('platform.plans');
            Route::put('/plans/{plan}', [\App\Http\Controllers\Platform\PlanController::class, 'update'])->name('platform.plans.update');

            // Plan lifecycle. `/plans/reorder` is a literal path and must stay
            // ABOVE any `/plans/{plan}` POST so it can never be shadowed.
            Route::post('/plans', [\App\Http\Controllers\Platform\PlanController::class, 'store'])->name('platform.plans.store');
            Route::post('/plans/reorder', [\App\Http\Controllers\Platform\PlanController::class, 'reorder'])->name('platform.plans.reorder');
            Route::post('/plans/{plan}/duplicate', [\App\Http\Controllers\Platform\PlanController::class, 'duplicate'])->name('platform.plans.duplicate');
            Route::post('/plans/{plan}/archive', [\App\Http\Controllers\Platform\PlanController::class, 'archive'])->name('platform.plans.archive');
            Route::post('/plans/{plan}/restore', [\App\Http\Controllers\Platform\PlanController::class, 'restore'])->name('platform.plans.restore');
            Route::post('/plans/{plan}/default-trial', [\App\Http\Controllers\Platform\PlanController::class, 'makeDefaultTrial'])->name('platform.plans.default-trial');

            // Add-on catalogue (the Agency toolkit lives here).
            Route::get('/addons', [\App\Http\Controllers\Platform\AddonController::class, 'index'])->name('platform.addons');
            Route::post('/addons', [\App\Http\Controllers\Platform\AddonController::class, 'store'])->name('platform.addons.store');
            Route::put('/addons/{addon}', [\App\Http\Controllers\Platform\AddonController::class, 'update'])->name('platform.addons.update');
            Route::post('/addons/{addon}/archive', [\App\Http\Controllers\Platform\AddonController::class, 'archive'])->name('platform.addons.archive');
            Route::post('/addons/{addon}/restore', [\App\Http\Controllers\Platform\AddonController::class, 'restore'])->name('platform.addons.restore');

            // Region pricing rules — which plans a part of the world may buy, and
            // at what local price. `/regions/resolve` is a LITERAL path and must
            // stay ABOVE any `/regions/{pricingRegion}` route so it is never shadowed.
            Route::get('/regions', [\App\Http\Controllers\Platform\RegionController::class, 'index'])->name('platform.regions');
            Route::get('/regions/resolve', [\App\Http\Controllers\Platform\RegionController::class, 'resolve'])->name('platform.regions.resolve');
            Route::post('/regions', [\App\Http\Controllers\Platform\RegionController::class, 'store'])->name('platform.regions.store');
            Route::put('/regions/{pricingRegion}', [\App\Http\Controllers\Platform\RegionController::class, 'update'])->name('platform.regions.update');
            Route::post('/regions/{pricingRegion}/archive', [\App\Http\Controllers\Platform\RegionController::class, 'archive'])->name('platform.regions.archive');
            Route::post('/regions/{pricingRegion}/restore', [\App\Http\Controllers\Platform\RegionController::class, 'restore'])->name('platform.regions.restore');

            // Per-company add-on grants (e.g. give ONE company Machine Compliance).
            Route::post('/tenants/{organization}/addons', [\App\Http\Controllers\Platform\AddonController::class, 'attach'])->name('platform.tenant.addons.attach');
            Route::delete('/tenants/{organization}/addons/{addon}', [\App\Http\Controllers\Platform\AddonController::class, 'detach'])->name('platform.tenant.addons.detach');

            // Cross-tenant operator activity log (read-only; the table is append-only).
            Route::get('/audit', [\App\Http\Controllers\Platform\AuditController::class, 'index'])->name('platform.audit');

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

            Route::prefix('leads')->group(function () {
                Route::get('/', [\App\Http\Controllers\Platform\LeadController::class, 'index'])->name('platform.leads.index');
                Route::post('/{lead}/read', [\App\Http\Controllers\Platform\LeadController::class, 'markRead'])->name('platform.leads.read');
                Route::delete('/{lead}', [\App\Http\Controllers\Platform\LeadController::class, 'destroy'])->name('platform.leads.destroy');
            });

            Route::prefix('blog')->group(function () {
                Route::get('/', [\App\Http\Controllers\Platform\BlogController::class, 'index'])->name('platform.blog.index');
                Route::get('/new', [\App\Http\Controllers\Platform\BlogController::class, 'create'])->name('platform.blog.create');
                Route::post('/', [\App\Http\Controllers\Platform\BlogController::class, 'store'])->name('platform.blog.store');
                Route::get('/{post}', [\App\Http\Controllers\Platform\BlogController::class, 'edit'])->name('platform.blog.edit');
                Route::put('/{post}', [\App\Http\Controllers\Platform\BlogController::class, 'update'])->name('platform.blog.update');
                Route::post('/{post}/publish', [\App\Http\Controllers\Platform\BlogController::class, 'publish'])->name('platform.blog.publish');
                Route::post('/{post}/unpublish', [\App\Http\Controllers\Platform\BlogController::class, 'unpublish'])->name('platform.blog.unpublish');
                Route::delete('/{post}', [\App\Http\Controllers\Platform\BlogController::class, 'destroy'])->name('platform.blog.destroy');
            });

            Route::prefix('developer')->group(function () {
                Route::get('/', [DeveloperController::class, 'index'])->name('developer.index');
                Route::post('/run', [DeveloperController::class, 'run'])->name('developer.run');
                Route::put('/env', [DeveloperController::class, 'updateEnv'])->name('developer.env.update');
                Route::get('/logs', [DeveloperController::class, 'logs'])->name('developer.logs');
                // Cross-tenant tracker-health feed: which agents are reporting,
                // and which have gone quiet while someone was supposed to be working.
                Route::get('/diagnostics', [DeveloperController::class, 'diagnostics'])->name('developer.diagnostics');
            });
        });

        // Owner-only — manage operator staff (invite / revoke).
        Route::middleware('platform.role:owner')->group(function () {
            // Feature catalogue — OWNER only: deactivating a feature revokes it
            // for every tenant at once, so it sits above the admin surface.
            Route::get('/features', [\App\Http\Controllers\Platform\FeatureController::class, 'index'])->name('platform.features');
            Route::post('/features', [\App\Http\Controllers\Platform\FeatureController::class, 'store'])->name('platform.features.store');
            Route::post('/features/reorder', [\App\Http\Controllers\Platform\FeatureController::class, 'reorder'])->name('platform.features.reorder');
            Route::put('/features/{feature}', [\App\Http\Controllers\Platform\FeatureController::class, 'update'])->name('platform.features.update');

            Route::get('/staff', [PlatformStaffController::class, 'index'])->name('platform.staff');
            Route::post('/staff/invite', [PlatformStaffController::class, 'invite'])->name('platform.staff.invite');
            Route::delete('/staff/{platformUser}', [PlatformStaffController::class, 'revoke'])->name('platform.staff.revoke');
            Route::delete('/staff/invitations/{invitation}', [PlatformStaffController::class, 'revokeInvitation'])->name('platform.staff.invitation.revoke');
        });
    });
});

// Public marketing home — rendered from the builder-driven content (Site\PageController).
Route::get('/', [SitePageController::class, 'home'])->name('home');

// Marketing contact form handler (the /contact page itself is a CMS block-page).
// 5 per 10 minutes, not 6 per minute: a marketing contact form has no honest
// need for 360 submissions an hour, and this is an anonymous endpoint that
// writes a row and sends mail.
Route::post('/contact', [\App\Http\Controllers\Site\ContactController::class, 'submit'])
    ->middleware('throttle:5,10')->name('contact.submit');

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

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // Per-viewer display preferences (timezone + 12/24h). Self-service, no permission.
    Route::patch('/profile/display', [ProfileController::class, 'updateDisplay'])->name('profile.display');
    Route::put('/preferences/theme', [ThemeController::class, 'update'])->name('theme.update');

    // First-run setup wizard (B1). Signup lands here; the owner can also return
    // from the dashboard nudge until it's finished. Admin-only inside.
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('/onboarding/workspace-type', [OnboardingController::class, 'workspaceType'])->name('onboarding.workspace-type');
    Route::post('/onboarding/team', [OnboardingController::class, 'invite'])->name('onboarding.team');
    Route::post('/onboarding/client', [OnboardingController::class, 'storeClient'])->name('onboarding.client');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    // Sample-data demo mode. Owner/admin only (enforced inside the controller,
    // the same gate as the rest of the wizard). Everything it creates is named
    // "Demo · " and removable in one call — see App\Services\DemoDataService.
    Route::post('/onboarding/demo-data', [OnboardingController::class, 'loadDemoData'])->name('onboarding.demo.load');
    Route::delete('/onboarding/demo-data', [OnboardingController::class, 'removeDemoData'])->name('onboarding.demo.remove');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/avatar/{user}', [\App\Http\Controllers\AvatarController::class, 'show'])->name('avatar.show');

    // Billing hub. Every name starts with `billing.`, which EnsureOrganizationIsUsable
    // whitelists, so a suspended/expired workspace can still reach it and pay.
    // Viewing stays ungated (it is where an expired workspace is sent — gating it
    // would loop a non-manager); CHANGES require billing.manage.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    // The literal /billing/addons POST is declared BEFORE the {addon:key} DELETE
    // so the literal segment can never be shadowed.
    Route::post('/billing/addons', [BillingController::class, 'requestAddon'])->middleware('permission:billing.manage')->name('billing.addons.request');
    Route::delete('/billing/addons/{addon:key}', [BillingController::class, 'removeAddon'])->middleware('permission:billing.manage')->name('billing.addons.remove');
    Route::put('/billing/cycle', [BillingController::class, 'updateCycle'])->middleware('permission:billing.manage')->name('billing.cycle');
    Route::put('/billing/details', [BillingController::class, 'updateDetails'])->middleware('permission:billing.manage')->name('billing.details');

    Route::get('/help', [\App\Http\Controllers\HelpController::class, 'index'])->name('help.index');

    // Feedback / request inbox. Anyone may file a request and follow their own
    // thread; only the triage status change is permission-gated (the controller
    // enforces "manager OR the ticket's owner" on the rest, so gating them would
    // lock ordinary members out of their own requests).
    Route::get('/feedback', [\App\Http\Controllers\FeedbackController::class, 'index'])->name('feedback.index');
    Route::post('/feedback', [\App\Http\Controllers\FeedbackController::class, 'store'])->middleware('throttle:feedback')->name('feedback.store');
    Route::match(['put', 'patch'], '/feedback/{feedback}', [\App\Http\Controllers\FeedbackController::class, 'update'])->middleware('permission:feedback.manage')->name('feedback.update');
    Route::post('/feedback/{feedback}/reply', [\App\Http\Controllers\FeedbackController::class, 'reply'])->middleware('throttle:feedback')->name('feedback.reply');
    Route::post('/feedback/{feedback}/seen', [\App\Http\Controllers\FeedbackController::class, 'seen'])->middleware('throttle:feedback')->name('feedback.seen');

    Route::get('/desktop-downloads', [DesktopDownloadController::class, 'index'])->name('desktop-downloads.index');
    // Throttled: these stream a ~100 MB installer, and PHP-FPM holds one worker
    // for the whole transfer. A handful of concurrent downloads exhausted the
    // pool and took the site down — along with the other sites on this box.
    // 10/min is far above any honest use (you install the app once).
    Route::get('/desktop-downloads/windows', [DesktopDownloadController::class, 'windows'])
        ->middleware('throttle:10,1')->name('desktop-downloads.windows');
    Route::get('/desktop-downloads/mac', [DesktopDownloadController::class, 'mac'])
        ->middleware('throttle:10,1')->name('desktop-downloads.mac');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->middleware('permission:users.manage')->name('users.create');
    Route::post('/users/bulk-update', [UserController::class, 'bulkUpdate'])->middleware('permission:users.manage')->name('users.bulk-update');
    // Archive lifecycle — must sit before the /users/{user} routes so the
    // literal segments aren't swallowed by the wildcard.
    Route::post('/users/bulk-status', [UserController::class, 'bulkStatus'])->middleware('permission:users.manage')->name('users.bulk-status');
    Route::patch('/users/{user}/status', [UserController::class, 'setStatus'])->middleware('permission:users.manage')->name('users.set-status');
    Route::post('/users/designations', [UserController::class, 'storeDesignation'])->middleware('permission:users.manage')->name('users.designations.store');
    Route::delete('/users/designations/{designation}', [UserController::class, 'destroyDesignation'])->middleware('permission:users.manage')->name('users.designations.destroy');
    // Named shifts — literal segment declared before the /users/{user} wildcards.
    Route::post('/users/shifts', [UserController::class, 'storeShift'])->middleware('permission:users.manage')->name('users.shifts.store');
    Route::delete('/users/shifts/{shift}', [UserController::class, 'destroyShift'])->middleware('permission:users.manage')->name('users.shifts.destroy');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage')->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.manage')->name('users.edit');
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage')->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->name('users.destroy');

    // Customizable teams (groups). Org-scoped; mutations require user management.
    Route::get('/teams', [TeamManagementController::class, 'index'])->middleware('permission:users.view')->name('teams.index');
    Route::post('/teams', [TeamManagementController::class, 'store'])->middleware('permission:users.manage')->name('teams.store');
    Route::match(['put', 'patch'], '/teams/{team}', [TeamManagementController::class, 'update'])->middleware('permission:users.manage')->name('teams.update');
    Route::delete('/teams/{team}', [TeamManagementController::class, 'destroy'])->middleware('permission:users.manage')->name('teams.destroy');

    // One-day shift changes. The page + write endpoints are gated to people who
    // can edit a shift at all; the controller enforces self (shift.edit_own) vs
    // anyone (shift.manage_all) and the today/future rule per action.
    Route::get('/my-schedule', [ShiftOverrideController::class, 'index'])->middleware('permission:shift.edit_own,shift.manage_all')->name('shift-overrides.index');
    Route::post('/shift-overrides', [ShiftOverrideController::class, 'store'])->middleware('permission:shift.edit_own,shift.manage_all')->name('shift-overrides.store');
    Route::delete('/shift-overrides/{shiftOverride}', [ShiftOverrideController::class, 'destroy'])->middleware('permission:shift.edit_own,shift.manage_all')->name('shift-overrides.destroy');

    Route::get('/report', [WorkHourController::class, 'report'])
        ->middleware('permission:reports.view')
        ->name('work-hours.report');
    Route::get('/work-hours/export', [WorkHourController::class, 'export'])
        ->middleware('permission:reports.export')
        ->name('work-hours.export');
    Route::post('/report/slack', [SlackReportController::class, 'store'])
        ->middleware(['permission:reports.send_slack', 'entitlement:integrations'])
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
    Route::post('/clients/bulk-status', [ClientController::class, 'bulkStatus'])
        ->middleware('permission:clients.manage')
        ->name('clients.bulk-status');
    Route::patch('/clients/{client}/status', [ClientController::class, 'setStatus'])
        ->middleware('permission:clients.manage')
        ->name('clients.set-status');
    Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view')->name('clients.index');
    Route::get('/clients/create', [ClientController::class, 'create'])->middleware('permission:clients.manage')->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.manage')->name('clients.store');
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->middleware('permission:clients.manage')->name('clients.edit');
    Route::match(['put', 'patch'], '/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage')->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('permission:clients.manage')->name('clients.destroy');

    Route::get('/upwork-profiles', [UpworkProfileController::class, 'index'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.view'])->name('upwork-profiles.index');
    Route::get('/upwork-profiles/create', [UpworkProfileController::class, 'create'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.create');
    Route::post('/upwork-profiles', [UpworkProfileController::class, 'store'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.store');
    Route::post('/upwork-profiles/bulk-status', [UpworkProfileController::class, 'bulkStatus'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.bulk-status');
    Route::patch('/upwork-profiles/{upwork_profile}/status', [UpworkProfileController::class, 'setStatus'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.set-status');
    Route::get('/upwork-profiles/{upwork_profile}', [UpworkProfileController::class, 'show'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.view'])->name('upwork-profiles.show');
    Route::get('/upwork-profiles/{upwork_profile}/edit', [UpworkProfileController::class, 'edit'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.edit');
    Route::match(['put', 'patch'], '/upwork-profiles/{upwork_profile}', [UpworkProfileController::class, 'update'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.update');
    Route::delete('/upwork-profiles/{upwork_profile}', [UpworkProfileController::class, 'destroy'])->middleware(['entitlement:upwork_profiles', 'permission:profiles.manage'])->name('upwork-profiles.destroy');

    // Machine Compliance — the endpoint-inventory review surface. Access is
    // decided ONLY by the `machine_compliance` entitlement key (granted by the
    // agency_toolkit add-on, a plan, or a per-company override from the operator
    // panel) — never by plan name and never by workspace_type, so the operator
    // can open this for any company without a deploy.
    // MUST stay above the marketing `/{slug}` catch-all at the end of this file,
    // which would otherwise swallow GET /compliance.
    Route::prefix('compliance')->middleware('entitlement:machine_compliance')->group(function () {
        Route::get('/', [ComplianceController::class, 'index'])
            ->middleware('permission:compliance.view')
            ->name('monitoring.compliance');

        // Acknowledge / ignore / reopen a finding. Accepts an optional `ids`
        // array so one click clears a tool across every browser profile it sits
        // in; the controller re-scopes that list to the flag's own machine+rule.
        Route::patch('/flags/{flag}', [ComplianceController::class, 'updateFlag'])
            ->middleware('permission:compliance.manage')
            ->whereNumber('flag')
            ->name('monitoring.compliance.flag');

        // Delete a decommissioned machine and its whole compliance history.
        Route::delete('/machines/{machine}', [ComplianceController::class, 'destroyMachine'])
            ->middleware('permission:compliance.manage')
            ->whereNumber('machine')
            ->name('monitoring.compliance.machine.destroy');
    });

    Route::get('/employee-attendance', [EmployeeAttendanceController::class, 'index'])->middleware('permission:attendance.view')->name('employee-attendance.index');
    Route::get('/employee-attendance/summary', [EmployeeAttendanceController::class, 'getSummary'])->middleware('permission:attendance.view')->name('employee-attendance.summary');
    Route::get('/employee-attendance/detailed', [EmployeeAttendanceController::class, 'getDetailed'])->middleware('permission:attendance.view')->name('employee-attendance.detailed');
    Route::get('/employee-attendance/timeline', [EmployeeAttendanceController::class, 'getTimeline'])->middleware('permission:attendance.view')->name('employee-attendance.timeline');
    Route::get('/employee-attendance/monthly', [EmployeeAttendanceController::class, 'getMonthlyGrid'])->middleware(['permission:attendance.view', 'throttle:30,1'])->name('employee-attendance.monthly');
    Route::get('/employee-attendance/manual-history', [EmployeeAttendanceController::class, 'getManualHistory'])->middleware('permission:attendance.view')->name('employee-attendance.manual-history');
    Route::patch('/employee-attendance/manual-status', [EmployeeAttendanceController::class, 'updateManualStatus'])->middleware('permission:attendance.view')->name('employee-attendance.manual-status');
    Route::post('/employee-attendance/calendar', [EmployeeAttendanceController::class, 'updateCalendar'])->middleware('permission:attendance.view')->name('employee-attendance.calendar');
    Route::post('/employee-attendance/slack', [EmployeeAttendanceController::class, 'sendSlack'])
        ->middleware(['permission:reports.send_slack', 'entitlement:integrations'])
        ->name('employee-attendance.slack');
    Route::get('/employee-attendance/export', [EmployeeAttendanceController::class, 'export'])->middleware('permission:attendance.export')->name('employee-attendance.export');
    // Admin clock-time editing: rewrites recorded punches, so its own permission key.
    Route::get('/employee-attendance/day-entries', [EmployeeAttendanceController::class, 'getDayEntries'])->middleware('permission:attendance.edit_times')->name('employee-attendance.day-entries');
    Route::post('/employee-attendance/clock-times', [EmployeeAttendanceController::class, 'updateClockTimes'])->middleware('permission:attendance.edit_times')->name('employee-attendance.clock-times');
    Route::post('/employee-attendance/clock-out', [EmployeeAttendanceController::class, 'adminClockOut'])->middleware('permission:attendance.edit_times')->name('employee-attendance.clock-out');

    Route::resource('work-hours', WorkHourController::class)->except(['show']);
    Route::get('/work-hours-export', [WorkHourController::class, 'exportPersonal'])->name('work-hours.export-personal');
    Route::post('/work-hours/bulk-delete', [WorkHourController::class, 'bulkDelete'])->name('work-hours.bulk-delete');

    // ── Timesheet approvals + pay periods ────────────────────────────────
    // The payroll surface: each pay period is one timesheet per person,
    // submitted by them and signed off by an approver. Approving FREEZES that
    // period's time entries (WorkHour::isApprovalLocked()).
    // Gated by the `timesheet_approvals` feature key so the operator can move it
    // between plans from the panel — never by plan name.
    // MUST stay above the marketing `/{slug}` catch-all at the end of this file.
    Route::prefix('timesheets')->middleware('entitlement:timesheet_approvals')->group(function () {
        Route::get('/', [TimesheetApprovalController::class, 'index'])->name('timesheets.index');

        // Submitting is SELF-ONLY and takes no user id, so it carries no
        // permission gate — attesting to your own period is not a privileged act.
        Route::post('/submit', [TimesheetApprovalController::class, 'submit'])->name('timesheets.submit');

        Route::post('/review', [TimesheetApprovalController::class, 'review'])
            ->middleware('permission:timesheets.approve,timesheets.approve_own')
            ->name('timesheets.review');
        Route::post('/bulk-review', [TimesheetApprovalController::class, 'bulkReview'])
            ->middleware('permission:timesheets.approve,timesheets.approve_own')
            ->name('timesheets.bulk-review');
        Route::post('/reopen', [TimesheetApprovalController::class, 'reopen'])
            ->middleware('permission:timesheets.reopen')
            ->name('timesheets.reopen');

        // Declared LAST inside the prefix so the literal segments above can
        // never be read as a user id.
        Route::get('/{user}', [TimesheetApprovalController::class, 'show'])
            ->whereNumber('user')->name('timesheets.show');
    });

    // INSIGHTS v1 — utilization vs target, the activity gauge, billable /
    // work-category split, worked-hours trend + ribbon, and derived highlights.
    // Read-only. Gated by the `insights` feature key (Growth+ per the pricing
    // matrix) plus a view permission.
    Route::get('/insights', [InsightsController::class, 'index'])
        ->middleware(['entitlement:insights', 'permission:insights.view'])
        ->name('insights.index');

    // Time tracking routes
    Route::prefix('time-entries')->group(function () {
        Route::get('/', [TimeEntryController::class, 'index'])->name('time-entries.index');
        Route::post('/', [TimeEntryController::class, 'store'])->name('time-entries.store');
        Route::get('/today', [TimeEntryController::class, 'getTodaysEntries'])->name('time-entries.today');
        Route::get('/today-summary', [TimeEntryController::class, 'getTodaysSummary'])->middleware('throttle:60,1')->name('time-entries.today-summary');
        Route::get('/dashboard-trend', [TimeEntryController::class, 'dashboardTrend'])->middleware('throttle:60,1')->name('time-entries.dashboard-trend');
        Route::get('/team-activity', [TimeEntryController::class, 'teamActivity'])->middleware('throttle:30,1')->name('time-entries.team-activity');
        Route::get('/export', [TimeEntryController::class, 'export'])->name('time-entries.export');
    });

    // Daily timeline view (scrin.io-style). Anyone can see their own; viewing
    // other users requires the timeline.view_others permission.
    Route::prefix('timeline')->group(function () {
        Route::get('/', [TimelineController::class, 'index'])->name('timeline.index');
        Route::get('/data', [TimelineController::class, 'data'])->name('timeline.data');
        Route::get('/history', [TimelineController::class, 'history'])->name('timeline.history');
        Route::get('/ai-summary', [TimelineController::class, 'aiSummary'])
            ->middleware('entitlement:ai')
            ->name('timeline.ai-summary');
    });

    // Manager chat over aggregated team work data (last 14 days).
    Route::get('/ai-assistant', [\App\Http\Controllers\AiAssistantController::class, 'index'])
        ->middleware(['permission:ai.assistant', 'entitlement:ai'])
        ->name('ai.assistant');
    Route::post('/ai-assistant/ask', [\App\Http\Controllers\AiAssistantController::class, 'ask'])
        ->middleware(['permission:ai.assistant', 'entitlement:ai', 'throttle:20,1'])
        ->name('ai.assistant.ask');
    Route::get('/ai-assistant/config', [\App\Http\Controllers\AiAssistantController::class, 'config'])
        ->middleware(['permission:ai.assistant', 'entitlement:ai'])
        ->name('ai.assistant.config');
    Route::post('/ai-assistant/questions', [\App\Http\Controllers\AiAssistantController::class, 'saveQuestions'])
        ->middleware(['permission:ai.assistant', 'entitlement:ai'])
        ->name('ai.assistant.questions');


    // Team day snapshot. Anyone with timeline.view_others sees the team
    // leaderboard; live "currently tracking" indicators are visible to all
    // permitted viewers regardless of monitoring.view_screenshots.
    Route::get('/team', [TeamController::class, 'index'])
        ->middleware('permission:timeline.view_others')
        ->name('team.index');
    Route::get('/team/apps', [TeamController::class, 'apps'])
        ->middleware(['permission:timeline.view_others', 'entitlement:app_url_tracking'])
        ->name('team.apps');
    Route::post('/team/slack-digest', [TeamController::class, 'sendDigest'])
        ->middleware(['permission:reports.send_slack', 'entitlement:integrations'])
        ->name('team.slack-digest');

    // Tracking / monitoring settings (scrin.io-style). Super Admin only by
    // default via the monitoring.settings permission.
    Route::prefix('settings')->middleware('permission:monitoring.settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/team', [SettingsController::class, 'updateTeam'])->name('settings.team.update');
        Route::put('/attendance', [SettingsController::class, 'updateAttendance'])->name('settings.attendance.update');
        Route::put('/pay-period', [SettingsController::class, 'updatePayPeriod'])->name('settings.pay-period.update');
        Route::put('/users/{user}', [SettingsController::class, 'updateUser'])->name('settings.user.update');

        // Work categories — the workspace's own editable "what the work was"
        // list (documents/STAFFVORA_WORK_VOCABULARY.md concept B). `reorder` is
        // declared BEFORE the {workCategory} route and both are whereNumber()
        // constrained, so PUT .../reorder can never be swallowed as an id.
        // NOTE: these must stay inside this group — the marketing catch-all
        // `/{slug}` near the end of this file matches "settings/work-categories"
        // and would shadow a GET registered after it.
        Route::get('/work-categories', [SettingsController::class, 'workCategories'])->name('settings.work-categories');
        Route::post('/work-categories', [SettingsController::class, 'storeWorkCategory'])->name('settings.work-categories.store');
        Route::put('/work-categories/reorder', [SettingsController::class, 'reorderWorkCategories'])->name('settings.work-categories.reorder');
        Route::put('/work-categories/{workCategory}', [SettingsController::class, 'updateWorkCategory'])->whereNumber('workCategory')->name('settings.work-categories.update');
        Route::delete('/work-categories/{workCategory}', [SettingsController::class, 'destroyWorkCategory'])->whereNumber('workCategory')->name('settings.work-categories.destroy');

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
    Route::prefix('monitoring')->middleware('entitlement:monitoring')->group(function () {
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

// Cross-host impersonation hand-off. The operator portal (platform host) mints a
// short-lived RELATIVE signed URL pointing here; this logs the customer in on the
// customer host under the `web` guard, keeping the two hosts' session cookies
// isolated. The signature (not a login session) is what authorizes the hand-off.
Route::get('/impersonate/enter', [PlatformController::class, 'enterImpersonation'])
    ->middleware('signed:relative')->name('impersonate.enter');

// Return from impersonation — auth only (the impersonated user is the customer).
Route::post('/stop-impersonating', [PlatformController::class, 'stopImpersonating'])
    ->middleware('auth')->name('platform.stop-impersonating');

require __DIR__.'/auth.php';

// SEO: XML sitemap of every published marketing page (declared before the
// catch-all so the literal path wins).
Route::get('/sitemap.xml', [SitePageController::class, 'sitemap'])->name('site.sitemap');

// Marketing blog (index + posts) — literal paths before the slug catch-all.
Route::get('/blog', [\App\Http\Controllers\Site\BlogController::class, 'index'])->name('site.blog');
Route::get('/blog/{slug}', [\App\Http\Controllers\Site\BlogController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')->name('site.blog.show');

// Published marketing pages by slug (e.g. /pricing, /privacy, /solutions/agencies).
// Declared LAST so it can never shadow an app route — only unmatched lowercase
// slugs reach it (one optional nested level allowed), and the controller 404s
// when there's no published page.
Route::get('/{slug}', [SitePageController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)?')
    ->name('site.page');
