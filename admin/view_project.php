<?php
// admin/view_project.php
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
function statusBadgeClass($s){
    switch ($s) {
        case 'completed':   return 'success';
        case 'in_progress': return 'primary';
        case 'planning':    return 'secondary';
        case 'on_hold':     return 'warning';
        default:            return 'dark';
    }
}

$pid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pid <= 0) {
    $_SESSION['error'] = "Invalid project ID.";
    header('Location: manage_projects.php');
    exit();
}

// Fetch project with club + creator (if any)
$sql = "
SELECT
    p.id, p.club_id, p.project_name, p.description, p.objectives,
    p.start_date, p.end_date, p.status,
    p.created_by, p.created_at, p.updated_at,
    c.group_name AS club_name,
    u.name AS creator_name, u.email AS creator_email
FROM projects p
JOIN clubs c   ON c.id = p.club_id
LEFT JOIN users u ON u.id = p.created_by
WHERE p.id = ?
LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $pid);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    $_SESSION['error'] = "Project not found.";
    header('Location: manage_projects.php');
    exit();
}

// Fetch project photos
$photos = [];
$photos_sql = "SELECT file_path, original_name FROM activity_photos WHERE activity_id = ? ORDER BY id ASC";
$photos_stmt = $conn->prepare($photos_sql);
$photos_stmt->bind_param("i", $pid);
$photos_stmt->execute();
$photos_result = $photos_stmt->get_result();
while ($photo = $photos_result->fetch_assoc()) {
    $photos[] = $photo;
}

// Now safe to print HTML
require_once __DIR__ . '/header.php';

