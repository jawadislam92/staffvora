# Tracksuite — product build log

A running, auditable record of everything built while turning SA Track into the
multi-tenant Tracksuite product. Review this top-to-bottom at the end; nothing
here is final until you sign off. Plan: `~/.claude/plans/wiggly-swimming-clover.md`.

Codename **Tracksuite** is a placeholder — rename the brand at launch (APP_NAME,
repo, folder are all trivial to change). Repo: github.com/jawadislam92/tracksuite.

---

## Phase 0 — fork + repo  ✅  (commit 6e672f2)
- Clean fork of SA Track into `C:\laragon\www\tracksuite`, fresh git history, new
  private GitHub repo. Builds + migrates locally (SQLite). Internal app untouched.

## Phase 1 — multi-tenant foundation  ✅  (43b3c33, 51434b2, 1861ef1)
- **Organization** model + table (tenant root): slug/subdomain, custom_domain,
  owner, status, white-label name/logo/accent, parent_org_id (reserved).
- **Tenancy core**: `CurrentOrganization` singleton, `OrganizationScope` global
  scope + `BelongsToOrganization` trait (applied to all 14 tenant models + User),
  bound via middleware + the Authenticated event. `organization_id` on every
  tenant table. `MonitoringSetting` is now one row per org (was id=1 singleton).
- **Teams** (data + endpoints): `Team` model + table + `users.team_id`;
  `TeamManagementController` CRUD at `/teams` (distinct from the `/team`
  Performance page).
- **Provisioning**: `tenant:create` command.
- **Org-aware jobs**: `tenant:run {target}` runs a command per active org; the 7
  data-touching scheduled jobs go through it.
- **Cache isolation fix**: the shared `monitoring_settings`/`branding` Inertia
  caches used global keys (a cross-tenant leak) → now org-scoped.
- Isolation test suite proves cross-tenant denial. Suite: 124 passed.

## Phase 2 — self-serve + white-label  (in progress)
### 2.1–2.2 Self-serve signup  ✅  (commit 2710c8f)
- `TenantProvisioner` service (one place that stands up a tenant; `tenant:create`
  now delegates to it). `RegisteredUserController` + net-new `/register` route +
  `Auth/Register.jsx`. Each signup provisions a whole tenant + 14-day trial and
  logs the owner in. Removed the obsolete "registration disabled" test. Suite:
  126 passed. Browser-verified.

### 2.3 Teams management UI  ✅
- `Teams/Index.jsx` — owner can add / rename / recolor / remove teams (dark page,
  color palette). Controller index now renders Inertia. Nav link added under
  Management → Teams.
- User ↔ team assignment: a "Team" select on the user form; UserController
  validates `team_id` against the caller's org teams (can't assign across orgs),
  stores it, and lists teams for the form.
- HTTP isolation test: a tenant can't rename/delete another tenant's team (404).
  Suite: 128 passed.

### 2.4 Branding settings + accent theming  ✅
- `Settings/Branding.jsx` (Management → Branding): upload a workspace logo, set a
  display name + accent colour, with a live preview. Backed by
  `BrandingController@edit/@saveBranding` (name+accent on the Organization) and
  the existing logo upload. Logo + name already flow through the `branding`
  shared prop to the sidebar and login page.
- Accent infra: layout sets `--brand-accent` from the workspace colour at
  runtime; `.bg-brand/.text-brand/.border-brand/.ring-brand` + `::selection`
  consume it. **NOTE for the end audit:** the app still uses hard-coded orange in
  many spots — propagating the accent var across those is a mechanical sweep best
  done in the final visual pass (deliberately deferred, per "no mid-build churn").

### 2.5 Per-org Slack (connect-your-own)  ✅
- `monitoring_settings.slack_bot_token` (encrypted, per org). `SlackBotService`
  resolves the active tenant's token, falling back to the env default (empty in
  the product). Each workspace connects its OWN Slack.
- Settings → Attendance Slack alerts: a "Slack connection" block (paste bot
  token / Replace / Disconnect, with a connected badge). Token is write-only —
  never returned to the client; `teamPayload` exposes only `slack_connected`.
  Endpoint `settings.slack.update`.
- Test: token is per-org and stored encrypted (raw column ≠ plaintext).
  Suite: 129 passed.

**Phase 2 COMPLETE.** The product is sellable: self-serve signup, customizable
teams, white-label branding, and per-org Slack.

## Phase 3 — subscriptions + manual billing  ✅  (0cd8646, + this)
- Data: `plans` (self-seeding catalogue Free/Starter/Pro/Enterprise with seat
  limits + feature flags), `subscriptions`, `payments` (org-scoped). New signups
  start on a 14-day Pro trial. Org helpers: subscription/activePlan/planFeature/
  seatLimit; UserController enforces the seat limit on user creation.
- Billing page (Billing → owner only): current plan + trial/period status, seat
  usage, plan comparison cards, payment history, and bank-transfer instructions
  (config/billing.php). No self-serve checkout yet.
- Payment seam: `App\Billing\PaymentProvider` interface + `ManualBankTransfer`
  (bound in AppServiceProvider) — a Stripe/Paddle adapter can replace it.
  `billing:record-payment {org} {amount} [--months --plan --reference]` records a
  transfer, extends the period, and activates the org.
- Bug fix found by new tests: MonitoringSetting::current() memoization moved from
  a function `static` (leaked across tests/orgs in a worker) to the container.
- Tests: trial on signup, plan-driven seats/features, seat-limit block, payment
  recording. Suite: 133 passed (541 assertions).

### Cleanup noted for the end-audit (not blocking)
- Internal seeders still present (AdminUserSeeder creates a Sparking-Asia admin;
  Client/Upwork/Monitoring seeders) — strip/replace for the product.
- Accent var (`--brand-accent`) not yet propagated across hard-coded oranges.
- Some inherited copy/footers may still say SA Track / Sparking Asia.

## Phase 4 — platform console + hardening  ✅ (in code)  (f999912, + this)
- Platform (landlord) console at `/platform` (platform admins only): every
  tenant with status/plan/seats, suspend/resume, record a bank-transfer payment,
  and impersonate an org owner (with a return banner). Guard: `is_platform_admin`
  + `EnsurePlatformAdmin` + `platform:grant {email}`.
- GDPR erasure: `tenant:delete {org} --force` permanently purges a tenant and all
  its data (each model bound to the org, then the org). Isolation-tested.
- Suite: 138 passed (557 assertions).

### Genuinely deferred to a follow-up / ops (need a decision or external step)
- **Desktop rebrand + signed release**: point `desktop/main/config.js` apiBaseUrl
  at the product host + new name/icon, then build + sign + upload installers.
  Needs the product domain decided (and code-signing, which the owner declined to
  pay for — affects SmartScreen/Gatekeeper warnings). Not done here.
- **Strip internal seeders** (AdminUserSeeder = Sparking-Asia admin, etc.).
- **Accent propagation** across hard-coded oranges; any remaining "SA Track /
  Sparking Asia" copy. (Fold into the end visual audit.)
- **GDPR export** command (data portability); per-org **S3 object** cleanup on
  delete; expanded **audit logging**; **CI / staging / observability**.

## How to operate (quick reference)
- Create a tenant by hand: `php artisan tenant:create "Acme" owner@acme.com`
- Make yourself the platform admin: `php artisan platform:grant you@email.com`
- Record a payment: `php artisan billing:record-payment <org> 80 --months=1`
  (or from the Platform → tenant page).
- Seed plans (idempotent): `php artisan db:seed --class=PlanSeeder`
- Run a job per tenant: `php artisan tenant:run <command>`

