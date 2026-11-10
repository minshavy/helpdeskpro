<?php
/**
 * Help & Guide
 * ---------------------------------------------------------
 * Works signed in or out. Signed-in users see the quick start for their own
 * role first; admins can read every role's guide.
 *
 * Numbers shown here (SLA hours, office hours, session timeout) are read
 * from the live configuration rather than typed in, so the guide stays
 * accurate automatically when a setting changes.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/escalation.php';

$user = is_logged_in() ? current_user() : null;
$role = $user['role'] ?? null;

$sla        = get_sla_hours();
$schedule   = BusinessHours::scheduleSummary();
$hours_day  = BusinessHours::secondsPerDay() / 3600;
$timeout_m  = (int) round(SESSION_TIMEOUT_SECONDS / 60);

// Show the guides relevant to this person
$show = [
    'employee'   => !$role || $role === 'employee'   || $role === 'admin',
    'technician' => !$role || $role === 'technician' || $role === 'admin',
    'admin'      => $role === 'admin',
];

$page_title = 'Help & Guide';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Help &amp; guide</h1>
    <p class="text-muted mb-0">
        <?php if ($role): ?>
            Everything you need to use HelpDeskPro as <?= $role === 'admin' ? 'an' : 'a' ?> <strong><?= e(ucfirst($role)) ?></strong>.
        <?php else: ?>
            How HelpDeskPro works, and how to get help with IT and workplace issues.
        <?php endif; ?>
    </p>
</div>

<div class="row g-4">
    <!-- Contents -->
    <div class="col-12 col-lg-3">
        <div class="card help-toc">
            <div class="card-body p-3">
                <div class="text-muted fs-8 fw-semibold text-uppercase mb-2 px-2">On this page</div>
                <nav class="nav flex-column fs-7">
                    <a class="nav-link" href="#start">Getting started</a>
                    <a class="nav-link" href="#lifecycle">How a ticket moves</a>
                    <a class="nav-link" href="#priority">Priority &amp; response times</a>
                    <a class="nav-link" href="#notifications">Notifications</a>
                    <a class="nav-link" href="#security">Account security</a>
                    <a class="nav-link" href="#faq">Common questions</a>
                </nav>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-9">

        <!-- Getting started -->
        <section id="start" class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-rocket-takeoff text-primary me-2"></i>Getting started</h2>

                <?php if ($show['employee']): ?>
                <div class="help-role mb-4">
                    <h3 class="h6 fw-bold mb-2"><?= $role === 'admin' ? 'For employees' : ($role ? 'Your quick start' : 'Reporting a problem') ?></h3>
                    <ol class="help-steps">
                        <li><strong>Check the Knowledge Base first.</strong> Many common problems &mdash; Wi-Fi, printers, password resets &mdash; have step-by-step fixes that take a minute and save you waiting.</li>
                        <li><strong>Submit a ticket</strong> if it's still not solved. Pick the team that should handle it, give it a clear title, and describe what happened, when it started, and what you've already tried.</li>
                        <li><strong>Attach a screenshot</strong> of any error message. It's often the fastest way for a technician to understand the problem.</li>
                        <li><strong>Follow progress</strong> under <em>My Tickets</em>. You'll get a notification whenever someone replies or the status changes.</li>
                    </ol>
                    <p class="text-muted fs-7 mb-0">You can edit or withdraw a ticket while it's still <em>Open</em>. Once a technician picks it up, add a comment instead.</p>
                </div>
                <?php endif; ?>

                <?php if ($show['technician']): ?>
                <div class="help-role mb-4">
                    <h3 class="h6 fw-bold mb-2"><?= $role === 'technician' ? 'Your quick start' : 'For technicians' ?></h3>
                    <ol class="help-steps">
                        <li><strong>Work from <em>Assigned Tickets</em>.</strong> It lists everything routed to you; your dashboard shows active work highest priority first.</li>
                        <li><strong>Keep the requester informed.</strong> Comment when you start, when you learn something, and when you're done &mdash; each comment notifies them.</li>
                        <li><strong>Use canned responses</strong> for replies you send often. Pick one from the dropdown under the comment box, then edit it before posting.</li>
                        <li><strong>Set the status honestly.</strong> Move to <em>In Progress</em> while you work and <em>Closed</em> only when it's actually resolved.</li>
                        <li><strong>Write it up</strong> in the Knowledge Base when you fix something likely to happen again.</li>
                    </ol>
                </div>
                <?php endif; ?>

                <?php if ($show['admin']): ?>
                <div class="help-role">
                    <h3 class="h6 fw-bold mb-2">For administrators</h3>
                    <ol class="help-steps">
                        <li><strong>Create accounts</strong> in <em>Manage Users</em> &mdash; there is no public sign-up. Give each person their Staff ID and a temporary password; they'll be made to replace the password on first sign-in.</li>
                        <li><strong>Assign new tickets</strong> from <em>Assign Tickets</em>. Only technicians from the ticket's own department are offered.</li>
                        <li><strong>Set up teams</strong> under <em>Departments</em>, then give each technician a department in <em>Manage Users</em>. A department with no technicians can't have its tickets assigned.</li>
                        <li><strong>Watch SLA health</strong> on <em>Analytics</em>, and use <em>Run SLA check now</em> to escalate overdue tickets immediately.</li>
                        <li><strong>Take regular backups</strong> from <em>Backup</em>. Your hosting provider does not keep copies &mdash; a backup is the only way to recover deleted data.</li>
                        <li><strong>Review the Audit Log</strong> for sign-ins, blocked access attempts and every change to tickets and accounts. It can be filtered and exported to CSV.</li>
                    </ol>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Lifecycle -->
        <section id="lifecycle" class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-arrow-repeat text-primary me-2"></i>How a ticket moves</h2>
                <div class="help-flow mb-3">
                    <div class="help-flow-step">
                        <span class="badge badge-open mb-2">Open</span>
                        <p class="fs-7 mb-0">Submitted and waiting to be assigned to a technician.</p>
                    </div>
                    <div class="help-flow-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></div>
                    <div class="help-flow-step">
                        <span class="badge badge-progress mb-2">In Progress</span>
                        <p class="fs-7 mb-0">A technician has it and is working on it.</p>
                    </div>
                    <div class="help-flow-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></div>
                    <div class="help-flow-step">
                        <span class="badge badge-closed mb-2">Closed</span>
                        <p class="fs-7 mb-0">Resolved, or withdrawn by the person who raised it.</p>
                    </div>
                </div>
                <p class="fs-7 mb-0">
                    A ticket marked <span class="badge badge-escalated">Escalated</span> went past its response time
                    without being resolved, so it was automatically raised in priority and flagged to administrators.
                </p>
            </div>
        </section>

        <!-- Priority & SLA -->
        <section id="priority" class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-clock-history text-primary me-2"></i>Priority &amp; response times</h2>
                <p class="fs-7">Choose the priority that matches the real impact &mdash; not how frustrating it feels. Marking everything High means genuinely urgent problems wait longer.</p>

                <div class="table-responsive mb-3">
                    <table class="table align-middle mb-0 fs-7">
                        <thead class="table-light fs-8 text-uppercase">
                            <tr><th>Priority</th><th>Use it when&hellip;</th><th class="text-end">Target</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?= priority_badge('High') ?></td>
                                <td>You or your team can't work at all, or many people are affected.</td>
                                <td class="text-end fw-semibold text-nowrap"><?= (int) $sla['High'] ?> working hrs</td>
                            </tr>
                            <tr>
                                <td><?= priority_badge('Medium') ?></td>
                                <td>Something is broken but there's a workaround.</td>
                                <td class="text-end fw-semibold text-nowrap"><?= (int) $sla['Medium'] ?> working hrs</td>
                            </tr>
                            <tr>
                                <td><?= priority_badge('Low') ?></td>
                                <td>A request, question, or minor annoyance.</td>
                                <td class="text-end fw-semibold text-nowrap"><?= (int) $sla['Low'] ?> working hrs</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-info fs-7 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>These are working hours, not calendar hours.</strong> The clock only runs during office hours
                    (<?= e($schedule) ?>, <?= $hours_day ?> hours a day) and pauses on weekends and public holidays.
                    So a High ticket raised on a Thursday afternoon is not overdue by Friday &mdash; it carries over to Sunday.
                    Each ticket shows its exact deadline.
                </div>
            </div>
        </section>

        <!-- Notifications -->
        <section id="notifications" class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-bell text-primary me-2"></i>Notifications</h2>
                <p class="fs-7 mb-2">The bell at the top of every page shows how many updates you haven't read yet. You're notified when:</p>
                <ul class="fs-7 mb-3">
                    <li>someone comments on your ticket</li>
                    <li>a ticket's status changes, or it's assigned</li>
                    <li><?= $role === 'admin' ? 'a new ticket arrives, or one breaches its response time' : 'a ticket is closed' ?></li>
                </ul>
                <p class="fs-7 mb-0">
                    Clicking a notification opens the ticket and marks it read. To clear them without opening each one,
                    use <strong>Mark as read</strong> on a single notification or <strong>Mark all as read</strong>.
                </p>
            </div>
        </section>

        <!-- Security -->
        <section id="security" class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-shield-lock text-primary me-2"></i>Account security</h2>
                <ul class="fs-7 mb-0">
                    <li class="mb-2"><strong>You can sign in with either your email address or your Staff ID</strong> &mdash; whichever you find easier to remember.</li>
                    <li class="mb-2"><strong>Your account is created by an administrator</strong>, who gives you a temporary password. The first time you sign in you'll be asked to choose your own, so nobody else knows it.</li>
                    <li class="mb-2"><strong>Passwords</strong> must be at least 8 characters. Use the eye icon in any password box to check what you've typed. Forgot yours? Use <em>Forgot password?</em> on the sign-in page &mdash; the reset link expires after 30 minutes.</li>
                    <li class="mb-2"><strong>Too many wrong passwords</strong> (5 in a row) locks the account for 15 minutes to stop guessing attacks.</li>
                    <li class="mb-2"><strong>You're signed out automatically</strong> after <?= $timeout_m ?> minutes without activity, so an unattended screen isn't left open.</li>
                    <li><strong>Technicians and admins</strong> can turn on two-step verification under <em>Security (2FA)</em>, using an app such as Google Authenticator or Microsoft Authenticator. Strongly recommended for admin accounts.</li>
                </ul>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-question-circle text-primary me-2"></i>Common questions</h2>
                <div class="accordion" id="faqAccordion">

                    <?php
                    $faqs = [
                        ['How do I get an account?',
                         'Ask your administrator. There\'s no public sign-up page &mdash; accounts are created by administrators so that only staff can use the system. You\'ll receive a Staff ID and a temporary password, and be asked to choose your own password the first time you sign in.'],
                        ['Nobody has replied to my ticket. What should I do?',
                         'Check its status first. Open means it hasn\'t been assigned yet; In Progress means a technician has it. Add a comment with any new detail rather than submitting a second ticket &mdash; duplicates split the conversation and slow things down. If it\'s past its deadline it will be escalated automatically.'],
                        ['I picked the wrong team or priority.',
                         'While the ticket is still Open you can fix it yourself with Edit on the ticket page. After a technician has picked it up, add a comment explaining the change and they or an admin will adjust it.'],
                        ['Why can\'t I edit my ticket any more?',
                         'Editing locks once a technician starts work, so the details can\'t change underneath them. You can still add comments at any time.'],
                        ['What does "Withdraw" do?',
                         'It closes your ticket without a technician resolving it &mdash; for example if the problem fixed itself or you raised it by mistake. It\'s only available while the ticket is Open.'],
                        ['Can other people see my tickets?',
                         'Only you, the technician assigned to it, and administrators. Other employees cannot see your tickets.'],
                        ['What file types can I attach?',
                         'JPG, PNG, GIF, PDF, TXT, DOC and DOCX, up to 5 MB. Screenshots of error messages are the most useful thing you can attach.'],
                    ];
                    foreach ($faqs as $i => [$q, $a]): ?>
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed fs-7 fw-semibold" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"
                                        aria-expanded="false" aria-controls="faq<?= $i ?>">
                                    <?= $q ?>
                                </button>
                            </h3>
                            <div id="faq<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body fs-7"><?= $a ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </section>

        <!-- Still stuck -->
        <section class="card help-cta">
            <div class="card-body p-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h2 class="h6 fw-bold mb-1">Still stuck?</h2>
                    <p class="fs-7 text-muted mb-0">Search the Knowledge Base, or raise a ticket and the right team will pick it up.</p>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <?php if ($user): ?>
                        <a href="<?= base_url('knowledge_base.php') ?>" class="btn btn-outline-secondary">Knowledge Base</a>
                        <?php if ($role === 'employee'): ?>
                            <a href="<?= base_url('employee/submit_ticket.php') ?>" class="btn btn-primary">Submit a ticket</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?= base_url('login.php') ?>" class="btn btn-primary">Sign in</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
