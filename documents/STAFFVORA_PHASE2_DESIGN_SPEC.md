# Staffvora — Phase 2: Design System + Top-Nav Shell (BUILD SPEC)

> Blueprint for the **terminal `claude`** (playwright-equipped) to build Phase 2.
> Read `documents/STAFFVORA_ZERO_TO_100_PLAN.md` first. **One session at a time** —
> only this session edits/migrates the local repo while Phase 2 is in flight (two
> sessions on the same SQLite file corrupt it).

## Goal
Replace the inconsistent, sidebar-based UI with **ONE design system + a new TOP-NAVIGATION
shell**, at the Hubstaff *craft* bar (spacious, clear, great empty/loading states), on
Staffvora's **electric-blue** identity. Every later page rebuild sits on this foundation.

## Step 0 (FIRST — unblocks decision D-3) — two look mockups
Build the Dashboard once, render it two ways, **screenshot both in-browser (playwright) and
post them to the owner to pick**:
- **Direction A — Dark-first:** default dark canvas (premium, distinctive — most competitors
  are light, so we stand out), electric-blue accent; light + black themes available.
- **Direction B — Light-first:** default light/airy canvas (Hubstaff-style, what big-company
  buyers expect), electric-blue accent; dark available.
Both use the SAME tokens/components/nav below — only the **default theme** differs (a one-line
config). **Do not build the real page rebuilds until the owner picks A or B.**

## 1. Tokens (extend `resources/css/app.css`)
- KEEP: `bg-app/surface/surface-2`, `border-line`, `text-body/muted/subtle`, `bg-accent`
  (electric blue `#2563EB`), the 3 themes (dark/light/black).
- ADD semantic status tokens: `--success / --warning / --danger / --info` (+ tint variants)
  → replace the raw `emerald/amber/rose/violet` picks across pages.
- FIX: `PageShell` `bg-slate-950` → `bg-app`; **consolidate the two `PageHeader`s** into one
  themed component that supports `eyebrow`; replace the muddy `from-orange-500 to-amber-500`
  gradient with a real electric-blue brand gradient; lock **one width** (`max-w-[1600px]`),
  **one spacing rhythm**, **one radius scale** (`rounded-lg` default, `rounded-xl` big cards).

## 2. Component library (`resources/js/Components/ui/*` — the ONE kit)
Delete the dead systems first (`Components/Themed/*` + `theme.js`, old `Components/ui/*`,
extra `Pagination` variants, old `Charts/*` chain). Then build, all token-based + theme-aware:
- **Button** (primary/secondary/ghost/danger, sm/md, loading, icon slot).
- **Card/Panel** (header/body/footer) — replaces the copy-pasted `rounded-lg border … bg-…` string.
- **StatCard** (label, big value, sparkline slot, delta ▲▼ in success/danger).
- **Table** (sortable headers, row hover, optional sticky first col, dense/comfortable,
  bulk-select header checkbox, per-row Actions dropdown, result-count footer, pagination).
- **Badge / StatusPill** (status tokens).
- **Field/Input/Select/Textarea/Toggle/Checkbox** (label, help, error, accent focus ring).
- **Modal** (standardize on the existing one; re-theme the light modals).
- **EmptyState** (illustration, title, subtext, CTA) — one per data surface.
- **Skeleton** (row/card/chart loaders).
- **Tabs / SegmentedControl** (Daily/Weekly, Active/Archived, etc.).
- **DateRangePicker** — preset rail (Today/Yesterday/Last 7 days/Last week/This month/Last
  month) + dual-month calendar + Apply/Cancel + timezone select. Reused on every data page.
- **Dropdown, Avatar, Tooltip** (incl. the `ⓘ` info tooltip on metric labels).
- **LockedFeature** — greys a control + shows an **Upgrade** CTA when the plan/add-on
  entitlement is missing (the "shown, not hidden" rule from D-2/D-8).
Ship a `/_ui` demo page rendering every component in all 3 themes for visual QA.

## 3. TOP-NAV shell — `resources/js/Layouts/AppShell.jsx` (replaces the sidebar)
Top bar + optional contextual sub-nav; **no left sidebar.**
**Top bar left→right:** Staffvora logo · **primary nav** (Dashboard · Timesheets · Activity ·
Insights · Reports · People · {clientTerm}s · [Profiles = agency add-on only] · Financials;
overflow → "More"; items with sub-pages open a dropdown) · **global search** (⌘K command
palette).
**Top bar right:** **Clock In / Clock Out control** (state-aware — Clocked out→"Clock in";
Clocked in→elapsed + "Clock out" + "Break"; on break→"Resume"; persistent on every page, per
D-6) · notifications · help · avatar menu (Profile, Settings, Branding, Billing, theme switch,
Log out) · trial/plan chip + Upgrade.
**Sub-nav:** pages with sub-sections render a contextual tab row under the top bar (Settings,
Insights, Billing, Timesheets). **Favorites:** a star pins any page into a Favorites menu.
**Responsive:** below `md`, primary nav → hamburger drawer; Clock In/Out + search stay visible.
**New-workspace:** a dismissible "Get started" progress affordance surfaces here.
> Build behind a flag; keep the old sidebar working until the new shell reaches parity, then swap.

## 4. Chart system (`resources/js/Components/charts/*` — rebuilt)
- Theme-aware **options factory** (reads `--text/--text-muted/--border/--accent` per theme).
- **Palette:** accent-derived categorical + sequential scales (no rainbow).
- **Chrome:** every chart wrapped in Card + title + legend + EmptyState + Skeleton.
- **Components:** Sparkline · BarChart (ranked) · LineChart (trend) · DonutChart (center label,
  e.g. utilization-vs-target) · Gauge (idle %) · **ActivityStrip** (generalise Timeline's
  `HourRuler` — the 24h tracked/idle/manual ribbon; the most Hubstaff-like viz we already have).

## 5. Page scaffold standard (every page)
`<AppShell> → <PageHeader eyebrow title description actions> → toolbar (DateRangePicker +
filters + search + export + column-toggle) → content (Card/Table/charts) → EmptyState/Skeleton`.
One width, one rhythm. "Learn one page, know them all."

## 6. Build order
1. Step 0 mockups → **owner picks D-3.**
2. Tokens + component kit + `/_ui` demo page.
3. Delete the dead systems.
4. Build `AppShell` (top nav + Clock In/Out) behind a flag.
5. Chart-system foundation.
6. Rebuild **Dashboard** on the new shell + kit + charts to the industry bar (the reference page).
7. Flip the app to `AppShell`; retire the sidebar.

## 7. Verification (playwright — the terminal's strength)
- Every component + Dashboard: **screenshot in all 3 themes** (light/dark/black) — no
  theme-lock, no contrast bugs, accent picker recolors.
- **Heavy-data** (Table with ~1000 rows paginates + stays fast) **and empty-data** (every
  surface has a real EmptyState + Skeleton) checks.
- `php artisan test` stays green; `npm run build` clean.
- Post the Dashboard screenshots (3 themes, both A/B looks) to the owner at **M2**.

## Design decisions to ASK the owner (do NOT assume — per Jawad's rule)
- **D-3:** dark-first vs light-first (from the Step 0 mockups).
- **Table density:** comfortable vs compact — show a screenshot of both, let him pick.
- **Primary nav:** confirm the top-level items + order above.
- **Scope:** are Financials + Projects launch-scope or fast-follow?
