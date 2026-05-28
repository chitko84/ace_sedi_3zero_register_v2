<?php
// admin/view_achievement.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function badgeClass(){ return 'primary'; } // simple accent for header chips

// Normalize DB file_path to ../uploads/achievements/<file>
function normalize_ach_path(?string $p): ?string {
    if (!$p) return null;
    $p = trim($p);

    // External URL? leave as-is
    if (preg_match('~^https?://~i', $p)) return $p;

    // Already correct
    if (strpos($p, '../uploads/achievements/') === 0) return $p;

    // Remove any leading slash
    $p = ltrim($p, '/');

    // If it already begins with uploads/achievements/, just prefix ../
    if (strpos($p, 'uploads/achievements/') === 0) return '../' . $p;

    // If it's just a filename or other relative path, route it to uploads/achievements/
    return '../uploads/achievements/' . basename($p);
}

$aid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($aid <= 0) {
    $_SESSION['error'] = "Invalid achievement ID.";
    header('Location: manage_achievements.php');
    exit();
}

// Fetch achievement with club + creator
$sql = "
SELECT
    a.id, a.club_id, a.title, a.description, a.achieved_on, a.created_by, a.created_at,
    c.group_name AS club_name,
    u.name AS creator_name, u.email AS creator_email
FROM achievements a
JOIN clubs c   ON c.id = a.club_id
LEFT JOIN users u ON u.id = a.created_by
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

// Fetch photos
$photos = [];
$ps = $conn->prepare("SELECT id, file_path, original_name FROM achievement_photos WHERE achievement_id = ? ORDER BY id ASC");
$ps->bind_param("i", $aid);
$ps->execute();
$pr = $ps->get_result();
while ($row = $pr->fetch_assoc()) $photos[] = $row;

// Now safe to print HTML
require_once __DIR__ . '/header.php';

// Niceties
$achievedOn = $achievement['achieved_on'] ? date('M j, Y', strtotime($achievement['achieved_on'])) : '—';
$createdAt  = $achievement['created_at']  ? date('M j, Y g:i A', strtotime($achievement['created_at'])) : '—';
?>
<main class="main-content container-fluid">
    <style>
        .meta-grid dt { width: 180px; color:#0f2f47; }
        .meta-grid dd { margin-left: 0; }
        @media (max-width: 767.98px) {
            .meta-grid dt { width: 100%; font-weight: 600; margin-top:.5rem; }
            .meta-grid dd { margin-bottom:.35rem; }
        }
        .stat-card {
            border:0; border-radius:12px; box-shadow:0 4px 18px rgba(0,0,0,.08); background:white; position:relative; overflow:hidden;
        }
        .stat-card::before {
            content:''; position:absolute; top:0; left:0; right:0; height:4px;
            background: linear-gradient(90deg, #1a5276, #154360);
        }
        .ach-img {
            width:100%; height:220px; object-fit:cover; border-radius:10px; border:1px solid #eef2f7;
        }
        @media (max-width: 575.98px){
            .ach-img { height: 180px; }
        }
        .empty { color: #6c757d; font-style: italic; }
    </style>

    <div class="row g-3 align-items-center">
        <div class="col-12 col-lg-8">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_achievements.php">Manage Achievements</a></li>
                    <li class="breadcrumb-item active" aria-current="page">View Achievement</li>
                </ol>
            </nav>
            <h2 class="mb-0">
                <?= h($achievement['title']) ?>
                <small class="text-muted">(#<?= (int)$achievement['id'] ?>)</small>
            </h2>
            <div class="mt-2">
                <span class="badge bg-<?= badgeClass() ?>">Club: <?= h($achievement['club_name']) ?></span>
                <span class="badge bg-light text-dark ms-2">Achieved On: <?= h($achievedOn) ?></span>
            </div>
        </div>
        <div class="col-12 col-lg-4 text-lg-end">
            <div class="btn-group">
                <a href="edit_achievement.php?id=<?= (int)$achievement['id'] ?>" class="btn btn-primary">
                    <i class="fa-solid fa-pen me-1"></i> Edit
                </a>
                <a href="delete_achievement.php?id=<?= (int)$achievement['id'] ?>" class="btn btn-outline-danger js-delete-confirm"
                   data-delete-title="Delete Achievement"
                   data-delete-message="Delete this achievement?"
                   data-delete-item="<?= h($achievement['title'] ?? ('Achievement #' . (int)$achievement['id'])) ?>"
                   data-delete-confirm-label="<i class='fa-solid fa-trash me-1'></i> Delete Achievement">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </a>
                <a href="manage_achievements.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <!-- Left: Overview + Gallery -->
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-circle-info me-2"></i>Overview</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($achievement['description'])): ?>
                        <p class="mb-0"><?= nl2br(h($achievement['description'])) ?></p>
                    <?php else: ?>
                        <p class="empty mb-0">No description provided.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-regular fa-images me-2"></i>Photos</h5>
                </div>
                <div class="card-body">
                    <?php if (!$photos): ?>
                        <div class="text-center py-3">
                            <i class="fa-regular fa-image fa-2x text-muted mb-2"></i>
                            <div class="empty">No photos uploaded for this achievement.</div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($photos as $p): ?>
                                <div class="col-12 col-sm-6">
                                    <img
                                        src="<?= h(normalize_ach_path($p['file_path'])) ?>"
                                        alt="<?= h($p['original_name'] ?: 'achievement') ?>"
                                        class="ach-img"
                                        onerror="this.closest('.col-sm-6').innerHTML='<div class=&quot;empty&quot;>Image not available.</div>';"
                                    >
                                    <?php if (!empty($p['original_name'])): ?>
                                        <div class="small text-muted mt-1"><?= h($p['original_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Meta -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-list me-2"></i>Details</h5>
                </div>
                <div class="card-body">
                    <dl class="row meta-grid mb-0">
                        <dt class="col-sm-5">Club</dt>
                        <dd class="col-sm-7">
                            <a href="club_details.php?id=<?= (int)$achievement['club_id'] ?>">
                                <?= h($achievement['club_name']) ?>
                            </a>
                        </dd>

                        <dt class="col-sm-5">Achieved On</dt>
                        <dd class="col-sm-7"><?= h($achievedOn) ?></dd>

                        <dt class="col-sm-5">Created By</dt>
                        <dd class="col-sm-7">
                            <?php if (!empty($achievement['creator_name'])): ?>
                                <?= h($achievement['creator_name']) ?>
                                <?php if (!empty($achievement['creator_email'])): ?>
                                    <div class="small text-muted"><?= h($achievement['creator_email']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Created</dt>
                        <dd class="col-sm-7"><?= h($createdAt) ?></dd>
                    </dl>
                </div>
            </div>

            <div class="card stat-card mt-3">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted">Photos</div>
                        <div class="h5 mb-0"><?= count($photos) ?></div>
                    </div>
                    <i class="fa-regular fa-images fa-2x text-primary"></i>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
