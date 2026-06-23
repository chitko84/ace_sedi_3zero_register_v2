<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup_functions.php';

$adminId = (int)($_SESSION['user_id'] ?? 0);
if ($adminId <= 0) {
    header('Location: ../login.php');
    exit();
}

$auth = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
$auth->bind_param('i', $adminId);
$auth->execute();
$adminUser = $auth->get_result()->fetch_assoc();

if (!$adminUser || ($adminUser['role'] ?? '') !== 'admin') {
    header('Location: ../user/dashboard.php');
    exit();
}

if (!isset($_SESSION['backup_csrf_token'])) {
    $_SESSION['backup_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['backup_csrf_token'];

function backup_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function backup_check_csrf(string $token): void
{
    if (!hash_equals($_SESSION['backup_csrf_token'] ?? '', $token)) {
        throw new RuntimeException('Invalid security token. Please refresh and try again.');
    }
}

function backup_db_config_from_globals(): array
{
    return [
        'host' => $GLOBALS['servername'] ?? 'localhost',
        'user' => $GLOBALS['username'] ?? '',
        'password' => $GLOBALS['password'] ?? '',
        'database' => $GLOBALS['dbname'] ?? '',
    ];
}

function backup_flash(string $type, string $message): void
{
    $_SESSION['backup_flash'][] = ['type' => $type, 'message' => $message];
}

try {
    backup_ensure_directories();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download') {
        backup_check_csrf((string)($_GET['csrf_token'] ?? ''));
        $file = backup_resolve_file((string)($_GET['file'] ?? ''));
        backup_download_file($file);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        backup_check_csrf((string)($_POST['csrf_token'] ?? ''));
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'export_csv') {
            backup_stream_csv($conn, (string)($_POST['table'] ?? ''));
            exit();
        }

        if ($action === 'backup_database') {
            $file = backup_create_database($conn, backup_db_config_from_globals());
            backup_flash('success', 'Database backup created: ' . basename($file));
        } elseif ($action === 'backup_files') {
            $file = backup_create_project_files_zip();
            backup_flash('success', 'Project files backup created: ' . basename($file));
        } elseif ($action === 'full_backup') {
            $file = backup_create_full_zip($conn, backup_db_config_from_globals());
            backup_flash('success', 'Full system backup created: ' . basename($file));
        } elseif ($action === 'delete') {
            backup_delete_file((string)($_POST['file'] ?? ''));
            backup_flash('success', 'Backup file deleted.');
        } elseif ($action === 'bulk_delete') {
            $selectedFiles = $_POST['files'] ?? [];
            if (!is_array($selectedFiles) || count($selectedFiles) === 0) {
                backup_flash('danger', 'No backup file was selected.');
            } else {
                $result = backup_delete_files($selectedFiles);
                $deleted = (int)$result['deleted'];
                $failedCount = count($result['failed']);

                if ($deleted > 0 && $failedCount === 0) {
                    backup_flash('success', "{$deleted} selected backup file(s) deleted successfully.");
                } elseif ($deleted > 0) {
                    backup_flash('warning', "{$deleted} selected backup file(s) deleted successfully. {$failedCount} file(s) could not be deleted.");
                } else {
                    backup_flash('danger', 'Selected backup files could not be deleted.');
                }
            }
        } elseif ($action === 'cleanup_old') {
            $deleted = backup_cleanup_old(30);
            backup_flash('success', "Deleted {$deleted} backup file(s) older than 30 days.");
        } else {
            throw new RuntimeException('Invalid backup action.');
        }

        header('Location: backup.php');
        exit();
    }
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        backup_flash('danger', $e->getMessage());
        header('Location: backup.php');
        exit();
    }
    $pageError = $e->getMessage();
}

$flash = $_SESSION['backup_flash'] ?? [];
unset($_SESSION['backup_flash']);
$backupFiles = backup_list_files();

require_once __DIR__ . '/header.php';
?>

