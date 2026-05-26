<?php
// admin/club_details.php
require_once __DIR__ . '/header.php'; // session + $conn + admin gate

// --- Helpers ---
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function val($v, $fallback='—'){ return ($v !== null && $v !== '') ? h($v) : $fallback; }
function badge_for_status($s){
    $s = strtolower((string)$s);
    if ($s === 'approved' || $s === 'completed' || $s === 'upcoming') return 'bg-success';
    if ($s === 'pending' || $s === 'planning')  return 'bg-warning text-dark';
    if ($s === 'rejected' || $s === 'on_hold' || $s === 'finished') return 'bg-danger';
    if ($s === 'in_progress') return 'bg-primary';
    return 'bg-secondary';
}
function badge_for_cluster($cluster) {
    switch ($cluster) {
        case 'Zero Poverty': return 'bg-danger';
        case 'Zero Unemployment': return 'bg-primary';
        case 'Zero Net Carbon Emissions': return 'bg-success';
        default: return 'bg-secondary';
    }
}
function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $res && $res->num_rows > 0;
}

// --- Input ---
$clubId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($clubId <= 0) {
    $_SESSION['error'] = "Invalid club ID.";
    header('Location: manage_clubs.php');
    exit();
}

// --- Fetch club ---
$clubSql = "SELECT id, club_identifier, group_name, cluster, focus_area, cluster_advisor,
                   key_person_name, key_person_student_id,
                   deputy_key_person_name, deputy_key_person_student_id,
                   date_of_registration, status, created_at, updated_at
            FROM clubs WHERE id = ? LIMIT 1";
$clubStmt = $conn->prepare($clubSql);
$clubStmt->bind_param("i", $clubId);
$clubStmt->execute();
$clubRes = $clubStmt->get_result();
$club = $clubRes->fetch_assoc();

if (!$club) {
    $_SESSION['error'] = "Club not found.";
    header('Location: manage_clubs.php');
    exit();
}

// --- Member filter/search ---
$m_q       = isset($_GET['mq']) ? trim($_GET['mq']) : '';
$m_type    = isset($_GET['mtype']) ? trim($_GET['mtype']) : '';
$validType = ['key_person','deputy','regular'];
$memberWhere = " WHERE cm.club_id = ? ";
$mParams = [$clubId];
$mTypes  = "i";

if ($m_q !== '') {
    $memberWhere .= " AND (cm.full_name LIKE ? OR cm.student_id LIKE ? OR cm.programme LIKE ? OR cm.nationality LIKE ? OR cm.phone LIKE ? OR cm.email LIKE ? OR cm.school_centre LIKE ? OR cm.intake_month_year LIKE ?)";
    $like = "%{$m_q}%";
    array_push($mParams, $like,$like,$like,$like,$like,$like,$like,$like);
    $mTypes .= str_repeat('s', 8);
}
if ($m_type !== '' && in_array($m_type, $validType, true)) {
    $memberWhere .= " AND cm.member_type = ? ";
    $mParams[] = $m_type;
    $mTypes   .= "s";
}

// --- Member counts (quick badges) ---
$countSql = "SELECT 
                SUM(CASE WHEN cm.member_type='key_person' THEN 1 ELSE 0 END) AS key_person,
                SUM(CASE WHEN cm.member_type='deputy'    THEN 1 ELSE 0 END) AS deputy,
                SUM(CASE WHEN cm.member_type='regular'   THEN 1 ELSE 0 END) AS regular,
                COUNT(*) AS total
            FROM club_members cm
            WHERE cm.club_id = ?";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param("i", $clubId);
$countStmt->execute();
$counts = $countStmt->get_result()->fetch_assoc() ?: ['key_person'=>0,'deputy'=>0,'regular'=>0,'total'=>0];

// --- Fetch members ---
$mSql = "SELECT 
            cm.id, cm.full_name, cm.student_id, cm.programme, cm.nationality, cm.phone, cm.email,
            cm.school_centre, cm.intake_month_year, cm.expected_graduation_year, cm.current_semester,
            cm.member_type
         FROM club_members cm
         {$memberWhere}
         ORDER BY 
            FIELD(cm.member_type,'key_person','deputy','regular') ASC,
            cm.full_name ASC";
$mStmt = $conn->prepare($mSql);
$mStmt->bind_param($mTypes, ...$mParams);
$mStmt->execute();
$mRes = $mStmt->get_result();
$members = [];
while ($r = $mRes->fetch_assoc()) $members[] = $r;

