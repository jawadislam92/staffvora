# Production Deployment

## Requirements

- PHP 8.2 or newer with the extensions required by Laravel
- Composer 2
- Node.js 20 or newer and npm 10 or newer
- MySQL 8 or compatible MariaDB
- A web root pointing to the `public` directory

## First Deployment

Create `.env` from `.env.production.example`, then set the production URL,
application key, database, mail, administrator, and optional Slack values.

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Provision the first workspace (organization + owner + settings + trial) — do NOT
create a bare admin user, the product is multi-tenant and every user must belong
to an organization:

```bash
php artisan tenant:create "Acme Co" owner@acme.test
```

To add another super admin to an EXISTING workspace, `user:create-admin` now
requires the target org:

```bash
php artisan user:create-admin --org=acme --name="Ops" --email=ops@acme.test
```

## Post-deploy tenancy check (required)

Run this after every migration and deploy. A non-zero exit means at least one
user has `organization_id = NULL`; such an account signs in on the ordinary
login page and, before the `tenant.required` middleware landed, ran every query
with no organization filter — total cross-customer read and write. Delete or
re-home the row before serving traffic.

```bash
php artisan tenancy:audit
```

The equivalent manual query:

```sql
SELECT id, email, role FROM users WHERE organization_id IS NULL;
```

Note that the project ships no database-level foreign keys (SQLite + MySQL
portability), so this invariant is enforced in the application and by this
command — not by the schema.

## Scheduler

The weekly Slack report is scheduled for Sunday at 10:00 AM in the configured
timezone. Run Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/timetracker && php artisan schedule:run >> /dev/null 2>&1
```

## cPanel

The repository `.cpanel.yml` installs dependencies, builds assets, runs
migrations, and caches Laravel. It preserves `.env`, uploaded files, logs, and
runtime storage. Confirm that `composer`, `npm`, `php`, and `/usr/bin/rsync`
are available to the cPanel deployment task.

Back up the database and `storage/app/public` before each production release.

## Hostinger hPanel Git Deployment

This repository also supports Hostinger's shared-hosting layout where the
complete Laravel project is deployed directly into `public_html`. The root
`index.php` boots Laravel from that directory, while the root `.htaccess`
serves assets from `public` and blocks direct access to application source.

Select the `jawad` branch and `public_html` as the deployment directory. Before
the first deployment, back up the existing `.env`, database, and
`storage/app/public` uploads. Hostinger requires an empty directory when first
connecting a Git deployment, so restore those private/runtime files afterward.

The production Vite build is committed because hPanel Git deployment only
copies repository files. After each deployment that changes PHP dependencies
or database migrations, run through SSH:

```bash
cd ~/domains/timetracker.sparkingasia.com/public_html
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Keep the production `.env` on the server. It is excluded from Git and must
include the database credentials, `APP_KEY`, production URL, and Slack webhook.
