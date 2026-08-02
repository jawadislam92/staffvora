# Tracksuite Desktop

Electron companion app for Tracksuite. It authenticates against the Laravel
API, starts and stops tracking sessions, stores a local retry queue, and can
capture screenshots, activity levels, and app/URL metadata when the workspace
has explicitly enabled those features.

## Local development

Requirements: Node.js 22, npm 10, a running Tracksuite web application, and the
native build prerequisites required by Electron modules on your operating
system.

```powershell
cd C:\laragon\www\tracksuite\desktop
npm install
npm run dev
```

The default local API URL is `http://tracksuite.test`. It can be changed from
the sign-in screen's Advanced section.

## Diagnostics & telemetry (0.4.0)

The agent reports its own health so a tracker that dies quietly — crashed
renderer, antivirus blocking screen capture, expired token, stuck upload queue —
is visible on the server instead of being discovered at payroll time.

- **Local:** `main/diag.js` writes `capture.log` under the app's userData folder
  (`users/<id>/capture.log` once someone signs in). Rotates at 1 MB to `.old`,
  so at most ~2 MB per employee.
- **Remote:** `main/report.js` buffers events and batches them to
  `POST /api/desktop/diagnostics` (Sanctum bearer token).

Request body — the tenant and user are derived server-side from the token; the
agent never sends an organization or user id:

```jsonc
{
  "device_name": "DESKTOP-VA7",              // string|null, <=120
  "events": [{
    "level": "info|warn|error",
    "event": "screenshot_capture_failed",    // <=80, from the fixed set below
    "message": "All capture methods failed", // string|null, <=2000
    "context": { "where": "scheduled" },     // object|null, shallow, <=12 keys
    "at": "2026-08-02T09:14:22.113Z",        // client clock — advisory only
    "app_version": "0.4.0",
    "platform": "win32"
  }]
}
```

Event names emitted: `app_ready`, `agent_state`, `signed_in`, `signed_out`,
`session_start`, `session_stop`, `pause`, `resume`, `time_clock`,
`screenshot_capture_failed`, `upload_failed`, `updater_error`,
`update_downloaded`, `window_unresponsive`, `render_process_gone`,
`child_process_gone`, `uncaughtException`, `unhandledRejection`,
`renderer_error`, `renderer_unhandled_rejection`, `renderer_boot_failed`,
`diagnostics_overflow`.

Guarantees the client enforces (see `scripts/report-selftest.js`): max 50 events
and 64 KB per request; buffer capped at 300 events (drop-oldest, and the loss is
itself reported as `diagnostics_overflow`); identical repeats coalesce into one
row with `context.repeat`; a 20s floor between requests no matter how many
callers ask to flush; exponential backoff 60s → 15 min with ±20% jitter after
consecutive failures; credential-looking context keys are dropped; and no
diagnostics failure can ever block or fail the clock/screenshot pipeline.
Worst case from one agent is therefore ~3 requests/min, so `throttle:6,1` on the
ingest route is comfortable headroom.

Steady-state volume per agent: ~1 `agent_state` row/hour plus whatever actually
goes wrong. Server-side retention/pruning is required — this table is designed
to be prunable.

## Machine compliance — endpoint inventory (0.4.6)

`main/collector.js` inventories what is installed and running on the PC so
automation tools, scrapers and anti-detect browsers that get an Upwork profile
banned are visible per machine and per employee, without visiting the machine.

**Windows only.** On macOS/Linux `collector.init()` logs a skip and schedules
nothing.

**Gating.** Access is decided entirely by the server's `machine_compliance`
entitlement. A workspace without it gets `403 feature_not_in_plan` from the
ingest, and the collector then stops collecting for 6 hours — no inventory is
gathered for a company that has not bought the feature. Signing in (or
restarting the app) clears the suppression immediately.

**Cadence.** First sweep 45s after launch, then hourly ±10 min of jitter, plus
`login` and `clock_in`, plus a 60s poll of each Chromium profile's
`Secure Preferences`/`Extensions` mtime that uploads within ~60s of an extension
being installed. A 5-minute floor applies between uploads whoever asks, and a
swallowed call re-arms rather than vanishing. The routine sweep is hourly, not
the source's 15 minutes, because at 500 orgs × 100 agents that would be ~55
uploads/second fleet-wide; the watcher keeps detection latency unchanged.

