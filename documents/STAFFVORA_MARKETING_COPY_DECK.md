# Staffvora — marketing site copy deck (launch)

**Status:** written and published 2026-08-03 (Phase 7, launch readiness).
**Source of truth:** `database/seeders/SiteContentSeeder.php` — the copy below is a
readable mirror of that file. Edit the seeder, or edit pages in the operator Site
Builder; do not treat this document as the thing the site renders.
**Guard:** `tests/Feature/PublicSiteContentTest.php` fails the build if any public
page is unpublished, empty, placeholder-ridden, or claims something we do not ship.

---

## ⚖️ READ THIS FIRST — the legal pages are DRAFTS, NOT LEGAL ADVICE

`/privacy` and `/terms` were written to be **honest and specific about what this
product actually does**, by an engineer, not by a lawyer. They are a genuine first
draft and a good starting point for review. **They have not been reviewed by a
qualified lawyer and must be before launch.** Staffvora is employee-monitoring
software sold across borders; the legal exposure here is the largest single risk
on the site.

Deliberate choices made in the drafting, so a reviewer knows what to look at:

- **No compliance claims anywhere.** GDPR, CCPA, SOC 2, ISO 27001 and HIPAA are
  never claimed. The security page names them **only to deny** them ("we have not
  been audited… we do not claim compliance"). This is enforced by a test.
- **No entity details invented.** No registered company name, company number or
  postal address appears, because none is configured (`config/brand.php` →
  `legal_name`, `address` are env-driven and currently blank/defaulted).
- **Governing law is deferred**, not asserted: the terms say we operate from
  Pakistan and invite a customer needing a specific jurisdiction to contact us.
  A lawyer must replace this with a real clause.
- **Monitoring lawfulness is placed on the employer**, with an indemnity, and the
  privacy policy tells employees to ask their employer. Whether that allocation
  holds up is exactly a lawyer question.

### Questions a qualified lawyer must answer before launch

1. **Controller vs processor.** For time, activity, screenshot and machine-
   inventory data: is Staffvora a processor acting for the customer, a joint
   controller, or a controller in its own right for some of it (e.g. account and
   billing data, support access, aggregate telemetry)? The drafts deliberately
   avoid these words. The answer decides whether we need a **Data Processing
   Agreement** as a standard document, and what it must say.
2. **Lawful basis for monitoring.** Consent is usually a weak basis in an
   employment relationship. What basis do we tell customers to rely on, and in
   which jurisdictions is screenshot capture lawful at all, restricted, or
   requiring works-council/union consultation or prior notification? Should the
   product **block** capture for certain regions rather than rely on a warning?
3. **Cross-border transfer.** Data is hosted on infrastructure whose region the
   owner must confirm (see owner actions below), sold from Pakistan, to customers
   who may be in the EU/UK. What transfer mechanism is required, what must the
   privacy policy disclose about the hosting location, and does the AI feature
   (which sends work data to Anthropic's API) need its own disclosure, sub-
   processor listing or opt-out?
4. **Data-retention minimums and maximums.** Time and attendance records are
   employment records — some jurisdictions require them to be kept for years,
   while privacy law pushes toward deleting monitoring data quickly. Does our
   plan-capped retention (7 / 90 / 365 / unlimited days) create a conflict for a
   customer, and must we warn them or refuse to delete something on request?
5. **Erasure mechanics.** Our erasure promise is "we delete the workspace and
   everything in it, including stored screenshot files". See the open engineering
   gap in *Owner actions* — the promise must be true before the page is live.
6. **Supporting documents.** Do we also need: a DPA, a sub-processor list, a
   cookie notice (the site sets no third-party cookies today), a refund policy,
   and an acceptable-use policy separate from the terms?

Until items 1–5 are answered, treat every sentence on `/privacy` and `/terms` as
provisional.

---

## What is on the site now

| Page | Slug | State |
| --- | --- | --- |
| Home | `home` | published |
| Features | `features` | published |
| How it works | `how-it-works` | published |
| Pricing | `pricing` | published |
| Solutions (hub) | `solutions` | published |
| For agencies | `solutions/agencies` | published |
| For BPOs & call centres | `solutions/bpos` | published |
| For software houses | `solutions/software-houses` | **new**, published |
| For remote teams | `solutions/remote-teams` | **new**, published |
| For freelancers | `solutions/freelancers` | **new**, published |
| About | `about` | published |
| Contact | `contact` | published |
| Security | `security` | published |
| Download | `download` | published |
| Privacy Policy | `privacy` | published (draft copy — see above) |
| Terms of Service | `terms` | published (draft copy — see above) |

The three new solutions pages were already referenced by `config/navigation.php`
and were silently hidden because the pages did not exist. All five now appear in
the header dropdown and the footer.

---

## The product, as the copy describes it

Staffvora tracks working time for companies: clock in/out, attendance and shifts,
timesheets with approval, reports and insights, plus an **optional** desktop
tracker that can capture screenshots and activity. It is multi-tenant SaaS. The
**Agency toolkit** (Upwork profiles, marketplace work categories, Machine
Compliance) is a **per-seat add-on**, not a separate product.

### Claims that are made, and where they were verified

| Claim in copy | Verified against |
| --- | --- |
| Clock in/out, breaks, overnight shifts | `EmployeeAttendanceController`, `AttendanceCloser`, shift models |
| Timesheets with approval + audit trail | `TimesheetApprovalController`, `PayPeriod`, `ManualAttendanceAudit` |
| CSV exports | `work-hours.export`, `employee-attendance.export`, `time-entries.export` |
| Screenshots / activity, off by default per workspace | `MonitoringSetting.capture_enabled`, `blur_screenshots`, `notify_on_screenshot` |
| "Counts, not contents" | `tracking_activity_samples`: `keyboard_count`, `mouse_count`, `url_domain` — no key contents, no full URL |
| App + website-domain tracking is Growth-and-up | `Plan::defaults()` → `app_url_tracking` false on Starter |
| AI day summaries (Growth+) | `AnthropicService::daySummary`, `ai` entitlement |
| Slack digests / clock notifications | `SlackReportService`, `ActivityDigestService`, `slack_clockin_enabled` |
| Retention 7 / 90 / 365 / unlimited days | `Plan::defaults()` → `max_retention_days` |
| Retention deletes the image files, not just rows | `PruneTrackingScreenshots` (`$disk->delete($path)`) |
| Screenshots private + permission-checked | `filesystems.screenshots` visibility private; `monitoring.screenshots.image` route |
| Workspace isolation fails closed | `OrganizationScope`, `TenancyFailClosedTest` |
| Offline queueing | `Api\Desktop\SessionController` offline-queued starts |
| White-label branding (Growth+) | `white_label` entitlement, `branding_logo_path` |
| Agency toolkit contents + price | `addons` seed row `agency_toolkit` ($4/seat/mo, $40/yr) |
| Machine Compliance inventory | `machine_inventory_items` (extensions/programs/processes/network) |
| Desktop tracker is a preview, unsigned | `DesktopDownloads.jsx` "Preview — signing required" |
| Bank transfer, no card checkout | `BillingController::checkout` (`config('billing.gateway')` unset) |
| 14-day trial on Growth | `TenantProvisioner` default 14 days; `pro` is `is_default_trial` |
| No third-party analytics on the site | no gtag/plausible/hotjar/pixel anywhere in `resources/views` |

### Claims deliberately NOT made

- **No SSO / SAML** and **no public API** — neither exists. Previously advertised;
  removed, and now blocked by a test.
- **No payroll processing** and **no client invoicing** — the old solutions copy
  promised "payroll-ready timesheets" and "invoices your clients trust". Both are
  gone. (Timesheets and pay periods exist; running payroll and invoicing clients
  do not.)
- **No macOS "available now"** — the in-app download page reports mac as
  unavailable in this environment; the site says preview and points people at
  their workspace.
- **No auto-update or signed-installer promise** — installers are not signed yet.
- **No fake customers.** The home page's placeholder testimonials ("Your happy
  customer", "Add a real review") and the About page's placeholder team grid were
  deleted rather than filled with invented people.
- **No self-serve card checkout implied anywhere.**

---

## Pricing copy (owner-approved placeholders)

Per seat. Yearly = pay 10 months (~17% off).

| Plan | Monthly | Yearly | PKR/mo | Seats | History |
| --- | --- | --- | --- | --- | --- |
| Free | $0 | $0 | Rs 0 | 2 | 7 days |
| Starter | $5 | $50 | Rs 900 | 10 | 90 days |
| Growth *(most popular, default trial)* | $9 | $90 | Rs 1,600 | 50 | 1 year |
| Enterprise | from $18 | custom | from Rs 3,200 | unlimited | unlimited |
| **Agency toolkit** (add-on) | $4 | $40 | Rs 700 | per seat | attaches to Starter+ |

Mechanics of the pricing block (`resources/js/Components/Site/blocks/PricingTable.jsx`):

- USD prices, plan names and seat caps render **live from the `plans` table** —
  changing a price in the operator panel changes the website. Growth's plan key is
  `pro` (deliberate: renaming a key would be a code-visible change).
- PKR prices are **fixed owner-approved figures** supplied as block content
  (`data.pkr`), not an FX conversion of the dollar price. Previously the block
  multiplied USD by a 280 rate and quoted Rs 1,400 for Starter — wrong.
- The **Agency toolkit card** is block content (`data.addon`) because the block
  renderer only passes `plans` to blocks. **If the `agency_toolkit` add-on price
  changes in the operator panel, update the pricing page content too.**
- Enterprise now shows **"from $18/seat·mo · custom yearly terms"** instead of
  "Let's talk", and the annual 10-for-12 discount is deliberately not applied to
  it (we would be quoting a discount nobody agreed).
- Plan feature lists were corrected: Free no longer says "Up to 3 users" (it is
  2), Starter no longer claims app/URL tracking (that is Growth), and the
  Enterprise list no longer refers to a plan called "Pro".

---

## Page-by-page notes

**Home** — hero, six core capabilities, the "counts, not contents" transparency
split, three stats, three steps, an Agency toolkit teaser, five FAQs, CTA.
Fake testimonials removed; the empty logo bar removed.

**Features** — core six, an insight split (Growth features), the transparency
split, the Agency toolkit grid, an operator-facing grid (roles, privacy controls,
timezones, offline, white-label, help centre + request inbox), CTA.

**How it works** — three steps, the transparency split, a "tell your team first"
split that sets the expectation that informing employees is the employer's job,
five FAQs including the retention windows, CTA.

**Pricing** — hero, pricing table (toggles, PKR, add-on card), a "what changes as
you move up" grid, seven FAQs that state plainly that payment is by bank transfer
and card checkout is not live, CTA.

**Solutions** — hub plus five audience pages (agencies, BPOs, software houses,
remote teams, freelancers). The agency page mentions the Agency toolkit; the
software-house page leans on "no keylogging, domains not URLs".

**About** — the honest origin story (built for our own distributed team first),
three beliefs, three stats. **No team members are named** and no company entity is
claimed — see owner actions.

**Contact** — hero, contact form, and a short FAQ that routes buying, add-on
requests, security reviews and bug reports to the right place.

**Security** — six real controls, a "what we have not done — said plainly"
section (no audits, no SSO, no API, unsigned preview installers), what is never
recorded, vulnerability reporting, six FAQs including operator access and the
absence of website analytics.

**Download** — leads with "the desktop tracker is optional", Windows/macOS/no
install, how to get it from inside the workspace, honest preview status, what the
app does on the machine, requirements. Explicitly: never ask anyone to disable
security software.

**Privacy** — 12 sections: who does what, what is collected (account, time,
activity metrics, app/window/domain, screenshots, machine inventory, technical),
what is never collected, why, who can see it (including our own staff and
operator impersonation with audit logging), sub-processors (hosting, Slack,
Anthropic for AI, email), retention by plan, deletion and requests, the
employer's obligations, security, children, changes, contact.

**Terms** — 14 sections: agreement, workspace responsibility, monitoring
lawfulness + indemnity, acceptable use, plans and billing (bank transfer, nothing
auto-charged), plan limits, data ownership, availability (no SLA, preview desktop
app), changes, termination, warranties and liability cap, IP, changes to terms,
governing law deferred + contact.

---

## Owner actions (JAWAD)

1. **Get a lawyer to review `/privacy` and `/terms`** against the six questions at
   the top of this document, and give me back the corrected text.
2. **Set the business identity env vars** before launch — `BRAND_LEGAL_NAME`,
   `BRAND_ADDRESS`, and optionally `BRAND_PHONE` / `BRAND_WHATSAPP` / socials in
   `config/brand.php`. Legal pages usually need a registered entity and address.
3. **Confirm the hosting region in writing.** The security and privacy pages say
   we will confirm it on request rather than naming one, because the deployment
   target was not settled. Decide it, then tell me and I will name it on the page.
4. **Close the erasure gap.** `tenant:delete` removes every database row for a
   workspace but does **not** delete that workspace's screenshot files from the
   screenshots disk (S3 or local), and once the rows are gone the retention job
   can never find those files either. The privacy page promises the files go too,
   so today that promise depends on someone purging the storage prefix by hand.
   This should be fixed in the command before the policy is relied on.
5. **Decide macOS.** If a signed macOS build will not be available at launch, the
   download page's macOS card should be softened further; if it will, tell me and
   I will state it plainly.
6. **`npm run build` is required** before these changes are visible in a browser —
   the pricing table and rich-text fixes are JSX, and the committed bundle is
   stale. (I was asked not to run a build.)
7. **Run the seeder wherever the site is served** (see below).

## Deploy notes

```bash
php artisan db:seed --class=SiteContentSeeder     # publishes the launch copy
```

- Safe and idempotent: a page an operator has since edited in the Site Builder is
  left untouched, and a second run writes nothing.
- To deliberately overwrite everything with the copy in the seeder:
  `SITE_CONTENT_FORCE=1 php artisan db:seed --class=SiteContentSeeder`
  (PowerShell: `$env:SITE_CONTENT_FORCE=1; php artisan db:seed --class=SiteContentSeeder`).
- `DatabaseSeeder` now calls it, so a fresh install gets a published site.
- No migration is involved. The `site_pages` table has no `published_at` column —
  "published" is `status = 'published'` plus a populated `published_blocks`
  snapshot, which is what the public renderer and the nav resolver read.
