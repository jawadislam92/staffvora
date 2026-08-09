'use strict';

/*
 * MACHINE COMPLIANCE — endpoint inventory collector.
 * ============================================================================
 *
 * WHY THIS EXISTS. Upwork bans profiles for automation: auto-refresh tools,
 * scrapers, mouse jigglers, anti-detect browsers. When a profile is flagged the
 * agency finds out from Upwork, not from itself, and then has to physically
 * visit machines to work out which PC and which browser profile was responsible.
 * This module inventories what is INSTALLED and RUNNING on the PC — browser
 * extensions, installed programs, running processes, network adapters — and
 * posts it to POST /api/desktop/machine-report, where the server matches it
 * against the blocklist and surfaces it per machine and per employee.
 *
 * WHAT IT DOES NOT DO — this is employee monitoring, so the scope is a ceiling,
 * not a starting point:
 *   • no file contents, no browsing history, no cookies, no window titles,
 *     no keystrokes, no clipboard, no command lines, no screenshots;
 *   • no Google/Microsoft ACCOUNT identity — `Local State` also holds
 *     `gaia_name` / `user_name` (the signed-in email) right next to the profile
 *     label we do read, and those are deliberately never touched;
 *   • no extension `permissions` and no `from_webstore`. The source agent sent
 *     both; this one does not. The server stores neither, and the evaluator has
 *     an explicit rule that permissions must NEVER be matched on (folding them
 *     into the searched text mislabelled every extension asking for "proxy" —
 *     Similarweb, IDM, ad blockers — as a VPN). Sending an unused field is pure
 *     liability plus, at 40 strings x 800 extensions, most of the payload.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠ NEVER SEND A FIELD NAMED `profile`
 * ─────────────────────────────────────────────────────────────────────────────
 * The wire field naming an extension's Chrome profile is `browser_profile`. In
 * the source app a repeated `profile` key — a standard ModSecurity HTTP
 * Parameter Pollution rule target — made the host firewall answer the ENTIRE
 * upload with a plain-text 403 before it reached PHP. Because all four
 * inventories ship in one request, that silently lost extensions, programs,
 * processes AND network together for over half the fleet. Bisected field by
 * field: {name,id} => 200, {name,profile} => 403, {name,browser_profile} x500
 * carrying real labels => 401 (i.e. it passes the WAF and reaches auth).
 *
 * The same WAF has an XSS rule that 403s a body containing angle brackets, so
 * every string is scrubbed HERE, on the client. The server sanitises too, but
 * the server is behind the firewall — client-side scrubbing is what actually
 * stops a profile someone named "<test>" from killing the whole upload.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE EXTENSION BUG THAT MUST NOT COME BACK
 * ─────────────────────────────────────────────────────────────────────────────
 * Agent 0.4.5 decided "is this extension enabled?" with `ext.state === 1`. On
 * current Chrome/Edge `state` is `undefined`, so the check was false for every
 * extension and the agent reported ZERO extensions from every machine — while
 * the dashboard cheerfully showed the whole fleet as clean. See
 * isExtensionEnabled(): probe each known shape in order and DEFAULT TO ENABLED
 * when the shape is unknown. A wrong "disabled" guess loses the finding
 * entirely; a wrong "enabled" guess costs one extra row a human can dismiss.
 * The scan funnel (raw seen vs kept) is reported in telemetry every run, so the
 * same class of regression shows up as `ext=0/58` instead of a silent zero.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HARD RULES, ENFORCED HERE RATHER THAN BY THE CALLER
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. NEVER blocks the clock or the screenshot pipeline. Every filesystem read
 *     is async and the loop yields between browser profiles (a 27-profile PC has
 *     multi-megabyte `Secure Preferences` files; the source read them with
 *     readFileSync and stalled the Electron main thread). PowerShell probes run
 *     ONE AT A TIME with a timeout, so collection can never spike a low-end
 *     employee PC into missing a capture. Only one run may be in flight.
 *  2. NEVER exceeds the server's limits. Item caps mirror the controller's caps
 *     exactly and the serialised body is measured and trimmed to a budget under
 *     the server's 1 MB ceiling before anything is sent.
 *  3. NEVER hammers the server. A floor between uploads, jittered routine
 *     interval, exponential backoff on failure, and a long quiet period when the
 *     workspace is not entitled to the feature at all.
 *  4. NEVER fails loudly. Every probe is wrapped: a blocked PowerShell or a
 *     locked Chrome profile yields [] and the rest of the report still ships.
 *
 * Windows-only, exactly like the source. On macOS/Linux this is a no-op.
 */

const fsp = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { execFile } = require('node:child_process');

const api = require('./api');
const store = require('./store');
const report = require('./report');
const consent = require('./consent');

let appVersion = '?';
try { appVersion = require('../package.json').version; } catch { /* non-electron / test harness */ }

const LOCAL = process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData', 'Local');

