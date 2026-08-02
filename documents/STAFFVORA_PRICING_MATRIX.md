# Staffvora — Placeholder Pricing Matrix

> **What this is:** the plan→feature matrix that closes the last open item on **Milestone M0**
> ("we know exactly what we're building"). It turns the locked edition model (D-2 + D-8) into a
> concrete catalogue the operator pricing manager can be built against.
>
> **Every price in this document is a PLACEHOLDER.** Per decision **D-4**, real numbers are set
> later in the operator pricing manager — the requirement is that the *structure* is right, not
> that the numbers are final. Nothing here is charged to anyone until you approve real prices.
>
> **Status:** DRAFT for Jawad's approval · 2026-07-29
> **Sources:** `STAFFVORA_ZERO_TO_100_PLAN.md` (Phase 5, D-1…D-8) · `app/Tenancy/Entitlements.php`
> · `app/Models/Plan.php` · `STAFFVORA_PHASE4_PORT_PLAN.md` · `STAFFVORA_WORK_VOCABULARY.md`

---

## 1. The model in three sentences

1. **We sell per-seat base plans, plus optional per-seat add-ons** — you pay for the plan, and
   bolt on extra toolsets at a price per user per month (exactly how Hubstaff sells Insights,
   extra screenshots and data retention).
2. **The "Agency tools" bundle is an add-on, not a separate product** — Upwork profiles, bidding
   categories and Machine Compliance attach to *any* base plan, so a General customer who becomes
   an agency **flips one switch: nothing migrates, no new account, no data move, tools just unlock.**
3. **One catalogue instead of two price lists** — a single set of plans means one pricing page, one
   billing system, one set of invoices, and every future capability (Advanced Insights, longer data
   retention, API) plugs into the same add-on shelf without ever splitting the product again.

**Why this beats "Agency edition vs General edition" price lists:** two price lists means two
pricing pages, two upgrade paths, two support stories, and a painful migration every time a
customer changes shape. Add-ons mean the *upgrade is a checkbox* — which is also the highest-margin
revenue in SaaS, because the customer is already paying and already trusts you.

---

## 2. THE MATRIX — base plans

**Reconciliation with the code (important):** the plan keys `free`, `starter`, `pro`, `enterprise`
**already exist** in `app/Models/Plan.php` and `app/Tenancy/Entitlements.php`. This matrix keeps
those four keys exactly as they are — **no data migration, no billing key changes.** The only
proposed change is the *display name* of `pro` → "Growth" (a single text field the operator plan
editor already lets you edit). If you prefer "Pro", change nothing at all.

| Display name | **Free** | **Starter** | **Growth** | **Enterprise** |
|---|---|---|---|---|
| Plan key in the system | `free` | `starter` | `pro` | `enterprise` |
| **Price — USD / seat / month** *(placeholder)* | **$0** | **$5** | **$9** | **Custom** (from $18) |
| **Price — PKR / seat / month** *(placeholder, Pakistan)* | **Rs 0** | **Rs 900** | **Rs 1,600** | **Custom** (from Rs 3,200) |
| **Annual (pay 10 months, save 17%)** | — | $50 / Rs 9,000 per seat/yr | $90 / Rs 16,000 per seat/yr | Negotiated |
| **Seat range** | 1–2 seats | up to 10 | up to 50 | Unlimited |
| Free trial | n/a | 14 days | 14 days | Pilot by agreement |
| — | | | | |
| **Time tracking, attendance, timesheets, reports** | ✅ | ✅ | ✅ | ✅ |
| Desktop tracker (Windows/Mac) | ✅ | ✅ | ✅ | ✅ |
| Clients / projects, work categories, CSV export | ✅ | ✅ | ✅ | ✅ |
| — | | | | |
| **Screenshot monitoring** | ✅ | ✅ | ✅ | ✅ |
| Screenshot rate (max per hour) | 6 | 12 | 30 | 60 |
| Screenshot retention (max) | 14 days | 30 days | 90 days | 365 days |
| Idle auto-pause (tightest allowed) | 10 min | 5 min | 1 min | 1 min |
| **Activity tracking** (keyboard/mouse %) | ❌ | ✅ | ✅ | ✅ |
| **App & website tracking** | ❌ | ✅ | ✅ | ✅ |
| — | | | | |
| **Weekly hour limits per person** | ❌ | ✅ | ✅ | ✅ |
| **Attendance Slack alerts** | ❌ | ✅ | ✅ | ✅ |
| **Slack integration** (your own workspace bot) | ❌ | ✅ | ✅ | ✅ |
| **AI assistant & day summaries** | ❌ | ❌ | ✅ | ✅ |
| **White-label branding** (your logo, name, colours) | ❌ | ❌ | ✅ | ✅ |
| **API access** *(see §7 — not built yet)* | ❌ | ❌ | ✅ | ✅ |
| — | | | | |
| **Agency tools add-on available?** | ❌ (shown locked) | ✅ | ✅ | ✅ (usually included) |
| Support | Help centre | Help centre + email | Priority support | Named contact + onboarding |

