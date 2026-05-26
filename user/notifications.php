<?php
// Start session and check authentication BEFORE including header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Include database connection
include '../includes/db.php';

// Handle all redirects BEFORE any output
if (isset($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    $update_sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();

    $_SESSION['success'] = "Notification marked as read";
    header('Location: notifications.php');
    exit();
}

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $update_sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $_SESSION['success'] = "All notifications marked as read";
    header('Location: notifications.php');
    exit();
}

// Handle delete notification
if (isset($_GET['delete_notif'])) {
    $notif_id = (int)$_GET['delete_notif'];
    $delete_sql = "DELETE FROM notifications WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();

    $_SESSION['success'] = "Notification deleted";
    header('Location: notifications.php');
    exit();
}

// FOLLOW THE SAME LOGIC AS HEADER.PHP FOR MESSAGE COLUMN
$message_column = 'message'; // default to 'message'
$check_column = $conn->query("SHOW COLUMNS FROM notifications LIKE 'message'");
if ($check_column && $check_column->num_rows > 0) {
    $message_column = 'message';
} else {
    $check_column = $conn->query("SHOW COLUMNS FROM notifications LIKE 'body'");
    if ($check_column && $check_column->num_rows > 0) {
        $message_column = 'body';
    }
}

// Get ALL notifications for the user (NO LIMIT here)
$allNotifications = [];
$sql = "SELECT id, title, $message_column AS msg_text, type, COALESCE(is_read,0) AS is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $allNotifications[] = $row;
}

// Counts based on full set
$total_count  = count($allNotifications);
$unread_count = 0;
foreach ($allNotifications as $n) {
    if ((int)$n['is_read'] === 0) $unread_count++;
}

// NOW include header.php after all potential redirects
// header.php uses its own $notifications for the DROPDOWN only (limit 5).
// Our page uses $allNotifications, so there is no collision.
include 'header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">Notifications</h1>
            <p class="text-muted">Manage your notifications</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($unread_count > 0): ?>
                <form method="POST" class="d-inline">
                    <button type="submit" name="mark_all_read" class="btn btn-success">
                        <i class="fas fa-check-double me-2"></i>Mark All as Read
                    </button>
                </form>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <!-- Notification Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="h2 mb-0"><?= (int)$total_count ?></h3>
                            <p class="mb-0">Total Notifications</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-bell fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="h2 mb-0"><?= (int)$unread_count ?></h3>
                            <p class="mb-0">Unread Notifications</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-envelope fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="h2 mb-0"><?= (int)($total_count - $unread_count) ?></h3>
                            <p class="mb-0">Read Notifications</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notifications List -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">All Notifications</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($allNotifications)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h4>No Notifications</h4>
                    <p class="text-muted">You don't have any notifications yet.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($allNotifications as $notification): ?>
                        <div class="list-group-item notification-card <?= ((int)$notification['is_read'] === 0) ? 'unread' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start notification-row">
                                <div class="d-flex align-items-start gap-3 flex-grow-1 me-3">
                                    <?php
                                    $icon_class = 'text-primary';
                                    $icon = 'fas fa-info-circle';
                                    if (!empty($notification['type'])) {
                                        switch($notification['type']) {
                                            case 'success': $icon_class = 'text-success'; $icon = 'fas fa-check-circle'; break;
                                            case 'warning': $icon_class = 'text-warning'; $icon = 'fas fa-exclamation-triangle'; break;
                                            case 'error':   $icon_class = 'text-danger';  $icon = 'fas fa-times-circle'; break;
                                            default:        $icon_class = 'text-primary'; $icon = 'fas fa-info-circle';
                                        }
                                    }
                                    ?>
                                    <i class="<?= $icon ?> <?= $icon_class ?> mt-1" style="font-size: 1.2rem;"></i>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 <?= ((int)$notification['is_read'] === 0) ? 'fw-bold' : '' ?>">
                                            <?= htmlspecialchars($notification['title']) ?>
                                        </h6>
                                        <?php if (!empty($notification['msg_text'])): ?>
                                            <p class="mb-1 text-muted"><?= htmlspecialchars($notification['msg_text']) ?></p>
                                        <?php endif; ?>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i>
                                            <?= htmlspecialchars(date('M j, Y g:i A', strtotime($notification['created_at']))) ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="dropdown notification-actions">
                                    <button class="btn btn-sm btn-light dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php if ((int)$notification['is_read'] === 0): ?>
                                            <li>
                                                <a class="dropdown-item" href="notifications.php?mark_read=<?= (int)$notification['id'] ?>">
                                                    <i class="fas fa-check me-2"></i>Mark as Read
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        <li>
                                            <a class="dropdown-item text-danger" href="notifications.php?delete_notif=<?= (int)$notification['id'] ?>"
                                               onclick="return confirm('Are you sure you want to delete this notification?')">
                                                <i class="fas fa-trash me-2"></i>Delete
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Custom styles for better dropdown positioning */
.dropdown-menu { min-width: 160px; }
.list-group-item { padding: 1rem 1.25rem; }
.notification-card { border-left:4px solid transparent; }
.notification-card.unread { background:#f8fbff; border-left-color:#1a5276; }
.notification-card.unread h6::after { content:"Unread"; margin-left:.5rem; font-size:.68rem; color:#1a5276; background:#eaf4fb; border-radius:999px; padding:.15rem .45rem; vertical-align:middle; }
@media(max-width:767.98px){
    .notification-row { flex-direction:column; gap:.85rem; }
    .notification-actions { align-self:flex-end; }
    .card-body.p-0 { padding:.75rem!important; }
    .list-group-flush { display:grid; gap:.75rem; }
    .notification-card { border:1px solid #e9eef4!important; border-left:4px solid transparent!important; border-radius:10px!important; box-shadow:0 4px 14px rgba(15,47,71,.06); }
    .notification-card.unread { border-left-color:#1a5276!important; }
}
</style>

<?php include 'footer.php'; ?>