// Niceties
$sd = $project['start_date'] ? date('M j, Y', strtotime($project['start_date'])) : '—';
$ed = $project['end_date']   ? date('M j, Y', strtotime($project['end_date']))   : '—';
$createdAt = $project['created_at'] ? date('M j, Y g:i A', strtotime($project['created_at'])) : '—';
$updatedAt = $project['updated_at'] ? date('M j, Y g:i A', strtotime($project['updated_at'])) : '—';
?>
<main class="main-content container-fluid">
    <style>
        /* Responsive definition list */
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
        
        /* Photo gallery styles */
        .photo-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 8px;
        }
        .photo-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .photo-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .photo-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            cursor: pointer;
        }
        .photo-name {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.7));
            color: white;
            padding: 8px 6px 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .photo-item:hover .photo-name {
            opacity: 1;
        }
        
        /* Photo modal styles */
        .photo-modal .modal-dialog {
            max-width: 90vw;
            max-height: 90vh;
        }
        .photo-modal .modal-content {
            background: transparent;
            border: none;
        }
        .photo-modal .modal-body {
            padding: 0;
            text-align: center;
        }
        .photo-modal img {
            max-width: 100%;
            max-height: 80vh;
            object-fit: contain;
        }
        .photo-modal .modal-header {
            background: rgba(0,0,0,0.7);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .photo-modal .modal-header .btn-close {
            filter: invert(1);
        }
        
        /* No photos state */
        .no-photos {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }
        .no-photos i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>

    <!-- Photo Modal -->
    <div class="modal fade photo-modal" id="photoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title text-white" id="photoModalLabel">Photo Preview</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="modalPhoto" src="" alt="Project photo">
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-center">
        <div class="col-12 col-lg-8">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_projects.php">Manage Projects</a></li>
                    <li class="breadcrumb-item active" aria-current="page">View Project</li>
                </ol>
            </nav>
            <h2 class="mb-0">
                <?= h($project['project_name']) ?>
                <small class="text-muted">(#<?= (int)$project['id'] ?>)</small>
            </h2>
            <div class="mt-2">
                <span class="badge bg-<?= statusBadgeClass($project['status']) ?>">
                    <?= h(ucfirst(str_replace('_',' ', $project['status']))) ?>
                </span>
                <span class="badge bg-light text-dark ms-2">
                    Club: <?= h($project['club_name']) ?>
                </span>
                <?php if (!empty($photos)): ?>
                    <span class="badge bg-info ms-2">
                        <i class="fa-solid fa-images me-1"></i><?= count($photos) ?> photo<?= count($photos) !== 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-12 col-lg-4 text-lg-end">
            <div class="btn-group">
                <a href="delete_project.php?id=<?= (int)$project['id'] ?>" class="btn btn-outline-danger"
                   onclick="return confirm('Delete this project? This action cannot be undone.');">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </a>
                <a href="manage_projects.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <!-- Overview -->
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-circle-info me-2"></i>Overview</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($project['description'])): ?>
                        <div class="mb-3">
                            <div class="text-muted small mb-1">Description</div>
                            <p class="mb-0"><?= nl2br(h($project['description'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($project['objectives'])): ?>
                        <div>
                            <div class="text-muted small mb-1">Objectives</div>
                            <p class="mb-0"><?= nl2br(h($project['objectives'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($project['description']) && empty($project['objectives'])): ?>
                        <p class="text-muted mb-0">No description or objectives provided.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Photo Gallery -->
            <?php if (!empty($photos)): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fa-solid fa-images me-2"></i>Project Photos</h5>
                    <span class="badge bg-primary"><?= count($photos) ?></span>
                </div>
                <div class="card-body">
                    <div class="photo-gallery">
                        <?php foreach ($photos as $index => $photo): ?>
                            <div class="photo-item">
                                <img src="../<?= h($photo['file_path']) ?>" 
                                     alt="Project photo <?= $index + 1 ?>"
                                     onclick="openPhotoModal('../<?= h($photo['file_path']) ?>', '<?= h($photo['original_name']) ?>')">
                                <div class="photo-name"><?= h($photo['original_name']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-images me-2"></i>Project Photos</h5>
                </div>
                <div class="card-body">
                    <div class="no-photos">
                        <i class="fa-regular fa-image"></i>
                        <p class="mb-0">No photos uploaded for this project</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-regular fa-calendar me-2"></i>Timeline</h5>
                </div>
                <div class="card-body">
                    <dl class="row meta-grid mb-0">
                        <dt class="col-sm-4 col-md-3">Start Date</dt>
                        <dd class="col-sm-8 col-md-9"><?= h($sd) ?></dd>

                        <dt class="col-sm-4 col-md-3">End Date</dt>
                        <dd class="col-sm-8 col-md-9"><?= h($ed) ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Meta -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-list me-2"></i>Details</h5>
                </div>
                <div class="card-body">
                    <dl class="row meta-grid mb-0">
                        <dt class="col-sm-5">Club</dt>
                        <dd class="col-sm-7">
                            <a href="club_details.php?id=<?= (int)$project['club_id'] ?>">
                                <?= h($project['club_name']) ?>
                            </a>
                        </dd>

                        <dt class="col-sm-5">Created By</dt>
                        <dd class="col-sm-7">
                            <?php if (!empty($project['creator_name'])): ?>
                                <?= h($project['creator_name']) ?>
                                <div class="text-muted small"><?= h($project['creator_email']) ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Created</dt>
                        <dd class="col-sm-7"><?= h($createdAt) ?></dd>

                        <dt class="col-sm-5">Updated</dt>
                        <dd class="col-sm-7"><?= h($updatedAt) ?></dd>
                    </dl>
                </div>
            </div>

            <div class="card stat-card mt-3">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted">Status</div>
                        <div class="h5 mb-0"><?= h(ucfirst(str_replace('_',' ', $project['status']))) ?></div>
                    </div>
                    <i class="fa-solid fa-diagram-project fa-2x text-primary"></i>
                </div>
            </div>

            <?php if (!empty($photos)): ?>
            <div class="card stat-card mt-3" style="background: linear-gradient(135deg, #17a2b8, #138496); color: white;">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-white-50">Photos</div>
                        <div class="h5 mb-0"><?= count($photos) ?></div>
                    </div>
                    <i class="fa-solid fa-camera fa-2x text-white"></i>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
// Photo modal functionality
function openPhotoModal(photoSrc, photoName) {
    const modal = new bootstrap.Modal(document.getElementById('photoModal'));
    const modalImg = document.getElementById('modalPhoto');
    const modalTitle = document.getElementById('photoModalLabel');
    
    modalImg.src = photoSrc;
    modalImg.alt = photoName;
    modalTitle.textContent = photoName || 'Project Photo';
    
    modal.show();
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = bootstrap.Modal.getInstance(document.getElementById('photoModal'));
        if (modal) {
            modal.hide();
        }
    }
});

// Close modal when clicking on modal background
document.getElementById('photoModal').addEventListener('click', function(e) {
    if (e.target === this) {
        const modal = bootstrap.Modal.getInstance(this);
        if (modal) {
            modal.hide();
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>