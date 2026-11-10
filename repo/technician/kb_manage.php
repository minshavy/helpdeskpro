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
        $category = $_POST['category'] ?? 'Other';
        $body = trim($_POST['body'] ?? '');
        $allowed_categories = ['Hardware', 'Software', 'Network', 'Other'];

        if ($title === '' || $body === '') {
            $error = 'Both a title and the article content are required.';
        } elseif (str_len($title) > 200) {
            $error = 'Title is too long (max 200 characters).';
        } elseif (!in_array($category, $allowed_categories)) {
            $error = 'Invalid category.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO kb_articles (title, category, body, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $category, $body, $user['user_id']]);
            audit_log($pdo, $user['user_id'], 'kb_article_created', "Created KB article: $title");
            flash('success', 'Article published.');
            header('Location: kb_manage.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'Article not found.');
        } elseif ($user['role'] !== 'admin' && (int) $target['created_by'] !== (int) $user['user_id']) {
            flash('error', 'You can only delete articles you created.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM kb_articles WHERE id = ?");
            $stmt->execute([$id]);
            audit_log($pdo, $user['user_id'], 'kb_article_deleted', "Deleted KB article: {$target['title']}");
            flash('success', 'Article deleted.');
        }
        header('Location: kb_manage.php');
        exit;
    }
}

$articles = $pdo->query("
    SELECT ka.*, u.name AS author_name
    FROM kb_articles ka
    JOIN users u ON ka.created_by = u.user_id
    ORDER BY ka.title
")->fetchAll();

$page_title = 'Manage Knowledge Base';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Manage Knowledge Base</h1>
        <p class="text-muted mb-0">Publish and maintain self-service articles for employees.</p>
    </div>
    <a href="../knowledge_base.php" class="btn btn-outline-secondary d-inline-flex align-items-center">
        <i class="bi bi-book me-1"></i> View Knowledge Base
    </a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <!-- Add Article Form -->
    <div class="col-12 col-lg-4">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-plus text-primary me-2"></i>New article</h5>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label fw-medium">Title</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. How to reset your password" required maxlength="200">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Category</label>
                    <select name="category" class="form-select">
                        <option value="Hardware">Hardware</option>
                        <option value="Software">Software</option>
                        <option value="Network">Network</option>
                        <option value="Other" selected>Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Content</label>
                    <textarea name="body" class="form-control" rows="8" placeholder="Step-by-step instructions..." required></textarea>
                    <div class="form-text">Plain text - line breaks are preserved.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Publish article</button>
            </form>
        </div>
    </div>

    <!-- Articles List -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Views</th>
                            <th>Helpful</th>
                            <th>Author</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($articles as $a): ?>
                        <tr>
                            <td class="fw-medium"><a href="../kb_article.php?id=<?= $a['id'] ?>" class="text-decoration-none"><?= e($a['title']) ?></a></td>
                            <td><span class="badge bg-secondary-subtle text-secondary"><?= e($a['category']) ?></span></td>
                            <td><?= (int) $a['views'] ?></td>
                            <td>
                                <span class="text-success"><i class="bi bi-hand-thumbs-up"></i> <?= (int) $a['helpful_yes'] ?></span>
                                <span class="text-danger ms-1"><i class="bi bi-hand-thumbs-down"></i> <?= (int) $a['helpful_no'] ?></span>
                            </td>
                            <td><?= e($a['author_name']) ?></td>
                            <td class="text-end">
                                <a href="kb_edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                <?php if ($user['role'] === 'admin' || (int) $a['created_by'] === (int) $user['user_id']): ?>
                                    <form method="POST" onsubmit="return confirm('Delete this article?')" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($articles)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No articles yet. Publish your first one!</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
