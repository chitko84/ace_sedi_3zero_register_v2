<?php
// admin/delete_user.php
// --- DO NOT OUTPUT ANYTHING BEFORE HEADERS ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin gate
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = (int)$_SESSION['user_id'];

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// CSRF setup
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// Get target id
$uid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($uid <= 0) {
    $_SESSION['error'] = 'Invalid user ID.';
    header('Location: manage_users.php');
    exit();
}

// Don’t allow deleting your own account
if ($uid === $admin_id) {
    $_SESSION['error'] = "You can't delete your own account while logged in.";
    header('Location: view_user.php?id='.$uid);
    exit();
}

// Load target user (for confirmation screen)
$sql = "SELECT id, name, email, role, profile_pic, created_at FROM users WHERE id = ? LIMIT 1";
$st  = $conn->prepare($sql);
$st->bind_param('i', $uid);
$st->execute();
$target = $st->get_result()->fetch_assoc();

if (!$target) {
    $_SESSION['error'] = 'User not found.';
    header('Location: manage_users.php');
    exit();
}

// Handle POST delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid session token. Please try again.';
        header('Location: view_user.php?id='.$uid);
        exit();
    }

    // Optional: double-check prevent self-delete (in case of crafted POST)
    if ($uid === $admin_id) {
        $_SESSION['error'] = "You can't delete your own account while logged in.";
        header('Location: view_user.php?id='.$uid);
        exit();
    }

    // Perform delete
    $sd = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
    $sd->bind_param('i', $uid);

    if ($sd->execute()) {
        $_SESSION['success'] = 'User deleted successfully.';
        header('Location: manage_users.php');
        exit();
    } else {
        $_SESSION['error'] = 'Delete failed. Please try again.';
        header('Location: view_user.php?id='.$uid);
        exit();
    }
}

// From here we can output HTML safely
require_once __DIR__ . '/header.php';

$profilePhoto = !empty($target['profile_pic']) ? $target['profile_pic'] : '../uploads/default-profile.jpg';
?>
<main class="main-content container-fluid">
    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_users.php">Manage Users</a></li>
                    <li class="breadcrumb-item"><a href="view_user.php?id=<?= (int)$target['id'] ?>">View User</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Delete User</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="view_user.php?id=<?= (int)$target['id'] ?>" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Delete</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <img src="<?= h($profilePhoto) ?>" onerror="this.src='../uploads/default-profile.jpg'"
                             class="rounded-circle" alt="Profile" style="width:72px;height:72px;object-fit:cover;border:2px solid #e9ecef;">
                        <div>
                            <div class="fw-semibold"><?= h($target['name']) ?> <small class="text-muted">(#<?= (int)$target['id'] ?>)</small></div>
                            <div class="text-muted small"><?= h($target['email']) ?></div>
                            <span class="badge <?= ($target['role']==='admin') ? 'bg-danger' : 'bg-secondary' ?> mt-1">
                                <?= h(ucfirst($target['role'])) ?>
                            </span>
                        </div>
                    </div>

                    <div class="alert alert-warning d-flex align-items-start">
                        <i class="fa-solid fa-circle-info me-2 mt-1"></i>
                        <div>
                            <strong>This action is permanent.</strong> The user account will be removed from the system.
                            <?php /* If you later add FKs to other tables, note any cascade effects here. */ ?>
                        </div>
                    </div>

                    <form method="post" class="d-flex justify-content-end gap-2">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <a href="view_user.php?id=<?= (int)$target['id'] ?>" class="btn btn-light">Cancel</a>
                        <button type="submit" class="btn btn-danger">
                            <i class="fa-solid fa-trash me-1"></i> Delete User
                        </button>
                    </form>
                </div>
                <div class="card-footer bg-white">
                    <small class="text-muted">
                        Created:
                        <?php if (!empty($target['created_at'])): ?>
                            <?= h(date('M j, Y g:i A', strtotime($target['created_at']))) ?>
                        <?php else: ?>
                            <em>Unknown</em>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
