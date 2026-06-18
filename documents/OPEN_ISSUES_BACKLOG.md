# Open Issues Backlog

Working list, worked one by one. Last updated: 2026-06-12 (late evening).

## SHIPPED — for reference

- Desktop v0.2.0–v0.2.4: auto-update pipeline (electron-updater, generic feed
  at /desktop-updates), in-app download-progress banner with Restart now/Later,
  Office Work / Test Task / Upwork Bidding no-client tracking (pseudo-client
  IDs -1/-2/-3), 401 → "session expired" re-login UX, login revamp (baked URL,
  hostname device name, remember-me via safeStorage, show/hide password),
  branded app icon, one-click installer. Current: **v0.2.4**.
- Server: clock actions 165 queries → 3; Dashboard ~400 queries → 6; Desktop
  App page 3.6s → 10ms (cached installer hashes); Reports dropdown hydration
  fix; www/bare domain normalization (rollups + ingestion + backfill).
- Dashboard ↔ desktop two-way clock sync (45s + focus polling web, 60s +
  focus desktop); no clock-state flash on navigation.
- Avatars + self-service logo upload on S3 (Developer → Branding); survive
  deploys.
- Scheduler alive on hPanel cron (plain full-path command, no metacharacters)
  + HTTP fallback /cron/run/{token}; schedule-heartbeat liveness file.
- Slack: daily/weekly digests at 11:00; bot token saved; password-reset codes
  via Slack DM (6-digit, 10-min TTL, rate-limited).
- Stale-session sweep (close-stale-sessions every 10 min, stops at last
  heartbeat).
- Midnight-shift clock-in bucketing fix (attendanceDateFor + 180-min early
  grace).
- Welcome + Login redesign (phase 1); instant Settings toggles (JSON ack).

## BUILD QUEUE (Claude work, in recommended order)

1. **AI integration phase 1** — AI-written daily Slack digest narrative +
   "Summarize this day" button on Timeline. Laravel + official PHP SDK
   (anthropic-ai/sdk). The Developer page "AI" group + ANTHROPIC_API_KEY
   slot + services.anthropic config SHIPPED (d173be6).
   **Blocked on:** user creating an Anthropic API key (console.anthropic.com).
2. ~~Navigation reorganization + clickable rows~~ **SHIPPED d173be6**,
   QA'd in browser 2026-06-13: Performance ▾ / Management ▾ / System ▾
   groups, Attendance top-level, Desktop App in avatar menu, Team renamed
   Team Performance with member click→filtered report, client click→report.
3. **Design revamp (Ember cinematic — picked 2026-06-13)** — phase 2 shell
   SHIPPED e912318; phase 3 part 1 SHIPPED ae083c6 (Dashboard, Team
   Performance, Timeline fully dark); part 2 SHIPPED 59c399d: dark canvas
   + ember headers on every remaining page (Reports, Work Diary,
   Attendance, Clients, Users, Settings, Developer, Desktop App, forms via
   PageShell/PageHeader + layout root). Content cards inside those pages
   stay light ("cards on dark") — optional final polish: convert each
   page's tables/cards to full dark. All verified live in browser.
   Note: color-scheme "only light" meta (d38e813) — flip to "dark" when
   the card interiors go dark too.

3b. **Timeline overnight split — SHIPPED 2d80aa7, verified on real night
   shift data**: sessions show on every day they overlap, screenshots/
   activity under the correct calendar date, totals split at midnight
   (Fri 3h24m + Sat 1h55m = 5h19m week ✓). Early clock-in grace now 4h.
4. **Reports deeper lightening** — server-side pagination/filtering so the
   month view stops shipping every row to the browser (page already
   paginates client-side; heavy dropdown hydration already fixed).
5. **Polish batch** — honor the 12/24h display setting in monitoring
   timestamps; branded 404/500 pages.
6. **Saved design (decision needed):** 8AM→8AM business-day attendance
   bucketing — documents/ATTENDANCE_DAY_BUCKETING.md (3 required pieces).

## USER-SIDE CHORES (only Jawad can do)

- Create Anthropic API key → unblocks build item 1.
- Delete the old AWS key (AKIA…7B6P) in IAM (new …B2PO key already live).
- Rotate the prod DB password (was pasted in chat) → then Claude updates
  .env over SSH (printf, never echo — no trailing newline in prod .env).
