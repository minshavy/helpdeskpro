<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['technician', 'admin']);
$user = current_user();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE id = ?");
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article) {
    flash('error', 'Article not found.');
    header('Location: kb_manage.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

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
        $stmt = $pdo->prepare("UPDATE kb_articles SET title = ?, category = ?, body = ? WHERE id = ?");
        $stmt->execute([$title, $category, $body, $id]);
        audit_log($pdo, $user['user_id'], 'kb_article_updated', "Updated KB article: $title");
        flash('success', 'Article updated.');
        header('Location: kb_manage.php');
        exit;
    }
}

$page_title = 'Edit Article';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Edit article</h1>
    <p class="text-muted mb-0">Update this Knowledge Base entry.</p>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="card p-4" style="max-width: 700px;">
    <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="id" value="<?= $article['id'] ?>">
        <div class="mb-3">
            <label class="form-label fw-medium">Title</label>
            <input type="text" name="title" class="form-control" value="<?= e($article['title']) ?>" required maxlength="200">
        </div>
        <div class="mb-3">
            <label class="form-label fw-medium">Category</label>
            <select name="category" class="form-select">
                <?php foreach (['Hardware', 'Software', 'Network', 'Other'] as $c): ?>
                    <option value="<?= $c ?>" <?= $article['category'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-4">
            <label class="form-label fw-medium">Content</label>
            <textarea name="body" class="form-control" rows="10" required><?= e($article['body']) ?></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="kb_manage.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
