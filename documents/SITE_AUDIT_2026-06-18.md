# SA Track — Full Site Audit & Improvement Plan
_Date: 2026-06-18 · Method: source-first (trace each symptom to its data source so one fix lands on every page)_

This is the master list. We work from here instead of page-by-page reaction.

---

## 0. The #1 issue (foundation): trustworthy data
Several machines log **time but no screenshots / apps / activity** because antivirus blocks the capture helpers (Bitdefender/Defender). Until that's closed, every report has blind spots. This outranks every cosmetic item.
- **P0 — Finish the AV exclusion rollout** on every machine (guide is on the Desktop App page).
- **P0 — "Tracker went silent" alert**: flag any employee clocked in / tracking who has sent **no screenshot or sample for N minutes**, so anomalies find you instead of you finding them. _(Designed, not built.)_

---

## 1. Cross-cutting (fix once, fixes everywhere)
| Item | State | Notes |
|---|---|---|
| Overnight tracked time split per calendar day | ✅ **FIXED (root)** | `syncWorkHour` now mirrors Timeline's `inDaySeconds`; 36 historical sessions backfilled. Report/Diary/Dashboard/Timeline now agree. |
| "Coverage %" could exceed 100% | ✅ **FIXED** | Replaced with **Activity %** (session activity score, weighted) on Dashboard + Team. |
| Live sessions read 0m | ✅ **FIXED** | Dashboard + Team count live elapsed, clamped to the day. |
| One vocabulary everywhere | ◑ partial | _In Office_ = presence, _Tracked_ = work, _Activity_ = focus. Applied on Dashboard + Team; **align Reports/Diary wording**. |
| Color/contrast system | ◑ partial | Empty Tracker pill fixed. **Remaining:** "Logged" badge low-contrast; Attendance grid has **no legend**; sweep for dim `slate-500` text. |
| Mobile / responsive | ☐ not assessed | Wide tables — verify horizontal scroll + small-screen layout. |
| Empty states & loading skeletons | ☐ review | Some pages show bare "No data"; standardize. |

---

## 2. Page-by-page

### Dashboard (`/dashboard`)
- ✅ Live-0m fixed, Activity % column, card subtitles + tooltips, status+shift sort on the team table.
- ☐ Night-shift rows show "In Office 10h" on a table titled **Today** — correct (their day started last evening) but confusing. **Add a small "shift day" hint** or split night vs day.

### Team Performance (`/team`)
- ✅ Live-first sort, search, day-clamped tracked, Activity %.
- ☐ **Date-range selector** (you asked) — "show this person over the week." _(Pending.)_
- ☐ Apps & URLs sub-tab — not yet reviewed.

### Timeline (`/timeline`)
- ✅ Day total labelled, month-strip auto-centers, clearer wording, overnight split (already correct here).
- ☐ Date area still stacks day-strip + calendar + arrows + Today — could simplify to one control.

### Reports (`/report`)
- ✅ Same-client/day **merge** (×N badge), empty Tracker pill fixed, overnight split now correct.
- ☐ "Logged" source badge contrast; align wording with the rest of the app.

### Work Diary (`/work-hours`)
- ✅ Now shows correct per-day hours (overnight split).
- ☐ **Merge decision**: keep entries editable but group under a client/day subtotal (recommended), vs hard-merge like Reports. _(Pending your call.)_

### Attendance (`/employee-attendance`)
- ☐ **No legend** for the status codes (LC / P / H / A / LI) — a CEO can't read the grid. **Add a legend.** _(New finding.)_
- ☐ Verify the code colors meet contrast; confirm what each code means and that they're consistent with the dashboard status vocabulary.

### Users (`/users`)
- ✅ Clean — search, role/designation filters, shift + grace, weekly tracked.
- ☐ Confirm "Weekly Tracked" uses the same (now-fixed) per-day source as everything else.

### Clients (`/clients`), Upwork Profiles (`/upwork-profiles`)
- ☐ Not visually audited this pass — review for consistency, color, empty states, and that client/tracker names render everywhere.

### AI Assistant (`/ai-assistant`)
- ☐ Review: does it answer from the corrected data? Confirm the AI uses per-day-accurate numbers now.

### Developer (`/developer`), Profile (`/profile`), Welcome (`/`)
- ☐ Quick pass for color/contrast and that the Developer page's rollback/env tools are clearly labelled.

---

## 3. Pending features we discussed (carry-overs)
- Work Diary grouped-but-editable merge.
- Team Performance date-range selector.
- "Tracker went silent" capture-health alert (P0).
- Color/contrast sweep (Attendance legend, Logged badge, dim text).
- Desktop app queue: break↔tracker pause coupling, shift-timing header, account switcher (Facebook-style, PIN deferred), in-app multi-device messages.

---

## 4. Prioritized roadmap
- **P0 (trust the data):** AV rollout · "tracker silent" alert · confirm all pages read the fixed per-day source.
- **P1 (clarity/CEO-readability):** Attendance legend · color/contrast sweep · Work Diary merge · wording alignment.
- **P2 (capability):** Team date-range · Apps&URLs review · AI-assistant data check · mobile/responsive pass.
- **P3 (desktop):** break/tracker coupling · shift header · account switcher.

---

## Already shipped this session (for the record)
Live-0m fix · day-clamped tracked · status+shift sort · search · Activity % (replaces Coverage) · dashboard card clarity · Timeline polish · Reports client-merge · empty-tracker-pill fix · **overnight work_hours split (root) + 36-session backfill** · attendance Slack (clock-in posts, auto-lockout posts, skip-if-tracking, 4-nudge cap) · AV exclusion guide on downloads page.
