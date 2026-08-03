# Staffvora — Production Deploy Runbook

**Who this is for:** the owner. You do not need to be a developer to follow it.
Every command is written out in full. Where you must decide something, it says so.

**Where it deploys to:** the shared Hostinger VPS at **2.24.11.90**, which ALSO
hosts other live sites (satimetracker.com / SA Track, gymrun.tech, and
books.sparkingasia.com). Nothing in this runbook restarts a shared service or
rewrites a global config. Every step is scoped to Staffvora's own folder, its own
nginx file, and its own database. **If a step ever asks you to restart nginx or
php-fpm, that briefly affects the other sites too — do it once, at the end, and
at a quiet hour.**

**Two addresses go live:**

| Address | What it serves |
| --- | --- |
| `https://staffvora.com` (+ `www`) | Marketing site, customer sign-up, the app itself, and the desktop update feed |
| `https://platform.staffvora.com` | The operator/landlord console — **your** console, not a customer's |

Wildcard DNS (`*.staffvora.com`) already points at the VPS, so **no new DNS
record is needed** for the platform host. It still needs its own nginx server
block and its own certificate (see §3).

---

## Quick reference (once it's live)

```bash
cd /home/USER/staffvora           # <- your real path; fill it in once and reuse
php artisan deploy:preflight      # "is this server OK?" in plain English
php artisan migrate --force       # apply database updates (NEVER skipped)
php artisan storage:link          # re-create the uploads link after a deploy
php artisan optimize:clear        # clear caches
php artisan optimize              # rebuild caches
```

The **one thing people get wrong**: Hostinger's git deploy copies **code only**.
It does **not** run migrations, does **not** rebuild the uploads link, and does
**not** restart the queue worker. If you deploy and stop there, the site will
break the moment someone touches a feature that needed a new database column.
**Every deploy ends with §7.**

---

## 0. Before you start — decisions and accounts you need

| You need | Why | Who does it |
| --- | --- | --- |
| SSH access to 2.24.11.90 | Everything below runs there | You (Hostinger panel → SSH) |
| A MySQL database + user for Staffvora **only** | Never share a database with SA Track or SparkBooks | You, in hPanel |
| An SMTP mailbox on staffvora.com | Invitations, password resets, receipts | You (Hostinger email or a provider) |
| The legal company name | Goes on invoices and Terms | You |
| A decision: where installers live | The desktop `.exe` must sit OUTSIDE the deployed folder | You (§8) |