> Every row above except the greyed "not built yet" ones maps to a **real switch that already
> exists in the code** (`app/Tenancy/Entitlements.php`): `max_users`, `max_screenshots_per_hour`,
> `max_retention_days`, `min_auto_pause_minutes`, `monitoring`, `activity_tracking`,
> `app_url_tracking`, `weekly_limit`, `attendance_alerts`, `integrations`, `ai`, `api`,
> `white_label`. Nothing in this matrix requires a new gating mechanism to be invented.

### What this changes versus what the code seeds today

The code today seeds **Starter $4 / Pro $8** and a **3-seat Free tier**. The three proposed changes
are all one-field edits in the operator plan editor — no code, no migration:

| Item | Seeded today | Proposed | Why |
|---|---|---|---|
| Starter price | $4.00 | $5.00 | Round number; still well under Hubstaff (~$7) and Time Doctor (~$7). |
| Growth (`pro`) price | $8.00 | $9.00 | Keeps a clear ~2× step from Starter; under Hubstaff Team (~$12). |
| Free seat cap | 3 seats | **2 seats** | 3 free seats forever would cannibalise Starter — a large share of our target Pakistani teams *are* 3–5 people. |
| Trial length | not set (`trial_days` = blank) | **14 days on Growth** | Signup already puts every new workspace on a full-featured **Growth/Pro trial**; the length just needs to be entered so we can advertise "14-day free trial" truthfully. |

**Free-tier design note (this is deliberate):** Free keeps screenshot monitoring on, at a slow
6/hour with 14-day retention. That is what makes Free a *real* demo of the product rather than a
crippled stub — and the missing pieces (activity %, apps & URLs, Slack, AI) are exactly what a
paying team needs. Per your locked rule, these appear **greyed with an Upgrade badge, never hidden.**

---

## 3. THE AGENCY TOOLS ADD-ON

One priced bundle, attaching to **any** base plan. This is the whole of "the Agency edition" — there
is no separate product, no separate signup, no separate price list.

| | **Agency tools** |
|---|---|
| **Price — USD / seat / month** *(placeholder)* | **$4** |
| **Price — PKR / seat / month** *(placeholder, Pakistan)* | **Rs 700** |
| Annual (pay 10 months) | $40 / Rs 7,000 per seat / year |
| Attaches to | Starter, Growth, Enterprise (shown locked on Free) |
| Billing | Per seat, on the same invoice as the base plan |
| Free trial of the add-on | 14 days, recommended (matches how Hubstaff sells add-ons) |

**What is inside:**

