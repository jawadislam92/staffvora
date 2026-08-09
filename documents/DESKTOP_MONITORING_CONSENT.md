# Desktop monitoring consent

**Status:** built, tested, **not shippable until the owner completes the actions in §7.**
**Introduced in:** desktop agent `0.5.0`
**Document version currently required:** `2026-08-09` (`App\Models\DesktopConsent::CURRENT_VERSION`)

---

## 1. Why this exists

The Staffvora desktop agent is monitoring software. It records working time and
activity levels, and — when the employer enables them — periodic screenshots and
the names of the apps and websites in use. On workspaces with Machine
Compliance, it also inventories browser extensions and installed programs.

In most jurisdictions monitoring an employee is lawful only where the person was
**told what is captured** and **actively agreed**. The predecessor product
installed and started tracking with no such step. That is the single most
expensive mistake this software can make for a customer, and it is also the one
a customer will never discover until it is being argued about.

So: nothing is monitored until the person being monitored has read a
plain-language summary and ticked an unticked box, and that acceptance is
recorded somewhere it can be produced years later.

---

## 2. Where the consent is taken, and why not in the installer

**The gate is the first-run window, after sign-in.** The NSIS installer shows a
*notice*, not a consent.

An installer cannot deliver consent, for three reasons that no amount of UI work
fixes:

| | Installer | First-run window |
|---|---|---|
| Runs | once per **machine** | once per **user**, on every launch until accepted |
| Who is at the keyboard | often an IT administrator | the person being monitored |
| Identity available | **none** — nobody has signed in | the authenticated user |
| Re-runs | silently, on every auto-update | only when the document version changes |

An "I agree" in the installer is one technician agreeing on behalf of people who
have not been told anything, and it cannot be attributed to anyone, so it is not
evidence. A consent re-taken silently by a background auto-update is worse than
none.

What the installer **does** do (`desktop/build/installer.nsh`, `customInit`):
a modal before any file is written, stating that this is workplace monitoring
software, what it can record, that every user must accept the terms on first
launch, and the two document URLs. **Cancel really cancels.** It is skipped in
silent mode (`IfSilent`), which covers both electron-updater applying an update
and IT mass-deployment — a modal on an unattended machine would hang a rollout,
and skipping it costs nothing because the per-user gate still applies.

---

## 3. What the employee sees

`desktop/renderer/src/views/Consent.jsx`, rendered by `App.jsx` between sign-in
and the Tracker.

- A seven-point plain-language summary **above** the checkbox, on the screen —
  not behind the links. Someone who never opens the Terms still learns that
  screenshots and app names can be recorded, that keystrokes are not, who can
  see the data, and that nothing is captured while the tracker is stopped.
- Real links to the Terms of Service and Privacy Policy, opened in the system
  browser.
- The document version and effective date, because the person is agreeing to a
  specific text.
- **An unticked checkbox** carrying the exact sentence
  *"I have read and agree to the Terms of Service and the Privacy Policy, and I
  understand what is monitored."*
- **Agree and continue**, disabled and visibly dimmed until the box is ticked.
- **Decline and sign out** — a real first-class option. A gate with no exit is
  coercion, and coerced consent is not consent.

There is no "by continuing you agree" path, and no route into the Tracker view
that skips this component.

---

## 4. How it is recorded

`POST /api/desktop/consent` → `desktop_consents` (append-only).

Each row holds: organization, user, `document_version`, `disclosure_hash`, the
two document URLs as advertised at the time, source, device name, platform, app
version, `accepted_at` (**server clock**), `client_at` (the employee's claim,
clamped, stored separately), and the request IP.

`disclosure_hash` is a sha256 of the exact disclosure text served with that
version. Without it a row saying "accepted 2026-08-09" cannot answer the only
question that matters in a dispute — *what did it actually say?* — once the
marketing pages have been edited.

**Retention: none.** Every other telemetry table here is pruned by
`monitoring:prune-screenshots`. This one is deliberately excluded — the whole
point is producing it later. Do not add it to a retention sweep.

**Row-flood bound:** writes de-duplicate on (organization, user, version,
device); the route is throttled; there is a 200-row-per-user backstop. One
employee's realistic ceiling is (devices × versions), a few dozen rows.

---

## 5. Versioning — designed in, not retrofitted

The **server** decides which version is required.
`GET /api/desktop/consent` advertises `DesktopConsent::CURRENT_VERSION`, and the
comparison is an **exact string match, never `version_compare()`** — "newer than
what they signed" is not consent.

