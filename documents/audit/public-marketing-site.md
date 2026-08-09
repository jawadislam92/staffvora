# Audit — Live public marketing site (https://staffvora.com)

**Scope:** 18 public pages, no login. Tested at **375 / 768 / 1024 / 1440 / 1920 px**.
**Date:** 2026-08-09 · **Mode:** READ-ONLY, nothing was edited.
**Method:** live DOM measurement on staffvora.com (horizontal-scroll test, over-wide element query, tap-target geometry, computed `text-align`, label/`for` association, focus-visible with real `Tab` presses, theme toggle, meta tags), cross-referenced against local source in `resources/js/Components/Site/`.

---

## Headline result

**Responsive layout is genuinely clean.** The objective test — `document.documentElement.scrollWidth <= window.innerWidth` — **passed on all 90 page × width combinations** (18 pages × 5 widths). The over-wide-element query returned **zero** offending elements at every width. The only element that ever extends past the viewport is a decorative `blur-3xl` glow on the hero, and it is clipped by an `overflow-hidden` ancestor so it never creates a scrollbar.

The **copy is unusually good** — careful, specific, and deliberately non-promissory. `/security` explicitly disclaims SOC 2 / ISO 27001 / GDPR, and `PricingTable.jsx:8-13` documents that "SSO & advanced security" and "Integrations & API" were *removed* because they don't exist. I found no typos, no trailing sentences, and no duplicated words. The copy defects below are inconsistencies and one broken sentence, not sloppiness.

The real damage is concentrated in **navigation and forms**: a header dropdown that is dead to both keyboard and touch, a contact form with no accessible labels, and two published pages nothing links to.

**Counts — BROKEN 4 · WRONG 11 · UGLY 13 · NIT 7 (35 findings)**

---

## BROKEN

### B1. Header "Solutions" and "Resources" dropdowns are dead to click and invisible to keyboard
**Page:** every page · **Viewport:** ≥1024px · **File:** `resources/js/Components/Site/SiteHeader.jsx:69-83`

`HeaderItem` renders a `<button type="button">` with **no `onClick` handler**. The dropdown panel is revealed only by `group-hover:visible`. Measured on the live site:

| state | panel `visibility`/`opacity` |
|---|---|
| initial | `hidden` / `0` |
| after `.focus()` on the button | `hidden` / `0` |
| **after `.click()` on the button** | **`hidden` / `0`** |

All five child links measured `visibility: hidden`, which removes them from the tab order entirely.

**Why it is wrong:** two failures in one control.
- **Touch at ≥1024px** (iPad Pro landscape, Surface, any touchscreen laptop): tapping "Solutions" does *nothing*. There is no hover state on touch, and `lg:hidden` means the hamburger is gone at this width. The control is completely dead.
- **Keyboard:** 9 header destinations (Agencies, BPOs, Software Houses, Remote Teams, Freelancers, Blog, Security, Help, Contact-under-Resources) are unreachable from the header. They survive only because the footer duplicates most of them.

**Fix:** make `open` real state on `HeaderItem`. Add `onClick={() => setOpen(o => !o)}`, `aria-expanded={open}`, `aria-haspopup="true"`; drive the panel from `open` **or** `group-hover`/`group-focus-within` rather than hover alone; close on `Escape` and on outside click. Replace `invisible`/`opacity-0` with conditional render (or keep `visibility` but add `group-focus-within:visible`) so the children enter the tab order when the menu is open.

### B2. Contact form — all 5 fields have no accessible label, no `id`, no `name`, no `autocomplete`
**Page:** `/contact` · **All viewports** · **File:** `resources/js/Components/Site/blocks/ContactBlock.jsx:5-13` (`Field`) and `:56-57` (Message textarea)

Measured live on `/contact` — every one of the five fields:

```
{ tag: INPUT,    name: "(none)", id: "(none)", labels: 0, aria-label: "(none)", autocomplete: "(none)" }  × 4
{ tag: TEXTAREA, name: "(none)", id: "(none)", labels: 0, aria-label: "(none)", autocomplete: "(none)" }  × 1
```

And every `<label>`: `for: "(none)"`.

