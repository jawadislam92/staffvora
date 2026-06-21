# Marketing Website — Build State & Open Questions

> Living status doc for the multi-page marketing site build. Source of truth for
> **what** = `SITE_BUILD_SPEC.md` (in the repo root / Downloads). This file tracks
> **progress, decisions, the brand, and the open questions** the owner needs to answer.
> Last updated: 2026-06-21.

---

## 1. Status at a glance

| Phase | What | State |
|---|---|---|
| Brand | Choose + extract brand tokens | ✅ **Done** (Direction A "Measured System", green) |
| P1 — Scaffolding backbone | `config/brand.php`, `config/features.php`, `config/navigation.php`, `site_pages` extensibility migration | ✅ **Done** (migrated + linted, committed locally, NOT deployed) |
| P1 — Brand foundation | Hanken Grotesk + green CSS vars + ring logo + favicon; data-driven header/footer; full block registry + stubs; flag-guarded collection routes | ⏳ **Next** |
| P1 — Home (green) | Re-skin Home to the brand + deploy/verify | ⏳ Pending |
| P2 — Conversion pages | Features, Pricing, How-it-works, Solutions, About, Contact, Security, Download, Legal | ⏳ Pending |
| P3 — Collections + SEO | blog/integrations/guides/tools/etc. behind flags + sitemap/JSON-LD/OG/SSR | ⏳ Pending |

