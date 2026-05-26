<?php
// admin/delete_event.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// --- Validate id ---
$eid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eid <= 0) {
    $_SESSION['error'] = "Invalid event ID.";
    header('Location: manage_events.php');
    exit();
}

// --- Helpers ---
function normalize_fs_path(?string $dbPath): ?string {
    if (!$dbPath) return null;
    // Strip leading ../ and slashes
    $p = ltrim($dbPath, '/');
    $p = preg_replace('#^\.\./#', '', $p); // remove one ../ if present
    // Expect paths like 'uploads/...'
    if (strpos($p, 'uploads/') !== 0) {
        // If DB stored absolute or unexpected path, refuse for safety
        return null;
    }
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) return null;

    // Build absolute path under uploads root
    $rel = substr($p, strlen('uploads/')); // e.g. 'events/photo.jpg'
    $abs = $uploadsRoot . DIRECTORY_SEPARATOR . $rel;

    // Normalize and ensure it's still inside uploads/
    $absReal = realpath(dirname($abs));
    if ($absReal === false) {
        // Parent folder may not exist; still allow unlink attempt on $abs
        return $abs;
    }
    if (strpos($absReal, $uploadsRoot) !== 0) {
        return null; // safety: outside uploads
    }
    return $abs;
}

$conn->begin_transaction();

try {
    // Ensure event exists (and capture a name for nicer messaging)
    $chk = $conn->prepare("SELECT id, title FROM events WHERE id = ? LIMIT 1");
    $chk->bind_param("i", $eid);
    $chk->execute();
    $event = $chk->get_result()->fetch_assoc();

    if (!$event) {
        $conn->rollback();
        $_SESSION['error'] = "Event not found.";
        header('Location: manage_events.php');
        exit();
    }

    // Fetch photos (to delete files after DB deletion)
    $photos = [];
    $ps = $conn->prepare("SELECT id, file_path FROM event_photos WHERE event_id = ?");
    $ps->bind_param("i", $eid);
    $ps->execute();
    $pr = $ps->get_result();
    while ($row = $pr->fetch_assoc()) {
        $photos[] = $row;
    }

    // Delete photo rows
    $dp = $conn->prepare("DELETE FROM event_photos WHERE event_id = ?");
    $dp->bind_param("i", $eid);
    $dp->execute();

    // Delete event
    $de = $conn->prepare("DELETE FROM events WHERE id = ? LIMIT 1");
    $de->bind_param("i", $eid);
    $de->execute();

    if ($de->affected_rows < 1) {
        // Nothing deleted — rollback
        $conn->rollback();
        $_SESSION['error'] = "Failed to delete event.";
        header('Location: manage_events.php');
        exit();
    }

    $conn->commit();

    // Best-effort file removal (after commit)
    foreach ($photos as $ph) {
        $abs = normalize_fs_path($ph['file_path'] ?? null);
        if ($abs && file_exists($abs) && is_file($abs)) {
            @unlink($abs);
        }
    }

    $_SESSION['success'] = "Event \"{$event['title']}\" (ID #{$event['id']}) was deleted.";
    header('Location: manage_events.php');
    exit();

} catch (Throwable $e) {
    if ($conn->errno) { // if in transaction, rollback
        $conn->rollback();
    }
    $_SESSION['error'] = "An error occurred while deleting the event.";
    header('Location: manage_events.php');
    exit();
}
