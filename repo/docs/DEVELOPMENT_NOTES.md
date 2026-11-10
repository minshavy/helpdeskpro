# HelpDeskPro — IT Helpdesk & Ticket Management System

A working implementation of the system described in your capstone proposal
(Chapter 1–6): a PHP + MySQL helpdesk with three roles (Admin, Technician,
Employee), ticket submission/assignment/resolution, dashboards, and a
comment/status history log — matching the ERD and wireframes in your report.

This has been built and **functionally tested end-to-end** (login, ticket
submission, admin assignment, technician status updates/closing, role-based
access control, and user registration all verified working).

## 1. Requirements
- XAMPP / WAMP / LAMP with PHP 8+ and MySQL/MariaDB
- A browser

## 2. Setup Instructions

1. Copy the whole `helpdeskpro` folder into your server's web root:
   - XAMPP (Windows): `C:\xampp\htdocs\helpdeskpro`
   - XAMPP (Mac/Linux): `/Applications/XAMPP/htdocs/helpdeskpro` or `/opt/lampp/htdocs/helpdeskpro`
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin`), go to the **Import**
   tab, and import `database.sql`. This creates the `helpdeskpro` database
   with the `users`, `tickets`, and `ticket_logs` tables plus 3 demo accounts.
4. If your MySQL root user has a password, update `config/db.php`:
   ```php
   define('DB_USER', 'root');
   define('DB_PASS', 'your_password_here');
   ```
5. Make sure the `uploads/` folder is writable (needed for ticket attachments).
6. Visit **`http://localhost/helpdeskpro/login.php`** in your browser.

## 3. Demo Accounts
All demo accounts use the password: **`password123`**

| Role       | Email                        |
|------------|------------------------------|
| Admin      | admin@helpdeskpro.com / ADM-0001        |
| Technician | tech1@helpdeskpro.com / TEC-0001        |
| Employee   | employee1@helpdeskpro.com / EMP-0001    |

There is no public sign-up: administrators create every account in **Manage Users**.

## 4. Folder Structure
```
helpdeskpro/
├── config/db.php          # Database connection
├── config/mail.php         # SMTP / email settings (PHPMailer)
├── config/cron.php          # Secret key for the SLA escalation cron endpoint
├── config/security_policy.php  # 2FA/session timeout/password expiry settings
├── includes/               # header, footer, functions.php, mailer.php, escalation.php, csrf.php, security.php, totp.php
├── lib/PHPMailer/           # Bundled PHPMailer library (from official GitHub repo)
├── cron/escalate_tickets.php # External/scheduled SLA escalation trigger
├── employee/                # Dashboard, submit/edit ticket, my tickets
├── technician/               # Dashboard, assigned tickets, canned responses, kb_manage, kb_edit
├── admin/                    # Dashboard, analytics, all tickets, assign, manage users, audit log, run_escalation, exports
├── knowledge_base.php / kb_article.php   # Self-service KB browse/search and article view
├── lib/FPDF/                  # Vendored pure-PHP PDF library (for report exports)
├── tests/                     # Automated test suite (php tests/run_tests.php)
├── admin/manage_departments.php  # Department CRUD (multi-team routing)
├── uploads/                 # Ticket attachments (.htaccess blocks script execution here)
├── assets/css/style.css
├── ticket.php                # Shared ticket detail page (view/comment/status/close)
├── login.php / logout.php / index.php
├── forgot_password.php / reset_password.php
└── database.sql              # Schema + seed data
```

