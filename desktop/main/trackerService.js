'use strict';

const { EventEmitter } = require('node:events');
const fs = require('node:fs');
const { v4: uuidv4 } = require('uuid');
const os = require('node:os');
let Notification = null;
try { Notification = require('electron').Notification; } catch { /* tests / non-electron contexts */ }

const api = require('./api');
const queue = require('./queue');
const store = require('./store');
const screenshotService = require('./screenshotService');
const activityService = require('./activityService');
const { DEFAULTS } = require('./config');

const HEARTBEAT_INTERVAL_MS = 30_000;
const SYNC_INTERVAL_MS = 15_000;
const SETTINGS_REFRESH_MS = 120_000;

class Tracker extends EventEmitter {
  constructor() {
    super();
    this.session = null; // { id, client_uuid, started_at_ms, total_seconds, ... }
    this.settings = { ...DEFAULTS.defaultSettings };
    this.timers = {
      heartbeat: null,
      activitySample: null,
      screenshot: null,
      sync: null,
      idleWatch: null,
      settingsRefresh: null,
    };
    this._activityIntervalSec = this.settings.activity_sample_interval_seconds;
    this._idleWarned = false;
  }

  async refreshSettings() {
    try {
      const remote = await api.getSettings();
      if (remote) this.settings = { ...this.settings, ...remote };
    } catch {
      // keep defaults if offline
    }
    this._activityIntervalSec = this.settings.activity_sample_interval_seconds || 60;
    return this.settings;
  }

  status() {
    return {
      running: !!this.session,
      paused: !!this.session?.paused_at_ms,
      session: this.session ? {
        id: this.session.id,
        client_uuid: this.session.client_uuid,
        started_at: new Date(this.session.started_at_ms).toISOString(),
        total_seconds: this._currentSeconds(),
        // The renderer uses these to tick smoothly without polling the main
        // process every second.
        frozen_seconds: this.session.frozen_seconds,
        last_change_at: new Date(this.session.last_change_at_ms).toISOString(),
        activity_percent: this.session.activity_percent,
        task_note: this.session.task_note,
        client_id: this.session.client_id,
        upwork_profile_id: this.session.upwork_profile_id,
        work_type: this.session.work_type,
      } : null,
      pending: queue.counts(),
      settings: this.settings,
    };
  }

  _currentSeconds() {
    if (!this.session) return 0;
    if (this.session.paused_at_ms) {
      return Math.max(0, Math.floor(this.session.frozen_seconds));
    }
    const delta = Math.floor((Date.now() - this.session.last_change_at_ms) / 1000);
    return Math.max(0, this.session.frozen_seconds + delta);
  }

  _pauseSession(reason = null) {
    if (!this.session || this.session.paused_at_ms) return;
    const now = Date.now();
    this.session.frozen_seconds += Math.max(0, Math.floor((now - this.session.last_change_at_ms) / 1000));
    this.session.paused_at_ms = now;
    this.session.last_change_at_ms = now;
    if (this.timers.screenshot) {
      clearTimeout(this.timers.screenshot);
      this.timers.screenshot = null;
    }
    if (reason) this.emit('warning', reason);
    this.emit('changed', this.status());
  }

  _resumeSession(reason = null) {
    if (!this.session || !this.session.paused_at_ms) return;
    const now = Date.now();
    this.session.paused_at_ms = null;
    this.session.last_change_at_ms = now;
    this._scheduleNextScreenshot();
    if (reason) this.emit('warning', reason);
    this.emit('changed', this.status());
  }

  async start(opts) {
    if (this.session) throw new Error('A session is already running');

    await this.refreshSettings();

    const client_uuid = uuidv4();
    const started_at = new Date();

    const payload = {
      client_uuid,
      started_at: started_at.toISOString(),
      client_id: opts.client_id ?? null,
      upwork_profile_id: opts.upwork_profile_id ?? null,
      work_type: opts.work_type ?? null,
      task_note: opts.task_note ?? null,
      device_name: opts.device_name || os.hostname(),
      platform: process.platform,
      app_version: require('../package.json').version,
    };

    let serverSession;
    try {
      serverSession = await api.startSession(payload);
    } catch (err) {
      // If offline at start, we cannot persist a remote session id.
      // The desktop app requires the start handshake to succeed for now;
      // a future enhancement could buffer the start payload locally.
      throw new Error('Could not start session: ' + (err.response?.data?.message || err.message));
    }

    this.session = {
      id: serverSession.id,
      client_uuid,
      started_at_ms: started_at.getTime(),
      // Active time accounting. `frozen_seconds` is the active time captured
      // before the most recent state change (pause/resume). `last_change_at_ms`
      // is the wall-clock moment when the session last started or resumed.
      // While running, current active time = frozen + (now - last_change_at).
      // While paused, current active time = frozen (no delta).
      frozen_seconds: 0,
      last_change_at_ms: started_at.getTime(),
      paused_at_ms: null,
      activity_percent: 0,
      task_note: opts.task_note ?? null,
      client_id: opts.client_id ?? null,
      upwork_profile_id: opts.upwork_profile_id ?? null,
      work_type: opts.work_type ?? null,
    };

    activityService.start();
    this._startTimers();
    this.emit('changed', this.status());
  }

