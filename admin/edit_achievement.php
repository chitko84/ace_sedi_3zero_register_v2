<?php
// admin/edit_achievement.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/image_upload_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate (redirect before any output) ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// ---------- Helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function valOrNull($v){ $v = trim((string)$v); return ($v === '') ? null : $v; }

// Normalize *display* path to ../uploads/achievements/<file>
function normalize_ach_path(?string $p): ?string {
    if (!$p) return null;
    $p = trim($p);
    if (preg_match('~^https?://~i', $p)) return $p; // external URL
    if (strpos($p, '../uploads/achievements/') === 0) return $p;
    $p = ltrim($p, '/');
    if (strpos($p, 'uploads/achievements/') === 0) return '../' . $p;
    return '../uploads/achievements/' . basename($p);
}

// Where to SAVE files (filesystem path + DB path prefix)
function ensure_ach_upload_dir(): array {
    $rootFs   = realpath(__DIR__ . '/..'); // project root (parent of /admin)
    if ($rootFs === false) { throw new RuntimeException('Upload root not available.'); }
    $relDir   = 'uploads/achievements';
    $fsDir    = $rootFs . DIRECTORY_SEPARATOR . $relDir;
    if (!is_dir($fsDir)) @mkdir($fsDir, 0755, true);
    if (!is_dir($fsDir)) { throw new RuntimeException('Failed to create upload directory.'); }
    // We store DB paths with '../uploads/achievements/...'
    $dbPrefix = '../' . $relDir . '/';
    return [$fsDir, $dbPrefix];
}

function is_allowed_img($tmpPath, $origName): ?string {
    // Returns extension to use (jpg/png/webp) or null if disallowed
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = @$finfo->file($tmpPath);
        return $allowed[$mime] ?? null;
    }
    // Fallback: guess by extension
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
    return null;
}

// ---------- CSRF ----------
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Load achievement ----------
$aid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($aid <= 0) {
    $_SESSION['error'] = "Invalid achievement ID.";
    header('Location: manage_achievements.php');
    exit();
}

$sql = "SELECT id, club_id, title, description, achieved_on, created_by, created_at
        FROM achievements WHERE id = ? LIMIT 1";
$st  = $conn->prepare($sql);
$st->bind_param("i", $aid);
$st->execute();
$achievement = $st->get_result()->fetch_assoc();

if (!$achievement) {
    $_SESSION['error'] = "Achievement not found.";
    header('Location: manage_achievements.php');
    exit();
}

// Load clubs for dropdown
$clubs = [];
$cq = $conn->query("SELECT id, group_name FROM clubs ORDER BY group_name ASC");
while ($row = $cq->fetch_assoc()) $clubs[] = $row;

// Load existing photos
$photos = [];
$ps = $conn->prepare("SELECT id, file_path, original_name FROM achievement_photos WHERE achievement_id = ? ORDER BY id ASC");
$ps->bind_param("i", $aid);
$ps->execute();
$pr = $ps->get_result();
while ($row = $pr->fetch_assoc()) $photos[] = $row;

