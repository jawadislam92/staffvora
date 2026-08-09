import React, { useEffect, useMemo, useState } from 'react';

/**
 * The monitoring-consent gate.
 *
 * This is the most trust-sensitive screen in the product, so a few things about
 * it are deliberate and should not be "tidied up" later:
 *
 *  - The checkbox starts UNTICKED and there is no other way past this screen.
 *    A pre-ticked box is not consent, and neither is "by continuing you agree".
 *  - The plain-language summary of what is monitored sits ABOVE the checkbox,
 *    on this screen, not behind the links. Someone who never opens the Terms
 *    still learns that screenshots and app names can be recorded.
 *  - "Decline" is a real, first-class option that signs the person out. A gate
 *    with no exit is coercion, and coerced consent is not consent.
 *  - The document version and effective date are shown, because the person is
 *    agreeing to a specific text, not to a vibe.
 */
export default function Consent({ user, onAccepted, onDeclined }) {
  const [doc, setDoc] = useState(null);
  const [offline, setOffline] = useState(false);
  const [ticked, setTicked] = useState(false);   // NEVER default to true
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      const state = await window.tt.consent.status();
      setDoc(state?.document || null);
      setOffline(!!state?.offline);
      // A fresh document means a fresh decision: never carry a tick across a
      // version change.
      setTicked(false);
    } catch {
      setDoc(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const disclosure = useMemo(
    () => (Array.isArray(doc?.disclosure) ? doc.disclosure : []),
    [doc],
  );

  const openDoc = (kind) => (event) => {
    event.preventDefault();
    window.tt.consent.openDocument(kind);
  };

  const agree = async () => {
    if (!ticked || !doc?.version) return;
    setBusy(true);
    setError('');
    try {
      const result = await window.tt.consent.accept(doc.version);

      if (result?.stale) {
        // The terms changed between this screen rendering and the tick. Show
        // the new ones rather than banking an acceptance of the old text.
        setError('The terms were updated. Please review the current version below and accept again.');
        if (result.document) {
          setDoc(result.document);
          setTicked(false);
        } else {
          await load();
        }
        return;
      }

      if (!result?.ok) {
        setError('Your acceptance could not be recorded. Please check your connection and try again.');
        return;
      }

      // result.synced === false means it is safely stored on this machine and
      // will be uploaded when the network is back. The person has consented;
      // holding them at the gate over our own connectivity would be absurd.
      onAccepted?.({ synced: result.synced !== false });
    } catch {
      setError('Your acceptance could not be recorded. Please check your connection and try again.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="login-wrapper">
        <div className="consent-card">
          <div className="subtle">Loading the terms...</div>
        </div>
      </div>
    );
  }

  return (
    <div className="login-wrapper consent-wrapper">
      <div className="consent-card">
        <header className="consent-head">
          <h1>Before you start</h1>
          <p className="subtitle">
            {user?.name ? `${user.name}, this` : 'This'} app records your working time and, when your
            employer enables it, more. Please read this and agree before it runs.
          </p>
        </header>

        {offline && (
          <div className="warning consent-notice">
            You are offline. You can still read and accept these terms — your acceptance is saved on
            this computer and sent to your workspace as soon as you are back online.
          </div>
        )}

        <section className="consent-body">
          <h2 className="consent-h2">What this app records</h2>
          <ul className="consent-list">
            {disclosure.map((item, index) => (
              <li key={item.title || index}>
                <span className="consent-item-title">{item.title}</span>
                <span className="consent-item-body">{item.body}</span>
              </li>
            ))}
          </ul>

          <p className="consent-docs">
            The full terms are here:{' '}
            <a href="#" onClick={openDoc('terms')}>Terms of Service</a>
            {' · '}
            <a href="#" onClick={openDoc('privacy')}>Privacy Policy</a>
            {doc?.version && (
              <span className="consent-version">
                {' '}— version {doc.version}
                {doc.effective_date ? `, effective ${doc.effective_date}` : ''}
              </span>
            )}
          </p>
        </section>

        {error && <div className="error consent-notice">{error}</div>}

        <label className="consent-check">
          <input
            type="checkbox"
            checked={ticked}
            onChange={(e) => setTicked(e.target.checked)}
          />
          <span>
            {doc?.checkbox_label
              || 'I have read and agree to the Terms of Service and the Privacy Policy, and I understand what is monitored.'}
          </span>
        </label>

        <div className="consent-actions">
          <button
            type="button"
            className="primary consent-agree"
            disabled={!ticked || busy}
            onClick={agree}
          >
            {busy ? 'Recording...' : 'Agree and continue'}
          </button>
          <button
            type="button"
            className="consent-decline"
            disabled={busy}
            onClick={() => onDeclined?.()}
          >
            Decline and sign out
          </button>
        </div>

        <p className="consent-foot">
          If you decline, the app signs you out and records nothing. Talk to your manager if you have
          questions about how your workspace uses this.
        </p>
      </div>
    </div>
  );
}
