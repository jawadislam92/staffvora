# Tracksuite product audit — 2026-07-11

## Executive verdict

Tracksuite has a substantial working product, but the repository began this
audit as a company-specific tracker wrapped in SaaS pages. The first
productization pass has removed the most dangerous coupling: tenant access is
enforced, plan features are enforced on the server, Upwork concepts are now an
agency-only workspace option, incomplete public pages cannot publish, and the
desktop source has its own Tracksuite identity.

It is suitable for controlled pilots after deployment review. It is not yet
ready for an unrestricted public launch because legal text, desktop signing,
self-serve billing, privacy operations, and the remaining native desktop
dependency risk are unresolved.

## Industry benchmark

Official competitor material consistently treats basic time entry as table
stakes:

| Product | Strong patterns to learn from | Tracksuite implication |
| --- | --- | --- |
| Hubstaff | Time, attendance/PTO, screenshots, activity/app/URL insights, payroll, invoices, budgets, and 20+ reports; dashboard widgets can be moved/removed and signup chooses industry-specific widgets. | Keep workspace presets and add user-configurable dashboard widgets, budgets, profitability, invoicing, and deeper reports. |
| Hubstaff privacy model | Employees can see captured data, monitoring runs only while tracking, capture is configurable, and there is no keylogging/camera/email access. | Make transparency, employee visibility, and explicit capture settings product requirements, not marketing-only claims. |
| HubSpot reporting | Dashboard templates, drag-and-drop customization, dashboard filters, granular visibility, and recurring sharing. | Add saved dashboards, reusable filters, scheduled delivery, and role-based sharing. |
| Toggl Track | Offline sync, calendar/mobile/desktop entry, profitability, custom reports, scheduled/shared reports, invoicing, and billable rates. | Build a revenue/profitability layer on top of projects and tracked time. |
| Clockify | Timer/manual/timesheets, projects/tasks/clients, scheduling, invoicing, and integrations; monitored features are enabled explicitly with user notification. | Add scheduling and integrations while preserving visible, consent-aware monitoring. |
| Time Doctor | Activity, attendance, projects/tasks, timelines, app/web reports, and screenshot blurring. | Screenshot blur/redaction is a practical privacy feature for larger customers. |

Sources:

- https://hubstaff.com/features
- https://hubstaff.com/insights
- https://hubstaff.com/guiding-principles
- https://support.hubstaff.com/understanding-management-dashboard/
- https://www.hubspot.com/products/reporting-dashboards
- https://toggl.com/track/features/
- https://clockify.me/features/
- https://clockify.me/help/troubleshooting/how-clockify-handles-your-data
- https://www.timedoctor.com/features
- https://support.timedoctor.com/knowledge/what-reports-does-time-doctor-generate
- https://support.timedoctor.com/knowledge/how-to-set-up-blurred-screencasts

## Completed in this pass

1. Added one organization-access decision path for suspended and expired
   workspaces across web sessions, API tokens, and desktop login.
2. Added route-level entitlement enforcement for monitoring, activity, app/URL
   tracking, AI, and integrations. Desktop settings are clamped to the plan,
   and screenshot limits are enforced during upload.
3. Made the default workspace project-based. `Project`, `Client`, and `Account`
   terminology now follows the selected workspace. Upwork profiles and labels
   are limited to the agency preset.
4. Scoped profile validation to the current organization and prevented general
   workspaces from retaining legacy profile assignments.
5. Rebranded desktop source, deep links, app identifiers, notifications,
   installer discovery, and documentation as Tracksuite. Legacy artifact names
   remain download fallbacks only.
6. Marked unsigned desktop installers as controlled previews and removed public
   instructions that asked employees to bypass antivirus or OS protections.
7. Added a public-site release guard: any page whose live content still contains
   `[REPLACE]` is taken offline and retained as an editable draft.
8. Removed the unused dashboard analytics payload, including synthetic hourly
   charts and hardcoded eight-hour efficiency scores. The live clock now uses
   the workspace timezone.
9. Removed the internal cPanel deployment destination, made brand/business
   details environment-driven, refreshed deployment documentation, and added
   GitHub Actions checks for tests and web/desktop renderer builds.
10. Updated vulnerable Guzzle/PSR-7 packages, `form-data`, `uuid`, and Jimp. The
    Jimp 1.x multi-monitor stitch pipeline has a deterministic smoke test.

## Launch gates (P0)

### Legal and privacy

- Replace and have counsel approve Terms, Privacy Policy, monitoring notice,
  data-processing terms, subprocessors, retention language, and governing law.
- Confirm the legal entity, support contacts, hosting region, and actual payment
  methods. Do not publish compliance claims until evidence exists.
- Add an auditable employee monitoring acknowledgement/notice flow appropriate
  to each customer's jurisdiction.
- Add tenant-level data portability. Tenant deletion exists, but a complete
  workspace export does not.

### Desktop release

- Code-sign Windows installers and sign/notarize macOS builds.
- Replace or isolate `active-win`. Its current native build chain retains six
  high-severity `node-tar` advisories. npm's automatic fix downgrades it to an
  older FFI-native major, so it must not be forced without clean-machine native
  and packaging tests.
- Add signed Windows and macOS release runners that test install, upgrade,
  rollback, auto-update, permissions, offline queueing, multi-monitor capture,
  sleep/wake, and uninstall.
- Decide whether app/URL capture should be a separate explicit consent toggle
  from activity levels.

### Billing and commercial operations

- Connect a hosted payment provider and implement checkout, verified webhooks,
  idempotency, renewal, cancellation, failed-payment recovery, invoices, tax,
  and refunds. Current billing is manual bank transfer.
- Confirm price/seat semantics and trial-to-paid behavior in legal text and UI.

### Security and reliability

- Add browser end-to-end tests for signup, onboarding, billing lockout, time
  entry, tenant isolation, permissions, and monitoring visibility.
- Add rate-limit, CSP, storage-access, backup/restore, queue-worker, scheduler,
  error monitoring, incident response, and disaster-recovery verification.
- Add MFA for customer owners and platform operators. Consider SSO/SAML only
  after MFA and audit-log coverage are mature.
- Perform an independent penetration test before selling to larger teams.

## Product roadmap

### P1 — competitive core

1. Customizable dashboards: role/workspace templates, movable widgets, saved
   filters, visibility rules, and scheduled email/Slack reports.
2. Project economics: billable rates, budgets, cost rates, utilization,
   profitability, alerts, approvals, and invoice-ready exports.
3. Scheduling/PTO: capacity planning, leave requests, approvals, holidays, and
   attendance-policy calendars.
4. Privacy controls: screenshot blur/redaction, retention preview, employee data
   export, capture notices, and a visible “what is being collected” panel.
5. Integrations: Slack first, then project tools and accounting/payroll based on
   pilot demand. Avoid a broad marketplace before the core APIs are stable.

### P2 — scale and expansion

- Mobile timer and calendar integrations.
- Public API, webhooks, integration health, and scoped API credentials.
- SSO, SCIM, advanced audit logs, data residency, and enterprise retention.
- Customer-facing status page, changelog, release notes, and incident history.

## Recommended positioning

Do not compete as “another screenshot tracker.” The strongest credible wedge is:

> Flexible time and project insight for service teams, with monitoring that is
> visible, proportionate, and controlled by the people who use it.

That supports general teams by default, lets agencies opt into Upwork-specific
workflows, and makes privacy architecture a product advantage rather than a
disclaimer.
