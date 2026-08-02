# Tracksuite

Privacy-conscious time tracking and workforce insights for agencies, service
teams, BPO operations, freelancers, and general project-based teams. The web
application is built with Laravel, Inertia, and React; optional desktop tracking
is provided by Electron.

## Features

- Timer, manual time, timesheets, attendance, and project reporting
- Optional screenshots, activity levels, and app/URL insights
- Project, agency-client, and BPO-account workspace modes
- Agency-only Upwork profile support that stays hidden for general teams
- Team roles, granular permissions, tenant isolation, and plan entitlements
- CSV exports, Slack reporting, AI summaries, billing, and onboarding
- Windows and macOS desktop tracker with offline capture queues

## Requirements

- PHP 8.2 or newer
- Composer 2
- Node.js 22 or newer
- npm 10 or newer
- MySQL 8 or compatible MariaDB version

## Local Setup

```bash
composer install
npm ci
copy .env.example .env
php artisan key:generate
```

Configure the database values in `.env`, then run:

```bash
php artisan migrate
php artisan tenant:create "Acme Co" owner@acme.test
npm run build
php artisan serve
```

`tenant:create` provisions an organization, its owner (a `super_admin`), the
default team, settings, and a trial subscription in one step. Staffvora is
multi-tenant: **every user must belong to an organization.** A user row with
`organization_id = NULL` authenticates normally and would then run every query
with no tenant filter, so the `EnsureTenantBound` (`tenant.required`) middleware
refuses such an account across the entire authenticated surface. Verify the
invariant at any time with:

```bash
php artisan tenancy:audit
```

Operator/landlord access to the platform portal uses a separate model — grant it
with `php artisan platform:grant`, never by creating a customer super admin.

Open `http://localhost:8000`.

For development with hot module replacement, use `npm run dev` in a second
terminal instead of `npm run build`.

If Windows PowerShell blocks `npm.ps1`, use `npm.cmd` for the same commands,
for example `npm.cmd run build`.

## Demo Data

The optional demo seeder creates sample users, attendance, projects, profiles,
and work-hour entries. Upwork profiles are only relevant to agency workspaces.

```bash
php artisan db:seed --class=DemoDataSeeder
```

Do not run the demo seeder on production.

## Access Control

- **Super Admin:** Workspace owner with every workspace permission
- **Admin:** Permissions selected by a workspace owner
- **Member:** Personal work diary plus any explicitly assigned permissions

The permission catalogue is defined in `config/access.php`.
New accounts are created from the Users page by an authorized administrator.

## Slack Reports

Set these values only in the server `.env` file:

```env
SLACK_REPORT_WEBHOOK_URL=
SLACK_WEEKLY_REPORT_ENABLED=false
SLACK_REPORT_TIMEZONE=Asia/Karachi
```

The webhook must never be committed. See
`documents/SLACK_INTEGRATION.md` for the scheduler and report details.

## Verification

```bash
composer validate --strict
composer audit --locked
npm audit --omit=dev
php artisan test
npm run build
php artisan route:cache
php artisan config:cache
```

Clear local caches after verification:

```bash
php artisan optimize:clear
```

## Production Deployment

1. Copy `.env.production.example` to `.env` on the server.
2. Set a production `APP_KEY`, database credentials, URL, mail settings, and
   optional Slack settings.
3. Set `APP_ENV=production` and `APP_DEBUG=false`.
4. Point the web server document root to the application's `public` directory.
5. Run the production helper from the release directory:

```bash
./deploy-production.sh
```

Configure Laravel's scheduler to run every minute:

```cron
* * * * * cd /path/to/tracksuite && php artisan schedule:run >> /dev/null 2>&1
```

The deployment destination, web-server configuration, secrets, queue workers,
TLS, backups, and rollback strategy belong to the hosting environment. This
repository intentionally contains no company-specific auto-deploy path.

Before a public launch, replace every `[REPLACE]` item in the site builder and
have the Terms of Service and Privacy Policy reviewed by qualified counsel.

## Security

- Never commit `.env`, webhook URLs, API tokens, database passwords, or admin
  passwords.
- Create the first workspace and its owner with
  `php artisan tenant:create "Acme Co" owner@acme.test`.
- `user:create-admin` is deprecated and now **requires** `--org=<id|slug>`; it
  only adds an extra super admin to an existing workspace. `AdminUserSeeder`
  likewise requires `ADMIN_ORG` alongside `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
  Neither can create the org-less account that used to bypass tenant scoping.
- Run `php artisan tenancy:audit` after every deploy (and in CI). A non-zero
  exit means a user exists with no organization — that account has unrestricted
  cross-customer read/write and must be deleted or re-homed immediately.
- Rotate any credential that has appeared in chat, screenshots, or logs.

## License

MIT
