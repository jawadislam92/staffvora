# Allow SA Track in Bitdefender Total Security — step by step

SA Track is our own time-tracker. Bitdefender flags it as malware (false positive)
because it's an unsigned app that takes screenshots and reads keyboard/mouse
activity — normal for a monitoring tool, but it looks suspicious to behavioural
AV (this is why Hubstaff/Time Doctor also need exclusions). The same thing blocks
installs/updates ("SA Track cannot be closed"). The fix is to add an **exception**.

You're on **Bitdefender Total Security** (the consumer product), so this is done
**in the Bitdefender app on each computer** (there's no central console). Repeat on
every machine that runs SA Track.

## What to exclude (the folder)
SA Track installs here (AppData is a hidden folder — type/paste the path):
```
C:\Users\<USERNAME>\AppData\Local\Programs\timetracker-desktop
```
Replace `<USERNAME>` with the Windows user (it's the folder name under `C:\Users`,
or run `echo %USERNAME%` in Command Prompt). On most machines you can also paste
`%LocalAppData%\Programs\timetracker-desktop` and Bitdefender will resolve it.

## Steps in Bitdefender Total Security
1. Open **Bitdefender** (double-click its icon near the clock, or Start menu).
2. Left sidebar → **Protection**.
3. In the **Antivirus** tile, click **Open**.
4. Click the **Settings** tab → **Manage Exceptions**.
5. Click **+ Add an Exception**.
6. Paste the folder path (above) into the box.
7. Turn **ON** every toggle shown — especially:
   - **Antivirus**
   - **Advanced Threat Defense**  ← this is the one that blocked "SA Track.exe"
   - **Online Threat Prevention**
8. Click **Save**.

## Allow it through the Bitdefender Firewall (required for sign-in)
The exception above stops the *scanner*, but Bitdefender's **Firewall** can still
block the app's connection to the server ("Could not reach the server").
1. **Protection → Firewall → Rules**.
2. Search **SA Track** (or "sa track.exe"). Set the top rule's **Access** to **ON**
   (Any network, Any protocol, Both, Any ports/IP). Add a rule if none exists.
3. (Optional) Also allow **sa track setup … .exe** so installs/updates can run.

## Clear the existing block / quarantine
If it already got blocked or quarantined:
- Open **Notifications** (the bell), find the **SA Track** "Threat blocked"
  entry → **Allow / Restore**, **or**
- **Protection → Advanced Threat Defense → Settings → Manage Exceptions** and add
  `C:\Users\<USERNAME>\AppData\Local\Programs\timetracker-desktop\SA Track.exe`,
- then reinstall SA Track from https://timetracker.sparkingasia.com/desktop-downloads.

## After the exception is added (per machine)
- SA Track stops being blocked.
- Installs and **auto-updates** complete cleanly — the "cannot be closed" loop ends.

## Also seen on at least one machine: NordVPN Threat Protection
`nordsec-threatprotection-service` was running too. If it also blocks SA Track,
add the same folder under **NordVPN → Threat Protection → exceptions** (or disable
NordVPN Threat Protection if it isn't needed alongside Bitdefender).

## Note (no cost involved)
We're not buying a code-signing certificate, so this exception is the supported
fix. It must be added per machine because it's the consumer Bitdefender product.
(If the company ever moves to **GravityZone**/Bitdefender Endpoint Security Tools,
the same exclusion can be pushed to all machines centrally from one policy.)
