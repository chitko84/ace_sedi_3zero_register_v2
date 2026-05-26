<?php
// admin/view_user.php
require_once __DIR__ . '/header.php'; // session, $conn, admin gate

// --- Helpers ---
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function val($v, $fallback = '—') { return ($v !== null && $v !== '') ? h($v) : $fallback; }
function cluster_badge_class($cluster) {
    switch ($cluster) {
        case 'Zero Poverty': return 'danger';
        case 'Zero Unemployment': return 'primary';
        case 'Zero Net Carbon Emissions': return 'success';
        default: return 'secondary';
    }
}

// Function to check if profile picture exists and return correct path
function getProfilePicPath($profilePic) {
    $defaultPic = '../uploads/default-profile.jpg';
    
    if (empty($profilePic) || $profilePic === 'default-profile.jpg') {
        return $defaultPic;
    }
    
    // Check if it's already a full path
    if (strpos($profilePic, 'uploads/profiles/') !== false) {
        $fullPath = '../' . $profilePic;
    } else {
        $fullPath = '../uploads/profiles/' . $profilePic;
    }
    
    // Check if file actually exists
    if (file_exists($fullPath)) {
        return $fullPath;
    }
    
    // Fallback to default
    return $defaultPic;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: manage_users.php');
    exit();
}

// --- Fetch user (exclude password) ---
$sql = "SELECT id, name, date_of_birth, phone_number, email, role, profile_pic,
               department, program_of_study, intake, country, gender,
               expected_graduation_year, created_at
        FROM users
        WHERE id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header('Location: manage_users.php');
    exit();
}

$profilePhoto = getProfilePicPath($user['profile_pic']);
$userEmail = $user['email'];

// --- Fetch clubs this user belongs to (by email in club_members) ---
$clubs = [];
$clubSql = "SELECT 
                c.id AS club_id,
                c.group_name,
                c.cluster,
                c.status AS club_status,
                c.club_identifier,
                c.date_of_registration,
                c.created_at AS club_created_at,
                cm.member_type,
                cm.student_id,
                cm.programme,
                cm.school_centre
            FROM clubs c
            JOIN club_members cm ON cm.club_id = c.id
            WHERE cm.email = ?
            ORDER BY c.created_at DESC";
$clubStmt = $conn->prepare($clubSql);
$clubStmt->bind_param("s", $userEmail);
$clubStmt->execute();
$clubRes = $clubStmt->get_result();
while ($r = $clubRes->fetch_assoc()) $clubs[] = $r;

// Tally counts for quick badges
$clubCounts = ['total'=>0, 'approved'=>0, 'pending'=>0, 'rejected'=>0, 'key_person'=>0, 'deputy'=>0, 'regular'=>0];
foreach ($clubs as $c) {
    $clubCounts['total']++;
    $st = strtolower($c['club_status'] ?? '');
    if (isset($clubCounts[$st])) $clubCounts[$st]++;
    $mt = strtolower($c['member_type'] ?? 'regular');
    if (isset($clubCounts[$mt])) $clubCounts[$mt]++;
}

// --- Fetch raw membership records (all columns from club_members for this email) ---
$memberships = [];
$memSql = "SELECT 
              cm.id,
              cm.club_id,
              cm.full_name,
              cm.student_id,
              cm.programme,
              cm.nationality,
              cm.phone,
              cm.email,
              cm.school_centre,
              cm.intake_month_year,
              cm.expected_graduation_year,
              cm.current_semester,
              cm.member_type,
              c.group_name
          FROM club_members cm
          LEFT JOIN clubs c ON c.id = cm.club_id
          WHERE cm.email = ?
          ORDER BY cm.club_id DESC, cm.id DESC";
