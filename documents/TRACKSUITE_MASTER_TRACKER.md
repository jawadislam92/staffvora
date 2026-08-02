# Tracksuite — Master Tracker (single source of truth)

> **Purpose.** This is the ONE living document for Tracksuite direction + work.
> Every working session: we record what we did here and **check items off**, so
> nothing is forgotten even when the idea was only said once. If it's not in
> here, it didn't happen.
>
> **How to read the checkboxes:** `[ ]` = not started · `[~]` = in progress ·
> `[x]` = done & verified. Each done item notes the date + what proved it.
>
> Last updated: 2026-07-01. Owner: Jawad. Maintained by: Claude.

---

## 0. The North Star (the "single point")

**The one promise (proposed — confirm):**
> **"Time tracking your team will actually accept."**
> Accurate hours + humane monitoring + employees see their own data — honest
> productivity insight *without the spyware feeling.*

Tagline already in `config/brand.php`: **"We measure the work, not the worker."** ✅ (good — keep.)

**Rule going forward:** website AND app ladder to this ONE promise. Industries
(agency / BPO / software house / in-house / freelancer) are *use-cases under it*,
not competing messages.

---

## 1. The core strategic problem we're solving

Tracksuite was forked from **SA Track**, which was built for **Sparking Asia, an
Upwork agency**. So the *application* still assumes an Upwork-agency world:
"Upwork Profiles," work attributed to a "tracker," a `outside_of_upwork` work
type, and "Clients" (external billing) as the only way to categorise work.

But the goal is: **a time tracker any company can use to track its employees'
work** — while *staying great for Upwork agencies*. Right now the marketing site
already says "for everyone," but the app still feels Upwork-specific. **That
split is the #1 thing to fix.**

### The design that resolves it (proposed — confirm)
Don't delete the Upwork features (agencies + Sparking Asia need them). Make the
app **adapt per workspace**:

1. **Workspace type chosen at onboarding** — *In-house team · Agency/Upwork ·
   BPO/Call center · Freelancer*. The choice sets sensible defaults (which
   optional modules show, terminology, default work types).
2. **A terminology + feature layer (per-org settings):**
   - Optional **"Profiles"** dimension — rename "Upwork Profile" to a neutral,
     **configurable label**; OFF for in-house, ON + labelled "Upwork Profile"
     for agencies. (A transition to this is already half-started in code.)
   - Configurable **"Client" vs "Project" vs "Department"** — in-house teams
     track internal projects, not external clients.
   - **Neutral work types** — drop hardcoded `outside_of_upwork`; make the set
     generic (or workspace-configurable).

This is the standard "one codebase, many verticals" SaaS approach — a thin
config layer + onboarding preset, not a fork.

---

## 2. Decision log (what's locked vs open)

| # | Decision | Status |
|---|---|---|
| D1 | The one core promise / positioning line | 🟡 PROPOSED — see §0 |
| D2 | Generalize via workspace-type preset + terminology layer (keep Upwork behind "Agency") | 🟡 PROPOSED — see §1 |
| D3 | Brand colour conflict (green vs violet vs electric) | ✅ RESOLVED → see D8 (Electric blue) |
| D4 | Public product name = "Tracksuite" (final?) | 🟡 working title |
| D5 | Payment gateway (Paddle + local PKR?) | 🔴 OPEN — owner |
| D6 | Port newer features from internal `timetracker` repo into Tracksuite? | 🔴 OPEN — needs diff |
| D7 | **Timezone storage model** = store UTC + per-org business tz (bucketing) + per-viewer display tz | 🟢 LOCKED (owner, 2026-06-30) — doing long-term model now while only test tenants exist |
| D8 | Brand colour = **Electric blue** | 🟢 LOCKED (owner, 2026-06-30) |

---

## 3. Diagnosis — everything I found (grouped)

