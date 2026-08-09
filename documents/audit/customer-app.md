# Customer App Audit — owner@big-org.test (Northwind Global, 120 users)

**Scope:** `/dashboard`, `/employee-attendance` (Monthly Grid · Summary · Activity · Timeline), `/timesheets`, `/work-hours`, `/report`, `/insights`, `/team`, `/team/apps`, `/timeline`, `/my-schedule`
**Widths:** 375, 768, 1024, 1440 (plus 1280 after a failure was found at 1024)
**Themes:** light, dark, black
**Method:** live browser on `http://localhost:8302` (local server, same code as production), driven with `read_page` / `get_page_text` / `javascript_tool`; objective checks (`documentElement.scrollWidth` vs `innerWidth`, per-element `getBoundingClientRect().right`, computed colours → WCAG contrast, React `__reactProps$` handler presence). Code cross-checked against `resources/js` and `app/Http/Controllers`.
**Round is READ-ONLY.** Nothing was fixed. The only writes were this file and dev-only entries in `.claude/launch.json` (needed to get a browser onto the port).

**Counts — BROKEN 6 · WRONG 11 · UGLY 7 · NIT 5 (29 findings)**

---

## Environment notes (not findings)

- `artisan serve` on a bare port is refused by the browser pane; the pane only navigates to a port it started itself from `.claude/launch.json`. An attach-only (`url`-without-command) entry is **not** enough.
- `APP_URL` in `.env` is `http://staffvora.test`, which does not resolve locally, so every asset 404s and the SPA renders blank. Serving with `APP_URL`/`ASSET_URL` overridden to the actual origin fixes it.
- Sessions are shared across `localhost:<port>` because cookies ignore the port. Concurrent audit workflows on 8303/8312 repeatedly clobbered this session. Isolated by giving this server its own `SESSION_COOKIE`.

---

## BROKEN

### B1. The app header overflows the viewport at 1024–1385px — every app page scrolls sideways
**Page:** every page inside `AppShell` · **Viewport:** 1024 and 1280 (fails), 1440 (passes) · **Element:** `header > div.mx-auto.flex.h-16.max-w-[1600px]`

Objective result:

| viewport | `documentElement.scrollWidth` | header inner `scrollWidth` | horizontal scroll |
|---|---:|---:|---|
| 375  | 375  | — | no |
| 768  | 753  | — | no |
| **1024** | **1386** | **1386** | **YES (+362px)** |
| **1280** | **1386** | **1386** | **YES (+106px)** |
| 1440 | 1425 | 1386 | no |

**Why:** the primary nav is gated `lg:flex` (`AppShell.jsx:310`), i.e. it switches on at exactly **1024px**, but at that point it needs 593px for six links + "More", on top of a 183px brand block and a **`shrink-0`** right cluster that needs 546px (`AppShell.jsx:371`). Total ≈1386px. Because the right cluster refuses to shrink and the row does not wrap, the whole document widens and the page scrolls horizontally. The component's own docblock (`AppShell.jsx:90`) says the nav "collapses into a hamburger drawer" **below ~1080px** — the code says 1024. The comment describes the correct behaviour; the class does not implement it.

Measured right-cluster contents at 1024: Search 146px, "Time" 75px, "Clock in" 91px, Notifications 36px, ThemeSwitcher 94px, avatar 64px + gaps.

**Fix:** move the nav breakpoint above the width the header actually needs — replace `lg:flex` with a custom `min-[1400px]:flex` (or raise `INLINE_MAX` down to 3–4 items so the row fits at 1024). Additionally drop `shrink-0` on `AppShell.jsx:371` and let the search button collapse to its icon-only variant earlier, so the row can never exceed the viewport. Add a regression assertion that `scrollWidth <= innerWidth` at 1024/1280/1366.

**File:** `resources/js/Layouts/AppShell.jsx:285` (row), `:310` (`lg:flex`), `:371` (`shrink-0`), `:90` (stale comment)

---

### B2. The dashboard invents hours that were never worked — and contradicts three other screens
**Pages:** `/dashboard`, `/team`, `/employee-attendance` · **All viewports, all themes**