/** Chromium user-data roots we inventory. Order is stable so runs are comparable. */
const BROWSERS = [
  ['Chrome', path.join(LOCAL, 'Google', 'Chrome', 'User Data')],
  ['Edge', path.join(LOCAL, 'Microsoft', 'Edge', 'User Data')],
  ['Brave', path.join(LOCAL, 'BraveSoftware', 'Brave-Browser', 'User Data')],
];

// ---- Scheduling -------------------------------------------------------------
// The source ran a full sweep every 15 minutes, which was right for one agency
// of ~15 PCs. This is a multi-tenant product with a 500-org / 100-user-each
// target: 15 minutes would be ~55 uploads/second fleet-wide, each carrying up to
// ~1,700 items. The routine sweep is therefore HOURLY (~14/s at that scale) and
// fast detection comes from the change watcher instead, which notices an
// extension being installed within ~60s and uploads then. Detection latency is
// unchanged for the case that matters; steady-state cost drops 4x.
const FIRST_RUN_DELAY_MS = 45_000;
const ROUTINE_INTERVAL_MS = 60 * 60_000;
const ROUTINE_JITTER_MS = 10 * 60_000;   // ±, so a fleet started by group policy cannot sync up
const MIN_INTERVAL_MS = 5 * 60_000;      // floor between uploads, whoever asks
const WATCH_POLL_MS = 60_000;

// ---- Backoff / suppression --------------------------------------------------
const BASE_BACKOFF_MS = 5 * 60_000;
const MAX_BACKOFF_MS = 6 * 60 * 60_000;
// A workspace without the `machine_compliance` entitlement gets a 403. Collecting
// endpoint inventory for a company that has not bought the feature is waste AND a
// privacy problem, so we go quiet for hours rather than retry. Held in memory
// only: restarting the app (what an operator tells the employee to do after
// granting the add-on) clears it immediately, and so does a fresh sign-in.
const NOT_ENTITLED_QUIET_MS = 6 * 60 * 60_000;

// ---- Payload budget ---------------------------------------------------------
// Item caps MIRROR MachineReportController::ITEM_CAPS. Sending more just burns
// the employee's bandwidth to have the server drop it.
const ITEM_CAPS = {
  extensions: 800,
  programs: 500,
  processes: 400,
  network: 60,
};
// The server rejects a body over 1 MB with 413. Stay clearly under it — the
// margin covers HTTP framing and any header the transport adds.
const MAX_BODY_BYTES = 900 * 1024;
// Trim order when the body is over budget: shed the least actionable inventory
// first. Extensions are the whole point of the feature and are trimmed last.
const TRIM_ORDER = ['processes', 'programs', 'network', 'extensions'];
// Mirrors Machine::FULL_INVENTORY_KINDS. These are the kinds the server treats
// as BLIND (unknown, not clean) when they arrive empty — so an empty block for
// them is meaningful and must be sent. Every other kind must be omitted instead.
const FULL_INVENTORY_KINDS = ['extensions', 'programs'];

// ---- Per-value caps (mirror the machine_inventory_items column widths) ------
const MAX_NAME = 200;
const MAX_BROWSER = 40;
const MAX_PROFILE = 60;
const MAX_PUBLISHER = 150;
const MAX_VERSION = 40;
const MAX_DETAIL = 200;
const MAX_ID = 64;

const PS_TIMEOUT_MS = 25_000;
const PS_MAX_BUFFER = 8 * 1024 * 1024;
// A Chromium preferences file this large is either corrupt or an attack on our
// own event loop (JSON.parse is synchronous and cannot be interrupted).
const MAX_PREFS_BYTES = 16 * 1024 * 1024;

// ---- Module state -----------------------------------------------------------
let running = false;            // one collection in flight at a time
let lastRunAt = 0;              // last time a run STARTED (debounce floor)
let rearmTimer = null;
let watchInterval = null;
let routineTimer = null;
let lastFingerprint = null;
let consecutiveFailures = 0;
let nextAttemptAt = 0;
let quietUntil = 0;             // entitlement / device-limit suppression
let quietReason = null;
let lastResult = null;          // surfaced in telemetry + the self-test
let extScanStats = { raw: 0, kept: 0, browsers: 0 };

/**
 * Scrub one attacker-supplied string for the wire.
 *
 * Angle brackets become SPACES (not ''), so removing them can never run two
 * words together into something misleading — and, more importantly, so the host
 * WAF's XSS rule never sees them and 403s the whole upload. Control characters
 * go too: they are what would let an extension name forge a newline inside the
 * Slack alert the server builds. Non-ASCII is deliberately KEPT — Urdu/Arabic
 * profile names are normal on this fleet and are verified to pass the WAF.
 */
