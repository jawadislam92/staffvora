# Staffvora — Plans, Pricing & Entitlements (FOR OWNER REVIEW)

> **Status: PROPOSAL. Nothing here is final.** All prices are **PLACEHOLDERS** for review.
> Supersedes the earlier draft in `STAFFVORA_PRICING_MATRIX.md`.
> Owner instruction (2026-07-23): four editable plans · full backend control · access driven by
> **feature entitlements, never by plan name** · Machine Compliance movable between plans ·
> monthly **and** yearly prices · an audit record of who changed a company's access and when.

---

## PART 1 — What the backend already does (the honest audit)

### ✅ Already built, and genuinely good
The foundation is stronger than expected. **We extend it — we do not rebuild it.**

| Capability | Evidence |
|---|---|
| A real **default-deny** entitlement engine | `app/Tenancy/Entitlements.php` — 13 keys, three kinds: `flag` (on/off), `max` (ceiling), `min` (floor). No plan ⇒ nothing granted. |
| Plan → feature values stored as **data**, not code | `plans.entitlements` JSON column |
| **Per-company overrides, both grant AND revoke** | `organizations.entitlement_overrides` JSON; a stored `false` revokes a feature the plan grants; a lower number lowers a cap |
| **One** access-decision path — no duplicated logic | everything funnels through `Organization::can()` / `entitlement()`; HTTP gate `EnsureEntitlement`, UI locks via the shared `entitlements` prop |
| Limits actually **enforced** (not decoration) | seats (user create + onboarding), screenshots/hour (server-side on upload, 429), retention (pruner), auto-pause (settings save) |
| Operator can already edit plan name, price, trial, visibility, and **every** feature value | `PlatformController::plans()/updatePlan()` |
| Operator can already **switch a company's plan** + set a custom price + discount | `PlatformController::updateTenant()` — owner req #4 ✅ |
| The operator UI renders **off the server's registry**, so it grows automatically | `Pages/Platform/Plans.jsx`, `Show.jsx` |

**Meaning: owner requirements #4, #5, #6 are largely already satisfied**, and the "don't duplicate access-control logic" instruction is already honoured by the core engine.

