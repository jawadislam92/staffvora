# Mac build prompt — Timetracker Desktop v0.2.0

Paste everything below this line into a Claude Code session on the Mac.

---

Build and publish the macOS version of Timetracker Desktop v0.2.0.

Context: the repo is https://github.com/jawadislam92/timetracker (branch `jawad`).
You built v0.1.0 on this machine before, so the toolchain (Node, Xcode CLT,
native module builds) should already work. v0.2.0 adds: auto-updater
(intentionally a NO-OP on macOS — the build is unsigned and Squirrel.Mac
requires signing, the code handles this), "Office Work"/"Test Task" no-client
tracking options, session-expired re-login UX, and a revamped login screen
(server URL is baked in, remembered credentials via the OS keychain).

Steps:

1. `cd` into the repo, `git fetch origin jawad && git checkout jawad && git pull`.
2. `cd desktop && npm install` (new dependency: electron-updater).
3. `npm run rebuild` (native modules for Electron, as before).
4. `npm run dist:mac` — output lands in `desktop/dist-app/`:
   expect `Timetracker Desktop-0.2.0-arm64.dmg` (and a zip).
5. Sanity-check the app launches: open the DMG, drag to Applications,
   `xattr -dr com.apple.quarantine "/Applications/Timetracker Desktop.app"`,
   launch, confirm the login screen shows email/password with the
   "Remember me" checkbox and an "Advanced" toggle (server URL should
   default to https://timetracker.sparkingasia.com), sign in with a test
   account, and confirm "Office Work" and "Test Task" appear at the top of
   the client picker.
6. Upload the DMG to the web server's deploy-proof installers folder:
   `scp -P 65002 "dist-app/Timetracker Desktop-0.2.0-arm64.dmg" u406855808@31.170.164.232:~/desktop-installers/`
   (The SSH key was set up during the v0.1.0 session. The download page
   auto-detects the 0.2.0 filename — no server code change needed.)
7. Optionally also create a GitHub release tag `desktop-v0.2.0-mac` with the
   DMG attached, like v0.1.0.
8. Report: the DMG filename + SHA-256 (`shasum -a 256 <file>`), and whether
   the smoke test in step 5 passed.

Do NOT try to enable auto-update on macOS — it requires Apple code signing
which we have deliberately declined for now. Mac users update manually from
https://timetracker.sparkingasia.com/desktop-downloads.
