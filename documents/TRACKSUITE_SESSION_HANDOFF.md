# Tracksuite — session handoff (for a fresh chat)

> **This document is ONLY about Tracksuite** — the productized, multi-tenant SaaS
> time-tracker at **satimetracker.com**. It is a *different product* from the
> internal **SA Track time tracker** (`timetracker.sparkingasia.com`, repo
> `C:\laragon\www\timetracker`). Do not mix them up. This handoff covers what was
> built in the previous chat so a new Tracksuite-only chat can continue cleanly.

---

## 1. What Tracksuite is + where it lives
- **Product:** a sellable, multi-tenant SaaS fork of the SA Track time tracker (each customer = one `Organization` tenant; isolated by a global scope).
- **Repo:** `C:\laragon\www\tracksuite` · GitHub `jawadislam92/tracksuite` · branch **`main`**.
- **Stack:** Laravel 12 (legacy skeleton, `app/Http/Kernel.php` aliases), Inertia v2 + React 18 (JSX, not TS), Vite 5, Tailwind v3, npm, PHP 8.3 prod.
- **Live customer site / app:** `https://satimetracker.com`
- **Operator (landlord) portal:** `https://platform.satimetracker.com` (separate `platform` auth guard; roles Support < Admin < Owner).
- **Server:** VPS **`2.24.11.90`**, path `/var/www/tracksuite`, SSH key `~/.ssh/tracksuite_vps` (`root@2.24.11.90`). **This is a DIFFERENT host than SA Track** (which is Hostinger cPanel).
- **Local dev:** `http://tracksuite.test` (Laragon) OR `php artisan serve` on a port. The platform host is route-bound (`Route::domain(config('platform.host'))`), so the operator portal isn't reachable on `127.0.0.1` locally — verify operator UI on prod.

### Deploy runbook (NEVER `config:clear` on prod → 500 APP_KEY)
```
ssh -i ~/.ssh/tracksuite_vps -o StrictHostKeyChecking=no root@2.24.11.90 \
  'cd /var/www/tracksuite && git fetch --quiet origin && git reset --hard origin/main \
   && php artisan migrate --force && php artisan config:cache && php artisan route:cache \
   && php artisan view:cache && echo DEPLOY_OK'
```
- Built assets (`public/build`) are **committed** (not gitignored) → run `npm run build` locally before pushing; the server does NOT build.
- After deploy, re-seed/refresh marketing pages by hitting the home route (DefaultSiteContent::ensure runs there).
- Local PowerShell git push prints a stderr "NativeCommandError" wrapper even on success — check the `->` line. Heredoc commit messages break in PowerShell; use a `$msg` variable with `git commit -m $msg`.

---

## 2. What was built in the previous chat (all deployed + verified live)

**The whole marketing site is owner-editable with no code, no developer, no AI** — this
was an explicit goal (owner may lose Claude access due to local AI restrictions).