function clean(value, max) {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  const out = String(value)
    // C0 + DEL + C1 + Unicode line/paragraph separators.
    .replace(/[\u0000-\u001F\u007F-\u009F\u2028\u2029]/g, ' ')
    .replace(/[<>]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
  return out === '' ? null : out;
}

/**
 * Run a PowerShell command and parse its JSON stdout. Never throws, never
 * rejects — a machine with PowerShell locked down by group policy simply yields
 * [] and the rest of the report still ships.
 *
 * -EncodedCommand (base64 UTF-16LE) rather than a -Command string: passing these
 * registry paths as a plain argument gets the backslashes and quotes mangled by
 * escaping, and the registry queries silently returned nothing.
 */
function ps(command) {
  return new Promise((resolve) => {
    let settled = false;
    const done = (rows) => { if (!settled) { settled = true; resolve(rows); } };
    try {
      const encoded = Buffer.from(command, 'utf16le').toString('base64');
      execFile(
        'powershell.exe',
        ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', encoded],
        { timeout: PS_TIMEOUT_MS, maxBuffer: PS_MAX_BUFFER, windowsHide: true },
        (err, stdout) => {
          if (err || !stdout) return done([]);
          try {
            const data = JSON.parse(stdout);
            done(Array.isArray(data) ? data : [data]);
          } catch { done([]); }
        },
      );
    } catch {
      done([]); // spawn itself refused (no shell, sandboxed, EPERM)
    }
  });
}

/**
 * Is this extension enabled?
 *
 * ⚠ READ THE FILE HEADER BEFORE TOUCHING THIS. Chromium has changed how it
 * records the answer more than once, so we probe each known shape in order and
 * DEFAULT TO ENABLED when the shape is unrecognised. Agent 0.4.5 checked only
 * `state === 1` — `undefined` on current Chrome/Edge — and dropped 100% of
 * extensions from every machine while the dashboard showed the fleet as clean.
 */
function isExtensionEnabled(ext) {
  // Current Chrome/Edge: an array of disable reasons — empty means enabled.
  if (Array.isArray(ext.disable_reasons)) return ext.disable_reasons.length === 0;
  // Some builds store the same thing as a numeric bitmask.
  if (typeof ext.disable_reasons === 'number') return ext.disable_reasons === 0;
  // Older Chrome: 1 = enabled, 0 = disabled.
  if (typeof ext.state === 'number') return ext.state === 1;
  return true; // unknown shape → keep it, never silently drop
}

/** Yield the event loop so a long scan cannot stall a screenshot or a heartbeat. */
function breathe() {
  return new Promise((resolve) => { setImmediate(resolve); });
}

/** Read + parse a JSON file, bounded. Returns null on anything unexpected. */
async function readJson(file) {
  try {
    const st = await fsp.stat(file);
    if (!st.isFile() || st.size > MAX_PREFS_BYTES) return null;
    return JSON.parse(await fsp.readFile(file, 'utf8'));
  } catch {
    return null; // absent, locked by a running browser, corrupt — all the same to us
  }
}

/**
 * Human-readable profile labels for one browser.
 *
 * Chromium's `Local State` holds `profile.info_cache`, mapping each profile
 * DIRECTORY ("Profile 22") to the label the user set in the profile switcher
 * ("Upwork Misbah"). Those labels are what makes a finding actionable — "a
 * scraper on PC-VA-7" cannot be acted on, "a scraper in the Upwork Misbah
 * profile" can, because that names the account at risk of the ban.
 *
 * ONLY `name` is read. The same records carry `gaia_name` and `user_name`, which
 * hold the signed-in Google ACCOUNT EMAIL. Those are never collected.
 */
async function profileLabels(userData) {
  const state = await readJson(path.join(userData, 'Local State'));
  const cache = state && state.profile && state.profile.info_cache;
  if (!cache || typeof cache !== 'object') return {};
  const out = {};
  for (const [dir, info] of Object.entries(cache)) {
    if (info && typeof info.name === 'string' && info.name.trim()) out[dir] = info.name;
  }
  return out;
}

/**
 * Installed browser extensions across every Chrome/Edge/Brave profile.
 *
 * Fully async and yielding: this used to be a wall of readFileSync on files that
 * reach several megabytes on a 27-profile machine, run on the Electron main
 * thread that also drives the clock and the capture scheduler.
 */
async function collectExtensions() {
  const out = [];
  let raw = 0;
  let browsersFound = 0;

  for (const [browser, userData] of BROWSERS) {
    let profiles;
    try {
      const entries = await fsp.readdir(userData, { withFileTypes: true });
      profiles = entries.filter((d) => d.isDirectory()).map((d) => d.name);
    } catch {
      continue; // this browser is not installed on this PC
    }
    browsersFound++;

    const labels = await profileLabels(userData);

    for (const profile of profiles) {
      await breathe();

      // The switcher label ("Upwork Misbah") when Chromium knows one, else the
      // bare directory ("Profile 22") so a finding is never left unattributed.
      const browserProfile = clean(labels[profile] || profile, MAX_PROFILE);

      // Which extension ids are actually UNPACKED ON DISK for this profile. Used
      // to drop account-SYNCED ghosts that Chromium knows about but that are not
      // really installed here — the reason two people sharing one Google account
      // were reported with identical extension lists.
      let installedIds = null;
      try {
        installedIds = new Set(await fsp.readdir(path.join(userData, profile, 'Extensions')));
      } catch {
        installedIds = null; // no Extensions dir → cannot tell → do not filter
      }

      for (const file of ['Secure Preferences', 'Preferences']) {
        const prefs = await readJson(path.join(userData, profile, file));
        const settings = prefs && prefs.extensions && prefs.extensions.settings;
        if (!settings || typeof settings !== 'object') continue;

        for (const [id, ext] of Object.entries(settings)) {
          const man = ext && ext.manifest;
          if (!man || !man.name) continue;                          // stub record, not an install
          if (ext.location === 5 || ext.location === 10) continue;   // component/system, not user-installed
          raw++;
          if (!isExtensionEnabled(ext)) continue;                    // owner policy: enabled only
          if (installedIds && !installedIds.has(id)) continue;       // synced ghost, not on this disk

          let name = man.name;
          // Localised manifests store a placeholder; the unpacked directory name
          // is the only identity available without reading _locales.
          if (typeof name === 'string' && name.startsWith('__MSG_')) name = ext.path || id;

          const cleanName = clean(name, MAX_NAME);
          if (!cleanName) continue;

          out.push({
            // `browser_profile` — NEVER `profile`. See the file header.
            browser: clean(browser, MAX_BROWSER),
            browser_profile: browserProfile,
            id: clean(id, MAX_ID),
            name: cleanName,
            version: clean(man.version, MAX_VERSION),
            // Always true by construction (disabled ones are skipped above). The
            // server does NOT filter on this — an older agent that reported
            // enabled=false for everything had 100% of its extensions dropped
            // server-side once, and that filter was removed for good.
            enabled: true,
          });
        }
        break; // one preferences file per profile is enough
      }
    }
  }

  // Dedup by browser + PROFILE + id. `Secure Preferences` and `Preferences`
  // overlap within a profile, so duplicates are expected. THE PROFILE MUST BE IN
  // THE KEY: keying on browser+id alone collapses every copy of an extension
  // down to whichever profile was enumerated first — and "Default" always sorts
  // first — so a scraper present in both "Default" and "Upwork Faryal" was
  // reported as living only in Default. That is a false all-clear on the exact
  // Upwork profile at risk of the ban.
  const seen = new Set();
  const deduped = [];
  for (const e of out) {
    const key = e.browser + '|' + e.browser_profile + '|' + e.id;
    if (seen.has(key)) continue;
    seen.add(key);
    deduped.push(e);
  }

  extScanStats = { raw, kept: deduped.length, browsers: browsersFound };
  return deduped;
}

/**
 * Installed programs, from the registry uninstall keys.
 *
 * Opens BOTH the 64- and 32-bit registry views explicitly through .NET so the
 * result does not depend on the bitness of the host process — a plain
 * `HKLM:\SOFTWARE\...` query silently returns nothing from a redirected 32-bit
 * process, which is how half a fleet can look like it has no software on it.
 */
async function collectPrograms() {
  const rows = await ps([
    '$o=@();',
    "foreach($h in 'LocalMachine','CurrentUser'){foreach($v in 'Registry64','Registry32'){try{",
    '$b=[Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::$h,[Microsoft.Win32.RegistryView]::$v);',
    "$u=$b.OpenSubKey('SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall');",
    "if($u){foreach($n in $u.GetSubKeyNames()){$k=$u.OpenSubKey($n);$d=$k.GetValue('DisplayName');",
    "if($d){$o+=[pscustomobject]@{name=$d;version=$k.GetValue('DisplayVersion');publisher=$k.GetValue('Publisher')}}}}",
    '}catch{}}}',
    '$o | Sort-Object name -Unique | ConvertTo-Json -Compress',
  ].join(''));

  const out = [];
  for (const r of rows) {
    if (!r || typeof r !== 'object') continue;
    const name = clean(r.name, MAX_NAME);
    if (!name) continue;
    out.push({
      name,
      version: clean(r.version, MAX_VERSION),
      publisher: clean(r.publisher, MAX_PUBLISHER),
    });
  }
  return out;
}

/**
 * Distinct running processes: image name + executable path.
 *
 * Stored server-side only when a process HITS the blocklist (a running-process
 * list is enormous and changes by the minute, and the dashboard has no browse
 * view for it), which is what catches an auto-refresh tool or a jiggler that is
 * running right now from a folder rather than installed.
 */
async function collectProcesses() {
  const rows = await ps(
    'Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | '
    + "Select-Object @{n='name';e={$_.Name}},@{n='detail';e={$_.ExecutablePath}} | "
    + 'Sort-Object name -Unique | ConvertTo-Json -Compress',
  );

  const out = [];
  for (const r of rows) {
    if (!r || typeof r !== 'object') continue;
    const name = clean(r.name, MAX_NAME);
    if (!name) continue;
    out.push({ name, detail: clean(r.detail, MAX_DETAIL) });
  }
  return out;
}

/**
 * Network adapters. VPN and proxy tunnels surface here as adapters, and the
 * product name is usually in the interface DESCRIPTION ("TAP-Windows Adapter
 * V9", "WireGuard Tunnel"), not the user-renameable adapter name — so both are
 * sent, and the server matches on both for this kind.
 *
 * By owner policy these findings are WATCH-ONLY: recorded and shown, never
 * Slacked. VPNs are used for legitimate client work in the office.
 */
async function collectNetwork() {
  const rows = await ps(
    'Get-NetAdapter -ErrorAction SilentlyContinue | '
    + "Select-Object @{n='name';e={$_.Name}},@{n='detail';e={$_.InterfaceDescription}} | "
    + 'ConvertTo-Json -Compress',
  );

  const out = [];
  for (const r of rows) {
    if (!r || typeof r !== 'object') continue;
    const name = clean(r.name, MAX_NAME);
    if (!name) continue;
    out.push({ name, detail: clean(r.detail, MAX_DETAIL) });
  }
  return out;
}

/**
 * FINAL GATE on everything that goes on the wire.
 *
 * Each probe already scrubs its own output, but the guarantee that no angle
 * bracket, control character or over-long value ever reaches the firewall is too
 * important to be spread across four functions where a future probe can forget
 * it. One item that slips through does not lose one row — it gets the WHOLE
 * upload 403'd, taking all four inventories with it. So the payload is rebuilt
 * here, field by field, from a fixed whitelist of keys.
 *
 * That whitelist is also what keeps scope creep out: a probe cannot start
 * shipping a new field by accident, because anything not named here is dropped.
 *
 * @param {string} kind
 * @param {Array<Record<string, unknown>>} items
 */
function sanitizeItems(kind, items) {
  const out = [];
  const seen = new Set();

  for (const raw of Array.isArray(items) ? items : []) {
    if (!raw || typeof raw !== 'object') continue;

    const name = clean(raw.name, MAX_NAME);
    if (!name) continue; // a nameless item is not actionable

    // ⚠ `browser_profile` — NEVER `profile`. See the file header.
    const item = { name };
    if (kind === 'extensions') {
      item.browser = clean(raw.browser, MAX_BROWSER);
      item.browser_profile = clean(raw.browser_profile, MAX_PROFILE);
      item.id = clean(raw.id, MAX_ID);
      item.version = clean(raw.version, MAX_VERSION);
      item.enabled = true;
    } else if (kind === 'programs') {
      item.version = clean(raw.version, MAX_VERSION);
      item.publisher = clean(raw.publisher, MAX_PUBLISHER);
    } else {
      item.detail = clean(raw.detail, MAX_DETAIL);
    }

    // Mirror the server's per-kind signature so an exact duplicate is dropped
    // here rather than colliding inside its upsert statement. Two Chrome
    // profiles holding the same extension are two rows BY DESIGN, so the
    // profile is part of the extension key.
    const signature = kind === 'extensions'
      ? (item.browser || '') + '|' + (item.browser_profile || '') + '|' + (item.id || item.name)
      : kind === 'programs'
        ? item.name + '|' + (item.publisher || '')
        : (item.detail || item.name);

    const key = signature.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);

    out.push(item);
  }

  return out;
}