### A. Generalization: de-Upwork the app (KEEP the capability, remove the default branding)
> **UNIT 1 DONE & verified (181 tests pass, frontend builds), 2026-06-30:** Added
> `organizations.workspace_type` (general·agency·bpo·freelancer; migration
> `2026_06_30_000003`, default **general**, existing orgs with profiles auto-set to
> agency). `Organization::usesProfiles()/clientTerm()/profileTerm()`. A new shared
> Inertia **`workspace`** prop (uses_profiles + terms) drives the UI. The **"Profiles"
> nav item is now hidden for non-agency workspaces** — an in-house company never sees
> it. Permission group **"Upwork Profiles" → "Profiles"** (neutral descriptions).
> Work-type labels neutralised everywhere a non-agency sees them: *Outside of Upwork →
> Other work*, *Upwork Bidding → Business development* (stored enum keys unchanged).
> Client-form profile help/placeholders de-Upworked. AI digest prompt no longer says
> "small agency team". `TenantProvisioner` accepts a workspace type (defaults general).
> New test `WorkspaceTypeTest` (general vs agency). **Net: a brand-new workspace is a
> plain employee tracker with zero "Upwork" wording; agencies set type=agency to get
> the full profile workflow back.**
>
> **UNIT 2 DONE & verified (183 tests pass, builds), 2026-06-30:** Added the
> **workspace-type picker at signup** (`Register.jsx` select + `RegisteredUserController`
> validates & passes it; 2 new signup tests). **Gated the Client-form profile field by
> `usesProfiles`** (`ClientCreate/Edit.jsx`) — in-house workspaces never see it at all.
> Cleaned the last backend labels: `SlackReportService::formatWorkType` and the
> `ClientController` CSV header.
>
> **Remaining in A (smaller follow-ups):** desktop `Tracker.jsx` work-type labels
> ("Outside Upwork"/"Bidding"); a workspace-type switch in **Settings** (so an existing
> workspace can change type post-signup — backend ready); optional per-workspace
> "Client"→"Project/Department" rename wired through the UI via `client_term`. The
> STRUCTURAL rename of the model/table/routes (`upwork_profiles`, `/upwork-profiles`)
> stays deferred by design — columns kept for backward-compat, surfaced behind the flag.
- [ ] A1. `UpworkProfile` model/table/controller/routes/3 pages — rename to a neutral, configurable "Profile" concept; gate behind a feature flag (default OFF for in-house). *(STRUCTURAL)*
- [ ] A2. Route `/upwork-profiles`, nav item, page titles "Upwork Profiles" → neutral label. *(COSMETIC→config)*
- [ ] A3. `config/access.php` permission group **"Upwork Profiles"** + `profiles.*` descriptions mention Upwork → neutral wording. *(COSMETIC)*
- [ ] A4. `Client.getWorkTypes()` includes `outside_of_upwork` → replace with generic work types; migration to convert existing data. *(STRUCTURAL)*
- [ ] A5. `WorkHour.tracker` (= Upwork profile name) — keep column, relabel concept; ensure it's optional for in-house. *(STRUCTURAL)*
- [ ] A6. `Client` ↔ make the entity label configurable ("Client/Project/Department"). *(STRUCTURAL-lite: a label setting)*
- [ ] A7. Desktop app: how the employee picks client/profile/work-type — make profile optional, relabel. *(STRUCTURAL)*
- [ ] A8. Slack report + AI assistant wording — `AnthropicService.php:95` system prompt literally says *"small agency team"*; `SlackReportService::formatWorkType` maps Upwork types. *(COSMETIC)*

**Exact inventory (from full code sweep, 2026-06-30):**
- *STRUCTURAL (~50 sites):* `upwork_profiles` table + `UpworkProfile` model + `UpworkProfileController` + routes `upwork-profiles.*` (web + api) ; FKs `clients.upwork_profile_id`, `tracking_sessions.upwork_profile_id`, pivot `client_upwork_profile` ; `work_hours.tracker` column ; permission keys `profiles.view/manage` ; work_type enum values `outside_of_upwork`, `upwork_bidding`, `tracker_manual` validated in `WorkHourController.php:26-29`, `Client::getWorkTypes()`, and hardcoded arrays in `WorkHourCreate.jsx` / `WorkHourEdit.jsx` ; desktop reads `upwork_profile_id` / `upwork_profile_name` in `Tracker.jsx`, `trackerService.js`, `api.js`, `ipc.js`, `preload.js`.
- *COSMETIC (~40 sites):* nav label + page titles ("Profiles"/"Upwork Profiles"), help text in `ClientCreate/Edit.jsx` ("Select Upwork profiles…"), work-type display labels in `WorkHoursList/Report.jsx`, `Tracker.jsx`, `SlackReportService.php`, permission group label in `access.php:124`, CSV header "Upwork Profiles" in `ClientController.php:225`.
- *Plan:* rename the cosmetic labels via a per-org **terminology config**; for STRUCTURAL, keep the DB columns (migrating live FKs is risky) but **alias them in code** to neutral names + gate the whole module behind a feature flag. Old data keeps working; new in-house workspaces never see "Upwork".

