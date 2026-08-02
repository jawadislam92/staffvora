# Staffvora - Phase 4 Port Plan (timetracker -> Staffvora)

> Auto-generated from the repo feature-delta analysis (62 deltas). SOURCE: C:\laragon\www\timetracker (mature, ~1 month ahead). TARGET: C:\laragon\www\Staffvora (multi-tenant fork). Bring each source-only/newer capability into the fork, adapt for tenancy (organization scoping), then improve to industry standard. Port order: P0 -> P1 -> P2.

## P0 (10 items)

### Break-aware tracker-health watchdog (SilentTrackerCheck)
- **Area:** Console command + Support | **Effort:** M | **Target status:** older | **Dim:** services-jobs
- **Evidence:** SOURCE app/Console/Commands/SilentTrackerCheck.php (156 lines): skips AV-alert when TimeEntry::currentClockState($user)==='break_start', distinguishes PAUSED vs BLOCKED tracker, and records App\Support\TrackerHealthDiagnostics::capture(...). SOURCE app/Support/TrackerHealthDiagnostics.php exists.
- **Port:** TARGET SilentTrackerCheck.php is only 96 lines with ZERO break markers — it will Slack-alert 'antivirus block' every time someone on break stops capturing (the exact false-alarm the source fixed). Replace target command body with source logic; also port App\Support\TrackerHealthDiagnostics.php (file-based, uses storage_path). Scope the clocked-in/session lookups per tenant.

