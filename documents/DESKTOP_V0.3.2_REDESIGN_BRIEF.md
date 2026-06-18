# Desktop v0.3.2 — Redesign brief (owner-requested, do next session)

**Goal:** make the SA Track desktop tracker *feel premium and original* — "the
person who works should feel they're using a company with premium products."
Today it's flatly dark and visibly borrows the layout of the competitor we took
inspiration from (the screenshot-monitor style). We keep the good ideas but make
it unmistakably ours, cinematic, and pleasant to use.

Ships as **v0.3.2** via the established build/release flow (see
[[desktop-release-rollback]] — `npm run rebuild && npm run dist`, upload exe +
blockmap then latest.yml, archive `latest-0.3.2.yml`). Rollback is already in
place (allowDowngrade), so this release is safely reversible.

## File map (Electron renderer — React + plain CSS, no Tailwind)
- `desktop/renderer/src/styles.css` — ALL styling. Theme tokens + scrollbars live here.
- `desktop/renderer/src/views/Tracker.jsx` — main view: the clock/timer, the
  week mini-graph, "tracking" state, the current session card, today's list,
  Clock In/Out/Break buttons, the logged-in name header.
- `desktop/renderer/src/views/Login.jsx` — login (eye icon already upgraded to SVG).
- `desktop/renderer/src/App.jsx` — shell/routing; good place to mount the theme provider.
- Persist prefs (active theme) via the existing `electron-store` (window.tt.* bridge).

## Work items

### 1. Theme system + cinematic redesign (the big one)
- One-click **theme switcher** (header or a small settings control), persisted
  per-device via electron-store. Default applied on launch.
- **3 themes Claude designs** (owner: "design the 3 best"). Direction:
  - **Warm cinematic (default)** — deep charcoal, ember/amber accent, soft
    glows; matches the SA Track web brand. Premium "studio" feel.
  - **Clean light** — bright, minimal, lots of whitespace; for bright rooms /
    people who dislike dark UIs.
  - **Neutral midnight** — calm slate/indigo, lower contrast than pure black;
    easy on the eyes.
- Implement as CSS variables (`--bg`, `--surface`, `--text`, `--muted`,
  `--accent`, `--accent-glow`, `--border`, ...) swapped by a `data-theme`
  attribute on the root. Every component reads the vars — no hard-coded colors.

### 2. Redesign the CLOCK / timer (owner specifically called this out)
- Current: a big flat **purple disc** reading "12:05 / tracking / Live" — too
  close to the competitor's look. Owner: "use a different kind of clock... we
  took the idea from them but shouldn't make it exactly like them."
- Make it **bespoke + premium + theme-aware** (accent comes from the theme).
  Options to prototype (Claude to pick the strongest):
  - An elegant **progress ring/arc** (stroke, not a solid disc) that advances
    with the session, refined monospace-ish time at center, soft accent glow, a
    subtle "live" pulse dot.
  - A cinematic **ember filament**: a glowing accent line/bar that grows as the
    session runs — distinctive and on-brand.
  - Keep it legible at a glance; the running time is the hero.
- The week mini-**graph** beside it: refine to clean, theme-aware bars (subtle
  gradient, rounded caps, hover tooltip) instead of the current plain bars.

### 3. Smoother scrolling (owner dislikes current scroll)
- Replace the rough default scrollbar with a slim, theme-aware custom scrollbar
  (`::-webkit-scrollbar*`), comfortable overflow regions, and momentum/`scroll-
  behavior: smooth` where it helps. Should feel native, not "web page in a box."

### 4. Prominent "who's logged in" (shared computers / shifts)
- Today the header shows "Tracking for myself / <Name>" small. Make the
  signed-in identity **unmissable** — avatar/initial + bold name (+ shift if
  available) — so two/three people sharing one PC on different shifts never
  track under the wrong account.

### 5. Desktop login eye icon — DONE (coded)
- Already swapped the easy-to-miss emoji for a clean SVG eye/eye-off matching
  the web. Ships in this build. (Owner confirmed the toggle existed but was
  "not visible to the naked eye" — this fixes visibility.)

## Out of scope here (separate carry-overs)
- Mac 0.3.1 build (bump the Mac build prompt to current). 
- Self-service rollback button on the Developer page (rollback is CLI-only now).

## Verify after build
- Install/auto-update one machine; check: themes switch + persist across
  restart; clock looks distinct + premium in all 3 themes; scrolling feels
  smooth; logged-in name is obvious; login eye icon visible. Then publish to the
  feed; the team auto-updates.