On 2026-08-09 the workspace has **zero** rows in `work_hours` and **zero** rows in `time_entries` (verified directly against the DB). The dashboard nevertheless reports:

- KPI **"HOURS TRACKED — 69h 43m — Team total · today"**
- Team activity table: all 12 rows show **STATUS Offline · TODAY 0m · TRACKED 5h 49m · ACTIVITY 0%** — every person carrying the *identical* tracked figure, next to a 0m "today".
- `/team`: **"LIVE NOW 12 — Tracking right now"**, **"TEAM TOTAL 69h 56m"**, and a "Top members" leaderboard where positions 01–08 all read exactly **5h 49m**, plus a "Top clients" list where every client reads exactly **5h 49m**.

The figure is wall-clock time since local midnight. Sampled twice: **5h 33m at 05:33:06** local, **5h 49m at 05:49:03** local, **5h 56m** later — it ticks up in real time. `12 × 5h49m = 69h 43m`, which is the headline KPI exactly.

**Two endpoints disagree for the same day.** `/time-entries/today-summary` returns `tracked_hours: 5.59, total_work_hours: 0, total_entries: 0, current_status: "Not Started", last_action: null, is_live: true`. `/time-entries/team-activity?range=today` returns `tracked_seconds: 0` for the *same* users. The dashboard renders both side by side.

**Root cause** — `app/Http/Controllers/TimeEntryController.php:490-496`:

```php
$live = $liveByUser?->get($employee->id);
if ($live) {
    $sinceMidnight = (int) $now->copy()->startOfDay()->diffInSeconds($now);
    $liveWall = (int) $live->started_at->diffInSeconds($now);
    $liveSeconds = min(16 * 3600, $liveWall, max(0, $sinceMidnight));
    $stats['tracked_hours'] = round($stats['tracked_hours'] + $liveSeconds / 3600, 2);
}
```

The DB holds **12 tracking sessions with `stopped_at IS NULL`, all started `2026-08-02 21:49:16`** — abandoned six days ago. `$liveWall` is ~600,000s, so `min()` always selects `$sinceMidnight`, and every abandoned session contributes "however long today has been so far" as tracked work — **forever, every single day**. `TrackingSession::scopeActive()` (`app/Models/TrackingSession.php:76-79`) is `where('status', STATUS_ACTIVE)` with **no staleness bound**, so nothing ever ages these out.

**Contradicted by, on the same data:**
- `/employee-attendance` → Summary tab: **TEAM HOURS 0m**, ACTIVE 0, CLOCKED OUT 0
- `/timesheets` → owner's own timesheet: **TRACKED 0.00h "Measured by the desktop tracker"**
- `/timeline` → **"0m tracked this day"**, "Nothing was tracked for Sunday, August 9"

**Fix:** treat a session as live only while it is genuinely heartbeating — add `->where('last_heartbeat_at', '>', now()->subMinutes(N))` (or `updated_at`) to `scopeActive()`, and in `TimeEntryController` clamp `liveSeconds` to `now - max(started_at, startOfDay)` **only** for sessions that pass that recency test. Sessions older than the threshold should be auto-closed by the existing `monitoring:close-stale-sessions` command (it exists but is not bounding these). Until then every "today" number on the dashboard and Team page is fiction.

---

### B3. Pagination runs off the left edge of the screen on mobile — "Previous" and the first pages are unreachable
**Pages:** `/work-hours`, `/report` · **Viewport:** 375 · **Element:** `div.flex.items-center.space-x-1` (the page-number row)

Measured on `/work-hours` at 375px:

```
pager:    left = -199,  right = 574,  width = 773
"« Previous": left = -199, right = -115   → entirely off-screen
container:   width = 301  (parent is flex + items-center)
```

The pager needs 773px inside a 301px column. Its parent is `flex flex-col sm:flex-row justify-between items-center` (`Pagination.jsx:83`), so at mobile widths the row is **centred** and overflows equally in both directions — pushing the left half to negative x. The card ancestor is `overflow-hidden` and no ancestor scrolls horizontally, so the user **cannot reach "« Previous" or pages 1–4 at all**. On `/report` the same row is 788px wide across **811 pages** ("1 2 3 4 5 6 7 8 9 10 … 810 811").

