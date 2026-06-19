# Tracksuite — sellability audit (Phase 5I)

A living checklist for shipping the product to outside customers. Phase 5A–5H are
done and deployed; this tracks the final "is it sellable" pass.

## Done in this pass
- **Removed user-facing "Sparking Asia / SA Track" leftovers** from the web app:
  - Public landing (`Welcome.jsx`): title, nav brand, hero badge, mock URL, footer
    → Tracksuite / generic copy.
  - Error pages (`errors/layout.blade.php`): title + brand → Tracksuite.
  - Dashboard CSV export filename → `timesheet-<date>.csv` (was `sparking-asia-…`).
  - Forgot-password placeholder → `you@company.com`.
  - AI assistant system prompt is now **brand-aware** — uses the current org's
    brand name instead of a hard-coded "Sparking Asia, a digital agency".

## Done in a follow-up pass
- **Neutral default logo + favicon + PWA name**: replaced the SA fallback logo with a
  brand-agnostic SVG mark (`/images/default-logo.svg`), added an SVG favicon, renamed
  the PWA manifest to Tracksuite. Last user-facing SA *asset* is gone (a final branded
  mark still pending the locked product name).
- **Product-clean `db:seed`**: `DatabaseSeeder` now seeds only the plan catalogue;
  the internal Sparking-Asia seeders (admin/clients/profiles) run only on demand.
  Prod DB verified clean (no org-less users/clients).
- **Per-page purpose**: spot-checked — existing pages already carry a one-line purpose
  (Users, Attendance, …). `PageHeader` (5G) is the pattern for any new/remaining pages;
  a full consistency sweep is best done with a live login + eyes on each theme.

## Remaining (tracked)
- **Bundled fallback logo asset**: `/images/sparking-asia-logo.png` is still the SA
  mark; it only shows when a tenant hasn't uploaded their own logo. Replace with a
  neutral Tracksuite mark (design asset) and update `BUNDLED_LOGO` in
  `ApplicationLogo.jsx` + `Welcome.jsx`. *(Asset task — no code blocker.)*
- **Desktop app rebrand**: `DesktopDownloads.jsx` / `DesktopDownloadController.php`
  reference "SA Track" because the actual installers are named `SA Track Setup.exe`
  / `SA Track-*.dmg`. These match real artifacts — do NOT rename strings until the
  desktop app is rebuilt + re-signed under the product name (separate deferred ops
  task, see PRODUCT_BUILD_LOG "genuinely deferred").

## Per-page review checklist (ongoing)
Run each page in Light / Dark / Black, desktop + mobile:
- [ ] Title + one-line purpose (`PageHeader`) present and accurate.
- [ ] Plan gating applied where relevant (locks + server enforcement) — see 5B.
- [ ] Branding/theme respected (no hard-coded colours that ignore the accent).
- [ ] Empty, loading, and error states are handled and on-brand.
- [ ] Copy is product-generic (no internal jargon, no "Sparking Asia").
- [ ] WCAG contrast holds in all three themes (sample the muted/subtle text).

Pages to sweep: auth, dashboard, timeline, work diary, attendance, reports, team,
users, teams, clients, profiles, settings, branding, billing, platform, developer,
desktop downloads, help.

> **Note:** `PageHeader` (5G) is wired into the Help page as the pattern; roll it
> onto the remaining pages during this sweep so every screen states its purpose.
