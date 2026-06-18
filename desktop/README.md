# Timetracker Desktop

Electron desktop tracker for the Timetracker Laravel app. It signs in with a
Timetracker account, starts a tracking session, captures screenshots and
activity counts, queues uploads locally when offline, and sends everything to
the Laravel monitoring API.

The desktop app lives inside the main project:

```text
C:\laragon\www\timetracker\desktop
```

Read the main handoff first:

```text
C:\laragon\www\timetracker\documents\CODEX_COMPLETE_CHAT_AND_PROJECT_HANDOFF.md
```

## Privacy Rules

- Tracking only happens after the user clicks Start tracking.
- Capture stops immediately when the user clicks Stop tracking.
- The app records keyboard and mouse counts only, never keystroke contents.
- It does not use webcam, microphone, or personal file access.
- Local queued screenshots live in the OS user data folder, not in the repo.

## Prerequisites

- Laragon running.
- Timetracker web app reachable, usually `http://timetracker.test`.
- Node.js 18+ and npm.
- Visual Studio Build Tools for native Electron modules.
- Desktop dependencies installed with `npm install`.

## First-Time Local Run

From PowerShell:

```powershell
cd C:\laragon\www\timetracker\desktop
npm run dev:setup
```

This script:

1. Runs `php artisan migrate` in the parent Laravel app.
2. Checks that `http://timetracker.test/login` is reachable.
3. Runs `npm run rebuild` for Electron native modules.
4. Starts the Vite renderer and Electron app with `npm run dev`.

If your local site URL is different:

```powershell
cd C:\laragon\www\timetracker\desktop
powershell -ExecutionPolicy Bypass -File scripts/start-dev.ps1 -ServerUrl http://127.0.0.1:8000
```

## Repeat Runs

After native modules have already been rebuilt once:

```powershell
cd C:\laragon\www\timetracker\desktop
npm run dev:fast
```

This skips migrations and native rebuild, then launches the app.

## Manual Two-Window Run

Window 1:

```powershell
cd C:\laragon\www\timetracker
php artisan migrate
```

Window 2:

```powershell
cd C:\laragon\www\timetracker\desktop
npm run rebuild
npm run dev
```

`npm run rebuild` is needed after Electron, Node, or native dependency changes.
It compiles `better-sqlite3` and `uiohook-napi` against Electron's Node ABI.

## Sign In

Use the same account as the web app.

- Server URL: `http://timetracker.test` or your actual local URL.
- Email/password: web app credentials.
- Device name: any stable name, for example `Spark Laptop`.

Then choose a client, enter a description, and click Start tracking. Work type
and tracker/profile are attached to the client in the web app, so employees do
not choose them in the desktop tracker.

## View Captures

Open:

```text
http://timetracker.test/monitoring/sessions
```

Use your actual local URL if different. Click a session to view screenshot
thumbnails and session details.

## Test Screenshot Interval

The local testing migration
`database/migrations/2026_06_09_000006_set_testing_screenshot_intervals.php`
sets screenshots and activity samples to every 10 seconds for end-to-end
testing.

For realistic intervals, change `monitoring_settings` back to:

```php
App\Models\MonitoringSetting::current()->update([
    'screenshot_interval_min_seconds' => 300,
    'screenshot_interval_max_seconds' => 600,
    'activity_sample_interval_seconds' => 60,
]);
```

## Troubleshooting

- If `npm run rebuild` fails, install Visual Studio Build Tools with the C++
  workload, then rerun `npm run rebuild`.
- If login fails, verify the Server URL points to the Laravel app, not the Vite
  dev server.
- If screenshots do not appear, check the desktop app pending screenshot count
  and Laravel logs.
- If the monitoring page is forbidden, the user needs monitoring permissions.
- If uploads fail while offline, keep the desktop app running after reconnecting
  so the local SQLite queue can drain.

## Main Files

```text
main/main.js                 Electron entry point
main/api.js                  API client for /api/desktop/*
main/queue.js                SQLite offline queue
main/screenshotService.js    Primary screen capture
main/activityService.js      Keyboard/mouse counts and active window metadata
main/trackerService.js       Start/stop, timers, heartbeat, sync
preload/preload.js           Safe IPC bridge
renderer/src/views/Login.jsx Login screen
renderer/src/views/Tracker.jsx Tracker screen
scripts/start-dev.ps1        Repeatable local dev startup helper
```
