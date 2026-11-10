<?php
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/notifications.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send an email notification. Fails silently (logs to error_log) so that
 * a mail server outage never blocks ticket actions like submit/assign/close.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $bodyHtml
 * @return bool true on success, false on failure or if mail is disabled
 */
function send_notification_email($toEmail, $toName, $subject, $bodyHtml) {
    if (!MAIL_ENABLED) {
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $bodyHtml;
        $mail->AltBody = strip_tags($bodyHtml);

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('HelpDeskPro mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Notify the relevant people about a ticket update: the employee who
 * submitted it and (if assigned) the technician working on it.
 * Skips whoever triggered the action so people aren't emailed about their own updates.
 *
 * @param PDO $pdo
 * @param int $ticket_id
 * @param string $heading   Short heading, e.g. "Ticket Assigned"
 * @param string $message   Plain text/HTML detail line
 * @param int|null $actor_user_id  user_id of whoever performed the action (excluded from notification)
 */
function notify_ticket_stakeholders($pdo, $ticket_id, $heading, $message, $actor_user_id = null) {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               u.name AS submitter_name, u.email AS submitter_email, u.user_id AS submitter_id,
               tech.name AS technician_name, tech.email AS technician_email, tech.user_id AS technician_id
        FROM tickets t
        JOIN users u ON t.user_id = u.user_id
        LEFT JOIN users tech ON t.assigned_to = tech.user_id
        WHERE t.ticket_id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        return;
    }

    $subject = "[HelpDeskPro] $heading - Ticket #{$ticket['ticket_id']}";
    $body = ticket_email_body($heading, $ticket, $message);
    $plain_message = "$heading: " . strip_tags($message);

    $recipients = [];
    $recipients[] = ['id' => $ticket['submitter_id'], 'name' => $ticket['submitter_name'], 'email' => $ticket['submitter_email']];
    if ($ticket['technician_id']) {
        $recipients[] = ['id' => $ticket['technician_id'], 'name' => $ticket['technician_name'], 'email' => $ticket['technician_email']];
    }

    foreach ($recipients as $r) {
        if ($actor_user_id !== null && (int) $r['id'] === (int) $actor_user_id) {
            continue; // don't notify the person who made the change
        }
        // In-app notification always fires - works even without email configured.
        create_notification($pdo, $r['id'], $ticket_id, $plain_message);
        if (MAIL_ENABLED) {
            send_notification_email($r['email'], $r['name'], $subject, $body);
        }
    }
}

/**
 * Notify all admins - used when a brand-new ticket is submitted and needs assignment.
 */
function notify_admins_new_ticket($pdo, $ticket_id) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name AS submitter_name FROM tickets t
        JOIN users u ON t.user_id = u.user_id
        WHERE t.ticket_id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        return;
    }

    $subject = "[HelpDeskPro] New Ticket Submitted - Ticket #{$ticket['ticket_id']}";
    $body = ticket_email_body(
        'New Ticket Needs Assignment',
        $ticket,
        'Submitted by ' . htmlspecialchars($ticket['submitter_name']) . '. Please assign it to a technician.'
    );
    $plain_message = 'New ticket from ' . $ticket['submitter_name'] . ' needs assignment.';

    $admins = $pdo->query("SELECT user_id, name, email FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $admin) {
        create_notification($pdo, $admin['user_id'], $ticket_id, $plain_message);
        if (MAIL_ENABLED) {
            send_notification_email($admin['email'], $admin['name'], $subject, $body);
        }
    }
}

/**
 * Send a password reset email with the reset link.
 */
function send_password_reset_email($to_email, $to_name, $reset_link) {
    if (!MAIL_ENABLED) {
        return false;
    }

    $subject = '[HelpDeskPro] Password Reset Request';
    $body = "
        <div style='font-family:Arial,sans-serif;font-size:14px;color:#222;'>
            <h3 style='color:#0d6efd;'>Password Reset Request</h3>
            <p>Hi " . htmlspecialchars($to_name) . ",</p>
            <p>We received a request to reset your HelpDeskPro password. Click the button below to choose a new one. This link expires in 30 minutes.</p>
            <p style='margin-top:20px;'><a href='" . htmlspecialchars($reset_link) . "' style='background:#0d6efd;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;'>Reset Password</a></p>
            <p style='margin-top:16px;font-size:12px;color:#888;'>If you didn't request this, you can safely ignore this email - your password will not be changed.</p>
            <hr style='margin-top:24px;border:none;border-top:1px solid #ddd;'>
            <small style='color:#888;'>This is an automated notification from HelpDeskPro.</small>
        </div>
    ";

    return send_notification_email($to_email, $to_name, $subject, $body);
}

/**
 * Notify all admins that a ticket has been auto-escalated due to an SLA breach.
 */
function notify_admins_escalation($pdo, $ticket_id, $old_priority, $new_priority, $hours_open) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name AS submitter_name FROM tickets t
        JOIN users u ON t.user_id = u.user_id
        WHERE t.ticket_id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        return;
    }

    $subject = "[HelpDeskPro] SLA BREACH - Ticket #{$ticket['ticket_id']} Escalated";
    $priority_note = $old_priority === $new_priority
        ? "It was already <strong>{$old_priority}</strong> priority and has now been flagged as breached."
        : "Priority automatically raised from <strong>{$old_priority}</strong> to <strong>{$new_priority}</strong>.";
    $body = ticket_email_body(
        'SLA Breach - Auto-Escalated',
        $ticket,
        "This ticket (submitted by " . htmlspecialchars($ticket['submitter_name']) . ") has been open for {$hours_open} hours without resolution. $priority_note"
    );
    $plain_message = "SLA breach on ticket - open {$hours_open}h ({$old_priority} -> {$new_priority}).";

    $admins = $pdo->query("SELECT user_id, name, email FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $admin) {
        create_notification($pdo, $admin['user_id'], $ticket_id, $plain_message);
        if (MAIL_ENABLED) {
            send_notification_email($admin['email'], $admin['name'], $subject, $body);
        }
    }
}

/**
 * Build a simple, consistent HTML email body for ticket notifications.
 */
function ticket_email_body($heading, $ticket, $message) {
    $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $link = $baseUrl . '/ticket.php?id=' . $ticket['ticket_id'];

    return "
        <div style='font-family:Arial,sans-serif;font-size:14px;color:#222;'>
            <h3 style='color:#0d6efd;'>$heading</h3>
            <p><strong>Ticket #{$ticket['ticket_id']}:</strong> " . htmlspecialchars($ticket['title']) . "</p>
            <p>$message</p>
            <p style='margin-top:20px;'><a href='$link' style='background:#0d6efd;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;'>View Ticket</a></p>
            <hr style='margin-top:24px;border:none;border-top:1px solid #ddd;'>
            <small style='color:#888;'>This is an automated notification from HelpDeskPro.</small>
        </div>
    ";
}
