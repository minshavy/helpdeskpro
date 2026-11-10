<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

$search = trim($_GET['search'] ?? '');
$category_filter = $_GET['category'] ?? '';

$per_page = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (title LIKE ? OR body LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($category_filter, ['Hardware', 'Software', 'Network', 'Other'])) {
    $where .= " AND category = ?";
    $params[] = $category_filter;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM kb_articles $where");
$stmt->execute($params);
$total_articles = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_articles / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "
    SELECT ka.*, u.name AS author_name
    FROM kb_articles ka
    JOIN users u ON ka.created_by = u.user_id
    $where
    ORDER BY ka.views DESC, ka.title ASC
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

$filter_params = array_filter([
    'search' => $search, 'category' => $category_filter,
], fn($v) => $v !== '');

$page_title = 'Knowledge Base';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="bi bi-book text-primary me-2"></i>Knowledge Base</h1>
        <p class="text-muted mb-0">Find a quick fix before submitting a ticket.</p>
    </div>
    <?php if (in_array($user['role'], ['technician', 'admin'])): ?>
        <a href="technician/kb_manage.php" class="btn btn-outline-secondary d-inline-flex align-items-center">
            <i class="bi bi-gear me-1"></i> Manage articles
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header bg-white py-3 border-0">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-8">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search articles..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-8 col-md-2">
                <select name="category" class="form-select">
                    <option value="">All categories</option>
                    <?php foreach (['Hardware', 'Software', 'Network', 'Other'] as $c): ?>
                        <option value="<?= $c ?>" <?= $category_filter === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </form>
    </div>

    <div class="list-group list-group-flush">
        <?php foreach ($articles as $a): ?>
            <a href="kb_article.php?id=<?= $a['id'] ?>" class="list-group-item list-group-item-action py-3">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold mb-1"><?= e($a['title']) ?></div>
                        <p class="text-muted fs-7 mb-1"><?= e(mb_strimwidth(strip_tags($a['body']), 0, 140, '...')) ?></p>
                        <span class="badge bg-secondary-subtle text-secondary"><?= e($a['category']) ?></span>
                        <small class="text-muted ms-2"><i class="bi bi-eye me-1"></i><?= (int) $a['views'] ?> views</small>
                        <?php if ($a['helpful_yes'] + $a['helpful_no'] > 0): ?>
                            <small class="text-muted ms-2"><i class="bi bi-hand-thumbs-up me-1"></i><?= round($a['helpful_yes'] / ($a['helpful_yes'] + $a['helpful_no']) * 100) ?>% found this helpful</small>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (empty($articles)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem;"></i>
                No articles found<?= $search !== '' ? ' for "' . e($search) . '"' : '' ?>.
                <?php if ($user['role'] === 'employee'): ?>
                    <br><a href="employee/submit_ticket.php">Submit a ticket</a> and our IT team will help.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white py-3">
        <?php render_pagination($page, $total_pages, $filter_params); ?>
        <p class="text-center text-muted fs-8 mt-2 mb-0"><?= $total_articles ?> article<?= $total_articles === 1 ? '' : 's' ?> total</p>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