  async stop(opts = {}) {
    if (!this.session) return;
    const taskNote = opts.task_note ?? this.session.task_note;

    this._stopTimers();
    activityService.stop();

    const stopPayload = {
      stopped_at: new Date().toISOString(),
      total_seconds: this._currentSeconds(),
      activity_percent: this.session.activity_percent,
      task_note: taskNote,
    };

    try {
      await api.stopSession(this.session.id, stopPayload);
    } catch (err) {
      // Best effort. Sync loop will retry via the heartbeat path; for now we
      // accept the local state as stopped.
      this.emit('warning', 'Could not notify server of stop: ' + (err.response?.data?.message || err.message));
    }

    const stopped = { ...this.session, stopped_at: stopPayload.stopped_at };
    this.session = null;
    this.emit('stopped', stopped);
    this.emit('changed', this.status());
  }

  _startTimers() {
    this._scheduleNextScreenshot();

    this.timers.activitySample = setInterval(() => this._sampleActivity().catch(() => {}), this._activityIntervalSec * 1000);
    this.timers.heartbeat = setInterval(() => this._sendHeartbeat().catch(() => {}), HEARTBEAT_INTERVAL_MS);
    this.timers.sync = setInterval(() => this._drainQueue().catch(() => {}), SYNC_INTERVAL_MS);
    this.timers.idleWatch = setInterval(() => this._checkIdleWarning(), 15_000);
    this.timers.settingsRefresh = setInterval(() => this._refreshAndApplySettings().catch(() => {}), SETTINGS_REFRESH_MS);
    this._idleWarned = false;
  }

  /**
   * Re-fetch the per-user effective settings while a session is running and
   * apply any changes live, so an admin editing the web Settings page takes
   * effect without the employee restarting the tracker.
   */
  async _refreshAndApplySettings() {
    if (!this.session) return;

    const prev = this.settings;
    await this.refreshSettings();
    const next = this.settings;

    // Restart activity sampling if the cadence changed.
    const prevInterval = prev.activity_sample_interval_seconds || 60;
    const nextInterval = next.activity_sample_interval_seconds || 60;
    if (nextInterval !== prevInterval && this.timers.activitySample) {
      clearInterval(this.timers.activitySample);
      this.timers.activitySample = setInterval(() => this._sampleActivity().catch(() => {}), nextInterval * 1000);
    }

    // Reschedule the screenshot loop if cadence or the capture toggle changed.
    const screenshotChanged =
      prev.capture_enabled !== next.capture_enabled ||
      prev.screenshots_per_hour !== next.screenshots_per_hour ||
      prev.screenshot_interval_min_seconds !== next.screenshot_interval_min_seconds ||
      prev.screenshot_interval_max_seconds !== next.screenshot_interval_max_seconds;
    if (screenshotChanged) {
      if (this.timers.screenshot) {
        clearTimeout(this.timers.screenshot);
        this.timers.screenshot = null;
      }
      this._scheduleNextScreenshot();
    }

    // Surface the new settings to the renderer settings panel.
    this.emit('changed', this.status());
  }