| Inside the add-on | What it does | Build status |
|---|---|---|
| **Upwork Profiles** | An extra attribution dimension so an agency running several Upwork seller accounts can see time, screenshots and reports per profile. | **Exists in the app today** — but currently switched on by the workspace *type*, not by a plan/add-on. Gating must move to an entitlement (see §7). |
| **Bidding & marketplace work categories** | The Agency-only work categories: *Bidding / proposals*, *Off-platform client work*, *Trial / test task* — on the same editable `work_categories` mechanism every workspace uses. | **Planned** (`STAFFVORA_WORK_VOCABULARY.md` — designed and approved, not built). |
| **Machine Compliance** | Per-PC inventory of browser extensions, installed programs, running processes and VPN/proxy adapters, with a blocklist, flags and a compliance dashboard — the tool that protects Upwork accounts from suspension. | **Planned** — exists in the SA Track codebase; it is a **P0 port item** into Staffvora (`STAFFVORA_PHASE4_PORT_PLAN.md`). |

**Positioning:** Machine Compliance alone is worth the add-on price to an Upwork agency — a single
suspended profile costs more than a year of the add-on for a 10-person team. Nobody else in this
market sells it. This is the strongest differentiated thing we have and it should be priced with
confidence, not apologetically.

**Upgrade experience (the point of the whole design):** a General customer sees the Agency tools
card in Billing → clicks **Add** → the tools unlock on the next page load. No migration, no export,
no new workspace, no data touched.

---

## 4. Suggested future add-ons — IDEAS, NOT COMMITMENTS

The add-on engine is generic, so once it exists these cost almost nothing to add. **None of these
are being promised, priced or built yet** — they are listed so the pricing structure is designed
with room for them.

| Idea | What it would sell | Rough placeholder | Fit with what exists |
|---|---|---|---|
| **Advanced Insights** | Utilisation-vs-target, idle gauge, 24-hour timeline ribbon, unusual-activity highlights | ~$3 / seat / mo | Being built in Phase 4 anyway; mirrors Hubstaff's $3 Insights add-on. |
| **Extended data retention** | Keep screenshots/activity beyond the plan's cap (e.g. 2 years) | ~$2 / seat / mo | Maps to the existing `max_retention_days` switch — cheapest add-on to ship (but see §7: that switch is not enforced yet). |
| **Higher capture rate** | More screenshots per hour than the plan allows | ~$2 / seat / mo | Maps to the existing `max_screenshots_per_hour` switch. |
| **API & webhooks** | Programmatic access for customers who want to pipe data into their own systems | ~$3 / seat / mo, or flat per workspace | Maps to the existing `api` switch — but a customer-facing API does not exist yet (§7). |
| **Client invoicing & payroll** | Invoice *your* clients from tracked time; run payroll | Flat per workspace | The Financials workstream, scheduled post-M5 (D-8). |

**Deliberately NOT proposed: a "silent / invisible tracker" add-on.** Hubstaff sells one. It
directly contradicts the trust positioning we chose ("we measure the work, not the worker",
consent-first design). Recommend we stay out of that market and say so publicly — it is a
differentiator, not a gap.

---

## 5. Regional pricing

The operator portal is being built to support **region rules** (allow / deny / price-by-region) at
signup and checkout. Here is the proposed shape.

| Region | Who | Currency shown | Proposed level |
|---|---|---|---|
| **Region A — Pakistan & South Asia** (PK, IN, BD, LK, NP) | Local teams, local agencies | **PKR first**, USD secondary | ~**35–40% below** the straight USD conversion |
| **Region B — Rest of world** | Everyone else | USD | The USD prices in §2 |
| *Region C — Gulf / MENA (later)* | Agencies with Gulf clients | USD (AED later) | Global pricing; add only if demand appears |

**Why PKR-first for Region A — the rationale:**

1. **A local buyer prices against local salaries, not against Hubstaff.** At the configured rate of
   Rs 280/USD, a $9 seat converts to Rs 2,520 — which reads as expensive next to a junior salary in
   Pakistan, even though it is cheap by global SaaS standards. The proposed **Rs 1,600** is a
   deliberate regional price, *not* a conversion.
2. **The payment rail is the conversion, not the price.** Local buyers convert on JazzCash /
   Easypaisa / bank transfer far more than on a card form. This is a genuine wedge — Hubstaff and
   Time Doctor cannot bill in PKR at all.