## 5. Features implemented (mapped to your requirement analysis, Ch.4)
- User login/authentication with password hashing (bcrypt) ✅
- Role-based access control: Admin / Technician / Employee ✅
- Create tickets with title, description, priority, category, attachment ✅
- Admin assigns tickets to technicians ✅
- Technician updates ticket status (Open → In Progress → Closed) ✅
- Ticket history / comment log (`ticket_logs` table) ✅
- Dashboards per role with ticket-count summaries ✅
- Search & filter tickets (by title/status) ✅
- Technician workload overview (Admin dashboard) ✅
- Full user management: create, edit (name/email/password/role), and delete accounts ✅
- Employees can edit or withdraw their own ticket while it's still Open ✅
- Pagination and multi-field filtering on all ticket list pages ✅
- Shared canned responses for technicians/admins ✅
- Two-factor authentication (TOTP), session auto-logout, and optional password expiry policy ✅
- CSV/PDF export of ticket lists and analytics reports, plus SLA compliance % trend ✅
- Self-service Knowledge Base with search, categories, helpful voting, and ticket-integration ✅
- Sidebar navigation, in-app notification bell, dark mode, and inline attachment previews ✅
- Departments / multi-team routing with department-scoped assignment ✅
- Business-hours SLA clock (Maldives government office hours) ✅
- Auditor-grade audit trail with before/after state on every action ✅
- One-click database backup (pure PHP, verified to restore byte-identically) ✅
- Notifications: mark one / mark all as read, click-to-open marks read ✅
- Role-aware Help & Guide page, plus a welcome card for new employees ✅
- Admin-only account creation with a forced password change on first sign-in ✅
- Show/hide (eye) toggle on every password field ✅
- Staff ID issued automatically from role (ADM-/TEC-/EMP-, in order); sign in with email **or** Staff ID ✅
- 108 automated tests (`php tests/run_tests.php`), mutation-verified ✅

## 6. Analytics Dashboard
Admins now have a dedicated **Analytics** page (`admin/analytics.php`, linked in
the nav bar and from the main dashboard) with:

- **KPI cards**: average resolution time (closed tickets), tickets this week
  vs. last week (with trend indicator), SLA breach count, and active ticket count
- **Charts** (via Chart.js, loaded from CDN): tickets by status, by priority,
  by category, and a 30-day ticket-volume trend line
- **Technician performance table**: total assigned, closed, active, and
  average resolution time per technician
- **SLA tracking**: configurable per-priority thresholds (default: High = 24h,
  Medium = 72h, Low = 120h) at the top of `analytics.php` — any active ticket
  that's exceeded its threshold counts as a breach

This maps to the "reporting dashboards" and "technician workload" requirements
from your proposal, and is a natural addition to Chapter 4/5 (a real
justification for building analytics beyond a basic ticket counter) — worth
mentioning explicitly in your Chapter 7 conclusion as a differentiator versus
the free/basic tier of tools like Spiceworks.

## 7. SLA Auto-Escalation
Active tickets that sit unresolved past their priority's time threshold are
now **automatically escalated**:

- **Low** priority breaching 120h → auto-bumped to **Medium**
- **Medium** priority breaching 72h → auto-bumped to **High**
- **High** priority breaching 24h → stays High, but flagged as breached

Each ticket is only escalated once (tracked via the `escalated` column), the
event is logged in the ticket's comment/history log, and — if email is
enabled — both the ticket's stakeholders *and* all Admins get notified.
Escalated tickets show a **⚡ Escalated** badge everywhere they appear.

Thresholds are configurable in `includes/escalation.php` (`get_sla_hours()`).

### Running the check
**Manually (works anywhere, no setup):** Log in as Admin → **Analytics** →
click **"⚡ Run SLA Check Now."**

**Automatically, via cron:** Point a scheduler at:
```
https://yourdomain.com/cron/escalate_tickets.php?key=YOUR_SECRET_KEY
```
(replace `YOUR_SECRET_KEY` with the value in `config/cron.php` — **change
that value** from the default before deploying).

- **On InfinityFree:** the free tier has no built-in cron, so use a free
  external scheduler like https://cron-job.org — set it to hit that URL
  every 30–60 minutes.
- **On your own server / XAMPP with cron access:** add a scheduled task
  (cron / Windows Task Scheduler) that runs:
  ```
  php /path/to/helpdeskpro/cron/escalate_tickets.php --key=YOUR_SECRET_KEY
  ```

### If you already have a live database
Don't re-import from scratch — just run `migration_add_escalation.sql`
(a single `ALTER TABLE` statement) via phpMyAdmin's SQL tab. It adds the
`escalated` column without touching any existing data.

## 8. Email Notifications (PHPMailer)
The system now sends real email notifications using **PHPMailer** (bundled in
`lib/PHPMailer/`, downloaded directly from the official GitHub repo — no
Composer required). Emails are sent when:

- An employee submits a new ticket → **all Admins** are emailed.
- An Admin assigns a ticket to a technician → the **employee and technician** are emailed.
- A comment is added to a ticket → the **other party** (employee/technician, whoever didn't write it) is emailed.
- A ticket's status changes or is closed → the **employee and technician** are emailed.

### Enabling it
1. Open `config/mail.php`.
2. Set `MAIL_ENABLED` to `true`.
3. Fill in your SMTP details. For Gmail:
   - Turn on 2-Step Verification on the Google account.
   - Create an **App Password** at https://myaccount.google.com/apppasswords.
   - Use that 16-character app password as `MAIL_PASSWORD` (not your real Gmail password).
   - `MAIL_HOST` = `smtp.gmail.com`, `MAIL_PORT` = `587`, `MAIL_ENCRYPTION` = `tls`.
4. Update `APP_URL` to match your actual server address so the "View Ticket"
   links in emails work correctly.

Until you fill this in, `MAIL_ENABLED` stays `false` and the app works exactly
as before — email sending is skipped safely, and every send is wrapped in a
try/catch so a mail server outage can never break ticket actions.

## 9. Forgot Password
Anyone can click **"Forgot password?"** on the login page to reset their own
password by email - no more needing an Admin to manually recreate an account.

- A one-time, cryptographically random token is generated and emailed as a
  reset link. Only a **SHA-256 hash** of the token is stored in the database
  (so a database leak alone can't be used to reset accounts).
- Links expire after **30 minutes** and can only be used **once**.
- The request form always shows the same message ("If an account exists...")
  whether or not the email is registered, so it can't be used to check which
  emails have accounts.
- A successful reset also clears any login lockout on that account.
- Every request and completion is recorded in the **Audit Log**.

**If email isn't configured yet** (`MAIL_ENABLED` is `false` in
`config/mail.php`, which is the default), the reset link is shown directly
on the confirmation page instead of being emailed - handy for local testing,
and it's safe because it's only ever shown to the person who submitted the
form, in their own browser.

### If you already have a live database
Run `migration_add_password_reset.sql` via phpMyAdmin's SQL tab - adds the
`password_resets` table, no data loss.

## 10. Security Hardening
This build includes the security fundamentals a production deployment needs
(the gap between "works" and "safe to put real people's data in"):

- **CSRF protection**: every form (login, submit ticket, comment,
  assign, close, manage users, run SLA check) includes a per-session token
  that's verified before any data changes. Forged cross-site requests are
  rejected with a 403.
- **Login rate-limiting**: 5 failed attempts for the same email within 15
  minutes locks that email out temporarily - blocks brute-force password
  guessing.
- **Audit log** (Admin → Manage Users → View Audit Log): records login
  successes/failures, account registrations, user creation, role changes,
  and user deletions, each with a timestamp and IP address.
- **Secure sessions**: `HttpOnly` + `SameSite=Lax` cookies (blocks
  JavaScript/XSS session theft and cross-site cookie leakage), `Secure` flag
  auto-enabled once you're on HTTPS, and the session ID is regenerated on
  every login (blocks session fixation attacks).
- **File upload validation**: attachments are checked by their *real*
  content type (via `finfo`, not just the filename extension), size-limited
  to 5MB, restricted to JPG/PNG/GIF/PDF/TXT/DOC/DOCX, saved under a random
  generated filename, and the `uploads/` folder has a `.htaccess` blocking
  script execution as defense-in-depth.
- **Last-admin protection**: you can't delete or demote the only remaining
  Admin account, preventing an accidental full lockout.
- **Input validation**: server-side email format/length checks, minimum
  8-character passwords, and length limits on all free-text fields (not just
  relying on HTML `required`/`maxlength`, which anyone can bypass).

### If you already have a live database
Run `migration_add_security.sql` (adds the `login_attempts` and `audit_log`
tables) via phpMyAdmin's SQL tab - no data loss, no need to re-import.

### One-time setup reminder
This build also fixed a pre-existing bug where success/error flash messages
could silently overwrite page-specific error messages (e.g. "Invalid email
or password" wasn't displaying). If you're updating from an earlier version,
just replace `includes/header.php` and `login.php`.

## 11. UI/UX Redesign
The interface has been redesigned with a modern SaaS-dashboard look:

- **Design system**: indigo accent (`#4F46E5`) on a clean slate/white palette,
  with **Sora** for headings/brand and **Inter** for body/data text (both via
  Google Fonts CDN), plus **Bootstrap Icons** throughout the nav and buttons.
- **Navbar**: clean white top nav with pill-style active states and a
  circular avatar-initials chip showing your name and role.
- **Auth screens** (login): split-panel layout with a gradient brand
  panel on the left and the form on the right - collapses to a single card
  on mobile.
- **Status/priority "chips"**: soft-tinted pill badges (not solid Bootstrap
  badges) - since these come from the shared `status_badge()` /
  `priority_badge()` / `escalated_badge()` helper functions in
  `includes/functions.php`, this updates every ticket list and detail page
  at once.
- **Ticket detail page**: redesigned as a clean meta-data grid plus a
  comment/status timeline with per-person avatar initials.
- **Tables**: wrapped in a card container with hover states and horizontal
  scroll on mobile (`table-responsive`).
- **Dashboards**: stat tiles now show an icon plus number, with calm flat
  borders instead of heavy drop shadows.

All of this lives in `assets/css/style.css` (the design tokens are CSS
custom properties at the top of the file, so recoloring the whole app means
changing a handful of `--variable` values) plus updated markup in
`includes/header.php` and every page.

**Note:** Bootstrap, Bootstrap Icons, and the Google Fonts are all loaded
from public CDNs (`cdn.jsdelivr.net`, `fonts.googleapis.com`). This is normal
and will work on both XAMPP and InfinityFree, as long as your browser has
regular internet access (no code changes needed) - if your school's network
somehow blocks specific CDNs, that's the one thing to check.

## 12. Ticket Editing, Pagination/Filters, and Canned Responses

**Edit / Withdraw a ticket** (`employee/edit_ticket.php`)
Employees can edit their own ticket's title, description, priority, category,
and attachment - but only while it's still **Open** (not yet picked up by a
technician). Once assigned or closed, editing locks to avoid changing details
out from under someone already working on it. A **Withdraw** button on the
ticket page lets the submitter cancel their own Open ticket (closes it with a
"withdrawn by submitter" note in the ticket's history).

**Pagination & advanced filters**
`employee/my_tickets.php`, `technician/tickets.php`, and `admin/all_tickets.php`
now page results (15 per page) and support richer filtering:
- Employee: search, status, category, date range
- Technician: status, priority, date range
- Admin: search, status, priority, category, technician, date range

Filters are preserved across pages via the URL query string, and there's a
one-click "clear filters" button. The pagination control itself is a shared
helper (`render_pagination()` in `includes/functions.php`) reused across all
three list pages.

**Canned responses** (`technician/canned_responses.php`)
Technicians and admins can create shared, reusable reply templates. On any
assigned ticket, a "Insert canned response" dropdown appears above the
comment box - selecting one fills the textarea (still editable before
sending). Anyone can create one; a technician can only delete their own,
while admins can delete any (shared library, but with basic accountability).

### If you already have a live database
Run `migration_add_canned_responses.sql` via phpMyAdmin's SQL tab - adds the
`canned_responses` table, no data loss.

## 13. Two-Factor Authentication, Session Timeout, Password Expiry

**Two-Factor Authentication (2FA)** - Admins and Technicians can enable
TOTP-based 2FA (Account Security icon in the navbar → shield icon, or visit
`two_factor_setup.php`). Scan the QR code with any authenticator app (Google
Authenticator, Authy, Microsoft Authenticator, etc.) or enter the setup key
manually. Once enabled, logging in requires the 6-digit code from the app
after the password is verified. This is implemented as a **self-contained
TOTP algorithm (RFC 6238)** in `includes/totp.php` - no external service,
no Composer package. I cross-validated it against Python's independent
`pyotp` library across 25 test cases (5 random secrets × 5 time offsets) and
every single code matched exactly, confirming it's compatible with real
authenticator apps.

Security details:
- 5 wrong codes locks the pending login attempt (must restart from the
  password step)
- ±30 seconds of clock drift tolerance between server and phone
- Disabling 2FA requires re-entering your password
- Every enable/disable/verify/failed attempt is recorded in the Audit Log

**Session auto-logout** - Everyone is automatically logged out after 15
minutes of inactivity (configurable via `SESSION_TIMEOUT_SECONDS` in
`config/security_policy.php`). Checked on every page load; shows a clear
message on the next login attempt.

**Password expiry policy** - Optional, off by default. When enabled
(`PASSWORD_EXPIRY_ENABLED = true` in `config/security_policy.php`), Admin
accounts are forced to set a new password after `PASSWORD_EXPIRY_DAYS`
(default 90) since it was last changed. Applies only to the Admin role, per
typical organizational security policy for privileged accounts. Enabling it
doesn't retroactively punish existing accounts - everyone's clock starts
fresh from whenever the migration/feature is deployed.

### If you already have a live database
Run `migration_add_2fa_password_policy.sql` via phpMyAdmin's SQL tab - adds
the three new columns to `users`, no data loss.

## 14. Reporting: CSV/PDF Export and SLA Compliance Trend

**CSV & PDF export** - From Admin → Analytics or Admin → All Tickets, an
**Export** button lets you download:
- **Ticket list** (PDF or CSV) - respects whatever filters are currently
  applied on the All Tickets page, so you can export exactly the subset you're
  looking at (e.g. "all High-priority tickets closed last month")
- **Analytics report** (PDF or CSV) - KPI summary, status/priority/category
  breakdowns, technician performance, and the weekly SLA compliance trend

The PDF reports are generated with **FPDF**, a self-contained pure-PHP
library (vendored under `lib/FPDF/`, ~120KB, no Composer, no external
binaries) - this matters because shared hosts like InfinityFree can't run
headless-browser PDF tools. The PDF includes simple bar charts drawn with
FPDF's own drawing primitives (no charting library needed) so it reads as a
proper report, not just a data dump - useful for printing or emailing to
someone who wants an official-looking document rather than a login.

**SLA compliance % trend** - A new chart on the Analytics page shows the
percentage of closed tickets each week that never breached their SLA
threshold (weren't auto-escalated), over the last 12 weeks, plus an
all-time headline percentage. This is a genuinely different metric from the
existing "SLA Breaches" counter: breaches show *current* problems, while
compliance % trend shows whether the team is *improving over time* - the
kind of metric that matters for a management or procurement audience.

Every export is logged in the Audit Log (who exported what, and when).

## 15. Knowledge Base / Self-Service

Directly addresses the gap identified in Chapter 2's literature review:
Spiceworks and the free tiers of most helpdesk tools offer little self-service,
meaning every issue - even trivial ones - becomes a ticket.

**For employees** (`knowledge_base.php`): search or browse articles by
category before filing a ticket. Each article shows view count and a
"was this helpful?" score. If an article doesn't solve the problem, a
**"This didn't help — submit a ticket"** button on the article takes them
straight to the submit-ticket form with the **category and title pre-filled**
from the article context - so self-service and ticket-filing are one
connected flow, not two disconnected features.

**For technicians/admins** (`technician/kb_manage.php`): publish and edit
articles. Any technician can edit any article (collaborative documentation),
but deletion is restricted to the article's author or an Admin - the same
accountability model already used for Canned Responses.

Ships with **5 sample articles** covering common issues (Wi-Fi, printers,
password resets, software installs, mobile email setup) so the feature has
real content out of the box rather than an empty page.

Every publish/edit/delete is recorded in the Audit Log.

### If you already have a live database
Run `migration_add_knowledge_base.sql` via phpMyAdmin's SQL tab - creates
the `kb_articles` table and seeds the 5 sample articles (attributed
automatically to your existing Admin account), no data loss.

## 16. UI Polish: Sidebar, Notifications, Dark Mode, Attachment Previews

**Sidebar navigation** - The top navbar has been replaced with a persistent
left sidebar across every authenticated page (all three roles). Each role
sees only its own menu, with the current page highlighted. On screens under
992px the sidebar collapses behind a hamburger button with a tap-to-close
overlay, so it stays usable on phones. Login/reset pages keep the
clean centered layout with no sidebar.

**In-app notification bell** - A bell icon in the top bar shows an unread
count badge and a dropdown of recent activity. Crucially, this works
**entirely independent of email** - the notification functions now always
write an in-app record and only *additionally* send email when
`MAIL_ENABLED` is true. So the system gives real feedback even though SMTP
isn't configured on your InfinityFree install. Notifications fire on: new
ticket submitted (→ admins), ticket assigned (→ employee + technician), new
comment, status change/close, and SLA auto-escalation. Whoever performed an
action never gets notified about their own action. Visiting
`notifications.php` marks everything read and clears the badge.

**Dark mode** - A moon/sun toggle in the top bar switches themes instantly.
The choice is saved in `localStorage` and applied *before first paint*, so
there's no white flash when reloading in dark mode.

**Inline attachment previews** - Ticket attachments now render in place
rather than as a bare link: images show a thumbnail (click to enlarge), PDFs
embed in a viewer, `.txt` files show their contents in a scrollable block,
and anything else (DOC/DOCX) falls back to a labelled file card.

### If you already have a live database
Run `migration_add_notifications.sql` via phpMyAdmin's SQL tab - creates the
`notifications` table, no data loss.

## 17. Automated Test Suite

60 automated tests live in `tests/`. Run them from the project root with:

```
php tests/run_tests.php
```

Zero dependencies — no Composer or PHPUnit needed, so it works on a plain
XAMPP install. They build a separate disposable `helpdeskpro_test` database
and **never touch your real data** (two guards refuse to run against a
non-`_test` database or a remote host). See `tests/README.md` for full
details and the coverage table.

Covers: password hashing, login rate limiting, 2FA/TOTP, password reset
tokens, ticket lifecycle and schema defaults, SLA escalation thresholds,
compliance percentage math, role-based data access, notifications, the
knowledge base, the audit trail, and pagination/filtering.

**Worth mentioning in your report:** the suite was *mutation-tested* —
deliberately broken versions of the app were run against it to confirm the
tests actually fail when something regresses. That process found a genuine
flaw in one test (it asserted a schema default while inserting an explicit
value, so it could never have caught a wrong default) which was then fixed.
A test suite is only meaningful if you've verified it can fail.

## 18. Departments (Multi-Team Support)

One installation can now serve several support teams - IT Support,
Facilities, HR, and any others you create - instead of one flat
organization-wide queue.

**How it works**
- **Admin → Departments** creates and edits teams, showing each one's
  technician count, ticket count and active workload at a glance.
- Each **technician belongs to a department**; **admins are global** and see
  everything.
- When an **employee submits a ticket** they choose which team should handle
  it, so it lands in the right queue from the start.
- The **assign page only offers technicians from the ticket's own
  department**, and the server re-checks this on submit - a tampered form
  can't route an HR ticket to an IT technician.
- **All Tickets** gains a department filter (including "Unrouted"), and
  **Analytics** gains a per-department workload table showing total, active,
  closed, SLA-breached and average resolution time per team.

**Nothing is ever orphaned:** deleting a department leaves its tickets and
technicians intact and simply marks them unrouted, so they can be reassigned
rather than lost.

### If you already have a live database
Run `migration_add_departments.sql` via phpMyAdmin's SQL tab. It creates the
table, seeds three default departments, and **backfills every existing ticket
and technician into "IT Support"** so nothing is left unrouted - the system
behaves exactly as before until you start using the other teams.

## 19. Business-Hours SLA (Maldives Government Hours)

SLA clocks now run **only during working hours**, so a ticket filed at 1pm
Thursday doesn't burn its entire budget over the weekend.

Configured in `config/business_hours.php` to standard **Maldives government
office hours**:
- **Working week:** Sunday - Thursday (Friday & Saturday are the weekend)
- **Hours:** 08:00 - 14:00 (a 6-hour working day)
- **Timezone:** Indian/Maldives
- **Public holidays:** fixed-date national holidays are pre-loaded. Islamic
  holidays move each year against the Gregorian calendar and are announced by
  the President's Office, so add those manually each year.

Each ticket stores a real `sla_due_at` deadline calculated at submission, so
breach detection is a simple, fast comparison rather than repeated maths. The
deadline is recalculated if the priority changes. The ticket page shows the
due date, the office-hours schedule it was based on, and an **Overdue** flag.

**Worked example:** a High-priority ticket (24 working hours) filed Thursday
13:00 is due the **following Wednesday 13:00** - 1h Thursday, then 6h each on
Sunday, Monday, Tuesday, and 5h Wednesday. A naive calendar clock would have
called it overdue on Friday, during the weekend.

## 20. Detailed Audit Trail

The audit log records **every action**, at the level of detail an external
auditor expects:

- **Who** - name and role *as held at the time*, denormalised so the trail
  stays readable even after the account is deleted
- **What** - the action, plus which record type and ID it touched
- **Before / after** - the previous and new values for every change
- **Where** - IP address and browser user-agent
- **When** - indexed timestamp

**Events captured:** logins (success/failure), logout, session events,
registration, 2FA enable/disable/verify/fail, password resets and forced
expiry, ticket created/viewed/edited/assigned/commented/status-changed/
closed/withdrawn, SLA auto-escalation, user create/update/role-change/delete,
department create/update/delete, KB and canned-response changes, report
exports - **and security events**: blocked access attempts, rejected CSRF
tokens, and blocked cross-department assignments.

The log page supports filtering by action, user, record type, date range and
free-text search, and exports to CSV with a provenance header (who exported,
when, and which filters were applied). Exporting is itself an audited event.

Auditing is written defensively: if a log write ever fails, the error is
recorded to the PHP error log and the user's action still completes - the
audit system can never take the application down.

### If you already have a live database
Run `migration_audit_and_business_hours.sql` via phpMyAdmin's SQL tab. It adds
the new columns, backfills actor names on existing audit rows, and gives every
existing ticket a deadline - no data loss.

## 21. Backup, Notification Controls, and Help Guide

**Database backup** (Admin → Backup). One button downloads a complete `.sql`
copy of every table. InfinityFree doesn't allow the `mysqldump` command, so
the dump is generated in pure PHP (`includes/backup.php`) and **streamed**
straight to the browser rather than built up in memory, so a large database
can't exhaust PHP's memory limit.

It was verified by round trip: back up, restore into an empty database, and
compare every table with MySQL's `CHECKSUM TABLE` - all tables came back
byte-identical, including quotes, backslashes, newlines, NULLs, Dhivehi text,
and a ticket titled `DROP TABLE users;` (which stayed as text). This is now
a permanent test, so a future change can't silently break restores.

The page warns if no backup has ever been taken, or if the last one is more
than 7 days old. Downloads are POST-only with a CSRF token, admin-only, and
recorded in the audit log - a full copy of everyone's data leaving the system
is exactly what an auditor wants to see. The file header warns that it
contains password hashes and 2FA secrets.

**Notifications.** Viewing the notifications page no longer silently marks
everything read. Instead there's **Mark as read** on each notification,
**Mark all as read** on the page and in the bell dropdown, and an All/Unread
filter. Clicking a notification marks just that one read and opens its ticket.
The ownership check is built into the SQL (`WHERE id = ? AND user_id = ?`), so
nobody can mark or open another user's notification by guessing its id.

**Help & Guide** (sidebar, and a "How it works" link on the sign-in page).
Role-aware quick starts for employees, technicians and admins; how a ticket
moves; priorities and response times; notifications; account security; and
an FAQ. Works signed out too. **The numbers in it - SLA hours, office hours,
session timeout - are read from the live configuration**, so the guide can't
drift out of date when a setting changes. New employees with no tickets yet
see a welcome card on their dashboard pointing to it.

**A bug found along the way:** `includes/header.php` assigned to `$user`,
silently overwriting any page's own `$user` variable. It was harmless on
every existing page (they all set the same value), but it made the help page
treat signed-out visitors as signed in. The header now uses its own
`$nav_user`, so no page can be affected by this again.

## 22. Account Provisioning & Password Visibility

**No public registration.** The site is publicly reachable, and a sign-up page
had no way to tell whether someone actually worked at the organisation - anyone
who found it could create an account, submit tickets, and read the knowledge
base. `register.php` has been removed and the sign-in page now says to ask an
administrator. This matches how internal ITSM tools work: administrators know
who is staff and can place technicians in the right department from the start,
and every account creation is already in the audit log.

**Forced password change on first sign-in.** When an administrator creates an
account, the administrator chooses - and therefore knows - the password. So new
accounts are flagged (`users.must_change_password`), and on first sign-in the
user must choose their own before they can reach any page. The same happens if
an administrator resets someone else's password. It does **not** happen when an
admin changes their *own* password, or when a user resets their own via the
emailed link, since in both cases only that person knows it. Re-using the
temporary password is rejected. This applies to every role, and works through
two-factor sign-in too. The seeded demo accounts are deliberately not flagged,
so they still sign in directly.

**Show/hide password (eye).** Every password field in the app gets an eye
button, added once in the shared footer so future fields get it automatically.
It is a `type="button"` (so it can never submit the form by accident), is
keyboard- and screen-reader-accessible ("Show password" / "Hide password",
with `aria-pressed`), and flips the field back to hidden on submit so browsers
never store the password as plain text in form history. The icon is inline SVG,
so it doesn't depend on the icon CDN loading.

### If you already have a live database
Run `migration_first_login_password.sql` via phpMyAdmin. Existing accounts are
not affected. **Don't** run it if you restore from the new
`RESTORE_database_infinityfree.sql`, which already includes the column.

## 23. Staff ID

Every account carries a **Staff ID**, issued automatically from the person's
role and numbered in order:

| Role | Pattern | Example |
|---|---|---|
| Admin | `ADM-nnnn` | ADM-0001, ADM-0002 … |
| Technician | `TEC-nnnn` | TEC-0001, TEC-0002 … |
| Employee | `EMP-nnnn` | EMP-0001, EMP-0002 … |

The administrator doesn't type it: the create-user form shows the ID that
will be issued and updates as the role is changed. In a real deployment HR
would allocate staff numbers; generating them keeps account creation to one
step while still producing a readable, ordered identifier.

**Why it matters.** For a government office the staff number is the
identifier that carries administratively: it links a ticket to the person's
HR record, distinguishes staff who share a name, and lets an auditor
cross-reference against the official staff register. It appears in Manage
Users, on the ticket detail page, and in the ticket and audit CSV exports.

**Signing in.** The sign-in box accepts **either** an email address or a
Staff ID. IDs are matched case-insensitively.

**IDs are permanent.** Once issued, a Staff ID never changes - not even if
the person's role changes - because past tickets and audit entries record it.
It is read-only when editing an account.

**Retired IDs are never reused.** A `staff_id_sequence` table remembers the
highest number issued per role, and only ever moves forward. This was found
by a test: the first version took the highest number currently in the users
table, so deleting the newest account would hand its ID to the next person -
making old audit entries ambiguous about who they referred to. Deleting every
employee now still issues the next number in sequence, never EMP-0001 again.

**A security detail worth explaining in your report.** Failed sign-ins were
counted per email address. Once a second identifier exists that becomes a
hole: someone could alternate between an account's email and its Staff ID and
get five attempts on each, never triggering the lockout. Sign-in now resolves
whatever was typed to the **account** first and counts attempts against that,
with unknown identifiers still rate limited on what was typed. A test
alternates the two identifiers and asserts the account still locks.

### If you already have a live database
Run `migration_add_staff_id.sql` via phpMyAdmin. Because a required, unique
column can't be added to a table that already has rows, it works in four
steps: add the column as optional, number each role's accounts in order
(oldest first), make the column required and unique, then set the ID counters
to match.

**If part of an upgrade didn't apply:** phpMyAdmin stops at the first
statement that fails, so a partly-applied migration can leave the
`staff_id_sequence` table missing. Run `FIX_staff_id_sequence.sql`, which is
safe to run at any time. The application also repairs this itself on first
use, seeding the counters from the highest ID already issued, so a partial
upgrade can't take Manage Users offline.

## 24. Notes for your report
- For screenshots, this matches your Chapter 6 wireframes (login page,
  dashboard, submit ticket, ticket details) almost exactly — good for a
  "design vs. implementation" comparison section.
- The email feature maps to your Chapter 1 scope item "Notification system
  for ticket updates" and Chapter 4 functional requirement "Notifications for
  status changes."