**Fix:** wrap the link row in `overflow-x-auto` and change `items-center` → `items-stretch`/`justify-start` at mobile, or (better) render a compact mobile pager — `‹ Prev · Page 3 of 811 · Next ›` — below `sm`. Cap the rendered window of page numbers regardless of viewport.

**File:** `resources/js/Components/Pagination.jsx:83` (centring parent), `:92` (the overflowing row)

---

### B4. The theme switcher has no visible keyboard focus indicator
**Pages:** every app page (header) · **All viewports**

Focusing each of the three theme buttons and reading computed style gives `outline-style: none` and `box-shadow: none` — there is **no** focus affordance of any kind. A keyboard user tabbing across the header loses the caret entirely for three consecutive stops. WCAG 2.4.7 (Focus Visible) failure.

Every other header control in `AppShell` carries `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent`; the switcher was simply missed. `Log out` in the account menu has the same gap.

**Fix:** append `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1 focus-visible:ring-offset-surface` to the button className.

**File:** `resources/js/Components/ThemeSwitcher.jsx:35-37`

---

### B5. Three header controls do nothing, and one advertises a keyboard shortcut that does not exist
**Pages:** every app page · **All viewports**

Verified by reading React's `__reactProps$` on each element — no `onClick`, `onMouseDown` or `onPointerDown` on any of:

| control | selector / label | handlers |
|---|---|---|
| Search (desktop) | `aria-label="Search"`, `md:flex` | *none* |
| Search (mobile) | `aria-label="Search"`, `md:hidden` | *none* |
| Notifications bell | `aria-label="Notifications"` | *none* |

The desktop search button additionally renders `<kbd>⌘K</kbd>`. Pressing **Ctrl+K** on `/dashboard` produces no dialog (`[role=dialog]` count stays 0, no search input appears, body text unchanged).

The source is candid about it — `AppShell.jsx:372` `{/* Search affordance (visual only) */}` and `:407` `{/* Notifications (static) */}` — but the user cannot see the comments. Three prominent, permanently-visible affordances that reward a click with nothing, on every screen in the product.

(For contrast: the "Account menu" button also has no handler on the `<button>` itself, but its parent `<div>` does, and it opens correctly. It is **not** a dead control.)

**Fix:** either implement them, or remove them until they exist. If they must stay as roadmap placeholders, disable them and add `title="Coming soon"` — but shipping a `⌘K` hint for a shortcut that is not bound is worse than having no search button.

**File:** `resources/js/Layouts/AppShell.jsx:373-390` (search ×2), `:408-414` (bell), `:380-382` (the ⌘K hint)

---

### B6. `--text-subtle` fails AA body contrast in all three themes
**Pages:** every page · **All viewports** · Sampled from live computed styles

| theme | `--text-subtle` | on `--surface` | ratio | AA 4.5:1 |
|---|---|---|---:|---|
| light | `#94a3b8` | `#ffffff` | **2.56** | fail |
| dark  | `#6b7793` | `#0e1628` | **4.03** | fail |
| black | `#6b6b6b` | `#0a0a0a` | **3.72** | fail |

This token carries real content, not decoration: every KPI sub-label ("Team total · today", "Clocked in right now", "vs previous 14 days", "Across active members", "Members paused · now"), the job title under every name in the dashboard and attendance tables ("Accountant", "Tech Lead"), and the header's "Search…" / "⌘K". Light theme at 2.56:1 is the worst and is the default theme.

**Fix:** lighten/darken the token per theme until it clears 4.5:1 — verified replacements: light `#64748b` (4.76:1 on white), black `#7d7d7d` (4.81:1 on `#0a0a0a`), dark ≈ `#8b96ad`. If some uses are genuinely decorative, move those to a separate `--text-decorative` token rather than lowering the bar for label text.

**File:** `resources/css/app.css:26` (dark), `:55` (light), `:85` (black)