3. **It costs us almost nothing.** Region A customers are incremental revenue at near-zero
   marginal cost; discounting them does not discount the USD market, because the price is
   enforced by region rules at checkout.
4. **It is the launch story** (Phase 8): "PKR pricing + local payments" is the single clearest
   reason a Pakistani business picks us over the incumbents.

**Local payment story:** **Alfa Payment Gateway** is the confirmed rail (hosted checkout + IPN),
sandbox already done per D-8. **JazzCash / Easypaisa / bank transfer** are the local methods to
advertise — the marketing site already mentions them but the copy is still flagged
`[REPLACE: confirm methods]`, so you need to confirm which are actually live before launch. Manual
bank transfer already works today (an operator records the payment against the workspace).

**Guardrail to build in:** the region price must key off the **billing country on the invoice**, not
the visitor's IP alone — otherwise anyone with a VPN buys at Pakistani prices. Recommend: IP
suggests the region, billing country decides it.

**What we can do right now, before the region engine exists:** the operator portal already supports
a **per-workspace custom price** and a **per-workspace percentage discount**. That is enough to
hand-price the first Pakistani customers manually while Phase 5 builds the automatic rules.

---

## 6. What the owner must decide

Only the genuinely commercial calls are listed. Everything else is structure, and the structure is
already locked by D-2/D-4/D-8.

| # | Decision | My recommendation | Why |
|---|---|---|---|
| **1** | **The actual price points** — Starter, Growth, Enterprise floor, and the Agency add-on. | **$5 / $9 / from $18 / add-on $4** (PKR: 900 / 1,600 / from 3,200 / 700). | Sits clearly under Hubstaff and Time Doctor on the global list while leaving room to raise later — it is far easier to raise a launch price than to cut one. |
| **2** | **Does the Free tier exist at launch at all?** | **Yes — keep it.** | We are an unknown brand asking companies to install monitoring software on their staff's PCs. Free removes the trust barrier, and monitoring-on-Free makes it a real demo, not a stub. |
| **3** | **Free-tier seat limit.** | **2 seats** (code currently seeds 3). | 3 free seats forever would let a real 3-person Pakistani agency stay free indefinitely — that is our Starter customer. |
| **4** | **Annual discount %.** | **17% (pay 10 months, get 12).** | It is the standard "2 months free" framing, it is already what the marketing site's toggle displays, and it pulls a year of cash forward — which matters far more than the discount costs. |
| **5** | **Regional discount depth for Pakistan / South Asia.** | **~35–40% below the USD conversion.** | Deep enough to be obviously local pricing, shallow enough that a Region-A customer is still clearly profitable. |
| **6** | **Enterprise: publish a "from" price, or pure "Contact us"?** | **Publish "from $18 / seat"**, then negotiate. | A blank Enterprise column makes small buyers assume it is unaffordable and stops big buyers from self-qualifying. An anchor does both jobs. |
| **7** | **Can the Agency add-on attach to the Free plan?** | **No — Starter and above.** Show it locked on Free with an Upgrade badge. | Billing an add-on on a $0 plan complicates invoicing, and Machine Compliance is our strongest asset — it should pull people onto a paid plan, not sit on the free one. |

---

## 7. Honest list — what this matrix names that is NOT built yet

Nothing on this list should appear on the public pricing page or in a sales conversation until it
is ticked off. Listed worst-first.

