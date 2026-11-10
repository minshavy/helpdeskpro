<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/escalation.php';
require_role('employee');
$user = current_user();

$ticket_id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM tickets WHERE ticket_id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    flash('error', 'Ticket not found.');
    header('Location: my_tickets.php');
    exit;
}

// Only the ticket's own submitter can edit it, and only while it's still
// Open (not yet assigned/picked up) - once a technician has started work,
// changing the details out from under them isn't safe.
if ((int) $ticket['user_id'] !== (int) $user['user_id']) {
    flash('error', 'You do not have permission to edit that ticket.');
    header('Location: my_tickets.php');
    exit;
}
// Must be unclaimed, not merely "Open": a technician can set an assigned
// ticket back to Open, and the submitter must not be able to edit it then.
// This page is reachable directly, so it repeats the check rather than
// trusting the button on the ticket page to be hidden.
if ($ticket['status'] !== 'Open' || $ticket['assigned_to'] !== null) {
    flash('error', 'This ticket can no longer be edited (it has already been picked up or closed).');
    header('Location: ' . base_url('ticket.php?id=' . $ticket_id));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $category = $_POST['category'] ?? 'Other';

    $allowed_priorities = ['Low', 'Medium', 'High'];
    $allowed_categories = ['Hardware', 'Software', 'Network', 'Other'];

    if ($title === '' || $description === '') {
        $error = 'Title and description are required.';
    } elseif (str_len($title) > 150) {
        $error = 'Title is too long (max 150 characters).';
    } elseif (!in_array($priority, $allowed_priorities) || !in_array($category, $allowed_categories)) {
        $error = 'Invalid priority or category selected.';
    } else {
        $attachment = $ticket['attachment']; // keep existing unless a new one is uploaded
        $upload_error = null;

        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $max_size = 5 * 1024 * 1024;
            $allowed_mimes = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                'application/pdf' => 'pdf',
                'text/plain' => 'txt',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];

            if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                $upload_error = 'File upload failed. Please try again.';
            } elseif ($_FILES['attachment']['size'] > $max_size) {
                $upload_error = 'Attachment is too large (max 5MB).';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $real_mime = finfo_file($finfo, $_FILES['attachment']['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowed_mimes[$real_mime])) {
                    $upload_error = 'Unsupported file type. Allowed: JPG, PNG, GIF, PDF, TXT, DOC, DOCX.';
                } else {
                    $uploadDir = __DIR__ . '/../uploads/';
                    $safeName = uniqid('att_', true) . '.' . $allowed_mimes[$real_mime];
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $safeName)) {
                        $attachment = $safeName;
                    } else {
                        $upload_error = 'Could not save the uploaded file.';
                    }
                }
            }
        }

        if ($upload_error) {
            $error = $upload_error;
        } else {
            // Recompute the SLA deadline if priority changed - a ticket bumped
            // to High should get the shorter High deadline, not keep the old one.
            $sla_hours = get_sla_hours()[$priority] ?? 72;
            $sla_due_at = BusinessHours::slaDueAt($ticket['created_at'], $sla_hours);

            $stmt = $pdo->prepare("UPDATE tickets SET title = ?, description = ?, category = ?, priority = ?, attachment = ?, sla_due_at = ? WHERE ticket_id = ?");
            $stmt->execute([$title, $description, $category, $priority, $attachment, $sla_due_at, $ticket_id]);
            log_ticket_action($pdo, $ticket_id, $user['user_id'], null, 'Ticket details edited by submitter.');

            $changes = audit_diff($ticket,
                ['title' => $title, 'description' => $description, 'category' => $category, 'priority' => $priority, 'attachment' => $attachment],
                ['title', 'description', 'category', 'priority', 'attachment']);
            audit_log($pdo, $user['user_id'], 'ticket_edited',
                "Edited ticket #$ticket_id (" . (count($changes) ? implode(', ', array_keys($changes)) : 'no field changes') . ")",
                'ticket', $ticket_id, $ticket, $changes);

            flash('success', 'Ticket updated successfully.');
            header('Location: ' . base_url('ticket.php?id=' . $ticket_id));
            exit;
        }
    }
}

$page_title = 'Edit Ticket';
include __DIR__ . '/../includes/header.php';
?>

<main class="py-3" style="max-width: 750px; margin: 0 auto;">
<div class="card border-0 shadow-sm">
<div class="card-body p-4 p-md-5">
    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">Edit ticket #<?= $ticket['ticket_id'] ?></h1>
        <p class="text-muted">You can edit this ticket until a technician picks it up.</p>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label fw-medium">Ticket Title</label>
            <input type="text" name="title" class="form-control form-control-lg" value="<?= e($ticket['title']) ?>" required maxlength="150">
        </div>
        <div class="mb-3">
            <label class="form-label fw-medium">Description</label>
            <textarea name="description" class="form-control" rows="5" required><?= e($ticket['description']) ?></textarea>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-medium">Priority</label>
                <select name="priority" class="form-select">
                    <?php foreach (['Low', 'Medium', 'High'] as $p): ?>
                        <option value="<?= $p ?>" <?= $ticket['priority'] === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-medium">Category</label>
                <select name="category" class="form-select">
                    <?php foreach (['Hardware', 'Software', 'Network', 'Other'] as $c): ?>
                        <option value="<?= $c ?>" <?= $ticket['category'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-medium">Attachment</label>
            <?php if ($ticket['attachment']): ?>
                <div class="mb-2">
                    <a href="<?= base_url('uploads/' . e($ticket['attachment'])) ?>" target="_blank"><i class="bi bi-paperclip me-1"></i>Current attachment</a>
                </div>
            <?php endif; ?>
            <input class="form-control" type="file" name="attachment">
            <div class="form-text">Uploading a new file will replace the current attachment (if any).</div>
        </div>
        <div class="d-flex flex-column gap-2">
            <button type="submit" class="btn btn-primary py-2 fw-medium">Save changes</button>
            <a href="<?= base_url('ticket.php?id=' . $ticket_id) ?>" class="btn btn-light border py-2 text-center">Cancel</a>
        </div>
    </form>
</div>
</div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
