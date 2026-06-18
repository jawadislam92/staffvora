import React, { useEffect, useMemo, useRef, useState } from 'react';

// Dark searchable client combobox. Native <select> popups are OS-white and
// unusable with hundreds of clients; this filters as you type.
function ClientPicker({ clients, value, onChange, disabled }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);
  const rootRef = useRef(null);
  const inputRef = useRef(null);

  const selected = clients.find((client) => String(client.id) === String(value));

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return clients;
    return clients.filter((client) => client.name.toLowerCase().includes(q));
  }, [clients, query]);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  useEffect(() => {
    if (open) {
      setQuery('');
      setActiveIndex(0);
      setTimeout(() => inputRef.current?.focus(), 0);
    }
  }, [open]);

  const pick = (client) => {
    onChange(String(client.id));
    setOpen(false);
  };

  const onKeyDown = (e) => {
    if (e.key === 'Escape') { setOpen(false); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActiveIndex((i) => Math.min(i + 1, filtered.length - 1)); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActiveIndex((i) => Math.max(i - 1, 0)); return; }
    if (e.key === 'Enter') {
      e.preventDefault();
      if (filtered[activeIndex]) pick(filtered[activeIndex]);
    }
  };

  return (
    <div className="client-picker" ref={rootRef}>
      <button
        type="button"
        className={`cp-trigger ${selected ? '' : 'placeholder'}`}
        onClick={() => !disabled && setOpen((o) => !o)}
        disabled={disabled}
      >
        <span className="cp-trigger-label">{selected ? selected.name : 'Select client'}</span>
        <span className="cp-caret">▾</span>
      </button>
      {open && (
        <div className="cp-pop">
          <input
            ref={inputRef}
            className="cp-search"
            value={query}
            placeholder="Search clients…"
            onChange={(e) => { setQuery(e.target.value); setActiveIndex(0); }}
            onKeyDown={onKeyDown}
          />
          <div className="cp-list">
            {filtered.length === 0 && <div className="cp-empty">No clients match “{query}”.</div>}
            {filtered.slice(0, 200).map((client, index) => (
              <button
                type="button"
                key={client.id}
                className={[
                  'cp-item',
                  index === activeIndex ? 'active' : '',
                  String(client.id) === String(value) ? 'selected' : '',
                ].join(' ').trim()}
                onMouseEnter={() => setActiveIndex(index)}
                onClick={() => pick(client)}
              >
                <span className="cp-item-name">{client.name}</span>
                {client.internal
                  ? <span className="cp-item-meta">Internal — no client</span>
                  : client.upwork_profile_name && <span className="cp-item-meta">{client.upwork_profile_name}</span>}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function fmtClock(seconds) {
  const s = Math.max(0, Math.floor(seconds || 0));
  const hh = Math.floor(s / 3600);
  const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
  const ss = String(s % 60).padStart(2, '0');
  return hh > 0 ? `${hh}:${mm}:${ss}` : `${mm}:${ss}`;
}

function fmtShort(seconds) {
  const s = Math.max(0, Math.floor(seconds || 0));
  const hours = Math.floor(s / 3600);
  const minutes = Math.max(1, Math.floor((s % 3600) / 60));
  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}

function sessionTitle(session, clients) {
  if (session?.task_note) return session.task_note;
  if (session?.client_name) return session.client_name;
  const client = clients.find((item) => Number(item.id) === Number(session?.client_id));
  return client?.name || 'Client work';
}

// The per-entry work type (an Upwork billing category reviewed weekly). For a
// "tracker_manual" client the user chooses Tracker or Manual; fixed/outside
// clients have a single fixed category.
function workTypeOptions(client) {
  if (!client) return [];
  switch (client.work_type) {
    case 'tracker_manual':
      return [
        { value: 'tracker', label: 'Tracker' },
        { value: 'manual', label: 'Manual' },
      ];
    case 'fixed':
      return [{ value: 'fixed', label: 'Fixed' }];
    case 'outside_of_upwork':
      return [{ value: 'outside_of_upwork', label: 'Outside Upwork' }];
    case 'office_work':
      return [{ value: 'office_work', label: 'Office Work' }];
    case 'test_task':
      return [{ value: 'test_task', label: 'Test Task' }];
    case 'upwork_bidding':
      return [{ value: 'upwork_bidding', label: 'Upwork Bidding' }];
    default:
      return [
        { value: 'tracker', label: 'Tracker' },
        { value: 'manual', label: 'Manual' },
      ];
  }
}

// Internal (no-client) work the team can track: sessions start with no
// client_id and the hours land in Reports under the matching work type.
// Negative ids keep all the Number(id) comparisons in this file safe.
const INTERNAL_CLIENTS = [
  { id: -1, name: 'Office Work', work_type: 'office_work', internal: true },
  { id: -2, name: 'Test Task', work_type: 'test_task', internal: true },
  { id: -3, name: 'Upwork Bidding', work_type: 'upwork_bidding', internal: true },
];

// Selectable appearance themes (CSS lives in styles.css under [data-theme]).
const THEMES = [
  { id: 'cinematic', name: 'Cinematic' },
  { id: 'light', name: 'Light' },
  { id: 'midnight', name: 'Midnight' },
];

// The progress ring fills against a notional 8h day.
const DAILY_GOAL_SECONDS = 8 * 3600;
const RING_RADIUS = 88;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

// Resolve a saved session with no client back to its internal pseudo-client,
// so Office Work / Test Task rows behave like client rows (title + resume).
function internalClientFor(session) {
  if (session?.client_id) return null;
  return INTERNAL_CLIENTS.find((c) => c.work_type === session?.work_type) || null;
}

// What actually goes to the server: internal pseudo-clients have no real row.
function payloadClientId(client) {
  return client?.internal ? null : Number(client.id);
}

function defaultWorkType(client) {
  const options = workTypeOptions(client);
  return options[0]?.value || '';
}

export default function Tracker({ user, apiBaseUrl, onLogout }) {
  const [status, setStatus] = useState({ running: false, session: null, pending: { screenshots: 0, activity: 0, heartbeats: 0 } });
  const [clients, setClients] = useState([]);
  const [todaySessions, setTodaySessions] = useState([]);
  const [week, setWeek] = useState([]);
  const [recentClients, setRecentClients] = useState([]);
  const [picker, setPicker] = useState({ client_id: '', work_type: '', task_note: '' });
  const [error, setError] = useState('');
  const [warning, setWarning] = useState('');
  const [busy, setBusy] = useState(false);
  const [ticker, setTicker] = useState(0);
  const [menuOpen, setMenuOpen] = useState(false);
  const [autoLaunch, setAutoLaunch] = useState(false);
  const [prefs, setPrefs] = useState({});
  const [appVersion, setAppVersion] = useState('');
  const [timeClock, setTimeClock] = useState({ last_action: null, available: [] });
  const [clockBusy, setClockBusy] = useState(false);
  const [updateInfo, setUpdateInfo] = useState(null);
  const autoStartedRef = useRef(false);

  // Auto-update lifecycle: progress + ready states render as an in-app
  // banner (bottom of the window) instead of OS dialogs.
  useEffect(() => {
    if (typeof window.tt?.updates?.onEvent !== 'function') return undefined;
    const off = window.tt.updates.onEvent((event) => {
      if (!event || event.type === 'error') {
        setUpdateInfo(null);
        return;
      }
      setUpdateInfo(event);
    });
    return () => off?.();
  }, []);

  const refreshTimeClock = async () => {
    if (typeof window.tt?.timeclock?.status !== 'function') return;
    try {
      setTimeClock(await window.tt.timeclock.status());
    } catch {
      // Shift bar simply stays in its last state when offline.
    }
  };

  const clockAct = async (actionType) => {
    if (clockBusy || typeof window.tt?.timeclock?.act !== 'function') return;
    setClockBusy(true);
    setError('');
    try {
      const next = await window.tt.timeclock.act(actionType);
      setTimeClock(next);
    } catch (err) {
      setError(err?.message || 'Time clock action failed');
    } finally {
      setClockBusy(false);
    }
  };

  useEffect(() => {
    if (typeof window.tt?.settings?.getPrefs === 'function') {
      window.tt.settings.getPrefs().then((p) => setPrefs(p || {})).catch(() => {});
    }
    if (typeof window.tt?.appInfo?.version === 'function') {
      window.tt.appInfo.version().then((v) => setAppVersion(v || '')).catch(() => {});
    }
    refreshTimeClock();
  }, []);

  // The shift can change from the web dashboard too — refetch whenever the
  // window regains focus and on a slow background cadence so the shift bar
  // never shows a stale state.
  useEffect(() => {
    const onFocus = () => refreshTimeClock();
    window.addEventListener('focus', onFocus);
    const interval = setInterval(refreshTimeClock, 60_000);
    return () => {
      window.removeEventListener('focus', onFocus);
      clearInterval(interval);
    };
  }, []);

  useEffect(() => {
    if (!menuOpen || typeof window.tt?.settings?.getAutoLaunch !== 'function') return;
    window.tt.settings.getAutoLaunch().then((result) => setAutoLaunch(!!result?.enabled)).catch(() => {});
  }, [menuOpen]);

  const setPref = async (key, value) => {
    if (typeof window.tt?.settings?.setPrefs !== 'function') return;
    const next = await window.tt.settings.setPrefs({ [key]: value });
    setPrefs(next || {});
  };

  // Apply the saved appearance theme to the document root whenever it changes.
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', prefs.theme || 'cinematic');
  }, [prefs.theme]);

  const applyTheme = (themeId) => {
    document.documentElement.setAttribute('data-theme', themeId);
    setPref('theme', themeId);
  };

  // Screenshot notification: local pref overrides the admin default when set.
  const effectiveNotifyScreenshot = prefs.notifyScreenshot === null || prefs.notifyScreenshot === undefined
    ? !!status.settings?.notify_on_screenshot
    : !!prefs.notifyScreenshot;

  const selectedClient = useMemo(
    () => clients.find((client) => String(client.id) === String(picker.client_id)),
    [clients, picker.client_id]
  );

  const workTypeChoices = useMemo(() => workTypeOptions(selectedClient), [selectedClient]);
  const effectiveWorkType = useMemo(() => {
    if (picker.work_type && workTypeChoices.some((option) => option.value === picker.work_type)) {
      return picker.work_type;
    }
    return defaultWorkType(selectedClient);
  }, [picker.work_type, workTypeChoices, selectedClient]);
  const descriptionValid = picker.task_note.trim().length > 0;

  const handleClientChange = (clientId) => {
    const client = clients.find((item) => String(item.id) === String(clientId));
    setPicker((prev) => ({ ...prev, client_id: clientId, work_type: defaultWorkType(client) }));
  };

  // One-click client switching. Works whether or not a session is running:
  // a running session is stopped (and saved) first, then the clicked client
  // starts immediately with its last work type and description.
  const switchTo = async ({ client_id, work_type, task_note }) => {
    if (busy) return;
    const client = clients.find((item) => Number(item.id) === Number(client_id));
    if (!client) return;
    if (status.running && Number(status.session?.client_id) === Number(client_id)) return; // already on it

    const options = workTypeOptions(client);
    const wt = options.find((option) => option.value === work_type)?.value || defaultWorkType(client);
    const note = (task_note || '').trim() || 'Continued work';

    setPicker({ client_id: String(client.id), work_type: wt, task_note: note });
    setError('');
    setBusy(true);

    try {
      if (status.running) {
        await window.tt.tracker.stop({});
      }
      const next = await window.tt.tracker.start({
        client_id: payloadClientId(client),
        upwork_profile_id: client.upwork_profile_id || null,
        work_type: wt,
        task_note: note,
      });
      setStatus(next);
      setWarning(`Now tracking ${client.name}.`);
      try {
        localStorage.setItem('tt.lastStart', JSON.stringify({ client_id: client.id, work_type: wt, task_note: note }));
      } catch { /* non-essential */ }
      await refreshToday();
      refreshWeek();
    } catch (err) {
      setError(err?.message || 'Could not switch client');
    } finally {
      setBusy(false);
    }
  };

  const quickStart = (recent) => switchTo({
    client_id: recent.client_id,
    work_type: recent.last_work_type,
    task_note: recent.last_task_note,
  });

  const restartFromSession = (session) => switchTo({
    client_id: session.client_id ?? internalClientFor(session)?.id,
    work_type: session.work_type,
    task_note: session.task_note,
  });

  // Format helpers used by the rich Today rows below.
  const WORK_TYPE_LABELS = {
    tracker: 'Tracker',
    manual: 'Manual',
    fixed: 'Fixed',
    outside_of_upwork: 'Outside Upwork',
    office_work: 'Office',
    test_task: 'Test task',
    upwork_bidding: 'Bidding',
  };
  const workTypeLabel = (value) => WORK_TYPE_LABELS[value] || value || '—';

  const liveSeconds = useMemo(() => {
    if (!status.session) return 0;
    const frozen = Number(status.session.frozen_seconds || 0);
    if (status.paused) return frozen;
    const last = Date.parse(status.session.last_change_at || status.session.started_at);
    if (Number.isNaN(last)) return Number(status.session.total_seconds || 0);
    return frozen + Math.max(0, Math.floor((Date.now() - last) / 1000));
  }, [status.session, status.paused, ticker]);

  const runningClient = clients.find((client) => Number(client.id) === Number(status.session?.client_id));
  const currentTitle = status.running
    ? [runningClient?.name, status.session?.task_note].filter(Boolean).join(' — ') || 'Tracking'
    : picker.task_note || selectedClient?.name || 'What are you working on?';
  const totalTodaySeconds = useMemo(() => {
    const activeId = status.session?.id;
    const saved = todaySessions.reduce((sum, session) => {
      // Skip the active session's stale saved total — we'll add the live
      // figure once below to avoid double counting.
      if (activeId && Number(session.id) === Number(activeId)) return sum;
      return sum + Number(session.total_seconds || 0);
    }, 0);
    return saved + (status.running ? liveSeconds : 0);
  }, [liveSeconds, status.running, status.session, todaySessions]);
  const activity = status.session?.activity_percent ?? 0;
  const pendingScreenshots = status.pending?.screenshots ?? 0;
  const pendingSamples = status.pending?.activity ?? 0;
  const weekDays = useMemo(() => {
    const days = (week || []).slice(-5).map((day) => ({
      ...day,
      total_seconds: Number(day.total_seconds || 0) + (day.is_today && status.running ? liveSeconds : 0),
    }));
    return days;
  }, [week, status.running, liveSeconds]);
  const weekScaleSeconds = useMemo(
    () => Math.max(9 * 3600, ...weekDays.map((day) => day.total_seconds)),
    [weekDays]
  );
  const todayRows = useMemo(() => {
    const sessions = [...todaySessions];

    if (status.running && status.session) {
      const existingIndex = sessions.findIndex((session) => Number(session.id) === Number(status.session.id));
      const live = {
        ...status.session,
        total_seconds: liveSeconds,
        status: 'active',
        task_note: picker.task_note || status.session.task_note,
      };

      if (existingIndex >= 0) sessions[existingIndex] = { ...sessions[existingIndex], ...live };
      else sessions.unshift(live);
    }

    // Group by client/task so frequent switching sums into ONE row per task
    // instead of a long repeating list (e.g. Test Task ×3 -> one row, summed).
    const groups = new Map();
    for (const s of sessions) {
      const internal = internalClientFor(s);
      const key = s.client_id ? `c:${s.client_id}` : (internal ? `i:${internal.work_type}` : `w:${s.work_type || 'x'}`);
      const isLive = s.status === 'active' || (status.running && Number(s.id) === Number(status.session?.id));
      const seconds = isLive ? liveSeconds : Number(s.total_seconds || 0);
      const startTs = Date.parse(s.started_at) || 0;

      let g = groups.get(key);
      if (!g) {
        g = { key, client_id: s.client_id ?? null, client_uuid: s.client_uuid, client_name: s.client_name, work_type: s.work_type, task_note: s.task_note, upwork_profile_name: s.upwork_profile_name, total_seconds: 0, live: false, paused: false, count: 0, latestTs: -1 };
        groups.set(key, g);
      }
      g.total_seconds += seconds;
      g.count += 1;
      if (isLive) { g.live = true; g.paused = !!status.paused; }
      // Keep the most recent session's details as the row's representative
      // (title, note, work type, profile) and for restarting it.
      if (startTs >= g.latestTs) {
        g.latestTs = startTs;
        g.client_id = s.client_id ?? g.client_id;
        g.client_uuid = s.client_uuid ?? g.client_uuid;
        g.client_name = s.client_name ?? g.client_name;
        g.work_type = s.work_type ?? g.work_type;
        g.task_note = s.task_note ?? g.task_note;
        g.upwork_profile_name = s.upwork_profile_name ?? g.upwork_profile_name;
      }
    }

    return Array.from(groups.values())
      .sort((a, b) => (b.live ? 1 : 0) - (a.live ? 1 : 0) || b.latestTs - a.latestTs)
      .slice(0, 8);
  }, [liveSeconds, picker.task_note, status.running, status.session, status.paused, todaySessions]);

  const refreshToday = async () => {
    if (typeof window.tt?.meta?.todaySessions !== 'function') return;
    try {
      const sessions = await window.tt.meta.todaySessions();
      setTodaySessions(sessions || []);
    } catch {
      // The live timer is still useful if today history cannot load.
    }
  };

  const refreshWeek = async () => {
    if (typeof window.tt?.meta?.weekSummary !== 'function') return;
    try {
      const days = await window.tt.meta.weekSummary();
      setWeek(days || []);
    } catch {
      // Chart simply stays empty when offline.
    }
  };

  const refreshRecent = async () => {
    if (typeof window.tt?.meta?.recentClients !== 'function') return;
    try {
      const rows = await window.tt.meta.recentClients();
      setRecentClients(rows || []);
    } catch {
      // Chips simply stay hidden when offline.
    }
  };

  useEffect(() => {
    (async () => {
      const results = await Promise.allSettled([
        window.tt.meta.clients(),
        window.tt.tracker.status(),
        typeof window.tt?.meta?.todaySessions === 'function'
          ? window.tt.meta.todaySessions()
          : Promise.resolve([]),
      ]);

      const [clientsRes, statusRes, todayRes] = results;

      if (clientsRes.status === 'fulfilled') {
        setClients([...INTERNAL_CLIENTS, ...(clientsRes.value || [])]);
      }
      if (statusRes.status === 'fulfilled') {
        setStatus(statusRes.value);
      }
      if (todayRes.status === 'fulfilled') {
        setTodaySessions(todayRes.value || []);
      }

      const failures = results
        .map((r, i) => (r.status === 'rejected' ? { i, reason: r.reason } : null))
        .filter(Boolean);

      refreshWeek();
      refreshRecent();

      if (failures.length) {
        const labels = ['clients', 'tracker status', "today's sessions"];
        const detail = failures
          .map((f) => `${labels[f.i]}: ${f.reason?.message || f.reason}`)
          .join('; ');
        const staleBridge = failures.some(
          (f) => /is not a function/i.test(String(f.reason?.message || f.reason))
        );
        setError(
          staleBridge
            ? 'Could not load tracker data — the desktop app needs to be restarted to pick up the latest update. ' + detail
            : 'Could not load tracker data: ' + detail
        );
      }
    })();
  }, []);

  useEffect(() => {
    const offChanged = window.tt.tracker.onChanged((s) => setStatus(s));
    const offStopped = window.tt.tracker.onStopped(() => { refreshToday(); refreshWeek(); refreshRecent(); });
    const offWarning = window.tt.tracker.onWarning((m) => setWarning(m));
    return () => { offChanged?.(); offStopped?.(); offWarning?.(); };
  }, []);

  // Auto-start tracking on launch (local preference): once clients are
  // loaded, restore the last session's client/work-type/note and start.
  useEffect(() => {
    if (autoStartedRef.current || !prefs.autoStartTracking || status.running || clients.length === 0) return;

    let saved = null;
    try { saved = JSON.parse(localStorage.getItem('tt.lastStart') || 'null'); } catch { /* ignore */ }
    if (!saved?.client_id) return;

    const client = clients.find((item) => Number(item.id) === Number(saved.client_id));
    if (!client) return;

    autoStartedRef.current = true;
    const workType = saved.work_type || defaultWorkType(client);
    const note = (saved.task_note || 'Continued work').trim();
    setPicker({ client_id: String(client.id), work_type: workType, task_note: note });

    (async () => {
      try {
        const next = await window.tt.tracker.start({
          client_id: payloadClientId(client),
          upwork_profile_id: client.upwork_profile_id || null,
          work_type: workType,
          task_note: note,
        });
        setStatus(next);
        setWarning('Auto-started tracking your last client (change this in Settings).');
        refreshToday();
      } catch {
        // Form stays pre-filled; user can start manually.
      }
    })();
  }, [prefs.autoStartTracking, clients, status.running]);

  // Web -> desktop deep link (timetracker://start?client_id=X&note=...).
  // Pre-fills the start form; never auto-starts tracking.
  useEffect(() => {
    if (typeof window.tt?.deeplink?.onOpen !== 'function') return undefined;
    const off = window.tt.deeplink.onOpen((payload) => {
      if (!payload || payload.action === 'open') return;
      setPicker((prev) => {
        const clientId = payload.client_id ? String(payload.client_id) : prev.client_id;
        const client = clients.find((item) => String(item.id) === clientId);
        return {
          client_id: clientId,
          work_type: client ? defaultWorkType(client) : prev.work_type,
          task_note: payload.task_note || prev.task_note,
        };
      });
    });
    return () => off?.();
  }, [clients]);

  useEffect(() => {
    if (!status.running || status.paused) return undefined;
    const t = setInterval(() => setTicker((x) => x + 1), 1000);
    return () => clearInterval(t);
  }, [status.running, status.paused]);

  // Warnings behave like toasts: auto-dismiss after 10s. The main process
  // also sends an empty warning to clear the idle banner the moment
  // activity resumes.
  useEffect(() => {
    if (!warning) return undefined;
    const t = setTimeout(() => setWarning(''), 10_000);
    return () => clearTimeout(t);
  }, [warning]);

  const handleStart = async () => {
    setError('');
    setWarning('');
    setBusy(true);

    try {
      if (!selectedClient) {
        throw new Error('Select a client before starting.');
      }

      if (!descriptionValid) {
        throw new Error('Add a description before starting.');
      }

      const next = await window.tt.tracker.start({
        client_id: payloadClientId(selectedClient),
        upwork_profile_id: selectedClient.upwork_profile_id || null,
        work_type: effectiveWorkType || null,
        task_note: picker.task_note.trim(),
      });
      setStatus(next);
      try {
        localStorage.setItem('tt.lastStart', JSON.stringify({
          client_id: selectedClient.id,
          work_type: effectiveWorkType,
          task_note: picker.task_note.trim(),
        }));
      } catch { /* non-essential */ }
      await refreshToday();
    } catch (err) {
      setError(err?.message || 'Could not start session');
    } finally {
      setBusy(false);
    }
  };

  const handleStop = async () => {
    setBusy(true);

    try {
      const next = await window.tt.tracker.stop({ task_note: picker.task_note || null });
      setStatus(next);
      await refreshToday();
    } catch (err) {
      setError(err?.message || 'Could not stop session');
    } finally {
      setBusy(false);
    }
  };

  const handleLogout = async () => {
    if (status.running) {
      const ok = confirm('A session is running. Stop and log out?');
      if (!ok) return;
      await window.tt.tracker.stop({});
    }
    await window.tt.auth.logout();
    onLogout?.();
  };

  return (
    <div className="scrin-shell">
      <header className="tracker-top">
        <div className="who">
          <div className="who-avatar">{(user?.name || '?').trim().charAt(0).toUpperCase()}</div>
          <div className="who-text">
            <div className="who-eyebrow">Signed in as</div>
            <h1>{user?.name || 'Timetracker'}</h1>
          </div>
        </div>
        <button className="menu-button" onClick={() => setMenuOpen((open) => !open)} aria-label="Menu">
          <span />
          <span />
          <span />
        </button>
      </header>

      <div className={menuOpen ? 'drawer-overlay open' : 'drawer-overlay'} onClick={() => setMenuOpen(false)} />
      <aside className={menuOpen ? 'drawer open' : 'drawer'}>
        <div className="drawer-head">
          <span className="drawer-brand">
            <span className="drawer-logo">▶</span>
            Timetracker
          </span>
          <button className="icon-button" onClick={() => setMenuOpen(false)} aria-label="Close">✕</button>
        </div>

        <a className="drawer-link" href={apiBaseUrl || '#'} target="_blank" rel="noreferrer">
          <span className="drawer-link-icon">🌐</span>
          Visit Website
        </a>

        <div className="drawer-user">
          <span className="drawer-user-dot" />
          Signed in as {user?.name || 'me'}
        </div>

        <div className="drawer-section-label">Appearance</div>
        <div className="theme-picker">
          {THEMES.map((theme) => (
            <button
              key={theme.id}
              type="button"
              data-theme-preview={theme.id}
              className={(prefs.theme || 'cinematic') === theme.id ? 'theme-swatch active' : 'theme-swatch'}
              onClick={() => applyTheme(theme.id)}
            >
              <span className="theme-swatch-dot" />
              <span className="theme-swatch-name">{theme.name}</span>
            </button>
          ))}
        </div>

        <div className="drawer-section-label">Preferences</div>

        <button
          type="button"
          className="setting-toggle-row"
          onClick={async () => {
            if (typeof window.tt?.settings?.setAutoLaunch !== 'function') return;
            const result = await window.tt.settings.setAutoLaunch(!autoLaunch);
            if (result?.ok !== false) setAutoLaunch(!!result.enabled);
          }}
        >
          <span className="setting-toggle-text">
            <strong>Launch on system startup</strong>
          </span>
          <span className={autoLaunch ? 'switch on' : 'switch'} aria-hidden="true"><i /></span>
        </button>

        <button type="button" className="setting-toggle-row" onClick={() => setPref('autoStartTracking', !prefs.autoStartTracking)}>
          <span className="setting-toggle-text">
            <strong>Auto-start tracking on launch</strong>
            <small>Resumes your last client when the app opens.</small>
          </span>
          <span className={prefs.autoStartTracking ? 'switch on' : 'switch'} aria-hidden="true"><i /></span>
        </button>

        <button type="button" className="setting-toggle-row" onClick={() => setPref('notifyScreenshot', !effectiveNotifyScreenshot)}>
          <span className="setting-toggle-text">
            <strong>Show screenshot notifications</strong>
          </span>
          <span className={effectiveNotifyScreenshot ? 'switch on' : 'switch'} aria-hidden="true"><i /></span>
        </button>

        <button type="button" className="setting-toggle-row" onClick={() => setPref('idleNotifications', prefs.idleNotifications === false)}>
          <span className="setting-toggle-text">
            <strong>Show idle time notifications</strong>
          </span>
          <span className={prefs.idleNotifications !== false ? 'switch on' : 'switch'} aria-hidden="true"><i /></span>
        </button>

        <button type="button" className="setting-toggle-row" onClick={() => setPref('minimizeToTray', !prefs.minimizeToTray)}>
          <span className="setting-toggle-text">
            <strong>Minimize to tray</strong>
            <small>Minimize hides the window; the tray icon brings it back.</small>
          </span>
          <span className={prefs.minimizeToTray ? 'switch on' : 'switch'} aria-hidden="true"><i /></span>
        </button>

        <div className="drawer-section-label">Team settings</div>
        <div className="team-summary">
          <div>
            <span>Screenshots</span>
            <strong>
              {status.settings?.capture_enabled === false || status.settings?.screenshots_per_hour === 0
                ? 'Off'
                : `${status.settings?.screenshots_per_hour || Math.round(3600 / (status.settings?.screenshot_interval_max_seconds || 600))}/hr`}
            </strong>
          </div>
          <div>
            <span>Auto-pause</span>
            <strong>{Number(status.settings?.auto_pause_minutes || 0) > 0 ? `${status.settings.auto_pause_minutes}min` : 'Off'}</strong>
          </div>
          <div>
            <span>Weekly limit</span>
            <strong>{status.settings?.weekly_time_limit_hours ? `${status.settings.weekly_time_limit_hours}h` : 'No limit'}</strong>
          </div>
          <div>
            <span>Offline time</span>
            <strong>{status.settings?.allow_offline_time ? 'Yes' : 'No'}</strong>
          </div>
          <div>
            <span>Activity tracking</span>
            <strong>{status.settings?.activity_tracking_enabled === false ? 'No' : 'Yes'}</strong>
          </div>
          <div>
            <span>App tracking</span>
            <strong>{status.settings?.app_url_tracking_enabled ? 'Yes' : 'No'}</strong>
          </div>
        </div>
        <p className="settings-note">Team settings are managed by your administrator in the web app.</p>

        {typeof window.tt?.updates?.check === 'function' && (
          <button
            type="button"
            className="setting-toggle-row"
            onClick={async () => {
              const result = await window.tt.updates.check();
              setWarning(result?.message || 'Update check finished.');
              setMenuOpen(false);
            }}
          >
            <span className="setting-toggle-text">
              <strong>Check for updates</strong>
              <small>Updates download in the background and apply on restart.</small>
            </span>
          </button>
        )}

        <div className="drawer-footer">
          <button className="logout-button" onClick={handleLogout}>Log out</button>
          <span className="drawer-version">v{appVersion || '0.1.0'} · {apiBaseUrl ? apiBaseUrl.replace(/^https?:\/\//, '') : 'no server'}</span>
        </div>
      </aside>

      <main className="tracker-main">
        <section className="hero-panel">
          <div className={['timer-ring', status.paused ? 'paused' : status.running ? 'live' : ''].join(' ').trim()}>
            <svg className="ring-svg" viewBox="0 0 200 200" aria-hidden="true">
              <defs>
                <linearGradient id="ttRingGrad" x1="0" y1="0" x2="1" y2="1">
                  <stop className="ring-grad-from" offset="0%" />
                  <stop className="ring-grad-to" offset="100%" />
                </linearGradient>
              </defs>
              <circle className="ring-track-circle" cx="100" cy="100" r={RING_RADIUS} />
              <circle
                className="ring-fill"
                cx="100"
                cy="100"
                r={RING_RADIUS}
                style={{
                  strokeDasharray: RING_CIRCUMFERENCE,
                  strokeDashoffset: RING_CIRCUMFERENCE * (1 - Math.max(0, Math.min(1, totalTodaySeconds / DAILY_GOAL_SECONDS))),
                }}
              />
            </svg>
            <div className="ring-center">
              <div className="ring-time">{fmtClock(totalTodaySeconds)}</div>
              <div className="ring-label">{status.paused ? 'paused' : status.running ? 'tracking' : 'today'}</div>
              {status.running && (
                <div className={['ring-live', status.paused ? 'paused' : ''].join(' ').trim()}>
                  <span className="ring-live-dot" />
                  {status.paused ? 'Paused' : 'Live'}
                </div>
              )}
            </div>
          </div>

          <div className="week-panel">
            <div className="week-labels">
              {weekDays.map((day) => (
                <span key={day.date} className={day.is_today ? 'active-day' : ''}>{day.weekday}</span>
              ))}
            </div>
            <div className="week-chart">
              <span />
              <span />
              <span />
              <div className="week-bars">
                {weekDays.map((day) => (
                  <div
                    key={day.date}
                    className={day.is_today ? 'week-bar today' : 'week-bar'}
                    style={{ height: `${Math.max(day.total_seconds > 0 ? 6 : 2, Math.min(96, (day.total_seconds / weekScaleSeconds) * 100))}%` }}
                    title={`${day.weekday}: ${fmtShort(day.total_seconds)}`}
                  />
                ))}
              </div>
              <div className="axis"><b>9h</b><b>6h</b><b>3h</b><b>0h</b></div>
            </div>
          </div>

          <div className="task-pill">
            {!status.running ? (
              <div className="task-fields">
                <ClientPicker
                  clients={clients}
                  value={picker.client_id}
                  onChange={handleClientChange}
                  disabled={busy}
                />
                {workTypeChoices.length > 1 && (
                  <div className="wt-pills" title="Upwork work type for this entry">
                    {workTypeChoices.map((option) => (
                      <button
                        type="button"
                        key={option.value}
                        className={effectiveWorkType === option.value ? 'wt-pill active' : 'wt-pill'}
                        onClick={() => setPicker({ ...picker, work_type: option.value })}
                      >
                        {option.label}
                      </button>
                    ))}
                  </div>
                )}
                <input
                  value={picker.task_note}
                  onChange={(e) => setPicker({ ...picker, task_note: e.target.value })}
                  placeholder="Description (required)"
                  required
                />
              </div>
            ) : (
              <div className="running-title">{currentTitle}</div>
            )}
            <button
              className={status.running ? 'round-action stop' : 'round-action start'}
              onClick={status.running ? handleStop : handleStart}
              disabled={busy || (!status.running && (!selectedClient || !descriptionValid))}
              aria-label={status.running ? 'Stop tracking' : 'Start tracking'}
            >
              {status.running ? <span className="stop-square" /> : <span className="play-triangle" />}
            </button>
          </div>
        </section>

        {typeof window.tt?.timeclock?.status === 'function' && (
          <section className="shift-bar">
            <div className="shift-status">
              <span className="shift-status-label">Shift</span>
              <strong className={`shift-state ${timeClock.last_action || 'none'}`}>
                {{
                  clock_in: 'Working',
                  break_end: 'Working',
                  break_start: 'On break',
                  clock_out: 'Clocked out',
                }[timeClock.last_action] || 'Not started'}
              </strong>
            </div>
            <div className="shift-actions">
              {[
                { type: 'clock_in', label: 'Clock In', hint: 'Start work' },
                { type: 'clock_out', label: 'Clock Out', hint: 'End work' },
                { type: 'break_start', label: 'Start Break', hint: 'Pause work' },
                { type: 'break_end', label: 'End Break', hint: 'Resume work' },
              ].map((action) => (
                <button
                  type="button"
                  key={action.type}
                  className={`shift-btn ${action.type}`}
                  disabled={clockBusy || !(timeClock.available || []).includes(action.type)}
                  onClick={() => clockAct(action.type)}
                >
                  <strong>{action.label}</strong>
                  <small>{action.hint}</small>
                </button>
              ))}
            </div>
          </section>
        )}

        {recentClients.length > 0 && (
          <section className="recent-row">
            <span className="recent-label">{status.running ? 'Switch to' : 'Quick start'}</span>
            <div className="recent-chips">
              {recentClients.map((recent) => {
                const isCurrent = status.running && Number(status.session?.client_id) === Number(recent.client_id);
                return (
                  <button
                    type="button"
                    key={recent.client_id}
                    className={isCurrent || (!status.running && String(recent.client_id) === String(picker.client_id)) ? 'recent-chip active' : 'recent-chip'}
                    onClick={() => quickStart(recent)}
                    disabled={busy || isCurrent}
                    title={isCurrent ? 'Currently tracking' : (recent.last_task_note ? `Last: ${recent.last_task_note}` : 'Start this client')}
                  >
                    {recent.client_name}
                  </button>
                );
              })}
            </div>
          </section>
        )}

        {selectedClient && !status.running && (
          <section className="client-strip">
            <div><span>Work type</span><strong>{workTypeChoices.find((o) => o.value === effectiveWorkType)?.label || 'Not set'}</strong></div>
            <div><span>Tracker</span><strong>{selectedClient.upwork_profile_name || 'Not attached'}</strong></div>
          </section>
        )}

        {(error || warning) && (
          <section className="notice-stack">
            {error && <div className="error">{error}</div>}
            {warning && <div className="warning">{warning}</div>}
          </section>
        )}

        <section className="today-panel">
          <div className="section-title">
            <h2>Today</h2>
            <strong>{fmtShort(totalTodaySeconds)}</strong>
          </div>
          {todayRows.length === 0 ? (
            <p className="empty-today">No tracked work yet. Pick a client above and hit start.</p>
          ) : (
            <div className="today-list">
              {todayRows.map((row) => {
                const isActive = row.live;
                const matchingClient = clients.find((c) => Number(c.id) === Number(row.client_id));
                const internalClient = internalClientFor(row);
                const clientName = row.client_name || matchingClient?.name || internalClient?.name;
                const trackerName = row.upwork_profile_name || matchingClient?.upwork_profile_name;
                const canResume = (!!row.client_id || !!internalClient) && !isActive && !busy;
                return (
                  <button
                    type="button"
                    key={row.key}
                    className={[
                      'today-row',
                      isActive ? 'active' : '',
                      canResume ? 'resumable' : '',
                    ].join(' ').trim()}
                    onClick={() => canResume && restartFromSession(row)}
                    disabled={!canResume}
                    title={canResume ? (status.running ? 'Switch tracking to this client' : 'Start this client again') : ''}
                  >
                    <div className="today-row-main">
                      <strong className="today-row-title">{clientName || row.task_note || sessionTitle(row, clients)}</strong>
                      <div className="today-row-meta">
                        {row.task_note && <span className="chip chip-note" title={row.task_note}>{row.task_note}</span>}
                        {row.work_type && <span className="chip chip-worktype">{workTypeLabel(row.work_type)}</span>}
                        {trackerName && <span className="chip chip-tracker">{trackerName}</span>}
                        {row.count > 1 && <span className="chip" title={`${row.count} sessions today, summed`}>{row.count}×</span>}
                        {isActive && row.paused && <span className="chip chip-paused">Paused</span>}
                        {isActive && !row.paused && <span className="chip chip-live">Live</span>}
                      </div>
                    </div>
                    <strong className="today-row-time">{fmtShort(row.total_seconds)}</strong>
                  </button>
                );
              })}
            </div>
          )}
        </section>

        <section className="status-footer">
          <div><span>Activity</span><strong>{activity}%</strong></div>
          <div><span>Pending screenshots</span><strong>{pendingScreenshots}</strong></div>
          <div><span>Pending samples</span><strong>{pendingSamples}</strong></div>
        </section>
      </main>

      {updateInfo && (
        <div className="update-banner" role="status">
          {updateInfo.type === 'downloaded' ? (
            <>
              <span className="update-banner-text">
                <strong>Update v{updateInfo.version} is ready.</strong> Restart to apply — or it installs next time you quit.
              </span>
              <span className="update-banner-actions">
                <button type="button" className="update-banner-btn" onClick={() => window.tt.updates.install()}>
                  Restart now
                </button>
                <button type="button" className="update-banner-later" onClick={() => setUpdateInfo(null)}>
                  Later
                </button>
              </span>
            </>
          ) : (
            <>
              <span className="update-banner-text">
                Downloading update{updateInfo.version ? ` v${updateInfo.version}` : ''}… {updateInfo.percent ?? 0}%
              </span>
              <span className="update-progress" aria-hidden="true">
                <i style={{ width: `${updateInfo.percent ?? 0}%` }} />
              </span>
            </>
          )}
        </div>
      )}
    </div>
  );
}