/**
 * Apply the per-kind item caps, then trim the serialised body to the byte
 * budget. Both caps exist server-side too; enforcing them here means the
 * employee's connection never carries bytes the server is going to discard, and
 * a machine with a pathological inventory can never 413 itself into never
 * reporting at all.
 *
 * @param {number} [budget] override, for the self-test only
 * @returns {{ body: string, counts: Record<string, number>, trimmed: Record<string, number> }}
 */
function fitPayload(envelope, kinds, budget) {
  const maxBytes = Number.isFinite(budget) && budget > 0 ? budget : MAX_BODY_BYTES;
  const trimmed = {};

  for (const kind of Object.keys(kinds)) {
    kinds[kind] = sanitizeItems(kind, kinds[kind]);
    const cap = ITEM_CAPS[kind] || 200;
    if (kinds[kind].length > cap) {
      trimmed[kind] = kinds[kind].length - cap;
      kinds[kind] = kinds[kind].slice(0, cap);
    }
  }

  const build = () => {
    const reports = [];
    for (const kind of ['extensions', 'programs', 'processes', 'network']) {
      if (!kinds[kind]) continue;
      // A processes/network block that the byte trim has emptied must be
      // DROPPED, not sent as `items: []`. Those kinds are not blind-protected
      // server-side: an empty list is taken as truth and sweeps their findings
      // closed, so shipping one would turn "we had to shed these bytes" into a
      // false all-clear on a live VPN or jiggler. Extensions and programs are
      // the opposite — an empty list there is exactly how the server is told the
      // machine is UNKNOWN rather than clean, so those blocks always ship.
      if (kinds[kind].length === 0 && !FULL_INVENTORY_KINDS.includes(kind)) continue;
      reports.push({ kind, collected_at: envelope.collected_at, items: kinds[kind] });
    }
    return { ...envelope.head, reports };
  };

  let payload = build();
  let body = JSON.stringify(payload);

  // Shed the least actionable inventory first, 20% at a time, so one oversized
  // machine still reports its extensions rather than reporting nothing.
  let guard = 0;
  while (Buffer.byteLength(body) > maxBytes && guard++ < 200) {
    let shed = false;
    for (const kind of TRIM_ORDER) {
      const list = kinds[kind];
      if (!list || list.length === 0) continue;
      const keep = Math.max(0, Math.floor(list.length * 0.8) - 1);
      trimmed[kind] = (trimmed[kind] || 0) + (list.length - keep);
      kinds[kind] = list.slice(0, keep);
      shed = true;
      break;
    }
    if (!shed) break; // everything is empty and it is still too big — impossible, but do not spin
    payload = build();
    body = JSON.stringify(payload);
  }

  const counts = {};
  for (const kind of Object.keys(kinds)) counts[kind] = kinds[kind].length;

  return { body, counts, trimmed };
}

