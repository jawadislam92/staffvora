# Audit — Customer app (rest): users, clients, profiles, compliance, billing, settings, teams, profile, feedback, help, onboarding, downloads + auth screens

**Auditor pass:** read-only. No application file was modified.
**Build audited:** local `php artisan serve` on port 8303, same code as production, built assets `app-BGZmEguC.css` / `app-C-LdL97Z.js`.
**Accounts:** `owner@big-org.test` (120 users, dense data), `user1@big-org.test` (member), logged-out for auth screens.
**Widths:** 375, 768, 1024, 1280, 1366, 1440.
**Themes:** light (default), dark, black.

---

## Environment caveats (read before reproducing)

1. **All local audit workflows share one cookie jar.** Cookies are scoped by host, not port, so every concurrent workflow on `localhost:830X` overwrites the same session. My session was silently swapped to another org mid-run several times. Re-authenticate immediately before any session-sensitive measurement.
2. **The headless browser pane does not advance animation frames.** `CSSTransition` objects sit at `playState:"running", currentTime:0` forever, so `getComputedStyle()` returns the *pre-change* value for any property under `transition`. This produces convincing false positives for colour and focus-ring checks. Reliable method: change the theme **server-side** (`PUT /preferences/theme`) and **reload**, then only measure elements where `el.getAnimations().length === 0`.

### False positives I chased down and discarded (do not re-report)

| Claim | Why it is wrong |
|---|---|
| "`text-muted` never re-themes; nav is 2.37:1 in light" | Stuck-transition artifact. A cloned node with identical classes resolved correctly (`#475569` light / `#a3a3a3` black). `.text-muted{color:var(--text-muted)}` works. |
| "Login submit button and Remember-me checkbox have no accessible name" | `read_page`'s tree was abbreviated. `cb.labels` → `["Remember me"]`; submit `innerText` → `"Log in"`. Both fine. |
| "Focus rings are invisible" | Same transition artifact. `--tw-ring-shadow: 0 0 0 4px #2563EB` and `--tw-ring-offset-shadow` are correctly set on `:focus-visible`. |
| "Icon-only row actions are unlabelled" | They are labelled well — `aria-label="Delete Acme Analytics"`, `"Archive Acme Commerce"`, etc. |
| "Clients numeric columns are left-aligned and not tabular" | The `<td>` is `start`-aligned but the value renders in a `font-mono tabular-nums` chip (ClientsList.jsx:820). Fine. |
| "Teams page shows fabricated data (Engineering/Design/…)" | `DEV_SAMPLE` is gated behind the `preview` prop, which is only set by `/_teams`-style routes wrapped in `app()->environment('local')` (routes/web.php:55). Not reachable in production. |

---

## BROKEN

### B1. Header overflows the viewport → whole page scrolls horizontally at 1024, 1280 and 1366 px, on every authenticated page
**Page/URL:** every page behind auth (`/settings`, `/clients`, `/users`, …)
**Viewport:** 1024, 1280, 1366 (clean at ≤768 and ≥1440)
**Element:** `<header>` → `AppShell.jsx:291` container, `:316` primary `<nav>`, `:377` actions cluster

Measured on `/settings`, viewport 1280×800:
```
document.documentElement.scrollWidth = 1386   window.innerWidth = 1280   → hscroll: true
header children: brand 183px | nav 593px | flex-1 spacer 0px | actions 546px  → right edge 1386px
```
Identical `scrollWidth: 1386` at 1024 and 1366.

**Why it is wrong.** The primary nav is revealed at `lg:` (1024px) via `ml-2 hidden items-center gap-0.5 lg:flex` (`AppShell.jsx:316`), but brand + nav + actions need ~1386px. Both the nav and the actions cluster are `shrink-0`, so nothing can compress and the header pushes the whole document wider than the viewport. `AppShell.jsx:265` sets `const INLINE_MAX = 6` — a **fixed** item count, not a measured fit — so the "More" overflow menu never actually relieves the pressure. This covers 1024, 1280 and 1366, the three most common laptop widths; every page in the app gets a horizontal scrollbar and the right-hand actions sit off-screen.

**Fix.** Make the header incapable of exceeding 100% width, and make overflow measurement-driven:
- Add `min-w-0 overflow-hidden` to the `<nav>` at `AppShell.jsx:316` so it can shrink instead of pushing.
- Replace the fixed `INLINE_MAX = 6` (`:265`) with a `ResizeObserver` that measures available width and moves the remainder into the existing "More" menu.
- As an interim single-line mitigation, move the nav reveal from `lg:flex` to `min-[1400px]:flex` so it only appears when it genuinely fits.