**Why it is wrong:** `<label>` without `htmlFor` and an input without `id` are not associated. A screen-reader user hears five unlabeled edit boxes on the primary conversion form — the only way to contact the company. Clicking the label text does not focus the field. Missing `name` + `autocomplete` also blocks password-manager/browser autofill on mobile, where this form is hardest to type into.

**Fix:** in `Field`, generate an id (`useId()`), set `htmlFor={id}` on the label and `id={id}` on the input; add `name`, `autoComplete` (`name`, `organization`, `email`), `required` on Name/Work email/Message; wire errors with `aria-describedby={errorId}` and `aria-invalid`. Do the same for the Message textarea, which is hand-rolled outside `Field`.

### B3. `/how-it-works` is an orphan — nothing on the site links to it
**Page:** `/how-it-works` · **File:** `config/navigation.php`

The string `how-it-works` does not appear anywhere in `config/navigation.php` — not in `header`, not in `footer`. Confirmed by enumerating every internal `<a href^="/">` on the homepage; the full unique set is:

```
/ /about /blog /contact /download /features /help /login /pricing /privacy
/register /security /solutions/agencies /solutions/bpos /solutions/freelancers
/solutions/remote-teams /solutions/software-houses /terms
```

Neither `/how-it-works` nor `/solutions` is present.

**Why it is wrong:** the page is published, returns 200, is `index,follow`, has a canonical and full SEO meta — and is reachable only by typing the URL. It is a funnel page ("Three steps to a real record of the week") that no visitor will ever see.

**Fix:** add `['label' => 'How it works', 'url' => '/how-it-works', 'page' => 'how-it-works']` to the header array in `config/navigation.php` (natural slot: between Features and Solutions), and/or to the footer **Product** column.

### B4. `/solutions` is an orphan — its configured URL is silently discarded
**Page:** `/solutions` · **Files:** `config/navigation.php:26` + `SiteHeader.jsx:65-68` and `:120-124`

`config/navigation.php:26` explicitly configures the parent URL:

```php
['label' => 'Solutions', 'url' => '/solutions', 'page' => 'solutions', 'children' => [ ... ]],
```

But `HeaderItem` (`SiteHeader.jsx:65-68`) returns early into the dropdown branch whenever `item.children` exists and **never reads `item.url`**. The mobile menu (`:120-124`) does the same — it renders the group label as a plain `<div>`. The footer's "Solutions" column title is an `<h4>`, not a link. Net result: the configured `/solutions` URL is used nowhere.

**Why it is wrong:** an operator edited the nav data as the file's own header comment instructs ("Adding/removing a link = a data edit here, never JSX") and the change had no effect — a silent data loss between config and render.

**Fix:** in `HeaderItem`, when `item.url` is present render the label itself as a link (with the caret still opening the panel); in the mobile branch, render the group heading as a `<Nav>` when `item.url` exists instead of a `<div>`. Alternatively prepend an "Overview" entry to `children`.

---

## WRONG

### W1. Every block page's `<title>` contains "Staffvora" twice
**All block pages** · **Files:** `resources/js/app.jsx:74` + CMS `seo_title` values

`app.jsx:74`: `title: (title) => title ? \`${title} - ${brandName}\` : brandName`

The CMS `seo_title` already ends in "— Staffvora", so the suffix is appended to a string that already has it. Measured live:

| page | `og:title` (correct) | `<title>` (shipped) |
|---|---|---|
| `/` | `Staffvora — time tracking that respects your team` | `Staffvora — time tracking that respects your team - Staffvora` |
| `/features` | `Features — Staffvora` | `Features — Staffvora - Staffvora` |
| `/pricing` | `Pricing — Staffvora` | `Pricing — Staffvora - Staffvora` |
| `/security` | `Security — Staffvora` | `Security — Staffvora - Staffvora` |
| `/blog/…` | `Why we measure…` | `Why we measure… - Staffvora` ✅ correct |

Note the separator is also inconsistent — em dash from the CMS, hyphen from the app.

**Why it is wrong:** this is the string Google shows in results and the browser tab. It reads as a bug to every visitor. The blog is correct because it passes a bare title, which proves the fix.

**Fix:** strip the " — Staffvora" suffix from the block pages' `seo_title` in the CMS/`DefaultSiteContent`, **or** make the `app.jsx:74` callback idempotent: `title.endsWith(brandName) ? title : \`${title} - ${brandName}\``.

