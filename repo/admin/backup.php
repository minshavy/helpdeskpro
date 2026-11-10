<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/backup.php';
require_role('admin');
$admin = current_user();

// ---------------------------------------------------------
// Download: POST only, so a backup can't be triggered by a link someone
// else sends you, and CSRF-protected like every other state-changing action.
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $filename = 'helpdeskpro-backup-' . date('Y-m-d-His') . '.sql';

    // Stop any output buffering so the file streams straight to the browser
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @set_time_limit(300);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $summary = generate_sql_backup($pdo, function ($chunk) {
        echo $chunk;
        flush();
    }, $admin['name'] . ' (' . $admin['role'] . ')');

    // Downloading a full copy of every user's data is exactly the kind of
    // event an auditor wants to see, so it's recorded after it succeeds.
    audit_log($pdo, $admin['user_id'], 'backup_downloaded',
        "Downloaded full database backup ({$summary['tables']} tables, {$summary['rows']} rows)",
        'database', null, null, ['file' => $filename] + $summary);
    exit;
}

// ---------------------------------------------------------
// Page
// ---------------------------------------------------------
$table_stats = [];
foreach (backup_table_order($pdo) as $t) {
    $table_stats[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
$total_rows = array_sum($table_stats);

$last_backup = $pdo->query("
    SELECT created_at, actor_name FROM audit_log
    WHERE action = 'backup_downloaded'
    ORDER BY created_at DESC LIMIT 1
")->fetch();

$days_since = $last_backup ? (int) floor((time() - strtotime($last_backup['created_at'])) / 86400) : null;

$page_title = 'Backup';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Database backup</h1>
    <p class="text-muted mb-0">Download a complete copy of the database you can restore at any time.</p>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <div class="card mb-4">
            <div class="card-body p-4">

                <?php if ($last_backup === false): ?>
                    <div class="alert alert-warning d-flex gap-2 mb-4">
                        <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1"></i>
                        <div><strong>No backup has been taken yet.</strong> Your hosting provider does not keep copies of your data, so if the database is deleted there is no way to get it back without one.</div>
                    </div>
                <?php elseif ($days_since >= 7): ?>
                    <div class="alert alert-warning d-flex gap-2 mb-4">
                        <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1"></i>
                        <div><strong>Last backup was <?= $days_since ?> days ago.</strong> Anything created since then would be lost if the database were deleted.</div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success d-flex gap-2 mb-4">
                        <i class="bi bi-check-circle flex-shrink-0 mt-1"></i>
                        <div>Last backup: <strong><?= e($last_backup['created_at']) ?></strong> by <?= e($last_backup['actor_name']) ?>.</div>
                    </div>
                <?php endif; ?>

                <h2 class="h6 fw-bold mb-2">What's included</h2>
                <p class="text-muted fs-7 mb-4">
                    Every table and every row &mdash; <?= count($table_stats) ?> tables, <?= number_format($total_rows) ?> rows in total &mdash;
                    as a single <code>.sql</code> file.
                </p>

                <form method="POST">
                    <?php csrf_field(); ?>
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-download me-2"></i>Download backup
                    </button>
                </form>

                <p class="text-muted fs-8 mt-3 mb-0">
                    <i class="bi bi-shield-lock me-1"></i>
                    The file contains password hashes and two-factor secrets. Keep it somewhere private and
                    never upload it to a public place such as a GitHub repository. Every download is recorded in the audit log.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">How to restore from a backup</h2>
                <ol class="fs-7 mb-3 ps-3">
                    <li class="mb-2">Open <strong>phpMyAdmin</strong> and select your database. If it was deleted, recreate it first with the same name from your hosting control panel.</li>
                    <li class="mb-2">Go to the <strong>Import</strong> tab and choose your backup file.</li>
                    <li class="mb-2">Click <strong>Import</strong>. Every table is rebuilt exactly as it was when the backup was taken.</li>
                </ol>
                <div class="alert alert-danger fs-7 mb-0">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    Restoring <strong>replaces</strong> everything currently in the database with the contents of the backup.
                    Anything created after the backup was taken will be lost.
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header bg-white py-3 border-0 fw-bold">Current contents</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 fs-7">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr><th>Table</th><th class="text-end">Rows</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($table_stats as $name => $count): ?>
                        <tr>
                            <td><code><?= e($name) ?></code></td>
                            <td class="text-end"><?= number_format($count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
