<?php
/**
 * SLA Escalation Cron Endpoint
 * ---------------------------------------------------------
 * On free hosts like InfinityFree that don't offer real cron jobs, point a
 * free external scheduler (e.g. https://cron-job.org) at this URL every
 * 30-60 minutes:
 *
 *   https://yourdomain.com/cron/escalate_tickets.php?key=YOUR_SECRET_KEY
 *
 * Replace YOUR_SECRET_KEY with the value of CRON_SECRET_KEY in config/cron.php.
 *
 * On XAMPP/local/your own server with real cron access, you can instead call
 * this from a system cron job / Windows Task Scheduler, e.g.:
 *   php /path/to/helpdeskpro/cron/escalate_tickets.php --key=YOUR_SECRET_KEY
 */

require_once __DIR__ . '/../config/cron.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/escalation.php';

header('Content-Type: text/plain');

// Accept the key from either a query string (web/HTTP cron) or a CLI flag (system cron)
$provided_key = $_GET['key'] ?? '';
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, '--key=') === 0) {
            $provided_key = substr($arg, 6);
        }
    }
}

if (!hash_equals(CRON_SECRET_KEY, $provided_key)) {
    http_response_code(403);
    echo "Forbidden: invalid or missing key.\n";
    exit;
}

$results = run_sla_escalation($pdo);

echo "[" . date('Y-m-d H:i:s') . "] SLA escalation check complete. " . count($results) . " ticket(s) escalated.\n";
foreach ($results as $r) {
    echo "  - Ticket #{$r['ticket_id']} \"{$r['title']}\": {$r['old_priority']} -> {$r['new_priority']}\n";
}