- Rotate SCHEDULER_HTTP_TOKEN (also pasted in chat) — rotate-later list.
- Delete leftover hPanel test crons (cron-test, cron-simple).
- Windows team: install v0.2.4 once (last manual install — auto-update
  takes over after).
- Mac build: run documents/MAC_BUILD_PROMPT_0.2.0.md on a Mac (pulls latest
  code so it builds the current version).
- Team avatar re-uploads (originals were wiped by deploys pre-S3).
- Audit night-shift users stored 21:00 vs real 22:00 shift start.

## NEXT SESSION QUEUE (noted 2026-06-13 morning, before Jawad logged off)

1. **Screenshot lightbox (first thing)** — clicking a screenshot must open
   an in-page popup, NOT a new tab. Next/Prev arrows (+ arrow keys) to
   flip through a session's screenshots fast; show time/app/activity; put
   Flag and Delete inside the lightbox. Applies to Timeline and the
   monitoring SessionDetail page.
2. **Inertia v1 -> v2 upgrade** — hover prefetch + deferred props for
   instant-feeling nav (measured floor ~0.7s, first visits ~2s).
3. **Full-system page audit WITH Jawad** — walk every page together and
   write documents/PAGE_AUDIT.md: per page (a) what it is for, (b) what
   can be improved, (c) what looks bad, (d) performance notes. This is
   the agreed page-per-page optimization plan.
4. AI phase 2: act-through-chat (permissions/shifts/attendance changes
   with confirmation cards) — awaiting explicit go.
5. Dark interiors final polish (Clients/Users/Settings/Attendance/forms
   card interiors) + flip the color-scheme meta to dark when complete.
6. Coverage chip in Attendance Summary tab + teach the AI assistant
   coverage questions.

USER-SIDE (Jawad): bulk-set night team shifts (Users -> sort by Shift ->
bulk edit); grant "Use the AI Assistant" to managers; verify Haris
Junaid's 41h manual entry on 06-07; security batch (DB password, old AWS
key, test crons); Mac build via the Mac prompt doc (builds v0.3.0).

## DELETION CLEANUP GAPS (Jawad, 2026-06-13, screenshot of Jawad Islam Fri 12)

A. Leftover residue after deleting sessions/screenshots: month-strip still
   shows green day dots, Week/Month totals show 1m, and the Tasks box lists
   clients at 0m. Cause: sessions reduced to <60s still exist (dot renders
   for any total_seconds > 0; client breakdown lists every session). Fix:
   treat <60s sessions as nothing everywhere (strip dots, totals, client
   breakdown, Team Performance) — or auto-delete sessions that hit 0.
B. Work Diary -> full cascade: deleting a work-hour entry must also remove
   its linked tracking session, screenshots, activity samples, and any
   Timeline/Team Performance traces (reverse of the session->workhour sync).
   Same audit trail as session deletion.
C. INDUSTRY-LEVEL FULL INSPECTION (CEO-ready): thorough joint pass over
   every page and every flow — correctness of all numbers across pages
   (Timeline = Reports = Team = Dashboard = Attendance), deletion cascades
   verified end to end, visual polish, empty states, error states, perf.
   Output: documents/PAGE_AUDIT.md with per-page findings + fix list.

## SHIPPED 2026-06-13 (continued session, opus fast)
- Deletion residue (gap A) FIXED e49f6c2: screenshot deletion that guts a
  session <60s with no shots purges the whole session; Timeline totals/
  Tasks/month-dots, week/month totals, and Team Performance ignore
  sub-minute sessions. One-time prod cleanup removed 52 existing ghost
  sessions (438s) + 33 orphan samples. Verified user 2 Fri Jun12 = 0m.
- Work Diary reverse cascade (gap B) FIXED e49f6c2: deleting a tracker
  entry (single+bulk) tears down its session, screenshots, samples;
  manual entries unaffected; audit logged. Shared
  TrackingSessionService::purge(). Tests: DeletionCascadeTest (3).
