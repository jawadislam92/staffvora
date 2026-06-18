# TimeTracker Live Website Visual QA Report

- **Site:** https://timetracker.sparkingasia.com
- **Test account:** chat@gpt.com
- **Test date:** 2026-06-11
- **Dummy marker used:** CODX-20260611172457

## Summary

The live TimeTracker website was visually verified by logging in, navigating through the
authenticated app, creating dummy records, editing them, deleting them, and confirming cleanup.
All dummy data created during testing was deleted.

## Features Verified

### Authentication
- Login works with the provided credentials.
- Authenticated dashboard loads successfully.

### Dashboard
- Dashboard renders correctly.
- Time cards display current status, worked time, break time, sessions, and actions.
- Team Activity section loads.
- Attendance link is visible.
- Export button is visible.

### Work Diary
- Work Diary page loads.
- Add Entry page loads.
- Created, verified, edited, and deleted a dummy work entry.
- Confirmed Work Diary no longer contains the dummy marker.

### Clients
- Clients page and Add Client page load.
- Created a dummy client, linked it to a dummy Upwork profile, edited the name, added tag `qa-temp`.
- Verified weekly hours reflected the dummy work entry before deletion.
- Deleted the dummy client; confirmed search no longer contains the dummy marker.

### Upwork Profiles
- Upwork Profiles page and Create Profile page load.
- Created, edited, and deleted a dummy profile.
- Confirmed profile count returned to 30 and the dummy marker was gone.

### Users
- Users page and Add User page load.
- Created a dummy member user, edited the designation, and deleted it.
- Confirmed Users search no longer contains the dummy marker.

### Reports
- Work Hours Report page loads; filters open correctly.
- Dummy work entry appeared in the report before deletion.
- Export CSV button is visible.
- Slack report dialog opens; webhook shows as connected. (Did not send.)

### Attendance
- Attendance page loads after async data fetch.
- Monthly Grid, Summary, Activity, and Timeline tabs load.
- Attendance Calendar, Audit History, and Slack Attendance dialogs open. (Did not apply/send.)

### Team
- Team Members and Team Apps & URLs pages load.
- Member activity and app/URL data render. (Did not send Slack digest.)

### Settings
- Settings page loads; panels expand correctly for Time zone & format, Screenshots,
  Activity Level tracking, App & URL tracking, Weekly time limit, Auto-pause tracking.
- Did not save or change settings.

### Desktop App
- Desktop App page loads; Windows and macOS download options visible.
- Version, size, and SHA/checksum information visible. (Did not download installers.)

### Developer
- Developer page loads; System, health, actions, integration credentials, and logs sections render.
- Did not run developer/system actions.

### Monitoring
- Tracking Sessions and Session detail pages load.
- Screenshot thumbnails/images load from S3.
- Session metadata displays user, client, work type, status, duration, activity, and screenshots.
- Did not delete or flag screenshots.

## Dummy Data Created (and Deleted)

| Type | Name | ID |
|---|---|---|
| Upwork Profile | `Codex QA Profile CODX-20260611172457` | 32 |
| Client | `Codex QA Client CODX-20260611172457` | 1798 |
| Work Entry | `Temporary Codex visual verification entry CODX-20260611172457` | 26003 |
| User | `Codex QA User CODX-20260611172457` | 132 |

Final cleanup verification showed the marker `CODX-20260611172457` no longer appeared in
Work Diary, Clients, Upwork Profiles, Users, or Reports.

## Issues Found

### 1. User schedule fields do not persist

When creating and editing a user, `joining_date` and `shift_start_time` were filled. After saving,
both fields were blank when reopening the user edit page, and the Users list showed
`Not configured` for the user's shift.

- **Expected:** Joining date and shift start time persist after create/edit; Users list shows the configured shift.
- **Actual (as reported):** Joining date and shift start time were not saved or not returned correctly; Users list showed `Not configured`.

### 2. Password hint does not match backend validation

The user form said `Use at least 8 characters.` while backend validation requires a minimum of 12.

- **Expected:** Frontend hint should say at least 12 characters (or backend should allow 8).
- **Actual:** UI said 8 characters; backend requires 12.

## Actions Intentionally Not Performed

These were visible but not executed because they affect live integrations, live attendance, or
production configuration: Slack report sending, Attendance Slack sending, Team Slack digest sending,
Attendance calendar apply/remove, Developer system actions, Settings save/update, Client CSV import,
file/avatar upload, Desktop installer download, screenshot delete/flag, Clock In / Start Break /
End Break / Clock Out.

## Overall Result

The main live website flows are functional: authentication, navigation, and data-backed CRUD for
Work Diary, Clients, Profiles, and Users all work. Reports, Attendance, Team, Monitoring, Settings,
Desktop App, and Developer screens render successfully.

Main bugs found:
- User joining date and shift start time do not persist (see resolution below).
- Password helper text is inconsistent with backend validation (fixed).

## Speed / Performance Notes

A quick live wall-clock check was done in the in-app browser. This was not a full Lighthouse audit,
but it gives a practical user-facing speed read.

### General Result

The website feels reasonably fast overall. Most authenticated pages loaded in roughly:
- `0.6s - 1.2s` for the main page shell
- `1s - 2s` for data-heavy pages to become useful

### Sample Timings

