# Staffvora

Multi-tenant SaaS time tracking and workforce insight for companies — clock
in/out, attendance, shifts, timesheets with approval, reports and insights, plus
an **optional** desktop tracker that can capture screenshots and activity when a
workspace turns it on.

The web application is Laravel 12 + Inertia v2 + React 18. The desktop tracker is
Electron (`desktop/`).

There is one product with two flavours: the **general edition** for any company,
and an **Agency toolkit add-on** (Upwork profiles, marketplace work categories,
Machine Compliance) for agencies. Editions are entitlement switches on a plan,
not separate codebases — a general customer upgrading to agency is a plan
change, not a migration.

## What it does

- Clock in / clock out, attendance, shifts and leave
- Timesheets with an approval workflow
- Timer and manual time, projects, clients and work categories
- Reports, exports and team insights
- Optional desktop tracker: screenshots, activity levels, app/URL insights,
  offline capture queues, self-diagnostics
- Agency toolkit (add-on): Upwork profiles, marketplace categories, Machine
  Compliance endpoint inventory
- Roles, granular permissions, strict tenant isolation, plan entitlements
- Operator/landlord console on its own host for running the platform itself

**Not built** — do not advertise these anywhere: there is **no single sign-on
(SSO/SAML)**, **no customer-facing API**, and **no payment gateway wired yet**
(so nothing may imply instant self-serve card checkout). If you are unsure
whether a capability exists, grep for it before writing about it.

## Requirements

- PHP 8.3 (8.2 minimum)
- Composer 2
- Node.js 22, npm 10
- MySQL 8 or MariaDB 10.6+ in production; SQLite is used for local dev and tests

## Local setup

```bash
composer install
npm ci
copy .env.example .env
php artisan key:generate
```

Configure the database values in `.env`, then:

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

Operator/landlord access to the platform console uses a separate model — grant
it with `php artisan platform:grant`, never by creating a customer super admin.

Open `http://localhost:8000`. For hot module replacement use `npm run dev` in a
second terminal instead of `npm run build`.

If Windows PowerShell blocks `npm.ps1`, use `npm.cmd` for the same commands.

## Demo data

```bash
php artisan db:seed --class=DemoDataSeeder
```

Creates sample users, attendance, projects, profiles and work-hour entries.
**Never run it on production.**

## Access control

- **Super Admin** — workspace owner, every workspace permission
- **Admin** — permissions selected by the workspace owner
- **Member** — personal work diary plus any explicitly assigned permissions

The permission catalogue is `config/access.php`. New accounts are created from
the Users page by an authorized administrator.

## Desktop tracker

Source in `desktop/`. Packaged builds point at `https://staffvora.com`; dev
builds at `http://staffvora.test`.

Building, code-signing and publishing a release — including how the auto-update
feed at `https://staffvora.com/desktop-updates` is served — is documented in
**`documents/DESKTOP_RELEASE_CHECKLIST.md`**. The desktop app cannot ship
without a rebuild and re-sign; read that document first.

## Verification

```bash
php artisan test
composer validate --strict
composer audit --locked
npm audit --omit=dev
npm run build
```

Clear local caches afterwards with `php artisan optimize:clear`.

## Deployment

**`documents/PRODUCTION_DEPLOYMENT.md` is the runbook.** It covers the nginx
vhosts and SSL for `staffvora.com` and `platform.staffvora.com`, the `.env` keys
that must be set, migrations, the uploads link, the queue worker, the scheduler
cron, caches, a smoke test and a rollback procedure. Follow it rather than
improvising — the host copies code but never runs migrations, and that has
broken a deploy before.

Before touching production, and after every deploy:

```bash
php artisan deploy:preflight     # plain-English readiness report, exits 1 on a real problem
php artisan tenancy:audit        # must exit 0
```

`.env.production.example` is the annotated template for the server's `.env`.

The old `deploy-production.sh` predates the runbook and should not be used.

Before a public launch, replace every `[REPLACE]` item in the Site Builder,
publish every marketing page, and have the Terms of Service and Privacy Policy
reviewed by qualified counsel.

## Security

- Never commit `.env`, webhook URLs, API tokens, database passwords or admin
  passwords. Rotate any credential that has appeared in chat, a screenshot or a
  log.
- Create the first workspace and its owner with
  `php artisan tenant:create "Acme Co" owner@acme.test`.
- `user:create-admin` is deprecated and **requires** `--org=<id|slug>`; it only
  adds an extra super admin to an existing workspace. `AdminUserSeeder` likewise
  requires `ADMIN_ORG` alongside `ADMIN_EMAIL` / `ADMIN_PASSWORD`. Neither can
  create the org-less account that used to bypass tenant scoping.
- Run `php artisan tenancy:audit` after every deploy and in CI. A non-zero exit
  means a user exists with no organization — that account has unrestricted
  cross-customer read/write and must be deleted or re-homed immediately.
- The project ships no database-level foreign keys (SQLite/MySQL portability),
  so tenancy is enforced in the application and by that command, not the schema.

See `documents/SECURITY.md`.

## Documentation

| Document | What it covers |
| --- | --- |
| `documents/PRODUCTION_DEPLOYMENT.md` | The deploy runbook |
| `documents/DESKTOP_RELEASE_CHECKLIST.md` | Building, signing and publishing the desktop app |
| `documents/STAFFVORA_ZERO_TO_100_PLAN.md` | The roadmap and phase gates |
| `documents/STAFFVORA_PLANS_AND_ENTITLEMENTS.md` | Plans, add-ons and entitlement keys |
| `documents/SECURITY.md` | Security posture |
| `documents/SLACK_INTEGRATION.md` | Optional Slack reporting |

## License

Proprietary — all rights reserved. The repository still contains an MIT
`LICENSE` file inherited from the Laravel skeleton; it does not reflect the
intended licensing of this product and needs the owner's decision.