### ⚠️ Half-works
- **Add/remove a *feature*** — you can toggle any of the **13 existing** features per plan and per company, but adding a **14th** feature needs a code edit. The feature *catalogue* is a PHP constant.
- **"Unlimited"** can't be expressed on a per-company override (blank = inherit), so you can't give one customer unlimited seats without moving them to another plan.
- **A real bug:** blanking a *floor*-type field (e.g. minimum auto-pause) writes `0`, which is the **most permissive** value — so clearing a field silently *grants* instead of restricting.
- **Plans:** only **rename** works. `sort` exists as a column but nothing writes it.
- **Expired subscriptions still resolve full plan features** (a separate gate catches it, but the entitlement layer alone isn't the whole answer).

### ❌ Completely missing
| Missing | Owner req |
|---|---|
| **Yearly price** — only one monthly `price_cents`; the site's "save 17%" is display arithmetic. The `interval` column exists but is dead code. | #8 |
| **Create / archive / reorder plans** | #7 |
| **Custom & legacy** plan states (only public/hidden exists) | #9 |
| **Add-ons entirely** — no table, model, or billing path. Machine Compliance cannot be an add-on today. | #10 |
| **Audit trail of operator actions — nothing at all.** Plan changes, feature grants, suspensions and trials are all written with no record of who or when. | #11 |
| **Storage limit** — you named storage explicitly; there is no storage entitlement or usage accounting. | #2 |
| A `machine_compliance` feature key | #10 |

### 🚨 The structural problem (and the security one)

**1. The edition is NOT an entitlement — it's a signup field.**
`organizations.workspace_type` ("general"/"agency") decides whether the Upwork tools appear, via a **second, parallel gate** (`EnsureProfilesAreEnabled`) that duplicates the entitlement middleware, plus a **second client-side source of truth** (`workspace.uses_profiles`) read by the nav and 5 pages.
**Worse: the operator cannot change it at all** — only the customer's own signup wizard sets it. So today you *cannot* move a company between editions, and "upgrade General → Agency in one click" is impossible.
This is precisely the pattern you banned — and Machine Compliance would inherit it if we did nothing.

**2. Plan *names* drive behaviour in several places:** the whole plan catalogue is hardcoded in PHP; feature defaults are a `match` on the plan key; **every new signup is hardwired to the literal key `'pro'`** (rename or archive that plan and signups silently fall to whatever sorts first); and the marketing "Contact us" button keys off `key === 'enterprise'`.

**3. Security — impersonation is unlogged.** A support-level operator can sign in as any customer's super-admin and **nothing anywhere records it.** Given this is employee-monitoring data, that needs an audit record.

---

## PART 2 — The proposed four plans (⚠️ PLACEHOLDER PRICES)

Positioning: **per-seat**, priced under Hubstaff/Time Doctor (~$7–10/seat) with a PKR-first local price. Yearly = **pay for 10 months** (≈17% off).

| | **Free** | **Starter** | **Growth** ⭐ | **Enterprise** |
|---|---|---|---|---|
| **Who it's for** | Trying it out | Small teams who want proof of work | Growing companies who want insight | Large / regulated orgs |
| **USD / seat / month** | $0 | **$5** | **$9** | **from $18** (custom) |
| **USD / seat / year** | $0 | **$50** | **$90** | custom |
| **PKR / seat / month** | Rs 0 | **Rs 900** | **Rs 1,600** | from **Rs 3,200** |
| **Seats** | 2 | up to 10 | up to 50 | unlimited |
| **Data retention** | 7 days | 90 days | 365 days | unlimited |
| Time tracking, attendance, shifts | ✅ | ✅ | ✅ | ✅ |
| Work categories + billable | ✅ | ✅ | ✅ | ✅ |
| Timesheets, reports, CSV export | ✅ | ✅ | ✅ | ✅ |
| Screenshots & monitoring | — | ✅ | ✅ | ✅ |
| Activity tracking | — | ✅ | ✅ | ✅ |
| App & URL tracking | — | — | ✅ | ✅ |
| Slack digests & alerts | — | ✅ | ✅ | ✅ |
| Team performance insights | — | — | ✅ | ✅ |
| AI day summaries | — | — | ✅ | ✅ |
| White-label branding | — | — | ✅ | ✅ |
| Full audit history | — | — | — | ✅ |
| Priority onboarding & support | — | — | — | ✅ |

**Deliberately NOT sold:** SSO/SAML and a customer API — **neither exists**. (Both were being advertised on the live site; I removed them.)

### The Agency toolkit — an ADD-ON, not a plan
**$4 / seat / month · Rs 700 · $40 / seat / year — attaches to Starter, Growth or Enterprise.**
Contains: Upwork profiles · marketplace work categories (Bidding, Outside Upwork) · **Machine Compliance**.

> **Per your instruction, none of this is welded to a plan.** Each item is a feature key. Machine
> Compliance can be moved onto any plan, sold in the add-on, or switched on for one company —
> all from the backend, with no code change.

---

## PART 3 — Recommended backend build (no duplicated access logic)

The rule: **one engine, one decision path.** Everything below either extends the existing entitlement engine or feeds it. Nothing introduces a second way to decide access.

### 3.1 Make the feature catalogue editable (req #1, #3)
Move the registry from a PHP constant into a **`features` table** (key, label, help, kind flag/max/min, group, default, sort, is_active) seeded from today's 13 keys, with `Entitlements` reading it (cached). *Result: you add a feature from the panel; every plan editor, override editor and UI lock picks it up automatically.*

### 3.2 Plan lifecycle (req #7, #8, #9)
Add to `plans`: `price_cents_yearly`, `visibility` (public/hidden/custom/legacy), `archived_at`, and **write** the existing `sort`. Add operator endpoints for **create / rename / archive / reorder / duplicate**. Stop re-seeding over operator edits.

### 3.3 Add-ons (req #10)
`addons` (key, name, monthly + yearly price, entitlements JSON, visibility) + `organization_addon` pivot. An add-on simply contributes entitlements — resolution order becomes:
**per-company override → add-on → plan → default-deny.** One path, one place.

### 3.4 Kill the edition leak (the important one)
Introduce feature keys `upwork_profiles` and `machine_compliance`. Delete the parallel `EnsureProfilesAreEnabled` middleware and the duplicate `workspace.uses_profiles` prop; the nav and pages read entitlements like everything else. `workspace_type` survives **only** as a signup hint that chooses which starter categories/plan to suggest — never as an access decision. *Result: General → Agency becomes "enable the add-on", exactly as you wanted.*

### 3.5 Audit trail (req #11) + the impersonation gap
New `platform_audit_logs` (actor, action, target org, before/after JSON, reason, IP, timestamp) — **landlord-side, deliberately NOT the tenant-scoped `tracking_audit_logs`**, so customers can never read operator activity. Record every plan change, feature grant/revoke, suspend/resume, trial change, price/discount change **and every impersonation**. Surfaced as a history tab on the tenant page.

### 3.6 Correctness fixes found on the way
Fix the floor-field bug (blank must mean "inherit", never `0`); allow "unlimited" on per-company numeric overrides; add optional **expiry + reason** on a grant (so a temporary trial of a feature expires by itself); add `max_storage_gb` with real usage accounting; remove the hardcoded `'pro'` signup plan (mark a plan as *the* default trial plan instead) and the `key === 'enterprise'` marketing check.

### 3.7 Guardrail
A test asserting **every feature key is enforced somewhere** — so a key can never be sold but silently ungated (today `api` is exactly that).

---

## PART 4 — What I need from you

1. **Plan names** — Free / Starter / Growth / Enterprise, or your own?
2. **The placeholder prices** — adjust any number; they are only starting points.
3. **Free plan** — keep it (recommended: yes, it lowers the trust barrier for monitoring software) at **2 seats**?
4. **Yearly discount** — 17% (pay 10 months) recommended.
5. **Agency add-on price** — $4/seat/mo suggested; and confirm it should **not** attach to Free.
6. **Enterprise** — publish "from $18" (recommended) or pure "Contact us"?

*Everything in Part 3 I will build regardless of the pricing answers — the structure is what makes the prices editable in the first place.*
