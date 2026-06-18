# Attendance Day Bucketing — current behavior + saved future update

Last updated: 2026-06-12 (after commit `26bd264`)

## The problem this solves

Shifts cross midnight (4 PM–12 AM, 10 PM–6 AM, 12 AM–8 AM), so "which day does a
clock-in belong to?" cannot be the plain calendar date. The reported bug: a
12 AM-shift member clocked in at 11:50 PM, the date rolled over, their clock-in
"turned into clocked out" and they had to clock in again.

## CURRENT behavior (live in production) — per-user shift anchoring

Every clock action's attendance day is derived from that user's **shift start
time** (the Shift column on the Users page), in `User::attendanceDateFor()`:

1. **Early arrival** — clocking in up to **3 hours before** a shift start counts
   toward that shift's day. (Fixes the 11:50 PM → 12 AM shift case.)
2. **At/after today's shift start** — counts toward today.
3. **Overnight continuation** — actions up to **12 hours after** the previous
   day's shift start count toward that previous day (covers 4 PM–12 AM and
   10 PM–6 AM teams working past midnight).
4. **No shift time set** — plain calendar day (resets at midnight!).

Worked examples (all verified by automated tests in
`tests/Feature/TimeEntryOvernightShiftTest.php`):

| Shift | Action time | Counts toward |
|---|---|---|
| 08:00 (morning) | 7:45 AM clock-in | that same day ✔ |
| 08:00 (morning) | 11:50 PM (overtime) | that same day ✔ |
| 16:00 (evening) | 1:00 AM clock-out | previous day (shift's day) ✔ |
| 00:00 (night) | 11:50 PM clock-in (early) | the upcoming day ✔ |
| 00:00 (night) | 8:00 AM clock-out | same day as the clock-in ✔ |
| none set | anything | calendar day — **resets at midnight** |

The desktop app's Clock In/Out bar uses the same server logic, so it behaves
identically. The desktop **tracker** (sessions/screenshots) is unaffected by
midnight entirely — sessions are continuous and attributed to their start day.

### Action items for admins (no code needed)

- **Set a shift time for every user.** Users without one (e.g. "Flexible
  shift") still reset at midnight. Even an approximate time fixes that.
- **Audit stored shift times vs reality.** E.g. the night group is stored as
  21:00 — if they actually start at 10 PM, late-coming detection is one hour
  stricter than intended.

## SAVED FOR LATER — Option: global 8 AM → 8 AM business day

Owner request (2026-06-12): consider replacing per-user anchoring with one
company-wide business day running 8 AM to 8 AM next morning, so all three
shifts (8–4, 4–12, 12–8) always count toward the same business day.

Verdict from analysis: **workable and arguably the better long-term model**
(one global rule, no dependence on per-user shift columns, matches how the
company talks about days), but it MUST ship with three pieces together:

1. **Early-arrival grace at the 8 AM boundary** — otherwise a 7:45 AM clock-in
   for the morning shift (the company's largest arrival cluster) lands on
   yesterday's business day. Same grace mechanic as today's, applied at 8 AM.
2. **Open-pair inheritance** — any action while the user has an open clock-in
   inherits the clock-in's business day. Guarantees a clock-in/clock-out pair
   can never split across days (e.g. night-shift overtime ending 8:20 AM).
   Worth implementing even without the business-day switch — it makes the
   current scheme bulletproof too.
3. **Window-aware late/absence detection** — the attendance grid currently
   compares the first clock-in against `shift_start_time` on the cell's
   calendar date. Under business days, a 00:00 shift counted on Monday
   actually starts Tuesday 00:00; `isLateClockIn`/`isShiftAbsenceDue` in
   `EmployeeAttendanceController` (and `AttendanceSlackReportService`) must
   resolve the shift start within the business-day window
   [8 AM, next 8 AM) instead of naive cell-date + time.

Behavior change to communicate before shipping: night-shift hours (12–8) will
appear under the **previous** day in all reports/grids — "Tuesday" comes to
mean "Tue 8 AM → Wed 8 AM". Historical rows keep their stored dates; the rule
applies going forward only.

Estimated effort: 30–45 minutes including tests, all changes centralized in
`User::attendanceDateFor()`, `TimeEntryController`, `TimeClockController`,
`EmployeeAttendanceController`, `AttendanceSlackReportService`.