1. **Marketing site (CMS block-pages)** — `site_pages` (slug + ordered `blocks` JSON) rendered by `Pages/Site/Show.jsx` via `BlockRenderer`. Pages: home, pricing (wired to live Plans), features, how-it-works, solutions + /solutions/agencies + /solutions/bpos, about, contact, security, download, terms, privacy. Authored via `App\Support\DefaultSiteContent::ensure()` (idempotent, runs on home/blog hit). Catch-all route allows one nested level.
2. **Day/night switcher + full dark theme** — `Components/Site/SiteShell.jsx` (shared chrome: palette CSS vars + header + footer + SEO) + `ThemeToggle.jsx` (animated sun/moon, persists to `localStorage` `tracksuite-site-theme`, honours OS `prefers-color-scheme`). Every block tokenised (no hardcoded `bg-white`).
3. **No-code Appearance system** — `App\Support\SitePalettes` (6 presets: **aurora, electric, sunset, emerald, crimson, slate**) + `SiteTheme.palette_preset` / `palette` (custom hex) / `font`. Resolved server-side in `App\Support\SiteContext::brand()` (config → preset → custom overrides). Builder UI: Platform → **Website → Appearance** (preset grid + 10 fonts + per-token fine-tune). **LIVE preset = `electric` (Electric blue)** — the owner's chosen brand. Font drives a Google-Fonts `<link>` + `--site-font`.
4. **Interactive hero + motion** — `HeroParticles.jsx` (canvas constellation bg on the home hero, theme-coloured, respects reduced-motion); scroll-reveal (sections fade up via IntersectionObserver in SiteShell + `.reveal-on` CSS); 3D gradient buttons (`.btn-primary`).
5. **SEO plumbing** — `/sitemap.xml` (pages + blog posts), `public/robots.txt`, per-page `<link rel=canonical>` + robots + OG, schema.org JSON-LD (Organization + WebSite site-wide, FAQPage on pages with an FAQ block, Article on blog posts). Rendered client-side (no SSR yet — Googlebot renders JS; SSR/OG-for-scrapers is a deferred enhancement).
6. **Blog engine** — `BlogPost` model + `blog_posts` table; public `/blog` (paginated) + `/blog/{slug}` (`.site-prose`); no-code editor at Platform → **Blog** (title/slug/cover→S3/body HTML/category/SEO, draft→publish, body sanitised). `blog` flag in `config/features.php` is ON. **6 SEO posts seeded** via `App\Support\BlogSeedContent` (idempotent firstOrCreate by slug; topics: humane tracking, remote teams, attendance, agency billable hours, screenshots ethics, BPOs).
7. **Builder drives the live site (no-code)** — colours (Appearance), nav (`SiteTheme.nav_links` else `config/navigation.php`), footer columns, contact email/phone/address + socials (overlay config). Migrations cleared stale dev-seed values so config is the default until the owner sets one. Per-block **Section background** control (Default/Muted/Soft/Accent) via `BlockRenderer` wrapper + `Editor.jsx` select.
8. **Leads inbox** — contact form stores to `contact_submissions` (was only emailed/logged → leads could be lost). Operator portal → **Leads** (unread flags, reply, delete). `ContactController` also **auto-acknowledges** the lead by email (best-effort until SMTP set).
9. **Operator billing controls (per tenant)** — Platform → tenant page: **Trial** panel (+7/+14/+30 days, end-now, set exact date → `POST /tenants/{org}/trial`), **per-tenant discount %** (`organizations.discount_percent`, `Organization::effectivePriceCents()`), shows effective price. **Plan editor:** `plans.trial_days` (Pro plan's value drives new-signup trial length via `TenantProvisioner`, 14-day fallback). (Existing: suspend/resume, record manual payment, custom price, entitlement overrides.)
10. **Billing frontend (compliance-first)** — customer `Pages/Billing/Index.jsx`: per-plan **Choose plan** → `POST /billing/checkout` (a no-op until `config('billing.gateway')` / env `BILLING_GATEWAY` is set), + a prominent **"we never see or store your card details"** security statement. Checkout is **gateway-hosted by design** — no card fields exist anywhere in our app (PCI-safe). `BillingController::checkout` is the wire-up point for a real gateway.
11. **Header transparency bug fixed** — the sticky header used `bg-[var(--canvas)]/85` which Tailwind can't apply to a `var()` (rendered fully transparent → nav vanished over gradient sections). Fixed with a `.site-header` class (`color-mix` frosted, solid fallback).
12. **Test hygiene** — froze time in `CloseStaleSessionsTest` (was wall-clock flaky). **Suite: 177 passing.**

### Strategy docs created (read these for the roadmap)
- `documents/PLATFORM_IDEAS.md` — ~150 operator-portal feature ideas (billing automation, invoices, dunning, MRR/churn dashboards, customer health, CRM, etc.) with a ⭐ "build first" shortlist.
- `documents/WEBSITE_BUILDER_IDEAS.md` — strategic take + ~100 features for productizing the builder into a no-code website builder ("Wix for Pakistan" wedge), if the tracker doesn't sell.
- `documents/MARKETING_SITE_BUILD_STATE.md` — earlier living build-state doc.

---

## 3. Open / pending — what a new chat could pick up

**Needs the owner's input (blocked on them, not code):**
- Real **pricing** numbers (PKR per plan), **contact** details (WhatsApp/phone/office address), **social proof** (testimonials, customer logos, team photos). Owner can edit all of this no-code in the builder.
- **Blog content** review (their content team).
- **Payment gateway** decision + credentials — see below.
- Owner still needs to **sign up** (`satimetracker.com/register`) and set up the operator portal (rotate the temp Owner password; invite team under Staff).

**Payment integration (frontend done, backend waiting on gateway details):**
- Recommendation given: **Paddle** (merchant-of-record, processes global cards + pays out to Pakistani banks — best for selling internationally from Pakistan) and/or **Safepay / JazzCash / Easypaisa** for local PKR. PayPal does NOT work for receiving in Pakistan; Stripe needs a US entity (Stripe Atlas).
- When the owner has an account: implement a `PaymentProvider` (e.g. `PaddleProvider`) behind the existing abstraction; `BillingController::checkout` redirects to the gateway's hosted checkout; a webhook creates the Payment + extends the Subscription. Set `BILLING_GATEWAY` env.
- Customer-redeemable **discount codes** (Shopify-style) come with the gateway; the per-tenant operator discount % already exists.

**Optional builds (no external dependency):**
- More no-code builder upgrades: **media library** (reuse uploaded images), **rich-text editor** (WYSIWYG instead of HTML box), **more block types** (video/embed, gallery, banner, button row, columns).
- More operator-portal features from `PLATFORM_IDEAS.md`: **automated recurring billing, invoices (PDF), dunning, MRR/churn dashboard, customer health + inactivity alerts, tenant CRM (notes/tags), delete/archive tenant, global search, 2FA + audit log.**
- Other Phase 3 SEO pages (flagged OFF in `config/features.php`): comparison "vs Hubstaff/Time Doctor", integrations directory, glossary, guides.
- SSR / server-rendered OG meta (for social link previews on non-JS scrapers).
- Replace the bundled fallback logo with a neutral Tracksuite asset; desktop-app rebrand (tracked in `documents/SELLABILITY_AUDIT.md`).

**Security reminder:** the temporary operator-portal Owner password from early in the build was exposed in chat — rotate it (Account page or `php artisan platform:owner --random`).

---

## 4. Starter prompt for the new Tracksuite chat
Paste this to begin:

> This chat is **only** for **Tracksuite** (the SaaS at satimetracker.com, repo `C:\laragon\www\tracksuite`, VPS 2.24.11.90, key ~/.ssh/tracksuite_vps). It is NOT the SA Track time tracker. Read `documents/TRACKSUITE_SESSION_HANDOFF.md` for full context on what's already built and what's pending. Deploy via the runbook in that doc (never `config:clear` on prod). [then state your task]