// ---------- Handle POST ----------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        // Inputs
        $club_id     = (int)($_POST['club_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = valOrNull($_POST['description'] ?? '');
        $achieved_on = valOrNull($_POST['achieved_on'] ?? '');
        $delete_ids  = array_map('intval', $_POST['delete_photo_ids'] ?? []);

        // Validate
        if ($club_id <= 0) $errors[] = "Club is required.";
        if ($title === '') $errors[] = "Title is required.";
        if ($achieved_on !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $achieved_on)) {
            $errors[] = "Achieved On must be a valid date (YYYY-MM-DD).";
        }

        // Validate photos to upload
        $newUploads = [];
        if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
            $count = count($_FILES['photos']['name']);
            for ($i=0; $i<$count; $i++) {
                $name = $_FILES['photos']['name'][$i];
                $tmp  = $_FILES['photos']['tmp_name'][$i];
                $err  = $_FILES['photos']['error'][$i];
                $size = (int)$_FILES['photos']['size'][$i];

                if ($err === UPLOAD_ERR_NO_FILE) continue; // nothing selected at this index

                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    $errors[] = "Upload error for file \"{$name}\" (code {$err}).";
                    continue;
                }
                if ($size > IMAGE_UPLOAD_MAX_BYTES) {
                    $errors[] = IMAGE_UPLOAD_SIZE_ERROR;
                    continue;
                }
                $ext = is_allowed_img($tmp, $name);
                if ($ext === null) {
                    $errors[] = "File \"{$name}\" must be JPG, PNG, GIF, or WEBP.";
                    continue;
                }
                $newUploads[] = ['tmp'=>$tmp, 'orig'=>$name, 'ext'=>$ext];
            }
        }

        if (!$errors) {
            // Collect file paths for photos to delete (to remove from disk AFTER commit)
            $deleteFiles = [];
            if (!empty($delete_ids)) {
                $in  = implode(',', array_fill(0, count($delete_ids), '?'));
                $typ = str_repeat('i', count($delete_ids));
                $qs  = $conn->prepare("SELECT file_path FROM achievement_photos WHERE achievement_id = ? AND id IN ($in)");
                // bind (achievement_id + ids)
                $bindTypes = 'i' . $typ;
                $bindVals  = array_merge([$aid], $delete_ids);
                $qs->bind_param($bindTypes, ...$bindVals);
                $qs->execute();
                $rs = $qs->get_result();
                while ($r = $rs->fetch_assoc()) {
                    // build FS path
                    $fp = $r['file_path'] ?? '';
                    // convert DB path (../uploads/achievements/...) to filesystem
                    $rootFs = realpath(__DIR__ . '/..');
                    if ($rootFs !== false) {
                        $clean = ltrim($fp, './');
                        if (strpos($clean, 'uploads/achievements/') === 0) {
                            $fsP = $rootFs . DIRECTORY_SEPARATOR . $clean;
                        } elseif (strpos($clean, '../uploads/achievements/') === 0) {
                            $fsP = $rootFs . DIRECTORY_SEPARATOR . substr($clean, 3);
                        } else {
                            $fsP = $rootFs . DIRECTORY_SEPARATOR . 'uploads/achievements' . DIRECTORY_SEPARATOR . basename($clean);
                        }
                        $deleteFiles[] = $fsP;
                    }
                }
            }

            // Transaction
            $conn->begin_transaction();
            try {
                // Update achievement
                $u = $conn->prepare("UPDATE achievements
                                     SET club_id = ?, title = ?, description = ?, achieved_on = ?
                                     WHERE id = ? LIMIT 1");
                $u->bind_param("isssi", $club_id, $title, $description, $achieved_on, $aid);
                $u->execute();

                // Delete selected photos
                if (!empty($delete_ids)) {
                    $in  = implode(',', array_fill(0, count($delete_ids), '?'));
                    $typ = str_repeat('i', count($delete_ids));
                    $d   = $conn->prepare("DELETE FROM achievement_photos WHERE achievement_id = ? AND id IN ($in)");
                    $bindTypes = 'i' . $typ;
                    $bindVals  = array_merge([$aid], $delete_ids);
                    $d->bind_param($bindTypes, ...$bindVals);
                    $d->execute();
                }

                // Save new uploads
                if (!empty($newUploads)) {
                    list($fsDir, $dbPrefix) = ensure_ach_upload_dir();
                    foreach ($newUploads as $nu) {
                        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/','_', pathinfo($nu['orig'], PATHINFO_FILENAME));
                        $newName  = 'ach_'.$aid.'_'.time().'_'.bin2hex(random_bytes(3)).'_'.$safeBase.'.'.$nu['ext'];
                        $destFs   = $fsDir . DIRECTORY_SEPARATOR . $newName;
                        if (!move_uploaded_file($nu['tmp'], $destFs)) {
                            throw new RuntimeException('Failed to store uploaded image: '.$nu['orig']);
                        }
                        $dbPath = $dbPrefix . $newName; // e.g. ../uploads/achievements/xxx.jpg
                        $ins = $conn->prepare("INSERT INTO achievement_photos (achievement_id, file_path, original_name) VALUES (?,?,?)");
                        $ins->bind_param("iss", $aid, $dbPath, $nu['orig']);
                        $ins->execute();
                    }
                }

                $conn->commit();

                // Remove deleted files from disk AFTER commit (best-effort)
                foreach ($deleteFiles as $fp) {
                    if (@is_file($fp)) { @unlink($fp); }
                }

                $_SESSION['success'] = "Achievement updated successfully.";
                header('Location: view_achievement.php?id='.(int)$aid);
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = "Update failed. Please try again.";
            }
        }
    }
}