### W2. `og:image` is missing on every page while `twitter:card` claims `summary_large_image`
**All pages** · **File:** `resources/js/Components/Site/SiteShell.jsx:185-188`

`<meta name="twitter:card" content="summary_large_image" />` is emitted unconditionally (line 188), but `og:image` is only emitted `{meta.image && ...}` (line 185) and **no page sets `meta.image`** — measured `(MISSING)` on `/`, `/pricing`, `/features`, `/security` and the blog post.

**Why it is wrong:** every link shared to Slack, WhatsApp, LinkedIn or X renders a blank card while explicitly declaring a large-image card. For a product whose entire top-of-funnel is link sharing, this is the highest-leverage cheap fix on the site.

**Fix:** add a default 1200×630 OG image to `SiteShell` (fall back to a site-wide default when `meta.image` is absent), and make `twitter:card` conditional — `summary` when there is no image.

### W3. "BPOs & call centres" vs "BPOs & Call Centers" — both on the same page
**Page:** `/solutions/bpos` (and site-wide footer) · **Files:** page content + `config/navigation.php:28,54`

`/solutions/bpos` body: *"For BPOs & call centres"* (British). The footer on that same page: *"BPOs & Call Centers"* (American). The header nav also says "Call Centers".

The rest of the site is consistently British: *"Categorise time…"*, *"Help centre and request inbox"*, *"notarised"*, *"BPOs and call centres"* on `/solutions`.

**Fix:** pick British to match the body copy and change `config/navigation.php:28` and `:54` to `'BPOs & Call Centres'`.

### W4. Theme is persisted under the **old product name**
**All pages** · **File:** `resources/js/Components/Site/SiteShell.jsx:6`

```js
const STORAGE_KEY = 'tracksuite-site-theme';
```

Confirmed live — `localStorage` contains exactly one key: `tracksuite-site-theme`. "Tracksuite" is the pre-rename name of the product.

**Why it is wrong:** the owner asked for inconsistent product naming. This is shipped, inspectable-in-devtools stale branding.

**Fix:** rename to `staffvora-site-theme`, with a one-time migration read of the old key so existing visitors keep their choice.

### W5. `/download` — a sentence that says the opposite of what is meant
**Page:** `/download`, "Honest status" section

> **"Never ask anyone to disable their security software to install Staffvora."**

The sentence has no subject, so it reads as an instruction **to the reader**. The intent is the promise made on `/security`: *"We will never ask you to disable security software to install it."*

**Correction:** *"We will never ask anyone to disable their security software to install Staffvora."*

### W6. The same "Activity & screenshots" mock renders inside every split block, twice per page
**Pages:** `/`, `/features`, `/how-it-works`, `/solutions`, all 5 solutions pages · **File:** `resources/js/Components/Site/blocks/FeatureSplit.jsx:13-31`

When `data.image_url` is absent, `FeatureSplit` falls back to a **hardcoded** visual: a 6-cell screenshot grid captioned "Activity & screenshots / Visible to the team" plus "No keylogging, ever / Screenshots are opt-in and employee-visible". No page sets `image_url` (see U4 — the site has zero images), so **every** split block renders this identical card.

Measured consequence — the card appears **twice on a single page** on `/`, `/features` and `/how-it-works`, and it contradicts the block it sits in:

| page | block heading | hardcoded card shown |
|---|---|---|
| `/features` | "See where the time actually went" (INSIGHT) | screenshots/keylogging card — unrelated |
| `/` | "Marketplace work, handled" (FOR AGENCIES) | screenshots/keylogging card — unrelated |
| `/solutions/remote-teams` | "Everyone sees the same day" (TIMEZONES) | screenshots/keylogging card — unrelated |
| `/solutions/bpos` | "The whole floor, at a glance" | screenshots/keylogging card — unrelated |

**Why it is wrong:** a block about timezones or marketplace work illustrated with a keylogging disclaimer reads as a template that was never filled in — and reads doubly so when the identical card appears twice as the visitor scrolls one page.

**Fix:** vary the placeholder by `data.eyebrow`/block subject, or render no visual at all when `image_url` is absent and let the text block go full width.

### W7. `/about` "Two editions" contradicts the homepage "not a separate product"
**Pages:** `/about` vs `/` and `/features`