/**
 * The four probes, in one indirection so the standalone self-test can swap them
 * for fixtures. Nothing else may reassign these.
 */
const probes = {
  extensions: collectExtensions,
  programs: collectPrograms,
  processes: collectProcesses,
  network: collectNetwork,
};

function backoffDelay(failures) {
  const raw = Math.min(BASE_BACKOFF_MS * Math.pow(2, Math.max(0, failures - 1)), MAX_BACKOFF_MS);
  const jitter = raw * 0.2 * (Math.random() - 0.5) * 2; // ±20% so a fleet cannot sync up
  return Math.max(30_000, Math.round(raw + jitter));
}

/**
 * Interpret a failed upload. The compliance ingest is the one endpoint that can
 * legitimately answer "not for you" (no entitlement) or "you have too many
 * devices", and neither is worth retrying on the normal schedule.
 */
function handleFailure(err) {
  const status = err && err.response ? err.response.status : null;
  const code = err && err.response && err.response.data ? err.response.data.code : null;

  if (status === 403) {
    // No `machine_compliance` entitlement (or no workspace). Stop collecting.
    quietUntil = Date.now() + NOT_ENTITLED_QUIET_MS;
    quietReason = code || 'forbidden';
    consecutiveFailures = 0;
    nextAttemptAt = 0;
    report.info('machine_report_disabled', 'workspace is not entitled to machine compliance — pausing inventory', {
      code: quietReason,
      quiet_hours: Math.round(NOT_ENTITLED_QUIET_MS / 3_600_000),
    });
    return 'not_entitled';
  }

  if (status === 422 && code === 'device_limit_reached') {
    quietUntil = Date.now() + NOT_ENTITLED_QUIET_MS;
    quietReason = code;
    report.warn('machine_report_device_limit', 'this account has registered too many devices — pausing inventory');
    return 'device_limit';
  }

  if (status === 401) {
    // The api layer already dropped the token and told the UI. Nothing to retry
    // against until someone signs in again.
    return 'unauthenticated';
  }

  consecutiveFailures++;
  let delay = backoffDelay(consecutiveFailures);

  if (status === 429) {
    const retryAfter = Number(err.response.headers && (err.response.headers['retry-after'] || err.response.headers['Retry-After']));
    if (Number.isFinite(retryAfter) && retryAfter > 0) delay = Math.max(delay, Math.min(retryAfter * 1000, MAX_BACKOFF_MS));
  }

  nextAttemptAt = Date.now() + delay;

  report.warn('machine_report_failed', (err && err.message ? err.message : String(err)).slice(0, 200), {
    status,
    code,
    failures: consecutiveFailures,
    backoff_seconds: Math.round(delay / 1000),
  });

  return status === 413 ? 'too_large' : 'failed';
}

