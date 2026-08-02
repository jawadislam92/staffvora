import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';

// Client-side crash reporting. A renderer error otherwise shows the employee a
// blank window and tells us nothing, while the tracker keeps "running" — which
// is exactly the silent failure the diagnostics pipeline exists to catch.
// Sends only the message/stack, never form values or app state, and is
// deliberately best-effort: reporting must never itself throw.
function reportRendererError(event, message, extra = {}) {
  try {
    window.tt?.diagnostics?.report({
      event,
      message: String(message ?? '').slice(0, 2000),
      source: extra.source,
      line: extra.line,
      stack: extra.stack,
    });
  } catch {
    /* ignore — a failed report must never cascade */
  }
}

window.addEventListener('error', (e) => {
  reportRendererError('renderer_error', e?.message || 'window error', {
    // Only the file name, never the full path (it contains the OS username).
    source: typeof e?.filename === 'string' ? e.filename.split(/[\\/]/).pop() : undefined,
    line: e?.lineno,
    stack: e?.error?.stack,
  });
});

window.addEventListener('unhandledrejection', (e) => {
  const reason = e?.reason;
  reportRendererError('renderer_unhandled_rejection', reason?.message || String(reason ?? 'unhandled rejection'), {
    stack: reason?.stack,
  });
});

try {
  createRoot(document.getElementById('root')).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
} catch (err) {
  // The UI never mounted at all — the one failure the handlers above can miss.
  reportRendererError('renderer_boot_failed', err?.message || String(err), { stack: err?.stack });
  throw err;
}