  _checkIdleWarning() {
    if (!this.session) return;
    const autoPauseSec = Number(this.settings.auto_pause_minutes || 0) * 60;
    if (autoPauseSec <= 0) return;

    const idle = activityService.getSystemIdleSeconds();

    // User is active again: reset, and clear the on-screen warning if we
    // showed one (empty string tells the renderer to hide the banner).
    if (idle < 30) {
      if (this._idleWarned) {
        this._idleWarned = false;
        this.emit('warning', '');
      }
      return;
    }

    // Warn one minute before auto-pause (but never sooner than 30s of idle).
    const warnAt = Math.max(30, autoPauseSec - 60);
    if (idle >= warnAt && !this._idleWarned) {
      this._idleWarned = true;
      const remaining = Math.max(1, Math.round((autoPauseSec - idle) / 60));
      const message = `You have been idle. Tracking will pause in about ${remaining} min unless you resume working.`;
      this.emit('warning', message);
      const idlePref = (store.get('prefs') || {}).idleNotifications;
      if (Notification && idlePref !== false) {
        try {
          new Notification({ title: 'Timetracker', body: message }).show();
        } catch { /* best effort */ }
      }
    }
  }

  _stopTimers() {
    for (const key of Object.keys(this.timers)) {
      if (this.timers[key]) {
        clearInterval(this.timers[key]);
        clearTimeout(this.timers[key]);
        this.timers[key] = null;
      }
    }
  }

  _scheduleNextScreenshot() {
    if (!this.session) return;

    // capture_enabled=false or screenshots_per_hour=0 disables screenshots entirely.
    if (this.settings.capture_enabled === false || this.settings.screenshots_per_hour === 0) {
      this.timers.screenshot = null;
      return;
    }

    const minS = this.settings.screenshot_interval_min_seconds || 300;
    const maxS = Math.max(minS, this.settings.screenshot_interval_max_seconds || minS);
    const jitter = Math.floor(Math.random() * (maxS - minS + 1));
    const delayMs = (minS + jitter) * 1000;

    this.timers.screenshot = setTimeout(async () => {
      try { await this._captureScreenshot(); } catch (err) { console.warn('screenshot failed:', err.message); }
      this._scheduleNextScreenshot();
    }, delayMs);
  }

  _maybeNotifyScreenshot() {
    // Local preference wins when set; otherwise follow the admin setting.
    const localPref = (store.get('prefs') || {}).notifyScreenshot;
    const shouldNotify = localPref === null || localPref === undefined
      ? this.settings.notify_on_screenshot
      : localPref;
    if (!shouldNotify || !Notification) return;
    try {
      new Notification({
        title: 'Timetracker',
        body: 'Screenshot taken',
        silent: true,
      }).show();
    } catch {
      // notifications are best-effort
    }
  }

  async _sampleActivity() {
    if (!this.session) return;
    if (this.settings.activity_tracking_enabled === false) return;

    const snap = activityService.snapshotAndReset();
    const hasInput = (snap.keyboard_count + snap.mouse_count) > 0;
    const looksActiveNow = hasInput || activityService.getSystemIdleSeconds() < 5;

    // Auto-resume: if currently paused and the user came back, resume before
    // doing anything else (sampling, screenshots) so the new active period
    // starts cleanly.
    if (this.session.paused_at_ms) {
      if (looksActiveNow) {
        this._resumeSession('Resumed — activity detected.');
      } else {
        // Stay paused; no point recording an empty sample for an empty room.
        return;
      }
    }

    const appUrlOn = this.settings.app_url_tracking_enabled !== false;
    const winInfo = appUrlOn
      ? await activityService.activeWindowInfo()
      : { active_app: null, active_window_title: null, url_domain: null };
    const capturedAt = new Date().toISOString();

    queue.enqueueActivitySample({
      tracking_session_id: this.session.id,
      captured_at: capturedAt,
      keyboard_count: snap.keyboard_count,
      mouse_count: snap.mouse_count,
      idle_seconds: snap.idle_seconds,
      active_app: winInfo.active_app,
      active_window_title: winInfo.active_window_title,
      url_domain: winInfo.url_domain,
    });

    // Roll activity percent (simple running indicator).
    const pct = activityService.computeActivityPercent(snap);
    this.session.activity_percent = Math.round((this.session.activity_percent * 0.7) + (pct * 0.3));

    // Auto-pause: freeze the timer when idle exceeds the configured threshold.
    // The session stays open; we resume automatically when the user is active
    // again. (Different from stop, which ends the session entirely.)
    // `snap.idle_seconds` is clamped to the sample interval, so it can never
    // reach a multi-minute threshold; use the raw system idle clock here.
    const autoPauseMin = Number(this.settings.auto_pause_minutes || 0);
    if (autoPauseMin > 0 && activityService.getSystemIdleSeconds() >= autoPauseMin * 60) {
      this._pauseSession(`Auto-paused after ${autoPauseMin} min of inactivity. Tracking resumes when you're back.`);
      return;
    }

    this.emit('changed', this.status());
  }

