# Staffvora — Zero-to-100 Plan (build → launch → marketing)

> The master plan for rebuilding the product (formerly Tracksuite) as **Staffvora**
> (staffvora.com) to an **industry standard** — the bar: *a big company signs up and
> feels they're using a serious product.* Hubstaff is **inspiration for features,
> layout and craft only — never their colors or branding.** We build it properly,
> phased, not rushed. Status: DRAFT for Jawad's review.

---

## The goal (one line)
**Staffvora: an industry-standard, multi-tenant time-tracking SaaS in two editions
(General + Agency), taken zero-to-100: rebuild → launch → market.**

## Jawad's locked directions (from review sessions)
- **Top navigation** (not sidebar). Nothing buried; easy to explore.
- **Persistent Clock In / Clock Out control** in the top bar, on every page (state-aware:
  clocked in / out / on break) — *not* a Hubstaff-style running-timer pill.
- **Locked features are SHOWN, not hidden** — lower plans see them greyed with an
  **Upgrade** CTA ("just-tease" system).
- **Our own brand** — electric blue, our identity, our look.
- **Ask before non-obvious design defaults** (no silent "centered tables" choices —
  options are presented for approval).
- Runs on VPS → speed is expected; performance is a first-class workstream.
- Works beautifully with **heavy data AND empty/low data** — never hangs, never looks broken.

---

## What "industry standard" means (distilled from the 32-screenshot Hubstaff review)

### The craft bar (how it must FEEL)
1. One consistent page scaffold everywhere: Title → toolbar (member/project/date-range/
   timezone filters) → data → result count. Learn one page, know them all.
2. **Customizable widget dashboard** — "Manage widgets" catalog (small + large widgets),
   ME vs ALL scope tabs.