// From here, SAFE to output HTML
require_once __DIR__ . '/header.php';

// Pretty dates
$achievedOnVal = $achievement['achieved_on'] ?? '';
?>
<main class="main-content container-fluid">
    <style>
        .ach-thumb { width: 100%; height: 160px; object-fit: cover; border-radius: 10px; border:1px solid #eef2f7; }
        @media (max-width: 575.98px){ .ach-thumb { height: 140px; } }
        .form-note { color:#6c757d; font-size:.9rem; }
    </style>

    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_achievements.php">Manage Achievements</a></li>
                    <li class="breadcrumb-item"><a href="view_achievement.php?id=<?= (int)$achievement['id'] ?>">View Achievement</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Achievement</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="view_achievement.php?id=<?= (int)$achievement['id'] ?>" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h4 class="mb-3">Edit Achievement #<?= (int)$achievement['id'] ?></h4>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <strong>Please fix the following:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

                        <div class="col-md-4">
                            <label class="form-label">Club <span class="text-danger">*</span></label>
                            <select name="club_id" class="form-select" required>
                                <option value="">Select a club…</option>
                                <?php foreach ($clubs as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$achievement['club_id']===(int)$c['id'])?'selected':''; ?>>
                                        <?= h($c['group_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required
                                   value="<?= h($achievement['title']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Achieved On</label>
                            <input type="date" class="form-control" name="achieved_on" value="<?= h($achievedOnVal) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="5"
                                      placeholder="Describe the achievement…"><?= h($achievement['description'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Upload New Photos (optional)</label>
                            <input type="file" class="form-control" id="new_photos" name="photos[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                            <div class="form-note mt-1"><?= h(IMAGE_UPLOAD_DISCLAIMER) ?> Allowed formats: JPG, PNG, WEBP.</div>
                            <div id="newPhotoPreview" class="d-flex gap-2 mt-2 flex-wrap"></div>
                        </div>

                        <?php if (!empty($photos)): ?>
                        <div class="col-12">
                            <label class="form-label">Existing Photos</label>
                            <div class="row g-3">
                                <?php foreach ($photos as $p): ?>
                                    <div class="col-6 col-md-3">
                                        <div class="card border-0 shadow-sm">
                                            <img src="<?= h(normalize_ach_path($p['file_path'])) ?>"
                                                 alt="<?= h($p['original_name'] ?: 'achievement') ?>"
                                                 class="ach-thumb"
                                                 onerror="this.closest('.card').outerHTML='<div class=&quot;text-muted&quot;>Image not available.</div>';">
                                            <div class="card-body p-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox"
                                                           name="delete_photo_ids[]"
                                                           value="<?= (int)$p['id'] ?>" id="delp<?= (int)$p['id'] ?>">
                                                    <label class="form-check-label small" for="delp<?= (int)$p['id'] ?>">
                                                        Remove this photo
                                                    </label>
                                                </div>
                                                <?php if (!empty($p['original_name'])): ?>
                                                    <div class="small text-muted mt-1 text-truncate" title="<?= h($p['original_name']) ?>">
                                                        <?= h($p['original_name']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <a href="view_achievement.php?id=<?= (int)$achievement['id'] ?>" class="btn btn-light">Cancel</a>
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card-footer bg-white">
                    <small class="text-muted">
                        Created: <?= $achievement['created_at'] ? h(date('M j, Y g:i A', strtotime($achievement['created_at']))) : '—' ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.getElementById('new_photos')?.addEventListener('change', function () {
    const preview = document.getElementById('newPhotoPreview');
    preview.innerHTML = '';
    const files = Array.from(this.files || []);

    if (files.some(file => file.size > 1024 * 1024)) {
        alert('Image size must be less than or equal to 1MB. Please compress the image and upload again.');
        this.value = '';
        return;
    }

    if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
        alert('Only JPG, JPEG, PNG, and WEBP images are allowed.');
        this.value = '';
        return;
    }

    files.forEach(file => {
        const img = document.createElement('img');
        const url = URL.createObjectURL(file);
        img.src = url;
        img.alt = 'Selected image preview';
        img.style.width = '90px';
        img.style.height = '90px';
        img.style.objectFit = 'cover';
        img.style.borderRadius = '6px';
        img.onload = () => URL.revokeObjectURL(url);
        preview.appendChild(img);
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