- Screenshot lightbox (queue #1) SHIPPED 83ae935: in-page viewer, Prev/
  Next + arrow keys across the day's shots, Esc close, header shows
  time/app/activity/flag/position, Flag + Delete inside. NOT YET applied
  to monitoring SessionDetail page — do in the joint audit.
- 116 tests green.

STILL NEXT: Inertia v2 speed upgrade (queue #2); full CEO-ready page
audit WITH Jawad -> documents/PAGE_AUDIT.md (queue #3, gap C); apply
lightbox to SessionDetail; dark card interiors; AI phase 2.

## SHIPPED 2026-06-13 (avatars/logo/filters, opus fast)
- Avatars (ab8f83b + 0c905c1): root cause was avatar_url minting a fresh
  presigned S3 URL every render -> no browser cache -> ~50 signed GETs to
  Stockholm at once -> 503 throttling + slow. Now a stable cached
  /avatar/{user} proxy (week browser cache + ETag/304 + server byte
  cache) AND on-serve resize to 160px. Live photos were 1.7-1.8MB ->
  ~30KB (60x). Also: 25 of 28 avatar refs were DEAD pointers (files
  wiped by pre-S3 deploys) -> nulled them so they show initials with no
  failed request (images already gone; only broken pointers removed). 3
  live avatars remain (ids 2,3,41).
- Logo: byte-cached + resized 1024px/1MB -> 320px/102KB (10x). Both
  verified live.
- Users filters lost on edit return FIXED: UserEdit never read the
  return_to prop, so save redirected to a filterless /users. Wired
  return_to -> UserForm. Verified: edit+save returns to
  /users?dir=desc&search=claude&sort=shift intact.
- New: AvatarController, app/Support/ImageResizer (GD, graceful
  fallback). 116 tests green.
- CHORE still open: 25 users need avatar re-uploads (originals long gone).

## SHIPPED 2026-06-13 (Inertia v2 + prefetch, opus fast)
- @inertiajs/react 1.3 -> 2.3.26 (d927d51). CLIENT ONLY — kept
  inertiajs/inertia-laravel at v1.3.4 because prod vendor/ isn't updated
  by git deploy (gitignored; Hostinger persists it), so a server-adapter
  bump would need a risky composer update on the box. v2 client is
  backward-compatible with the v1 server for core nav + prefetch.
- Hover prefetch on all nav (primary items + dropdowns), cacheFor 20s
  (175c6c6). Verified live: hovering Timeline fired GET /timeline 200
  BEFORE click; click navigates instantly from cache.
- Verified: pages render, router.get (Reports search) works, Link clicks
  navigate, zero console errors, 116 tests pass. The one 404 seen at
  deploy was the known mid-deploy race (endpoints 200 after).
- Rollback point was e701f75 (not needed).

OPTIONAL FUTURE (needs prod composer update): bump inertia-laravel to v2
to unlock DEFERRED PROPS — render the page frame instantly and stream
heavy tables (Reports/Team) after, like the Dashboard already does.
Plan it as its own step with a server composer require + full retest.

## SHIPPED 2026-06-13 (dark-theme polish pass, opus fast)
- Bulk-edit permissions picker: killed the nested scroll. SearchableMultiSelect
  got an `inline` mode that expands the list in-flow (no inner scrollbar) so a
  scrollable modal has ONE scrollbar instead of two stacked (2772e42). Applied
  to the Grant/Revoke pickers; page filters keep the floating overlay.
- Dark card interiors brought to the remaining LIST/DATA pages so they match
  the Ember dark system (Dashboard/Team/Timeline):
  - Monitoring SessionDetail: dark + upgraded the screenshot lightbox to the
    Timeline pattern (header, Close button, Prev/Next arrows, ←/→/Esc keys,
    click-dark-to-close, "n / total" index). Verified live on session #297.
  - Monitoring SessionsList, Attendance (metric cards/tabs/inputs/summary
    table/accordions/monthly grid), Clients, Work Diary list + report.
  - Commits 0fda879, 4b12311, f4b7195. All verified live.
- DELIBERATELY LEFT LIGHT (consistent rule): complex FORM dialogs that embed
  the light-themed SearchableMultiSelect / form controls — Attendance
  calendar/history/Slack dialogs, the Report "Send to Slack" dialog. Simple
  confirm modals (delete) WERE darkened.

STILL LIGHT — remaining dark-theme work (its own deliberate step):
- The add/edit FORMS: ClientCreate/Edit, WorkHourCreate/Edit, User add/edit,
  UpworkProfiles. These use shared light-themed input components (TextInput,
  ClientCombobox, TagInput, SearchableMultiSelect) that are also used inside
  the light bulk-edit modal — so they need a dark *variant* on those shared
  components rather than per-page edits, or the light modals break. Scope it
  with the shared components, not page-by-page.
- Settings page (admin, System menu): big white panel + many setting
  sub-components; full conversion or leave — a half job looks broken.
- Minor: DesktopDownloads, Developer, Profile/Edit.

NOT DONE — needs backend, not polish:
- Coverage chip in Attendance Summary tab. The summary endpoint returns
  total_work_hours / total_break_hours but NO tracked-work figure, so
  presence-vs-tracked coverage needs the controller to compute + include
  tracked seconds per employee/day first. Small feature, not a frontend tweak.

## SHIPPED 2026-06-14 (forgotten-clock-out safety net, opus fast)
- ROOT CAUSE: the attendance clock is fully MANUAL (web + desktop only record
  the action pressed). A clock-in with no clock-out hangs open forever — person
  reads "Working" indefinitely, day never closes. close-stale-sessions only
  touches tracking SESSIONS, not attendance. 15 users were stuck (Yasir 61h,
  Salman 39h, Qaswar on-break 30h, ... Jawad 9.5h).
- NEW command attendance:auto-clock-out (2d7150d). Closes a dangling clock-in
  and writes a REAL clock_out with a plain-language reason. Clock-out time:
  (1) tracked-then-idle >= idle-hours -> last heartbeat; (2) never tracked ->
  capped at cap-hours. Never past the cap (no 60h ghosts); never closes a
  still-alive session (idle < 2h). Open breaks get a break_end first. clock_out
  uses the clock-in's attendance_date so the pair stays on the right day.
  Params: --cap-hours=12 --idle-hours=2 (12h = owner's stated max shift).
- Gated by services.attendance.auto_clockout_enabled (env ATTENDANCE_AUTO_CLOCKOUT).
  Dry-ran on prod, owner reviewed, then applied: closed 8 stale (15 -> 7 open;
  the 7 left are genuinely active / within 12h, incl. Jawad who auto-closes at
  8:03 AM). Set ATTENDANCE_AUTO_CLOCKOUT=true + config:cache; scheduled every
  30 min. Verified Yasir got a clean clock_in/clock_out pair w/ reason.
- NEXT STEP (owner approved "Slack next"): build an interactive Slack
  "still working?" check as the PRIMARY going-forward mechanism, 12h cap as the
  backstop. Flow: at ~8h open, DM the person with buttons [Yes still working]/
  [No clock me out]; Yes snoozes, No clocks out, no-response after a few 10-min
  nudges -> auto clock-out. NEEDS FROM OWNER: Slack app "Interactivity" enabled
  + Request URL (we provide, e.g. /api/slack/interact) + SLACK_SIGNING_SECRET in
  .env; DMs reach users via email->Slack lookup (users:read.email scope).

## SHIPPED 2026-06-14 (Slack "still working?" check — LIVE team-wide)
- Interactive companion to the 12h cap (66dff3c). At 8h open (config
  prompt_after_hours), attendance:still-working-check DMs the person Yes/No
  buttons; Yes => confirmed_until=now+2h (snooze + exempt from cap while fresh);
  No => clock out now ("Clocked out via Slack"); no response after 6 nudges
  (10-min interval) => auto clock-out with reason. Buttons POST to
  /api/slack/interact (SlackInteractionController, signing-secret HMAC + replay
  guard). State table attendance_clock_checks; shared App\Services\AttendanceCloser.
- Owner enabled Slack app Interactivity + Request URL; SLACK_SIGNING_SECRET in
  .env. Both buttons tested live on Jawad (Yes set snooze; No wrote a real
  clock_out). Enabled team-wide: ATTENDANCE_STILL_WORKING_SLACK=true,
  scheduled */10. Open clock-ins now 5 (from 15); Haris/Aliyan get the first
  real DMs on the next run.
- Two attendance jobs now live: auto-clock-out (*/30, 12h cap backstop) +
  still-working-check (*/10, Slack prompts). Tunables: ATTENDANCE_PROMPT_AFTER_HOURS,
  --cap-hours, --idle-hours, --max-prompts, --snooze-hours.

## DESKTOP v0.3.1 (multi-monitor capture) — code done, build/release pending
- Dual/extended-monitor users looked "frozen" (only the primary screen was
  captured). Now desktop/main/screenshotService.js detects displays and, on a
  multi-monitor setup, captures every screen (screenshot.all) and stitches them
  side-by-side into ONE wide jpg via jimp (added dep, classic 0.22 API, pure JS
  so it bundles in asar). Single-monitor unchanged; always falls back to primary
  on any failure. No server/web changes — still one image per capture. Stitch
  validated standalone (1920x1080 + 1366x768 -> 3294x1080 JPEG). Commit 7f42976,
  version bumped 0.3.0 -> 0.3.1.
- TO SHIP: (1) Windows build `cd desktop && npm run rebuild && npm run dist`
  -> dist-app installer + latest.yml, upload to the update feed at
  https://timetracker.sparkingasia.com/desktop-updates (deploy-proof installer
  hosting). (2) Mac build via the Mac Claude session — BUMP the Mac build prompt
  to 0.3.1 so Mac includes this too. Auto-updater then pushes it; the 2-3
  dual-monitor employees get it on next update.
- VERIFY after rollout: a dual-monitor employee updates, then their next
  timeline screenshot is a wide image showing both screens.

## SHIPPED 2026-06-14 (desktop v0.3.1 multi-monitor + rollback) — LIVE
- v0.3.1 built (Windows) + uploaded to the feed (/home/u406855808/desktop-installers):
  SA Track Setup 0.3.1.exe + blockmap + latest.yml. Verified over HTTP
  (latest.yml=0.3.1, exe 200/87.5MB). Clients on 0.3.0 auto-update. Downloads
  page bumped to 0.3.1. Mac build still pending (bump its prompt to 0.3.1).
- ROLLBACK now possible:
  - desktop/main/updater.js: autoUpdater.allowDowngrade=true (baked into 0.3.1+),
    so the feed can move clients DOWN a version.
  - Every release archived as latest-<version>.yml beside its installer
    (latest-0.3.0.yml, latest-0.3.1.yml created). Old exes already kept.
  - Command `php artisan desktop:set-active-version <ver>` copies that version's
    archived manifest over latest.yml → all clients move to it next check. `--list`
    shows archived + active. ROLL BACK A BAD RELEASE: `... 0.3.0`; forward again: `... 0.3.1`.
  - NOTE: rollback only reaches versions that have BOTH an archived manifest and
    the installer present. 0.2.x exes exist but have no archived manifest (and a
    different productName), so practical rollback floor is 0.3.0.
- NOT DONE (optional follow-up): self-service rollback BUTTON on the Developer
  page (currently rollback is the artisan command via SSH). Reuse the Developer
  `run` action infra + show active/available versions.

## SHIPPED 2026-06-14 (timeline labels + auto clock-in; desktop redesign queued)
- Timeline Tasks breakdown: non-client work now shows its type (office_work->
  "Office work", upwork_bidding->"Bidding", test_task->"Test task") instead of
  "Unassigned" (TimelineController::breakdownLabel). Verified on Jawad's real
  6/14 data. Pure backend, no rebuild.
- Auto clock-in on tracker start (Desktop SessionController::start): if not
  already clocked in, create a clock_in at the SESSION'S start time (correct
  even for offline-queued starts), notes "Auto clock-in (started tracker)".
  Pairs with the auto-clock-out net. Backend only.
- Web login show-password eye toggle ALREADY EXISTS (Login.jsx).
- Offline tracking CONFIRMED fine: screenshots/activity queue locally with real
  timestamps + sync on reconnect; auto clock-in uses session start time too.

## QUEUED — DESKTOP v0.3.2 (owner wishlist, needs build; owner: "design the 3 best")
- THEME SYSTEM + cinematic redesign: app feels flatly dark; add a one-click
  theme switcher with ~3 polished themes (Claude to design — e.g. warm
  cinematic default / clean light / neutral midnight). Premium, "studio" feel.
- Smoother scrolling (QoL — current scroll feels rough).
- Prominent "who's logged in" name (shared computers / different shifts so
  people don't track under someone else). Header already shows name; make it
  unmissable.
- Show-password eye toggle on the DESKTOP login (web already has it).
- (Carry) Mac 0.3.1 build (bump Mac prompt), self-service rollback button.

## NEXT SESSION (owner: "we'll do it tomorrow"): DESKTOP v0.3.2 redesign
Full brief: documents/DESKTOP_V0.3.2_REDESIGN_BRIEF.md. Premium/cinematic
redesign of the desktop tracker: 3 themes + switcher (Claude designs), REDESIGN
THE CLOCK (owner: the purple disc looks too much like the competitor's
screenshot-monitor — make it bespoke/premium/theme-aware), premium week graph,
smoother scrolling, prominent logged-in name, desktop login eye icon (coded).
Files: desktop/renderer/src/{styles.css, views/Tracker.jsx, views/Login.jsx, App.jsx}.

## SHIPPED 2026-06-15 (desktop v0.3.2 — premium redesign)
- Theme system: CSS-variable palette + 3 switchable themes (Cinematic ember
  default / Light / Midnight slate-indigo), one-click switcher in the drawer,
  persisted via prefs.theme (electron-store), applied app-wide incl. login.
- Bespoke clock: flat purple disc -> progress RING filling toward an 8h day,
  accent gradient + glow + live pulse; theme-aware. (Owner: competitor's
  screenshot-monitor disc, make it ours.)
- Theme-aware week graph (today in accent gradient); custom slim scrollbars +
  smooth scroll; prominent identity header (gradient avatar + "Signed in as").
- Desktop login eye icon (from 0.3.1 commit) ships here too.
- Verified all 3 themes via a temporary browser mock harness (removed after).
  Commit 26618cd. Build/upload to feed in progress; archive latest-0.3.2.yml,
  set active, Downloads page bumped to 0.3.2. latest-0.3.0/0.3.1 kept as
  rollback targets.

## QUEUED — DESKTOP v0.3.3 (identity / shared-computer, owner discussion 2026-06-15)
- Goal: on a shared PC, make it obvious whose tracker it is + easy to switch to
  your own account, WITHOUT nagging people who are clearly present.
- ACCOUNT SWITCHER (Facebook-style): "Welcome, <name>" header with a Switch
  account control -> list of saved people on this machine (avatar+name) +
  "Add another account" (normal login once) + Log out. Switching uses the
  already-saved token (no email/password re-entry).
- PIN: DEFERRED (owner: skip for now, revisit if team feedback in ~1-2 weeks
  asks for it so no one can open someone else's tracker). When needed: per-
  account 4-5 digit PIN (store a HASH), required to switch into an account.
- PRESENCE SIGNAL = clock-in/out (server-connected), NOT tracker activity —
  executives clock in but don't run the tracker, so "no tracker session" must
  never be read as "not working".
- DON'T NAG THE CLOCKED-IN: no welcome/confirm prompt for someone already
  clocked in within their shift. Active prompts only when NOT clocked in, or
  past ~8h (the existing Slack still-working check already fires at 8h on the
  open clock-in, so they're consistent).
- CONTEXTUAL GREETING: "Welcome, <name>" only on open / when NOT clocked in
  (invites confirm-identity + clock-in). Once clocked in / mid-shift, just show
  name + status (e.g. "Working since 9:02") — no "welcome", which feels wrong
  to someone who's been in the office for hours (owner's point).
- OPTIONAL pairing: auto-lock to the switch/identity screen after ~10min idle,
  so the next person at a shared PC must pick their own account before tracking.
- More settings to consider: daily-hours goal (the ring target, fixed 8h now),
  default client + work type for one-tap start, "remind me to clock in",
  launch-minimized-to-tray, global start/stop hotkey, per-account theme.

## CONFIRMED SPEC — concurrent / multi-device tracking (owner decided 2026-06-15)
PROBLEM (verified in code): sessions are keyed on (user, device-uuid) with NO
one-active-session guard, and reports SUM total_seconds with no overlap merge.
So tracking on 2 PCs double-counts (9-5 on both = 16h) + 2 screenshot streams.
Abuse/accuracy hole.

DECISIONS:
1. DEFAULT = single active session, LAST DEVICE WINS. Starting the tracker on a
   2nd PC auto-STOPS the session on the 1st PC. Show a clear message: the new PC
   says it started here; the stopped PC tells the user "you started tracking on
   another PC, this one was stopped" so they know.
2. SUPER-ADMIN per-user setting "Allow tracking on multiple devices" (off by
   default). A person asks the owner; owner enables it for that user only.
3. For ALLOWED dual-tracking: keep both, but it must be WRITTEN CLEARLY that the
   overlapping time was DOUBLE TRACKED — a visible "Double tracked" label on the
   overlapping sessions/intervals in Timeline + Reports (transparency, not silent
   sum/merge).

BUILD PATH (incremental):
- SERVER-ONLY (no desktop rebuild, closes the hole now): add allow_multiple_devices
  user flag (default off); on /sessions/start finalize the user's OTHER active
  sessions unless the flag is set, return stopped-device info; heartbeat signals
  the stopped PC. Overlap detection + "Double tracked" label in Timeline/Reports.
  Super-admin toggle in Users edit + bulk edit. Admin overlap alert (quick win).
- DESKTOP (fold into v0.3.3): the friendly "tracking moved here / your other PC
  was stopped" in-app messages.

## SHIPPED 2026-06-15 (multi-device single-session enforcement) — server-side, LIVE
- users.allow_multiple_devices (default false) + super-admin toggle in user
  create/edit form. SessionController::start finalizes the user's OTHER active
  sessions unless allowed; heartbeat 409s a stopped session so stale pings can't
  re-inflate. Closes the double-count hole (commit 291fa65, migrated). Verified:
  0 users currently double-tracking, 0 allowed.
- FAST-FOLLOW (desktop v0.3.3 + a bit of web): in-app "tracking moved here /
  your other PC was stopped" messages (desktop reads stopped_other_devices on
  start + the 409 on heartbeat); "Double tracked" label on overlapping
  sessions in Timeline/Reports for allowed users; allow-multiple toggle in BULK
  edit; admin overlap alert.

## PRODUCT VISION doc created (documents/PRODUCT_VISION.md)
- Full feature inventory + roadmap to turn SA Track into a sellable multi-tenant
  SaaS: tenancy (the gate), self-serve signup, Stripe billing/membership, white-
  label + themes + i18n ("global, matches every company"), compliance, cloud
  scale, marketing. Build it as a multi-tenant evolution of THIS codebase, not a
  rewrite. See the doc for phases + packaging/pricing matrix + differentiation.

## SHIPPED 2026-06-15 (Mac v0.3.2) — LIVE on downloads page
- Mac build done on owner's Apple Silicon Mac (ad-hoc signed so permissions
  persist), published to GitHub release desktop-v0.3.2-mac. Claude pulled the
  DMG+zip from the public release (sha256 19cfac2a… verified) and scp'd them to
  the prod feed as "SA Track-0.3.2-arm64.dmg" + ".zip" (space form to match
  DesktopDownloadController MAC_INSTALLERS; MAC_VERSION bumped to 0.3.2). Serving
  over HTTP (200, 123MB). Shows on /desktop-downloads.
- Mac smoke test: login + tracking VERIFIED server-side (session 312, platform
  darwin, app_version 0.3.2). Screenshots NOT yet confirmed (0 shots) — needs
  macOS Screen Recording permission granted to SA Track + a longer session.
- Reminder: Mac is unsigned-for-update (ad-hoc only) -> NO auto-update; Mac users
  update manually from /desktop-downloads. arm64 only.

## ROLLOUT ISSUES 2026-06-15 (company-wide deploy)
1. UNCLEAR LOGIN ERROR — FIXED in code (08e407a): desktop showed raw
   "AxiosError: Request failed with status code 422". Now api.js login()
   maps failures to plain language ("Incorrect email or password.", "Could not
   reach the server…", rate-limit) + renderer strips the Electron IPC prefix.
   Ships in next desktop build. Server already returned a clear message.
   NOTE: the reporting user was on the OLD "Timetracker Desktop" build (pre-
   rebrand) — they should update to SA Track v0.3.2.
2. BITDEFENDER blocks SA Track.exe as "Malware" (Advanced Threat Defense /
   behavioral, 60 apps blocked). FALSE POSITIVE — the app is UNSIGNED and does
   keylogger-adjacent things (global kbd/mouse hooks via uiohook-napi +
   screenshots), which behavioral AV flags. Standard for monitoring tools
   (Hubstaff/Time Doctor also need AV exclusions).
   - IMMEDIATE (unblock today): central Bitdefender **GravityZone** exclusion
     for the install folder %LOCALAPPDATA%\Programs\timetracker-desktop\ (+ exe),
     incl. an Advanced Threat Defense exception, pushed to all machines. Per-
     machine: Protection → Advanced Threat Defense → Settings → Manage
     exceptions. Also submit a false-positive to Bitdefender Labs.
   - PROPER (recommended for company + product): buy a **code-signing cert**
     (OV ~$200-400/yr, or EV which also clears Windows SmartScreen instantly).
     Then configure electron-builder win.signtoolOptions/certificate → signed
     builds gain publisher reputation + clear SmartScreen. NOTE: even signed, a
     screenshot/keystroke tool may still need an ATD exclusion — signing reduces
     friction (esp. on un-managed machines) but the exclusion is the reliable op
     fix for this app category.
   - DECISION PENDING: whitelist now + ship unsigned v0.3.3 (login fix), or get
     a cert first and ship signed.

## PLAN — tracker↔clock coupling + shift info on desktop (owner 2026-06-15)
TRIGGER: Hina Batool's day was contradictory — auto clock-out backdated to
1:00 PM (tracker idle) WHILE she was still present (manual break 3:11, manual
clock-out 5:08). Root cause: the auto-clock-out's IDLE rule trusts "tracker went
quiet" as "left", which conflicts with the principle clock=truth-not-tracker.

DESIGN — "the tracker follows the clock":
1. Start tracker -> auto clock-in if not clocked in. (ALREADY SHIPPED.)
2. Start break -> PAUSE the tracker; end break -> resume. (NEW)
3. Clock out -> STOP the tracker. (NEW) Can't track while clocked-out/on-break.
   => break time never tracked; can't track without being clocked in. Prefer
   auto-clock-in over "block tracker unless clocked in" (smoother).
4. Refine AUTO-CLOCK-OUT: drop the "tracker idle -> clock out at last activity"
   rule (it mis-closed Hina). Keep honest backstops: Slack "still working?"
   check (asks the person at 8h) + 12h cap. This is SERVER-SIDE — can ship now,
   no desktop build, stops the conflicting records immediately.
5. SHIFT INFO on desktop top: show name + shift window + on-time/late + current
   clock status, so people catch wrong shift entries (owner mis-entered shifts
   for several people) and always know where they stand. Server adds shift to
   the /time-clock status response; desktop displays it (v0.3.3).

SPLIT: #4 server-side now (urgent, stops bad records). #2/#3/#5 desktop = v0.3.3.

## FIXED 2026-06-15 (Hina / auto-clock-out idle rule) — server, LIVE
- ROOT BUG: auto-clock-out's idle rule back-dated a clock-out to the tracker's
  last activity when the tracker went quiet — produced contradictory records
  (Hina: auto clock-out 1:00 PM AND manual clock-out 5:08 PM, break_end at 1:00
  BEFORE break_start at 3:11). Removed the idle rule (503e07d): a quiet tracker
  no longer triggers a clock-out; only the 12h hard cap force-closes; the Slack
  "still working?" check (8h, ASKS the person) handles forgotten clock-outs.
  Verified: dry-run now closes 0 (was about to wrongly close an idle user).
- Hina's data fixed: shift_start 9 PM -> 9 AM (was owner's data-entry error);
  deleted the 2 bogus auto entries (ids 23534/23535) -> clean day (in 9:11,
  break 3:11-3:57, out 5:08).
- DEPLOY LESSON: a `git checkout -f` deploy did NOT stick first time (prod stayed
  on the prior commit). Always VERIFY deploys by file content (grep), not just
  the rev-parse echo.

## SHIPPED 2026-06-15 (Windows desktop v0.3.3) — LIVE
- Clear login errors ("Incorrect email or password." instead of raw 422) +
  Today-list aggregation (sums time per task; no more repeating rows). Built,
  uploaded to feed, active=0.3.3 (verified HTTP 200, 87.5MB), Downloads page
  bumped. Rollback targets 0.3.0–0.3.3 archived. Windows auto-updates once IT
  adds the Bitdefender exclusion. Mac 0.3.3 = quick rebuild (MAC_BUILD_PROMPT_0.3.3.md).
- COST DECISION: owner won't buy a code-signing cert / Apple Dev account. Not
  needed — Bitdefender GravityZone exclusion (free) is the standard fix for a
  monitoring tool; Mac stays ad-hoc-signed (free, right-click-Open). Cert idea dropped.
- STILL QUEUED for a future desktop build (not blocking): break↔tracker pause +
  clock-out↔tracker stop, shift-timing header on the tracker, account switcher
  (welcome logic), in-app multi-device "tracking moved here" messages.

## SHIPPED 2026-06-15 (Windows v0.3.4) — installer "cannot be closed" fix, LIVE
- NSIS installer/updater got stuck on "SA Track cannot be closed" because the
  Electron app's tray + helper processes don't close via the default check.
  Added build/installer.nsh with a customCheckAppRunning macro (taskkill /F /T)
  + nsis.include in package.json -> installer hard-closes the whole process tree
  before installing. Active=0.3.4 so older clients update straight to it via the
  fixed installer (no manual close). Verified HTTP 200; rollback targets 0.3.0-0.3.4.
- USER WORKAROUND for in-flight 0.3.3 installs: Task Manager -> End task all
  "SA Track" -> Retry (or Cancel and let 0.3.4 auto-install). Not from "removing
  a button" — inherent Electron/NSIS tray-app behaviour.
- Mac: bump MAC_BUILD_PROMPT to 0.3.4 next Mac build (installer.nsh is Win-only;
  Mac DMG unaffected).