---

## WRONG

### W1. Two different brand primaries render on the same screen
**Pages:** `/work-hours`, `/report` (anywhere `TraditionalPagination` appears) · **All themes**

On `/work-hours` the "Add Manual Time" button paints **orange `rgb(249,115,22)`** (`bg-accent`) while, 400px below, the active pagination chip paints **blue `rgb(59,130,246)`** — despite its class literally being `bg-orange-500`.

Runtime values on `<html>`:
```
style="--brand-accent: #f97316"   (inline, set from the workspace accent)
--accent          : #f97316
--c-orange-500    : 59 130 246     ← still blue
```

**Why:** `resources/css/app.css:563-566` defines the `--c-orange-*` ramp as the Tailwind **blue** channels, on the premise (comment at `:554-558`) that the locked brand is electric blue `#2563EB`. `resources/js/app.jsx:30-41` is supposed to re-derive that ramp from the workspace accent — but `:33` short-circuits and **removes** the derived properties when the accent equals `DEFAULT_ACCENT`, and `app.jsx:11` sets `DEFAULT_ACCENT = '#f97316'` (orange). So for every workspace on the default accent — the common case — the derivation is skipped and the `orange-*` utilities fall through to the blue CSS defaults, while `bg-accent` resolves to orange. The two files disagree about what "default" means.

53 `orange-*` utility usages remain across 6 JSX files.

**Fix:** pick one. Either (a) set the `--c-orange-*` defaults in `app.css` to the ember ramp derived from `#f97316`, or (b) delete the early-return at `app.jsx:33` so the ramp is always derived from the resolved accent, or (c) if the brand really is electric blue, change `--accent`/`DEFAULT_ACCENT` to `#2563EB`. `config/brand.php:50` sets the marketing accent to violet `#7C3AED`, a third colour — worth reconciling at the same time.

**File:** `resources/js/app.jsx:11`, `:33`; `resources/css/app.css:554-566`

---

### W2. White text on the accent button is 2.8:1
**Pages:** every page with a primary CTA · **All themes**

`#ffffff` on `#f97316` = **2.80:1** (AA needs 4.5:1 for 13–14px text). Affects "Time" and "Clock in" in the header, "Add Manual Time" on `/work-hours`, "Export CSV" on `/report`, and the active chip in the theme switcher (`bg-accent text-white`). Orange in the `#f97316` range simply cannot carry white text at body size.

**Fix:** darken the accent used *as a button fill* to ≈`#c2410c` (white → 4.6:1), or keep the bright accent and switch the label to a near-black (`#1c1917` on `#f97316` = 7.4:1). Do not solve it by bumping font size — these are 12–14px labels.

---

### W3. The Work Hours Report's headline numbers and trend chart describe page 1 of 811
**Page:** `/report` · **All viewports**

The page reports **TOTAL HOURS 56:59**, **ENTRIES 15**, **PEOPLE 8**, **CLIENTS 15** while the filter reads "Date: Last 90 days" and the pager reads **811 pages**. The sub-labels admit the scope ("Across the rows shown", "Grouped rows on this page") but the cards are titled TOTAL HOURS / PEOPLE / CLIENTS on a page called *Work Hours Report* — anyone will read them as the report total. The real 30-day figure from `/insights` is 11,073.8h.

The "Hours trend — Total hours per day across the entries shown below" chart inherits the same scope: it plots the 15 rows of page 1, so a 90-day trend renders as **a single bar (57.0h) with the x-axis labelled "Aug 3, Aug 3"** against a 0–1000h y-axis.

**Fix:** compute the KPI strip and the chart from the **filtered query**, not the paginated slice (a separate aggregate query, as `/insights` already does). If page-scoped totals are genuinely wanted, label them "This page" and put the filtered total next to them.

---

### W4. Dashboard "ACTIVE MEMBERS 0 / 12" uses the wrong denominator
**Page:** `/dashboard` · **All viewports**

