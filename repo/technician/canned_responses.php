<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['technician', 'admin']);
$user = current_user();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');

        if ($title === '' || $body === '') {
            $error = 'Both a title and the response text are required.';
        } elseif (str_len($title) > 100) {
            $error = 'Title is too long (max 100 characters).';
        } elseif (str_len($body) > 2000) {
            $error = 'Response text is too long (max 2000 characters).';
        } else {
            $stmt = $pdo->prepare("INSERT INTO canned_responses (created_by, title, body) VALUES (?, ?, ?)");
            $stmt->execute([$user['user_id'], $title, $body]);
            audit_log($pdo, $user['user_id'], 'canned_response_created', "Created canned response: $title", 'canned_response', $pdo->lastInsertId(), null, ['title' => $title]);
            flash('success', 'Canned response created.');
            header('Location: canned_responses.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM canned_responses WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'Canned response not found.');
        } elseif ($user['role'] !== 'admin' && (int) $target['created_by'] !== (int) $user['user_id']) {
            // Technicians can only delete their own; admins can delete any
            flash('error', 'You can only delete canned responses you created.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM canned_responses WHERE id = ?");
            $stmt->execute([$id]);
            audit_log($pdo, $user['user_id'], 'canned_response_deleted', "Deleted canned response: {$target['title']}", 'canned_response', $id, ['title' => $target['title']], null);
            flash('success', 'Canned response deleted.');
        }
        header('Location: canned_responses.php');
        exit;
    }
}

$responses = $pdo->query("
    SELECT cr.*, u.name AS author_name
    FROM canned_responses cr
    JOIN users u ON cr.created_by = u.user_id
    ORDER BY cr.title
")->fetchAll();

function preview_text($text, $limit = 80) {
    if (str_len($text) <= $limit) {
        return $text;
    }
    return (function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit)) . '...';
}

$page_title = 'Canned Responses';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Canned responses</h1>
        <p class="text-muted mb-0">Shared reply templates any technician can use when commenting on a ticket.</p>
    </div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <!-- Add Response Form -->
    <div class="col-12 col-lg-4">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-plus-circle text-primary me-2"></i>New response</h5>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label fw-medium">Title</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Requesting more info" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Response text</label>
                    <textarea name="body" class="form-control" rows="5" placeholder="The reply text that gets inserted..." required maxlength="2000"></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create response</button>
            </form>
        </div>
    </div>

    <!-- Responses List -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr>
                            <th>Title</th>
                            <th>Preview</th>
                            <th>Created by</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($responses as $r): ?>
                        <tr>
                            <td class="fw-medium"><?= e($r['title']) ?></td>
                            <td class="text-muted fs-7"><?= e(preview_text($r['body'])) ?></td>
                            <td><?= e($r['author_name']) ?></td>
                            <td class="text-end">
                                <?php if ($user['role'] === 'admin' || (int) $r['created_by'] === (int) $user['user_id']): ?>
                                    <form method="POST" onsubmit="return confirm('Delete this canned response?')" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($responses)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No canned responses yet. Add your first one!</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
