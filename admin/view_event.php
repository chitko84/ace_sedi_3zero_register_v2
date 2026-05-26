<?php
// admin/view_event.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin gate
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function normalize_upload_path(?string $p): ?string {
    if (!$p) return null;
    $p = ltrim($p, '/');
    if (strpos($p, '../') === 0) return $p;              // already relative
    if (strpos($p, 'uploads/') === 0) return '../'.$p;   // DB keeps 'uploads/...'
    return '../'.$p;                                     // fallback
}
function statusBadgeClass($s){ return $s === 'finished' ? 'success' : 'warning'; }

// Get id
$eid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eid <= 0) {
    $_SESSION['error'] = "Invalid event ID.";
    header('Location: manage_events.php');
    exit();
}

// Fetch event + club
$sql = "SELECT
            e.id, e.club_id, e.title, e.description, e.start_date, e.end_date,
            e.start_time, e.end_time, e.status, e.created_by, e.created_at,
            c.group_name
        FROM events e
        JOIN clubs c ON c.id = e.club_id
        WHERE e.id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $eid);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

if (!$event) {
    $_SESSION['error'] = "Event not found.";
    header('Location: manage_events.php');
    exit();
}

// Optional: created_by details
$creator = null;
if (!empty($event['created_by'])) {
    $u = $conn->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
    $u->bind_param("i", $event['created_by']);
    $u->execute();
    $creator = $u->get_result()->fetch_assoc();
}

// Photos
$photos = [];
$ps = $conn->prepare("SELECT id, file_path, original_name, created_at FROM event_photos WHERE event_id = ? ORDER BY id ASC");
$ps->bind_param("i", $eid);
$ps->execute();
$pr = $ps->get_result();
while ($row = $pr->fetch_assoc()) {
    $row['file_path'] = normalize_upload_path($row['file_path']);
    $photos[] = $row;
}

// From here it is safe to output
require_once __DIR__ . '/header.php';

// Pre-format times
$startDate = $event['start_date'] ? date('M j, Y', strtotime($event['start_date'])) : '—';
$endDate   = $event['end_date']   ? date('M j, Y', strtotime($event['end_date']))   : '—';
$startTime = (!empty($event['start_time']) && $event['start_time'] !== '00:00:00') ? date('g:i A', strtotime($event['start_time'])) : null;
$endTime   = (!empty($event['end_time'])   && $event['end_time']   !== '00:00:00') ? date('g:i A', strtotime($event['end_time']))   : null;
$createdAt = $event['created_at'] ? date('M j, Y g:i A', strtotime($event['created_at'])) : '—';
?>
<main class="main-content container-fluid">
    <style>
        .kv { display:flex; gap:.5rem; }
        .kv .k { min-width: 160px; color:#475569; font-weight:600; }
        .kv .v { flex:1; }
        @media (max-width: 575.98px){
            .kv { flex-direction:column; }
            .kv .k { min-width: auto; }
        }
        .photo-tile {
            border-radius: 10px; overflow:hidden;
            border: 1px solid #e9ecef; background:#fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.03);
        }
        .photo-tile img { width:100%; height:160px; object-fit:cover; display:block; }
    </style>

    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_events.php">Manage Events</a></li>
                    <li class="breadcrumb-item active" aria-current="page">View Event</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="manage_events.php" class="btn btn-light"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
                <a href="delete_event.php?id=<?= (int)$event['id'] ?>" class="btn btn-outline-danger"
                   onclick="return confirm('Delete this event? This action cannot be undone.');">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </a>
            </div>
        </div>

        <!-- Title + Status -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                        <div>
                            <h3 class="mb-1"><?= h($event['title']) ?></h3>
                            <div class="text-muted small">
                                Event #<?= (int)$event['id'] ?> • Created <?= h($createdAt) ?>
                            </div>
                        </div>
                        <div>
                            <span class="badge bg-<?= statusBadgeClass($event['status']) ?> px-3 py-2">
                                <?= h(ucfirst($event['status'])) ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($event['description'])): ?>
                        <hr>
                        <p class="mb-0"><?= nl2br(h($event['description'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Details -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <strong>Event Details</strong>
                </div>
                <div class="card-body">
                    <div class="kv mb-2">
                        <div class="k">Club</div>
                        <div class="v">
                            <a class="text-decoration-none" href="club_details.php?id=<?= (int)$event['club_id'] ?>">
                                <i class="fa-solid fa-people-group me-1"></i><?= h($event['group_name']) ?>
                            </a>
                        </div>
                    </div>

                    <div class="kv mb-2">
                        <div class="k">Start</div>
                        <div class="v">
                            <?= h($startDate) ?><?= $startTime ? ' • '.h($startTime) : '' ?>
                        </div>
                    </div>

                    <div class="kv mb-2">
                        <div class="k">End</div>
                        <div class="v">
                            <?= h($endDate) ?><?= $endTime ? ' • '.h($endTime) : '' ?>
                        </div>
                    </div>

                    <div class="kv mb-2">
                        <div class="k">Created By</div>
                        <div class="v">
                            <?php if ($creator): ?>
                                <?= h($creator['name'] ?? 'User #'.(int)$event['created_by']) ?>
                                <span class="text-muted">(<?= h($creator['email'] ?? '') ?>)</span>
                            <?php else: ?>
                                User #<?= (int)$event['created_by'] ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="kv">
                        <div class="k">Created At</div>
                        <div class="v"><?= h($createdAt) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Photos -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Photos</strong>
                    <span class="badge bg-light text-dark"><?= count($photos) ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$photos): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fa-regular fa-image fa-2x mb-2"></i>
                            <div>No photos uploaded for this event.</div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($photos as $ph): ?>
                                <div class="col-6">
                                    <div class="photo-tile">
                                        <a href="<?= h($ph['file_path']) ?>" target="_blank" rel="noopener">
                                            <img src="<?= h($ph['file_path']) ?>" alt="<?= h($ph['original_name'] ?: 'Event photo') ?>"
                                                 onerror="this.closest('.photo-tile').innerHTML='<div class=&quot;p-3 text-center text-muted&quot;>Image missing</div>';">
                                        </a>
                                        <div class="p-2 small text-truncate">
                                            <?= h($ph['original_name'] ?: 'photo_'.$ph['id']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