`/about` stat: **"Two editions — GENERAL AND AGENCY, ONE PRODUCT"**.
`/` (FOR AGENCIES block): *"Sold as a per-seat add-on, not a separate product"*.
`/pricing` and `/features` both sell it as *"Agency toolkit — an optional add-on"*.

"Editions" is used nowhere else on the site and implies two SKUs, which the pricing page contradicts.

**Fix:** change the `/about` stat to match the commercial model, e.g. "One product — WITH AN OPTIONAL AGENCY TOOLKIT".

### W8. Homepage stat "14 days OF GROWTH" is ambiguous
**Page:** `/`, Stats band

> **14 days** / *OF GROWTH ON EVERY NEW WORKSPACE*

Reads as business growth, not the **Growth plan**. Elsewhere the site says "14-day Growth trial".

**Correction:** *"OF THE GROWTH PLAN ON EVERY NEW WORKSPACE"*.

### W9. Pricing toggles expose no state to assistive tech
**Page:** `/pricing` · **File:** `PricingTable.jsx:134-153`

The Monthly/Annual and USD/PKR buttons carry **no `aria-pressed`**, no `role="group"`, no group label. Measured live: `aria-pressed: (none)` on all four. Active state is conveyed *only* by a gradient background.

**Why it is wrong:** a screen-reader user cannot tell which currency or billing period the prices on screen represent — and the two are indistinguishable ($5 vs $4.17).

**Fix:** `aria-pressed={annual === val}` / `aria-pressed={cur === c}`, wrap each pair in `role="group"` with `aria-label="Billing period"` / `aria-label="Currency"`.

### W10. FAQ accordions expose no expanded state
**Pages:** `/`, `/pricing`, `/how-it-works` · **File:** `blocks/Faq.jsx:7`

The toggle `<button>` has no `aria-expanded` and no `aria-controls`; the collapsed panel is hidden with `grid-rows-[0fr]` + `opacity-0`, which does **not** remove it from the accessibility tree. A screen reader announces every answer as present while sighted users see them collapsed, and never announces open/closed.

**Fix:** add `aria-expanded={open}` and `aria-controls={panelId}` to the button, `id={panelId}` and `role="region"` on the panel, and add `invisible` (or `hidden`) to the collapsed state so it leaves the a11y tree.

### W11. Night mode never sets `color-scheme`, so native UI stays light
**All pages, night mode** · **File:** `SiteShell.jsx:168-173`

The theme is applied as CSS custom properties on a `<div class="site-root" data-site-theme="dark">`. Nothing sets `color-scheme: dark` and no `<meta name="color-scheme">` is emitted. Measured: `<html>` carries `data-theme="light"` (from the customer-app theme layer) and **never changes** when the site toggle is clicked, even though the aria-label correctly flips between "Switch to day mode" and "Switch to night mode".

**Why it is wrong:** in night mode the scrollbar, the contact form's native input chrome, autofill backgrounds and text-selection colours all render in light scheme against the dark canvas.

**Fix:** set `style={{ colorScheme: mode }}` on `.site-root` (or `document.documentElement.style.colorScheme = mode` in the toggle effect).

---

## UGLY

### U1. 17 footer links are 18 px tall on a phone
**All pages** · **375px** · **File:** `SiteFooter.jsx:52-53`

Measured on `/pricing` at 375px — 23 sub-40px controls, **17 of them in the footer**:

| element | size |
|---|---|
| Features / Pricing / Download / Security / Agencies / BPOs & Call Centers / Software Houses / Remote Teams / Freelancers / About / Contact / Blog / Help / Terms / Privacy | **18 px tall** |
| footer logo | 21 px |
| `hello@staffvora.com` | 20 px |

18 px is well under the 40 px minimum and under Apple's 44 pt / Google's 48 dp guidance. The `<li>` spacing is `space-y-3` (12 px), so adjacent links are ~30 px apart centre-to-centre — mis-taps are likely.

**Fix:** on `FLink`, add `inline-block py-2` (or `min-h-[40px] flex items-center`) so each link's hit box reaches 40 px; reduce `space-y-3` to `space-y-1` to keep the column height unchanged.

### U2. Pricing toggles are 32 px tall — the owner explicitly asked about thumb usability
**Page:** `/pricing` · **375px** · **File:** `PricingTable.jsx:137-151`