The workspace has **120 users (110 active)** — `/team` correctly shows "TEAM SIZE 120", `/insights` shows "110 active people", `/timesheets` shows "110 of 110 people". The dashboard shows **"0 / 12"** and "Team activity — 0 of 12 members active today", because 12 is the number of rows the today-summary endpoint returned (the 12 ghost sessions from B2), not the team size. A workspace owner reading the dashboard is told they run a 12-person team.

**Fix:** denominator should be the active-member count from the roster, independent of who has data today.

---

### W5. Attendance KPI strip goes stale when the date changes on the Activity tab
**Page:** `/employee-attendance` → Activity tab · **Viewport:** 1440

Repro: open the Activity tab (today, empty) → change the Date input to `2026-08-03`. The list below correctly repopulates with 93 employees ("4 actions", "6 actions"…), but the KPI strip stays at **ACTIVE 0 · ON BREAK 0 · CLOCKED OUT 0 · TEAM HOURS 0m**. Switching to the Summary tab with the same date immediately shows **ACTIVE 4 · CLOCKED OUT 89 · TEAM HOURS 640h 10m**.

So the KPIs are only refetched by the Summary tab; the Activity tab changes the date without re-requesting `employee-attendance/summary`. The user sees a populated day sitting under four zeros.

**Fix:** hoist the summary fetch so it is keyed on the selected date regardless of active tab.

---

### W6. People are shown as "Working" / "ACTIVE" on a date six days in the past
**Page:** `/employee-attendance` → Summary tab, date `2026-08-03` · **Viewport:** 1440

The strip reads **ACTIVE 4** and rows read e.g. `Ali Malik · Tech Lead · Working · 0m work hours · 34m break · 3 Times`. "Working" is a *present-tense* live status being applied to a historical date, and it comes from the same never-closed sessions as B2. On a past date the only correct terminal states are Clocked Out / Absent / Auto-closed.

**Fix:** when the selected date is not today, resolve a never-closed punch to "No clock-out" (or auto-close it per the shift rules) rather than "Working".

---

### W7. Durations are formatted three different ways across the app
**Pages:** all · **All viewports**

| screen | rendering of the same kind of value |
|---|---|
| `/dashboard`, `/employee-attendance` | `7h 18m`, `640h 10m` |
| `/report`, `/work-hours` | `07:18`, `56:59` |
| `/timesheets`, `/insights` | `7.30h`, `11,073.8h` |

`07:18` is especially bad next to a `DATE` column — it reads as a clock time, not a duration. Three formats for one concept forces mental conversion when reconciling a timesheet against a report.

**Fix:** one shared formatter with an explicit variant argument (`compact` → `7h 18m`, `decimal` → `7.30h` for payroll/export only) and use `h`/`m` units everywhere on screen. Reserve `HH:MM` for actual times of day.

---

### W8. Onboarding checklist claims the team has not been invited — on a 120-person workspace
**Page:** `/dashboard` · **All viewports**

The "Get started" panel reads **"3 of 4 done"** with **"Invite your team — Add the people whose time you want to track"** still open and an "Invite people" button, while the same page lists 12 teammates and the workspace holds 120 users with months of history. It also offers "Load demo data … Want to see a populated workspace first?" to a workspace that is thoroughly populated.

**Fix:** base the step on member count (`users > 1`), not on an invitation record; and suppress the whole panel (and the demo-data offer) once the workspace has real tracked history.

---

### W9. "Good morning, Workspace"
**Page:** `/dashboard` · **All viewports**

The greeting takes the first whitespace-delimited token of the full name. For "Workspace Owner" that yields **"Good morning, Workspace"**. Any single-word or title-prefixed name degrades the same way.

**Fix:** use a dedicated first-name/preferred-name field, and fall back to the greeting without a name rather than to a mangled one.

---

### W10. Attendance legend and column header disagree about the same concept
**Page:** `/employee-attendance` → Monthly Grid · **All viewports**

The legend defines **`LI` = "Before joining"**. The summary columns include **"LATE JOINING"**. Two different names, and "LI"/"Late joining" invites the reading that `LI` means *late*, adjacent to `LC` = "Late coming".