  async _captureScreenshot() {
    if (!this.session) return;
    if (this.session.paused_at_ms) return; // no capture while paused
    if (this.settings.capture_enabled === false || this.settings.screenshots_per_hour === 0) return;

    const capturedAt = new Date().toISOString();
    const { localPath } = await screenshotService.capture({ sessionId: this.session.id });
    const appUrlOn = this.settings.app_url_tracking_enabled !== false;
    const winInfo = appUrlOn
      ? await activityService.activeWindowInfo()
      : { active_app: null, active_window_title: null, url_domain: null };
    const snap = activityService.snapshotAndReset();

    queue.enqueueScreenshot({
      tracking_session_id: this.session.id,
      captured_at: capturedAt,
      image_path: localPath,
      activity_percent: this.session.activity_percent,
      keyboard_count: snap.keyboard_count,
      mouse_count: snap.mouse_count,
      active_app: winInfo.active_app,
      active_window_title: winInfo.active_window_title,
      url_domain: winInfo.url_domain,
    });
    this._maybeNotifyScreenshot();
    this.emit('changed', this.status());
  }

  async _sendHeartbeat() {
    if (!this.session) return;
    const totalSeconds = this._currentSeconds();
    this.session.total_seconds = totalSeconds;

    queue.enqueueHeartbeat({
      tracking_session_id: this.session.id,
      total_seconds: totalSeconds,
      activity_percent: this.session.activity_percent,
      heartbeat_at: new Date().toISOString(),
    });

    // Weekly time limit: server is authoritative, but stop locally if the
    // current session would obviously cross the configured weekly cap so the
    // user gets immediate feedback instead of an offline-queue overshoot.
    const weeklyHours = Number(this.settings.weekly_time_limit_hours || 0);
    if (weeklyHours > 0 && totalSeconds >= weeklyHours * 3600) {
      this.emit('warning', `Weekly time limit reached (${weeklyHours}h). Tracking stopped.`);
      await this.stop({ task_note: this.session?.task_note }).catch(() => {});
      return;
    }

    this.emit('changed', this.status());
  }

  async _drainQueue() {
    // Heartbeats first (cheap & most useful).
    for (const h of queue.nextHeartbeats()) {
      try {
        await api.heartbeat(h.tracking_session_id, {
          total_seconds: h.total_seconds,
          activity_percent: h.activity_percent,
          heartbeat_at: h.heartbeat_at,
        });
        queue.deleteHeartbeat(h.id);
      } catch (err) {
        queue.markHeartbeatError(h.id, err.message);
        return; // bail until next tick
      }
    }

    // Activity samples in batches.
    const samples = queue.nextActivityBatch(100);
    if (samples.length) {
      // All samples must belong to the same session for the batch endpoint.
      const bySession = new Map();
      for (const s of samples) {
        if (!bySession.has(s.tracking_session_id)) bySession.set(s.tracking_session_id, []);
        bySession.get(s.tracking_session_id).push(s);
      }
      for (const [sessionId, rows] of bySession.entries()) {
        try {
          await api.sendActivityBatch({
            tracking_session_id: sessionId,
            samples: rows.map((r) => ({
              captured_at: r.captured_at,
              keyboard_count: r.keyboard_count,
              mouse_count: r.mouse_count,
              idle_seconds: r.idle_seconds,
              active_app: r.active_app,
              active_window_title: r.active_window_title,
              url_domain: r.url_domain,
            })),
          });
          queue.deleteActivitySamples(rows.map((r) => r.id));
        } catch (err) {
          queue.markActivityError(rows.map((r) => r.id), err.message);
          return;
        }
      }
    }

    // Screenshots (one at a time, with file cleanup on success).
    for (const s of queue.nextScreenshots(3)) {
      try {
        await api.uploadScreenshot(s.image_path, {
          tracking_session_id: s.tracking_session_id,
          captured_at: s.captured_at,
          activity_percent: s.activity_percent,
          keyboard_count: s.keyboard_count,
          mouse_count: s.mouse_count,
          active_app: s.active_app,
          active_window_title: s.active_window_title,
          url_domain: s.url_domain,
        });
        queue.deleteScreenshot(s.id);
        try { fs.unlinkSync(s.image_path); } catch { /* ignore */ }
      } catch (err) {
        queue.markScreenshotError(s.id, err.message);
        return;
      }
    }

    this.emit('changed', this.status());
  }
}

module.exports = new Tracker();
