<?php
/**
 * SLA Auto-Escalation
 * ---------------------------------------------------------
 * Any active (non-Closed) ticket that has been open longer than its
 * priority's SLA threshold gets automatically escalated:
 *   - Low    -> bumped to Medium
 *   - Medium -> bumped to High
 *   - High   -> stays High, but flagged so it's visibly marked as breached
 * Each ticket is only escalated once (the `escalated` flag prevents repeats).
 *
 * This file is included by both:
 *   - admin/run_escalation.php   (manual "Run Now" button, session-authenticated)
 *   - cron/escalate_tickets.php  (external cron trigger, secret-key authenticated)
 */

function get_sla_hours() {
    // Tune these to match whatever service levels your organization commits to.
    return ['High' => 24, 'Medium' => 72, 'Low' => 120];
}

/**
 * Find and escalate all SLA-breaching tickets.
 * @return array List of ['ticket_id', 'title', 'old_priority', 'new_priority']
 */
function run_sla_escalation($pdo) {
    $sla = get_sla_hours();
    $sla_case = "CASE priority " .
        "WHEN 'High' THEN {$sla['High']} " .
        "WHEN 'Medium' THEN {$sla['Medium']} " .
        "ELSE {$sla['Low']} END";

    $stmt = $pdo->query("
        SELECT * FROM tickets
        WHERE status != 'Closed'
        AND escalated = 0
        AND sla_due_at IS NOT NULL
        AND sla_due_at < NOW()
    ");
    $breaching = $stmt->fetchAll();

    if (empty($breaching)) {
        return [];
    }

    // Attribute the automated log entry to the first admin account found.
    $system_admin_id = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();

    $escalated = [];
    $next_priority = ['Low' => 'Medium', 'Medium' => 'High', 'High' => 'High'];

    foreach ($breaching as $ticket) {
        $old_priority = $ticket['priority'];
        $new_priority = $next_priority[$old_priority];
        $hours_open = floor((time() - strtotime($ticket['created_at'])) / 3600);

        $upd = $pdo->prepare("UPDATE tickets SET priority = ?, escalated = 1 WHERE ticket_id = ?");
        $upd->execute([$new_priority, $ticket['ticket_id']]);

        audit_log($pdo, $system_admin_id, 'ticket_escalated',
            "SLA breach: ticket #{$ticket['ticket_id']} escalated after {$hours_open}h",
            'ticket', $ticket['ticket_id'],
            ['priority' => $old_priority, 'escalated' => 0, 'sla_due_at' => $ticket['sla_due_at']],
            ['priority' => $new_priority, 'escalated' => 1]);

        $message = ($old_priority === $new_priority)
            ? "SLA breach: ticket has been open {$hours_open}h, past its {$old_priority} priority threshold. Flagged for urgent attention."
            : "SLA breach: priority automatically escalated from {$old_priority} to {$new_priority} after {$hours_open}h without resolution.";

        if ($system_admin_id) {
            log_ticket_action($pdo, $ticket['ticket_id'], $system_admin_id, $new_priority, $message);
        }

        notify_ticket_stakeholders($pdo, $ticket['ticket_id'], 'SLA Breach - Ticket Escalated', $message, null);
        notify_admins_escalation($pdo, $ticket['ticket_id'], $old_priority, $new_priority, $hours_open);

        $escalated[] = [
            'ticket_id' => $ticket['ticket_id'],
            'title' => $ticket['title'],
            'old_priority' => $old_priority,
            'new_priority' => $new_priority,
        ];
    }

    return $escalated;
}
