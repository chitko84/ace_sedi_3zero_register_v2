<?php
// admin/edit_project.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// ---------- Helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function valOrNull($v){
    if (!isset($v)) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}
function is_valid_date($v){
    if ($v === null || $v === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}
$statusOptions = ['planning','in_progress','completed','on_hold'];

// ---------- CSRF ----------
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Target project ----------
$pid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pid <= 0) {
    $_SESSION['error'] = "Invalid project ID.";
    header('Location: manage_projects.php');
    exit();
}

// Load clubs for dropdown (deduped by id)
$clubs = [];
$cq = $conn->query("SELECT DISTINCT id, group_name FROM clubs ORDER BY group_name ASC");
while ($row = $cq->fetch_assoc()) $clubs[] = $row;

// Fetch project (include club name + creator for header niceties)
$sql = "
SELECT
    p.id, p.club_id, p.project_name, p.description, p.objectives,
    p.start_date, p.end_date, p.status, p.created_by, p.created_at, p.updated_at,
    c.group_name AS club_name,
    u.name AS creator_name
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

// ---------- Handle POST ----------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        $club_id        = (int)($_POST['club_id'] ?? 0);
        $project_name   = trim($_POST['project_name'] ?? '');
        $description    = valOrNull($_POST['description'] ?? '');
        $objectives     = valOrNull($_POST['objectives'] ?? '');
        $status         = trim($_POST['status'] ?? '');
        $start_date     = valOrNull($_POST['start_date'] ?? '');
        $end_date       = valOrNull($_POST['end_date'] ?? '');

        // Basic validations
        if ($club_id <= 0) $errors[] = "Club is required.";
        if ($project_name === '') $errors[] = "Project name is required.";
        if (!in_array($status, $statusOptions, true)) $errors[] = "Invalid status value.";
        if (!is_valid_date($start_date)) $errors[] = "Invalid start date format (YYYY-MM-DD).";
        if (!is_valid_date($end_date))   $errors[] = "Invalid end date format (YYYY-MM-DD).";

        // Ensure club exists
        if ($club_id > 0) {
            $chk = $conn->prepare("SELECT id FROM clubs WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $club_id);
            $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) {
                $errors[] = "Selected club not found.";
            }
        }

        // Logical dates (optional)
        if ($start_date && $end_date) {
            if (strtotime($end_date) < strtotime($start_date)) {
                $errors[] = "End date cannot be earlier than start date.";
            }
        }

        // Update if OK
        if (!$errors) {
            $sets = [
                "club_id = ?",
                "project_name = ?",
                "description = ?",
                "objectives = ?",
                "start_date = ?",
                "end_date = ?",
                "status = ?"
            ];
            $sqlU = "UPDATE projects SET ".implode(", ", $sets).", updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1";
            $stU  = $conn->prepare($sqlU);

            $club_id_i = (int)$club_id;

            $stU->bind_param(
                "issssssi",
                $club_id_i,
                $project_name,
                $description,
                $objectives,
                $start_date,
                $end_date,
                $status,
                $pid
            );

            if ($stU->execute()) {
                $_SESSION['success'] = "Project updated successfully.";
                header('Location: view_project.php?id='.$pid);
                exit();
            } else {
                $errors[] = "Failed to update project. Please try again.";
            }
        }
    }
}

// ---------- Safe to output now ----------
require_once __DIR__ . '/header.php';

// Pre-fill values (use posted values if errors, else DB)
function old($key, $default){
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

$pf = [
    'club_id'        => old('club_id',        $project['club_id']),
    'project_name'   => old('project_name',   $project['project_name']),
    'description'    => old('description',    $project['description']),
    'objectives'     => old('objectives',     $project['objectives']),
    'status'         => old('status',         $project['status']),
    'start_date'     => old('start_date',     $project['start_date']),
    'end_date'       => old('end_date',       $project['end_date']),
];

$createdAt = $project['created_at'] ? date('M j, Y g:i A', strtotime($project['created_at'])) : '—';
$updatedAt = $project['updated_at'] ? date('M j, Y g:i A', strtotime($project['updated_at'])) : '—';
?>
<main class="main-content container-fluid">
    <style>
        .form-section-title { font-weight: 600; color:#0f2f47; }
    </style>

    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_projects.php">Manage Projects</a></li>
                    <li class="breadcrumb-item"><a href="view_project.php?id=<?= (int)$project['id'] ?>">View Project</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Project</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="view_project.php?id=<?= (int)$project['id'] ?>" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h4 class="mb-3"><?= h($project['project_name']) ?> <small class="text-muted">(#<?= (int)$project['id'] ?>)</small></h4>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <strong>Please fix the following:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li><?= h($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?= h($_SESSION['success']); unset($_SESSION['success']); ?></div>
                    <?php endif; ?>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

                        <div class="col-md-6">
                            <label class="form-label">Club</label>
                            <select name="club_id" class="form-select" required>
                                <option value="">-- Select a club --</option>
                                <?php foreach ($clubs as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$pf['club_id']===(int)$c['id'])?'selected':''; ?>>
                                        <?= h($c['group_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Project Name</label>
                            <input type="text" name="project_name" class="form-control" value="<?= h($pf['project_name']) ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Optional"><?= h($pf['description']) ?></textarea>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Objectives</label>
                            <textarea name="objectives" class="form-control" rows="3" placeholder="Optional"><?= h($pf['objectives']) ?></textarea>
                        </div>

                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= h($pf['start_date']) ?>">
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= h($pf['end_date']) ?>">
                        </div>

                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <?php foreach ($statusOptions as $opt): ?>
                                    <option value="<?= h($opt) ?>" <?= ($pf['status']===$opt)?'selected':''; ?>>
                                        <?= h(ucfirst(str_replace('_',' ', $opt))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <a href="view_project.php?id=<?= (int)$project['id'] ?>" class="btn btn-light">Cancel</a>
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-white">
                    <small class="text-muted">
                        Created: <?= h($createdAt) ?> &nbsp;•&nbsp; Updated: <?= h($updatedAt) ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
