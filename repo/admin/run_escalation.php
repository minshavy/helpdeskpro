<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
csrf_verify();
require_once __DIR__ . '/../includes/escalation.php';

$results = run_sla_escalation($pdo);

if (empty($results)) {
    flash('success', 'SLA check complete — no tickets needed escalation.');
} else {
    $lines = array_map(function ($r) {
        return "#{$r['ticket_id']} ({$r['old_priority']} \u{2192} {$r['new_priority']})";
    }, $results);
    flash('success', 'Escalated ' . count($results) . ' ticket(s): ' . implode(', ', $lines));
}

header('Location: analytics.php');
exit;