3. **Global running-timer pill** in the top bar (Jawad's requirement — always visible).
4. **One rich date-range picker** reused everywhere: preset rail (Today / Yesterday /
   Last 7 days / Last week / This month…) + dual-month calendar + Apply.
5. **First-class empty states** — every widget/table has a designed "no data" state or an
   educational sample + "Set it up" CTA. Positive framing where apt ("That's good news!").
6. **Skeleton loaders** for anything async — perceived speed, nothing ever blank/frozen.
7. Stat cards with **sparkline + ▲/▼ delta**; purpose-fit charts: donut gauge (utilization
   vs target with variance pill), half-circle gauge (idle), 24-hour timeline ribbon,
   %-allocation bars, activity-% pills.
8. **ⓘ info tooltips** on every metric — self-documenting analytics.
9. Tables that scale: bulk select + batch actions, per-row Actions dropdown, column
   toggles, export, search, filters — as one standard cluster.
10. Segmented controls for view modes (Daily/Weekly/Calendar; Active/Archived) — no
    full-page reloads.
11. Progressive onboarding: "Get started" progress, dismissible promos, "New" badges,
    integration CTAs inside the widgets that need them, persistent Feedback tab.
12. **Trust/consent design** (critical for tracking software): plain-language disclosure,
    "not keystroke logging," "you're in control" — shown in-app and in the desktop tracker.
13. Multi-timezone display selector (member's / mine / organization time) — we already
    built the org-timezone foundation for this.
14. Desktop ↔ web **deep links** (custom URL scheme), offline buffering with visible
    "unsent records" count.

### The feature bar (WHAT it must have — deduped Hubstaff catalog mapped to us)
| Area | Features | We have | Port from timetracker | Build new |
|---|---|---|---|---|
| Tracking | Desktop timer, offline buffer, idle detection, activity % | ✅ | 0.4.x upgrades (clicks, telemetry, diagnostics) | Web timer + top-bar pill |
| Monitoring | Screenshots, apps/URLs, consent gate | ✅ | tracker-health watchdog | Apps/URLs productivity classification (later) |
| Timesheets | Daily/Weekly/Calendar views, manual time | partial | manual-time windows/gap-fill, locked tracked entries | **Approvals + pay periods (Approve/Deny)** |
| Attendance/Scheduling | grid, shifts, breaks, time off | ✅ (needs redesign) | named shifts, per-day overrides, My Schedule | Time-off/PTO policies (later) |
| Insights | — | ❌ (zero live charts) | team charts service | **Utilization-vs-target, idle gauge, timeline ribbon, focus, unusual-activity highlights** |
| Projects/Clients | clients, tags, budgets | partial | archive lifecycle | Active/Archived tabs, budgets, bulk actions, To-dos (later) |
| People/HR | users, roles, permissions | ✅ | archive, per-worker tz | custom fields (later) |
| Financials | manual billing | partial | — | invoices + payroll (post-launch phase) |
| Platform | multi-tenant, entitlements, operator portal, marketing site | ✅ (our edge) | help KB + feedback inbox | widget dashboard, upgrade-gating, edition pricing, regions |
| AI | assistant + day summaries | ✅ (our differentiator — Hubstaff lacks this) | — | keep + surface prominently |

---

## Foundation decision (recommendation)
**Keep the multi-tenant Staffvora codebase as the base.** It owns the expensive parts
(tenancy, entitlements, billing, operator portal, marketing site). **Port** timetracker's
mature modules and correctness fixes into it. Do NOT rebuild on single-company timetracker.
*(Jawad to confirm — flagged as Decision D-1 below.)*

---

# The phases (zero → 100)

### Phase 0 — Lock decisions & the standard *(gate: Jawad's answers)*
Deliverables: this plan approved; decisions D-1…D-8 answered; plan→feature pricing
matrix drafted & approved; design direction chosen from presented options.
**Milestone M0: "We know exactly what we're building."**

### Phase 1 — Rename & rebrand → Staffvora
One clean pass: brand config, all UI copy, marketing site, operator portal ("Tracksuite
Operations" → Staffvora), desktop app naming plan, GitHub repo → `staffvora`, domain
staffvora.com wiring plan (DNS/SSL/deploy). Old domain redirects.
**Milestone M1: no trace of Tracksuite/satimetracker anywhere a customer looks.**

> **STATUS (2026-07-22): WEB RENAME DONE (local, verified).** `Tracksuite → Staffvora`
> across **39 web files** (config-driven + hardcoded: config/brand.php name+legal, config/
> app.php APP_NAME, marketing site, operator portal, auth, PWA manifest, all UI copy, error
> pages); brand contact emails → `hello@staffvora.com`; local `.env` APP_NAME → Staffvora;
> local site-theme row rebranded. `config('brand.name')`/`app.name` = **Staffvora**. Build
> clean, **206 tests pass.** (Local `database.sqlite` was corrupted mid-work by two Claude
> sessions writing the same SQLite file concurrently — backed up + rebuilt clean; a stale
> config cache pointing at `:memory:` was also cleared. Prod untouched.)
> **DEFERRED / EXTERNAL (owner or cutover):**
> - **Register staffvora.com** + DNS + SSL, set up `hello@staffvora.com` mailbox.
> - **GitHub repo → `staffvora`** (owner renames; then update remote URL locally).
> - **Domain cutover** (do together, when staffvora.com is live): flip `APP_URL`,
>   `config/platform.php` host, `public/robots.txt` sitemap, and the desktop backend URL from
>   staffvora.com → staffvora.com; add a staffvora.com→staffvora.com redirect.
>   *(These operational domain refs were intentionally left on staffvora.com so nothing
>   breaks before the domain is live.)*
> - **Desktop app rebrand** (own sub-task, needs a rebuild + re-sign): `desktop/` strings,
>   productName, appId, installer, icons → Staffvora.

### Phase 2 — Design system + new app shell (the "feel" foundation)
- Delete the 3 dead component systems; build ONE component library (Button, Card, Table,
  Badge, StatCard, EmptyState, Skeleton, Field, Modal, Toast) on our tokens + electric blue.
- **NEW APP SHELL: top navigation** (grouped menus, Favorites/pinning, search), with a
  persistent **Clock In / Clock Out control** (state-aware: in / out / on-break) +
  notifications + user menu in the top bar. *(Per D-6 — not a Hubstaff timer pill.)*
- The **upgrade-gating pattern**: `<Locked>` wrapper — feature visible, greyed, lock badge,
  "Upgrade to unlock" CTA → plan page. Driven by the existing entitlements engine.
- Chart system: theme-aware Chart.js factory, accent-derived palette, gauge/donut/
  sparkline/ribbon primitives, chart chrome (title+legend+empty+skeleton).
- The standard page scaffold + the universal date-range picker + timezone selector.
- **Design options presented to Jawad for approval before build** (shell mockups, table
  density, card style — 2 options each).
**Milestone M2: one screen (Dashboard) rebuilt end-to-end in the new system, approved.**

### Phase 3 — Core pages rebuilt on the new system (worst-first)
Order: Dashboard (widget system) → Attendance (worst offender today) → Timesheets/Work
Diary (Daily/Weekly/Calendar) → Timeline/Activity → Reports → Team/Insights (the new
analytics pages with the full chart vocabulary) → People/Users/Teams → Projects/Clients →
Settings (hub-with-cards layout + search) → Billing → Help → Auth (one on-brand family) →
Operator portal cleanup.
Every page ships with: empty state + skeleton + heavy-data strategy (server-side
aggregation, pagination) + all-themes screenshots + tests green.
**Milestone M3: every page on the new system; app is visually one product.**

### Phase 4 — FULL timetracker port, then improve each feature (per D-1)
- **Port EVERYTHING timetracker has** that the fork lacks (it predates ~a month of work):
  Help KB + feedback/ticket inbox · named shifts + My Schedule + per-day overrides ·
  manual-time windows/gap-fill + locked tracked entries · archive lifecycle · per-worker
  timezone · team analytics service + charts · desktop 0.4.x (telemetry, diagnostics,
  click counts, tracker-health) · Machine-Compliance (→ Agency tier) · attendance Slack
  threading · S3 usage monitor · correctness repair commands · security-audit fixes.
- **Then the improvement pass:** each ported feature reviewed against the industry-standard
  checklist (§ craft bar) and upgraded — not just copied.
- Build new on top: **timesheet approvals + pay periods** · widget dashboard completion ·
  Insights v1 (utilization-vs-target, idle gauge, timeline ribbon, highlights).
- Desktop tracker: full Staffvora rebrand (D-7) + point at staffvora.com + consent polish.
**Milestone M4: everything timetracker has, in Staffvora, improved — plus the new
industry-standard capabilities.**

### Phase 5 — Two coexisting editions + pricing + regions (the business layer)
- **Edition model (per D-2 + D-8): base plans + a priced "Agency tools" ADD-ON.** General =
  every-company toolset on the base plans; Agency = base plan **+ the Agency add-on**
  (profiles, bidding, compliance) — per-seat priced, Hubstaff-style. Gating driven by plan/
  add-on **entitlements**, so General→Agency = enabling one add-on: **nothing migrates,
  tools just unlock.** The add-on engine is generic (later: Insights add-on, retention
  add-on, etc.).
- **Customer billing hub rebuilt to the 4-tab pattern** (Billing info + add-on cards ·
  Invoices · Subscription settings with live invoice preview · Client invoice), with
  add-on cards showing name + per-seat price + feature checklist + Add/Remove/Trial.
- Finish the General purge: zero Upwork wording/fields/schema visible on General plans;
  locked Agency tools appear greyed with the **Upgrade** CTA (the "shown, not hidden" rule).
- **Operator pricing manager**: create/edit pricing structures anytime (placeholder prices
  at launch per D-4); **region rules** (allow/deny/price-by-region) at signup/checkout.
- Payments: Alfa Payment Gateway (sandbox → production) hosted checkout + IPN webhook;
  checkout page styled per the payment-page screenshots Jawad is providing (D-8).
**Milestone M5: BOTH editions live and coexisting; a customer picks a plan, pays the right
regional price, gets exactly the right toolset, and can upgrade General→Agency in one step.**

### Phase 6 — Performance, scale & reliability hardening
- Server-side SQL aggregation + covering indexes + caching for every analytics surface
  (no PHP loops that die on big orgs — timetracker's 400-queries→6 lesson applied app-wide).
- Pagination/virtualization on all large tables; lazy widget loading; queue worker for
  heavy exports/reports.
- Load test with a synthetic BIG org (100+ users, months of screenshots) AND a brand-new
  empty org — both must feel instant and look intentional.
- Security pass (mirror timetracker's sanitization audit: IDOR/SQLi/XSS/CSRF/uploads),
  backups, error monitoring, uptime checks.
**Milestone M6: fast at both extremes, secured, monitored.**

### Phase 7 — Launch readiness
- Onboarding polish (wizard + "Get started" checklist + sample-data demo mode).
- Help center content written; feedback loop live; consent/privacy pages; legal (Terms/
  Privacy with real company name).
- Marketing site rewritten for Staffvora: two-edition positioning, real pricing, feature
  pages, comparison vs Hubstaff/Time Doctor ("we measure the work, not the worker" + AI).
- Full regression: tests + cross-theme + cross-edition + payment flow end-to-end.
- Deploy runbook to VPS; staffvora.com live; desktop installers hosted + auto-update.
**Milestone M7: LAUNCH. A stranger can sign up, pay, track, and get support.**

### Phase 8 — Marketing & growth (post-launch)
- SEO engine: blog cadence (the CMS + 6 seeded posts exist), comparison/alternative pages,
  glossary — flags already scaffolded.
- Launch channels: Product Hunt, LinkedIn, local PK business networks (PKR pricing +
  JazzCash/Easypaisa story is a real wedge), agency communities for the Agency edition.
- Feedback→roadmap loop from the in-app inbox; iterate pricing; win-back/trial-ending
  email automation (operator-portal idea bank already lists these).
**Milestone M8: repeatable acquisition running; roadmap driven by real users.**

---

## Decisions (Jawad's answers — LOCKED 2026-07-22)
- **D-1 Foundation — ✅ LOCKED.** Keep the Staffvora base (billing/tenancy/operator portal =
  the expensive parts, already done). Then **port EVERY feature from timetracker** (the fork
  predates most of them), and after porting, **improve each feature to industry standard.**
  Full port, not selective.
- **D-2 Editions — ✅ LOCKED, refined model.** Build **BOTH editions before going public** —
  launch only when both exist; build order doesn't matter. Model: **General ⊂ Agency** —
  General = the tools every company needs; **Agency = General + the Upwork-specific tools**
  (profiles, bidding, compliance). Editions **coexist**, and a General customer can
  **upgrade to Agency seamlessly** (it's purely additive — nothing to migrate).
  *Architectural implication:* edition gating moves from a hard `workspace_type` switch to
  **plan entitlements** (e.g. `profiles` as a priced entitlement) so upgrade = plan change.
- **D-3 Look — OUR OWN identity, not Hubstaff's.** We take Hubstaff's *craft* (spacious,
  clear, great states) but the visual identity is Staffvora's own (electric blue). Default
  theme + density: **to be picked from 2 designed mockup options at the M2 gate** —
  recommendation will be presented, Jawad chooses. *(The one still-open sub-decision.)*
- **D-4 Pricing — ✅ LOCKED.** Placeholder pricing now; the **operator backend pricing
  manager** (add/edit structures, change anytime) is the real requirement. Real numbers later.
- **D-5 Regions:** deferred — decide at Phase 5.
- **D-6 Top-bar control — ✅ LOCKED, changed.** NOT a Hubstaff-style running-timer pill.
  The persistent top-bar element is a **Clock In / Clock Out control** (with state — in,
  out, on break) matching our attendance-first model. Visible on every page.
- **D-7 Desktop rebrand — ✅ LOCKED.** In scope: full Staffvora rebrand of the desktop app.
- **D-8 Payments — ✅ screenshots received (Hubstaff billing pages, 2026-07-22).** What we
  adopt from them (patterns, not branding):
  - **The ADD-ON model** — Hubstaff sells a base plan + per-seat add-ons (Insights $3/seat,
    Silent app $3, More screenshots $3, Data retention $2, Locations $4), each shown as a
    card: name + price + feature checklist + Add/Remove/Try-free button, with add-on
    trials and an auto-billing warning banner. **This is exactly our D-2 model made
    concrete: "Agency tools" = a priced add-on bundle on a base plan** — upgrade
    General→Agency is just enabling the add-on. Machine-Compliance / Insights can be
    add-ons later too. The operator pricing manager must therefore manage **plans AND
    add-ons** (both per-seat).
  - **Customer billing hub, 4 tabs:** Billing information (plan status chip, Change plan,
    payment method status, referral discounts, billing history, add-on cards) ·
    Subscription invoices (All/Paid/Unpaid tabs, status chips, expandable rows, count +
    pagination, "updates within 24h to reflect paid seats") · Subscription settings
    (bill-to address incl. Pakistan, tax ID, notes, email-notification prefs, **live
    invoice preview** that renders the real invoice as you type — adopt this) · Client
    invoice (see below).
  - **Client invoicing** (customer → THEIR clients): invoice logo, tax rate, net terms,
    notes, and **auto-invoicing** (hourly/fixed, frequency, delay, reminders, line items
    grouped by user/project/date, include non-billable/expenses). Strong Financials
    feature for BOTH editions — scheduled into the Financials workstream (post-M5).
  - Gateway rail: **Alfa Payment Gateway** confirmed (sandbox done); hosted checkout +
    IPN. International card story evaluated post-launch.

## Ways of working (so this goes smoothly)
- Jawad reviews at every milestone gate (M0…M8) — nothing advances past a gate unapproved.
- Design choices come as **presented options**, never silent defaults.
- One session edits code at a time (terminal Claude builds; this session plans/reviews) —
  no collisions.
- The master tracker doc stays the day-to-day log; THIS document is the roadmap.
