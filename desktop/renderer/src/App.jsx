import React, { useEffect, useState } from 'react';
import Consent from './views/Consent.jsx';
import Login from './views/Login.jsx';
import Tracker from './views/Tracker.jsx';

export default function App() {
  const [authState, setAuthState] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notice, setNotice] = useState('');
  // null = not checked yet. The gate FAILS CLOSED: anything other than an
  // explicit `true` from the main process keeps the tracker out of reach.
  const [consented, setConsented] = useState(null);

  useEffect(() => {
    (async () => {
      try {
        const state = await window.tt.auth.state();
        setAuthState(state);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  // Apply the saved appearance theme as early as possible so the login screen
  // and the tracker share the chosen look (default: cinematic).
  useEffect(() => {
    if (typeof window.tt?.settings?.getPrefs !== 'function') return;
    window.tt.settings.getPrefs()
      .then((p) => document.documentElement.setAttribute('data-theme', p?.theme || 'cinematic'))
      .catch(() => {});
  }, []);

  // Monitoring consent is checked on EVERY launch and after every sign-in, not
  // once at install: the required document version can move server-side (new
  // terms), and a shared PC can change hands between employees. Re-running it
  // whenever the signed-in user changes is what stops one person's acceptance
  // standing for the next person's.
  useEffect(() => {
    if (!authState?.hasToken) {
      setConsented(null);
      return;
    }
    if (typeof window.tt?.consent?.status !== 'function') {
      // An old preload with a new renderer should not silently disable the
      // gate — treat a missing bridge as "not consented".
      setConsented(false);
      return;
    }
    let cancelled = false;
    setConsented(null);
    window.tt.consent.status()
      .then((state) => { if (!cancelled) setConsented(state?.accepted === true); })
      .catch(() => { if (!cancelled) setConsented(false); });
    return () => { cancelled = true; };
  }, [authState?.hasToken, authState?.user?.id]);

  // Server rejected our token (e.g. it was revoked): drop to the login
  // screen with a clear message instead of surfacing raw 401 errors.
  useEffect(() => {
    if (typeof window.tt?.auth?.onExpired !== 'function') return undefined;
    const off = window.tt.auth.onExpired(() => {
      setNotice('Your session expired — please sign in again.');
      setAuthState((prev) => ({ ...(prev || {}), hasToken: false, user: null }));
    });
    return () => off?.();
  }, []);

  const signOut = async () => {
    try { await window.tt.auth.logout(); } catch { /* the local token is dropped anyway */ }
    setConsented(null);
    setAuthState((prev) => ({ ...(prev || {}), hasToken: false, user: null }));
  };

  if (loading) {
    return (
      <div className="app">
        <main className="app-main" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div className="subtle">Loading...</div>
        </main>
      </div>
    );
  }

  if (!authState?.hasToken) {
    return (
      <Login
        initial={authState}
        notice={notice}
        onLoggedIn={(s) => {
          setNotice('');
          setConsented(null);
          setAuthState({ ...authState, ...s, hasToken: true });
        }}
      />
    );
  }

  if (consented === null) {
    return (
      <div className="app">
        <main className="app-main" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div className="subtle">Checking your workspace terms...</div>
        </main>
      </div>
    );
  }

  // The tracker — and therefore every capture path — is unreachable until this
  // person has actively ticked the box for the current document version.
  if (consented !== true) {
    return (
      <Consent
        user={authState.user}
        onAccepted={() => setConsented(true)}
        onDeclined={() => {
          setNotice('You need to accept the terms before the tracker can run.');
          signOut();
        }}
      />
    );
  }

  return (
    <Tracker
      user={authState.user}
      apiBaseUrl={authState.apiBaseUrl}
      onLogout={() => {
        setConsented(null);
        setAuthState({ ...authState, hasToken: false, user: null });
      }}
    />
  );
}
