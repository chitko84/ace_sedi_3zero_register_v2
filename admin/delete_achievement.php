<?php
// admin/delete_achievement.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin gate (redirect before any output)
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Normalize DB file_path to filesystem path under ../uploads/achievements/
function fs_ach_path(?string $p): ?string {
    if (!$p) return null;
    $p = trim($p);

    // External URL? we won't delete external resources
    if (preg_match('~^https?://~i', $p)) return null;

    // Strip leading ../ for filesystem join
    $p = ltrim($p, '/');

    // If starts with uploads/achievements/ keep it; else assume it is a bare filename
    if (strpos($p, 'uploads/achievements/') !== 0) {
        $p = 'uploads/achievements/' . basename($p);
    }

    // Build absolute path relative to /admin/
    $root = realpath(__DIR__ . '/..'); // project root
    if ($root === false) return null;
    return $root . DIRECTORY_SEPARATOR . $p;
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// Achievement ID
$aid = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
if ($aid <= 0) {
    $_SESSION['error'] = "Invalid achievement ID.";
    header('Location: manage_achievements.php');
    exit();
}

// Load achievement for display (title + club)
$sql = "SELECT a.id, a.title, a.club_id, c.group_name
        FROM achievements a
        JOIN clubs c ON c.id = a.club_id
        WHERE a.id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $aid);
$stmt->execute();
$achievement = $stmt->get_result()->fetch_assoc();

if (!$achievement) {
    $_SESSION['error'] = "Achievement not found.";
    header('Location: manage_achievements.php');
    exit();
}

// Handle POST (confirm delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid session token. Please try again.";
        header('Location: manage_achievements.php');
        exit();
    }

    // Collect photo file paths before deletion
    $files = [];
    $ps = $conn->prepare("SELECT file_path FROM achievement_photos WHERE achievement_id = ?");
    $ps->bind_param("i", $aid);
    $ps->execute();
    $pr = $ps->get_result();
    while ($row = $pr->fetch_assoc()) {
        $fsPath = fs_ach_path($row['file_path'] ?? '');
        if ($fsPath) $files[] = $fsPath;
    }

    // Transaction: delete photos rows then achievement
    $conn->begin_transaction();
    try {
        $dp = $conn->prepare("DELETE FROM achievement_photos WHERE achievement_id = ?");
        $dp->bind_param("i", $aid);
        $dp->execute();

        $da = $conn->prepare("DELETE FROM achievements WHERE id = ? LIMIT 1");
        $da->bind_param("i", $aid);
        $da->execute();

        $conn->commit();

        // Best-effort file removal AFTER commit
        foreach ($files as $f) {
            if (@is_file($f)) { @unlink($f); }
        }

        $_SESSION['success'] = "Achievement \"{$achievement['title']}\" was deleted.";
        header('Location: manage_achievements.php');
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to delete achievement. Please try again.";
        header('Location: manage_achievements.php');
        exit();
    }
}

// From here, safe to render confirmation page
require_once __DIR__ . '/header.php';
?>
<main class="main-content container-fluid">
    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_achievements.php">Manage Achievements</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Delete Achievement</li>
                </ol>
            </nav>
            <a href="manage_achievements.php" class="btn btn-light">
                <i class="fa-solid fa-arrow-left me-1"></i> Back
            </a>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Deletion</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        You are about to delete the achievement:
                    </p>
                    <ul class="list-unstyled mb-4">
                        <li><strong>Title:</strong> <?= h($achievement['title']) ?></li>
                        <li><strong>Club:</strong> <?= h($achievement['group_name']) ?></li>
                        <li class="text-danger mt-2"><strong>Note:</strong> This will permanently remove the achievement and all associated photos.</li>
                    </ul>

                    <form method="post" class="js-delete-confirm"
                          data-delete-title="Delete Achievement"
                          data-delete-message="Delete this achievement and all associated photos?"
                          data-delete-item="<?= h($achievement['title']) ?>"
                          data-delete-confirm-label="<i class='fa-solid fa-trash me-1'></i> Delete Achievement">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="id" value="<?= (int)$achievement['id'] ?>">

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-danger">
                                <i class="fa-solid fa-trash me-1"></i> Yes, delete it
                            </button>
                            <a href="view_achievement.php?id=<?= (int)$achievement['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-white">
                    <small class="text-muted">This action cannot be undone.</small>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