// --- Optional: related sections if tables exist ---
$events = $projects = $achievements = [];
if (table_exists($conn, 'events')) {
    // NOTE: no 'location' column in your schema
    $e = $conn->prepare("SELECT id, title, start_date, start_time, end_date, end_time, status, created_at
                         FROM events
                         WHERE club_id = ?
                         ORDER BY COALESCE(start_date, DATE(created_at)) DESC, COALESCE(start_time,'00:00:00') DESC
                         LIMIT 5");
    $e->bind_param("i", $clubId);
    $e->execute();
    $events = $e->get_result()->fetch_all(MYSQLI_ASSOC);
}
if (table_exists($conn, 'projects')) {
    // Use project_name (not title)
    $p = $conn->prepare("SELECT id, project_name, status, created_at
                         FROM projects
                         WHERE club_id = ?
                         ORDER BY created_at DESC
                         LIMIT 5");
    $p->bind_param("i", $clubId);
    $p->execute();
    $projects = $p->get_result()->fetch_all(MYSQLI_ASSOC);
}
if (table_exists($conn, 'achievements')) {
    $a = $conn->prepare("SELECT id, title, achieved_on, created_at
                         FROM achievements
                         WHERE club_id = ?
                         ORDER BY COALESCE(achieved_on, created_at) DESC
                         LIMIT 5");
    $a->bind_param("i", $clubId);
    $a->execute();
    $achievements = $a->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<main class="main-content container-fluid">
    <div class="row g-3">
        <!-- Header / Breadcrumb -->
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_clubs.php">Manage Clubs</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Club Details</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="edit_club.php?id=<?= (int)$club['id'] ?>" class="btn btn-primary">
                    <i class="fa-solid fa-pen me-1"></i> Edit
                </a>
                <a href="manage_clubs.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <!-- Club Summary -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="flex-grow-1">
                        <h3 class="mb-1"><?= h($club['group_name']) ?></h3>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge <?= badge_for_status($club['status']) ?>"><?= h(ucfirst($club['status'])) ?></span>
                            <span class="badge <?= badge_for_cluster($club['cluster']) ?>"><?= h($club['cluster']) ?></span>
                            <span class="badge bg-light text-dark border"><?= h($club['focus_area'] ?: 'Unknown Focus Area') ?></span>
                            <span class="badge bg-dark">Members: <?= (int)$counts['total'] ?></span>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Created</div>
                        <div class="fw-semibold"><?= h(date('M j, Y g:i A', strtotime($club['created_at']))) ?></div>
                        <div class="small text-muted mt-1">Updated</div>
                        <div class="fw-semibold"><?= h(date('M j, Y g:i A', strtotime($club['updated_at']))) ?></div>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Club Identifier</div>
                                <div class="fw-semibold"><code><?= val($club['club_identifier']) ?></code></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Date of Registration</div>
                                <div class="fw-semibold"><?= $club['date_of_registration'] ? h(date('M j, Y', strtotime($club['date_of_registration']))) : '—' ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Club Advisor</div>
                                <div class="fw-semibold"><?= val($club['cluster_advisor']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Focus Area</div>
                                <div class="fw-semibold"><?= val($club['focus_area']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Status</div>
                                <div class="fw-semibold">
                                    <span class="badge <?= badge_for_status($club['status']) ?>"><?= h(ucfirst($club['status'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Key Person</div>
                                <div class="fw-semibold"><?= val($club['key_person_name']) ?></div>
                                <small class="text-muted"><?= val($club['key_person_student_id']) ?></small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Deputy Key Person</div>
                                <div class="fw-semibold"><?= val($club['deputy_key_person_name']) ?></div>
                                <small class="text-muted"><?= val($club['deputy_key_person_student_id']) ?></small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Member Breakdown</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-dark">Key: <?= (int)$counts['key_person'] ?></span>
                                    <span class="badge bg-info text-dark">Deputy: <?= (int)$counts['deputy'] ?></span>
                                    <span class="badge bg-light text-dark">Regular: <?= (int)$counts['regular'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /footer quick facts -->
            </div>
        </div>

        <!-- Members: search + table -->
        <div class="col-12">
            <form class="card shadow-sm border-0 mb-0" method="get" action="">
                <input type="hidden" name="id" value="<?= (int)$club['id'] ?>">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <strong>Members</strong>
                    <div class="small text-muted">Showing <?= count($members) ?> of <?= (int)$counts['total'] ?></div>
                </div>
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label">Search members</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" class="form-control" name="mq" placeholder="Name, student ID, programme, nationality, phone, email, school/centre, intake"
                                       value="<?= h($m_q) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Member type</label>
                            <select class="form-select" name="mtype">
                                <option value="">All</option>
                                <option value="key_person" <?= $m_type==='key_person'?'selected':''; ?>>Key Person</option>
                                <option value="deputy" <?= $m_type==='deputy'?'selected':''; ?>>Deputy</option>
                                <option value="regular" <?= $m_type==='regular'?'selected':''; ?>>Regular</option>
                            </select>
                        </div>
                        <div class="col-md-3 text-end">
                            <button class="btn btn-success w-100"><i class="fa-solid fa-filter me-1"></i> Apply</button>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Full Name</th>
                                <th>Member Type</th>
                                <th>Student ID</th>
                                <th>Programme</th>
                                <th>Nationality</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>School/Centre</th>
                                <th>Intake (Month/Year)</th>
                                <th>Expected Grad Year</th>
                                <th>Current Semester</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($members)): ?>
                            <tr><td colspan="12" class="text-muted text-center py-4">No members found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($members as $m): ?>
                                <?php
                                  $mt = strtolower($m['member_type'] ?? 'regular');
                                  $badge = $mt === 'key_person' ? 'bg-dark' : ($mt === 'deputy' ? 'bg-info text-dark' : 'bg-light text-dark');
                                ?>
                                <tr>
                                    <td><?= (int)$m['id'] ?></td>
                                    <td class="fw-semibold"><?= val($m['full_name']) ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= h(ucwords(str_replace('_',' ', $mt))) ?></span></td>
                                    <td><?= val($m['student_id']) ?></td>
                                    <td><?= val($m['programme']) ?></td>
                                    <td><?= val($m['nationality']) ?></td>
                                    <td><?= val($m['phone']) ?></td>
                                    <td><?= val($m['email']) ?></td>
                                    <td><?= val($m['school_centre']) ?></td>
                                    <td><?= val($m['intake_month_year']) ?></td>
                                    <td><?= val($m['expected_graduation_year']) ?></td>
                                    <td><?= val($m['current_semester']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <!-- Optional related (if tables available) -->
        <?php if (!empty($events) || !empty($projects) || !empty($achievements)): ?>
        <div class="col-12">
            <div class="row g-3">
                <?php if (!empty($events)): ?>
                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <strong>Recent Events</strong>
                            <a href="manage_events.php?club_id=<?= (int)$clubId ?>" class="btn btn-sm btn-light">View all</a>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($events as $e): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= h($e['title'] ?: 'Untitled') ?></div>
                                        <small class="text-muted">
                                            <?= $e['start_date'] ? h(date('M j, Y', strtotime($e['start_date']))) : 'Date TBA' ?>
                                            <?php if (!empty($e['start_time']) && $e['start_time'] !== '00:00:00'): ?>
                                                at <?= h(date('g:i A', strtotime($e['start_time']))) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($e['end_date']) && $e['end_date'] !== $e['start_date']): ?>
                                                – <?= h(date('M j, Y', strtotime($e['end_date']))) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <span class="badge <?= badge_for_status($e['status'] ?? '') ?>"><?= h(ucfirst($e['status'] ?? '')) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($projects)): ?>
                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <strong>Recent Projects</strong>
                            <a href="manage_projects.php?club_id=<?= (int)$clubId ?>" class="btn btn-sm btn-light">View all</a>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($projects as $p): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= h($p['project_name'] ?: 'Untitled') ?></div>
                                        <small class="text-muted"><?= h(date('M j, Y g:i A', strtotime($p['created_at']))) ?></small>
                                    </div>
                                    <?php
                                      $ps = strtolower($p['status'] ?? '');
                                      $pbadge = badge_for_status($ps);
                                    ?>
                                    <span class="badge <?= $pbadge ?>"><?= h(ucwords(str_replace('_',' ', $ps))) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($achievements)): ?>
                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <strong>Recent Achievements</strong>
                            <a href="manage_achievements.php?club_id=<?= (int)$clubId ?>" class="btn btn-sm btn-light">View all</a>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($achievements as $a): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= h($a['title'] ?: 'Untitled') ?></div>
                                        <small class="text-muted">
                                            <?= $a['achieved_on'] ? h(date('M j, Y', strtotime($a['achieved_on']))) : h(date('M j, Y', strtotime($a['created_at']))) ?>
                                        </small>
                                    </div>
                                    <a href="manage_achievements.php?club_id=<?= (int)$clubId ?>" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