/**
 * Gather everything and upload once.
 *
 * Called from a routine timer, shortly after launch, on sign-in, right after a
 * clock-in, and whenever the extension watcher notices a change. EVERY outcome —
 * including the no-op branches — is reported to the diagnostics pipe, so a
 * machine that is silent is diagnosable instead of invisible.
 *
 * Resolves (never rejects) with a short outcome string.
 *
 * @param {string} [reason] what triggered this run — telemetry only
 */
async function run(reason) {
  const trigger = reason || 'timer';

  if (process.platform !== 'win32') { lastResult = 'skip_platform'; return lastResult; }
  if (!store.get('token')) { lastResult = 'skip_signed_out'; return lastResult; }
  // NOTHING is inventoried before the employee has passed the consent gate.
  // This module is started from app.whenReady() and runs 45s later on its own
  // timer, independently of the renderer — so unlike screenshots and activity
  // (which cannot happen until someone starts the tracker, which lives behind
  // the gate) it would otherwise be the ONE capture path that runs on a machine
  // whose user has not yet been told anything. The consent screen names this
  // inventory explicitly; collecting it before the tick would make that
  // disclosure a lie.
  if (!consent.hasAccepted()) { lastResult = 'skip_no_consent'; return lastResult; }
  if (running) { lastResult = 'skip_running'; return lastResult; }

  const now = Date.now();

  if (now < quietUntil) { lastResult = 'skip_quiet'; return lastResult; }
  if (now < nextAttemptAt) { lastResult = 'skip_backoff'; return lastResult; }

  // Debounce. The routine timer, a clock-in and a filesystem change can land
  // together; one upload is enough. A swallowed call RE-ARMS rather than
  // vanishing — otherwise an extension installed just after a routine run waits
  // a whole hour, which is precisely the latency the watcher exists to remove.
  if (now - lastRunAt < MIN_INTERVAL_MS) {
    if (!rearmTimer) {
      const wait = MIN_INTERVAL_MS - (now - lastRunAt) + 1_000;
      rearmTimer = setTimeout(() => {
        rearmTimer = null;
        run(trigger + '_rearmed').catch(() => {});
      }, wait);
      rearmTimer.unref?.();
    }
    lastResult = 'skip_debounced';
    return lastResult;
  }

  running = true;
  lastRunAt = now;

  try {
    // SEQUENTIAL on purpose. The source fired all three PowerShell probes at
    // once; on a low-end employee PC that is three powershell.exe processes
    // competing with the capture pipeline. One at a time costs a few seconds of
    // wall clock nobody is waiting on, and cannot spike the machine.
    // Sanitised immediately, so the "did this probe see anything?" decision
    // below is made on what would actually SHIP, not on raw rows that the final
    // gate is about to discard.
    const extensions = sanitizeItems('extensions', await probes.extensions().catch(() => []));
    const programs = sanitizeItems('programs', await probes.programs().catch(() => []));
    const processes = sanitizeItems('processes', await probes.processes().catch(() => []));
    const network = sanitizeItems('network', await probes.network().catch(() => []));

    const collectedAt = new Date().toISOString();
    const kinds = {};

    // Extensions and programs are always reported, EVEN WHEN EMPTY. The server
    // treats an empty inventory for these kinds as "the collector could not
    // see", not "nothing is installed": it keeps the previous inventory, leaves
    // open findings open, and records the machine as unknown rather than clean.
    kinds.extensions = extensions;
    kinds.programs = programs;

    // Processes and network are reported ONLY when the probe actually returned
    // rows. These kinds are NOT blind-protected server-side — an empty list is
    // taken as truth and sweeps their findings closed. A live Windows PC always
    // has processes and at least a loopback adapter, so zero rows means the
    // probe failed (PowerShell blocked by policy, CIM service down), and
    // reporting that as "no VPN adapter here any more" would be a false
    // all-clear on exactly the finding this feature exists to keep.
    if (processes.length) kinds.processes = processes;
    if (network.length) kinds.network = network;

    const { body, counts, trimmed } = fitPayload({
      collected_at: collectedAt,
      head: {
        device_name: clean(store.get('deviceName') || os.hostname(), 200),
        // The server compares this against its minimum agent version to decide
        // whether an empty extension list means "clean" or "this agent is too
        // old to look" — the stale-agent warning. It must always be sent.
        app_version: clean(appVersion, 32),
        platform: clean(process.platform, 32),
      },
    }, kinds);

    const result = await api.sendMachineReport(body);

    consecutiveFailures = 0;
    nextAttemptAt = 0;
    quietUntil = 0;
    quietReason = null;
    lastResult = 'sent';

    report.info('machine_report', 'inventory sent (' + trigger + ')', {
      ext: counts.extensions + '/' + extScanStats.raw,
      browsers: extScanStats.browsers,
      prog: counts.programs,
      proc: counts.processes ?? 0,
      net: counts.network ?? 0,
      bytes: Buffer.byteLength(body),
      trimmed: Object.keys(trimmed).length ? trimmed : null,
      flagged: result && typeof result.flagged === 'number' ? result.flagged : null,
    });

    return lastResult;
  } catch (err) {
    lastResult = handleFailure(err);
    return lastResult;
  } finally {
    running = false;
  }
}