Measured: Monthly 84×**32**, "Annual · save 17%" 146×**32**, USD 59×**32**, PKR 57×**32**. `px-4 py-1.5` inside a `p-1` pill.

They *work* (I clicked all four and verified prices change: `$0|$4.17|$7.50|$18` annual → `$0|$5|$9|$18` monthly → `Rs 0|Rs 900|Rs 1,600|Rs 3,200` PKR), but at 32 px they are below the touch minimum on the page where the buying decision happens.

**Fix:** `py-2.5` on the toggle buttons (→ ~40 px).

### U3. Mobile menu "Sign in" is a 20 px tall tap target
**All pages** · **375px, menu open** · **File:** `SiteHeader.jsx:131`

Measured with the menu open: "Sign in" is 335×**20**, while its sibling "Start free trial" is 335×40 and the nav links are 36–40 px. The `<Link>` has `px-1` and no vertical padding.

**Fix:** add `py-2.5` to match the adjacent CTA.

### U4. The entire marketing site contains **zero images**
**All 18 pages** · measured `document.images.length === 0` on every page at every width

No product screenshots, no team photos on `/about`, no author images on the blog, no OG images (see W2). Every "visual" is a CSS mock — including the one hardcoded card from W6, repeated twice per page.

**Why it matters:** this is a monitoring product asking buyers to trust what the dashboard looks like, and it never shows the dashboard. Combined with W6 it is the single biggest reason the site reads as unfinished.

**Fix:** real product screenshots in the `FeatureSplit`/`Hero` slots (both already accept `image_url`), and a default OG image.

### U5. Every internal marketing link is a full page reload
**All pages** · **Files:** `SiteHeader.jsx:6,59-61` · `SiteFooter.jsx:9,14-16`

`isAppRoute` returns true only for `/login`, `/register`, `/dashboard`; everything else renders a plain `<a>`. So in an Inertia SPA, clicking Features → Pricing → Security triggers three full document loads, each re-downloading the JS bundle and re-requesting Google Fonts. Measured navigation duration on `/pricing`: **691 ms**, `type: "navigate"`.

(This is also *why* B-adjacent mobile-menu state resets cleanly — verified the menu closes after navigation, `aria-expanded: false`, header back to 69 px.)

**Fix:** use Inertia `<Link>` for all same-origin site paths; keep the plain `<a>` only for external/`mailto:`/`tel:` URLs. `safeHref` already tells you which are relative.

### U6. `ImageBlock` hardcodes `zinc` greys — token violation, and the file's own sibling documents why
**File:** `resources/js/Components/Site/blocks/ImageBlock.jsx:6-7`

```jsx
<img … className="w-full rounded-2xl border border-zinc-200 shadow-lg" />
<figcaption className="mt-3 text-center text-sm text-zinc-500">
```

`RichText.jsx:21-25` carries an explicit warning that this exact mistake was already fixed once there: *"Colours come from the site's CSS variables, NOT fixed zinc greys: the public site has a day/night switcher… hardcoded zinc-700 body text rendered as near-invisible dark grey on the dark canvas."* `ImageBlock` was missed.

**Fix:** `border-[var(--line)]` and `text-[var(--subtle)]`. Not currently visible because no page uses `ImageBlock`, but it will render wrong the first time an operator adds an image in the Site Builder in night mode.

### U7. Three components hardcode `alt=""` on content images
**Files:** `FeatureSplit.jsx:12`, `TeamGrid.jsx:13`, `ImageBlock.jsx:6`

- `FeatureSplit.jsx:12` — `<img src={data.image_url} alt="" />`, no way for the operator to supply alt text.
- `TeamGrid.jsx:13` — `<img src={m.avatar_url} alt="" />` on a team member's photo; should be the member's name.
- `ImageBlock.jsx:6` — `alt={data.alt || ''}` is acceptable (operator-supplied) but has no builder-side prompt.

**Fix:** add an `alt` field to the `FeatureSplit` block schema; use `alt={m.name}` in `TeamGrid`.

### U8. Long prose centred where it should be left-aligned
Measured computed `text-align: center` on runs >150 chars:

| page | element | chars | file |
|---|---|---|---|
| `/contact` | hero subhead "Questions about the product, a walkthrough for your team, help getting set up, or activating a paid plan — write to hello@staffvora.com or use the form. We usually reply within one business day." | **194** | `Hero.jsx:105` (`center` variant) |
| `/pricing` | footnote "Prices are per seat. Yearly billing costs ten months instead of twelve. We activate paid plans…" | **179** | `PricingTable.jsx:201` |
| `/how-it-works` | Step 2 body, centred inside `max-w-xs` | 162 | `Steps.jsx:16` |
| `/` | hero subhead | 157 | `Hero.jsx:105` |

Centred hero subheads are a defensible convention, but at 194 characters on `/contact` and 179 in the pricing footnote every line starts at a different x-position, which is measurably harder to read. The `Steps` case is worst: 162 characters centred inside a `max-w-xs` (320 px) column produces a very ragged narrow block.

**Fix:** cap centred subheads at ~120 chars or switch these three to `text-left` within their `max-w-*` container. For `Steps.jsx:16`, left-align the body text under the centred number badge.

### U9. `RichText` has no table styling and no overflow wrapper
**File:** `blocks/RichText.jsx:38-47`

The child-element utility list covers `h2 h3 p strong a ul ol li em hr` — **no `table`**. `app/Support/HtmlSanitizer.php:95` allows `'table' => []`, so an operator can author one in the Site Builder.

**Why it matters:** the owner asked specifically about tables. Today no page contains one (my over-wide sweep found zero table elements), so nothing is broken *yet* — but the first table an operator adds to `/terms`, `/privacy` or a blog post will render unstyled and, on a 375 px phone, will overflow with no horizontal scroll container.

**Fix:** add `[&_table]:w-full [&_table]:my-6 [&_th]:text-left [&_th]:font-semibold [&_td]:py-2 [&_td]:border-b [&_td]:border-[var(--line)]` and wrap the block in `overflow-x-auto`. Note `text-left` on `th` — the default `<th>` is centred, which is exactly the mis-centring the owner called out.

### U10. No branded focus indicator anywhere on the site
**All pages** · **Files:** `resources/css/app.css` (only `.form-input:focus` at :307)

Verified honestly with **real `Tab` presses** (not scripted `.focus()`, which gives a false negative): focus **is** visible — the UA default ring applies, e.g. on the Menu button `outline: auto 1px rgb(229,151,0)`, `:focus-visible` matching `true`.

So this is not broken. But the only element with a deliberate focus style in the whole site chrome is `ThemeToggle.jsx:16` (`focus-visible:ring-2 focus-visible:ring-[var(--a)]`). Everything else — nav links, footer links, `btn-primary` CTAs on gradient backgrounds, FAQ accordions, pricing toggles — depends entirely on the browser default, whose contrast is not controlled against the violet gradient or the night canvas.

**Fix:** one global rule — `.site-root :focus-visible { outline: 2px solid var(--a); outline-offset: 2px; }` plus a light-coloured variant for controls sitting on the accent gradient.

### U11. Contact form errors use raw `rose-500`
**File:** `ContactBlock.jsx:10,58` — `className="mt-1 text-xs text-rose-500"`

Raw Tailwind palette instead of the danger status token.
**Fix:** `text-danger`.

### U12. `CtaBand` uses `bg-white`
**File:** `blocks/CtaBand.jsx:16-17` — `className="… rounded-full bg-white px-8 py-4 … text-[var(--deep)] …"`

A white pill on the gradient band. Defensible as a reversed CTA, but it is a literal `bg-white` in JSX, which the design contract disallows, and it will not respond if the band's gradient is ever re-themed.
**Fix:** `bg-[var(--surface)]` or a dedicated `--on-accent` token.

### U13. Raw `black/5` rings
**Files:** `Hero.jsx:24`, `FeatureSplit.jsx:14` — `ring-1 ring-black/5`

Fixed black at 5% is nearly invisible on the night canvas where a light hairline is needed.
**Fix:** `ring-[var(--line)]`.

---

## NIT

### N1. Blog voice and typography diverge from the marketing pages
**Page:** `/blog/measure-the-work-not-the-worker`

The marketing pages avoid contractions almost entirely ("it is", "do not", "we are", "does not"). The blog post is full of them: *"you don't need"*, *"doesn't mean fuzzy"*, *"aren't hiding"*, *"That's the whole idea"*, *"what's measured"*.