| # | Item | Reality today | Blocks |
|---|---|---|---|
| **1** | **The add-on engine itself** | There is **no add-on table, model or billing path** anywhere in the codebase — only plans. | The Agency add-on cannot be sold at all until this is built (Phase 5). |
| **2** | **Agency gating is not an entitlement yet** | Agency tools are switched on by `organizations.workspace_type = 'agency'`, a hard field set at signup — not by a plan or add-on. | The core promise "upgrade = flip one switch" is **not possible today**. Moving this to an entitlement is the single most important Phase 5 task. |
| **3** | **Machine Compliance** | **Completely absent from Staffvora.** It exists in the SA Track codebase and is a P0 port item (dashboard, ingest endpoint, blocklist, flags, plus a desktop-agent collector). | The headline reason to buy the Agency add-on. |
| **4** | **Bidding / work categories** | Designed and approved, **not built** — no `work_categories` table, no billable flag. Today's work types are still the old flat Upwork-coded list. | Part 2 of the Agency add-on; also blocks the "zero 'Upwork' words in General" promise. |
| **5** | **API access** | The `api` switch exists and is granted on Pro/Enterprise, but there is **no customer-facing API** — the only API endpoints are the desktop agent's own. | **Do not advertise API access.** Either build it or remove that row before the pricing page goes live. |
| **6** | **SSO & "advanced security"** | The live marketing pricing block already lists **"SSO & advanced security"** under Enterprise. **There is no SSO in the codebase.** | Remove this claim from the marketing site now, or build it. This is the one item currently being advertised that does not exist. |
| **7** | **Retention limits are not enforced** | `max_retention_days` is set per plan, but nothing validates a workspace's actual retention setting against it — a Free workspace could set 365 days. | The retention row in the matrix, and the "Extended retention" add-on idea. |
| **8** | **Annual billing** | Plans are monthly only (`interval` = month); there is no annual price, no proration, no annual invoice. The site's "Annual · save 17%" toggle is **display maths only**. | Every annual price in §2. |
| **9** | **Regional pricing engine** | No region rules, no currency switching at checkout. Only a per-workspace custom price and percentage discount exist. | Everything in §5 beyond hand-pricing individual customers. |
| **10** | **Live payments** | Alfa Payment Gateway is **sandbox only**. Live billing today = an operator manually recording a bank transfer. | Self-serve signup-to-paid. Nobody can actually buy a plan online yet. |
| **11** | **Trial length is blank** | Every signup does get a full-featured 14-day Growth/Pro trial, but the plan's `trial_days` field is empty (it falls back to a hard-coded 14). | Set it in the plan editor before advertising "14-day free trial". |
| **12** | **Priority support / Help centre** | The searchable help centre and the feedback inbox are **port items from SA Track**, not yet in Staffvora. | The support rows on Starter/Growth/Enterprise. |

**Summary of the honesty check:** of the three things inside the Agency add-on, **only Upwork
Profiles exists today** — and even that is gated the wrong way. The base-plan matrix is in far
better shape: **11 of the 13 switches are real and enforced in code right now**; the two that are
not are API access and retention limits.

---

## Appendix — how these map to the real switches in the code

For whoever builds the operator pricing manager. Source: `app/Tenancy/Entitlements.php`.

| Matrix row | Entitlement key | Type | Enforced today? |
|---|---|---|---|
| Seat range | `max_users` | ceiling (blank = unlimited) | ✅ on user creation & invites |
| Screenshot monitoring | `monitoring` | on/off | ✅ route + settings |
| Screenshot rate | `max_screenshots_per_hour` | ceiling | ✅ settings + upload endpoint |
| Screenshot retention | `max_retention_days` | ceiling | ❌ **not enforced** |
| Idle auto-pause | `min_auto_pause_minutes` | floor | ✅ settings |
| Activity tracking | `activity_tracking` | on/off | ✅ route + upload endpoint |
| App & website tracking | `app_url_tracking` | on/off | ✅ route + upload endpoint |
| Weekly hour limits | `weekly_limit` | on/off | ✅ settings |
| Attendance Slack alerts | `attendance_alerts` | on/off | ✅ settings |
| Slack integration | `integrations` | on/off | ✅ routes + settings |
| AI assistant | `ai` | on/off | ✅ routes |
| White-label branding | `white_label` | on/off | ✅ branding controller |
| API access | `api` | on/off | ❌ **no customer API exists** |
| Agency tools | *(none yet)* | — | ❌ uses `workspace_type` instead — must become an entitlement |

The operator plan editor already lets you change, per plan, without any code change: **display name,
price, seat cap, trial days, public/hidden, and every one of the 13 switches above.** It does
**not** yet manage currency, billing interval, add-ons or regions — those are the Phase 5 build.