### B. Onboarding (the biggest missing piece for a general product)
- [x] B1. **First-run setup wizard DONE & verified (194 tests pass, build clean), 2026-07-01.**
  New column `organizations.onboarding_completed_at` (migration `2026_07_01_000001`; NULL = needs
  setup, backfilled to `now()` for pre-existing orgs so no established team gets dropped into a
  first-run flow). New owners are redirected from signup into **`/onboarding`** instead of a cold
  dashboard (`RegisteredUserController`), and a **dashboard "Finish setting up your workspace" nudge**
  (`needsOnboarding` prop → banner) lets anyone who skips return until it's done. New
  **`OnboardingController`** + routes (`onboarding.show/workspace-type/team/client/complete`), admin-only
  (members are bounced to the dashboard / 403 on mutate). Wizard page **`Onboarding.jsx`** (themed,
  inside `AuthenticatedLayout`, electric-blue) with a 4-step stepper: **(1) workspace type** (re-preset,
  busts the `workspace.shared` cache so nav/labels update), **(2) invite team** (creates real `member`
  accounts honouring the plan seat-limit, surfaces a one-time temp password to copy — no tenant email-
  invite pipeline exists yet, see follow-up), **(3) install desktop tracker** (real Windows/macOS
  download links, presence-detected), **(4) create first {Client/Project}** (uses the workspace's
  `clientTerm` + work types), then **Finish** (stamps completion → dashboard). "Skip setup" available
  throughout. Shared flash gained an `invited` key for the one-time creds. New `OnboardingTest` (11
  cases) + updated `RegistrationTest` (now expects redirect to the wizard). *(local, not deployed)*
  ⚠️ **Follow-up:** the invite step shows a generated temp password to hand over (matches the app's
  admin-sets-password model) — replace with real emailed invites once SMTP/invite tokens land. The
  wizard was **not** screenshotted live (auth-gated; no browser-automation tooling available this
  session) — verified by the Inertia render test + clean Vite build.
- [x] B2. **Already fixed** (audit lagged the code). `TenantProvisioner::provision()` (lines 65-70) already sets `attendance_tracking_start_date => now()` on signup, so a brand-new workspace never brands anyone Absent for days before it existed. PRODUCT_READINESS H1's "Audit Test" tenant predated this fix. *(Minor future polish: use `BusinessTime::today()` so the start date is the org's local day, not the storage zone's.)*

### C. Positioning / website (single point)
- [ ] C1. Lock the one core promise (§0) and make every page ladder to it.
- [ ] C2. Keep agency/BPO/etc. as *use-case* subpages, not 5 competing headlines.
- [ ] C3. Resolve the brand-colour conflict (D3) and apply ONE identity site-wide.

