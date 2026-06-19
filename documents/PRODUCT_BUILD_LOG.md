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

<!-- New entries appended below as each piece lands. -->
