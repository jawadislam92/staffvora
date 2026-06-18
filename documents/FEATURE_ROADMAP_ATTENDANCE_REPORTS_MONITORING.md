# Feature Roadmap: Attendance, Reports, And Monitoring

Last updated: 2026-06-08

Important working rule: future feature changes should stay local for review and testing first. Do not push to GitHub unless Jawad explicitly asks for a push.

## 1. Monthly Attendance Visual View

Goal: create a spreadsheet-style monthly attendance page that is easier for HR/admins to read at a glance.

### Proposed Layout

- Month and year selector at the top.
- One row per employee.
- One column per day in the selected month.
- Status cells with compact codes:
  - `P` = Present
  - `A` = Absent
  - `H` = Holiday
  - `L` = Leave
  - `HD` = Half day
  - `WFH` = Work from home
  - `LI` = Late joining
  - `PH` = Public holiday
- Color-coded cells for quick scanning.
- Right-side summary columns:
  - Holidays
  - Present
  - Absent
  - Leave
  - Half day
  - Late joining
  - Work from home
  - Total work hours

### Manual Attendance Permissions

Manual marking should be permission controlled.

Allowed users:

- Super admin
- Admins with an assigned attendance edit permission

Suggested permissions:

- `attendance.view`
- `attendance.manage`
- `attendance.manual_mark`
- `attendance.export`

Members should only see their own attendance unless a specific permission is assigned.

Current implementation note:

- HR role is removed for now.
- Manual marking is allowed for Super Admin and admins/members who are explicitly assigned `attendance.manual_mark`.
- Each user can have a shift start time and grace period. If their first clock-in is later than shift start + grace, the monthly grid and Slack attendance report count that day as `LI`.

### Manual Marking Rules

- Manual statuses should be stored separately from raw clock-in/clock-out entries.
- The system should clearly show whether a day was calculated automatically or manually overridden.
- Manual changes should be logged with:
  - changed by
  - changed at
  - old value
  - new value
  - optional reason

### Open Questions

- Should `Half day` be automatic based on worked hours, or manually marked by Super Admin/admin?
- Should weekends/public holidays come from a holiday settings page?

## 2. Report Improvements

Goal: turn Reports into a proper management view, not only a list of raw entries.

### Proposed Report Tabs

- Detailed entries
- By user
- By client
- By work type
- By tracker/profile
- User x client summary
- User x work type summary

### Filters

All report tabs should share the same filter system:

- Date range
- Users
- Clients
- Work types
- Trackers/profiles
- Shift

### Client Summary

Show how many hours were spent for each client in the selected date range.

Columns:

- Client
- Total hours
- Users involved
- Entries count
- Top work type
- Top tracker/profile

### Work Type Summary

Show how many hours were spent in each work type.

Columns:

- Work type
- Total hours
- Users involved
- Clients involved
- Entries count

### User Total Issue

User total should not repeat on every detail row.

Better options:

- Show user total once in a grouped header row.
- Or show user total only in a separate summary table above details.
- Or show total only on the final row for each user.

Recommended approach:

- On the web report: group rows by user and show `User name - Total hours` as a group header.
- In Slack report: send a user totals table first, then optional detail rows without repeating the same total on every row.

## 3. Screenshot And Activity Monitoring

Goal: reduce reliance on manual integrity-only time entries by adding optional evidence-based tracking.

Reference idea: ScreenshotMonitor-style products use a desktop app that employees start/stop. While tracking, screenshots and activity context are uploaded for manager/employee review.

### Recommended Product Direction

Do not try to capture screenshots from the browser website itself. Browsers cannot reliably capture the user's desktop, active applications, or other screens for security reasons.

Build a separate lightweight desktop tracker app later.

Possible technology options:

- Electron desktop app
- Tauri desktop app
- Native Windows app first, then Mac later

### Desktop Tracker Workflow