**Fix:** make the column header match the legend ("Before joining"), or rename the code so the abbreviation, the legend and the column all say one thing.

---

### W11. Raw ISO dates appear in user-facing prose
**Page:** `/employee-attendance` → Summary / Activity empty states · **All viewports**

> "Nobody clocked in on **2026-08-09**" · "No punches on **2026-08-09**"

Everywhere else the same date is rendered "Sunday, August 9", "Aug 3 – 9", "3 Aug – 9 Aug 2026".

**Fix:** run these through the same date formatter as the rest of the app.

---

## UGLY

### U1. KPI card labels are truncated on every mobile screen
**Pages:** `/employee-attendance`, `/timesheets`, `/report`, `/dashboard` · **Viewport:** 375–393

Measured `scrollWidth` vs `clientWidth` on visible `.truncate` labels:

| page | label | available | needed |
|---|---|---:|---:|
| `/employee-attendance` | Present Days | 72px | 83px |
| `/employee-attendance` | Absent Days | 72px | 77px |
| `/employee-attendance` | Week Hours | 72px | 76px |
| `/timesheets` | Total Hours | **49px** | 79px |
| `/timesheets` | Tracked | 49px | 52px |
| `/timesheets` | Manual | 49px | 51px |
| `/report` | Total Hours | 70px | 79px |

The label sits in a `flex` row *beside* a fixed 40×40 icon, so on a 2-up/3-up mobile grid there is almost nothing left for text — "Total Hours" renders as roughly "Total…".

**Fix:** at `KpiCard.jsx:110` drop `truncate` and allow two lines (`whitespace-normal leading-tight`), or hide the icon below `sm` (`hidden sm:inline-flex` on the icon span at `:99`), or move the label above the icon row on narrow cards.

**File:** `resources/js/Components/ui/KpiCard.jsx:99-113`

---

### U2. Five data tables have no mobile layout — only Attendance does
**Viewport:** 375

| page | table width at 375 | cols | mobile card fallback |
|---|---:|---:|---|
| `/employee-attendance` | (grid hidden) | 17 | **yes** — proper card list |
| `/timesheets` | 589px | 7 | no |
| `/insights` | 765px | 7 | no |
| `/team` | 890px | 8 | no |
| `/report` | 1029px | 8 | no |
| `/work-hours` | **1358px** | 11 | no |

