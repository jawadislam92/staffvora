; Custom NSIS include for Tracksuite Desktop.
;
; Force-close any running instance before installing. Tracksuite Desktop is an Electron
; app with a tray icon and several helper processes (all named the same exe),
; so the installer's default "please close the app" check can get stuck on
; "Tracksuite Desktop cannot be closed. Please close it manually and click Retry."
; Defining customCheckAppRunning overrides that check with a hard taskkill of
; the whole process tree, so updates/installs proceed without user prompts.
!macro customCheckAppRunning
  nsExec::Exec 'taskkill /F /IM "${APP_EXECUTABLE_FILENAME}" /T'
  Sleep 1500
!macroend