1. Employee opens desktop tracker.
2. Employee logs in.
3. Employee selects client/project/work type/tracker.
4. Employee clicks Start.
5. App tracks time locally and sends activity to the Laravel backend.
6. App captures screenshots at configured intervals.
7. Employee clicks Stop.
8. Time entry, screenshots, and activity data are visible in the web dashboard.

### Screenshot Interval Options

Configurable by company or team:

- 1 minute
- 2 minutes
- 5 minutes
- 10 minutes
- random interval inside a range, for example every 5-10 minutes

Recommendation:

- Start with 5 or 10 minutes.
- Avoid 1 minute by default because it creates high storage cost and stronger privacy concerns.

### Activity Signals

Useful signals that can be tracked without recording keystroke content:

- mouse activity count
- keyboard activity count, but not actual keys
- idle time
- active app name
- active window title
- active website domain or URL, where technically possible
- selected client/project/work type

Do not collect:

- keystroke content
- webcam images
- microphone audio
- personal files

### Privacy And Trust Guardrails

This feature must be transparent, not hidden.

Recommended rules:

- Tracking only happens after employee clicks Start.
- Tracking stops after employee clicks Stop.
- Clear visible status in the desktop app.
- Employee can view their own screenshots.
- Admin/HR access is permission controlled.
- Store screenshots only for a configured retention period.
- Add option to delete/flag sensitive screenshots with review workflow.
- Add policy text before enabling the feature.
- Never capture when tracking is off.

### Storage Considerations

Screenshots can become expensive quickly.

Suggested controls:

- compress images before upload
- use thumbnails in dashboard
- store originals separately
- retention settings, for example 30/60/90 days
- object storage later, such as S3-compatible storage
- per-user/per-team screenshot limits

### Backend Data Model Ideas

Tables to consider:

- `tracking_sessions`
  - user_id
  - started_at
  - stopped_at
  - client_id
  - work_type
  - tracker/profile_id
  - total_minutes
  - activity_percent
  - status

- `tracking_screenshots`
  - tracking_session_id
  - user_id
  - captured_at
  - image_path
  - thumbnail_path
  - activity_percent
  - active_app
  - active_window_title
  - website_url/domain
  - is_flagged

- `tracking_activity_samples`
  - tracking_session_id
  - captured_at
  - keyboard_count
  - mouse_count
  - idle_seconds
  - active_app
  - active_window_title

### Dashboard Views

- Live active users
- Screenshot timeline per user
- Daily activity timeline
- Low activity alerts
- Missing screenshots warning
- Time tracked by client/work type/project
- Manual time vs tracked time comparison

### Permissions

Suggested permissions:

- `monitoring.view`
- `monitoring.manage`
- `monitoring.view_screenshots`
- `monitoring.delete_screenshots`
- `monitoring.settings`

Super admin gets all permissions.

HR/admin only get access if permission is assigned.

Members can view only their own tracked screenshots and sessions.

## 4. Suggested Implementation Order

Phase 1: reports cleanup

- Fix user total repeating.
- Add report summary tabs by client, work type, tracker, and user.
- Improve Slack report format to show totals once.

Phase 2: attendance visual grid

- Build monthly attendance grid.
- Add summary columns.
- Add HR/super-admin/manual-permission status editing.
- Add audit log for manual changes.

Phase 3: monitoring foundation

- Add backend tables for tracking sessions, screenshots, and activity samples.
- Add web dashboard screens without desktop capture first.
- Add settings for intervals, retention, and permissions.

Phase 4: desktop tracker prototype

- Build a small internal desktop app.
- Support login, start/stop, selected client/work type/tracker.
- Upload tracking sessions.
- Add screenshot capture later after policy and storage decisions are finalized.

## 5. Key Decisions Needed Later

- Should monitoring be required for all users or only selected teams?
- Should screenshots be every fixed interval or randomized?
- What retention period is acceptable?
- Who can view screenshots?
- Should employees be allowed to delete or request deletion of screenshots?
- Should manual work entries still be allowed after desktop tracking exists?
- Should manual entries be marked differently from verified tracked entries?
