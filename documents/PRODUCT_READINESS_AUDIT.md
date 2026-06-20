# Tracksuite — Product-readiness audit (2026-06-21)

Audited live on https://satimetracker.com via a fresh self-serve tenant ("Audit Test"),
which lands on a **Pro trial** (ends 2026-07-05) — so it shows full Pro features.
Mode: surface everything first, fix after (owner decides what to fix).

Legend: 🔴 high · 🟠 medium · 🟡 low/polish · 🔵 verify

---

## 🔴 High — real bugs / bad first impression

### H1. New customers are branded "Absent" for days before they signed up
A workspace created **today** shows the owner **Absent (A)** for every prior day of the
month — "18 Absent Days" on a brand-new account. The Phase 5F **"Tracking start date"**
control exists (Settings → Attendance policy; copy literally says *"so a new workspace never
brands people Absent for days it wasn't even tracking"*) but is **left blank on signup**, so
the grid back-fills Absent over the whole month.
- **Fix:** `TenantProvisioner` should set the org's attendance tracking-start date to the
  org creation date when provisioning. (Also honor each user's `joining_date`.)

### H2. Placeholder billing email shown to customers
Billing page footer: *"Questions? **billing@example.com**"* — a leftover placeholder a real
customer sees.
- **Fix:** real billing contact, configurable (env / platform setting / branding).

### H3. Profile & Settings input text is faint/unreadable (light-on-white)
Profile page (and the Settings panel) use **hard-coded white cards** that don't follow the
dark theme. Field **values** (e.g. name "Audit Test", email) render light-gray on white →
barely readable. Same root-cause family as the auth-input fix just shipped.
- **Fix:** theme these legacy white-card pages (dark card in dark theme) or force dark text
  on their inputs/labels. Profile + Settings both affected.

---

## 🟠 Medium — sellability (brand leaks: "doesn't look finished")

### M1. "SA Track" all over the Desktop App page
The Desktop App page every customer sees still says **"SA Track"** repeatedly (antivirus
help text, install paths `%LocalAppData%\Programs\SA Track`), titles it **"Timetracker
Desktop"**, shows an **"Unsigned local build"** badge (internal dev language), lists a
**stale Windows version 0.3.4** (fleet is on 0.3.7) with blank Size/SHA256, and shows macOS
as **"Not built."**
- **Fix (web copy/metadata, independent of re-signing the installer):** rename to
  "Tracksuite Desktop", neutralize the dev badge, bump the shown version, hide or soften the
  macOS card ("Coming soon"). (Renaming the actual installer = the deferred desktop rebrand.)

### M2. "Upwork Profiles" terminology
Page title **"Upwork Profiles"**, route `/upwork-profiles`, and an **"Upwork Profile"**
column on Clients — niche to the original agency use-case.
- **Fix:** rename to generic "Profiles" (or make the label configurable per workspace).

---

## 🟡 Low — polish / consistency

- **L1.** Inconsistent page headers — eyebrow + one-line purpose present on Dashboard / Work
  Diary / Attendance / Users / Reports, missing on Timeline / Team Performance / Teams.
  Standardize via `PageHeader`.
- **L2.** Users → "Rows" filter dropdown renders blank (no visible default value).
- **L3.** Settings page fixed white card stands out against the dark app (same root as H3).

---

## 🔵 Verify (not confirmed bugs)

- **V1.** White-label / branding panel locks correctly on **Free / after the Pro trial ends**
  (trial currently shows full Pro features — correct; confirm the downgrade re-locks).
- **V2.** Help-center copy has no "SA Track" / "Sparking Asia" leftovers.

---

## Not yet reviewed (secondary)
Add Manual Time form, create/edit forms (user/client/profile), AI assistant chat widget,
and the **Platform / Plans / Developer** console (needs the platform-owner login).

## Dropped — false alarms during the audit
- "0 screenshots/hr" in Settings was a **local-dev seed quirk**; production (Pro trial) shows
  **30/hr** correctly.
- Reports "405 Method Not Allowed" was a **wrong manually-typed URL** (`/work-hours/report`);
  the real route is `/report` and works.

---

## Pages confirmed clean
Landing, Register, Login, Forgot-password, Dashboard, Timeline, Work Diary, Reports, Team
Performance, Clients, Teams, Branding, Billing (matrix), Help center, and the new accordion
sidebar nav — all good.
