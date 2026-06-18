# Time Tracker Improvement Plan

Prepared for follow-up work on 2026-06-07.

## Goal

Improve the app one page at a time until it feels like a professional internal operations tool, not an AI-generated prototype.

The first focus should be the Work Hours page because it is already open, used often, and exposes the biggest UX issues: filters, tables, buttons, spacing, font sizes, and bulk actions.

## Current State

The app works, but the experience is not yet industry-level.

Main issues noticed:

- Page layouts are not fully consistent across modules.
- Filters are basic and sometimes force the user to clear everything instead of adjusting one filter.
- Dropdown filters are single-select where multi-select would be more useful.
- Some dropdowns do not support typing/searching.
- Buttons, spacing, table density, and font sizes need a more consistent design system.
- Access control is currently simple: mostly `admin` and `employee`.
- Admin routes are protected as one large group, so it is not easy to give selected access to selected users.

## Page-By-Page Approach

Work on one page at a time, in this order:

1. Work Hours
2. Work Hours Report
3. Dashboard
4. Employee Attendance
5. Users
6. Clients
7. Upwork Profiles
8. Profile and auth pages

Each page should be completed before moving to the next page.

For each page, we should check:

- Header layout
- Filter layout
- Table/list layout
- Empty states
- Loading states
- Buttons and actions
- Mobile layout
- Font sizes
- Color consistency
- Permission behavior
- Export/import behavior where applicable

## First Page: Work Hours

### Filter Improvements

The Work Hours page should support stronger filtering:

- Searchable dropdowns where the user can type inside the dropdown.
- Multi-select filters for fields like client, employee, work type, and date/status where useful.
- Active filter chips displayed above the table.
- Each chip should have its own remove button.
- Removing one filter should not reset all filters.
- Clear all should remain available as a separate action.
- Filters should preserve state when navigating/paginating.
- Filter labels should be human-readable.
- Dropdowns should handle long lists without becoming hard to use.

Example active filters:

- Client: ABC Company
- Client: XYZ Company
- Work Type: Development
- Employee: John
- Date: This Month

Each of these should be removable independently.

### Table Improvements

The Work Hours table should be easier to scan:

- Consistent row height.
- Smaller, more professional font sizes.
- Better alignment for date, time, total hours, and actions.
- Sticky or clearly visible table header if useful.
- Cleaner action buttons with icons.
- Better bulk-select behavior.
- Better empty state when no records match filters.
- Better pagination layout.

### Button Improvements

Buttons should follow one consistent style:

- Primary action: strong filled button.
- Secondary action: neutral outline/soft button.
- Destructive action: red, used only for delete/remove actions.
- Icon-only buttons should have tooltips.
- Button labels should stay short and action-based.

Examples:

- Add Entry
- Export CSV
- Apply
- Clear All
- Delete Selected

### Layout Improvements

The page should feel like a real work tool:

- Reduce oversized hero-like spacing.
- Use a compact page header.
- Use one consistent max-width or full-width layout.
- Keep filters visually attached to the data they affect.
- Avoid nested cards.
- Make table and actions visible without too much scrolling.

## Shared Design System Direction

Create reusable UI patterns so every page matches:

- Page header component
- Toolbar component
- Search input
- Multi-select dropdown
- Filter chip row
- Data table wrapper
- Empty state
- Confirm dialog
- Button variants
- Status badge
- Avatar/user cell

This will prevent every page from having its own style.

## Access Control Plan

The app should move from simple roles to role plus permissions.

### Implementation Status - June 6, 2026

Completed:

- Added `super_admin`, `admin`, and `member` roles.
- Migrated every legacy Admin to Super Admin and every Employee to Member.
- Added user-level JSON permission storage.
- Added centralized permission names and labels in `config/access.php`.
- Added backend permission middleware to protected routes.
- Updated controller checks for team dashboard data and all-work-hour management.
- Updated navigation and page actions to use effective permissions.
- Added a shared user create/edit form for roles and grouped permissions.
- Redesigned the Users page with compact layout, searchable multi-select filters, and removable chips.
- Protected Super Admin accounts from non-Super-Admin changes.
- Prevented the signed-in Super Admin from deleting or demoting their own account.
- Added automated access-control tests.

Implementation decision:

- Super Admin has every permission automatically.
- Admin and Member receive only permissions selected for that individual account.
- Management/export permissions automatically include the matching view permission.
- Only Super Admin can assign roles and permissions.

### Proposed Roles

#### Super Admin

Full access to everything.

Can:

- Manage all users
- Assign roles
- Assign permissions
- Access all reports
- Access all attendance
- Access all work hours
- Manage clients
- Manage profiles
- Delete records
- Export data
- Change system-level settings if added later

#### Admin

Limited management access based on assigned permissions.

Can be granted access to things like:

- View reports
- Manage users
- Manage clients
- View attendance
- Export work hours
- Edit employee work hours
- Manage Upwork profiles

Admin should not automatically have everything unless permission says so.

#### Member

Normal user/employee.

Default access:

- View own dashboard
- Track own time
- Add/edit own work hours
- Export own work hours if allowed

Optional permissions:

- View selected reports
- Manage selected clients
- View team attendance
- Approve/edit work hours
- Access specific modules

## Implemented Permission Names

Use clear permission keys:

