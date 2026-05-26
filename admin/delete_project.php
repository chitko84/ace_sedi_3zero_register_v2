<?php
// admin/delete_project.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// --- Validate id ---
$pid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pid <= 0) {
    $_SESSION['error'] = "Invalid project ID.";
    header('Location: manage_projects.php');
    exit();
}

$conn->begin_transaction();

try {
    // Confirm project exists; fetch some context for the message
    $chk = $conn->prepare("
        SELECT p.id, p.project_name, c.group_name
        FROM projects p
        JOIN clubs c ON c.id = p.club_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $chk->bind_param("i", $pid);
    $chk->execute();
    $project = $chk->get_result()->fetch_assoc();

    if (!$project) {
        $conn->rollback();
        $_SESSION['error'] = "Project not found.";
        header('Location: manage_projects.php');
        exit();
    }

    // If you add dependent rows later (e.g., project_photos), delete them here first.

    // Delete the project itself
    $del = $conn->prepare("DELETE FROM projects WHERE id = ? LIMIT 1");
    $del->bind_param("i", $pid);
    $del->execute();

    if ($del->affected_rows < 1) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to delete project.";
        header('Location: manage_projects.php');
        exit();
    }

    $conn->commit();

    $_SESSION['success'] = "Deleted project \"{$project['project_name']}\" (ID #{$project['id']}) from club \"{$project['group_name']}\".";
    header('Location: manage_projects.php');
    exit();

} catch (Throwable $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    $_SESSION['error'] = "An error occurred while deleting the project.";
    header('Location: manage_projects.php');
    exit();
}