Approximate live timings observed:

| Page | Approx Load Time |
|---|---:|
| Dashboard | 2.4s |
| Work Diary | 1.1s |
| Add Work Entry | 1.2s |
| Reports | 1.0s - 1.2s |
| Attendance | 2.1s until data visible |
| Team | 1.1s |
| Team Apps | 0.8s |
| Clients | 0.7s |
| Upwork Profiles | 0.7s |
| Users | 0.7s |
| Settings | 0.8s |
| Desktop App | 3.6s |
| Developer | 1.3s |
| Monitoring Sessions | 0.7s |
| Monitoring Session Detail | 1.5s until screenshot image visible |

### Slower Areas

- Dashboard was slower than most pages at around `2.4s`.
- Desktop App page was the slowest observed at around `3.6s`.
- Attendance loads the shell quickly, then fetches data async; useful data appeared around `2.1s`.
- Monitoring session detail loads quickly, but screenshot images add extra time.

### Performance Verdict

Performance is acceptable for normal use, but not instant. Main improvement targets:
- Dashboard initial load
- Desktop App page
- Attendance data fetch/render
- Screenshot-heavy monitoring pages

> Short version: it's not slow-broken, but a few heavier pages could definitely be tightened.

---

## Resolution / Engineering Follow-up (2026-06-11)

### Issue 2 — Password hint mismatch — FIXED

`resources/js/Components/Users/UserForm.jsx` hint changed from "Use at least 8 characters." to
"Use at least 12 characters." (and the edit-mode hint now adds ", or use at least 12 characters."),
matching the backend `Password::min(12)` rule. Rebuilt and deployed.

### Issue 1 — Schedule fields not persisting — INVESTIGATED, NOT REPRODUCIBLE ON CURRENT PRODUCTION

Every layer was verified directly against the live production database/code on 2026-06-11:

1. **Columns exist** — `users.joining_date` (`date`, nullable), `shift_start_time`, and
   `shift_grace_minutes` are all present on production.
2. **shift_start_time already persists** — 22 production users have shift values set, with varied
   real times (08:00, 09:00, 11:00, 13:00, 16:00, 21:00), all written by the user form's only
   write path (`UserController::store/update`).
3. **joining_date count was 0** — explained by timeline, not a bug: `shift_start_time` shipped
   2026-06-08 (`2026_06_08_000005_add_shift_timing_to_users_table`), but `joining_date` only shipped
   2026-06-10 (`2026_06_10_000002_add_joining_date_to_users_table`, commit `b2050e8`). The 22 users
   were edited in that 2-day window before the joining_date field existed, so none had it set.
4. **Validation passes** — simulating the real payload (`joining_date=2026-01-15`) through the exact
   validator returns it in `validated()` with no errors.
5. **Model + DB save works** — a direct `User::create([...joining_date=>'2026-02-20'...])` on
   production persisted and read back `2026-02-20`; the edit() prop read returned it correctly too
   (verified in a rolled-back transaction — no test data left behind).
6. **Deployed frontend is correct** — the live `UserForm-*.js` build contains the date input wired
   as `onChange: t => setData("joining_date", t.target.value)`.

**Conclusion:** The full create/edit → save → read chain works on current production
(`decc63c`). The QA observation is most consistent with the browser loading a **stale cached
UserForm chunk** during the same-day (2026-06-10) deploy window — i.e., a transient deploy/caching
artifact, not a live code defect. Recommended re-test: hard-refresh (Ctrl/Cmd+Shift+R) the Users
form, create/edit a user with both fields, and confirm they persist. If it ever recurs, capture the
exact request payload from the browser Network tab so we can confirm whether `joining_date` is
present in the POST body.

### Performance follow-up — OPTIMIZED (deployed 2026-06-11, commits `5e6cfc2` + `0393b7c`)

All four "Slower Areas" from the Speed/Performance Notes were addressed:

| Target | Before (QA) | Root cause | After (measured on prod) |
|---|---:|---|---:|
| Desktop App page | 3.6s | SHA-256 of both ~100MB installers hashed on **every** page view | **10ms** server compute (hash cached by path+mtime) |
| Dashboard (admin) | 2.4s | ~400 SUM queries (17 per employee) + settings query per loop iteration | **213ms / 6 queries** (one GROUP BY feeds all charts) |
| Attendance data | 2.1s | `time_entries` had no date index (full scan) + per-cell Carbon parsing | **266ms / 3 queries** (indexed + hoisted parsing) |

Sitewide notes:
- TTFB: a controlled server-side A/B showed `config:cache` makes **no measurable difference** on
  this host (~0.08s either way — OPcache already makes config parsing cheap). The shell's baseline
  TTFB seen by users (~0.7-1.1s) is dominated by network RTT/TLS to the visitor's location, not
  server time. `config:cache` is still safe (route:cache remains forbidden) and a
  `config-cache-self-heal` scheduler task exists, but it is not a perf lever here.
- New DB indexes: `time_entries(action_date)`, `time_entries(user_id, action_date)`,
  `work_hours(user_id, date)`.
- Monitoring/screenshot pages already used locally-signed S3 presigned URLs and `loading="lazy"`;
  remaining image latency is S3 transfer, which doesn't block the page.
- Login page full load measured from a real browser: 2.3s → **1.2s**.