/**
 * Notice extension changes quickly WITHOUT watching a churning directory tree.
 *
 * Chromium rewrites `<profile>/Secure Preferences` whenever an extension is
 * added, removed, enabled or disabled, so its mtime is a reliable change signal.
 * We poll those mtimes instead of using a recursive fs.watch on `User Data`:
 * that tree also carries caches, history and cookies, which churn constantly, so
 * on a 27-profile machine a recursive watch fires thousands of times a minute
 * for changes nobody cares about. A stat per profile is bounded, predictable
 * work with no handle to be invalidated when the browser updates itself.
 *
 * This is what keeps detection fast now that the routine sweep is hourly: a
 * scraper installed at 09:05 is on the dashboard by ~09:06, not ~10:05.
 */
async function extensionFingerprint() {
  const parts = [];
  for (const [, userData] of BROWSERS) {
    let dirs;
    try {
      const entries = await fsp.readdir(userData, { withFileTypes: true });
      dirs = entries.filter((d) => d.isDirectory()).map((d) => d.name);
    } catch { continue; }

    for (const dir of dirs) {
      for (const leaf of ['Secure Preferences', 'Extensions']) {
        try {
          const st = await fsp.stat(path.join(userData, dir, leaf));
          parts.push(dir + ':' + leaf + ':' + Math.floor(st.mtimeMs));
        } catch { /* absent for this profile */ }
      }
    }
  }
  return parts.join('|');
}

