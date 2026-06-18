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

Create the first administrator interactively if it was not seeded:

```bash
php artisan user:create-admin
```

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