**To publish new terms:**

1. Update the published `/terms` and/or `/privacy` site pages.
2. Edit `DesktopConsent::DISCLOSURE` / `CHECKBOX_LABEL` if what is captured has
   changed.
3. Bump `DesktopConsent::CURRENT_VERSION` and `EFFECTIVE_DATE` **in the same
   commit**.
4. Update `BUNDLED_DOCUMENT` in `desktop/main/consent.js` to match (a test pins
   this; see §6).
5. Deploy. **No desktop release is required** — every installed agent re-asks on
   its next launch, and old acceptances stay on file untouched.

An acceptance of a superseded version stops satisfying the gate the moment the
constant moves. Nothing is auto-migrated forward.

---

## 6. Failure modes it is built to survive

| Situation | Behaviour |
|---|---|
| Server unreachable on first launch | Gate still shows, using the bundled fallback disclosure. Acceptance is stored locally (`synced: false`) and uploaded on the next sign-in or 5-minute retry. **Offline is never a bypass.** |
| Terms changed while a machine was offline | The queued acceptance gets `409`; the local record is **discarded** and the person is asked about the terms that actually apply. Consent to text nobody saw is never banked. |
| Shared PC changes hands | Consent is keyed by **user id**, never by machine. The next person is asked. |
| Error, malformed payload, corrupt local ledger, missing preload bridge | All read as **not accepted**. The gate fails closed. |
| Compromised renderer / tampered server | Documents are opened by **name** (`'terms'` \| `'privacy'`) through the main process against an http(s) allow-list, so the consent screen cannot become a launcher for arbitrary URLs. |
| Tampered agent claiming someone else's consent | The user and tenant come from the Sanctum token; a body-supplied `user_id` / `organization_id` is not merely ignored, it is never read. |

**Tests**

- `tests/Feature/DesktopConsentTest.php` — 18 tests. Includes contract guards
  that fail if the agent's bundled fallback drifts from the server document, if
  the checkbox stops starting unticked, if `App.jsx` stops rendering the gate, or
  if the installer notice loses its `IfSilent` guard.
- `desktop/scripts/consent-selftest.js` — 20 checks, plain node
  (`npm test` from `desktop/`).

---

## 7. What only the owner can do before this ships

1. **Publish `/terms` and `/privacy`.** They are currently seeded as **drafts**
   and both URLs **404 today** (`DefaultSiteContent`, pinned by
   `PublicSiteReleaseGuardTest`). A consent screen linking to two 404s is worse
   than no consent screen — it is a documented, dated record of pointing people
   at nothing. **This is the hard blocker.** The pages need legal review, not
   placeholder copy.
2. **Have a lawyer review the disclosure bullets** in
   `DesktopConsent::DISCLOSURE` against the published terms and against the
   jurisdictions being sold into. They are written to be honest and readable;
   they are not legal advice and have not been reviewed.
3. **Code-signing certificates.** The build is unsigned. An unsigned monitoring
   agent triggers SmartScreen on Windows and cannot auto-update on macOS
   (Squirrel.Mac requires signing). Windows OV/EV certificates have a 1–3 week
   lead time; an Apple Developer ID is same-day but needs an enrolled account.
   Nothing here can be shipped as a trusted install until these exist.
4. **Decide the employer-side obligations.** Whether Staffvora requires the
   customer to warrant that it has a lawful basis to monitor, and whether that
   belongs in the Terms, is a commercial/legal decision, not a code one.
5. **Run `php artisan migrate --force` on production** after deploy — the
   Hostinger auto-deploy pulls code but not migrations.

---

## 8. Files

| File | Role |
|---|---|
| `app/Models/DesktopConsent.php` | Version, disclosure text, hash — the single source of truth |
| `app/Http/Controllers/Api/Desktop/ConsentController.php` | `GET`/`POST` endpoints |
| `database/migrations/2026_08_09_100000_create_desktop_consents_table.php` | The append-only ledger |
| `desktop/main/consent.js` | Gate state, offline record, sync, link allow-list |
| `desktop/main/ipc.js` | `consent:status` / `consent:accept` / `consent:openDocument` |
| `desktop/preload/preload.js` | The bridge |
| `desktop/renderer/src/views/Consent.jsx` | The screen |
| `desktop/renderer/src/App.jsx` | The gate wiring |
| `desktop/build/installer.nsh` | Pre-install notice |
| `desktop/scripts/consent-selftest.js` | 20 client-side checks |
| `tests/Feature/DesktopConsentTest.php` | 18 server-side tests |
