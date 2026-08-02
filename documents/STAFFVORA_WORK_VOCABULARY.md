# Staffvora — Work Vocabulary Redesign (Upwork-specific → universal)

> **Raised by Jawad (2026-07-23):** "Time tracker is specifically designed for our company
> needs, which is an Upwork agency… *tracked time, manual time, outside of Upwork* — those
> need to change, because not everyone uses Upwork."
> **Status: DESIGN — awaiting owner answers (Q1–Q5 below). Not built yet.**
> Belongs to **Phase 4** (port + improve) and feeds **Phase 5** (two editions).

## 1. What exists today (the problem)
`WorkHourController::WORK_TYPES` is a single flat list of seven values:

| Value | Meaning | Universal? |
|---|---|---|
| `tracker` | captured automatically by the desktop app | ✅ yes |
| `manual` | typed in by hand | ✅ yes |
| `fixed` | fixed-price engagement | ⚠️ billing concept |
| `office_work` | internal/admin work | ✅ mostly |
| `test_task` | unpaid trial task for a prospect | ⚠️ marketplace concept |
| `outside_of_upwork` | client work not booked through Upwork | ❌ **Upwork-only** |
| `upwork_bidding` | time spent bidding for jobs | ❌ **Upwork-only** |

**Three flaws:**
1. **Upwork leaks into every edition.** A software company or BPO sees "Outside Upwork" and
   "Upwork bidding" — meaningless, and it makes the product look like it isn't for them.
2. **One field, three concepts.** *How* time was captured (tracker/manual), *what* the work
   was (office/bidding/test), and *how it bills* (fixed) are crammed into one column. That is
   why the list can never be clean — the categories aren't comparable to each other.
3. **Half-neutralised already, inconsistently.** `Client::workTypeLabel()` maps
   `outside_of_upwork` → "Other work" for General but "Outside Upwork" for Agency;
   `SlackReportService` maps `upwork_bidding` → "Business development"; but
   `WorkHourCreate.jsx` and `TimelineController` carry their own separate label maps. The same
   value renders under different names in different places, and the stored value is still
   Upwork-coded for everyone.

## 2. Proposed design (split the one field into three)
**A. Capture source — system-set, never chosen by a user.**
`tracked` (desktop app) vs `manual` (typed in). Already how the product behaves; just stops
being mixed in with work categories. Drives the "locked tracked entries" rule.

**B. Work category — workspace-configurable, with per-edition defaults.**
A short editable list each workspace owns (like Clients/Projects already are), so a company
uses its OWN words. Proposed defaults:

| General edition (default) | Agency edition (adds) |
|---|---|
| Client work | Bidding / proposals |
| Internal / admin | Off-platform client work |
| Business development | Trial / test task |
| Training / onboarding | |
| Meetings | |

Existing values map cleanly: `office_work`→Internal/admin, `upwork_bidding`→Bidding,
`outside_of_upwork`→Off-platform client work, `test_task`→Trial/test task. Nothing is lost —
Agency keeps its exact vocabulary, General never sees the word "Upwork".

**C. Billable — a yes/no flag** (plus optional rate), replacing `fixed` as a *type*.
This is what customers actually report on ("how many billable hours this month?").

## 3. Migration & safety
- Keep the existing `work_type` column; ADD `work_category_id` + `is_billable`, backfill from
  the current values with the map above, and read through an accessor so old rows keep working.
- The two Upwork-named DB values stay only as legacy data behind the Agency edition; General
  workspaces never see or store them.
- Terminology already flows through `workspace.client_term` / `profile_term` — extend the same
  pattern so ONE place decides every label (kill the four competing label maps in §1.3).

## 4. Owner decisions — ✅ ALL LOCKED (Jawad, 2026-07-23)
- **Q1 — ✅ SPLIT.** Three fields: capture method · work category · billable status. (Not a rename.)
- **Q2 — ✅ EDITABLE PER COMPANY**, shipped with sensible defaults already populated.
- **Q3 — ✅ DEFAULTS APPROVED:** Client Work · Internal/Admin · Business Development ·
  Training/Onboarding · Meetings.
- **Q4 — ✅ ADD BILLABLE (Yes/No)** on time entries.
- **Q5 — ✅ SPARKING ASIA KEEPS ITS LABELS** ("Bidding", "Outside Upwork") — **but on the exact
  same underlying structure as every other company.** *Owner was explicit: "so we are not
  maintaining a separate system."*
  > **Binding constraint:** there is ONE mechanism (a `work_categories` table, one row per
  > category per workspace) and ONE code path. Agency vs General differ ONLY in the seed rows
  > written at signup. No `if (agency)` branches in the work-type logic, no parallel enum, no
  > second label map. Sparking Asia's categories become ordinary editable rows like anyone's.

## 4b. Build order (after the current attendance/manual-time batch lands)
1. `work_categories` table (workspace-owned, editable, sortable, soft-archivable) + seeding:
   General = the 5 approved defaults; Agency = those + Bidding + Outside Upwork + Trial/Test task.
2. `work_hours.work_category_id` + `work_hours.is_billable`; backfill from the legacy
   `work_type` using the §2B map; keep `work_type` readable via an accessor for old rows.
3. **Delete the four competing label maps** (`Client::workTypeLabel`, `SlackReportService`,
   `WorkHourCreate.jsx`, `TimelineController`) → one vocabulary source.
4. Settings screen to manage categories; Billable toggle on the manual-time form; category +
   billable columns/filters on Timesheets, Reports and the Slack digest.
5. Acceptance: a General workspace walkthrough where "Upwork" appears **zero** times, and
   Sparking Asia's existing entries still read exactly as they do today.

## 5. Broader sweep to do at the same time
Everywhere else agency assumptions leak into General: the `Profiles` module (already gated),
`upwork_profile_id` on work hours, Slack report wording, report column headers, and the
onboarding copy. One pass, one vocabulary source, verified by a General-workspace walkthrough
where the word "Upwork" must appear **zero** times.
