# Mac build prompt — SA Track Desktop v0.3.3

Same procedure as `MAC_BUILD_PROMPT_0.3.2.md` — only the version changes (0.3.2 →
**0.3.3**). Paste the block below into a Claude Code session on an **Apple Silicon
Mac**. No certificate / Apple Developer account needed (ad-hoc signed, free).

**What's new in 0.3.3 (over 0.3.2):** clearer sign-in errors ("Incorrect email or
password." instead of a raw 422) and the **Today list now sums time per task**
(juggling tasks no longer makes a long repeating list).

---

Build and publish **SA Track Desktop v0.3.3** for macOS. Repo:
https://github.com/jawadislam92/timetracker (branch `jawad`).

1. `git fetch origin jawad && git checkout -f jawad && git pull`
2. `cd desktop && npm install`
3. `npm run rebuild`
4. `npm run dist:mac` → `desktop/dist-app/SA Track-0.3.3-arm64.dmg` (+ `.zip`)
5. Smoke-test: open DMG → drag **SA Track** to Applications →
   `xattr -dr com.apple.quarantine "/Applications/SA Track.app"` → launch →
   grant **Accessibility** + **Screen Recording** → sign in → confirm a wrong
   password now shows **"Incorrect email or password."**, and that switching
   between the same task sums into one Today row.
6. Publish: `scp -P 65002 "dist-app/SA Track-0.3.3-arm64.dmg" u406855808@31.170.164.232:~/desktop-installers/`
   (the Downloads page already expects this filename). Optionally the `.zip` too.
7. Report the DMG name + `shasum -a 256 "<file>"` and whether the smoke test passed.

Caveats unchanged: unsigned (right-click → Open first time), **no auto-update on
Mac** (manual download), arm64 only.
