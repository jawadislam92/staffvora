#!/usr/bin/env bash
#
# Staffvora — server-side deploy.
#
# This is section 7 of documents/PRODUCTION_DEPLOYMENT.md and nothing else.
# The runbook is the source of truth; if the two ever disagree, the runbook
# wins and this script is the thing that is wrong.
#
# Run it FROM the app folder on the server, AFTER the new code is on disk
# (Hostinger's panel pulls it, or you ran `git pull` yourself):
#
#     cd /home/USER/staffvora && ./deploy-production.sh
#
# It does NOT build front-end assets. Assets are built on your machine and the
# built public/build folder is uploaded — see §5 of the runbook. Building on a
# shared VPS is how deploys run out of memory at the worst possible moment.
#
# It does NOT pull code, and it does NOT take the database backup for you.
# Take the backup first (§10.0) — it is the only thing that makes a rollback
# possible.

set -euo pipefail

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
fail() { printf '\033[31mERROR: %s\033[0m\n' "$1" >&2; exit 1; }

[[ -f artisan ]] || fail "No artisan file here. Run this from the application folder."
[[ -f .env ]] || fail ".env is missing. Copy .env.production.example to .env and fill it in first."

if [[ ! -d public/build ]]; then
    fail "public/build is missing — the front-end assets were never uploaded.
       Build them on your machine (npm ci && npm run build) and upload
       public/build to the server. See §5 of documents/PRODUCTION_DEPLOYMENT.md."
fi

step "1/4  PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

step "2/4  The three things the host does NOT do for you"
php artisan migrate --force
php artisan storage:link
php artisan queue:restart          # workers must pick up the new code

step "3/4  Caches"
php artisan optimize:clear
php artisan optimize               # config + routes + views

step "4/4  Prove it"
# Exits non-zero on a real problem, which -e turns into a failed deploy.
php artisan deploy:preflight

printf '\n\033[32mDeploy finished and pre-flight passed.\033[0m\n'
printf 'Now run the 10-minute smoke test in §9 of documents/PRODUCTION_DEPLOYMENT.md\n'
printf 'before telling anyone the site is updated.\n'