### D. Sellability / brand leaks (a customer must never see "SA Track"/"Sparking Asia")
- [ ] D1. Desktop App page still says "SA Track", stale version 0.3.4, "Unsigned local build", macOS "Not built" — fix web copy (PRODUCT_READINESS M1).
- [x] D2. **Resolved.** The `billing@example.com` placeholder is already gone — Billing/Help read `config('billing.support_email')` (null hides the line). Improved: now falls back to `config('brand.support_email')` (`hello@satimetracker.com`) so customers always see a real address. *(local)*
- [ ] D3. Faint/unreadable text on Profile + Settings (white cards ignore dark theme) (H3).
- [ ] D4. Bundled fallback `sparking-asia-logo.png` → neutral Tracksuite mark.
- [ ] D5. `config/brand.php` `legal_name` still "Sparking Asia (Pvt.) Ltd." placeholder. *(needs owner's real registered company name)*
- [x] D6. **DONE — app re-skinned to Electric blue.** Root fix in `resources/css/app.css`:
  the global `--brand-accent` default and the remapped `--c-orange-*` channel scale (which
  ~166 hard-coded `orange-*` utilities follow) flipped from Tailwind **orange → Tailwind
  blue** (`#2563EB`). So the entire app's default accent — buttons, links, focus rings,
  `::selection`, the Billing `text-orange-300`, etc. — is now electric blue, while
  custom-accent tenants are unaffected (they override these vars at runtime). Then fixed the
  pieces that don't go through the variable system: `Auth/Register.jsx` + `Auth/Login.jsx`
  hardcoded `yellow-*`/inline-hex gradients + links → blue, button text → white, and the
  **"TimeTracker" brand leak** on Login → dynamic workspace/brand name. **Builds clean, 183
  tests pass.** *(local)* ✅ **VISUALLY VERIFIED 2026-07-01** via headless Chrome screenshots of
  `/login` + `/register` (served from `php artisan serve`): Sign In / Create-workspace buttons,
  focus rings, links and background blobs all electric blue; brand reads "Tracksuite" (no
  "TimeTracker" leak); workspace-type picker present on register. **Found + fixed the last orange
  leak:** the bundled logo mark `public/images/default-logo.svg` was still an orange gradient
  (`#fb923c→#f97316`) → flipped to electric blue (`#3b82f6→#2563EB`); re-screenshotted, logo now blue.

### E. Cross-repo feature drift (owner flagged)
- [ ] E1. Audit what newer work exists in internal `C:\laragon\www\timetracker` (SA Track) that isn't in Tracksuite, and decide what to port. *(Needs a diff — owner to confirm scope.)*

### F. Payments (frontend done, backend waiting)
- [ ] F1. Decide gateway (Paddle for global + JazzCash/Easypaisa/bank for PKR). *(owner)*
- [ ] F2. Implement provider behind existing abstraction; wire `BillingController::checkout` + webhook.

### G. 🔴 Multi-tenant CORRECTNESS bugs (a non-Pakistan customer gets WRONG data) — found in sweep
> **ARCHITECTURAL FINDING (2026-06-30):** This is NOT a find-replace. `app/Support/BusinessTime.php`
> shows the app stores **all datetimes as wall-clock in `config('app.timezone')` = Asia/Karachi**
> (no UTC); the desktop sends UTC and the server converts it *into Karachi for storage*. The whole
> storage + day-bucketing model assumes **one global business timezone for the deployment.** A
> `display_timezone` setting exists (team + per-user) but storage is single-tz. → A blind refactor of
> the ~40 `Asia/Karachi` sites risks the **"5h re-save corruption"** already hit in the SA Track repo.
> **Decision required before any edit** (see Decision Log). Investigation across both repos running
> 2026-06-30 to design a safe fix from SA Track's proven per-viewer display-switcher solution.
**INVESTIGATION RESULT (2026-06-30, both repos):**
- SA Track storage = **wall-clock in `app.timezone`, DATETIME columns** (single-company). Desktop UTC converted on ingest via `BusinessTime::fromClient()`. NOT multi-timezone.
- **The 5h corruption root cause:** `time_entries.action_timestamp` was a MySQL **`TIMESTAMP`** column → implicit `ON UPDATE CURRENT_TIMESTAMP` → on UTC-mode MySQL, any row UPDATE (e.g. Slack-thread backfill) rewrote it to UTC = −5h. **Fix (SA Track migration `2026_06_27_000002`): convert `TIMESTAMP` → plain `DATETIME`.** Guardrail: never use `TIMESTAMP`/`ON UPDATE` on tz-sensitive columns.
- SA Track also added per-worker **`users.work_timezone`** (migration `2026_06_27_000001`) + `User::attendanceDateFor()`.
- **Tracksuite (forked ~June 19) is MISSING both June-27 fixes** → still carries `TIMESTAMP` on 5 tz-sensitive columns: `time_entries.action_timestamp`, `tracking_sessions.started_at`, `tracking_screenshots.captured_at`, `tracking_activity_samples.captured_at`, `manual_attendance_audits.changed_at`. **Latent 5h-corruption risk** (only bites when MySQL `explicit_defaults_for_timestamp=OFF`, e.g. MariaDB).
- **Tracksuite DID keep** the per-viewer display switcher (`resources/js/lib/datetime.js` `useFormatters` + `HandleInertiaRequests` `display` prop). Storage still global Karachi.

- [x] G-SAFETY. **Applied the `TIMESTAMP → DATETIME` guardrail** — migration `2026_06_30_000001_fix_tracking_timestamp_columns_on_update.php` covers all 5 vulnerable columns (`time_entries.action_timestamp`, `tracking_sessions.started_at`, `tracking_screenshots.captured_at`, `tracking_activity_samples.captured_at`, `manual_attendance_audits.changed_at`). MySQL-guarded (no-op on local SQLite). **177 tests pass.** ✅ *Local only — NOT deployed.* ⚠️ STILL TODO before/at deploy: check the VPS MySQL mode (`explicit_defaults_for_timestamp`) and whether live data already corrupted → may need a one-off repair like SA Track did.
- [x] G0. **Storage-model decision (D7) = STORE UTC + per-viewer display tz** (owner, 2026-06-30) — chosen because there are no real tenants yet (only disposable test tenants), so the "risky re-conversion" downside is moot; do the clean long-term model now. **Final design:** storage = UTC · day/shift/auto-close bucketing = a **per-org business timezone** (`organizations.timezone`, chosen at signup) + optional per-worker `work_timezone` later · rendering = the existing per-viewer display switcher (`datetime.js`/`useFormatters`, already in the fork).
- [x] G-CENTRALIZE. **Org-aware timezone — DONE & verified (178 tests pass).** Added `organizations.timezone` (migration `2026_06_30_000002`, default Asia/Karachi) + `Organization::businessTimezone()`. Rewrote `BusinessTime` to separate **storage** tz (`storageTz()`), **business** tz (`tz()`, resolved per-tenant from the bound org via `CurrentOrganization`, cached), and display. Replaced **~40 hardcoded `Asia/Karachi` sites** (attendance grid/summary/timeline, web + desktop clock, auto-close, still-working/silent-tracker checks, Slack digests, AI prompt) with the org-aware `tz()`. Scheduled commands were already per-tenant via `tenant:run`, so they now bucket in each org's zone automatically. Added `OrganizationFactory`, made the overnight tests tenant-aware, and added a **New-York-vs-Karachi test** proving a clock-in buckets to the correct business day per workspace. Also fixed a **cross-tenant cache leak** (`AiAssistantController` datapack key was global → org-scoped).
  - **What this fixes:** a non-Pakistan workspace now gets correct attendance **dates**, "today", shift/late/auto-absent logic, and clock-in **time strings** in its own zone.
- [ ] G-UTC-INSTANTS. **DEFERRED follow-up (was attempted, reverted):** store datetime *instants* in UTC so cross-timezone DISPLAY times + tracking-session **durations/timeline splits** are exact for a workspace whose zone ≠ storage zone. Flipping `app.timezone`→UTC needs care: Eloquent does NOT convert tz on write (stores the Carbon's wall-clock), a write-normalizing model trait conflicts with `date` casts, and `Carbon::setTestNow()` makes test reads parse in the mock's zone — so the whole suite must be made instant-explicit. Do as its own focused, fully-tested unit. Storage stays Asia/Karachi until then (configurable via `APP_TIMEZONE`).
- [ ] G1. `Asia/Karachi` hardcoded in ~40 backend sites: attendance (`EmployeeAttendanceController` — many), `AutoCloseAttendance`, `SilentTrackerCheck`, `StillWorkingCheck`, `Console/Kernel.php:16`, `Api/Desktop/SessionController.php:116`, `Api/Desktop/TimeClockController.php:23/46`, `TimeEntry.php`, `AttendanceCloser.php`, services. → wrong attendance dates, "today", auto-clock-out timing. *(STRUCTURAL — gated on G0)*
- [ ] G2. Frontend hardcodes Karachi for the default date — `WorkHourCreate.jsx:10-14`. *(STRUCTURAL — gated on G0)*
- [ ] G3. AI prompts hardcode `Asia/Karachi` (`AnthropicService.php:118`). *(COSMETIC-ish)*

### H. Desktop app points at the WRONG product's server — found in sweep
- [x] H1. **DONE (local).** Repointed the desktop at the Tracksuite backend: `desktop/main/config.js` (prod `https://satimetracker.com`, dev `http://tracksuite.test`), auto-update feed in `desktop/package.json` (`satimetracker.com/desktop-updates`), and the login default + placeholder in `Login.jsx`. The URL stays user-overridable in the login Advanced section. *Left `productName`/`appId` ("SA Track" / `com.spark.timetracker.desktop`) untouched — those are installer identity tied to signing/distribution = the deferred desktop rebrand.* Not built/deployed.

---

## 4. Session log (append every session)

- **2026-06-30** — Got up to speed on the whole project. Read all handoffs +
  core models/config. Ran a codebase sweep for Upwork/Sparking coupling.
  Produced this master tracker + the §3 diagnosis. No code changed yet —
  awaiting owner's go on D1/D2 direction.
- **2026-06-30 (cont.)** — Owner locked brand = Electric blue (D8); chose to work
  through the whole list in order, correctness first. Investigated timezone across
  both repos: found Tracksuite carries the latent 5h-corruption bug (TIMESTAMP
  columns, missing SA Track's June-27 guardrail) and lacks per-org timezone, but
  kept the display switcher. Owner locked D7 = UTC long-term model.
  **Shipped Stage 1 (local):** guardrail migration `2026_06_30_000001` (5 columns,
  MySQL-guarded). `php artisan migrate` clean, **177 tests pass**. Not deployed.
  Next: Stage 2 = execute the full UTC model as one coherent unit (G-UTC).
- **2026-06-30 (cont. 2)** — Built the **org-aware timezone centralization** (G-CENTRALIZE):
  `organizations.timezone` + org-resolved `BusinessTime::tz()`, ~40 hardcoded `Asia/Karachi`
  sites rewritten, `OrganizationFactory`, tenant-aware overnight tests + a New-York multi-tz
  proof test, and a cross-tenant AI cache-leak fix. **178 tests pass.** Attempted the full
  UTC-instant storage flip but reverted it (Laravel write-tz + `setTestNow` read quirk +
  date-cast conflict make it a dedicated effort) — storage stays Asia/Karachi; UTC-instants
  deferred as G-UTC-INSTANTS. All local, not deployed.
- **2026-06-30 (cont. 3)** — Desktop server fix (H) → satimetracker.com. Confirmed B2 was
  already fixed in TenantProvisioner. Built **de-Upwork Unit 1 (A)**: `workspace_type` +
  `usesProfiles` flag + shared `workspace` Inertia prop + Profiles nav gated for in-house
  workspaces + permission group renamed + work-type/client-form/AI wording neutralised +
  `WorkspaceTypeTest`. **181 tests pass, `vite build` clean.** All local, not deployed.
- **2026-06-30 (cont. 4)** — De-Upwork **Unit 2**: workspace-type picker at signup
  (`Register.jsx` + controller + 2 tests), Client-form profile field gated by `usesProfiles`,
  last Slack/CSV labels cleaned. **183 tests pass, build clean.** Noted a brand leak: the
  **auth pages (Register/Login) still use the old ORANGE placeholder palette**, not electric
  blue → added to D. All local, not deployed.
- **2026-06-30 (cont. 5)** — Brand re-skin (D6): flipped the app's global accent default
  (`app.css` `--brand-accent` + the `--c-orange-*` scale that ~166 `orange-*` utilities follow)
  from orange → **electric blue** (`#2563EB`); fixed the auth-page yellow/inline-orange + the
  "TimeTracker" leak; billing/help support email now falls back to the brand address (D2).
  **183 tests pass, build clean.** All local, not deployed. (Visual not screenshotted live.)
- **2026-07-01** — Built **B1 first-run onboarding wizard**: `onboarding_completed_at` column +
  migration, `OnboardingController` + routes (admin-only), `Onboarding.jsx` 4-step wizard
  (workspace type / invite team / install tracker / first client → finish), signup now redirects
  into it, dashboard nudge to resume. New `OnboardingTest` (11) + updated `RegistrationTest`.
  **194 tests pass, `npm run build` clean.** Then **visually verified the D6 electric-blue re-skin**
  on `/login` + `/register` with headless Chrome (no playwright/MCP browser tooling available, so used
  the system Chrome `--headless --screenshot` against `php artisan serve`) — all blue as intended, and
  **caught + fixed the last orange leak** (bundled `default-logo.svg` gradient → electric blue),
  re-screenshotted to confirm. All local, not deployed.
