# SA Track → a sellable product: feature inventory + go-to-market plan

*Prepared 2026-06-15. Purpose: capture everything SA Track already does, and lay
out exactly what it takes to turn it from "our internal tool" into a global,
industry-level SaaS product we can sell, market, and customize per customer.*

> **Status (owner decision, 2026-06-15): documentation only for now.** The
> go/no-go and the open decisions (product name, pricing, cloud stack) are
> **deferred until after the team has tested the product internally at Sparking
> Asia**. No SaaS build starts yet — we keep this document the living plan and
> revisit once internal testing is done.

---

## 1. The opportunity in one paragraph

We have already built — and run in production — a genuinely competitive
time-tracking, attendance, and team-monitoring platform with a premium desktop
app, AI summaries, and Slack automation. The work-tracking market (Hubstaff,
Time Doctor, Time Champ, Insightful, Scrin) is large, global, and full of
products that look dated. Our edge is a **modern, premium, customizable**
experience plus **AI** baked in. The gap between what we have and a sellable
product is mostly **multi-tenancy, self-serve billing, white-label
customization, and cloud scale** — not features. Most of the hard product work
is done.

---

## 2. What we already have (feature inventory)

### A. Desktop time tracker (Electron — Windows live, Mac pending)
- Cross-platform tracker with **auto-update + one-command rollback** (versioned
  feed, `allowDowngrade`).
- **Screenshot capture** on a configurable cadence; **multi-monitor** capture
  stitched into one image.
- **Activity tracking** (keyboard/mouse %), **app & URL** tracking.
- **Offline-first**: local SQLite queue, syncs on reconnect with original
  timestamps — nothing lost when the network drops or during deploys.
- Client / work-type / task selection, one-click client switching, recent-client
  quick-start, deep links (web → desktop start).
- **Idle detection + auto-pause**, idle notifications.
- **Premium themed UI** — 3 switchable themes (Cinematic / Light / Midnight), a
  bespoke progress-ring clock, prominent identity, custom scrollbars.
- **Single-device enforcement** (no double-counting) with a per-user override.

### B. Attendance / time clock
- Manual clock in/out + breaks (web and desktop), **shift times + grace**,
  night-shift-aware attendance-date logic.
- **Monthly attendance grid** (Present/Absent/Leave/Holiday/WFH/Late etc.),
  manual marking with an **audit log**.
- **Forgotten-clock-out safety net**: auto clock-out (last activity or a cap)
  with plain-language reasons; **interactive Slack "still working?" check**;
  auto clock-in when the tracker starts.
- **In-office vs tracked-work** coverage.

### C. Monitoring
- Screenshot **timeline** with lightbox (prev/next, flag, delete), per-session
  detail, activity bands, app/URL rollups.
- Deletion integrity (cascading removal, time deduction, no ghost residue).

### D. Reporting & insight
- Per-day **Timeline** per user with **AI day summaries**.
- **Work-hours reports** (rich filters, CSV export, client/work-type breakdown).
- **Team performance** (in-office vs tracked, manual hours).
- **Weekly + daily Slack digests**.

### E. Team & client management
- Users with **roles** (super-admin / admin / manager / member) and **granular
  permissions**, **bulk edit**.
- **Clients** (CRUD, work types, tags, Upwork profiles, CSV import/export).
- Designations, shift management.

### F. AI (Claude)
- Day summaries, activity digests, an **AI assistant chat**, a floating widget,
  editable preset questions.

### G. Integrations & infra
- **Slack** (bot token, multi-channel, DMs, interactive buttons, password-reset
  codes), **S3** screenshot storage, **Anthropic** AI.
- Branding (logo upload), display settings (timezone, 12/24h, week start,
  currency), a **Developer console** (.env management, health, scheduler,
  desktop release + rollback), scheduled jobs (digests, pruning, sweeps).

**Bottom line:** the feature surface is already at parity with — and in places
ahead of (AI, premium themes, humane attendance) — the established players.

---

## 3. What separates "internal tool" from "sellable product"

These are the real build items. Roughly in priority order.

### Tier 1 — foundational (without these we can't sell)
1. **Multi-tenancy (workspaces/organizations).** Today everything is one company
   in one database. To sell, each customer needs an isolated **organization**:
   every table scoped by `organization_id`, every query tenant-scoped, users
   belong to an org, data never crosses orgs. This is the single biggest change
   and everything else depends on it.
2. **Self-serve signup + onboarding.** Create an org, invite teammates, download
   the tracker, first-run wizard. No manual setup by us.
3. **Billing & subscriptions (membership).** Stripe: plans, **per-seat**
   pricing, free trial, upgrade/downgrade, invoices, dunning. Feature-gate by
   plan. This is the "membership / start selling" piece.