All five scroll inside `overflow-x-auto`, so nothing is *lost* — but an 11-column, 1358px table on a 375px phone means the user drags sideways through four screens per row, with no sticky identity column (Attendance's grid has `sticky left-0`; these do not). Attendance proves the pattern already exists in the codebase.

**Fix:** reuse the Attendance mobile card pattern for `/work-hours` and `/report` first (widest, and both are daily-use screens). At minimum add `sticky left-0` to the first cell of each so the row stays identifiable while scrolling.

---

### U3. Attendance numeric summary columns are centred
**Page:** `/employee-attendance` → Monthly Grid · **All viewports** · 990 centred numeric cells measured

The nine summary columns — HOLIDAYS, PRESENT, ABSENT, LEAVE, HALF DAY, WFH, LATE COMING, LATE JOINING, **HOURS** — are all `text-center`. Centred integers in a 110-row table give ragged left and right edges, so the eye cannot scan a column; the HOURS column ("7h 18m", "0m", "8h 27m") is worst because the strings vary most in length. The cells already carry `tabular-nums`, so the intent to align was there.

This is also inconsistent with the rest of the app: `/timesheets` gets it right — TOTAL / BILLABLE / TRACKED / MANUAL are `text-align: right` with `font-variant-numeric: tabular-nums`, and `/report`'s HOURS column is right-aligned too. The attendance grid is the outlier.

**Fix:** change `text-center` → `text-right` on both the `<th>` and the `<td>` for the summary columns. Leave the per-day status chips centred — a single "P"/"A"/"LC" glyph belongs centred.

**File:** `resources/js/Pages/EmployeeAttendance.jsx:2230` (data cell), `:2212` (header). Leave `:2203` and `:2225` (day chips) as they are.

---

### U4. The Attendance calendar modal does not close on Escape
**Page:** `/employee-attendance` → "Calendar" · **Viewport:** 375 (and up)

The dialog renders with `role="dialog"` and `aria-modal`, which sets the expectation that Escape dismisses it. A real Escape key press leaves it open. Close and Cancel buttons do work, so it is not a trap — but it breaks the convention the ARIA roles promise.

Sizing at 375 is otherwise fine: panel 343×690 inside 375×812, nothing clipped, no horizontal scroll.

**Fix:** add a `keydown` listener for `Escape` on the dialog (the "More" popover in `AppShell.jsx:131` already does exactly this — reuse that pattern).

---

### U5. The hidden 111×17 attendance grid is fully rendered in the DOM on mobile
**Page:** `/employee-attendance` · **Viewport:** 375

The desktop grid is hidden with `hidden … sm:block` (`EmployeeAttendance.jsx:2192`) — but `hidden` only hides it. React still builds all 111 rows × 17 columns (≈1,887 cells, 1,760 of them carrying computed styles) on every phone render, alongside the mobile card list that is actually shown. Confirmed: `table.rows.length === 111` with `offsetParent === null`.

**Fix:** gate on a matchMedia/`useMediaQuery` hook so only one of the two views is mounted.

---

### U6. Console error on every page load
**Pages:** all

```
Error: <svg> attribute height: Expected length, "auto".
```

Fires on every navigation. An SVG is being given `height="auto"`, which is not a valid SVG length — the browser falls back to the default 150px intrinsic height, so whatever that icon/illustration is, it is not sized as intended.

**Fix:** find the SVG passing `height="auto"` and set a real length or use CSS `height:auto` on the element instead of the presentation attribute.

---

### U7. Error pages ignore the user's theme and hard-code colours
**Pages:** 403, 404 (reachable from any bad link)

The error templates ship `<meta name="color-scheme" content="only light">` while painting `background: #020617` with `color: #e2e8f0` — declaring light and rendering dark, which is self-contradictory and makes form controls/scrollbars render for the wrong scheme. They also ignore `data-theme` entirely, so a user on light theme gets slammed with a near-black page. Raw hex throughout (`#020617`, `#e2e8f0`).

**Fix:** render error pages with the same token set and `data-theme` as the app; drop `color-scheme: only light`.

---

## NIT

### N1. Form controls with no programmatic label
Visible text labels exist, but are not associated (`for`/`id`, `aria-label` or wrapping `<label>`), so screen readers announce them as unlabelled:

| page | control |
|---|---|
| `/insights` | From (date), To (date), Person (select), Team (select) |
| `/timeline` | person select, date input |
| `/employee-attendance` → Summary | status select ("All statuses / Working / On Break / …") |
| `/timesheets` | pay-period select |
| `/work-hours` | search input (placeholder "Search entries…" only) |
| `/report` | search input (placeholder "Search report…" only) |

**Fix:** give each input an `id` and point the existing visible label at it with `htmlFor`; for the search inputs add `aria-label="Search entries"` (the `sr-only` "Search" span is present on some but not wired to the input).

### N2. Row checkboxes are 16×16 on mobile
`/work-hours` at 375 renders 16 checkboxes at 16×16 px (`h-4 w-4`) — well under the 24px AA minimum (44px AAA) for touch. **Fix:** enlarge the hit area with padding on the wrapping label rather than resizing the box.

### N3. Dead code — 5 of 6 exports in `Pagination.jsx` are unused
`resources/js/Components/Pagination.jsx` is 309 lines. Only `TraditionalPagination` is imported anywhere (`Pages/WorkHoursList.jsx:5`, `Pages/WorkHoursReport.jsx:3`). Unused across the whole codebase:

| export | lines |
|---|---|
| `LoadMorePagination` | 5–61 |
| `useInfiniteScroll` | 131–146 |
| `VirtualScrollPagination` | 147–205 |
| `CursorPagination` | 206–252 |
| `Pagination` (default) | 253–309 |

Deleting them removes ~200 lines and, incidentally, 9 of the 13 design-token violations in the file (the `bg-white/10`, `border-white/20`, `from-green-500 to-blue-500` gradients all live in the dead exports).

### N4. Design-token violations
The **page** files in this area are clean — `Dashboard.jsx`, `EmployeeAttendance.jsx`, `Timesheets/Index.jsx`, `WorkHoursList.jsx`, `WorkHoursReport.jsx`, `Insights/Index.jsx`, `Team/Index.jsx`, `Team/Apps.jsx`, `Timeline/Index.jsx`, `MySchedule.jsx` all report **0** `slate-`/`gray-`/`zinc-`/`bg-white`/hex/`orange-` usages. The violations are concentrated in two shared components:

| file:line | violation |
|---|---|
| `Layouts/AppShell.jsx:277` | `text-slate-950` (impersonation banner) |
| `Layouts/AppShell.jsx:282` | `bg-slate-950/20`, `hover:bg-slate-950/30` |
| `Components/Pagination.jsx:74` | `text-slate-400` / `text-slate-600` |
| `Components/Pagination.jsx:75` | `text-slate-100` / `text-slate-950` |
| `Components/Pagination.jsx:76` | `text-slate-600` / `text-slate-400` |
| `Components/Pagination.jsx:77` | `bg-orange-500 text-white` / `bg-slate-900 text-white` |
| `Components/Pagination.jsx:79` | `border-slate-700 bg-slate-800 text-slate-200 … focus:ring-orange-500` |
| `Components/Pagination.jsx:80` | `border-slate-200 bg-white text-slate-700 hover:bg-slate-50 … focus:ring-blue-500` |
| `Components/Pagination.jsx:31, 181, 221, 241` | `bg-white/5`, `bg-white/10`, `border-white/20`, `from-green-500 to-blue-500` *(all in dead exports — see N3)* |

`Pagination.jsx` also carries its own `dark` **prop** (`:67`) that hand-switches two hard-coded palettes, instead of using the app's `data-theme` tokens. Observed consequence in black theme: the disabled "« Previous" resolves to `rgb(63,63,63)` on `rgb(10,10,10)` — **1.88:1**, effectively invisible; the active page chip is `rgb(59,130,246)` white-on-blue at **3.68:1**.

**Fix:** rewrite `TraditionalPagination` against `bg-surface` / `bg-surface-2` / `border-line` / `text-body` / `text-muted` / `text-subtle` / `bg-accent` and delete the `dark` prop and its two call sites. `Dashboard.jsx:715`'s `"#6b7793"` is a documented `cssVar()` fallback and is fine.

### N5. Login form fails silently
Outside the assigned page list, but hit repeatedly while getting in: when `POST /login` is rejected (419/403 observed), the page renders **no error at all** — `props.errors` stays `{}`, the button appears to do nothing, and the user has no idea what happened. Worth a look by whoever owns the auth screens.

---

## What passed

Recording these so the fix round does not churn code that is already right:

- **Horizontal scroll is clean at 375, 768 and 1440** on every page in scope (`documentElement.scrollWidth <= innerWidth`). The only page-level overflow found is B1's header band at 1024–1385px.
- **`/timesheets` is the reference implementation for tabular numbers** — TOTAL / BILLABLE / TRACKED / MANUAL are right-aligned with `tabular-nums`. `/report`'s HOURS column too.
- **`/employee-attendance` has a genuine mobile card layout** and a sticky first column + sticky header on the desktop grid.
- **`/insights` is internally consistent** — 6,885.8h tracked + 4,188h manual = 11,073.8h worked; 3,917.7h billable + 7,156.1h non-billable = 11,073.8h. Its charts, legends and explanatory copy are the strongest in the app.
- **Empty states are good** where they exist — `/employee-attendance` ("Your team has attendance on record, just none for this day — a weekend, a holiday, or a date before they started"), `/timeline`, `/my-schedule`.
- **No images without `alt`**, no buttons with a completely absent accessible name, on any page in scope.
- The Attendance calendar modal fits and is dismissible at 375 (Escape aside — U4).
- Charts resize correctly across 375/768/1024/1440; no clipping measured.
