# HelpDeskPro — Automated Test Suite

108 automated tests covering the logic most likely to break silently:
authentication, rate limiting, 2FA, password resets, role permissions, the
ticket lifecycle, SLA escalation, compliance reporting, notifications, the
knowledge base, the audit trail, and pagination/filtering.

Deliberately **zero-dependency** — no Composer, no PHPUnit. It runs on a
plain XAMPP install with nothing extra to set up.

## Running the tests

From the project root:

```
php tests/run_tests.php
```

On Windows/XAMPP, if `php` isn't on your PATH:

```
C:\xampp\php\php.exe tests\run_tests.php
```

You'll get a pass/fail line per test and a summary. The script exits with
code `0` if everything passes and `1` if anything fails, so it also works in
a CI pipeline if you ever add one.

## Safety

**The tests never touch your real data.** They build a separate, disposable
database called `helpdeskpro_test`, rebuilt from `database.sql` before each
test that needs a clean slate.

Two guards enforce this — the suite refuses to run if the target database
name doesn't contain `_test`, or if the host looks like a live/remote server.
Run these **locally only**, never against your InfinityFree database.

If your local MySQL uses different credentials, either edit the constants at
the top of `tests/bootstrap.php` or pass them as environment variables:

```
HDP_TEST_USER=root HDP_TEST_PASS=secret php tests/run_tests.php
```

## What's covered

| Group | Examples |
|---|---|
| Password hashing | bcrypt verifies correct / rejects wrong; seeded demo accounts work |
| Login rate limiting | locks at 5 failures; stale attempts age out of the 15-min window |
| 2FA (TOTP) | code generation, verification, ±1 step clock drift, malformed input rejected |
| Password resets | tokens stored hashed, expiry enforced, single-use |
| Ticket lifecycle | schema defaults, assignment, activity log, FK cascade |
| SLA escalation | per-priority thresholds, no double-escalation, closed tickets skipped |
| SLA compliance | percentage math with and without breaches |
| Permissions | employees see only their tickets; technicians only their assignments |
| Notifications | created unread, per-user counts, mark-all-read |
| Knowledge Base | seeding, search hits/misses, category filter, helpful votes |
| Audit log | anonymous failed logins, history survives user deletion |
| Business hours | workdays, holidays, weekend-skipping SLA deadlines, deadline always in office hours |
| Audit trail | actor identity, before/after state, survives user deletion, fails safely |
| Staff ID & sign-in | auto-issued in order per role, never reused after deletion, unique IDs, sign in by email or staff ID, lockout cannot be dodged by alternating identifiers |
| Backup | covers every table, dependency order, byte-identical round-trip restore, re-runnable |
| Notification state | mark one / mark all, cannot touch another user's notifications |
| Departments | routing, unique names, department-scoped technician eligibility, safe deletion |
| Pagination | LIMIT/OFFSET correctness, page-count math, cumulative filters |

## A note on test quality

These tests were **mutation-tested** — deliberately broken versions of the
app were run against the suite to confirm the tests actually fail when
something regresses, rather than passing vacuously.

That process caught a real weakness during development: the "new ticket
defaults to Open" test originally inserted an explicit status, so it would
still have passed even if the schema's `DEFAULT` were wrong. It now inserts
only the required columns so the database defaults are genuinely exercised.
Worth knowing: a green test suite only means something if you've checked the
tests can go red.