function watchForChanges() {
  if (process.platform !== 'win32' || watchInterval) return;

  extensionFingerprint().then((fp) => { lastFingerprint = fp; }).catch(() => {});

  watchInterval = setInterval(() => {
    extensionFingerprint().then((fp) => {
      if (lastFingerprint === null) { lastFingerprint = fp; return; }
      if (fp === lastFingerprint) return;
      lastFingerprint = fp;
      // run() debounces and re-arms, so the burst of writes Chromium makes
      // during an install still results in exactly one upload.
      run('extension_change').catch(() => {});
    }).catch(() => {});
  }, WATCH_POLL_MS);
  watchInterval.unref?.();

  report.info('collector_watch', 'polling browser profile stores for extension changes every 60s');
}

/**
 * Start the routine sweep. Jittered rather than a fixed interval: a fleet whose
 * machines all boot at 09:00 under group policy would otherwise upload in
 * lockstep forever and turn an hourly cadence into an hourly spike.
 */
function scheduleRoutine() {
  if (routineTimer) clearTimeout(routineTimer);
  const jitter = Math.round((Math.random() - 0.5) * 2 * ROUTINE_JITTER_MS);
  routineTimer = setTimeout(() => {
    run('timer').catch(() => {});
    scheduleRoutine();
  }, Math.max(60_000, ROUTINE_INTERVAL_MS + jitter));
  routineTimer.unref?.();
}

/**
 * Wire the collector up. Called once from main.js after the app is ready.
 * Nothing here awaits anything: startup must not wait on compliance.
 */
function init() {
  if (process.platform !== 'win32') {
    report.info('machine_report_skip', 'endpoint inventory is Windows-only; not scheduled on ' + process.platform);
    return;
  }
  const first = setTimeout(() => { run('launch').catch(() => {}); }, FIRST_RUN_DELAY_MS);
  first.unref?.();
  scheduleRoutine();
  watchForChanges();
}

/**
 * Sign-in clears any entitlement suppression: an operator who just granted the
 * add-on should not have to wait out the quiet window.
 */
function onSignIn() {
  quietUntil = 0;
  quietReason = null;
  consecutiveFailures = 0;
  nextAttemptAt = 0;
}

/** Snapshot for the hourly agent_state telemetry and the self-test. */
function stats() {
  return {
    running,
    last_result: lastResult,
    last_run_at: lastRunAt || null,
    consecutive_failures: consecutiveFailures,
    backing_off: Date.now() < nextAttemptAt,
    quiet: Date.now() < quietUntil,
    quiet_reason: quietReason,
    extensions_raw: extScanStats.raw,
    extensions_kept: extScanStats.kept,
    browsers: extScanStats.browsers,
    app_version: appVersion,
  };
}

/** Test hook — resets module state between self-test cases. */
function _reset() {
  running = false;
  lastRunAt = 0;
  consecutiveFailures = 0;
  nextAttemptAt = 0;
  quietUntil = 0;
  quietReason = null;
  lastResult = null;
  extScanStats = { raw: 0, kept: 0, browsers: 0 };
  if (rearmTimer) { clearTimeout(rearmTimer); rearmTimer = null; }
  if (routineTimer) { clearTimeout(routineTimer); routineTimer = null; }
  if (watchInterval) { clearInterval(watchInterval); watchInterval = null; }
  lastFingerprint = null;
}

module.exports = {
  init,
  run,
  onSignIn,
  watchForChanges,
  stats,
  // Exported for the standalone self-test (scripts/collector-selftest.js).
  _reset,
  _probes: probes,
  _clean: clean,
  _isExtensionEnabled: isExtensionEnabled,
  _fitPayload: fitPayload,
  _collectExtensions: collectExtensions,
  ITEM_CAPS,
  MAX_BODY_BYTES,
  MIN_INTERVAL_MS,
};