$memStmt = $conn->prepare($memSql);
$memStmt->bind_param("s", $userEmail);
$memStmt->execute();
$memRes = $memStmt->get_result();
while ($r = $memRes->fetch_assoc()) $memberships[] = $r;
?>
<main class="main-content container-fluid">
    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_users.php">Manage Users</a></li>
                    <li class="breadcrumb-item active" aria-current="page">View User</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="edit_user.php?id=<?= (int)$user['id'] ?>" class="btn btn-primary">
                    <i class="fa-solid fa-pen me-1"></i> Edit
                </a>
                <a href="delete_user.php?id=<?= (int)$user['id'] ?>" class="btn btn-outline-danger"
                   onclick="return confirm('Delete this user? This action cannot be undone.');">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </a>
                <a href="manage_users.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <!-- Profile Header -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <img src="<?= h($profilePhoto) ?>"
                         onerror="this.src='../uploads/default-profile.jpg'"
                         alt="Profile picture of <?= h($user['name']) ?>" class="rounded-circle"
                         style="width:90px;height:90px;object-fit:cover;border:2px solid #e9ecef;">
                    <div class="flex-grow-1">
                        <h4 class="mb-1"><?= h($user['name']) ?></h4>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge <?= $user['role']==='admin' ? 'bg-danger' : 'bg-secondary' ?>">
                                <?= h(ucfirst($user['role'])) ?>
                            </span>
                            <span class="text-muted"><i class="fa-regular fa-envelope me-1"></i><?= h($user['email']) ?></span>
                            <span class="text-muted"><i class="fa-solid fa-phone me-1"></i><?= h($user['phone_number']) ?></span>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Created</div>
                        <div class="fw-semibold">
                            <?= h(date('M j, Y g:i A', strtotime($user['created_at']))) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Details -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <strong>User Details</strong>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Full Name</div>
                                <div class="fw-semibold"><?= val($user['name']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Email</div>
                                <div class="fw-semibold"><?= val($user['email']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Phone Number</div>
                                <div class="fw-semibold"><?= val($user['phone_number']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Date of Birth</div>
                                <div class="fw-semibold"><?= val($user['date_of_birth']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Gender</div>
                                <div class="fw-semibold"><?= val($user['gender']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Country</div>
                                <div class="fw-semibold"><?= val($user['country']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Role</div>
                                <div>
                                    <span class="badge <?= $user['role']==='admin' ? 'bg-danger' : 'bg-secondary' ?>">
                                        <?= h(ucfirst($user['role'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Department</div>
                                <div class="fw-semibold"><?= val($user['department']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Program of Study</div>
                                <div class="fw-semibold"><?= val($user['program_of_study']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Intake</div>
                                <div class="fw-semibold"><?= val($user['intake']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Expected Graduation Year</div>
                                <div class="fw-semibold"><?= val($user['expected_graduation_year']) ?></div>
                            </div>
                        </div>
                        <!-- <div class="col-md-8">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Profile Photo Path</div>
                                <div class="fw-semibold"><?= val($user['profile_pic']) ?></div>
                            </div>
                        </div> -->
                    </div> <!-- /row -->
                </div>
            </div>
        </div>

        <!-- Club Memberships (joined clubs + member_type) -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <strong>Club Memberships</strong>
                    <div class="d-flex gap-2 small">
                        <span class="badge bg-secondary">Total: <?= (int)$clubCounts['total'] ?></span>
                        <span class="badge bg-success">Approved: <?= (int)$clubCounts['approved'] ?></span>
                        <span class="badge bg-warning text-dark">Pending: <?= (int)$clubCounts['pending'] ?></span>
                        <span class="badge bg-danger">Rejected: <?= (int)$clubCounts['rejected'] ?></span>
                        <span class="badge bg-dark">Key: <?= (int)$clubCounts['key_person'] ?></span>
                        <span class="badge bg-info text-dark">Deputy: <?= (int)$clubCounts['deputy'] ?></span>
                        <span class="badge bg-light text-dark">Regular: <?= (int)$clubCounts['regular'] ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($clubs)): ?>
                        <div class="text-center text-muted py-4">No club memberships found for this email.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Club</th>
                                        <th>Cluster</th>
                                        <th>Status</th>
                                        <th>Member Type</th>
                                        <th>Student ID</th>
                                        <th>Programme</th>
                                        <th>School/Centre</th>
                                        <th>Club ID</th>
                                        <th>Date Registered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($clubs as $c): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($c['group_name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= cluster_badge_class($c['cluster']) ?>"><?= h($c['cluster']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $c['club_status']==='approved' ? 'bg-success' : ($c['club_status']==='pending' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                                <?= h(ucfirst($c['club_status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $mt = strtolower($c['member_type'] ?? 'regular');
                                            $mtBadge = $mt === 'key_person' ? 'bg-dark' : ($mt === 'deputy' ? 'bg-info text-dark' : 'bg-light text-dark');
                                            ?>
                                            <span class="badge <?= $mtBadge ?>"><?= h(str_replace('_',' ', ucfirst($mt))) ?></span>
                                        </td>
                                        <td><?= val($c['student_id']) ?></td>
                                        <td><?= val($c['programme']) ?></td>
                                        <td><?= val($c['school_centre']) ?></td>
                                        <td><code><?= val($c['club_identifier']) ?></code></td>
                                        <td><?= $c['date_of_registration'] ? h(date('M j, Y', strtotime($c['date_of_registration']))) : '—' ?></td>
                                        <td>
                                            <a href="club_details.php?id=<?= (int)$c['club_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fa-regular fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Membership Records (raw club_members rows for this email) -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <strong>Membership Records (club_members)</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($memberships)): ?>
                        <div class="text-center text-muted py-4">No membership records.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Club</th>
                                        <th>Club ID</th>
                                        <th>Full Name</th>
                                        <th>Student ID</th>
                                        <th>Programme</th>
                                        <th>Nationality</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>School/Centre</th>
                                        <th>Intake (Month/Year)</th>
                                        <th>Expected Grad Year</th>
                                        <th>Current Semester</th>
                                        <th>Member Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($memberships as $m): ?>
                                    <tr>
                                        <td><?= (int)$m['id'] ?></td>
                                        <td><?= val($m['group_name']) ?></td>
                                        <td><?= (int)$m['club_id'] ?></td>
                                        <td><?= val($m['full_name']) ?></td>
                                        <td><?= val($m['student_id']) ?></td>
                                        <td><?= val($m['programme']) ?></td>
                                        <td><?= val($m['nationality']) ?></td>
                                        <td><?= val($m['phone']) ?></td>
                                        <td><?= val($m['email']) ?></td>
                                        <td><?= val($m['school_centre']) ?></td>
                                        <td><?= val($m['intake_month_year']) ?></td>
                                        <td><?= val($m['expected_graduation_year']) ?></td>
                                        <td><?= val($m['current_semester']) ?></td>
                                        <td><?= val($m['member_type']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<style>
@media (max-width: 768px) {
    .card-header .d-flex.gap-2 {
        flex-wrap: wrap;
        gap: 4px !important;
        margin-top: 8px;
    }
    
    .card-header .d-flex.gap-2 .badge {
        font-size: 0.7rem;
        padding: 4px 6px;
        margin: 1px;
    }
    
    .card-header .d-flex.gap-2 {
        justify-content: flex-start;
    }
}

/* Additional mobile improvements */
@media (max-width: 576px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    .card-header .d-flex.gap-2 {
        width: 100%;
    }
}
</style>
<?php require_once __DIR__ . '/footer.php'; ?>
