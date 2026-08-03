# Staffvora Desktop — Release Checklist

**Read this before building anything.** The desktop app cannot ship as-is: it
has been rebranded, it points at a new server, and it gained two features that
talk to endpoints the old builds never knew about. Every existing installer on
disk or on the server is from a different product and a different address.

**Who does what, honestly:**

| Step | Who |
| --- | --- |
| Version, packaging metadata, source changes | Done — see §1 |
| Building the Windows installer | You, on a Windows PC |
| Building the macOS installer | You, on a Mac — a Mac build **cannot** be produced on Windows |
| Buying and using code-signing certificates | **You, personally.** The private keys live on hardware you hold; nobody else can sign for you. |
| Uploading installers and switching the update feed | You, over SSH |

---

## 0. Why a rebuild is mandatory (not optional)

Three things changed that live **inside the compiled app**, not on the server:

1. **The server address.** Packaged builds default to `https://staffvora.com`
   (`desktop/main/config.js`). Older builds default to a different site
   entirely. An old installer will never reach Staffvora.
2. **Tracker-health diagnostics (0.4.0).** The agent buffers its own crashes,
   capture failures and an hourly health snapshot and posts them to
   `POST /api/desktop/diagnostics`. Without it, a tracker that dies quietly —
   antivirus blocking screen capture, expired token, stuck upload queue — is
   invisible until payroll is already wrong.
3. **Machine Compliance (0.4.6).** The agent inventories browser extensions,
   installed programs, processes and network adapters and posts them to
   `POST /api/desktop/machine-report`.
   **The server treats 0.4.6 as the minimum agent version**
   (`App\Models\Machine::MIN_AGENT_VERSION`). Anything older cannot report
   extensions at all, and the Compliance dashboard is built to show those
   machines as **UNKNOWN**, never as clean. So an old agent does not merely miss
   a feature — it makes the compliance screen useless for that PC.

Plus the rebrand: the app now calls itself **Staffvora Desktop**.

---

## 1. What has already been changed for you

In `desktop/`:

| Field | Now |
| --- | --- |
| `name` | `staffvora-desktop` |
| `productName` | `Staffvora Desktop` |
| `version` | **`0.4.6`** — deliberately left as-is, because it is exactly the server's minimum agent version. Do not lower it. |
| `build.appId` | `com.staffvora.desktop` |
| `build.publish[0].url` | `https://staffvora.com/desktop-updates` |
| `build.protocols.schemes` | `staffvora` **and** `tracksuite` (the old scheme stays registered so links the web app already emits keep working) |
| NSIS shortcut / DMG title / tray / notifications / window title | "Staffvora" |
| `scripts/start-dev.ps1` default server | `http://staffvora.test` |

**Two consequences of the rename you must know about before you build:**

- **The app's data folder changes.** Electron stores settings, the offline
  upload queue and the saved sign-in under a folder named after the product. It
  moves from `…/Tracksuite Desktop` to `…/Staffvora Desktop`. Anyone running an
  old internal build will be **signed out once** and any not-yet-uploaded
  screenshots in the old folder are stranded. Harmless before launch; it would
  be painful after, which is exactly why the rename happens now.
- **The install identity changes** (`appId`). Windows will treat the new build
  as a **separate application**, so it installs alongside an old
  "Tracksuite Desktop" rather than upgrading it, and old installs will never
  auto-update to it. If any test machines have the old build, **uninstall it by
  hand** before installing the new one.

If you would rather not accept either consequence, the alternative is to keep
the old `appId` and `productName` — say so and it can be reverted. But then the
app shows the wrong brand name to every customer forever.

---

## 2. Decide the version number

Keep **0.4.6** unless you have a reason not to. It matches
`Machine::MIN_AGENT_VERSION` on the server, so a fleet on 0.4.6 is a fleet the
Compliance dashboard fully trusts.

If you do bump it (say to 0.4.7), edit `desktop/package.json` `version` and
nothing else — the number flows into the installer filename, the update feed and
the diagnostics the agent reports. **Never go below 0.4.6.**

---

## 3. Build the Windows installer

On a Windows PC with Node.js 22:

```powershell
cd C:\laragon\www\Staffvora\desktop
npm ci
npm run rebuild          # recompiles the native modules for Electron
npm test                 # reporter + collector self-tests, must pass
npm run dist             # builds renderer, then packages Windows x64
```

Output lands in `desktop/dist-app/`:

| File | What it is |
| --- | --- |
| `Staffvora Desktop Setup 0.4.6.exe` | The installer people download |
| `Staffvora Desktop Setup 0.4.6.exe.blockmap` | Lets auto-update download only the changed parts |
| `latest.yml` | **The update feed manifest.** Without this, no installed app ever discovers an update. |

**Check before going further:**

- [ ] All three files exist.
- [ ] `latest.yml` opens in a text editor and its `version:` says `0.4.6` and its
      `path:` matches the `.exe` filename exactly.
- [ ] Install it on a clean PC. It launches, the tray icon says **Staffvora
      Desktop**, and the sign-in screen's Advanced section shows
      `https://staffvora.com`.
- [ ] Sign in as a test employee, start tracking, confirm a screenshot appears
      in the web app.
- [ ] After ~1 minute, the machine appears under Compliance in the web app and
      is **not** marked as a stale agent.

---

## 4. Build the macOS installer

**This must be done on a Mac.** electron-builder cannot produce a real macOS
build from Windows.

```bash
cd /path/to/Staffvora/desktop
npm ci
npm run rebuild
npm test
npm run dist:mac         # produces arm64 .dmg and .zip in dist-app/
```

Output: `Staffvora Desktop-0.4.6-arm64.dmg` and
`Staffvora Desktop-0.4.6-arm64-mac.zip`.

Current config builds **arm64 only** (Apple Silicon). Intel Macs are not
covered. If you have customers on Intel Macs, that has to be added deliberately
(`mac.target[].arch` gains `x64`, roughly doubling build time and file size).

---

## 5. Code signing — what only you can do

**We cannot sign the app for you.** Code-signing certificates are issued to a
verified legal entity and, since 2023, their private keys must live on hardware
you physically hold (a USB token or an HSM/cloud-signing account you control).
Handing that key to anyone else voids it. What follows is exactly what to buy
and exactly what to change once you have it.

### 5.1 Windows

**Without signing:** every download shows Microsoft SmartScreen's
"Windows protected your PC — unknown publisher" warning. Most employees will not
click through it, and some corporate antivirus will quarantine the file
outright. **Auto-update still works**, so this is unpleasant rather than fatal —
but it is the single biggest install-conversion killer.

**What to buy:** an **OV** or **EV** code-signing certificate from DigiCert,
Sectigo, SSL.com, GlobalSign or Certum.

- **OV** (~$200–400/year): cheaper, but SmartScreen reputation builds up over
  weeks of downloads, so early users still see warnings.
- **EV** (~$400–700/year): SmartScreen trusts it more or less immediately.
  **Recommended** if you want a clean first impression.

Either way you will need company registration documents and a verifiable
business phone listing, and the certificate arrives on a **hardware USB token**
or as a cloud-signing account. Allow **1–3 weeks** for the vetting.

**Once you have it**, sign on the machine where the token is plugged in. Two
routes:

- *File-based certificate (`.pfx`)* — set two environment variables before
  `npm run dist`; electron-builder picks them up automatically:
  `CSC_LINK` (path to the `.pfx`) and `CSC_KEY_PASSWORD` (its password).
  **Never commit either. Never paste the password into chat.**
- *Hardware token / cloud signing* — the key cannot be exported, so
  electron-builder must call the vendor's tool. Add to `desktop/package.json`
  under `build.win` either `"certificateSubjectName": "<your company name as it
  appears in the certificate>"` (Windows picks the token cert from the
  certificate store), or a custom `"sign"` script that shells out to the CA's
  signing utility. Your CA's documentation will name the exact tool.

**After signing, verify:** right-click the `.exe` → Properties → there is a
**Digital Signatures** tab naming your company. If that tab is missing, it is
not signed, whatever the build log said.

### 5.2 macOS

