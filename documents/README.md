# Project Documentation

## Going live

- `PRODUCTION_DEPLOYMENT.md` — **the deploy runbook.** nginx + SSL for
  staffvora.com and platform.staffvora.com, the `.env` keys to set, migrations,
  the queue worker, the scheduler cron, a smoke test and rollback. Written to be
  followed by the owner.
- `DESKTOP_RELEASE_CHECKLIST.md` — **the desktop release.** Version, per-platform
  builds, code signing (what to buy and why nobody else can do it), publishing
  installers, and how the auto-update feed works.
- `STAFFVORA_ZERO_TO_100_PLAN.md` — the roadmap and phase gates.

Two commands back these documents up:
`php artisan deploy:preflight` (plain-English server readiness) and
`php artisan tenancy:audit` (the tenant-isolation invariant).

## Project handoff

Start here:

- `CODEX_COMPLETE_CHAT_AND_PROJECT_HANDOFF.md`

That file is the canonical project and conversation handoff for another Codex
session. It contains the current Git state, released work, local-only work,
product decisions, production cautions, verification history, and next steps.

Supporting documents:

- `APP_IMPROVEMENT_PLAN.md`: original application, interface, permissions, and
  Slack improvement plan.
- `FEATURE_ROADMAP_ATTENDANCE_REPORTS_MONITORING.md`: attendance, reporting,
  and future screenshot-monitoring roadmap.
- `SECURITY.md`: credential and production security rules.
- `SLACK_INTEGRATION.md`: Slack webhook, manual report, and scheduler setup.

These documents must not contain passwords, webhook URLs, API tokens, database
credentials, or other secrets.