<main class="main-content container-fluid">
    <style>
        .backup-action-card {
            border: 0;
            border-radius: 10px;
            box-shadow: 0 4px 18px rgba(0,0,0,.08);
            height: 100%;
        }
        .backup-action-card .icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e8f4fd;
            color: #1a5276;
            font-size: 1.25rem;
        }
        .backup-table td {
            vertical-align: middle;
        }
        .backup-check {
            width: 1%;
            white-space: nowrap;
        }
    </style>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-1">Backup Management</h2>
            <div class="text-muted">Create and download secure backups of system data and project files.</div>
        </div>
        <form method="post" class="js-delete-confirm"
              data-delete-title="Delete Old Backups"
              data-delete-message="Delete all backup files older than 30 days?"
              data-delete-item="Old backup files"
              data-delete-confirm-label="<i class='fa-solid fa-broom me-1'></i> Delete Old Backups">
            <input type="hidden" name="csrf_token" value="<?= backup_h($csrfToken) ?>">
            <input type="hidden" name="action" value="cleanup_old">
            <button class="btn btn-outline-danger">
                <i class="fa-solid fa-broom me-1"></i> Delete Backups Older Than 30 Days
            </button>
        </form>
    </div>

    <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger"><?= backup_h($pageError) ?></div>
    <?php endif; ?>

    <?php foreach ($flash as $item): ?>
        <div class="alert alert-<?= backup_h($item['type']) ?> alert-dismissible fade show" role="alert">
            <?= backup_h($item['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php if (!class_exists('ZipArchive')): ?>
        <div class="alert alert-warning">
            PHP ZipArchive is not enabled. Database and CSV exports can still work, but project ZIP and full ZIP backups require the PHP zip extension.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php
        $actions = [
            ['backup_database', 'Backup Database', 'Create a full SQL backup of all MySQL tables and data.', 'fa-database', 'primary'],
            ['backup_files', 'Backup Project Files', 'Create a ZIP archive of admin, user, includes, uploads, assets, and PHPMailer files.', 'fa-folder-tree', 'primary'],
            ['full_backup', 'Download Full Backup ZIP', 'Create a ZIP containing SQL, project files ZIP, and CSV exports.', 'fa-file-zipper', 'success'],
        ];
        ?>
        <?php foreach ($actions as $action): ?>
            <div class="col-12 col-md-4">
                <div class="card backup-action-card">
                    <div class="card-body">
                        <div class="icon mb-3"><i class="fa-solid <?= backup_h($action[3]) ?>"></i></div>
                        <h5><?= backup_h($action[1]) ?></h5>
                        <p class="text-muted small"><?= backup_h($action[2]) ?></p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= backup_h($csrfToken) ?>">
                            <input type="hidden" name="action" value="<?= backup_h($action[0]) ?>">
                            <button class="btn btn-<?= backup_h($action[4]) ?> w-100">
                                <i class="fa-solid fa-download me-1"></i> <?= backup_h($action[1]) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $csvActions = [
            ['users', 'Export Users CSV', 'fa-users'],
            ['clubs', 'Export Clubs CSV', 'fa-people-group'],
            ['club_members', 'Export Club Members CSV', 'fa-address-book'],
        ];
        ?>
        <?php foreach ($csvActions as $csvAction): ?>
            <div class="col-12 col-md-4">
                <div class="card backup-action-card">
                    <div class="card-body">
                        <div class="icon mb-3"><i class="fa-solid <?= backup_h($csvAction[2]) ?>"></i></div>
                        <h5><?= backup_h($csvAction[1]) ?></h5>
                        <p class="text-muted small">Download a UTF-8 CSV export immediately.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= backup_h($csrfToken) ?>">
                            <input type="hidden" name="action" value="export_csv">
                            <input type="hidden" name="table" value="<?= backup_h($csvAction[0]) ?>">
                            <button class="btn btn-outline-success w-100">
                                <i class="fa-solid fa-file-csv me-1"></i> <?= backup_h($csvAction[1]) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong>Existing Backup Files</strong>
                <span class="text-muted small ms-2"><?= number_format(count($backupFiles)) ?> file(s)</span>
            </div>
            <form id="bulkDeleteForm" method="post" class="js-delete-confirm mb-0"
                  data-delete-title="Delete Selected Backups"
                  data-delete-message="Are you sure you want to delete the selected backup files? This action cannot be undone."
                  data-delete-item="Selected backup files"
                  data-delete-confirm-label="<i class='fa-solid fa-trash me-1'></i> Delete Selected">
                <input type="hidden" name="csrf_token" value="<?= backup_h($csrfToken) ?>">
                <input type="hidden" name="action" value="bulk_delete">
                <button class="btn btn-sm btn-outline-danger" <?= $backupFiles ? '' : 'disabled' ?>>
                    <i class="fa-solid fa-trash me-1"></i>Delete Selected
                </button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover backup-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="backup-check">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="selectAllBackups">
                                <label class="form-check-label small" for="selectAllBackups">Select All</label>
                            </div>
                        </th>
                        <th>File Name</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$backupFiles): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No backup files created yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($backupFiles as $file): ?>
                        <tr>
                            <td class="backup-check">
                                <input
                                    class="form-check-input backup-file-checkbox"
                                    type="checkbox"
                                    name="files[]"
                                    form="bulkDeleteForm"
                                    value="<?= backup_h($file['relative']) ?>"
                                    aria-label="Select <?= backup_h($file['name']) ?>"
                                >
                            </td>
                            <td>
                                <div class="fw-semibold"><?= backup_h($file['name']) ?></div>
                                <div class="text-muted small"><?= backup_h($file['relative']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= backup_h(ucfirst($file['type'])) ?></span></td>
                            <td><?= backup_h(backup_format_bytes((int)$file['size'])) ?></td>
                            <td><?= backup_h(date('M j, Y g:i A', (int)$file['created'])) ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="backup.php?action=download&amp;file=<?= urlencode($file['relative']) ?>&amp;csrf_token=<?= urlencode($csrfToken) ?>">
                                        <i class="fa-solid fa-download me-1"></i>Download
                                    </a>
                                    <form method="post" class="d-inline js-delete-confirm"
                                          data-delete-title="Delete Backup"
                                          data-delete-message="Are you sure you want to delete this backup file?"
                                          data-delete-item="<?= backup_h($file['name']) ?>"
                                          data-delete-confirm-label="<i class='fa-solid fa-trash me-1'></i> Delete Backup">
                                        <input type="hidden" name="csrf_token" value="<?= backup_h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="file" value="<?= backup_h($file['relative']) ?>">
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllBackups');
    const checkboxes = Array.from(document.querySelectorAll('.backup-file-checkbox'));
    const bulkForm = document.getElementById('bulkDeleteForm');

    function syncSelectAll() {
        if (!selectAll) return;
        const checkedCount = checkboxes.filter(cb => cb.checked).length;
        selectAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            syncSelectAll();
        });
    }

    checkboxes.forEach(cb => cb.addEventListener('change', syncSelectAll));

    if (bulkForm) {
        bulkForm.addEventListener('submit', function(event) {
            const selected = checkboxes.some(cb => cb.checked);
            if (!selected) {
                event.preventDefault();
                alert('No backup file was selected.');
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
