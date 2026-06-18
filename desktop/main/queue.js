'use strict';

const fs = require('node:fs');
const path = require('node:path');
const Database = require('better-sqlite3');
const { paths } = require('./config');

let db;

function init() {
  if (db) return db;
  fs.mkdirSync(path.dirname(paths.queueDb), { recursive: true });
  fs.mkdirSync(paths.screenshotsDir, { recursive: true });

  db = new Database(paths.queueDb);
  db.pragma('journal_mode = WAL');

  db.exec(`
    CREATE TABLE IF NOT EXISTS pending_screenshots (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tracking_session_id INTEGER NOT NULL,
      captured_at TEXT NOT NULL,
      image_path TEXT NOT NULL,
      activity_percent INTEGER,
      keyboard_count INTEGER,
      mouse_count INTEGER,
      active_app TEXT,
      active_window_title TEXT,
      url_domain TEXT,
      attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS pending_activity_samples (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tracking_session_id INTEGER NOT NULL,
      captured_at TEXT NOT NULL,
      keyboard_count INTEGER NOT NULL DEFAULT 0,
      mouse_count INTEGER NOT NULL DEFAULT 0,
      idle_seconds INTEGER NOT NULL DEFAULT 0,
      active_app TEXT,
      active_window_title TEXT,
      url_domain TEXT,
      attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS pending_heartbeats (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tracking_session_id INTEGER NOT NULL,
      total_seconds INTEGER NOT NULL,
      activity_percent INTEGER,
      heartbeat_at TEXT NOT NULL,
      attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE INDEX IF NOT EXISTS idx_screenshots_session ON pending_screenshots(tracking_session_id);
    CREATE INDEX IF NOT EXISTS idx_activity_session ON pending_activity_samples(tracking_session_id);
  `);

  return db;
}

function enqueueScreenshot(row) {
  init();
  const stmt = db.prepare(`
    INSERT INTO pending_screenshots
      (tracking_session_id, captured_at, image_path, activity_percent, keyboard_count, mouse_count, active_app, active_window_title, url_domain)
    VALUES (@tracking_session_id, @captured_at, @image_path, @activity_percent, @keyboard_count, @mouse_count, @active_app, @active_window_title, @url_domain)
  `);
  return stmt.run(row).lastInsertRowid;
}

function enqueueActivitySample(row) {
  init();
  const stmt = db.prepare(`
    INSERT INTO pending_activity_samples
      (tracking_session_id, captured_at, keyboard_count, mouse_count, idle_seconds, active_app, active_window_title, url_domain)
    VALUES (@tracking_session_id, @captured_at, @keyboard_count, @mouse_count, @idle_seconds, @active_app, @active_window_title, @url_domain)
  `);
  return stmt.run(row).lastInsertRowid;
}

function enqueueHeartbeat(row) {
  init();
  // Only keep the most recent heartbeat per session to avoid pile-up.
  db.prepare(`DELETE FROM pending_heartbeats WHERE tracking_session_id = ?`).run(row.tracking_session_id);
  const stmt = db.prepare(`
    INSERT INTO pending_heartbeats
      (tracking_session_id, total_seconds, activity_percent, heartbeat_at)
    VALUES (@tracking_session_id, @total_seconds, @activity_percent, @heartbeat_at)
  `);
  return stmt.run(row).lastInsertRowid;
}

function nextScreenshots(limit = 5) {
  init();
  return db.prepare(`SELECT * FROM pending_screenshots ORDER BY id ASC LIMIT ?`).all(limit);
}

function nextActivityBatch(limit = 100) {
  init();
  return db.prepare(`SELECT * FROM pending_activity_samples ORDER BY id ASC LIMIT ?`).all(limit);
}

function nextHeartbeats() {
  init();
  return db.prepare(`SELECT * FROM pending_heartbeats ORDER BY id ASC`).all();
}

function deleteScreenshot(id) {
  init();
  db.prepare(`DELETE FROM pending_screenshots WHERE id = ?`).run(id);
}

function deleteActivitySamples(ids) {
  if (!ids.length) return;
  init();
  const placeholders = ids.map(() => '?').join(',');
  db.prepare(`DELETE FROM pending_activity_samples WHERE id IN (${placeholders})`).run(...ids);
}

function deleteHeartbeat(id) {
  init();
  db.prepare(`DELETE FROM pending_heartbeats WHERE id = ?`).run(id);
}

function markScreenshotError(id, err) {
  init();
  db.prepare(`UPDATE pending_screenshots SET attempts = attempts + 1, last_error = ? WHERE id = ?`).run(String(err), id);
}

function markActivityError(ids, err) {
  if (!ids.length) return;
  init();
  const placeholders = ids.map(() => '?').join(',');
  db.prepare(`UPDATE pending_activity_samples SET attempts = attempts + 1, last_error = ? WHERE id IN (${placeholders})`).run(String(err), ...ids);
}

function markHeartbeatError(id, err) {
  init();
  db.prepare(`UPDATE pending_heartbeats SET attempts = attempts + 1, last_error = ? WHERE id = ?`).run(String(err), id);
}

function counts() {
  init();
  return {
    screenshots: db.prepare(`SELECT COUNT(*) AS c FROM pending_screenshots`).get().c,
    activity: db.prepare(`SELECT COUNT(*) AS c FROM pending_activity_samples`).get().c,
    heartbeats: db.prepare(`SELECT COUNT(*) AS c FROM pending_heartbeats`).get().c,
  };
}

module.exports = {
  init,
  enqueueScreenshot,
  enqueueActivitySample,
  enqueueHeartbeat,
  nextScreenshots,
  nextActivityBatch,
  nextHeartbeats,
  deleteScreenshot,
  deleteActivitySamples,
  deleteHeartbeat,
  markScreenshotError,
  markActivityError,
  markHeartbeatError,
  counts,
};