**You do not need** a payment gateway to go live. There is **no card checkout
wired yet** (Alfa is parked pending the bank's spec). Signup, trials and manual
invoicing all work without it. Nothing on the site may promise instant self-serve
card payment until a gateway exists.

---

## 1. Server requirements

- PHP **8.3** (8.2 is the floor, 8.3 is what we build against) with the standard
  Laravel extensions: `bcmath ctype curl dom fileinfo json mbstring openssl pcre pdo pdo_mysql tokenizer xml zip`, plus `gd` (thumbnails) and `intl`
- Composer 2
- Node.js 22 + npm 10 — **only needed if you build assets on the server** (see §5)
- MySQL 8 or MariaDB 10.6+
- nginx (already on the box for the other sites)
- `unzip`, `git`, `rsync`

Check quickly:

```bash
php -v && composer --version && node -v && mysql --version
php -m | tr '\n' ' '
```

---

## 2. Put the code on the server

Deploy into a folder of its own, **beside** the other sites, not inside them.

```bash
cd /home/USER
git clone -b staffvora-rebuild <repo-url> staffvora
cd staffvora
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

If you use Hostinger's built-in Git deployment instead of `git clone`, point it
at branch `staffvora-rebuild` and at this folder. **Remember what it does not
do** (migrations, storage link, worker restart) — §7 covers all of it.

> **Warning about Hostinger git deploy:** it re-clones the tree and deletes files
> that are not in the repository. That is why uploaded avatars/screenshots belong
> on S3 (§4) and desktop installers belong outside the folder (§8). Anything you
> put inside `storage/` by hand will disappear on the next deploy.

---

## 3. nginx + SSL for both hosts

Create **one** nginx file for Staffvora. Do not edit the other sites' files.

`/etc/nginx/sites-available/staffvora.conf`:

```nginx
# ── Customer site + app ─────────────────────────────────────────────
server {
    listen 80;
    listen [::]:80;
    server_name staffvora.com www.staffvora.com platform.staffvora.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name staffvora.com www.staffvora.com;

    root /home/USER/staffvora/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/staffvora.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/staffvora.com/privkey.pem;

    charset utf-8;
    client_max_body_size 32M;      # desktop screenshot uploads + installer downloads

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # confirm the real socket path
        fastcgi_read_timeout 120;
    }

    # Desktop installers are large; let them stream without a proxy timeout.
    location /desktop-updates/ {
        try_files $uri $uri/ /index.php?$query_string;
        fastcgi_read_timeout 600;
    }

    location ~ /\.(?!well-known).* { deny all; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_log  /var/log/nginx/staffvora-error.log;
    access_log /var/log/nginx/staffvora-access.log;
}

# ── Operator console ────────────────────────────────────────────────
# Same code, same folder — the app routes by hostname (config/platform.php).
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name platform.staffvora.com;

    root /home/USER/staffvora/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/staffvora.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/staffvora.com/privkey.pem;

    charset utf-8;
    client_max_body_size 32M;

    add_header X-Robots-Tag "noindex, nofollow" always;   # never index the console
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }

    error_log  /var/log/nginx/staffvora-platform-error.log;
    access_log /var/log/nginx/staffvora-platform-access.log;
}
```

Enable it and get a certificate covering **both** names in one go:

```bash
sudo ln -s /etc/nginx/sites-available/staffvora.conf /etc/nginx/sites-enabled/
sudo nginx -t                                   # MUST say "syntax is ok"
sudo systemctl reload nginx                     # reload, not restart — no downtime for other sites