**Without signing and notarization:** macOS Gatekeeper refuses to open the app
at all on a normal Mac ("damaged and can't be opened" or "unidentified
developer"). And **auto-update is impossible** — Squirrel.Mac requires a signed
app, which is why `desktop/main/updater.js` deliberately no-ops updates on
macOS. Mac users must download each new version by hand.

**What to buy:** an **Apple Developer Program** membership — **$99/year**,
enrolled as your company (needs a D-U-N-S number for an organization
enrolment; allow 1–2 weeks). From it you generate a **"Developer ID
Application"** certificate.

**Once you have it**, in `desktop/package.json` under `build.mac`:

- change `"identity": null` → `"identity": "Developer ID Application: <Your
  Company> (TEAMID)"`
- change `"hardenedRuntime": false` → `true`
- add notarization credentials as environment variables at build time
  (`APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID`) and enable
  `"notarize": true`

Hardened runtime is stricter than the current build, and this app ships native
modules (`better-sqlite3`, `uiohook-napi`, `screenshot-desktop`). Budget time
for entitlements tuning — the first hardened build usually fails to launch until
the right entitlements are added.

**After notarizing, verify on a Mac that never built it:**
`spctl -a -vvv -t install "/Applications/Staffvora Desktop.app"` must say
`accepted / source=Notarized Developer ID`.

macOS also needs the employee to grant **Screen Recording** and **Accessibility**
permissions on first run (System Settings → Privacy & Security). That is a
person-at-the-keyboard step no build can remove — put it in your install
instructions.

---

## 6. Publish the installers on the server

Installers must live **outside** the deployed code folder. Hostinger's git
deploy deletes untracked files inside the tree, which would erase them on the
next push.

```bash
# once, on the server
mkdir -p /home/USER/staffvora-installers

# in .env
DESKTOP_INSTALLERS_PATH=/home/USER/staffvora-installers

php artisan config:cache
```

Upload from your PC (keep the exact filenames — spaces and all):

```powershell
scp "dist-app\Staffvora Desktop Setup 0.4.6.exe" USER@2.24.11.90:/home/USER/staffvora-installers/
scp "dist-app\Staffvora Desktop Setup 0.4.6.exe.blockmap" USER@2.24.11.90:/home/USER/staffvora-installers/
scp "dist-app\latest.yml" USER@2.24.11.90:/home/USER/staffvora-installers/latest-0.4.6.yml
```

Note the last one is uploaded as **`latest-0.4.6.yml`**, not `latest.yml`. That
is deliberate: the app keeps every version's manifest archived by version, and
`latest.yml` is just a copy of whichever one is currently active. That is what
makes rollback possible.

Then activate it:

```bash
cd /home/USER/staffvora
php artisan desktop:set-active-version --list      # shows what is archived and what is live
php artisan desktop:set-active-version 0.4.6       # copies latest-0.4.6.yml → latest.yml
```

Also upload the macOS `.dmg` (and `.zip`) into the same folder if you built one.

---

## 7. The auto-update feed — is it ready?

**Yes, the server side is ready. No, it is not yet serving anything.** Precisely:

**What works today, verified in the code:**

- The route exists and is public (the updater has no login session):
  `GET /desktop-updates/{file}` → `DesktopDownloadController@updates`.
- It is registered **before** the marketing site's catch-all route, so the
  catch-all does not swallow it.
- It only serves single-segment filenames ending in `.yml`, `.exe`,
  `.blockmap`, `.dmg` or `.zip`, and blocks path traversal. It reads from
  `DESKTOP_INSTALLERS_PATH` first, then two local-dev fallbacks.
- It sends `Cache-Control: no-cache`, so a new release is picked up
  immediately rather than being cached by a CDN or browser.
- The desktop app is configured to poll exactly this URL
  (`build.publish` → `https://staffvora.com/desktop-updates`): once ~15 seconds
  after launch, then every 4 hours.
- `allowDowngrade` is on, which is what makes rollback work: republish an older
  manifest as active and every client moves **back** to it on its next check.
  Nobody reinstalls by hand.
- `php artisan desktop:set-active-version <version>` is the switch that
  publishes or rolls back a release.

**What is missing — all of it on your side:**

- [ ] `DESKTOP_INSTALLERS_PATH` is **not set** in production, so today the feed
      has nothing to serve and returns 404 to every updater.
- [ ] No Staffvora-branded installer has ever been built, so there is nothing to
      upload.
- [ ] Windows installers are **unsigned** (§5.1) — the update *mechanism* works
      unsigned, but the first install shows a SmartScreen warning.
- [ ] macOS auto-update **will not work at all** until the app is signed and
      notarized (§5.2). Mac is manual-download-only until then.

**A known mismatch to fix before the download page works** — this is in a file
outside this task's scope, so it is listed here rather than changed:
`app/Http/Controllers/DesktopDownloadController.php` hardcodes the installer
filenames it will offer on the `/download` page, and they still say **0.3.4**
(Windows) and **0.3.2/0.3.4** (macOS). A freshly built `Staffvora Desktop Setup
0.4.6.exe` will **not** be found and the download page will show "unavailable" —
even though auto-update, which asks for filenames by name, works fine. Someone
must add the 0.4.6 filenames to `WINDOWS_INSTALLERS` / `MAC_INSTALLERS` and bump
`WINDOWS_VERSION` / `MAC_VERSION`.

**Prove the feed works, from any machine:**

```bash
curl -I https://staffvora.com/desktop-updates/latest.yml      # expect 200
curl -s  https://staffvora.com/desktop-updates/latest.yml     # expect version: 0.4.6
```

Then, on a PC running the **previous** version, open the app and wait ~15
seconds: it should show the update banner and, after downloading, offer
"Restart now".

---

## 8. Roll a bad desktop release back

You do not need anyone to reinstall anything.

```bash
cd /home/USER/staffvora
php artisan desktop:set-active-version --list     # find the last good version
php artisan desktop:set-active-version 0.4.5      # every client reverts on its next check
```

Clients check every 4 hours (and ~15 seconds after each launch), so the fleet is
back on the good build within one working day at the outside. This works only
for versions whose `latest-<version>.yml` **and** installer are both still in the
installers folder — **so never delete an old installer you might want back.**

---

## 9. Release sign-off

Do not tell anyone the desktop app is available until every box is ticked.

**Build**
- [ ] `npm test` in `desktop/` passes (reporter + collector self-tests)
- [ ] `desktop/package.json` version is ≥ 0.4.6
- [ ] Windows `.exe`, `.blockmap` and `latest.yml` all produced
- [ ] macOS `.dmg` produced on a Mac (or macOS consciously deferred)

**Signing**
- [ ] Windows installer signed, and Properties shows a Digital Signatures tab
- [ ] macOS app signed **and notarized**, `spctl` says "Notarized Developer ID"
- [ ] — or: unsigned shipped knowingly, and the install instructions warn users
      about the SmartScreen / Gatekeeper prompt

**Server**
- [ ] `DESKTOP_INSTALLERS_PATH` set, `php artisan config:cache` run
- [ ] Installers + `latest-<version>.yml` uploaded
- [ ] `php artisan desktop:set-active-version <version>` run
- [ ] `curl -I https://staffvora.com/desktop-updates/latest.yml` returns 200
- [ ] `php artisan deploy:preflight` shows "Desktop app downloads" as OK
- [ ] `DesktopDownloadController` filename list updated so `/download` offers the
      new build (see §7)

**End-to-end on a clean PC**
- [ ] Installs and launches; tray and window say **Staffvora Desktop**
- [ ] Signs in against `https://staffvora.com` with no manual URL entry
- [ ] Tracking starts; a screenshot reaches the web app
- [ ] The machine appears under Compliance and is not flagged as a stale agent
- [ ] Diagnostics events appear server-side (tracker health screen)
- [ ] An older build on another PC auto-updates to it

---

## Related documents

- `documents/PRODUCTION_DEPLOYMENT.md` — the server runbook (§8 there covers the installers folder)
- `documents/BITDEFENDER_EXCLUSION_GUIDE.md` — antivirus exclusions employees may need
- `desktop/README.md` — what the agent collects and how it behaves
