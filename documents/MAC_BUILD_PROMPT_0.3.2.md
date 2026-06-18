# Mac build prompt — SA Track Desktop v0.3.2

Paste everything below the line into a Claude Code session on an **Apple Silicon
Mac** (or follow it yourself). It builds and publishes the macOS version.

---

Build and publish the macOS version of **SA Track Desktop v0.3.2**.

**Context.** Repo: https://github.com/jawadislam92/timetracker (branch `jawad`).
The desktop app is an Electron tracker. The Mac build is far behind (last Mac
build was 0.1.0); v0.3.2 brings Mac fully current with the Windows build —
notably: the **SA Track rebrand**, the **premium redesign** (3 switchable themes
Cinematic/Light/Midnight + a bespoke progress-ring clock + custom scrollbars +
prominent "Signed in as" identity), **multi-monitor screenshot capture**
(stitched into one image, uses `jimp`), **auto clock-in** when tracking starts,
**single-device enforcement** (starting on a 2nd device stops the 1st), and the
clean **show-password eye** on login. The renderer is shared with Windows, so
the Mac build gets all of it automatically.

**Prerequisites.**
- Apple Silicon Mac (this build targets `arm64`).
- Xcode Command Line Tools: `xcode-select --install` (needed for the native
  modules `better-sqlite3` and `uiohook-napi`).
- Node 18+ and git. SSH access to the prod server (same key used for earlier
  builds; port 65002).

**Steps.**
1. Get the code:
   `git fetch origin jawad && git checkout -f jawad && git pull` (or clone fresh,
   then `git checkout jawad`).
2. `cd desktop && npm install` — deps now include `jimp` (multi-monitor stitch).
3. `npm run rebuild` — rebuilds `better-sqlite3` + `uiohook-napi` for Electron's
   ABI on macOS.
4. `npm run dist:mac` — output lands in `desktop/dist-app/`:
   expect **`SA Track-0.3.2-arm64.dmg`** (and an `-arm64-mac.zip`).
5. Smoke-test the app:
   - Open the DMG, drag **SA Track** to Applications.
   - Clear the unsigned-app quarantine:
     `xattr -dr com.apple.quarantine "/Applications/SA Track.app"`
   - Launch it. When macOS prompts, grant **Accessibility** (keyboard/mouse
     activity) and **Screen Recording** (screenshots) — both are required for
     tracking to work on macOS.
   - Sign in (server URL defaults to https://timetracker.sparkingasia.com) with
     a test account. Confirm: the login has the **eye** show-password toggle; the
     tracker shows the **ring clock** + **"Signed in as"** header; the drawer's
     **Appearance** section switches between the 3 themes; starting tracking
     captures screenshots. On a Mac with **two monitors**, confirm one screenshot
     shows **both screens** side by side.
6. Publish to the download feed (deploy-proof installers folder):
   `scp -P 65002 "dist-app/SA Track-0.3.2-arm64.dmg" u406855808@31.170.164.232:~/desktop-installers/`
   (optionally also the `.zip`). The web Downloads page already expects this
   exact filename (`MAC_VERSION = 0.3.2`), so it will appear on
   https://timetracker.sparkingasia.com/desktop-downloads automatically — **no
   server code change needed.**
7. Report back: the DMG filename + its SHA-256 (`shasum -a 256 "<file>"`), and
   whether the step-5 smoke test passed.

**Important caveats (do not try to "fix" these):**
- **Unsigned build** (no Apple Developer certificate). Gatekeeper blocks a normal
  double-click — users open it the first time via **right-click → Open**, or
  `xattr -cr "/Applications/SA Track.app"`. This is expected.
- **Auto-update does NOT work on macOS** — Squirrel.Mac requires code signing,
  which we've deliberately skipped. The in-app updater is a no-op on Mac. **Mac
  users update manually** by downloading the newer DMG from /desktop-downloads.
  (Windows auto-updates; Mac does not.)
- This build is **arm64 only** (Apple Silicon). Intel Macs are not targeted.

**Optional future:** an Apple Developer account ($99/yr) would let us sign +
notarize the Mac build — removing the Gatekeeper warning and enabling Mac
auto-update. Not needed now.
