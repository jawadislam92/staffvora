; Custom NSIS include for Staffvora Desktop.
;
; ---------------------------------------------------------------------------
; 1. PRE-INSTALL DISCLOSURE
; ---------------------------------------------------------------------------
; This app is monitoring software: it can record working time, activity levels
; and — when the employer enables them — screenshots and the names of apps and
; websites. Nothing about that may be a surprise, so the installer says so
; before a single file is written, and CANCEL really cancels.
;
; This notice is NOT the consent, and it is deliberately not written as one.
; The consent is taken on FIRST LAUNCH, inside the app, per user, with an
; unticked box (see desktop/main/consent.js). The reasons the installer cannot
; be the place consent is given:
;
;   * an installer runs ONCE PER MACHINE, and on a managed fleet it is run by
;     an IT administrator — so "I agree" here would be one technician agreeing
;     on behalf of people who have not been told anything;
;   * at install time nobody has signed in, so there is no identity to record
;     the acceptance against, and an unattributable consent is not evidence;
;   * auto-updates re-run this installer silently, and a consent that is
;     re-taken by a background update is meaningless.
;
; It is skipped in silent mode. That covers both electron-updater (which runs
; the installer with /S to apply an update) and IT mass-deployment — a modal
; dialog on an unattended machine would hang the rollout, and skipping it costs
; nothing because the per-user gate at first launch still applies.
;
; ---------------------------------------------------------------------------
; 2. FORCE-CLOSE A RUNNING INSTANCE
; ---------------------------------------------------------------------------
; Staffvora Desktop is an Electron app with a tray icon and several helper
; processes (all named the same exe), so the installer's default "please close
; the app" check can get stuck on "Staffvora Desktop cannot be closed. Please
; close it manually and click Retry." Defining customCheckAppRunning overrides
; that check with a hard taskkill of the whole process tree, so updates and
; installs proceed without user prompts.

!macro customInit
  ; Auto-update / unattended deployment: no dialogs.
  IfSilent sv_skip_monitoring_notice

  MessageBox MB_OKCANCEL|MB_ICONINFORMATION \
    "Staffvora Desktop is workplace monitoring software.$\n$\n\
When an employee signs in and starts the tracker, it records their working time and activity level. \
If their employer enables it, it also records periodic screenshots of the screen and the names of the \
apps and websites in use.$\n$\n\
Every person who signs in on this computer is shown a full summary and must read and accept the Terms \
of Service and Privacy Policy before anything is recorded. Nothing is recorded until they do, and \
nothing is recorded while the tracker is stopped.$\n$\n\
Terms:   https://staffvora.com/terms$\n\
Privacy: https://staffvora.com/privacy$\n$\n\
Select OK to install, or Cancel to stop." \
    IDOK sv_skip_monitoring_notice

  Abort

  sv_skip_monitoring_notice:
!macroend

!macro customCheckAppRunning
  nsExec::Exec 'taskkill /F /IM "${APP_EXECUTABLE_FILENAME}" /T'
  Sleep 1500
!macroend
