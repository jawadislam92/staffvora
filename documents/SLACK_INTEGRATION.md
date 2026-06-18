# Slack Weekly Reports

The Report page can send a selected date range to the Slack channel connected
to the configured incoming webhook.

## Manual Report

Open `Report` and select `Send to Slack`. The dialog lets the sender choose:

- Start and end dates
- One or more users
- Client
- Work type
- Tracker
- Hours
- User total (selected by default)

The Slack selections are independent from the filters on the Report page.
User names are always included. The selected dimensions become columns in a
native Slack table. Rows are grouped by the selected dimensions, and hours are
summed for each group when the Hours field is enabled. User Total shows each
person's complete hours for the selected period beside their rows.

Slack allows one table and up to 100 rows per message. The report reserves rows
for its header and total, then shows a notice if additional grouped rows were
omitted.

## Environment

```env
SLACK_REPORT_WEBHOOK_URL=
SLACK_WEEKLY_REPORT_ENABLED=true
SLACK_REPORT_TIMEZONE=Asia/Karachi
```

The webhook must only be stored in the server `.env` file. Do not place it in
frontend code or commit it to Git.

## Access

Super Admins can send Slack reports automatically. Admins and Members need the
`Send work-hour reports to Slack` permission.

## Automatic Report

Laravel schedules `reports:send-weekly-slack` every Sunday at 10:00 AM in the
configured timezone. It reports the last seven completed days, ending Saturday.

The production server must run Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/timetracker && php artisan schedule:run >> /dev/null 2>&1
```

The command can also be invoked manually:

```bash
php artisan reports:send-weekly-slack
php artisan reports:send-weekly-slack --start=2026-06-01 --end=2026-06-07
```
