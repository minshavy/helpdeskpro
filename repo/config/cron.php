<?php
// ---------------------------------------------------------
// Cron / SLA Escalation Configuration
// ---------------------------------------------------------
// This secret key protects the public cron endpoint (cron/escalate_tickets.php)
// from being triggered by random visitors. CHANGE THIS to your own random
// string before deploying - anyone who knows this key can trigger the
// escalation check (harmless, but still worth keeping private).
//
// Generate a random one at https://randomkeygen.com/ or just mash your keyboard.

define('CRON_SECRET_KEY', 'change-this-to-a-random-secret-abc123xyz');
