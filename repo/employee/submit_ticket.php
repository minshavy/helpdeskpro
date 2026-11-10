<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/escalation.php';
require_role('employee');
$user = current_user();

$allowed_priorities = ['Low', 'Medium', 'High'];
$allowed_categories = ['Hardware', 'Software', 'Network', 'Other'];

// Departments the employee can route this ticket to
$departments = $pdo->query("SELECT department_id, name, description FROM departments ORDER BY name")->fetchAll();
$valid_department_ids = array_column($departments, 'department_id');

// Support prefilling from a Knowledge Base article's "submit a ticket" link
$prefill_title = trim($_GET['title'] ?? '');
$prefill_category = $_GET['category'] ?? '';
if (!in_array($prefill_category, $allowed_categories)) {
    $prefill_category = '';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $category = $_POST['category'] ?? 'Other';
    $department_id = $_POST['department_id'] ?? '';

    if ($title === '' || $description === '') {
        $error = 'Title and description are required.';
    } elseif (str_len($title) > 150) {
        $error = 'Title is too long (max 150 characters).';
    } elseif (!in_array($priority, $allowed_priorities) || !in_array($category, $allowed_categories)) {
        $error = 'Invalid priority or category selected.';
    } elseif (!empty($valid_department_ids) && !in_array((int) $department_id, array_map('intval', $valid_department_ids))) {
        $error = 'Please choose which team should handle this ticket.';
    } else {
        $attachment = null;
        $upload_error = null;

        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $max_size = 5 * 1024 * 1024; // 5MB
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
                // Check the file's REAL content type, not just its filename extension
                // (a malicious .php file renamed to .jpg would fail this check).
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
        // SLA deadline is measured in BUSINESS hours, so a ticket filed near
        // close of business doesn't burn its budget overnight or at the weekend.
        $sla_hours = get_sla_hours()[$priority] ?? 72;
        $created_at = date('Y-m-d H:i:s');
        $sla_due_at = BusinessHours::slaDueAt($created_at, $sla_hours);

        $stmt = $pdo->prepare("INSERT INTO tickets (user_id, title, description, category, priority, attachment, department_id, sla_due_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user['user_id'], $title, $description, $category, $priority, $attachment, $department_id !== '' ? (int) $department_id : null, $sla_due_at]);
        $ticket_id = $pdo->lastInsertId();
        log_ticket_action($pdo, $ticket_id, $user['user_id'], 'Open', 'Ticket submitted.');

        audit_log($pdo, $user['user_id'], 'ticket_created',
            "Submitted ticket #$ticket_id: $title",
            'ticket', $ticket_id, null,
            ['title' => $title, 'priority' => $priority, 'category' => $category,
             'department_id' => $department_id !== '' ? (int) $department_id : null,
             'attachment' => $attachment, 'sla_due_at' => $sla_due_at]);

        notify_admins_new_ticket($pdo, $ticket_id);

        flash('success', 'Ticket submitted successfully! Ticket #' . $ticket_id);
        header('Location: my_tickets.php');
        exit;
        }
    }
}

$page_title = 'Submit Ticket';
include __DIR__ . '/../includes/header.php';
?>

<main class="py-3" style="max-width: 750px; margin: 0 auto;">
<div class="card border-0 shadow-sm">
<div class="card-body p-4 p-md-5">
    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">Submit a new ticket</h1>
        <p class="text-muted">Describe the issue and our IT team will take it from here.</p>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

    <div class="alert alert-info d-flex align-items-center gap-3 mb-4">
        <i class="bi bi-book fs-4"></i>
        <div>
            <strong>Looking for a quick fix?</strong> Many common issues are already answered in our
            <a href="../knowledge_base.php">Knowledge Base</a> - it might save you the wait for a ticket.
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label fw-medium">Ticket Title</label>
            <input type="text" name="title" class="form-control form-control-lg" placeholder="Brief issue summary..." value="<?= e($prefill_title) ?>" required maxlength="150">
        </div>
        <div class="mb-3">
            <label class="form-label fw-medium">Description</label>
            <textarea name="description" class="form-control" rows="5" placeholder="Detailed description of the problem..." required></textarea>
        </div>
        <?php if (!empty($departments)): ?>
        <div class="mb-3">
            <label class="form-label fw-medium">Which team should handle this?</label>
            <select name="department_id" class="form-select" required>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['department_id'] ?>"><?= e($d['name']) ?><?= $d['description'] ? ' — ' . e($d['description']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-medium">Priority</label>
                <select name="priority" class="form-select">
                    <option value="Low">Low</option>
                    <option value="Medium" selected>Medium</option>
                    <option value="High">High</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-medium">Category</label>
                <select name="category" class="form-select">
                    <?php foreach ($allowed_categories as $c): ?>
                        <option value="<?= $c ?>" <?= $prefill_category === $c ? 'selected' : ($prefill_category === '' && $c === 'Hardware' ? 'selected' : '') ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-medium">Attachment (optional)</label>
            <input class="form-control" type="file" name="attachment">
        </div>
        <div class="d-flex flex-column gap-2">
            <button type="submit" class="btn btn-primary py-2 fw-medium">Submit Ticket</button>
            <button type="reset" class="btn btn-light border py-2">Reset</button>
        </div>
    </form>
</div>
</div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