### Tier 2 — the "global, premium, customizable" product
4. **White-label & customization** (owner's emphasis: "match the style of
   companies around the world"). Per-org **branding** (logo, accent color,
   the 3 themes we already built as a starting palette), **custom subdomain**
   (`company.satrack.app`) and optionally **custom domain**, branded emails,
   branded desktop app name/icon for enterprise. Theming is largely done — we
   extend it to per-org.
5. **Internationalization.** Per-org **timezone** (have), **currency** (have),
   **date/number formats**, and **multi-language UI** (new — the big i18n lift
   for "global"). Localized Slack/digest text.
6. **Customer admin console.** The org owner self-manages users, billing, roles,
   integrations, data export — without us. (We already have most of the admin
   surfaces; they get scoped per-org + a billing area.)

### Tier 3 — trust, scale, growth
7. **Security & compliance.** Strict tenant isolation, audit logging (partial
   today), data export & delete (GDPR), a security/privacy page, a path to
   SOC 2. Screenshot data is sensitive — privacy controls (blur, per-org
   retention, consent copy) matter for selling to bigger companies.
8. **Cloud scale.** Hostinger shared hosting won't scale to many tenants —
   move to proper cloud (managed Postgres/MySQL, object storage already on S3,
   a queue worker, autoscaling). CI/CD, staging, observability.
9. **Public surface.** Marketing site, pricing page, docs, changelog, support
   (help center + ticketing), status page.
10. **Ecosystem.** A customer-facing **REST API + webhooks**; more integrations
    (Jira/Trello/Asana, payroll/QuickBooks/Xero, Google/Microsoft SSO); a
    **mobile companion** (clock in/out, view reports) later.

---

## 4. Suggested packaging (so we can gate features and price)

| Plan | Who | Headline gating |
|---|---|---|
| **Free** | tiny teams / trial | up to ~3 users, basic tracking + attendance, 7-day history |
| **Starter** | small teams | screenshots + activity, reports, Slack, longer history |
| **Pro** | growing teams | AI summaries/assistant, monitoring, custom themes, integrations, API |
| **Enterprise** | larger orgs | white-label + custom domain, SSO, audit/compliance, priority support, custom retention |

Per-seat monthly, with annual discount and a free trial. The AI features and
white-label are natural premium differentiators.

---

## 5. Why we win (differentiation)

- **Premium, modern, themeable UX** — most competitors look like 2015. Our
  desktop app (3 themes, bespoke clock) and dark web UI already feel premium.
- **AI built in** — day summaries, an assistant, digests. Few competitors have
  this; it's our marketing hook.
- **Humane monitoring** — the "still working?" Slack check, honest auto
  clock-out with reasons, in-office-vs-tracked transparency. Sells to companies
  that find pure surveillance distasteful (a growing concern).
- **Customizable / white-label** — lets agencies and enterprises make it *their*
  product.
- **Offline-solid + reliable** — the tracker never loses data; rollback-safe
  releases.

---

## 6. Phased roadmap (build order)

**Phase 0 — decide & design (1–2 wks):** pick the cloud stack; design the
org/tenant data model; choose Stripe; lock the plan/pricing matrix.

**Phase 1 — multi-tenant core (the big one):** add `organization_id`
everywhere, tenant scoping, org-aware auth, data isolation tests. *Nothing ships
to customers until this is solid.*

**Phase 2 — self-serve + billing:** signup/onboarding, Stripe subscriptions,
seat management, plan gating, trials.

**Phase 3 — white-label + i18n:** per-org branding/themes, subdomains, multi-
language, localized formats. (Owner priority — the "global, matches every
company" feel.)

**Phase 4 — trust & scale:** compliance (export/delete, audit, privacy),
observability, staging/CI, performance for many tenants.

**Phase 5 — growth:** marketing site, docs, public API + webhooks, more
integrations, SSO, then mobile.

---

## 7. Reality check / sequencing notes

- **The internal app stays as-is** and keeps serving Sparking Asia; the product
  is best built as a **multi-tenant evolution of this same codebase** (Laravel +
  Inertia/React + the Electron tracker), not a rewrite — we reuse ~all the
  feature work above.
- **Multi-tenancy is the gate.** It's invasive (touches every model/query) and
  must be done carefully with isolation tests — but it's well-understood Laravel
  work (global scopes / a tenancy package).
- **The desktop tracker** already points at a server URL per install, so it maps
  cleanly to per-org servers/subdomains.
- Biggest *new* skill areas vs. what we've built: Stripe billing, a tenancy
  layer, i18n, and cloud infra/observability.

---

## 8. Immediate, low-cost next steps (when ready)

1. Lock the **plan/pricing matrix** and the **product name/brand** for the
   public product.
2. Spike the **tenancy model** on a branch (org table + scoping a couple of
   modules) to size Phase 1 accurately.
3. Stand up a **Stripe** test account + a throwaway **marketing landing page**
   to start collecting interest while Phase 1 is built.

*This document is the running source of truth for the product vision; update it
as decisions are made.*
