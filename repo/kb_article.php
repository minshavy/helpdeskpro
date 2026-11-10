<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'vote') {
        $vote = $_POST['vote'] ?? '';
        if ($vote === 'yes') {
            $pdo->prepare("UPDATE kb_articles SET helpful_yes = helpful_yes + 1 WHERE id = ?")->execute([$id]);
            flash('success', 'Thanks for the feedback!');
        } elseif ($vote === 'no') {
            $pdo->prepare("UPDATE kb_articles SET helpful_no = helpful_no + 1 WHERE id = ?")->execute([$id]);
            flash('success', 'Thanks for letting us know - consider submitting a ticket for further help.');
        }
        header('Location: kb_article.php?id=' . $id);
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT ka.*, u.name AS author_name
    FROM kb_articles ka
    JOIN users u ON ka.created_by = u.user_id
    WHERE ka.id = ?
");
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article) {
    flash('error', 'Article not found.');
    header('Location: ' . base_url('knowledge_base.php'));
    exit;
}

// Count the view (simple increment - good enough for a self-service popularity signal)
$pdo->prepare("UPDATE kb_articles SET views = views + 1 WHERE id = ?")->execute([$id]);

$page_title = $article['title'];
include __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
    <a href="knowledge_base.php" class="text-decoration-none text-muted"><i class="bi bi-arrow-left me-1"></i>Back to Knowledge Base</a>
</div>

<div class="card">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-2 gap-3">
            <h1 class="h4 fw-bold mb-0"><?= e($article['title']) ?></h1>
            <span class="badge bg-secondary-subtle text-secondary flex-shrink-0"><?= e($article['category']) ?></span>
        </div>
        <p class="text-muted fs-8 mb-4">
            By <?= e($article['author_name']) ?> &middot; Updated <?= e($article['updated_at']) ?> &middot; <?= (int) $article['views'] ?> views
        </p>

        <div class="mb-4" style="white-space: pre-line;"><?= nl2br(e($article['body'])) ?></div>

        <hr>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <p class="fw-medium mb-2">Was this article helpful?</p>
                <form method="POST" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="vote">
                    <input type="hidden" name="vote" value="yes">
                    <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-hand-thumbs-up me-1"></i>Yes (<?= (int) $article['helpful_yes'] ?>)</button>
                </form>
                <form method="POST" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="vote">
                    <input type="hidden" name="vote" value="no">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-hand-thumbs-down me-1"></i>No (<?= (int) $article['helpful_no'] ?>)</button>
                </form>
            </div>
            <?php if ($user['role'] === 'employee'): ?>
                <a href="employee/submit_ticket.php?category=<?= urlencode($article['category']) ?>&title=<?= urlencode('Re: ' . $article['title']) ?>"
                   class="btn btn-primary btn-sm"><i class="bi bi-life-preserver me-1"></i>This didn't help &mdash; submit a ticket</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
