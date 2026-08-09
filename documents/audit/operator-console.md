# Operator console (platform.staffvora.com) — audit

**Scope:** the whole operator/landlord console — dashboard, tenants list, tenant detail (all 5 tabs), plans, add-ons, features, regions, activity/audit, staff, leads, blog, Site Builder, account, developer panel, and the operator login / invite-accept pages.

**How this was run.** `PLATFORM_HOST` was overridden **at the process level only** (`PLATFORM_HOST=platform.127.0.0.1.nip.io php artisan serve --host=127.0.0.1 --port=8304`). No `.env` and no config file was edited. `platform.localhost` does not resolve on this machine; `platform.127.0.0.1.nip.io` does, so the real `Route::domain()` platform host group was exercised — **these are host-routed findings, not `/_p-*` preview findings.** Logged in as `operator@staffvora.test` (role `owner`, so every role-gated surface was reachable).

The shared Browser pane kept evicting my tab mid-session, so the sweep was run in an **isolated headless Chrome** (puppeteer-core driving the machine's installed Chrome, scratchpad-only install). 95 page-loads across `/dashboard /tenants /tenants/{15,14,1} /plans /addons /regions /features /audit /staff /account /leads /blog /blog/new /blog/2 /site /site/pages/1 /developer` × widths **1920, 1440, 1024, 768, 375**, plus a separate pass clicking all 5 tenant-detail tabs at 4 widths. Every number below (`scrollWidth`, contrast ratio, element widths) is measured, not estimated.

> ### ⚠️ Concurrency warning — read before dispatching fixes
> A **concurrent fix workflow was editing this exact area while the audit ran.** At 05:38 a Vite dev server appeared (`public/hot` → `localhost:5175`), and `Platform/Show.jsx` (05:36), `PlatformController.php` (05:40) and `routes/web.php` (05:36) were rewritten under me.
>
> **Already fixed mid-audit (do NOT re-dispatch, but DO verify):** the tenant page now has a real `SubscriptionPanel` (plan + billing cycle + subscription status + seat-overage acknowledgement), and `PUT /tenants/{organization}/subscription` → `PlatformController::updateSubscription` is now routed (`routes/web.php:136`). Before 05:36 `updateSubscription()` was **fully implemented, documented and unreachable** — no route, no UI — which is exactly the owner's "I cannot change which plan a company is on". It also computed `planImpact` / `settable_statuses` / `billing_cycle` / `base_price_cents` on every tenant page load and threw them away.
>
> Everything else in this report was re-verified against the **live source at the end of the run**. Files untouched since the 00:12 baseline (`PlatformLayout`, `PlatformAuth/*`, `PlatformPortal/Account`, `PlatformPortal/Staff`, `Platform/{Plans,Regions,Addons,Features,Audit,AuditFeed,Leads}`, `Components/ui/Form.jsx`) are fully reliable.

---

## Counts

| Class | Count |
|---|---|
| **BROKEN** | 9 |
| **WRONG** | 8 |
| **UGLY** | 7 |
| **NIT** | 4 |
| **Capability gaps** (missing / broken operator powers) | 14 |

---

# BROKEN

### B1 — Four operator pages have an **invisible page title** (white on near-white, 1.06:1)
- **Pages:** `/blog`, `/blog/{id}` (and `/blog/new`), `/site`, `/site/pages/{id}` · **all widths** · **light theme, which is the only theme this console can render (see B4)**
- **Element:** the `header` prop passed to `PlatformLayout` — `<h2 className="text-xl font-semibold text-white">`
- **Measured:** `rgb(255,255,255)` on `rgb(246,248,251)` (`bg-app`) = **1.06:1**. WCAG AA needs 4.5:1. The heading is literally not there.
- **Why wrong:** these four pages predate the token system and were never converted. `text-white` is only legal on a filled accent/status button.
- **Files:** `resources/js/Pages/Platform/Blog/Index.jsx:8`, `Blog/Editor.jsx:50`, `SiteBuilder/Index.jsx:66`, `SiteBuilder/Editor.jsx:181`
- **Fix:** replace each hand-rolled `<h2 className="… text-white">` with `<PlatformPageHeader title="…" description="…" />` (imported from `@/Layouts/PlatformLayout`), matching every other console page.

### B2 — Tenant detail scrolls horizontally at 375px, on every tab
- **Page:** `/tenants/{id}` · **375px** · Overview, Access, Add-ons, Billing, History — all five
- **Measured:** `document.documentElement.scrollWidth = 383` vs `innerWidth = 375`. Fails the objective test.
- **Element:** the tab strip — `<div className="inline-flex items-center gap-1 rounded-lg bg-surface-2 p-1">` (the `Tabs` component wrapper), right edge **383px**. The `History` button ends at 379px.
- **Why wrong:** `inline-flex` sizes to its content with no overflow container, so 5 tabs cannot fit 375px and push the document wider. Confirmed still present on live post-edit source.
- **Fix:** wrap the `Tabs` strip in `<div className="-mx-5 overflow-x-auto px-5">` (or give the `Tabs` root `max-w-full overflow-x-auto`), the same treatment `PlatformLayout`'s mobile nav already uses.

### B3 — The operator dashboard is a permanent hollow shell, and one tile actively lies
- **Page:** `/dashboard` · all widths
- **What is wrong:**
  - `PortalController::dashboard()` sends **only** `['tenants' => …, 'staff' => …]`.
  - `Dashboard.jsx:77` hardcodes the non-preview branch to `{ stats, mrrTrend: [], plans: [], tenants: [] }`.
  - Result: **MRR**, **Active tenants** and **Trials** render `—` forever (lines 91–93); the **"Revenue trend"** and **"Tenants by plan"** cards are behind `hasTrend`/`hasPlans` and **can never render in production** (lines 124–142); and **"Recent tenants"** always falls to the empty state.
  - The measured page with 3 real tenants (2 active, 1 trialing) says: `TENANTS 3` … `MRR —` … `ACTIVE TENANTS —` … `TRIALS —` … and **"No tenant activity yet — New organizations and their plans will appear here as they sign up."** That statement is false, and `/tenants` two clicks away proves it.
- **Why wrong:** the data all exists. `PlatformController::tenantStats()` (`PlatformController.php:130`) already computes active/trialing/suspended in one grouped SQL query, and the tenants list already renders them.
- **Files:** `app/Http/Controllers/Platform/PortalController.php:17-23`, `resources/js/Pages/PlatformPortal/Dashboard.jsx:77`
- **Fix:** have `PortalController::dashboard()` return the same `tenantStats()` aggregate plus `active`, `trialing`, an MRR sum over active subscriptions, a per-plan tenant count, and the 5 newest organizations. Then the existing markup lights up with no JSX change. If MRR genuinely cannot be computed yet, **remove the MRR tile** rather than ship a permanent `—`.

### B4 — The operator console is locked to the light theme; dark and black are unreachable
- **Everywhere in the console**
- **What is wrong:** `PlatformLayout.jsx` renders no theme switcher (verified: no `ThemeSwitcher` import; page text contains no theme control). `HandleInertiaRequests` resolves `theme` from `$user?->theme` — the **customer `web` guard** user, which is always `null` on the platform host — falling through to `'light'` (`HandleInertiaRequests.php:151-153`). `PUT /preferences/theme` (`routes/web.php:279`) is on the customer host only and is not in the `Route::domain(config('platform.host'))` group.
- **Measured:** `data-theme="light"`, `htmlBg rgb(246,248,251)` on both `/login` and `/dashboard`; no way to change it.
- **Why wrong:** `PlatformLayout`'s own docblock claims it is "fully tokenized so it reads correctly in all three themes (dark / light / black)" — an operator can never see two of them, and the token work is untested in practice.
- **Fix:** add the `ThemeSwitcher` to the `PlatformLayout` user menu and add a platform-host theme route that persists on `platform_users` (or, minimally, on the `tt_theme` cookie for the platform host).

### B5 — There is no operator password reset
- **Page:** `/login`
- **What is wrong:** no "Forgot password?" link on the login card (`PlatformAuth/Login.jsx` — verified no `forgot`/`reset` string), and **no password-reset route exists inside the platform domain group** (`routes/web.php:104-…` has only `/login`, `/invite/{token}`, and the authed group).
- **Why wrong:** an operator who forgets their password is locked out of the console entirely; recovery requires SSH to the production box to run `php artisan platform:owner`. For a console that can suspend every customer and impersonate any account, that is an availability and an operational risk.
- **Fix:** add a platform-guard password-reset flow (`platform.password.request` / `platform.password.reset`) with its own broker + token table, throttled, and link it from the login card.

### B6 — 56 plan toggles have no accessible name
- **Page:** `/plans` · all widths
- **Measured:** 56 `<button>` elements with **no text, no `aria-label`, no `title`** — `class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-line …"`.
- **Cause:** `resources/js/Components/ui/Form.jsx:~160` (`Toggle`) renders a bare `<button>` whose only child is the knob `<span>`. `Plans.jsx` calls `<Toggle id={id} …>` for every flag entitlement (13 flags × 4 plans) and `<Toggle checked={form.data.is_default_trial} …>` for "Default signup plan" — the latter passes **no `id` at all**, so it has neither a label nor an id.
- **Why wrong:** a screen reader announces 56 identical unnamed buttons on the pricing desk. Also `Toggle`'s knob uses **`bg-white`** (`Form.jsx:166`) — a raw colour in a component that must theme.
- **Fix:** give `Toggle` `role="switch"`, `aria-checked`, and either an `aria-label` prop or `aria-labelledby` pointing at the visible `<label htmlFor={id}>` that `EntitlementGrid` already renders (`Plans.jsx:81`). Add an explicit `id`/`aria-label` on the "Default signup plan" toggle (`Plans.jsx:~272`). Replace `bg-white` with a token.

### B7 — Blog editor and Site Builder forms have no labels at all
- **Measured (1440px):**
  - `/blog/new` and `/blog/{id}`: **8 unlabelled fields** (title, slug, category, 2 text inputs, 2 textareas incl. the body editor, excerpt) — no `<label htmlFor>`, no `aria-label`, mostly no placeholder either.
  - `/site`: **33 unlabelled inputs**, including every `<input type="color">` in the Brand/Appearance panels (accent, gradient start, gradient end, headings, dark band × light and dark palettes) and the 5 social-link URL fields.
  - `/site/pages/{id}`: **3 unlabelled inputs** plus **8 icon-only `<button>`s with no accessible name** (`class="rounded p-1 text-subtle hover:text-body"` — the block collapse/expand chevrons and the delete control).
- **Why wrong:** these are the two content surfaces the owner is meant to run "no code, no deploy". They are unusable by keyboard/AT and give no hover affordance for what each colour swatch controls.
- **Files:** `Platform/Blog/Editor.jsx`, `Platform/SiteBuilder/Index.jsx`, `Platform/SiteBuilder/Editor.jsx:124`
- **Fix:** route every field through the shared `<Field label=… htmlFor=…>` + `<Input id=…>` pair used by `Plans.jsx`/`Show.jsx`, and add `aria-label` (or `title`) to the icon-only block buttons.

### B8 — The operator login page's own fields are unlabelled and unfillable by a password manager
- **Page:** `/login`
- **Measured:** email input → `labelled: false`, `aria-label: null`, `autocomplete: null`. Password input → same.
- **Cause:** `PlatformAuth/Login.jsx:20-28` renders `<label className="mb-1 block …">Email</label>` as a **sibling** of the input, with no `htmlFor` and no nesting, so the association does not exist.
- **Why wrong:** the very first screen of the console is inaccessible, and 1Password/Chrome will not autofill without `autocomplete="email"` / `autocomplete="current-password"`.
- **Fix:** add `htmlFor`/`id` pairs and the `autocomplete` attributes. Same defect on `PlatformAuth/AcceptInvite.jsx:22-41` (4 fields).

### B9 — `text-subtle` fails WCAG AA on real data, in all three themes
- **Pages:** every console page (it is a shared token) — measured on `/tenants`
- **Measured contrast:**
  | Theme | `--text-subtle` on surface | Ratio | Needs |
  |---|---|---|---|
  | light | `rgb(148,163,184)` on `#ffffff` | **2.56:1** | 4.5 |
  | light | `rgb(148,163,184)` on `#f1f5f9` | **2.34:1** | 4.5 |
  | dark | `rgb(107,119,147)` on `#0e1628` | **4.02:1** | 4.5 |
  | black | `rgb(107,107,107)` on `#0a0a0a` | **3.72:1** | 4.5 |
- **What it is used for (not decoration — data):** the tenant **slug** under every organization name (`big-org`, `empty-org`, `sparking-asia` — `Index.jsx:191`), the **seat-limit denominator** (`/ ∞`, `/ 50` — `Index.jsx:207`), the result count (`3 matching` — `Index.jsx:161`), the operator's **role** in the user menu (`PlatformLayout.jsx:152`), and the `OPERATIONS` sidebar eyebrow (`PlatformLayout.jsx:108`).
- **Fix:** darken `--text-subtle` in the light theme (e.g. `#64748b`, ~4.8:1 on white) and lighten it in dark/black; and stop using `text-subtle` for the slug and the seat denominator — those are data, use `text-muted`.

---

# WRONG

### W1 — The operator login and invite pages still carry the **old Tracksuite "T" logo**
- **Pages:** `/login`, `/invite/{token}`
- **Element:** `<div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-orange-500 text-xl font-black text-white">T</div>` — literally the character `T` — sitting directly above the words **"Staffvora Operations"**.
- **Files:** `PlatformAuth/Login.jsx:14`, `PlatformAuth/AcceptInvite.jsx:16`
- **Fix:** use `<ApplicationLogo />` (as `PlatformLayout`'s `Brand()` already does at line 104).

### W2 — The login page is off-brand and off-theme (orange, forced dark) vs the blue, light console
- **Measured:** console `--accent` resolves to `rgb(37,99,235)` (blue). The login page hardcodes `bg-orange-500`, `focus:border-orange-500`, `focus:ring-orange-500`, `text-orange-500` and forces `bg-zinc-950` full-screen.
- **Effect:** the operator signs in on a black orange-accented page and lands one click later in a white blue-accented app.
- **Files:** `PlatformAuth/Login.jsx:10,14,22,28,32,36`; `PlatformAuth/AcceptInvite.jsx:12,16,24,29,35,41,44` — **~20 `zinc-*` / `orange-*` class occurrences, the only token violations of that kind in the whole console.**
- **Fix:** convert both pages to `bg-app / bg-surface / border-line / text-body / text-muted / bg-accent`, matching `AuthLayout`.

### W3 — "Trial ends" shows a date for tenants that are not on trial
- **Page:** `/tenants` · all widths
- **Observed:** Northwind Global — status **Active**, Trial ends **2027-08-03**. Fresh Start Co — status **Active**, Trial ends **2027-08-03**. Only Sparking Asia is actually `Trialing`.
- **Cause:** `PlatformController::index()` emits `trial_ends_at` unconditionally (`PlatformController.php:110`) and `Index.jsx:209` prints it regardless of status.
- **Why wrong:** an operator scanning for expiring trials gets three false positives out of three rows.
- **Fix:** render `—` unless `org.status === 'trial'` (or `subscription.on_trial`), or rename the column "Trial ends (if trialing)".

### W4 — "You charge $9.00 per month" is stated per-company for a **per-seat** plan
- **Page:** `/tenants/{id}` Overview — the `You charge` KPI tile and the Subscription card row
- **Observed:** Northwind Global, **120 users**, Growth plan → tile reads **`$9.00` / `per month`**.
- **Why wrong:** every other pricing surface in the console says per seat — `/plans` labels the fields **"Monthly (USD/seat)"** and **"Yearly (USD/seat)"** (`Plans.jsx:203,219`), `/regions` columns are **"/ seat / mo"**, and the add-on rows print `/seat/mo`. Only the tenant page drops the qualifier, so it reads as $9/month total for a 120-seat account instead of $1,080.
- **Files:** `Platform/Show.jsx` (`You charge` KpiCard `sub="per month"`, and the `You charge` `Row` printing `…/mo`)
- **Fix:** print `{money}/seat/mo` and add a computed **"× 120 seats = $1,080.00/mo"** line, or rename the tile to "Unit price".

### W5 — Blog and Site Builder use raw Tailwind palette colours instead of status tokens
- **Occurrences:** `bg-emerald-500/15 text-emerald-400` and `bg-amber-500/15 text-amber-400` for the published/draft chips (`Blog/Index.jsx:28`, `SiteBuilder/Index.jsx:80`); `text-rose-400 hover:text-rose-300` for every destructive control (`Blog/Index.jsx:30`; `Blog/Editor.jsx:65,70,80,91`; `SiteBuilder/Index.jsx:19,32,84,94,170`; `SiteBuilder/Editor.jsx:39,71,86,124`).
- **Why wrong:** these do not re-theme, and they visually diverge from the `Badge tone="success|warning"` / `text-danger` used everywhere else in the console.
- **Fix:** `<Badge tone="success|warning">` for the chips; `text-danger` for the destructive text; `<Button variant="ghost" className="text-danger">` for the buttons.

### W6 — Destructive deletes use the browser's native `confirm()` on 2 pages, a styled modal on 7 others
- **Occurrences:** `Blog/Index.jsx:5` (`if (confirm(\`Delete “${p.title}”?\`))`), `Blog/Editor.jsx` (delete post), `SiteBuilder/Index.jsx` (delete page), `PlatformPortal/Staff.jsx:158` (revoke invitation).
- **Why wrong:** every other destructive action (suspend, resume, impersonate, remove add-on, end trial) goes through the tokenised `ConfirmModal` with a consequence sentence. The native dialog is unstyled, un-themable, and gives no explanation of what deleting a published blog post does to the live marketing site.
- **Fix:** route all four through `ConfirmModal`.

### W7 — The leads inbox silently truncates at 500 and cannot page
- **Page:** `/leads`
- **Cause:** `Platform/LeadController.php:19` — `ContactSubmission::orderByDesc('id')->limit(500)->get()`. No `paginate()`, no pagination UI, no total count beyond `unread`.
- **Why wrong:** lead 501 onward is unreachable from the console with no indication anything is missing — against the standing "must survive at scale" rule. It also loads and serialises 500 rows including full message bodies on every visit.
- **Fix:** `->paginate(50)` with the same `Previous/Next` footer `Platform/Index.jsx:227-236` already implements, plus a search box.

### W8 — `Blog/Index.jsx` and `SiteBuilder/Index.jsx` bypass the console's page chrome entirely
- **What is wrong:** they hand-roll an `<h2>` header instead of `PlatformPageHeader`, hand-roll `<Link className="rounded-lg bg-accent px-4 py-2 …">` instead of `<Button>`, and hand-roll an empty state (`<p className="p-10 text-center text-sm text-muted">`, `Blog/Index.jsx:18`) instead of `<EmptyState>`.
- **Why wrong:** two of the thirteen nav destinations look like a different product. This is also the root cause of B1 and W5/W6.
- **Fix:** rebuild both on `PlatformPageHeader` + `Card` + `Table`/`EmptyState` + `Button`, as `Platform/Index.jsx` does.

---

# UGLY

### U1 — Four pages waste half the screen at 1920 (measured)
Operators use big desktops, and the standing rule is fill the page.

| Page | main available | content capped at | wasted | offending class |
|---|---|---|---|---|
| `/blog` | 1680px | **896px** | 784px | `mx-auto w-full max-w-4xl` (`Blog/Index.jsx:10`) |
| `/site` | 1680px | **896px** | 784px | `mx-auto w-full max-w-4xl` (`SiteBuilder/Index.jsx:68`) |
| `/blog/{id}` | 1680px | **768px** | 912px | `mx-auto w-full max-w-3xl` (`Blog/Editor.jsx:52`) |
| `/site/pages/{id}` | 1680px | **768px** | 912px | `mx-auto w-full max-w-3xl` (`SiteBuilder/Editor.jsx:183`) |
| `/account` | 1680px | **672px** | 1008px | `mx-auto max-w-2xl` (`PlatformPortal/Account.jsx:48`) |

`/tenants`, `/plans`, `/regions`, `/features`, `/audit`, `/staff`, `/dashboard` correctly fill 1680px — so this is an inconsistency, not a house style. **Fix:** drop `mx-auto max-w-*` and use the responsive grids the other pages use (the page editors can keep a reading-width *column* inside a full-width two-column layout with a live preview beside it).

### U2 — The Features table clips its Actions column on small laptops
- **Page:** `/features` · **1024px and 768px**
- **Measured:** table intrinsic width **757px** inside a **718px** (1024) / **702px** (768) `overflow-x:auto` wrapper. The `Actions` `<th>` and every `Edit` button sit at right edge **1030px** (1024) / **790px** (768) — i.e. off-screen.
- **Effect:** at 1024 the operator sees no Edit button at all until they discover the table scrolls sideways. 41 elements overflow at 1024, 62 at 768, 371 at 375.
- **Fix:** collapse `Group` and `Granted by` below `xl`, or make `Actions` a sticky right column (`sticky right-0 bg-surface`).

### U3 — The mobile/tablet nav hides most of itself with no affordance
- **Widths:** 768 and 375 · `PlatformLayout.jsx:216` — `<nav className="flex gap-1 overflow-x-auto px-3 pb-3">`
- **Measured:** at **375px**, 9 of 13 items are off-screen (`Regions` right edge 404 … `Account` 992). At **768px**, `Developer`, `Staff`, `Account` are off-screen (854 / 911 / 992).
- **Why ugly:** it scrolls, so nothing is technically dead — but there is no fade, no chevron and no scrollbar, so the console looks like it only has 4 sections on a phone.
- **Fix:** add an edge fade mask (or a "More" overflow menu) and `scroll-snap`, and auto-scroll the active item into view on mount.

### U4 — Numeric columns are left-aligned
- **Page:** `/tenants` · `Index.jsx:180 <Th>Users</Th>` / `:205 <Td className="tabular-nums">`, and `<Th>Trial ends</Th>` / `:209`.
- **Why:** the brief and normal table practice put counts and dates right-aligned with tabular figures so digits line up down the column. `tabular-nums` is already applied — only the alignment is missing. The dashboard's own (unreachable) table already does this correctly (`Dashboard.jsx:156-159` uses `text-right`).
- **Fix:** add `text-right` to both the `Th` and `Td` for Users and Trial ends.

### U5 — Every DEV_SAMPLE fixture ships to production
- **Files:** `Show.jsx`, `Index.jsx`, `Plans.jsx`, `Regions.jsx`, `Features.jsx`, `Audit.jsx`, `Dashboard.jsx`, `Staff.jsx`, `Account.jsx` — 9 operator pages each carry a `DEV_SAMPLE` object of fake tenants/plans/staff ("Blue Harbor Studio", "Amelia Cortez", "Halcyon Labs", `BANK-2026-0043`…). `Show.jsx`'s alone is ~160 lines.
- **Why ugly:** the `/_p-*` preview routes are correctly gated to `app()->environment('local')` (`routes/web.php:55`), but the fixtures are referenced at runtime (`preview ? DEV_SAMPLE : …`) so Vite cannot tree-shake them — the JS bundle every operator downloads contains invented customer names and fake bank references.
- **Fix:** move each `DEV_SAMPLE` into a sibling `*.preview.js` imported via `import.meta.env.DEV` (or a dynamic import behind the `preview` prop) so production drops it.

### U6 — `/staff` shows the owner's own row with `ACTIONS: —` and no explanation
- **Page:** `/staff` — the only row is `Verify Operator (you)` with a bare em dash in the Actions column. Correct behaviour (you cannot revoke yourself) but reads as a broken/empty cell.
- **Fix:** replace `—` with a muted "You cannot revoke your own access" hint or a disabled button with a `title`.

### U7 — Blog list has no pagination, no search, no draft/published filter
- **Page:** `/blog` — `BlogController::index` returns all posts; the list is a flat `divide-y` stack. Fine at 6 posts, unusable at 200.
- **Fix:** paginate + add a status filter, reusing the `Platform/Index.jsx` footer.

---

# NIT

- **N1** — No "Skip to content" link anywhere in `PlatformLayout`; a keyboard user tabs through 13 nav links on every page. Focus rings themselves are fine (measured: 2px solid outline + accent ring on all interactive elements).
- **N2** — `/blog` empty state is `text-center` prose (`Blog/Index.jsx:18`) while every other empty state uses the left-aligned `<EmptyState>` component.
- **N3** — `/tenants` table headers are not sortable (measured: 0 of 6 `<th>` contain a button or link). No sort by seats, by trial-end date, or by created date.
- **N4** — `Regions.jsx:198,214` uses `text-center` on the "Offered" column. This one is **correct** (a single toggle glyph) — noted only so a fixer does not "fix" it.

---

# CAPABILITY — what the operator still cannot do

Walked every tenant-management surface and every platform route. Legend: **MISSING** = no route at all · **BROKEN** = route exists but the path is unusable · **HIDDEN** = works but is not findable.

### Present and good (do not touch)
Suspend / resume · impersonate (audited twice, requested + entered) · trial extend +7/+14/+30, end now, set exact date · record a bank-transfer payment and extend the paid period · per-company entitlement overrides with grant / revoke / `unlimited` / expiry / reason · custom price + discount % · attach & detach add-ons with a start/end window · tenant search + status filter + plan filter + pagination · per-tenant change history · cross-tenant activity log with action/company/operator/date filters · full plan CRUD, reorder, duplicate, archive/restore, default-signup-plan · add-on and region catalogues · feature catalogue · staff invite/revoke · **and, as of the 05:36 concurrent edit, plan + billing-cycle + subscription-status change with seat-overage acknowledgement.**

The **"why is this feature on or off"** question the brief asks about is answered *well*: the Access tab shows plan / add-on / override layers side by side with a source badge, driven by the same resolver the gate uses. That is the strongest part of the console.

| # | Operation | Verdict | Evidence / consequence |
|---|---|---|---|
| C1 | **Change which plan a company is on** | **HIDDEN → now duplicated** | The only pre-05:36 path was a bare `<Select id="tc-plan">` labelled "Switch plan" buried in **Access → Commercials**, below the fold, on a tab named after entitlements rather than billing (`Show.jsx` `AccessTab`). Nothing on Overview or in the Subscription card hinted it existed — hence the owner's complaint. It is now *also* on the new `SubscriptionPanel`. **There are currently two plan switchers on one page, posting to two different endpoints.** Decide which one survives; leaving both invites divergent behaviour. |
| C2 | **Downgrade a tenant that exceeds the target plan's seats** | **was BROKEN, now fixed — verify** | `updateTenant` validates `acknowledge_seat_overage` (`PlatformController.php:359`) but the Access form never sent it and offered no checkbox, so `SubscriptionManager::changePlan` threw and the downgrade was impossible for any over-seat tenant. The new `SubscriptionPanel` adds the acknowledgement. **Re-test: Northwind Global (120 users) → Starter (10 seats).** |
| C3 | **Refund / void / correct a recorded payment** | **MISSING** | `platform.payment` records; there is no delete, void or adjust route, and `Show.jsx` renders payment history as a read-only table. A mistyped amount permanently extends the customer's paid period and cannot be undone from the console. |
| C4 | **Cancel or delete a tenant** | **MISSING** | Only `suspend` (status flip). No route to cancel a subscription outright, close an account, or delete/anonymise an organization. There is no GDPR erasure path for a customer who asks to be deleted. |
| C5 | **Transfer tenant ownership** | **MISSING** | No route. If the org owner leaves the company, the operator cannot promote another user. |
| C6 | **Resend an invitation** | **MISSING** | Neither for operator staff (`Staff.jsx` offers revoke only — you must revoke and re-invite, which mints a new token) nor for tenant users. |
| C7 | **Change an existing operator's role** | **MISSING** | Role is fixed at invite time. `platform.staff.*` has `invite` + `revoke` + `invitation.revoke` and no `update`. Promoting Support → Admin means revoking their account and re-inviting. |
| C8 | **Impersonate a specific user** | **MISSING** | `PlatformController::impersonate` (line 639) always targets `$organization->owner`, falling back to the first user in the org. An operator cannot sign in as the employee who actually reported the bug. |
| C9 | **Export anything** | **MISSING** | Measured: no export/CSV/download control on `/tenants`, `/audit`, `/leads`, or the payment history. An operator cannot pull a tenant list for finance or hand an auditor the activity log. |
| C10 | **Sort the tenant list** | **MISSING** | 0 of 6 column headers are interactive. At 500 tenants, "who is closest to their seat cap", "whose trial ends this week", "who churned" are all unanswerable without the DB. |
| C11 | **See tenant health / usage** | **MISSING** | The tenant page shows seats used, plan, status, trial end and price. It shows **no storage used vs limit, no screenshot count, no last-activity date, no active-user count**. An operator cannot distinguish a thriving account from a dead one, or see who is about to blow a storage ceiling — even though `max_storage_gb` and `max_retention_days` are enforced entitlements. |
| C12 | **Reply to, note, or assign a lead** | **MISSING** | `/leads` has mark-read and delete only. No reply, no status, no owner, no "converted to tenant" link. |
| C13 | **Search globally** | **MISSING** | No shell-level search. Finding a customer by their *user's* email address (the only identifier a support ticket usually carries) is impossible — `PlatformController::index` searches organization `name` and `slug` only (`PlatformController.php:78`). |
| C14 | **Recover a locked-out operator** | **MISSING** | See B5. Requires production SSH. |

---

## Dead / unreachable code found

1. **`PlatformController::updateSubscription()`** — at audit start: ~40 lines, fully documented ("PER-COMPANY SUBSCRIPTION MANAGEMENT — the control the console was missing"), calling `SubscriptionManager::apply()`, **with no route and no caller**. Routed at 05:36 by the concurrent workflow. *Resolved — verify.*
2. **`SubscriptionManager::impactMap()`** — invoked on every `/tenants/{id}` load (`PlatformController.php:205`), resolving the entitlement diff for *every* plan against the org, and the result (`planImpact`) was destructured by nothing. Now consumed by `SubscriptionPanel`. *Resolved — verify.*
3. **`subscription.settable_statuses`, `.cycles`, `.billing_cycle`, `.on_trial`, `.usable`; `organization.base_price_cents`; `plans[].max_users`** — all serialised to the client and unused. Mostly consumed now; **`organization.base_price_cents` and `subscription.usable` still appear unused** — confirm and drop.
4. **9 × `DEV_SAMPLE` fixtures** shipped in the production bundle — see U5.
5. **`Blog/Index.jsx`, `Blog/Editor.jsx`, `SiteBuilder/Index.jsx`, `SiteBuilder/Editor.jsx`** are effectively a pre-design-system fork of the console: no `PlatformPageHeader`, no `Button`, no `Field`, no `Badge`, no `EmptyState`, no `ConfirmModal`, `text-white` headings, raw palette colours. Not dead, but duplicated — deleting the hand-rolled markup in favour of the shared components removes B1, B7, W5, W6, W8 and U1 in one pass.

## Things that are fine (checked, no finding)

- Horizontal-scroll test passes at 1920/1440/1024/768 on **all 19 pages**, and at 375 on all except `/tenants/{id}` (B2). All wide tables sit in `overflow-x:auto` wrappers.
- **Zero JS console errors and zero page errors** across all 95 loads; every page returned HTTP 200; no failed requests.
- Every link in the tenant table resolves to a real URL (`/tenants/15`, `/tenants/14`, `/tenants/1`) — no `href="#"` anywhere in the console.
- `askSuspend` correctly branches to the Resume dialog for suspended tenants (`Index.jsx:94`) — not the bug it looks like.
- Focus rings are present and visible on all interactive elements (2px outline + accent ring).
- Search / status / plan filters on `/tenants` are properly labelled, debounced at 300ms, and filtered server-side in SQL with an indexed subquery — correct at scale.
- The `/regions` `text-center` "Offered" column is correct usage (a single toggle glyph).
- Plan card values render correctly (Free 2/1/0/10/7, Starter 10/10/12/5/90, Growth 50/50/30/1/365, Enterprise unlimited/unlimited/60/1/unlimited) — the `∞` in the page text is the "set unlimited" toggle button, not a rendering bug.
- `--accent` in light resolves to `rgb(37,99,235)`; active nav and plan badges measure **5.17:1** — pass.
