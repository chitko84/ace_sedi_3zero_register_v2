<?php
// admin/delete_club.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// --- Validate id ---
$cid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cid <= 0) {
    $_SESSION['error'] = "Invalid club ID.";
    header('Location: manage_clubs.php');
    exit();
}

// --- Helpers ---
function normalize_fs_path(?string $dbPath): ?string {
    if (!$dbPath) return null;
    // Remove a leading ../ once and any leading slash
    $p = ltrim($dbPath, '/');
    $p = preg_replace('#^\.\./#', '', $p);

    // Expect paths like 'uploads/...'
    if (strpos($p, 'uploads/') !== 0) {
        // unexpected path; refuse for safety
        return null;
    }

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) return null;

    $rel = substr($p, strlen('uploads/')); // e.g. 'events/photo.jpg'
    $abs = $uploadsRoot . DIRECTORY_SEPARATOR . $rel;

    // Ensure still inside uploads
    $absDir = realpath(dirname($abs));
    if ($absDir === false) return $abs; // parent might not exist; allow best-effort unlink
    if (strpos($absDir, $uploadsRoot) !== 0) return null;

    return $abs;
}

$conn->begin_transaction();

try {
    // Confirm club exists (grab name for nicer messaging)
    $chk = $conn->prepare("SELECT id, group_name FROM clubs WHERE id = ? LIMIT 1");
    $chk->bind_param("i", $cid);
    $chk->execute();
    $club = $chk->get_result()->fetch_assoc();

    if (!$club) {
        $conn->rollback();
        $_SESSION['error'] = "Club not found.";
        header('Location: manage_clubs.php');
        exit();
    }

    // -------- Collect files to delete (BEFORE DB delete) --------
    $eventPhotos = [];       // [ ['id'=>..., 'file_path'=>...], ... ]
    $ep = $conn->prepare("
        SELECT ep.id, ep.file_path
        FROM event_photos ep
        JOIN events e ON e.id = ep.event_id
        WHERE e.club_id = ?
    ");
    $ep->bind_param("i", $cid);
    $ep->execute();
    $epr = $ep->get_result();
    while ($row = $epr->fetch_assoc()) $eventPhotos[] = $row;

    $achievementPhotos = [];
    $ap = $conn->prepare("
        SELECT ap.id, ap.file_path
        FROM achievement_photos ap
        JOIN achievements a ON a.id = ap.achievement_id
        WHERE a.club_id = ?
    ");
    $ap->bind_param("i", $cid);
    $ap->execute();
    $apr = $ap->get_result();
    while ($row = $apr->fetch_assoc()) $achievementPhotos[] = $row;

    // -------- Delete dependents in safe order --------
    // 1) event_photos (for this club's events)
    $delEP = $conn->prepare("
        DELETE ep FROM event_photos ep
        JOIN events e ON e.id = ep.event_id
        WHERE e.club_id = ?
    ");
    $delEP->bind_param("i", $cid);
    $delEP->execute();
    $deleted_event_photos = $delEP->affected_rows;

    // 2) events
    $delE = $conn->prepare("DELETE FROM events WHERE club_id = ?");
    $delE->bind_param("i", $cid);
    $delE->execute();
    $deleted_events = $delE->affected_rows;

    // 3) achievement_photos
    $delAP = $conn->prepare("
        DELETE ap FROM achievement_photos ap
        JOIN achievements a ON a.id = ap.achievement_id
        WHERE a.club_id = ?
    ");
    $delAP->bind_param("i", $cid);
    $delAP->execute();
    $deleted_achievement_photos = $delAP->affected_rows;

    // 4) achievements
    $delA = $conn->prepare("DELETE FROM achievements WHERE club_id = ?");
    $delA->bind_param("i", $cid);
    $delA->execute();
    $deleted_achievements = $delA->affected_rows;

    // 5) projects
    $delP = $conn->prepare("DELETE FROM projects WHERE club_id = ?");
    $delP->bind_param("i", $cid);
    $delP->execute();
    $deleted_projects = $delP->affected_rows;

    // 6) club_members
    $delM = $conn->prepare("DELETE FROM club_members WHERE club_id = ?");
    $delM->bind_param("i", $cid);
    $delM->execute();
    $deleted_members = $delM->affected_rows;

    // 7) the club itself
    $delC = $conn->prepare("DELETE FROM clubs WHERE id = ? LIMIT 1");
    $delC->bind_param("i", $cid);
    $delC->execute();

    if ($delC->affected_rows < 1) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to delete club.";
        header('Location: manage_clubs.php');
        exit();
    }

    // All good — commit
    $conn->commit();

    // -------- Best-effort delete files from disk (AFTER commit) --------
    foreach ($eventPhotos as $ph) {
        $abs = normalize_fs_path($ph['file_path'] ?? null);
        if ($abs && is_file($abs)) { @unlink($abs); }
    }
    foreach ($achievementPhotos as $ph) {
        $abs = normalize_fs_path($ph['file_path'] ?? null);
        if ($abs && is_file($abs)) { @unlink($abs); }
    }

    // Flash message summary
    $_SESSION['success'] =
        "Deleted club \"{$club['group_name']}\" (ID #{$club['id']}). ".
        "Removed {$deleted_members} member(s), {$deleted_projects} project(s), ".
        "{$deleted_achievements} achievement(s) (+ {$deleted_achievement_photos} photo(s)), ".
        "{$deleted_events} event(s) (+ {$deleted_event_photos} photo(s)).";

    header('Location: manage_clubs.php');
    exit();

} catch (Throwable $e) {
    if ($conn->errno) {
        $conn->rollback();
    }
    $_SESSION['error'] = "An error occurred while deleting the club.";
    header('Location: manage_clubs.php');
    exit();
}