- `users.view`
- `users.manage`
- `users.delete`
- `clients.view`
- `clients.manage`
- `clients.import_export`
- `dashboard.view_team`
- `work_hours.manage_all`
- `reports.view`
- `reports.export`
- `attendance.view`
- `attendance.export`
- `profiles.view`
- `profiles.manage`

## Access-Control Implementation Direction

Implemented technical path:

1. Keep role as a high-level label.
2. Store selected permissions as JSON on each user.
3. Use permissions for actual route/controller access.
4. Show only navigation and actions the user can access.
5. Protect backend routes and controller methods, not only frontend links.

Important: frontend hiding is not security. Permissions must be checked in Laravel too.

## Slack Weekly Report Integration

Add Slack reporting as an admin/reporting feature after the core report page is cleaned up.

Slack can be used in two ways:

- Bot token with `chat.postMessage`
- Incoming webhook for simpler one-channel posting

Recommended approach for this app: use a Slack App bot token because it gives more flexibility for channels, future direct messages, and report types.

Official Slack references:

- `chat.postMessage`: https://docs.slack.dev/reference/methods/chat.postMessage/
- Incoming Webhooks: https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/

### Manual Slack Report

Manual sending should live inside the Reports page or a Slack panel inside the admin area.

Manual report requirements:

- Admin selects a start date.
- Admin selects an end date.
- Admin can choose full team or selected user.
- Admin can choose destination channel if multiple channels are configured.
- System calculates hours for the selected date range.
- System previews the message before sending.
- Admin clicks `Send to Slack`.
- Backend sends the message to Slack.
- App shows success or failure message.

Example manual message:

```text
Weekly Hours Report
Date Range: June 1 - June 7

Ramish Mahmood - 38.5 hours
Naila Yaqoob - 42 hours
Basit Ahmad - 31 hours
Nimra Khan - 36.25 hours

Total Team Hours: 147.75
```

Manual report permissions:

- `slack_reports.send`
- `slack_reports.preview`
- `slack_reports.configure`

Only Super Admin should configure Slack credentials. Admins can send reports only if they have permission.

### Automatic Weekly Slack Report

Automatic weekly report should run every Sunday at 10:00 AM.

Schedule:

```text
Every Sunday at 10:00 AM
→ calculate weekly hours
→ format team summary
→ send to configured Slack channel
→ log success/failure
```

Important implementation note:

- Confirm whether Sunday 10:00 AM means Pakistan time.
- Store timezone explicitly, for example `Asia/Karachi`.
- Use Laravel Scheduler for the weekly job.
- Production server cron must call Laravel scheduler every minute.

Suggested `.env` values:

```env
SLACK_REPORTS_ENABLED=true
SLACK_BOT_TOKEN=
SLACK_WEEKLY_REPORT_CHANNEL=
SLACK_WEEKLY_REPORT_TIME=10:00
SLACK_WEEKLY_REPORT_DAY=sunday
SLACK_REPORT_TIMEZONE=Asia/Karachi
```

Suggested backend pieces:

- `SlackReportService`
- `WeeklyHoursReportService`
- `SendWeeklySlackReport` scheduled command
- `SlackIntegrationController`
- `slack_report_logs` table

### Slack Settings Page

Add a Slack Integration Settings page later.

Fields:

- Enable Slack reports
- Bot token status
- Default channel ID
- Weekly report day
- Weekly report time
- Report timezone
- Manual send permission
- Test Slack connection button
- Send test message button

Do not show the raw Slack token in the frontend after it is saved.

### Slack Report Logs

Store a history of sends:

- Sent by user
- Date range
- Destination channel
- Report type
- Success/failure
- Slack response timestamp if available
- Error message if failed
- Created date/time

This helps debug failed Sunday reports.

### Security Rules

- Never put Slack token in frontend code.
- Do not expose token through Inertia props.
- Store token in `.env` first; encrypted database storage can be added later.
- Only Super Admin can update Slack settings.
- Manual send must require backend permission.
- Log every manual and automatic Slack send.

## Remaining Questions To Confirm

These should be answered before implementing the access-control redesign:

- Should clients be assignable to specific members/admins?
- Should work hours require approval before becoming final?
- Should deleted records be permanently deleted or soft deleted?
- Should exports be available to members, admins only, or permission-based?
- Should Sunday 10:00 AM Slack reports use Pakistan time (`Asia/Karachi`)?
- Should Slack reports include only approved/final work hours if approval is added later?
- Should Slack reports go to one channel only, or should each team/client have its own channel?
- Should manual Slack reports require preview before sending?

## Tomorrow's Recommended Work Session

Start with Work Hours page only.

Suggested task list:

1. Audit current Work Hours UI.
2. Define the reusable filter component shape.
3. Build searchable multi-select dropdown.
4. Add active filter chips with single-filter remove.
5. Improve Work Hours page spacing, table, and buttons.
6. Test desktop and mobile layout.
7. Then decide whether the same filter system should be reused on Reports, Users, and Clients.
8. After Reports are improved, add manual Slack report sending for selected date ranges.
9. After manual Slack sending is stable, add the automatic Sunday 10:00 AM scheduled report.

## Success Criteria

The Work Hours page is considered improved when:

- Filters are searchable.
- Multi-select works where needed.
- Individual filters can be removed one at a time.
- Clear all still works.
- Table is easier to read.
- Buttons look consistent.
- Mobile layout is usable.
- The page visually matches the app shell.
- No backend security behavior is weakened.