### B2. `/settings` renders 120 switches with no accessible name
**Page/URL:** `/settings` (also `/settings/work-categories`)
**Viewport:** all
**Element:** `Settings/Index.jsx:986` → `<Toggle checked={enabled} onChange={setEnabled} disabled={isExempt} />`

```
document.querySelectorAll('[role=switch]').length      → 120
…of which have no accessible name                     → 120
sample: {id:":r0:", labelText:"", aria-label:null, aria-checked:"false"}
```

**Why it is wrong.** `Toggle` (`Components/ui/Form.jsx:131-174`) renders `<label htmlFor={fieldId}>` wrapping `<button id={fieldId} role="switch">`, and only emits text when the optional `label` prop is passed. At `Settings/Index.jsx:986` no `label` is passed — the user's name sits in a **sibling** `<span>` on line 987, outside the label's accessible-name computation. A screen-reader user hears "switch, off" 120 times with no idea which person each row controls, on the single most settings-dense page in the product.

**Fix.** Pass the name into the control and drop the sibling span:
`<Toggle label={user.name} checked={enabled} onChange={setEnabled} disabled={isExempt} />`
Additionally, give `Toggle` a safety net in `Form.jsx`: when `label` is null, require/forward an `aria-label` (e.g. `aria-label={rest['aria-label']}`) and warn in dev if neither is present.

### B3. Password reset is Slack-only — there is no email path
**Page/URL:** `/forgot-password` (logged out)
**Viewport:** all
**Element:** heading "Reset password"; sole submit button "Send code to Slack"

Full page copy: *"Enter your account email and we will send a 6-digit code to your Slack."* Controls are only: **Send code to Slack**, "Back to sign in", "I already have a code", plus the note *"No Slack access? Ask an admin — they can reset your password from the Users page."*

**Why it is wrong.** This is a public multi-tenant SaaS. A customer whose workspace has no Slack connection — or any user not in that Slack — has **no self-service way to recover their account**; the only fallback is asking an admin, and an admin who is locked out has none at all. The page is titled "Forgot Password" but never offers the industry-standard email reset. This is an account-lockout path on a live product.

