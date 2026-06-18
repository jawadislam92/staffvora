# Sparking Asia Time Tracker

Internal Laravel and React application for employee work diaries, attendance,
client assignments, reporting, and weekly Slack summaries.

## Codex Handoff

Before continuing development in a new Codex session or account, read
`documents/CODEX_COMPLETE_CHAT_AND_PROJECT_HANDOFF.md`. It consolidates the project
conversation, current Git and production state, non-negotiable workflow rules,
verification history, product decisions, cautions, and prioritized next steps.

All supporting project documentation is organized in `documents/`.

## Features

- Personal work diary with manual work-hour entries
- Team work-hours report with searchable multi-select filters
- CSV export for authorized users
- Attendance monitoring and dashboard summaries
- Client and Upwork profile management
- Super Admin, Admin, and Member roles with granular permissions
- Administrator-managed accounts with public registration disabled
- Manual Slack reports with selectable users and table columns
- Automatic weekly Slack report every Sunday at 10:00 AM

Portfolio functionality has intentionally been removed.

## Requirements

- PHP 8.2 or newer
- Composer 2
- Node.js 20 or newer
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
php artisan user:create-admin
npm run build
php artisan serve
```

Open `http://localhost:8000`.

For development with hot module replacement, use `npm run dev` in a second
terminal instead of `npm run build`.

If Windows PowerShell blocks `npm.ps1`, use `npm.cmd` for the same commands,
for example `npm.cmd run build`.

## Demo Data

The optional demo seeder creates sample users, attendance, clients, profiles,
and work-hour entries:

```bash
php artisan db:seed --class=DemoDataSeeder
```

Do not run the demo seeder on production.

## Access Control

- **Super Admin:** Every permission and integration setting
- **Admin:** Permissions selected by a Super Admin
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
5. Run:

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Configure Laravel's scheduler to run every minute:

```cron
* * * * * cd /path/to/timetracker && php artisan schedule:run >> /dev/null 2>&1
```

The repository's `.cpanel.yml` performs the same deployment steps while
preserving the server's existing `.env`.

## Security

- Never commit `.env`, webhook URLs, API tokens, database passwords, or admin
  passwords.
- Create the first administrator interactively with
  `php artisan user:create-admin`, or set `ADMIN_EMAIL` and `ADMIN_PASSWORD`
  before running `AdminUserSeeder`.
- Rotate any credential that has appeared in chat, screenshots, or logs.

## License

MIT