Request body — the tenant and user are derived server-side from the Sanctum
token; the agent never sends an organization or user id:

```jsonc
{
  "device_name": "DESKTOP-VA7",              // string|null, <=200
  "app_version": "0.4.6",                    // REQUIRED for the stale-agent warning
  "platform": "win32",
  "reports": [                               // <=6 blocks, one per kind
    { "kind": "extensions", "collected_at": "2026-08-02T09:14:22.113Z", "items": [
      { "name": "Auto Refresh Plus", "browser": "Chrome",
        "browser_profile": "Upwork Faryal",  // ⚠ NEVER a key named "profile"
        "id": "cnpni…", "version": "4.5.3", "enabled": true } ] },
    { "kind": "programs",  "collected_at": "…", "items": [
      { "name": "AnyDesk", "version": "8.0", "publisher": "AnyDesk Software GmbH" } ] },
    { "kind": "processes", "collected_at": "…", "items": [
      { "name": "chrome.exe", "detail": "C:\\…\\chrome.exe" } ] },
    { "kind": "network",   "collected_at": "…", "items": [
      { "name": "Ethernet", "detail": "Realtek Gaming 2.5GbE Family Controller" } ] }
  ]
}
```

Two source bugs are carried over **as fixes**:

- **Never a field named `profile`.** A repeated `profile` key is a standard
  ModSecurity HPP rule target; the host firewall answered the ENTIRE upload with
  a plain-text 403 before it reached PHP, silently losing all four inventories at
  once for over half the fleet. The wire field is `browser_profile`. For the same
  reason every string is scrubbed of angle brackets and control characters *on
  the client* — the server sanitises too, but the server is behind the firewall.
- **An unknown Chromium enablement shape counts as ENABLED.** Agent 0.4.5 tested
  `ext.state === 1`, which is `undefined` on current Chrome/Edge, so it reported
  zero extensions from every machine while the dashboard showed the fleet clean.
  The scan funnel (`ext=44/151`) is in every telemetry line so the same class of
  regression is visible instead of silent.

Blind vs clean: `extensions` and `programs` are reported **even when empty** —
the server treats that as "the collector could not see" and marks the machine
unknown rather than clean. `processes` and `network` are **omitted** when their
probe returned nothing, because those kinds are not blind-protected server-side
and an empty list would falsely clear a live VPN or jiggler finding.

Not collected, deliberately: file contents, browsing history, cookies, window
titles, keystrokes, clipboard, command lines, the signed-in Google/Microsoft
account (`gaia_name`/`user_name` sit right next to the profile label we do read),
extension `permissions`, and `from_webstore`. The last two were sent by the
source; the server stores neither and matching on permissions once mislabelled
every ad blocker as a VPN.

Guarantees the client enforces (see `scripts/collector-selftest.js`): all
filesystem reads async with a yield between browser profiles; PowerShell probes
one at a time with a 25s timeout; one run in flight at a time; per-kind item caps
mirroring the server (800 / 500 / 400 / 60) and a 900 KB body budget that sheds
processes first and extensions last; exponential backoff 5 min → 6 h with ±20%
jitter, `Retry-After` honoured on 429; and no failure here can block or fail the
clock/screenshot pipeline. Worst case from one agent is well under the ingest
route's `throttle:6,1`.

## Verification

```powershell
npm test                 # reporter + collector regression checks (plain node, no Electron)
npm run build:renderer
npm audit --omit=dev
```

Native activity and screenshot modules must also be exercised on clean Windows
and macOS machines before a release. A renderer build alone does not verify OS
permissions, installer behavior, auto-update, or capture quality.

## Packaging

```powershell
npm run dist
npm run dist:mac
```

Public releases must be code-signed. macOS builds must also be notarized. Keep
signing credentials out of this repository and provide them only through the
release environment.

The update feed is configured in `package.json`. Upload a release only after its
installer, update metadata, checksum, upgrade path, and rollback path have been
verified together.

## Privacy expectations

- Tracking data is collected only during an active session.
- The server's plan and workspace settings control screenshot and activity
  capabilities; the desktop defaults cannot elevate them.
- Users should be clearly told what is captured and should be able to see their
  own data.
- Never add keylogging, camera capture, or hidden background monitoring.