**Fix.** Add email as the default reset channel (Laravel's `Password::sendResetLink` is already the framework default) and keep Slack as an optional accelerator. Until transactional email is actually live, the page must state plainly that email reset is unavailable rather than presenting Slack as the whole story.

### B4. Deleting a designation is destructive with no confirmation
**Page/URL:** `/users` (designations manager)
**Viewport:** all
**Element:** `Pages/UsersList.jsx:403-409`

```js
const removeDesignation = (designation) => {
    if (preview) return;
    router.delete(route('users.designations.destroy', designation.id), { … });
};
```

**Why it is wrong.** Its immediate sibling `removeShift` (`:394-401`) *does* confirm: `if (!window.confirm(\`Remove the "${shift.name}" shift? …\`)) return;`. `removeDesignation` fires the DELETE on the first click with no prompt and no undo — an irreversible action one stray click away, and inconsistent with the adjacent control.

**Fix.** Mirror the shift flow: confirm before deleting, naming the designation and stating what happens to users currently assigned to it.

---

## WRONG

### W1. Validation errors are shown but never announced or programmatically associated
**Page/URL:** all forms; verified on `/users/create`
**Viewport:** all
**Element:** `Components/ui/Form.jsx:42-46` (shared `Field`) and `Components/Users/UserForm.jsx:388-397` (local `Field`)

Submitted `/users/create` with a duplicate email; the server error rendered correctly on screen, but:
```
aria-live / role="alert" regions on page → 0
elements with aria-invalid="true"        → 0
error <p>/<span> linked via aria-describedby → none
```

**Why it is wrong.** A screen-reader or voice user gets no notification that submission failed and no way to find which of 47 fields is at fault. The error text is visually adjacent but semantically orphaned.

**Fix.** In both `Field` implementations: give the error node an `id`, set `aria-describedby={errorId}` and `aria-invalid="true"` on the control when `error` is truthy, and wrap the error in `role="alert"` (or render a single `aria-live="assertive"` summary at the top of the form and move focus to the first invalid field on failure).

### W2. Required fields are not visually marked on the two biggest user forms
**Page/URL:** `/users/create`, `/users/{id}/edit`
**Viewport:** all
**Element:** `Components/Users/UserForm.jsx:388-397`

The local `Field` renders `label`, `children`, `hint`, `error` — and no required indicator. The design-system `Field` at `Components/ui/Form.jsx:38` renders `{required && <span className="text-danger ml-0.5">*</span>}`. "Full name", "Email address" and "Password" carry the HTML `required` attribute but display no `*`, so the only signal is a native browser bubble after a failed submit.

**Why it is wrong.** Users cannot tell which of ~47 controls they must fill before submitting, and the page contradicts the required-field convention used everywhere else in the app.

**Fix.** Delete the local `Field` (see U6) and use the shared one with `required`; or add the same `*` span plus `aria-required` to `UserForm.jsx:388-397`.

### W3. White-on-orange primary action fails contrast in all three themes
**Page/URL:** every authenticated page (top bar)
**Viewport:** all
**Element:** "Clock in" / "Time" button — `color: rgb(255,255,255)` on `background: rgb(249,115,22)` (`--accent` = `#f97316`)

Measured with animations settled: **2.80:1** in light, dark and black. WCAG AA needs 4.5:1 at 12–13px.

**Why it is wrong.** This is the app's most-used control and its label is the least legible text in the header. `#f97316` (orange-500) is simply too light to carry white text.

**Fix.** Darken the accent used behind white text to ~`#c2410c` (orange-700, ≈4.8:1 with white), or keep the orange fill and switch the label to a dark token. Fix at the token level in `resources/css/app.css` (`--accent` for light `:52` and black `:86`) so every `bg-accent + text-white` pairing is corrected at once.

### W4. `--text-subtle` fails contrast in all three themes
**Page/URL:** everywhere (hints, metadata, "Karachi · 12h", "6/hr", search placeholder)
**Viewport:** all
**Element:** `.text-subtle`

| Theme | Colour | On | Ratio | Needs |
|---|---|---|---|---|
| light | `#94a3b8` | `#ffffff` surface | **2.56:1** | 4.5 |
| light | `#94a3b8` | `#f1f5f9` surface-2 | **2.34:1** | 4.5 |
| black | `#6b6b6b` | `#0a0a0a` app | **3.72:1** | 4.5 |
| black | `#6b6b6b` | `#161616` surface-2 | **3.40:1** | 4.5 |
| dark | `#6b7793` | `#0e1628` surface | **4.02:1** | 4.5 |

**Why it is wrong.** `text-subtle` carries genuine information (timezone, screenshot rate, field hints) at 12px, and it is unreadable against the surfaces it is actually used on — worst in the default light theme.

**Fix.** Darken/lighten the token in `resources/css/app.css`: light `--text-subtle: #94a3b8` → `#64748b` (4.76:1 on white); black `#6b6b6b` → `#8a8a8a`; dark `#6b7793` → `#7d8aa6`. Re-validate against `--surface-2`, not just `--bg`, since that is the failing case.

### W5. Design-token violations — raw palette colours in JSX
**Viewport:** all

| File:line | Offending classes |
|---|---|
| `Pages/ClientCreate.jsx:149, 184, 273` | `focus:border-purple-500 focus:ring-purple-200 placeholder-slate-400` |
| `Pages/ClientCreate.jsx:299` | `text-slate-300` |
| `Pages/ClientEdit.jsx:155, 190, 273` | `focus:border-purple-500 focus:ring-purple-200 placeholder-slate-400` |
| `Components/Users/UserForm.jsx:98, 318, 341, 394` | `text-rose-600` |

**Why it is wrong.** `purple-*` and `rose-*` are **not** remapped by the theming layer — `tailwind.config.js:59-63` only rebinds `slate`, `gray` and `orange` to per-theme CSS vars. These literals therefore stay fixed across light/dark/black, so the client forms show a purple focus ring in an orange-accented workspace and validation errors ignore the `--danger` token entirely.

**Fix.** `purple-500`/`purple-200` → `focus:border-accent focus:ring-accent/30`; `placeholder-slate-400` → `placeholder:text-subtle`; `text-slate-300` → `text-subtle`; `text-rose-600` → `text-danger`.

### W6. Header brand link has no accessible name below 640px
**Page/URL:** every authenticated page
**Viewport:** 375 (fine at ≥640)
**Element:** `Layouts/AppShell.jsx:303-313`

```jsx
<Link href={route("dashboard")} className="flex shrink-0 items-center gap-2.5 …">
  <span …><ApplicationLogo size="7" /></span>
  <span className="hidden text-[17px] … sm:block">{brandName}</span>
</Link>
```

`hidden … sm:block` removes the only text from the accessible tree below `sm`, leaving `<a href="/dashboard">` with an empty name. This was the one unnamed control present on **every** page in my 375px sweep.

**Fix.** Add `aria-label={brandName}` to the `Link`, or swap `hidden sm:block` for `sr-only sm:not-sr-only sm:block` so the name survives.

### W7. Pagination previous/next links are unlabelled
**Page/URL:** `/clients` (same pattern in `EmployeeAttendance.jsx`)
**Viewport:** all
**Element:** `Pages/ClientsList.jsx:897-903` + `:927`

```js
const label = isPrev ? (<ChevronLeft className="h-4 w-4" />)
            : isNext ? (<ChevronRight className="h-4 w-4" />)
            : (<PageLabel label={link.label} />);
```
Verified in the DOM: `<a href="/clients?page=2">` containing only an SVG — no `aria-label`, no `title`, no text.

**Fix.** `aria-label="Previous page"` / `"Next page"` on the `<Link>` at `:927`, and `aria-hidden="true"` on the chevron SVGs.

### W8. Search and filter inputs with no label
**Viewport:** all — placeholder is the only cue, and it disappears on typing

| Page | Control |
|---|---|
| `/clients` | text input, placeholder "Search clients by name or tag." |
| `/compliance` | text input "Tool or extension name…" + **two** unlabelled `<select>` filters |
| `/help` | text input "Search help…" |
| `/settings/branding` | text input, placeholder "#2563eb" (accent hex) |

**Fix.** Add a visually-hidden `<label>` bound by `id`/`htmlFor`, or `aria-label` on each. The two bare `<select>`s on `/compliance` are the worst case — a screen reader announces neither what they filter nor their current value.

---

## UGLY

### U1. Hover states that do nothing
`Components/Users/UserForm.jsx:379`, `Pages/ClientCreate.jsx:430`, `Pages/ClientEdit.jsx:393` all read `bg-accent … hover:bg-accent` — the hover class re-applies the base colour, so the primary submit button on all three forms gives **no hover feedback at all**.
**Fix.** `hover:bg-accent/90` (or a defined `--accent-hover`).

### U2. `/settings` renders every user in the workspace, unpaginated and unsearchable
`Pages/Settings/Index.jsx:969` — `{users.map((user) => …)}` over the full roster. On the 120-user org, measured at 375px:
```
page height 8,295px | 1,322 DOM nodes | 278 KB HTML | 120 switches | 28 selects | search inputs: 0
```
**Why it matters.** The project's standing requirement is to survive 500 orgs and very large companies. There is no filter, no pagination and no virtualisation, so a 1,000-person workspace renders a ~70,000px page. Finding one person means scrolling past everyone.
**Fix.** Add a name filter and paginate (or virtualise) the "Individual settings" list; default to showing only users who already have an override.

### U3. The member sees three disabled buttons and no reason why
`/billing` as `user1@big-org.test`: "Talk to us" and two "Choose plan" buttons render with `disabled: true`, `title: null`, `aria-disabled: null`. Gating itself is **correct** — I confirmed the server refuses (`POST /billing/addons` → 403, `PUT /billing/details` → 403) and `PlanAddonsTab.jsx:194` sets `disabled={!canManage}`. But the add-on section explains itself (`PlanAddonsTab.jsx:105`) while the plan cards do not.
**Fix.** Mirror the add-on treatment: an inline note such as "Only workspace admins can change the plan", plus `title` on the disabled buttons so the reason is discoverable on hover.

### U4. Destructive actions are split between native `confirm()` and styled modals
Native `window.confirm()`: `Teams/Index.jsx:226`, `UpworkProfiles/Index.jsx:289`, `UsersList.jsx:347, 362, 396`.
Styled modal: `ClientsList.jsx:232, 461`, `Compliance/Index.jsx:995`.
**Why it matters.** The native dialog is unthemed, unstyled, ignores light/dark/black, cannot be branded, and on mobile renders as browser chrome that looks like a phishing prompt. Two different confirmation languages for the same class of action.
**Fix.** Route all destructive confirms through the existing `Modal` used by `ClientsList`/`Compliance`.

### U5. Tab bars overflow at 375px with no scroll affordance
`/billing` tablist is 406px wide in a 343px container (4th tab "Billing details" starts at x=331 and ends at 418, off-screen); `/compliance` is 378px with "By extension" clipped. Both parents **are** `overflow-x-auto`, so the tabs are reachable by swiping and the page itself does not scroll — this is not broken, but there is no fade, shadow or partial-tab peek, so a phone user has no cue that more tabs exist.
`Components/ui/Tabs.jsx:55-61` — the component's own root is `inline-flex` with no overflow handling, leaving each caller to remember the wrapper.
**Fix.** Move `overflow-x-auto` plus an edge fade-mask into `Tabs` itself so every consumer gets the affordance.

### U6. A second `Field` implementation shadows the design system
`Components/Users/UserForm.jsx:388-397` defines a local `Field` that duplicates `Components/ui/Form.jsx:20-49` while dropping the required marker (W2) and using a raw palette colour (W5). Two divergent implementations of the same primitive is exactly how W2 and W5 happened.
**Fix.** Delete the local `Field`; import from `@/Components/ui`.

---

## NIT

- **N1.** `DEV_SAMPLE` fixture blocks ship inside the production JS bundle in ~30 `Pages/*` files even though every `preview` route is wrapped in `app()->environment('local')` (`routes/web.php:55-95`). Dead weight in the shipped bundle, not a correctness bug. Strip via a build-time flag if bundle size matters.
- **N2.** `Components/ui/Form.jsx:166` — `bg-white` on the Toggle knob. It reads acceptably on a filled accent, but it is the one `bg-white` in shared UI and is pure `#fff` in the black theme, which is hotter than that theme's `--text` (`#fafafa`).
- **N3.** `routes/web.php:303-304` — `GET /billing` and `POST /billing/checkout` carry no permission middleware while every sibling mutation requires `permission:billing.manage`. The ungated view is deliberate and documented (an expired workspace must reach it to pay); `checkout` is currently a no-op stub with no gateway configured, so this is not exploitable today. Add `permission:billing.manage` to `billing.checkout` before a real gateway is wired.
- **N4.** Title-case inconsistency across auth screens: "Forgot Password - Staffvora" vs "Create your account - Staffvora" and "Log in - Staffvora".
- **N5.** `Pages/Teams/Index.jsx:151` — inline fallback `style={{ backgroundColor: team.color || '#64748b' }}`. Team colours are legitimately data, but the *fallback* should be a token (`var(--text-subtle)`), not a literal.
- **N6.** No auth form sets the HTML `required` attribute (`/login`, `/register`, `/forgot-password`) — all validation is server-round-trip only. `/users/create` does set it, so the app is inconsistent with itself.

---

## Clean results worth recording

- **No page-level horizontal scroll at 375 or 768** on any of the 16 pages swept. Wide tables are correctly wrapped in `overflow-x-auto` containers, and `/users` switches from table to cards below `sm`.
- **No 500s anywhere.** As a member, every restricted page returns a clean, styled `403`: `/users`, `/users/create`, `/clients`, `/clients/create`, `/upwork-profiles`, `/compliance`, `/settings`, `/settings/branding`, `/settings/work-categories`, `/teams`. `/onboarding` correctly redirects to `/dashboard`. No empty shells.
- **Member nav is correctly gated** — Users/Clients/Profiles/Settings links are absent, not dead.
- **`/users/create` labelling is good**: all 47 controls are wrapped in `<label>`, `<select>`s and checkboxes included.
- **Auth screens are well-built**: `/login`, `/register`, `/forgot-password` all use explicit `for`/`id` label association and correct `autocomplete` (`username`, `new-password`, `current-password`, `organization`).
- **Icon-only row actions are properly labelled** — `aria-label="Delete Acme Analytics"`, `"Archive Acme Commerce"`, `"Restore …"`.
- **Focus rings are correctly implemented** app-wide via `focus-visible:ring-2 ring-accent ring-offset-2 ring-offset-app`.
- **`Tabs` keyboard support is correct ARIA** — roving `tabIndex`, arrow-key navigation with wraparound (`Components/ui/Tabs.jsx:35-52`).
- **Table headers default to `text-left`**; numeric columns that matter use `text-right tabular-nums` (e.g. work-categories entry counts). The owner's "centered when it shouldn't be" complaint does **not** reproduce in this area — the only `text-center` uses are a bulk-selection banner (`UsersList.jsx:576`, `ClientsList.jsx:675`) and empty-state text, all legitimately centered.
- **No images without `alt`** on any page swept.

---

## Counts

**BROKEN 4 · WRONG 8 · UGLY 6 · NIT 6** (24 findings)