sudo certbot --nginx -d staffvora.com -d www.staffvora.com -d platform.staffvora.com
sudo certbot renew --dry-run                    # prove auto-renewal works
```

> `nginx -t` failing means the other sites would go down on reload. **Never
> reload after a failed `nginx -t`.** Fix the file first.

---

## 4. The `.env` file — the keys that must be set

Copy the template and edit it **on the server**. `.env` is never in git.

```bash
cp .env.production.example .env
nano .env
php artisan key:generate --force     # only ONCE, on first install
```

**Never paste real values into chat, a document, or a screenshot.** The list
below names the keys; you fill in the values.

### Must be set — the site is broken or unsafe without these

| Key | What to put |
| --- | --- |
| `APP_NAME` | `Staffvora` |
| `APP_ENV` | `production` |
| `APP_KEY` | Generated by `key:generate` — **never regenerate it later** (it would make existing encrypted data unreadable and log everyone out) |
| `APP_DEBUG` | `false` — if this is `true`, every error page shows your database password |
| `APP_URL` | `https://staffvora.com` |
| `PLATFORM_HOST` | `platform.staffvora.com` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The database you created in §0 |
| `SESSION_DRIVER` | `database` (survives a deploy; `file` gets wiped and logs everyone out) |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` — see §6, this needs a worker |
| `CACHE_DRIVER` | `file` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | Your SMTP mailbox |
| `MAIL_FROM_ADDRESS` | A real mailbox on staffvora.com, e.g. `hello@staffvora.com` |
| `MAIL_FROM_NAME` | `Staffvora` |
| `LOG_LEVEL` | `error` |

### Brand and legal

| Key | What to put |
| --- | --- |
| `BRAND_NAME` | `Staffvora` |
| `BRAND_LEGAL_NAME` | The **registered** company name — this is what appears on invoices and in the Terms |
| `BRAND_SUPPORT_EMAIL`, `BRAND_SALES_EMAIL` | `hello@staffvora.com` |
| `BRAND_ADDRESS`, `BRAND_PHONE`, `BRAND_WHATSAPP` | Optional; blank hides them |

### Storage — set these before you have real customers

Uploaded screenshots and avatars must **not** live inside the deployed folder,
because a git deploy deletes them.

| Key | What to put |
| --- | --- |
| `SCREENSHOTS_DRIVER` | `s3` |
| `AVATARS_DRIVER` | `s3` |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | An IAM user restricted to the Staffvora bucket only |
| `AWS_DEFAULT_REGION`, `SCREENSHOTS_BUCKET` (and optionally `AVATARS_BUCKET`) | The bucket you create for Staffvora |

Objects stay private and are served through signed URLs. **Do not reuse the SA
Track bucket** — separate product, separate bucket, separate IAM user.

### Background work

| Key | What to put |
| --- | --- |
| `SCHEDULER_HTTP_TOKEN` | A long random string (32–128 letters/digits). Only needed if you use the URL-based scheduler fallback in §6. Leave blank to disable that endpoint entirely. |
| `DESKTOP_INSTALLERS_PATH` | Absolute path to the installers folder from §8, e.g. `/home/USER/staffvora-installers` |

### Optional — leave blank until you actually want them

`SLACK_REPORT_WEBHOOK_URL`, `SLACK_WEEKLY_REPORT_ENABLED`, `SLACK_BOT_TOKEN`,
`SLACK_DAILY_DIGEST_ENABLED`, `TRACKER_HEALTH_SLACK`,
`ATTENDANCE_AUTO_CLOCKOUT`, `ATTENDANCE_STILL_WORKING_SLACK`,
`ANTHROPIC_API_KEY` (AI day summaries), `BILLING_GATEWAY` (leave **unset** —
there is no gateway yet), `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD`
(leave blank; use §5's `tenant:create` instead).

---

## 5. Build the front-end assets

The site will render as unstyled text if this step is skipped.

**Option A — build on your PC and upload (recommended on a shared VPS).**
`npm run build` is memory-hungry and can disturb the other sites.

```powershell
# on your Windows machine, in C:\laragon\www\Staffvora
npm ci
npm run build
```

Then upload `public/build/` to the server (SFTP, or commit it if the repo tracks
built assets).

**Option B — build on the server**, only if the box is idle:

```bash
npm ci && npm run build && rm -rf node_modules
```

---

## 6. Database, uploads, background jobs, scheduler

### 6.1 Database

```bash
php artisan migrate --force
```

`--force` is required because the app is in production mode. **This never runs
by itself.** Hostinger's deploy does not do it. If a deploy adds a migration and
you skip this, the site 500s on the first page that touches the new column.

Create the first workspace (organization + owner + settings + trial) — never a
bare admin user, because every user must belong to an organization:

```bash
php artisan tenant:create "Your Company" you@staffvora.com
```

Create your operator-console login. This is a **completely separate account
type** from a customer super-admin — it signs in at `platform.staffvora.com`,
not at the customer login:

```bash
php artisan platform:owner you@staffvora.com --name="Your Name" --random
```

`--random` generates a strong password and prints it **once**. Copy it into your
password manager immediately; it is not shown again. To add more operator staff
later, use `php artisan platform:grant`.

### 6.2 Uploads link

```bash
php artisan storage:link
```

Re-run this **after every deploy that re-clones the tree**, or uploaded logos
become broken images.

### 6.3 The queue worker — the silent killer

`config/queue.php` defaults to **`database`**. That means queued work (screenshot
thumbnails, mail, exports) is written into the `jobs` table and waits for a
worker. **With no worker, nothing errors — the work simply never happens**, and
you find out weeks later when a customer asks why thumbnails are missing.

Pick **one** of these:

**Option A — a supervised worker (best).** Create
`/etc/supervisor/conf.d/staffvora-worker.conf`:

```ini
[program:staffvora-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/USER/staffvora/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/home/USER/staffvora
autostart=true
autorestart=true
user=USER
numprocs=1
redirect_stderr=true
stdout_logfile=/home/USER/staffvora/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start staffvora-worker:*
sudo supervisorctl status staffvora-worker:*     # should say RUNNING
```

If the box uses systemd instead of supervisor, the equivalent unit is
`/etc/systemd/system/staffvora-worker.service` with
`ExecStart=/usr/bin/php /home/USER/staffvora/artisan queue:work --sleep=3 --tries=3 --max-time=3600`,
`Restart=always`, then `sudo systemctl enable --now staffvora-worker`.

**Option B — no worker at all: rely on the scheduled drain.** This already
exists in the app. `app/Console/Kernel.php` runs, every minute,
`queue:work --stop-when-empty --max-time=50 --max-jobs=200`, but **only if the
cron in §6.4 is installed**. Jobs are processed within about a minute instead of
instantly. This is a legitimate choice on shared hosting — it just means §6.4
becomes mandatory rather than merely important.

> **After every deploy, restart the worker** (`php artisan queue:restart`, or
> `sudo supervisorctl restart staffvora-worker:*`). A running worker holds the
> OLD code in memory and will keep running it until it is told to stop.

### 6.4 The scheduler cron — install this or background work stops

Without it: no retention pruning (screenshots pile up until the disk fills), no
stale-session closing (people stay "tracking" forever), no digests, no health
watchdog, and — if you chose Option B above — no queue processing at all.

```bash
crontab -e
```

Add exactly one line (use the real path, and `which php` to confirm the binary):

```cron
* * * * * cd /home/USER/staffvora && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Confirm within two minutes:

```bash
php artisan deploy:preflight        # "Scheduled tasks (cron)" must say OK
```

**Fallback if the panel's cron is unreliable:** set `SCHEDULER_HTTP_TOKEN` in
`.env` to a long random string, then have a free external pinger (cron-job.org,
UptimeRobot) request `https://staffvora.com/cron/run/<that-token>` every minute.
It is throttled and safe to call repeatedly. Do not publish the URL.

### 6.5 Caches

```bash
php artisan optimize:clear      # throw away the old caches first
php artisan optimize            # rebuild config + routes + views
```

Two things to know:

- **Re-run `php artisan optimize` after any `.env` change.** A cached config
  ignores `.env` entirely, so editing `.env` and not re-caching looks like the
  edit did nothing. This especially bites `PLATFORM_HOST` and `APP_URL`, which
  are baked into the cached routes.
- If a future code change adds a route defined with a closure, `optimize` will
  **fail loudly** at cache time with "Unable to prepare route for
  serialization". It cannot half-break the site. If that happens, run
  `php artisan route:clear` and tell whoever wrote it.

The app also self-heals: if a git deploy wipes `bootstrap/cache`, the scheduler
rebuilds the config cache within five minutes on its own.

---

## 7. The deploy checklist — every single time

Run these in order, from the app folder. Copy-paste friendly:

```bash
cd /home/USER/staffvora

# 1. New code
git pull                                  # (skip if Hostinger's panel already pulled)
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 2. The three things the host does NOT do for you
php artisan migrate --force
php artisan storage:link
php artisan queue:restart                 # workers must pick up the new code

# 3. Caches
php artisan optimize:clear
php artisan optimize

# 4. Prove it
php artisan deploy:preflight
```

> `deploy-production.sh` in the repo root now runs **exactly** steps 1–4 above
> and nothing else — it refuses to start if `public/build` was never uploaded,
> and it fails the deploy if `deploy:preflight` fails. Running
> `./deploy-production.sh` from the app folder is equivalent to copy-pasting the
> block. It does not pull code, build assets, or take the backup; you still do
> those. If the script and this section ever disagree, **this section is right
> and the script is the bug.**
>
> (It used to be an older script that printed the previous product name and
> built assets on the server. That version is gone.)

If `deploy:preflight` reports any **[ FAIL ]**, fix it before telling anyone the
site is updated.

---

## 8. Desktop installers on the server

The desktop `.exe` / `.dmg` and the auto-update feed are served by the app, but
the files themselves must live **outside** the deployed folder — a git deploy
deletes untracked files inside it.

```bash
mkdir -p /home/USER/staffvora-installers
# then in .env:
#   DESKTOP_INSTALLERS_PATH=/home/USER/staffvora-installers
php artisan config:cache
```

Upload the built installer, its `.blockmap`, and `latest.yml` into that folder.
Full instructions are in **`documents/DESKTOP_RELEASE_CHECKLIST.md`** — read that
before building anything.

**No version number is configured anywhere.** The `/desktop-downloads` page reads
`latest.yml` in that folder and reports whatever version and filename it names —
so publishing a new build is an upload, never a code change. If there is no
`latest.yml`, the page falls back to the highest-versioned installer it can see
in the folder; if the folder is empty, the page honestly says the app is not
available rather than offering a link that 404s. Discovery is cached for **60
seconds**, so a freshly uploaded installer can take up to a minute to appear —
`php artisan cache:clear` if you need it immediately.

---

## 9. Smoke test — 10 minutes, do it before announcing

Do this in a **private/incognito window** so you are not signed in.

**Public site**
1. `https://staffvora.com` loads, is styled, and the padlock shows.
2. `https://www.staffvora.com` redirects to the bare domain.
3. `http://staffvora.com` redirects to `https://`.
4. Every page linked in the header and footer opens — **pricing, features,
   security, about, contact, privacy, terms, download**. Any of them showing an
   empty page means that page was never published in the Site Builder.
5. Pricing shows the approved numbers and says nothing about single sign-on or
   an API (we have neither).

**Sign-up and the app**
6. Sign up as a brand-new company with a real email address.
7. **The welcome/verification email actually arrives.** If it does not, mail is
   misconfigured — nobody can join.
8. The onboarding wizard completes; the dashboard loads.
9. Clock in from the top bar, wait a minute, clock out. The time appears in
   Attendance.
10. Invite a second user; that invitation email arrives too.
11. Upload a workspace logo — it displays (this proves `storage:link` / S3).
12. Open a report and export a CSV.

**Operator console**
13. `https://platform.staffvora.com` loads and asks for operator sign-in.
14. Your new test company appears in the tenant list.
15. `https://platform.staffvora.com` is **not** reachable with a customer login.

**Background**
16. Wait 2 minutes, then `php artisan deploy:preflight` → "Scheduled tasks" OK.
17. `php artisan queue:failed` → empty.
18. `tail -50 storage/logs/laravel.log` → no repeating errors.

**Then clean up:** delete the test company from the operator console so it is not
counted as a real customer.

---

## 10. Rollback — when a deploy breaks the site

**First, decide which kind of broken it is.** This matters more than speed:

- **Code is broken, database is fine** → §10.1. Fast and safe.
- **A migration ran and the data is wrong** → §10.2. Slower; restore a backup.

### 10.0 Take a backup BEFORE every deploy (this is what makes rollback possible)

```bash
cd /home/USER
mysqldump -u DBUSER -p DBNAME | gzip > backup-staffvora-$(date +%F-%H%M).sql.gz
cp /home/USER/staffvora/.env /home/USER/env-backup-$(date +%F-%H%M)
cd /home/USER/staffvora && git rev-parse HEAD > /home/USER/last-good-commit.txt
```

Keep the last 7 days of these. Without a backup taken **before** the deploy,
§10.2 is not possible.

### 10.1 Roll the code back

```bash
cd /home/USER/staffvora
cat /home/USER/last-good-commit.txt          # the commit that was working
git reset --hard <that-commit>
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
php artisan deploy:preflight
```

Migrations are additive, so older code normally runs fine against a newer
database. If the broken deploy added a migration that **renamed or dropped**
something, you need §10.2 instead.

### 10.2 Roll the database back

```bash
# 1. Stop traffic making it worse
php artisan down --render="errors::503"
sudo supervisorctl stop staffvora-worker:*        # if you use supervisor

# 2. Restore the pre-deploy dump
gunzip < /home/USER/backup-staffvora-YYYY-MM-DD-HHMM.sql.gz | mysql -u DBUSER -p DBNAME

# 3. Put the matching code back (§10.1)

# 4. Back up
php artisan up
sudo supervisorctl start staffvora-worker:*
php artisan deploy:preflight
```

> **Do not use `php artisan migrate:rollback` on production.** Rollback methods
> are rarely exercised and several of ours drop columns. Restoring the dump is
> the honest path.

### 10.3 Emergency maintenance mode

```bash
php artisan down --secret="a-long-random-string"   # you can still browse via /a-long-random-string
php artisan up
```

---

## 11. Troubleshooting the five things that actually go wrong

| Symptom | Almost always | Fix |
| --- | --- | --- |
| Site is plain text, no styling | Assets were never built/uploaded | §5, then `php artisan optimize:clear` |
| "500 Server Error" right after a deploy | Migrations not applied | `php artisan migrate --force` |
| Logos/avatars are broken images | `public/storage` link missing, or S3 keys wrong | `php artisan storage:link`; check `SCREENSHOTS_DRIVER` / `AVATARS_DRIVER` |
| Emails never arrive | `MAIL_MAILER` is `log`, or the from-address is a placeholder | §4 mail keys, then `php artisan config:cache` |
| Thumbnails/exports never appear, no error anywhere | Queue has no worker | §6.3, and check `php artisan queue:failed` |
| A page shows nothing at all | That marketing page has no published content | Publish it in the Site Builder |
| Everyone got logged out after a deploy | `SESSION_DRIVER=file` got wiped, or `APP_KEY` changed | Set `SESSION_DRIVER=database`; never regenerate `APP_KEY` |

Logs to read, in this order:

```bash
tail -100 storage/logs/laravel.log
tail -100 /var/log/nginx/staffvora-error.log
php artisan queue:failed
```

---

## 12. Security checks that must pass before launch

```bash
php artisan deploy:preflight --strict   # everything green
php artisan tenancy:audit --details     # MUST exit 0
```

`tenancy:audit` failing means a user account exists that belongs to no
workspace. Such an account can read and write **every** customer's data. The
project ships no database-level foreign keys (for SQLite/MySQL portability), so
this command — not the schema — is what enforces the rule. **Delete or re-home
those accounts before anyone uses the site.**

Also confirm by hand:

- `APP_DEBUG=false` and the site shows a generic error page, not a stack trace.
- `.env` is not readable over the web: `curl -I https://staffvora.com/.env`
  must return 403 or 404.
- `https://platform.staffvora.com` is not reachable with a customer account.
- Terms of Service and Privacy Policy have been **reviewed by a lawyer** and
  name the real registered company. This is not a technical step and cannot be
  done for you.

---

## 13. Routine maintenance

| How often | Do this |
| --- | --- |
| Every deploy | §7, and take the §10.0 backup first |
| Daily (automatic) | Retention pruning at 02:30, platform pruning at 03:30 — driven by the cron in §6.4 |
| Weekly | `php artisan deploy:preflight`; skim `storage/logs/laravel.log`; check disk with `df -h` |
| Monthly | `sudo certbot renew --dry-run`; `composer audit --locked`; confirm database backups are actually restorable |
| Whenever a credential appears in a chat, screenshot or log | Rotate it immediately |

---

## Related documents

- `documents/DESKTOP_RELEASE_CHECKLIST.md` — building, signing and publishing the desktop app
- `documents/SECURITY.md` — the security posture
- `documents/SLACK_INTEGRATION.md` — optional Slack reporting
- `documents/STAFFVORA_ZERO_TO_100_PLAN.md` — the overall roadmap