### Machine Compliance flag ledger (machine_flags table + MachineFlag model)
- **Area:** Database/Models | **Effort:** M | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_07_18_000001_create_machine_flags_table.php; app/Models/MachineFlag.php. Columns: user_id, device_name, kind, rule, severity, alert(bool), label, signature, status(open|acknowledged|ignored|resolved), first_seen_at, last_seen_at, alerted_at, resolved_at, acknowledged_by(users FK), acknowledged_at, note, app_version, platform; unique(user_id,device_name,signature); index(status,severity...
- **Port:** Copy migration + model. Add organization_id (nullable, indexed) + OrganizationScope. The unique(user_id,device_name,signature) key stays valid because user_id already scopes to a tenant, but consider adding organization_id to the unique tuple for safety. Acknowledge/Ignore workflow is manager-facing so also needs the operator/tenant admin UI, but the DB layer is self-contained.

### Machine Compliance endpoint inventory (machine_reports table + MachineReport model)
- **Area:** Database/Models | **Effort:** M | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_07_17_000001_create_machine_reports_table.php; app/Models/MachineReport.php. Columns: user_id, device_name, kind(extensions|programs|processes|network|history), collected_at, items(json), flagged(json), item_count, flagged_count, app_version, platform; indexes (user_id,kind,created_at),(device_name,kind),flagged_count. Casts items/flagged as array.
- **Port:** Copy migration + model verbatim. Add nullable indexed organization_id and register table in OrganizationScope so each tenant only sees its own machines' inventory. This is the storage layer for the per-PC extension/program/process/VPN compliance dashboard.

### Archive/deactivate for users and clients (is_active columns)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_29_000002_add_is_active_to_users_table.php (boolean is_active default true) + 2026_06_29_000001_add_is_active_to_clients_table.php (boolean is_active default true, indexed). TARGET grep 'is_active' only hits upwork_profiles default, not users/clients.
- **Port:** Add is_active to users and clients (index on clients). Backs the Active/Archived toggle: auth lockout + hide-from-active lists + bulk archive. Inherits tenant scope. Ensure login/guard checks and default query scopes honor is_active. Note profiles/designations archiving may also be part of the feature set in source controllers.

### Machine Compliance endpoint-inventory collector (browser extensions, installed programs, running processes, network adapters/VPN, anti-detect browsers)
- **Area:** desktop | **Effort:** L | **Target status:** absent | **Dim:** desktop
- **Evidence:** SOURCE desktop/main/collector.js (220 lines; PowerShell -EncodedCommand inventory, isExtensionEnabled via disable_reasons, run('login'|'clock_in') debounced); api.js sendMachineReport() -> POST /api/desktop/machine-report; main.js wires collector.run() 45s after launch + hourly; ipc.js runs collector on login (5s) and clock_in (3s). Server side: routes/api.php desktop.machine-report.store (DesktopMachineReportControl...
- **Port:** Port collector.js verbatim plus api.sendMachineReport() and the main.js/ipc.js scheduling hooks. Bring the 0.4.5-0.4.7 fixes with it: enabled-detection reads disable_reasons (array/bitmask) with state fallback and defaults-to-enabled (never silently drop), and DO NOT send the Chrome profile-directory-name field (Hostinger/ModSecurity 403 on repeated `profile` body key). Multi-tenant: the /machine-report server controller and machine_reports/machine_flags storage + blocklist must be scoped per workspace/tenant (desktop payload is unchanged since it authenticates via the /api/desktop token which resolves the tenant server-side).

### start() double-start race guard (shared-PC inflated/impossible time fix)
- **Area:** desktop | **Effort:** S | **Target status:** older | **Dim:** desktop
- **Evidence:** SOURCE desktop/main/trackerService.js: synchronous this._starting re-entrancy flag set before the awaits in start(); guard `if (this.session || this._starting) throw new Error('A session is already running')` (line ~214), released after session object exists (line ~267). TARGET trackerService.js line 107 has only `if (this.session) throw ...` with no _starting flag — the async window between start() awaits is unguard...
- **Port:** Add the _starting boolean field (constructor), set it synchronously at the top of start() before any await, include it in the already-running guard, and clear it once this.session is assigned and in the catch/finally. Pure client-side; no multi-tenant change.

### Machine Compliance dashboard (per-PC extension/program/process/VPN inventory + blocklist + flag/remove)
- **Area:** React UI — Pages/Monitoring | **Effort:** L | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/Monitoring/Compliance.jsx (410 lines): props {tools, machines, employees, extensions, summary, lastReport}; view tabs action|people|software|machines; VPN_PROXY label map; routes monitoring.compliance.flag and monitoring.compliance.machine.destroy. No equivalent file/route in TARGET (target Monitoring has only SessionDetail.jsx + SessionsList.jsx; grep for 'monitoring.compliance' / a Complia...
- **Port:** Port Compliance.jsx wholesale. Depends on backend compliance controller + routes (monitoring.compliance.*) which must also be ported. Multi-tenant adaptation: scope tools/machines/employees/extensions queries to the current tenant/company_id and namespace routes under the tenant guard; gate via existing permissions prop pattern.

### Machine Compliance ingest + dashboard (per-PC extension/program/process/VPN + anti-detect browser inventory, blocklist, flags)
- **Area:** Routes+Controllers | **Effort:** L | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE app/Http/Controllers/Api/Desktop/MachineReportController.php (217 lines, POST /api/desktop/machine-report -> desktop.machine-report.store); SOURCE app/Http/Controllers/ComplianceController.php (294 lines) with index/updateFlag/destroyMachine; web.php routes monitoring.compliance, monitoring.compliance.flag (PATCH /compliance/flags/{flag}), monitoring.compliance.machine.destroy (DELETE /compliance/machine); api...
- **Port:** Port both controllers + the desktop ingest route (api.php) and the 3 monitoring.compliance web routes (gated permission:monitoring.settings). Multi-tenant: scope machine reports and compliance flags to organization/tenant_id; ensure desktop sanctum route sits inside the tenant.usable middleware group already used by other desktop routes. Depends on backing migrations/models (machine reports, blocklist, flags) from the migrations dimension.

### Archive / deactivate (Active vs Archived) for users, clients and upwork profiles — single + bulk status toggle
- **Area:** Routes+Controllers | **Effort:** M | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php: users.bulk-status (POST /users/bulk-status -> UserController@bulkStatus), users.set-status (PATCH /users/{user}/status -> setStatus); clients.bulk-status + clients.set-status (ClientController@bulkStatus/setStatus); upwork-profiles.bulk-status + upwork-profiles.set-status (UpworkProfileController). All gated permission:users.manage / profiles.manage
- **Port:** Add bulkStatus + setStatus methods to target UserController, ClientController, UpworkProfileController and register the 6 routes. Multi-tenant: status queries must be tenant-scoped; ensure archived users are locked out of auth and hidden from active lists per-tenant. Pairs with migration adding status/archived_at columns.

### Machine Compliance ingestion + automation blocklist
- **Area:** Support + config + ingestion command/endpoint | **Effort:** L | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Support/AutomationBlocklist.php (Upwork-flagging extension/scraper/jiggler/VPN/proxy signatures), app/Http/Controllers/Api/Desktop/MachineReportController.php (ingests extensions/programs/processes/vpn inventory), app/Http/Controllers/ComplianceController.php (dashboard scan of kinds extensions|programs), app/Models/MachineFlag.php + MachineReport, migrations 2026_07_17_000001_create_machine_reports_table....
- **Port:** TARGET has NO machine_reports/machine_flags migrations, no AutomationBlocklist, no compliance code (grep of app only hits marketing DefaultSiteContent.php). Port the blocklist/support + ingestion endpoint + models + both migrations; add tenant_id to machine_reports/machine_flags and scope the compliance dashboard + Slack compliance_channel per tenant. Large because it spans desktop API + dashboard, but the background pieces are the blocklist, models, migrations, and compliance_channel config.

## P1 (33 items)

### Installer CDN hosting + min-agent-version gating (config/desktop.php)
- **Area:** Config | **Effort:** S | **Target status:** partial | **Dim:** services-jobs
- **Evidence:** SOURCE config/desktop.php defines 'downloads_base_url' (302 installer downloads to S3/CloudFront so ~90MB .exe downloads don't get cut on shared hosting) and 'min_agent_version' (default 0.4.6 — warns when compliance data comes from agents too old to report extensions).
- **Port:** TARGET config/desktop.php has ONLY 'installers_path' — missing downloads_base_url and min_agent_version. Port both keys plus the controller 302-redirect logic that consumes downloads_base_url. In multi-tenant, downloads_base_url can stay global (single installer CDN); min_agent_version stays global.

### Double-start inflated-tracking repair (tracking:repair-inflated)
- **Area:** Console command | **Effort:** S | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Console/Commands/RepairInflatedTrackingSeconds.php (signature 'tracking:repair-inflated' — clamps tracking_sessions whose total_seconds exceed real elapsed wall-clock, backs up first).
- **Port:** No tracking:repair-inflated in TARGET. Port the command; it is the server-side cleanup for the confirmed desktop start() double-start race. Add tenant scoping to the tracking_sessions query and back-up table.

### action_date mis-filing guard + repair (diagnostics)
- **Area:** Console command + Support | **Effort:** M | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Console/Commands/RepairAttendanceActionDates.php (signature 'attendance:repair-action-dates' — refiles punches whose stored action_date != attendanceDateFor(action_timestamp)); app/Console/Commands/AttendanceDiagnosticsCommand.php (signature 'attendance:diagnostics'); app/Support/AttendanceDiagnostics.php; app/Support/AttendanceHours.php.
- **Port:** None of these command signatures or support classes exist in TARGET (no attendance:repair-action-dates, no attendance:diagnostics, no Support/AttendanceDiagnostics). Port the repair command, the diagnostics capture/read command, and AttendanceDiagnostics/AttendanceHours support. Run per-tenant via tenant:run; scope repairs by tenant to avoid cross-tenant writes.

### Unified diagnostics framework + tracker-diagnostics viewer
- **Area:** Console command + Support | **Effort:** M | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Console/Commands/DiagnosticsCommand.php (signature 'diagnostics' — errors + domain events in one place), app/Support/Diagnostics.php (captures across app), app/Console/Commands/TrackerDiagnosticsCommand.php (signature 'monitoring:tracker-diagnostics').
- **Port:** TARGET has neither the diagnostics/monitoring:tracker-diagnostics commands nor Support/Diagnostics. This is the capture-and-review substrate the tracker-health and action_date fixes write into, so port it alongside/ before those. Make the capture store tenant-tagged.

### action_date/timestamp mis-filing guard (fix_action_timestamp_on_update)
- **Area:** Database | **Effort:** S | **Target status:** partial | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_27_000002_fix_action_timestamp_on_update.php. TARGET has its own 2026_06_30_000001_fix_tracking_timestamp_columns_on_update.php which may cover overlapping ground.
- **Port:** Diff the two timestamp-on-update fixes column-by-column. The source fix targets the action timestamp tied to the clock-punch action_date mis-filing bug (Aqsa case in memory). Confirm the target's tracking-timestamp fix covers the same columns/tables (time_entries/attendance action timestamp); if not, port the source fix. Pairs with the write-mechanism guard logging still open in source.

### Multi-window manual work-hours (work_hour_windows child table + WorkHourWindow model)
- **Area:** Database/Models | **Effort:** M | **Target status:** partial | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_25_000001_create_work_hour_windows_table.php (work_hour_id FK cascade, start_at datetime, end_at datetime, index work_hour_id); app/Models/WorkHourWindow.php (belongsTo WorkHour). TARGET instead added single started_at/ended_at columns directly on work_hours via 2026_06_21_140000_add_time_range_to_work_hours.php + monitoring_settings.manual_time_overlap_policy.
- **Port:** DESIGN DIVERGENCE, not a clean copy. Source models ONE manual entry -> MANY concrete clock windows (e.g. 2:30-3:30 and 4:40-6:40) with work_hours.hours as the denormalised sum; target only supports a SINGLE started_at/ended_at range per entry. To reach parity port the work_hour_windows table + model and migrate the target's single-range data into one window row per entry, keeping work_hours.hours authoritative. Add organization_id to work_hour_windows (or scope via parent work_hour). Reconcile with target's manual_time_overlap_policy (which the source expresses through window gap validation).

### Feedback inbox with threaded messages (feedback_items + feedback_messages tables + models, read-receipt columns)
- **Area:** Database/Models | **Effort:** M | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_28_000002_create_feedback_items_table.php (user_id, type, subject, message, context json, status, response, handled_by, handled_at + later response_seen_at via 2026_06_29_000003 + manager_seen_at) and 2026_06_30_000001_create_feedback_messages_table.php (feedback_item_id FK, user_id, body, index); app/Models/FeedbackItem.php, FeedbackMessage.php. TARGET grep 'feedback_items'/'respon...
- **Port:** Port both create migrations plus the response_seen_at add-migration, and fold manager_seen_at in. Include the backfill that migrates each feedback_items.response into the first feedback_messages row. Add organization_id to both tables + OrganizationScope so tenants only see their own inbox. Two-directional unread badges rely on response_seen_at (submitter) + manager_seen_at (manager).

### Help center knowledge base (help_articles table + HelpArticle model)
- **Area:** Database/Models | **Effort:** M | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_28_000001_create_help_articles_table.php; app/Models/HelpArticle.php. Columns: title, slug unique, category, body(text), keywords, admin_only(bool), sort_order, is_published(bool); index(is_published,category); scopePublished. DB-backed searchable KB (no AI credit for basic lookups). TARGET grep 'help_articles' returns nothing.
- **Port:** Copy migration + model. For multi-tenant decide split: platform-global articles (organization_id null) plus optional per-tenant articles (organization_id set); adjust slug unique to composite (organization_id, slug) if per-tenant. Port the scored search logic from the source HelpController alongside.

### Per-worker work timezone (users.work_timezone)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_27_000001_add_work_timezone_to_users_table.php: string work_timezone(64) default 'Asia/Karachi' after shift_grace_minutes. TARGET grep 'work_timezone' returns nothing (target has org-level timezone via 2026_06_30_000002_add_timezone_to_organizations + display_timezone, but not per-user work timezone).
- **Port:** Add the column to users (inherits tenant scope). In multi-tenant, default should derive from the organization's timezone rather than hard-coded Asia/Karachi. Drives which attendance day a punch buckets to and auto-clockout timing per worker.

### Non-tracking users flag (users.tracks_time)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_28_000003_add_tracks_time_to_users_table.php: boolean tracks_time default true. TARGET grep 'tracks_time' returns nothing.
- **Port:** Add boolean column to users. Lets a workspace hold members who don't run the desktop tracker (e.g. managers) without polluting tracker health/attendance. Inherits tenant scope.

### Break-aware watchdog probe (tracking_sessions.health_probe_seconds)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_24_000001_add_health_probe_seconds_to_tracking_sessions.php: unsignedInteger health_probe_seconds nullable after health_alerted_at. TARGET grep 'health_probe' returns nothing (health_alerted_at exists in both via 2026_06_18).
- **Port:** Add column to tracking_sessions (inherits tenant scope). Part of the break-aware tracker-health watchdog that stops false AV-block Slack alerts during breaks; port with the health/diagnostics service logic.

### Named Shifts lookup + users.shift_id (shifts table + Shift model + FK)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_07_05_000001_create_shifts_table.php (id, name unique, sort_order, timestamps; seeds Morning/Noon/Evening/Night) + 2026_07_05_000002_add_shift_id_to_users_table.php (users.shift_id nullable FK after shift_start_time); app/Models/Shift.php (hasMany User). TARGET grep for 'shifts' create + 'shift_id' returns nothing.
- **Port:** Port both migrations + Shift model. shifts is a curated pick-list distinct from shift_start_time/shift_hours (which already exist in target). Decide per-tenant: either add organization_id to shifts so each org curates its own labels (recommended for multi-tenant), or keep global. users.shift_id inherits tenant scope from users. Seed defaults per new organization instead of once globally.

### Per-day shift overrides (user_shift_overrides table + UserShiftOverride model)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_26_000001_create_user_shift_overrides_table.php; app/Models/UserShiftOverride.php. Columns: user_id, date, shift_start_time(time), shift_hours(decimal 4,2), reason, created_by(users FK); unique(user_id,date). One-day schedule change affecting attendance-day bucketing and auto-clockout only.
- **Port:** Copy migration + model. Add organization_id (nullable indexed) + OrganizationScope. Depends on the attendance-day/auto-clockout logic that reads overrides, so port alongside the scheduler/attendance service that consumes it.

### Per-employee storage isolation on shared PCs (per-user queue.sqlite + pending-screenshots)
- **Area:** desktop | **Effort:** M | **Target status:** older | **Dim:** desktop
- **Evidence:** SOURCE desktop/main/config.js exports userPaths(userId) -> users/<safeId>/queue.sqlite + pending-screenshots (0.3.7+ so one employee's pending items can't be jammed by the next login); queue.setUser/diag.setUser called from ipc.js on login. TARGET config.js has only the flat shared paths.queueDb/screenshotsDir and no userPaths; target queue has no per-user scoping.
- **Port:** Port userPaths() and the queue.setUser()/diag.setUser() calls on login/remembered-user. Important for kiosk/shared-PC tenants. No server change; purely local path scoping.

### Client diagnostics/telemetry pipeline (capture.log + buffered event reporter to server)
- **Area:** desktop | **Effort:** M | **Target status:** absent | **Dim:** desktop
- **Evidence:** SOURCE desktop/main/diag.js (rotating per-user %AppData%/SA Track/capture.log, setUser scoping) and report.js (buffered info/warn/error events, flush batch of 50 via api.sendDiagnostics -> POST /api/desktop/diagnostics, 300 cap). main.js: uncaughtException/unhandledRejection -> report.error, report.flush() every 60s. Server: routes/api.php desktop.diagnostics.store (DesktopDiagnosticsController), routes/web.php devel...
- **Port:** Port diag.js + report.js and api.sendDiagnostics(); wire report.flush() interval and the crash handlers in main.js. Multi-tenant: the /diagnostics server endpoint + Developer diagnostics viewer must be tenant-scoped. This is a prerequisite dependency for the AV-safe screenshot, updater-error, and collector reporting deltas (they all call report/diag).

### AV-safe in-process screenshot capture (desktopCapturer primary, screenshot-desktop fallback) with antivirus/GPU block detection
- **Area:** desktop | **Effort:** M | **Target status:** older | **Dim:** desktop
- **Evidence:** SOURCE desktop/main/screenshotService.js (184 lines): primary path uses Electron desktopCapturer in-process (no helper exe for Defender/Bitdefender ASR/Controlled-Folder-Access to block), stitches multi-monitor, falls back to screenshot-desktop only on failure, detects all-black/empty thumbnails ('GPU disabled or screen-capture blocked'), and logs every step via diag. TARGET screenshotService.js (68 lines) + imageSer...
- **Port:** Replace the target's screenshot-desktop-only path with the source two-tier capture (desktopCapturer first, legacy fallback) and the empty/black-frame detection. Depends on the diag module being ported first for the capture.log trail.

### Break-aware pause / timer (distinguish idle vs break vs manual; heartbeat on_break reconcile; timer never advances during a break)
- **Area:** desktop | **Effort:** M | **Target status:** older | **Dim:** desktop
- **Evidence:** SOURCE trackerService.js: _pauseSession takes pauseReason 'idle'|'break'|'manual'; pauseForBreak() idempotent; auto-resume applies ONLY to idle pauses (a break/manual stays paused, line ~479-487); heartbeat drain reconciles a web-dashboard break -> pauses tracker on the pause edge and never auto-resumes on on_break=false (lines ~606-611); paused ticks record nothing and keep the clock frozen. TARGET trackerService.js...
- **Port:** Port the pauseReason parameter and pause_reason session field, the break-vs-idle auto-resume distinction, pauseForBreak()/resume() idempotency, and the heartbeat on_break reconcile in the sync drain. Server heartbeat response already returns on_break; ensure the multi-tenant heartbeat controller still emits it per workspace break rules.

### Graceful stop-then-quit + Restart-now hang fix (before-quit finalizer with 4s guard + powerMonitor shutdown)
- **Area:** desktop | **Effort:** S | **Target status:** absent | **Dim:** desktop
- **Evidence:** SOURCE main.js: stopThenQuit() on 'before-quit' and powerMonitor 'shutdown' sends a final tracker.stop() with a hard 4s Promise.race guard so a hung/offline stop still reaches app.quit() — comment notes this is exactly what made 'Restart now' silently do nothing and leave the update pending (lines ~86-110). TARGET main.js (156 lines) has only window-all-closed -> app.quit() (lines 56-58); no before-quit handler, no g...
- **Port:** Port the stopThenQuit finalizer, register it on app before-quit and powerMonitor.shutdown, keep the 4s race guard. Fixes both dangling server sessions on shutdown and the auto-update 'Restart now' no-op. Server stop endpoint already multi-tenant via token.

### Auto-updater hardening: disable differential download + report updater errors to diagnostics
- **Area:** desktop | **Effort:** S | **Target status:** older | **Dim:** desktop
- **Evidence:** SOURCE desktop/main/updater.js: `autoUpdater.disableDifferentialDownload = true` (block-based diff fails to reassemble the NSIS installer on the host and loops 'downloads a bit, disappears, restarts'); on('error') -> report.warn('updater_error', ...). diff against TARGET updater.js shows target LACKS the disableDifferentialDownload line and its error handler is an empty `on('error', () => {...})` with no report requi...
- **Port:** Add disableDifferentialDownload=true and require('./report') to surface updater errors. Ensure the packaged feeds omit .blockmap as a belt. Keep the feed URL/appId tenant-specific (do not copy timetracker.sparkingasia.com). Depends on report.js being ported.

### Client archive / restore (per-row + bulk, Archived badge, status filter)
- **Area:** React UI — Pages/ClientsList | **Effort:** S | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/ClientsList.jsx lines 42-541: status option 'archived', route('clients.set-status'), bulk archive/restore toasts, per-row toggle, Archived pill. TARGET ClientsList.jsx: grep for is_active|deactivat|set-status|archived returns zero matches.
- **Port:** Port the clients.set-status wiring, bulk actions, and Archived badge/filter. Backend clients.set-status route + is_active column needed. Multi-tenant: scope to tenant client set.

### Feedback inbox (submit + admin triage with status workflow and replies)
- **Area:** React UI — Pages/Feedback + Components | **Effort:** M | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/Feedback/Index.jsx (318 lines): STATUS_META/STATUS_ORDER new|in_review|planned|done|declined, OPEN/CLOSED filters, reply + replyStatus state, canManage admin view, Inbox/MessageSquare icons. SOURCE Components/FeedbackForm.jsx. TARGET has neither (grep 'feedback' across target Pages returns no inbox; no Feedback dir).
- **Port:** Port Feedback/Index.jsx + FeedbackForm.jsx plus feedback model/controller/routes (feedback.store, status update, reply). Multi-tenant: scope feedback rows to tenant; 'canManage' should map to tenant admin, not global operator. Keep the AI chat widget separate (target already has AiChatWidget.jsx).

### Worker schedule / shifts UI (standing shift + date overrides)
- **Area:** React UI — Pages/MySchedule | **Effort:** M | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/MySchedule.jsx (212 lines): props {standingShift, overrides, canEdit}, fields shift_start_time + shift_hours, routes shift-overrides.store / shift-overrides.destroy. TARGET has no MySchedule page (grep 'MySchedule'/'shift_start'/'shift-overrides' in target Pages returns only unrelated word hits).
- **Port:** Port MySchedule.jsx + shift-overrides routes/controller and standing-shift columns (shift_start_time, shift_hours). Multi-tenant: scope shifts and overrides to tenant users; expose canEdit via tenant permission.

### Per-viewer timezone / display-preferences form on Profile
- **Area:** React UI — Pages/Profile | **Effort:** M | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/Profile/Partials/UpdateDisplayPreferencesForm.jsx and timezone references in SOURCE Profile/Edit.jsx. TARGET Profile/Partials has only DeleteUserForm, UpdatePasswordForm, UpdateProfileInformationForm — no UpdateDisplayPreferencesForm; grep for timezone/DisplayPreferences in target Pages/Profile returns nothing.
- **Port:** Port UpdateDisplayPreferencesForm.jsx and wire it into Profile/Edit.jsx; needs backend display-preferences update route + user column (display_timezone) and the useFormatters/display-prop viewer switcher. Multi-tenant: preference is per-user so it ports cleanly; ensure the formatter default falls back to tenant timezone.

### Per-person team analytics page with switchable charts (Team/Member) + TeamChartsPanel
- **Area:** React UI — Pages/Team | **Effort:** L | **Target status:** partial | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/Team/Member.jsx: route('team.member', member.id), four SwitchableChartCard drill-down cards; SOURCE Team/TeamChartsPanel.jsx. TARGET Pages/Team contains only Index.jsx + Apps.jsx (no Member.jsx, no TeamChartsPanel). Target has its own AdminAnalytics/EmployeeAnalytics/ProductivityInsights components but no per-member drill-down page.
- **Port:** Port Team/Member.jsx + TeamChartsPanel.jsx (or reconcile with target's EmployeeAnalytics). Needs team.member route + per-member analytics endpoint. Multi-tenant: scope member queries to tenant; reuse target chart components if adopting its Charts set instead of SwitchableChartCard.

### User archive / deactivate-reactivate (per-row + bulk, status filter, Deactivated badge)
- **Area:** React UI — Pages/UsersList | **Effort:** M | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE resources/js/Pages/UsersList.jsx lines 250-598: route('users.set-status'), is_active toggle, bulk activate/deactivate with all_matching, status filter option 'archived'->'Deactivated', UserX/UserCheck icons, Deactivated pill. TARGET UsersList.jsx: grep for is_active|deactivat|set-status|archived returns zero matches.
- **Port:** Bring over the set-status handlers, bulk selection, status filter dropdown, and Deactivated badge. Requires backend users.set-status route + is_active column + auth-lockout on inactive. Multi-tenant: constrain set-status to same-tenant users and to tenant-admin permission.

### Desktop diagnostics ingest + developer diagnostics panel (break-aware tracker-health)
- **Area:** Routes+Controllers | **Effort:** M | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE app/Http/Controllers/Api/Desktop/DiagnosticsController.php (63 lines, POST /api/desktop/diagnostics -> desktop.diagnostics.store); web.php developer.diagnostics (GET /developer/diagnostics -> DeveloperController@diagnostics). Target grep DiagnosticsController=0, function diagnostics=0
- **Port:** Port the desktop DiagnosticsController + api route (inside tenant.usable sanctum group) and add diagnostics() to target DeveloperController + the developer.diagnostics route. Supports the break-aware watchdog / tracker-health diagnostics. Tenant-scope diagnostic records.

### Feedback inbox (submit feedback, admin reply, mark seen/update status)
- **Area:** Routes+Controllers | **Effort:** M | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE app/Http/Controllers/FeedbackController.php (203 lines) with index/store/update/reply/seen -> feedback.index, feedback.store, feedback.update (PATCH, permission:feedback.manage), feedback.reply, feedback.seen. Target grep FeedbackController = 0
- **Port:** Port FeedbackController + 5 routes + Feedback model/migration. Note target already has its own HelpController (help.index) so the Help KB half is NOT a port — only the feedback inbox is. Multi-tenant: scope feedback to organization and route admin notifications per-tenant.

### Admin attendance clock editing (view day entries, edit clock in/out times, force clock-out)
- **Area:** Routes+Controllers | **Effort:** M | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php: employee-attendance.day-entries (GET -> EmployeeAttendanceController@getDayEntries), employee-attendance.clock-times (POST -> updateClockTimes), employee-attendance.clock-out (POST -> adminClockOut); all gated permission:attendance.edit_times. Target grep: getDayEntries/updateClockTimes/adminClockOut = 0
- **Port:** Add the 3 methods to target EmployeeAttendanceController + register routes under attendance.edit_times permission. This is the write side of the action_date mis-filing repair path — verify it writes attendance under the correct action_date (target already has 8 action_date references, so the column exists but these admin-edit endpoints do not). Tenant-scope all attendance queries.

### Dashboard analytics endpoints (trend chart + team activity feed)
- **Area:** Routes+Controllers | **Effort:** M | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php: time-entries.dashboard-trend (GET /time-entries/dashboard-trend -> TimeEntryController@dashboardTrend), time-entries.team-activity (GET -> teamActivity). Target grep dashboardTrend=0, teamActivity=0
- **Port:** Add dashboardTrend + teamActivity to target TimeEntryController + 2 routes. Feeds the approved dashboard-redesign/per-person analytics (Chart.js). Likely depends on the shared CalculatesTimeStats trait (see separate delta). Tenant-scope the aggregate queries.

### Shift assignment (assign/remove a worker's shift) + one-day shift overrides ('My schedule')
- **Area:** Routes+Controllers | **Effort:** M | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php: users.shifts.store (POST /users/shifts -> UserController@storeShift), users.shifts.destroy (DELETE /users/shifts/{shift} -> destroyShift); ShiftOverrideController.php (161 lines) with index/store/destroy -> shift-overrides.index (GET /my-schedule), shift-overrides.store, shift-overrides.destroy, gated permission:shift.edit_own,shift.manage_all
- **Port:** Port ShiftOverrideController wholesale + add storeShift/destroyShift to target UserController and register all 5 routes. Controller enforces self (shift.edit_own) vs anyone (shift.manage_all) and today/future rule — preserve that logic. Multi-tenant: scope shifts + overrides to organization. Depends on shifts / shift_start_time + shift_overrides tables from migrations dimension.

### Shared time-stats trait (CalculatesTimeStats concern)
- **Area:** Routes+Controllers | **Effort:** S | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE app/Http/Controllers/Concerns/CalculatesTimeStats.php (89 lines). Target grep CalculatesTimeStats = 0
- **Port:** Port the trait; it is a dependency used by dashboard/analytics + attendance controllers for consistent stat calculation. Port before/with the dashboard-trend and attendance deltas to avoid divergent math.

### Per-viewer timezone display switcher (profile display preferences)
- **Area:** Routes+Controllers | **Effort:** S | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php profile.display (PATCH /profile/display -> ProfileController@updateDisplay). Target ProfileController methods = edit/update/destroy only; updateDisplay = 0 grep hits
- **Port:** Add updateDisplay() to target ProfileController + register PATCH /profile/display. Persists the viewer's display timezone/format preference (useFormatters/display prop). Small, self-contained; tenant-neutral (per-user).

### Shift board / shift filtering / team metrics stack
- **Area:** Service + Support | **Effort:** L | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Services/ShiftBoardService.php, app/Services/TeamMetricsService.php, app/Support/ShiftBand.php, app/Support/ShiftFilter.php (shared shift_ids[] manager filter incl 'no_shift' sentinel for Dashboard/Team Performance/Attendance), app/Support/AttendanceHours.php.
- **Port:** None of ShiftBoardService, TeamMetricsService, ShiftBand, ShiftFilter exist in TARGET Services/Support. These back the shifts / shift_start_time feature and the shift-filtered dashboards. Port together with the shift schema migrations (verify shifts/shift assignments tables exist in target first); scope all shift queries per tenant.

## P2 (19 items)

### Auto-close attendance config knobs
- **Area:** Config | **Effort:** S | **Target status:** partial | **Dim:** services-jobs
- **Evidence:** SOURCE config/services.php defines 'auto_close_buffer_minutes' and 'auto_close_hard_cap_hours' under attendance (consumed by the attendance:auto-clock-out safety net).
- **Port:** diff of services.php keys shows TARGET is missing auto_close_buffer_minutes and auto_close_hard_cap_hours (and compliance_channel). TARGET still has AttendanceCloser + the auto-clock-out schedule, so it is running on hardcoded/older defaults — port these keys and wire AttendanceCloser to read them.

### Weekly per-person Slack digest (reports:weekly-digest)
- **Area:** Console command + Service | **Effort:** M | **Target status:** older | **Dim:** services-jobs
- **Evidence:** SOURCE app/Console/Commands/SendWeeklyDigest.php (signature 'reports:weekly-digest {--start=} {--end=}') + app/Services/WeeklyDigestService.php; SOURCE Kernel schedules reports:weekly-digest on Sundays, replacing the old daily activity digest + weekly slack report.
- **Port:** TARGET Kernel still schedules the OLDER 'tenant:run reports:send-weekly-slack' + 'tenant:run activity:digest-slack'; there is no reports:weekly-digest command or WeeklyDigestService in TARGET. Port SendWeeklyDigest + WeeklyDigestService (tracked vs activity, in-office hours, gap, late days) and switch the schedule. Scope metrics per tenant.

### Widened work_hours.hours precision (decimal 7,4)
- **Area:** Database | **Effort:** S | **Target status:** older | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_25_000002_widen_work_hours_hours_precision.php changes hours to decimal(7,4). TARGET create_work_hours_table still defines decimal('hours',5,2) and no widening migration exists.
- **Port:** Add a change() migration widening work_hours.hours to decimal(7,4) to avoid rounding drift when summing multiple windows/ranges. Low risk, no data loss.

### App-rollup index on activity samples
- **Area:** Database | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_28_000005_index_samples_for_app_rollup.php. TARGET grep 'index_samples_for_app_rollup' returns nothing.
- **Port:** Port the composite index for the per-app usage rollup query on tracking_activity_samples. Pure performance; verify column set matches target's samples schema (which now also carries organization_id, so consider leading the index with organization_id).

### Missing domain foreign keys (work_hours.client_id, attendance_clock_checks.clock_in_id)
- **Area:** Database | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_28_000004_add_missing_foreign_keys.php adds FK work_hours.client_id -> clients (nullOnDelete) and attendance_clock_checks.clock_in_id -> time_entries (cascadeOnDelete). TARGET has neither migration.
- **Port:** NOTE the fork's deliberate portability policy (see target 2026_06_19_020000 comment: no DB-level FKs, app-enforced). Porting these FKs may conflict with SQLite-local/MySQL-prod portability. Prefer replicating the referential integrity at the application layer, or add FKs guarded for MySQL only. Low priority.

### Mouse-click capture (mouse_clicks on tracking_screenshots + tracking_activity_samples)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_25_000005_add_mouse_clicks_to_tracking.php: tracking_screenshots.mouse_clicks (nullable, after mouse_count) and tracking_activity_samples.mouse_clicks (default 0, after mouse_count). TARGET grep 'mouse_clicks' returns nothing.
- **Port:** Add both columns. Distinct from existing mouse_count (movement) — this is click count, part of richer activity/compliance capture. Both base tables already tenant-scoped.

### Clock-out reminder in minutes (users.clockout_reminder_minutes replacing _hours)
- **Area:** Database/Models | **Effort:** S | **Target status:** older | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_25_000004_replace_clockout_reminder_hours_with_minutes.php drops clockout_reminder_hours and adds unsignedSmallInteger clockout_reminder_minutes. TARGET still has clockout_reminder_hours (from 2026_06_19_000001_add_shift_hours_and_reminder_to_users.php, referenced in target User model); no _minutes column.
- **Port:** Port the replace migration: add clockout_reminder_minutes, backfill from existing clockout_reminder_hours*60, drop the hours column. Update User model fillable/casts and any reminder scheduler reading the old column.

### Slack thread linkage on time entries (time_entries.slack_thread_ts)
- **Area:** Database/Models | **Effort:** S | **Target status:** absent | **Dim:** schema-models
- **Evidence:** SOURCE database/migrations/2026_06_25_000003_add_slack_thread_ts_to_time_entries.php: string slack_thread_ts nullable after notes. TARGET grep 'slack_thread_ts' returns nothing.
- **Port:** Add column to time_entries (tenant-scoped). Lets clock-in/out Slack notifications thread together. In multi-tenant this pairs with the target's per-org slack_bot_token (2026_06_19_040000).

### Tracker self-test / diagnostics script (dev + support tool)
- **Area:** desktop | **Effort:** S | **Target status:** absent | **Dim:** desktop
- **Evidence:** SOURCE desktop/scripts/tracker-selftest.js exists and is wired as `npm test` in desktop/package.json ('test': 'node scripts/tracker-selftest.js'). TARGET desktop/scripts/ has only start-dev.ps1; package.json has no test script.
- **Port:** Port the selftest script and the npm test entry. Low urgency support/QA tooling; helps diagnose a silent machine after the compliance/diagnostics ports land.

### Desktop version currency and release feed (0.4.7 vs 0.3.4)
- **Area:** desktop | **Effort:** S | **Target status:** older | **Dim:** desktop
- **Evidence:** SOURCE desktop/package.json version 0.4.7 with a detailed _comment_version changelog covering 0.4.4-0.4.7 (Machine Compliance, extension-inventory disable_reasons fix, ModSecurity profile-field 403 fix, Restart-now before-quit fix, differential-download disable). TARGET desktop/package.json version 0.3.4, productName 'Tracksuite Desktop', appId com.tracksuite.desktop, feed https://staffvora.com/desktop-updates. T...
- **Port:** This is the umbrella of the deltas above rather than a separate copy job. After porting collector/diag/report/tracker/updater fixes, bump the target desktop version and rebuild. KEEP tenant identity (productName, appId com.tracksuite.desktop, protocol scheme 'tracksuite', feed staffvora.com) — do NOT copy the SA Track identity. Installer hosting controller is already present in target; only the desktop-side ports feed it.

### Dashboard 'Needs attention' list widget
- **Area:** React UI — Components + Pages/Dashboard | **Effort:** S | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE Components/NeedsAttentionList.jsx, imported and rendered in SOURCE Dashboard.jsx (line 43, line 940: <NeedsAttentionList items={needsAttention} canViewAnalytics=.../>). TARGET has no NeedsAttentionList component and no needsAttention usage (grep returns zero).
- **Port:** Port NeedsAttentionList.jsx and the needsAttention prop from the dashboard controller. Multi-tenant: the underlying 'needs attention' query must be tenant-scoped server-side.

### SwitchableChartCard + MultiTypeChart (user-toggleable chart-type cards)
- **Area:** React UI — Components/Charts | **Effort:** M | **Target status:** absent | **Dim:** react-ui
- **Evidence:** SOURCE Components/Charts/SwitchableChartCard.jsx and Components/Charts/MultiTypeChart.jsx, imported by SOURCE Dashboard.jsx (lines 42, 898-927) and Team/Member.jsx. TARGET Components/Charts has BarChart, LineChart, DoughnutChart, TrendChart, Sparkline, RankedBars, ActivityStrip — but no switchable/multi-type card wrapper.
- **Port:** Either port SwitchableChartCard + MultiTypeChart, or build a thin switchable wrapper around the target's existing Chart.js components (target already imports from @/Components/charts). Prereq for the Dashboard switchable panels and Team/Member port. Pure UI, no tenant concern.

### Searchable Help center KB (article scoring/search + contact form) vs static help
- **Area:** React UI — Pages/Help | **Effort:** M | **Target status:** older | **Dim:** react-ui
- **Evidence:** SOURCE Help.jsx (307 lines): articles prop, scoreArticle() keyword/title/body ranking, live search results, contact/send flow, ShieldCheck/LifeBuoy icons. TARGET Help/Index.jsx (117 lines): hardcoded SECTIONS array with a simple client-side filter, no articles data source, no article scoring or contact submission.
- **Port:** Upgrade target Help/Index.jsx to the source's article-driven searchable KB (articles prop + scoreArticle ranking) and contact/feedback hand-off. Multi-tenant: Help articles can stay global/product-level, but any contact submission should route into the tenant's feedback inbox.

### Developer storage monitor (S3 usage/cost panel)
- **Area:** Routes+Controllers | **Effort:** S | **Target status:** absent | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php developer.storage (GET /developer/storage -> DeveloperController@storage). Target DeveloperController has index/run/updateEnv/logs only; function storage = 0
- **Port:** Add storage() to target DeveloperController + route. Backs the S3 usage/cost monitor. Multi-tenant nuance: decide whether storage stats are per-tenant (screenshots/avatars bucket usage attributed to an org) or operator-global; likely belongs in the operator portal rather than a tenant developer panel.

### Per-member team detail page
- **Area:** Routes+Controllers | **Effort:** S | **Target status:** partial | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php team.member (GET /team/member/{user} -> TeamController@member). Target grep 'function member' = 0 (target TeamController exists but lacks member())
- **Port:** Add member() to target TeamController + register team.member route (keep the existing middleware/entitlement pattern used by /team and /team/apps). Tenant-scope the member lookup so you cannot view a user outside your org.

### Public desktop download alias (/download)
- **Area:** Routes+Controllers | **Effort:** S | **Target status:** partial | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php desktop-downloads.public (GET /download -> DesktopDownloadController@index). Target grep desktop-downloads.public = 0 (target has DesktopDownloadController but not the /download public alias name)
- **Port:** Add the GET /download route named desktop-downloads.public pointing at the existing index method — a short public alias for the installer landing page. Trivial; confirm it does not collide with any marketing-site route in the target.

### Developer branding update endpoint (dev-panel logo/branding write)
- **Area:** Routes+Controllers | **Effort:** S | **Target status:** partial | **Dim:** routes-controllers
- **Evidence:** SOURCE web.php developer.branding.update (POST /developer/branding -> BrandingController@update). Target BrandingController already has update() + saveBranding() reached via its own flow
- **Port:** Low priority / likely NOT needed: target already exposes branding update + saveBranding (per-tenant branding is a fork feature). Only port the developer.branding.update route wiring if the dev-panel specifically needs it; otherwise the target's tenant branding flow supersedes the source's single-tenant one.

### Threaded attendance clock notifier (AttendanceClockNotifier)
- **Area:** Service | **Effort:** M | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Services/AttendanceClockNotifier.php — posts one Slack thread per person per day (first clock-in = parent; break-start/break-end/clock-out/after-lunch clock-in = replies).
- **Port:** grep of TARGET app finds no AttendanceClockNotifier. Port the service; store the per-day parent Slack thread ts keyed by tenant+user+day so threads don't collide across tenants.

### S3 usage / cost monitor service
- **Area:** Service | **Effort:** M | **Target status:** absent | **Dim:** services-jobs
- **Evidence:** SOURCE app/Services/S3UsageService.php — uses Aws\CloudWatch\CloudWatchClient + Aws\CostExplorer\CostExplorerClient (cached) to report screenshot-bucket size and rough cost.
- **Port:** No S3UsageService in TARGET. Lower priority (ops/observability, not user-facing). Port as-is; in multi-tenant the bucket is shared so metrics stay global, but consider per-tenant prefix attribution if billing needs it.

## Dimension summaries
- **routes-controllers:** Routes+Controllers dimension: TARGET (Staffvora) is missing 6 source-only controllers and ~25 source-only route names. Confirmed absent in target (0 grep hits): ComplianceController, Api/Desktop/MachineReportController, Api/Desktop/DiagnosticsController, FeedbackController, ShiftOverrideController, and the Concerns/CalculatesTimeStats trait. Also absent as methods on shared controllers: User/Client/UpworkProfile bulkStatus+setStatus (archive/deactivate), UserController storeShift/destroyShift (shift assignment), EmployeeAttendanceController getDayEntries/updateClockTimes/adminClockOut (admin clock editing), ProfileController updateDisplay (per-viewer timezone), TimeEntryController dashboardTrend/teamActivity (dashboard analytics), TeamController member, DeveloperController diagnostics/storage. Note: target already has HelpController (help.index), users.designations, recentClients, aiSummary, monthly/manual-history, and 8 action_date references, so those are NOT ports. Target-only work (Platform/*, Site/*, Billing, Onboarding, TeamManagement, Theme, RegisteredUser) correctly ignored.
- **schema-models:** Compared database/migrations and app/Models between SOURCE (C:\laragon\www\timetracker) and TARGET (C:\laragon\www\Staffvora). Source has 22 migrations with no counterpart in the target and 8 models that do not exist in the fork. The biggest source-only clusters are Machine Compliance (machine_reports + machine_flags tables/models), the Help center + threaded Feedback inbox (help_articles, feedback_items, feedback_messages), Shifts + per-day shift overrides (shifts, user_shift_overrides, users.shift_id), and a set of user/tracking columns (work_timezone, tracks_time, is_active on users+clients, mouse_clicks, health_probe_seconds, slack_thread_ts, clockout_reminder_minutes). Two items are partial/older rather than absent: the target implemented manual time ranges as single started_at/ended_at columns on work_hours instead of the source's one-to-many work_hour_windows child table, and the target still carries clockout_reminder_hours and work_hours.hours decimal(5,2) where the source moved to clockout_reminder_minutes and decimal(7,4). CRITICAL multi-tenant note: the target's 2026_06_19_020000_add_organization_id_to_tenant_tables migration enumerates a fixed list of tenant tables and none of the source-only tables are in it — every ported per-tenant table (machine_reports, machine_flags, user_shift_overrides, help_articles, feedback_items, feedback_messages, work_hour_windows; shifts is a candidate global-or-per-org lookup) must add a nullable indexed organization_id and be wired into OrganizationScope, and every ported users/clients column addition inherits org scoping automatically since those base tables are already tenant-scoped.
- **react-ui:** Compared resources/js/Pages/* and resources/js/Components/* between SOURCE (C:\laragon\www\timetracker) and TARGET (C:\laragon\www\Staffvora). Found 10 React-UI deltas where the source is ahead. Highest priority is the entire Machine Compliance dashboard (Monitoring/Compliance.jsx, 410 lines) which has no counterpart in the target. Other confirmed source-only UI: the feedback inbox (Feedback/Index.jsx + FeedbackForm), archive/deactivate toggles on UsersList and ClientsList, the per-viewer timezone display-preferences form, the MySchedule shifts UI, the per-person Team/Member analytics page (+TeamChartsPanel) with SwitchableChartCard/MultiTypeChart, the NeedsAttentionList dashboard widget, and a fuller searchable Help KB (source Help.jsx 307 lines vs target static Help/Index.jsx 117 lines). NOTE: several things the brief flagged are NOT deltas — the Timeline screenshot grid + hover preview + flag/manage/delete already exists in the target (Timeline/Index.jsx has the same ScreenshotTile/SessionCard, 1168 lines), and Monitoring SessionDetail/SessionsList and UpworkProfiles are actually LARGER/newer in the target (redesigned, not ports). Target-only pages (Platform/*, PlatformPortal/*, PlatformAuth/*, Billing, Site/*, Settings/Branding, Onboarding, Teams, Auth/Register, DesignKit) were ignored per instructions. All findings are read-only; nothing was modified.
- **services-jobs:** Background-dimension port inventory (commands/services/support/config/scheduler) comparing SOURCE C:\laragon\www\timetracker vs TARGET C:\laragon\www\Staffvora. The two schedulers are structurally identical (target just wraps each command in `tenant:run`), so the deltas are in the command/service/support classes those schedules invoke, plus config. Biggest finding: the break-aware tracker-health watchdog is REGRESSED in the target (SilentTrackerCheck 156→96 lines, all break-awareness stripped, would false-alert during breaks), and the entire Machine Compliance ingestion/blocklist stack plus the diagnostics/repair toolchain are ABSENT. Multi-tenant adaptation for every port: scope queries by tenant, and each artisan command runs per-tenant via the existing `tenant:run` wrapper already used in the target Kernel.
- **desktop:** Both repos ship an Electron desktop/ app, but the TARGET (Tracksuite Desktop v0.3.4) is ~13 releases behind the SOURCE (SA Track v0.4.7). The whole Machine Compliance stack is the biggest gap: the source's collector.js (per-PC extension/program/process/VPN + anti-detect inventory via PowerShell, with the 0.4.5-0.4.7 disable_reasons and ModSecurity-403 fixes), api.sendMachineReport()/POST /api/desktop/machine-report, and the launch/login/clock_in scheduling are entirely ABSENT in the target (no collector, no compliance keywords anywhere). Also absent: the diag.js + report.js diagnostics/telemetry pipeline (capture.log + buffered /api/desktop/diagnostics reporter, crash handlers), tracker-selftest.js, and the graceful stop-then-quit before-quit finalizer that fixes both dangling sessions and the auto-update 'Restart now' no-op. OLDER/less-complete in the target: trackerService.start() lacks the _starting re-entrancy guard (the confirmed shared-PC double-start doubled-time race), there is NO break-aware pause (no idle/break/manual pauseReason, no heartbeat on_break reconcile, so idle auto-resume can clobber a break), screenshotService uses only the legacy screenshot-desktop path with no AV-safe desktopCapturer or block/empty-frame detection, updater.js is missing disableDifferentialDownload (the download-loop fix) and updater-error reporting, and config.js has no per-user userPaths() so shared-PC queue/screenshot isolation is gone. The desktop-updates feed/installer-hosting controller already EXISTS in the target and needs no port. All ports are client-side except the machine-report and diagnostics server endpoints, which must be added and scoped per workspace/tenant (the desktop payloads are unchanged since they authenticate via the /api/desktop token). Preserve the target's own identity (productName, appId com.tracksuite.desktop, protocol 'tracksuite', feed staffvora.com) — do not copy the SA Track branding/URLs. Recommended order: port diag/report first (dependency), then collector, double-start guard, break-aware pause, AV-safe capture, updater + graceful-quit, per-user storage.
