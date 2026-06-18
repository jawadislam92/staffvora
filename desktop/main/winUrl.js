'use strict';

const { execFile } = require('node:child_process');

// Windows UI Automation script: from the focused element, walk up to the
// top-level window, then find the address-bar Edit control and read its value.
// Works for Chrome/Edge/Firefox/Brave without any browser extension. Passed to
// PowerShell as base64 (-EncodedCommand) to avoid all shell-quoting issues.
const PS_LINES = [
  '$ErrorActionPreference = "SilentlyContinue"',
  'Add-Type -AssemblyName UIAutomationClient',
  'Add-Type -AssemblyName UIAutomationTypes',
  '$el = [System.Windows.Automation.AutomationElement]::FocusedElement',
  'if (-not $el) { return }',
  '$walker = [System.Windows.Automation.TreeWalker]::ControlViewWalker',
  '$win = $el',
  'while ($win -ne $null) {',
  '  $parent = $walker.GetParent($win)',
  '  if ($parent -eq $null -or $parent -eq [System.Windows.Automation.AutomationElement]::RootElement) { break }',
  '  $win = $parent',
  '}',
  'if (-not $win) { return }',
  '$cond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty, [System.Windows.Automation.ControlType]::Edit)',
  '$edits = $win.FindAll([System.Windows.Automation.TreeScope]::Descendants, $cond)',
  'foreach ($e in $edits) {',
  '  $vp = $null',
  '  if ($e.TryGetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern, [ref]$vp)) {',
  '    $val = $vp.Current.Value',
  '    $nm = $e.Current.Name',
  '    if ($val -and ($nm -match "address|search|url" -or $val -match "^[\\w.-]+\\.[a-z]{2,}")) { Write-Output $val; break }',
  '  }',
  '}',
].join('\n');

const ENCODED = Buffer.from(PS_LINES, 'utf16le').toString('base64');

const BROWSERS = ['chrome', 'edge', 'msedge', 'firefox', 'brave', 'opera', 'vivaldi'];

function isBrowser(appName) {
  if (!appName) return false;
  const n = String(appName).toLowerCase();
  return BROWSERS.some((b) => n.includes(b));
}

/**
 * Resolve the active browser's URL domain via UI Automation. Resolves null on
 * non-Windows, non-browser, timeout, or any failure (best-effort).
 */
function getBrowserDomain(appName) {
  return new Promise((resolve) => {
    if (process.platform !== 'win32' || !isBrowser(appName)) {
      resolve(null);
      return;
    }

    execFile(
      'powershell.exe',
      ['-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-EncodedCommand', ENCODED],
      { timeout: 4000, windowsHide: true, maxBuffer: 1 << 20 },
      (err, stdout) => {
        if (err || !stdout) {
          resolve(null);
          return;
        }
        const raw = String(stdout).trim().split(/\r?\n/)[0]?.trim();
        if (!raw) {
          resolve(null);
          return;
        }
        try {
          const withScheme = /^https?:\/\//i.test(raw) ? raw : 'https://' + raw;
          const host = new URL(withScheme).hostname;
          resolve(host ? host.replace(/^www\./, '').slice(0, 255) : null);
        } catch {
          resolve(null);
        }
      }
    );
  });
}

module.exports = { getBrowserDomain, isBrowser };