It also **mixes quote styles within one paragraph** — curly apostrophes (`don’t`, `team’s`) alongside straight double quotes (`"works"`, `"absent"`). `/solutions/bpos` has the same straight-double-quote issue: `"who was in?"`.

**Fix:** pick one register; convert straight double quotes to curly (`"…"`) for typographic consistency.

### N2. "sort you out" is too colloquial for the download page
**Page:** `/download`, macOS card

> *"if you do not see it in your workspace yet, email us and we will sort you out."*

**Correction:** *"…email us and we will get it to you."*

### N3. Footer "Resources" column doesn't match the header "Resources" dropdown
Header Resources: Blog, Security, Help, (Guides — flagged off). Footer Resources: **Help only** — Blog sits under Company and Security under Product.
**Fix:** align the two groupings.

### N4. Footer social icons are 36 px
**File:** `SiteFooter.jsx:42` — `h-9 w-9`. Just under the 40 px touch minimum.
**Fix:** `h-10 w-10`.

### N5. Google Fonts sits slightly awkwardly against the site's own privacy claim
**File:** `SiteShell.jsx:151,176-178` — fonts hot-linked from `fonts.googleapis.com` / `fonts.gstatic.com`.

`/security` states: *"There are no advertising pixels, session recorders or third-party analytics scripts on this site."* Strictly true — Google Fonts is none of those. But it is a third-party request that discloses every visitor's IP to Google, and it is the *only* third-party request the site makes. For a page whose whole argument is privacy candour, self-hosting closes the gap and also removes a render-blocking external stylesheet on every one of those full page reloads (U5).

**Fix:** self-host the Hanken Grotesk woff2 files.

### N6. `/solutions/freelancers` heading is blunt
> **"What you get for nothing"**

Reads as dismissive of the free plan's value.
**Correction:** *"What you get on the free plan"*.

### N7. Blog makes a product claim not stated anywhere else
**Page:** `/blog/measure-the-work-not-the-worker`

> *"Admins are exempt from monitoring by default."*

This appears nowhere in `/features`, `/security` or `/pricing`. Given `PricingTable.jsx:8-13` documents a deliberate policy of only publishing shipped capabilities, this one should be verified against the product and either propagated to `/features` or removed.

---

## What passed

Worth recording, because these were tested and are genuinely fine:

- **Horizontal scroll:** clean on 18 pages × 5 widths (90/90). Zero over-wide elements.
- **Mobile menu:** opens, exposes all 13 destinations plus Sign in / Start free trial, `aria-expanded` toggles correctly, icon swaps hamburger↔X, and closes on navigation. Verified by clicking through to `/pricing`.
- **Pricing toggles:** all four work; prices recompute correctly (annual = 10/12 of monthly; Enterprise correctly exempted from the annual discount via the `p.contact` guard at `PricingTable.jsx:162,164`).
- **Plan-name chain is consistent:** Free → Starter → Growth → Enterprise, and the "Everything in X" bullets chain correctly against the live plan names.
- **SEO fundamentals:** exactly one `<h1>` per page, `lang="en"`, correct canonical, `index,follow`, JSON-LD present on every page.
- **Copy accuracy:** no typos, no trailing sentences, no duplicated words. The retention figures on `/how-it-works` (7 / 90 days / 1 year / unlimited) match `PricingTable.jsx:21-26` exactly.
- **Security posture of the chrome:** `safeHref` in `SiteHeader.jsx:44-53` decodes entities before scheme-testing and is shared with the footer; `RichText` refuses non-string HTML; JSON-LD is escaped for the script context. These are well-reasoned and well-commented.
- **Focus visibility:** present via UA defaults (see U10 for the caveat).

---

## Suggested fix order

1. **B1** — dead dropdown (touch + keyboard). One component, biggest functional win.
2. **W2** — add an OG image. One file, largest marketing impact.
3. **W1** — the doubled `<title>`. One line in `app.jsx`.
4. **B2** — contact form labels. The only contact path on the site.
5. **B3 / B4** — link `/how-it-works` and `/solutions`. A config edit plus a small `HeaderItem` change.
6. **W6 + U4** — real screenshots; kills the duplicated placeholder card at the same time.
7. **U1 / U2 / U3** — tap targets (three small padding changes).
8. **W5, W3, W7, W8** — the copy corrections.
