<?php
// achievements.php (LOGGED-IN USERS VIEW WITH APPROVAL SYSTEM)
// Complete updated version with layout fixes & real rejection reason.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';
require_once __DIR__ . '/../includes/image_upload_helper.php';

// Require auth
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Fetch email of logged in user
$user_stmt = $conn->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();
$user_email = $user ? $user['email'] : '';

/* ---------------------------------------------------
   Fetch Clubs the User Belongs To
--------------------------------------------------- */
$clubs = [];
$stmt = $conn->prepare("
    SELECT DISTINCT c.*, cm.member_type
    FROM clubs c
    JOIN club_members cm ON c.id = cm.club_id
    WHERE cm.email = ?
    ORDER BY c.group_name ASC
");
$stmt->bind_param("s", $user_email);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clubs[] = $row;
$stmt->close();

/* ---------------------------------------------------
   Helper Functions
--------------------------------------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normalize_upload_path(string $p): string {
    $p = str_replace('\\','/',$p);
    $p = preg_replace('~^(\.\./)+~', '', $p);
    $p = ltrim($p,'/');
    if (strpos($p, 'uploads/') !== 0) $p = 'uploads/' . $p;
    return $p;
}

/* ---------------------------------------------------
   Handle Add / Edit / Delete
--------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* -----------------------------------------------
       ADD
    -------------------------------------------------- */
    if (isset($_POST['add_achievement'])) {
        $club_id = (int)($_POST['club_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $achieved_on = $_POST['achieved_on'] ?? null;

        // Validate membership
        $chk = $conn->prepare("SELECT 1 FROM club_members WHERE club_id=? AND email=? LIMIT 1");
        $chk->bind_param("is", $club_id, $user_email);
        $chk->execute();
        $has_access = $chk->get_result()->num_rows > 0;
        $chk->close();

        if (!$has_access) {
            $_SESSION['error'] = "You don't have permission to add achievements for this club.";
            header("Location: achievements.php");
            exit();
        }

        if ($title === '' || $description === '' || !$achieved_on) {
            $_SESSION['error'] = "All fields are required.";
            header("Location: achievements.php");
            exit();
        }

        // Validate Photos
        $files = $_FILES['photos'] ?? null;
        $fileCount = 0; $totalBytes = 0;
        if ($files && is_array($files['name'])) {
            for ($i=0;$i<count($files['name']);$i++) {
                if ($files['error'][$i]===UPLOAD_ERR_OK && $files['size'][$i]>0) {
                    if ((int)$files['size'][$i] > IMAGE_UPLOAD_MAX_BYTES) {
                        $_SESSION['error'] = IMAGE_UPLOAD_SIZE_ERROR;
                        header("Location: achievements.php");
                        exit();
                    }
                    $fileCount++;
                    $totalBytes += (int)$files['size'][$i];
                }
            }
        }

        if ($fileCount < 1 || $fileCount > 3) {
            $_SESSION['error'] = "Please upload 1–3 photos or Your image is too Big. Please try to compress your image/s here (https://imagecompressor.com/)";
            header("Location: achievements.php");
            exit();
        }
        if ($totalBytes > 3 * IMAGE_UPLOAD_MAX_BYTES) {
            $_SESSION['error'] = IMAGE_UPLOAD_SIZE_ERROR;
            header("Location: achievements.php");
            exit();
        }

        // Insert Achievement (pending)
        $ins = $conn->prepare("
            INSERT INTO achievements (club_id, title, description, achieved_on, created_by, approval_status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $ins->bind_param("isssi", $club_id, $title, $description, $achieved_on, $user_id);
        $ins->execute();
        $achievement_id = $ins->insert_id;
        $ins->close();

        // Upload Photos
        $uploadDir = __DIR__ . '/../uploads/achievements';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

        $allowedMime = ['image/jpeg','image/png','image/webp','image/jpg'];
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'JPG', 'JPEG', 'PNG', 'WEBP'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $saved = 0;

        for ($i=0;$i<count($files['name']);$i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

            $tmp = $files['tmp_name'][$i];
            if ((int)$files['size'][$i] > IMAGE_UPLOAD_MAX_BYTES) {
                $conn->query("DELETE FROM achievements WHERE id=$achievement_id");
                $_SESSION['error'] = IMAGE_UPLOAD_SIZE_ERROR;
                header("Location: achievements.php");
                exit();
            }
            $mime = $finfo->file($tmp);
            $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));

            // Double check: mime type and file extension
            if (!in_array($mime, $allowedMime) || !in_array($fileExt, $allowedExt)) {
                $conn->query("DELETE FROM achievements WHERE id=$achievement_id");
                $_SESSION['error'] = "Only JPG, PNG, WEBP images are allowed.";
                header("Location: achievements.php");
                exit();
            }

            $ext = ($mime==='image/jpeg'||$fileExt==='jpg'||$fileExt==='jpeg'?'jpg':($mime==='image/png'||$fileExt==='png'?'png':'webp'));
            $safeBase = preg_replace('/[^A-Za-z0-9_-]/','_', pathinfo($files['name'][$i], PATHINFO_FILENAME));

            $newName = uniqid('ach_', true) . "_" . $safeBase . "." . $ext;

            $abs = "$uploadDir/$newName";
            $rel = "uploads/achievements/$newName";

            if (!move_uploaded_file($tmp, $abs)) {
                $conn->query("DELETE FROM achievements WHERE id=$achievement_id");
                $_SESSION['error'] = "File upload failed.";
                header("Location: achievements.php");
                exit();
            }

            $ph = $conn->prepare("INSERT INTO achievement_photos (achievement_id, file_path, original_name) VALUES (?, ?, ?)");
            $orig = $files['name'][$i];
            $ph->bind_param("iss", $achievement_id, $rel, $orig);
            $ph->execute();
            $ph->close();
            $saved++;
        }

        if ($saved < 1) {
            $conn->query("DELETE FROM achievements WHERE id=$achievement_id");
            $_SESSION['error'] = "No valid photos uploaded.";
        } else {
            $_SESSION['success'] = "Achievement submitted! Pending admin approval.";
        }

        header("Location: achievements.php");
        exit();
    }

    /* -----------------------------------------------
       EDIT
    -------------------------------------------------- */
    if (isset($_POST['edit_achievement'])) {

        $achievement_id = (int)$_POST['achievement_id'];
        $club_id = (int)$_POST['club_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $achieved_on = $_POST['achieved_on'];

        // Validate ownership
        $own = $conn->prepare("SELECT id FROM achievements WHERE id=? AND created_by=? LIMIT 1");
        $own->bind_param("ii", $achievement_id, $user_id);
        $own->execute();
        if ($own->get_result()->num_rows === 0) {
            $_SESSION['error'] = "You cannot edit this achievement.";
            header("Location: achievements.php");
            exit();
        }
        $own->close();

        if ($title==="" || $description==="" || !$achieved_on) {
            $_SESSION['error'] = "All fields are required.";
            header("Location: achievements.php");
            exit();
        }

        // Update & re-submit for approval
        $up = $conn->prepare("
            UPDATE achievements
            SET club_id=?, title=?, description=?, achieved_on=?, approval_status='pending', rejection_reason=NULL
            WHERE id=? AND created_by=?
        ");
        $up->bind_param("isssii", $club_id, $title, $description, $achieved_on, $achievement_id, $user_id);
        $up->execute();
        $up->close();

        $_SESSION['success'] = "Achievement updated. Resubmitted for approval.";
        header("Location: achievements.php");
        exit();
    }

    if (isset($_POST['reupload_achievement_photos'])) {
        $achievement_id = (int)($_POST['achievement_id'] ?? 0);

        $own = $conn->prepare("SELECT id FROM achievements WHERE id=? AND created_by=? LIMIT 1");
        $own->bind_param("ii", $achievement_id, $user_id);
        $own->execute();
        if ($own->get_result()->num_rows === 0) {
            $_SESSION['error'] = "You cannot replace images for this achievement.";
            header("Location: achievements.php");
            exit();
        }
        $own->close();

        $validatedPhotos = image_upload_validate_many($_FILES['photos'] ?? [], 1, 3);
        if (!$validatedPhotos['ok']) {
            $_SESSION['error'] = $validatedPhotos['error'];
            header("Location: achievements.php");
            exit();
        }

        $oldPhotos = [];
        $old = $conn->prepare("SELECT file_path FROM achievement_photos WHERE achievement_id=?");
        $old->bind_param("i", $achievement_id);
        $old->execute();
        $oldResult = $old->get_result();
        while ($row = $oldResult->fetch_assoc()) {
            $oldPhotos[] = $row['file_path'];
        }

        $uploadDir = __DIR__ . '/../uploads/achievements';
        $conn->begin_transaction();

        try {
            $del = $conn->prepare("DELETE FROM achievement_photos WHERE achievement_id=?");
            $del->bind_param("i", $achievement_id);
            $del->execute();

            foreach ($validatedPhotos['files'] as $photoFile) {
                $moved = image_upload_move_validated($photoFile, $uploadDir, 'uploads/achievements', 'ach');
                if (!$moved['ok']) {
                    throw new RuntimeException($moved['error']);
                }

                $ph = $conn->prepare("INSERT INTO achievement_photos (achievement_id, file_path, original_name) VALUES (?, ?, ?)");
                $ph->bind_param("iss", $achievement_id, $moved['db_path'], $moved['original_name']);
                $ph->execute();
            }

            $conn->commit();
            foreach ($oldPhotos as $oldPath) {
                image_upload_delete_db_path($oldPath, __DIR__ . '/..');
            }
            $_SESSION['success'] = "Achievement images updated successfully.";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = "Could not update achievement images. Please try again.";
        }

        header("Location: achievements.php");
        exit();
    }

    /* -----------------------------------------------
       DELETE
    -------------------------------------------------- */
    if (isset($_POST['delete_achievement'])) {
        $achievement_id = (int)$_POST['achievement_id'];

        $own = $conn->prepare("SELECT 1 FROM achievements WHERE id=? AND created_by=?");
        $own->bind_param("ii", $achievement_id, $user_id);
        $own->execute();
        if ($own->get_result()->num_rows === 0) {
            $_SESSION['error'] = "You cannot delete this achievement.";
            header("Location: achievements.php");
            exit();
        }
        $own->close();

        // Delete images
        $ps = $conn->prepare("SELECT file_path FROM achievement_photos WHERE achievement_id=?");
        $ps->bind_param("i", $achievement_id);
        $ps->execute();
        $pr = $ps->get_result();
        while ($p = $pr->fetch_assoc()) {
            $file = __DIR__ . '/../' . normalize_upload_path($p['file_path']);
            if (is_file($file)) @unlink($file);
        }
        $ps->close();

        $del = $conn->prepare("DELETE FROM achievements WHERE id=? AND created_by=?");
        $del->bind_param("ii", $achievement_id, $user_id);
        $del->execute();
        $del->close();

        $_SESSION['success'] = "Achievement deleted.";
        header("Location: achievements.php");
        exit();
    }
}

/* ---------------------------------------------------
   Fetch Achievements (Approved OR Own)
--------------------------------------------------- */

$achievements = [];
$photosByAchievement = [];

if (!empty($clubs)) {
    $club_ids = array_column($clubs, 'id');
    $ph = implode(',', array_fill(0, count($club_ids), '?'));
    $types = str_repeat('i', count($club_ids));

    $sql = "
        SELECT a.*, c.group_name
        FROM achievements a
        JOIN clubs c ON a.club_id = c.id
        WHERE 
            (a.approval_status='approved' AND a.club_id IN ($ph))
            OR (a.created_by = ?)
        ORDER BY a.achieved_on DESC, a.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $types .= 'i';
    $params = array_merge($club_ids, [$user_id]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $achievements[] = $row;
    $stmt->close();

    if (!empty($achievements)) {
        $ids = array_column($achievements, 'id');
        $ph2 = implode(',', array_fill(0, count($ids), '?'));
        $ps = $conn->prepare("
            SELECT id, achievement_id, file_path, original_name
            FROM achievement_photos
            WHERE achievement_id IN ($ph2)
            ORDER BY id ASC
        ");
        $ps->bind_param(str_repeat('i', count($ids)), ...$ids);
        $ps->execute();
        $pr = $ps->get_result();
        while ($p = $pr->fetch_assoc()) {
            $aid = (int)$p['achievement_id'];
            $p['file_path'] = normalize_upload_path($p['file_path']);
            $photosByAchievement[$aid][] = $p;
        }
        $ps->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Achievements - 3ZERO Club</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root { --primary:#1a5276; --primary-dark:#154360; --shadow:0 4px 6px rgba(0,0,0,0.08); --radius:10px; }

body { background:#f8f9fa; }

.main-content { max-width:1200px; margin:0 auto; padding:0 15px; }

/* CARD DESIGN */
.card-ach {
    border:0;
    border-left:4px solid var(--primary);
    box-shadow:var(--shadow);
    border-radius:var(--radius);
    background:#fff;
    overflow:hidden;
}

/* FIX: Reserved space so edit/delete does not overlap */
.head .title-wrap {
    padding-right:110px;
}

.head { 
    background:linear-gradient(135deg,#f8f9fa,#eef2f5); 
    padding:1rem 1.25rem; 
    border-bottom:1px solid #e9ecef;
}

/* STATUS ROW */
.status-wrap {
    margin-top:.35rem;
    display:flex;
    align-items:flex-start;
    flex-wrap:wrap;
    gap:.5rem;
}

.badge-status { font-size:.7rem; }
.badge-status.pending { background:#fff3cd; color:#8a6d3b; border:1px solid #ffe69c; }
.badge-status.approved { background:#d1e7dd; color:#0f5132; border:1px solid #a3cfbb; }
.badge-status.rejected { background:#f8d7da; color:#842029; border:1px solid #f1aeb5; }

/* ACTUAL REJECTION REASON */
.reason-text{
    font-size:.82rem;
    line-height:1.35;
    color:#6c757d;
    background:#fff;
    border-left:3px solid #f1aeb5;
    padding:.35rem .5rem;
    border-radius:6px;
    word-break:break-word;
    max-width:100%;
}

/* ACTION BUTTONS */
.action-buttons{
    position:absolute;
    top:10px; right:10px;
    display:flex; gap:6px;
}
.action-buttons .btn{ padding:.25rem .5rem; font-size:.85rem; }

.thumbs img { width:100%; height:84px; object-fit:cover; border-radius:6px; }

.achievements-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(380px,1fr));
    gap:1.25rem;
}
</style>
</head>

<body>

<?php include("header.php"); ?>

<div class="main-content">
      <!-- Note about converting photos -->
    <div class="alert alert-info mt-2 p-2 small">
        <strong><i class="bi bi-info-circle me-1"></i>Having trouble uploading?</strong><br>
        If your photos won't upload, try compressing them.<br>
        You can compress your image/s here at this website (https://imagecompressor.com/). Please copy the link and paste it in your browser tab.
    </div>

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap mb-3">
        <div>
            <h2 class="mb-1">Achievements</h2>
            <p class="text-muted mb-0">Submit achievements. Admin must approve before publishing.</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAchievementModal">
            <i class="bi bi-trophy me-2"></i> Add Achievement
        </button>
    </div>

    <!-- Alerts -->
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($_SESSION['error']); unset($_SESSION['error']); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($_SESSION['success']); unset($_SESSION['success']); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


    <?php if(empty($achievements)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-image" style="font-size:3rem;"></i>
            <h4 class="mt-3">No achievements yet</h4>
            <p>Click "Add Achievement" to submit one for your club.</p>
        </div>
    <?php else: ?>

    <div class="achievements-grid">

        <?php foreach ($achievements as $a): 
            $isOwner = ($a['created_by'] == $user_id);
            $status = $a['approval_status'];
            $statusLabel = ucfirst($status);
            $reason = $a['rejection_reason'] ?? '';
            $photos = $photosByAchievement[$a['id']] ?? [];
        ?>

        <div class="card-ach position-relative">
            
            <?php if($isOwner): ?>
            <div class="action-buttons">
                <button class="btn btn-outline-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#editAchievementModal"
                    onclick="setEditForm(
                        <?= (int)$a['id'] ?>,
                        `<?= h(addslashes($a['title'])) ?>`,
                        `<?= h(addslashes($a['description'])) ?>`,
                        <?= (int)$a['club_id'] ?>,
                        `<?= h($a['achieved_on']) ?>`
                    )">
                    <i class="bi bi-pencil"></i>
                </button>

                <button class="btn btn-outline-danger btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#deleteAchievementModal"
                    onclick="setDeleteId(<?= (int)$a['id'] ?>)">
                    <i class="bi bi-trash"></i>
                </button>

                <button class="btn btn-outline-success btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#reuploadAchievementPhotosModal"
                    onclick="setReuploadAchievementId(<?= (int)$a['id'] ?>)">
                    <i class="bi bi-image"></i>
                </button>
            </div>
            <?php endif; ?>

            <div class="head">
                <div class="title-wrap">
                    <h5 class="mb-1"><?= h($a['title']) ?></h5>
                    <small class="text-muted d-block">
                        <?= h($a['group_name']) ?> &middot;
                        <?= $a['achieved_on'] ? date("M j, Y", strtotime($a['achieved_on'])) : "Date not set" ?>
                    </small>

                    <div class="status-wrap">
                        <span class="badge badge-status <?= $status ?>"><?= $statusLabel ?></span>

                        <?php if($isOwner && $status==='rejected' && !empty($reason)): ?>
                            <div class="reason-text">
                                <i class="bi bi-chat-left-text me-1"></i>
                                <?= nl2br(h($reason)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="p-3">
                <?php if($a['description']): ?>
                    <p><?= nl2br(h($a['description'])) ?></p>
                <?php endif; ?>

                <?php if(!empty($photos)): ?>
                    <div class="row thumbs">
                        <?php foreach(array_slice($photos,0,3) as $p): ?>
                        <div class="col-4 mb-2">
                            <a href="../<?= h($p['file_path']) ?>" target="_blank">
                                <img src="../<?= h($p['file_path']) ?>" alt="">
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<!-- ADD MODAL -->
<div class="modal fade" id="addAchievementModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Add Achievement</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Club</label>
                    <select name="club_id" class="form-select" required>
                        <option value="">Select Club</option>
                        <?php foreach($clubs as $club): ?>
                        <option value="<?= (int)$club['id'] ?>"><?= h($club['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Achieved On</label>
                    <input type="date" name="achieved_on" class="form-control" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4" required placeholder="Include details such as date, time, place, location, participants, and what was accomplished..."></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">Photos (1-3, JPG/PNG/WEBP, each <= 1MB)</label>
                    <input type="file" name="photos[]" id="photos" class="form-control" multiple required accept=".jpg,.jpeg,.png,.webp,.JPG,.JPEG,.PNG,.WEBP">
                    <div class="form-text"><?= htmlspecialchars(IMAGE_UPLOAD_DISCLAIMER, ENT_QUOTES, 'UTF-8') ?></div>
                    <div id="preview" class="d-flex gap-2 mt-2 flex-wrap"></div>
                </div>

            </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" name="add_achievement">Submit for Approval</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editAchievementModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="achievement_id" id="edit_achievement_id">

        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Edit Achievement</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
            <div class="alert alert-info">
                Editing will set this achievement back to <strong>pending</strong>.
            </div>

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">Club</label>
                    <select name="club_id" id="edit_club_id" class="form-select" required>
                        <?php foreach($clubs as $club): ?>
                        <option value="<?= (int)$club['id'] ?>"><?= h($club['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Achieved On</label>
                    <input type="date" name="achieved_on" id="edit_achieved_on" class="form-control" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" id="edit_title" class="form-control" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="4" required placeholder="Include details such as date, time, place, location, participants, and what was accomplished..."><?= isset($a['description']) ? h($a['description']) : '' ?></textarea>
                </div>

            </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" name="edit_achievement">Save Changes</button>
        </div>

      </form>
    </div>
  </div>
</div>

<!-- REUPLOAD PHOTOS MODAL -->
<div class="modal fade" id="reuploadAchievementPhotosModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="achievement_id" id="reupload_achievement_id">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title">Replace Achievement Images</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">New Photos (1-3, JPG/PNG/WEBP, each <= 1MB)</label>
          <input type="file" name="photos[]" id="reupload_achievement_photos" class="form-control" multiple required accept=".jpg,.jpeg,.png,.webp,.JPG,.JPEG,.PNG,.WEBP">
          <div class="form-text"><?= htmlspecialchars(IMAGE_UPLOAD_DISCLAIMER, ENT_QUOTES, 'UTF-8') ?></div>
          <div id="reuploadAchievementPreview" class="d-flex gap-2 mt-2 flex-wrap"></div>
          <small class="text-muted d-block mt-2">Existing achievement images will be replaced after you submit.</small>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-success" name="reupload_achievement_photos">Replace Images</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteAchievementModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="achievement_id" id="delete_achievement_id">

        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title">Delete Achievement</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <p>Are you sure you want to delete this achievement?</p>
          <p class="text-muted">This action cannot be undone.</p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" name="delete_achievement">Delete</button>
        </div>

      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function previewAchievementImages(input, previewId) {
    const preview = document.getElementById(previewId);
    preview.innerHTML = '';
    const files = [...input.files];

    if (files.length < 1 || files.length > 3) {
        input.value = "";
        preview.innerHTML = "<span class='text-danger'>Please upload between 1 and 3 images.</span>";
        return;
    }

    if (files.some(file => file.size > 1024 * 1024)) {
        input.value = "";
        preview.innerHTML = "<span class='text-danger'>Image size must be less than or equal to 1MB. Please compress the image and upload again.</span>";
        return;
    }

    if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
        input.value = "";
        preview.innerHTML = "<span class='text-danger'>Only JPG, JPEG, PNG, and WEBP images are allowed.</span>";
        return;
    }

    files.slice(0,3).forEach(f=>{
        const url = URL.createObjectURL(f);
        const img = document.createElement('img');
        img.src=url;
        img.style.width="90px";
        img.style.height="90px";
        img.style.objectFit="cover";
        img.style.borderRadius="6px";
        img.onload = () => URL.revokeObjectURL(url);
        preview.appendChild(img);
    });
}

// Client-side preview
document.getElementById('photos')?.addEventListener('change', function() {
    previewAchievementImages(this, 'preview');
});

document.getElementById('reupload_achievement_photos')?.addEventListener('change', function() {
    previewAchievementImages(this, 'reuploadAchievementPreview');
});

// Edit form helper
function setEditForm(id, title, description, clubId, achievedOn) {
    document.getElementById('edit_achievement_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_club_id').value = clubId;
    document.getElementById('edit_achieved_on').value = achievedOn;
}

// Delete form helper
function setDeleteId(id){
    document.getElementById('delete_achievement_id').value = id;
}

function setReuploadAchievementId(id){
    document.getElementById('reupload_achievement_id').value = id;
}
</script>

<script>
// ✅ Stand-alone notification dropdown toggle script
document.addEventListener('DOMContentLoaded', function () {
    // Ensure header doesn't clip the dropdown
    const headerEl = document.querySelector('.main-header');
    if (headerEl) headerEl.style.overflow = 'visible';

    // Initialize all Bootstrap dropdowns on the page
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
    });

    // Optional: direct manual toggle for the bell icon (if Bootstrap fails to auto-bind)
    const bellBtn = document.getElementById('notificationDropdown');
    if (bellBtn) {
        bellBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const dd = bootstrap.Dropdown.getOrCreateInstance(bellBtn, { autoClose: 'outside', display: 'static' });
            dd.toggle();
        });
    }
});
</script>

<?php include("footer.php"); ?>

</body>
</html>