**Paused here** awaiting owner answers to §6 (per the owner's request to ask first).

---

## 2. Brand tokens (LOCKED — from the Claude Design handoff)

Direction A — **"The Measured System"**. Idea: *"Most time trackers watch people. We measure outcomes."* Pillars: Calm · Honest · Respectful · Precise.

**Colors** (single source = `config/brand.php` → `colors`):
- Sage `#4F9E78` — actions/links/focus (`--a`) · Sage deep `#3C8763` (hover)
- Evergreen `#1C3D2F` — brand / dark sections · Mint-bright `#86C9A4` (`--hl`, on dark)
- Ink `#15201B` (text) · Slate `#4A5650` (body/muted) · Subtle `#8A938C`
- Mint `#CFE6D7` · Paper `#F4F1E9` · Canvas `#FBFAF6` (page bg) · Surface `#FFFFFF` · Border `#E4E2D8`

**Type:** Hanken Grotesk (one family, weights 400–800; Google Fonts). Display 700 ‑.035em; Body 400 17/1.6 in Slate.

**Logo:** open progress-ring mark (a measured arc on an open ring) + "Tracksuite" wordmark. SVG (48×48):
```html
<svg viewBox="0 0 48 48" fill="none">
  <circle cx="24" cy="24" r="16" stroke="#BFD7C7" stroke-width="3.5"/>
  <path d="M24 8 A16 16 0 0 1 39.03 29.47" stroke="#4F9E78" stroke-width="3.5" stroke-linecap="round"/>
  <circle cx="39.03" cy="29.47" r="3.4" fill="#4F9E78"/>
</svg>
```
Variants: reversed (mark `#86C9A4` on Evergreen), monotone `#1C3D2F`, symbol-only (white mark on Sage — app icon/favicon). Min size: lockup 120px / mark 20px.

**Voice:** "Say it plainly, kindly, once." Say: *"See where the day went — no screenshots, no spying." / "Your team's time, on your team's terms."* Avoid surveillance language ("monitor employees", "catch idle time", "full surveillance").

**Reconciliation:** `SITE_BUILD_SPEC.md` §3 lists orange `#f97316` / Inter — those were **placeholders written before the brand was chosen**. The handoff green brand + Hanken Grotesk supersede them (the spec calls these "placeholders wired to swap in one place" — this is that swap).

---

## 3. Architecture (per spec — non-negotiable)

- Marketing pages = **CMS block-pages** (`site_pages` row: slug + ordered `blocks` JSON) rendered by `Pages/Site/Show.jsx` via `BlockRenderer`, served at `/{slug}` (catch-all, last, lowercase). Authored via **seeders**, stay builder-editable.
- New section types = **block components** in `Components/Site/blocks/`, registered in `BlockRenderer.jsx` + `blockSchemas.js`.
- **Collection** types (blog, integrations, tools, guides, glossary, compare, customers, industries) = explicit routes + data source + shared template, declared **before** the catch-all, each behind a **feature flag**.
- Marketing uses the **fixed light palette** (now green); **no block hardcodes a hex**.
- Stack: Laravel 12 / Inertia v2 / React 18 JSX / Vite 5 / Tailwind v3 / npm / Node 22. No SSR yet (client-rendered; meta server-injected).

---

## 4. What's built so far (committed locally, not deployed)

- `config/brand.php` — identity + green palette + font (the one swap point).
- `config/features.php` — all section flags (off).
- `config/navigation.php` — header + footer as data (flag/page-aware).
- `2026_06_21_160000_add_extensibility_to_site_pages.php` — `show_in_nav`, `canonical_url`, `robots`; `status` now also `planned`.

## 5. Immediate next steps (no owner input needed — can start anytime)

1. `App\Support\SiteNavigation::resolve()` (drops flagged-off / unpublished items) → pass to `Show.jsx`; rewrite `SiteHeader`/`SiteFooter` to render from it (dropdowns + mobile drawer).
2. Brand foundation: load Hanken Grotesk in `app.blade.php`; emit the green palette as CSS vars on the marketing root; ring-logo React component (replaces the `T` box) + generate favicon from the mark; seed `SiteTheme` defaults to the green values.
3. Register full block library + stubs (PricingTable, ContactBlock, SecurityHighlights, TeamGrid now; Integrations/Tools/etc. as no-op stubs).
4. Reserve flag-guarded collection routes in `routes/web.php`.
5. Re-skin **Home** to the green brand (seeder) → build, deploy, verify.

---

## 6. ⭐ OPEN QUESTIONS — owner input needed (answer when back)

These let the launch pages ship with **real** content instead of `[REPLACE]` placeholders. Anything left blank, I'll fill with tasteful placeholders + a `[REPLACE]` marker (and **never fabricate** testimonials, stats, or legal text).

**A. Name & company**
1. Is **"Tracksuite"** the final public name, or still a working title? (It's the logo wordmark + appears everywhere — easy to swap via `config/brand.php`, but confirm.)
2. Registered legal company name for the footer + legal pages? (I've placeheld "Sparking Asia (Pvt.) Ltd.")

**B. Contact / conversion (footer + Contact page; WhatsApp is a prominent CTA)**
3. Support/contact **email**? **WhatsApp** number? **Phone**? Full **Rawalpindi office address**? **Demo-booking link** (Calendly)?
4. **Social URLs** you have live: LinkedIn / Facebook / Instagram / X / YouTube?

**C. Pricing page (the big converter)**
5. Should the marketing Pricing pull from your **existing app plans** (Free / Starter / Pro, already in the DB) — recommended, one source of truth — or use **separate marketing prices**?
6. The actual **price points** (PKR **and** USD) per tier, what's included in each, and the **trial length** (currently 14-day Pro trial).
7. **Payment methods** to advertise: bank transfer, JazzCash, Easypaisa, card?

**D. Positioning**
8. Your top **2–3 differentiators vs Hubstaff** to lead with. (My draft from the product: *"employees see their own data," "no screenshots/keylogging unless you choose it," "privacy controls," "local PKR billing + support."* — confirm / edit / add.)
9. Primary **audience(s)** to feature first? (Spec defaults to building **Agencies** + **BPOs/Call centers** live; the rest reserved.)

**E. Proof & people (omit if none — won't fake)**
10. Any real **testimonials** (name/company/quote), **client logos**, or **outcome stats** to use? If not, I'll omit those blocks for now.
11. **About/Team:** real people + photos to feature, or keep it company/mission-focused for v1?

**F. Download page**
12. Which OS downloads to show (Windows / macOS / Linux / Android / iOS / Chrome)? Link to the **existing desktop downloads** or mark "coming soon"? (Note: the desktop installer is still SA Track-branded — see SELLABILITY_AUDIT.)

**G. Defaults I'll use unless you object**
- **SEO rendering:** ship v1 **client-rendered** (meta is server-injected; sharing + basic indexing work); add **SSR** later as a deliberate infra step (spec §8 / Phase 4).
- **Legal:** scaffold standard Terms/Privacy sections with a "have a lawyer review before launch" note — I won't write binding legal text.
- **Currency:** show **PKR + USD** toggle on pricing.

---

## 7. Notes
- Remove legacy `public/images/sparking-asia-logo.png` once the real Tracksuite logo asset lands (currently using the neutral `default-logo.svg` placeholder; the ring mark replaces it).
- The no-code **builder** still edits page content + theme; nav structure moves to `config/navigation.php` (more appropriate for a flag-driven multi-page site).
