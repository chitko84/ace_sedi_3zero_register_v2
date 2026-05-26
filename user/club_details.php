<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: myclubs.php');
    exit();
}

$club_id = (int)$_GET['id'];
$user_id = (int)$_SESSION['user_id'];

// Helper for HTML escaping
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Get user email
$user_sql = "SELECT email FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

if (!$user) {
    header('Location: myclubs.php');
    exit();
}

// Get club details (c.* will include club_identifier and cluster if they exist in DB)
$sql = "SELECT c.*, cm.member_type 
        FROM clubs c 
        JOIN club_members cm ON c.id = cm.club_id 
        WHERE c.id = ? AND cm.email = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $club_id, $user['email']);
$stmt->execute();
$result = $stmt->get_result();
$club = $result->fetch_assoc();
$stmt->close();

if (!$club) {
    header('Location: myclubs.php');
    exit();
}

// Get all members (show FULL details)
$members_sql = "SELECT * FROM club_members 
                WHERE club_id = ? 
                ORDER BY 
                    CASE member_type 
                        WHEN 'key_person' THEN 1 
                        WHEN 'deputy' THEN 2 
                        ELSE 3 
                    END,
                    full_name ASC";
$members_stmt = $conn->prepare($members_sql);
$members_stmt->bind_param("i", $club_id);
$members_stmt->execute();
$members_result = $members_stmt->get_result();
$members = [];
while ($row = $members_result->fetch_assoc()) {
    $members[] = $row;
}
$members_stmt->close();

// Optional: total members count
$count_sql = "SELECT COUNT(*) AS c FROM club_members WHERE club_id = ?";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param("i", $club_id);
$count_stmt->execute();
$count_res = $count_stmt->get_result()->fetch_assoc();
$total_members = (int)($count_res['c'] ?? 0);
$count_stmt->close();

// Small helper for cluster badge color
function cluster_badge_class($cluster_value) {
    switch ($cluster_value) {
        case 'Zero Poverty':
            return 'danger';
        case 'Zero Unemployment':
            return 'primary';
        case 'Zero Net Carbon Emissions':
            return 'success';
        default:
            return 'secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($club['group_name']) ?> - Club Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include('header.php'); ?>
    
    <div class="container mt-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="myclubs.php">My Clubs</a></li>
                <li class="breadcrumb-item active"><?= e($club['group_name']) ?></li>
            </ol>
        </nav>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h3 class="mb-0"><?= e($club['group_name']) ?></h3>
                        <!-- Club ID shown on the header right -->
                        <?php if (!empty($club['club_identifier'])): ?>
                            <span class="badge bg-light text-dark">
                                <i class="fa-solid fa-id-card-clip me-1"></i>
                                Club ID: <?= e($club['club_identifier']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Club Advisor:</strong>
                                    <?= e($club['cluster_advisor']) ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Key Person:</strong>
                                    <?= e($club['key_person_name']) ?>
                                    <?php if (!empty($club['key_person_student_id'])): ?>
                                        (<?= e($club['key_person_student_id']) ?>)
                                    <?php endif; ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Deputy Key Person:</strong>
                                    <?= e($club['deputy_key_person_name'] ?: 'Not specified') ?>
                                    <?php if (!empty($club['deputy_key_person_student_id'])): ?>
                                        (<?= e($club['deputy_key_person_student_id']) ?>)
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Registration Date:</strong>
                                    <?= e(date('F j, Y', strtotime($club['date_of_registration']))) ?>
                                </p>
                                <p class="mb-2">
                                    <strong>Status:</strong> 
                                    <span class="badge bg-<?= 
                                        $club['status'] === 'approved' ? 'success' : 
                                        ($club['status'] === 'pending' ? 'warning' : 'danger')
                                    ?>">
                                        <?= e(ucfirst($club['status'])) ?>
                                    </span>
                                </p>
                                <p class="mb-2">
                                    <strong>Your Role:</strong> 
                                    <span class="badge bg-info">
                                        <?= e(ucfirst(str_replace('_', ' ', $club['member_type']))) ?>
                                    </span>
                                </p>
                                <!-- Cluster row -->
                                <?php if (!empty($club['cluster'])): ?>
                                <p class="mb-0">
                                    <strong>Cluster:</strong>
                                    <span class="badge bg-<?= cluster_badge_class($club['cluster']); ?>">
                                        <?= e($club['cluster']) ?>
                                    </span>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-3">
                            <span class="text-muted">Total Members:</span>
                            <span class="fw-semibold"><?= (int)$total_members ?></span>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Club Members</h5>
                        <small class="text-muted">Full details</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Student ID</th>
                                        <th>Programme</th>
                                        <th>Nationality</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>School/Centre</th>
                                        <th>Intake (Month/Year)</th>
                                        <th>Expected Grad. Year</th>
                                        <th>Current Semester</th>
                                        <th>Role</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $member): ?>
                                    <tr>
                                        <td><?= e($member['full_name'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['student_id'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['programme'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['nationality'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['email'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['phone'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['school_centre'] ?? 'Not provided') ?></td>
                                        <td><?= e($member['intake_month_year'] ?? 'Not provided') ?></td>
                                        <td><?= e(isset($member['expected_graduation_year']) ? $member['expected_graduation_year'] : 'Not provided') ?></td>
                                        <td><?= e($member['current_semester'] ?? 'Not provided') ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                ($member['member_type'] === 'key_person') ? 'primary' : 
                                                (($member['member_type'] === 'deputy') ? 'success' : 'warning')
                                            ?>">
                                                <?= e(ucfirst(str_replace('_', ' ', $member['member_type'] ?? 'regular'))) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($members)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-muted">No members yet.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="myclubs.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left me-2"></i>Back to My Clubs
                            </a>
                            <?php if ($club['status'] === 'pending' || $club['status'] === 'rejected'): ?>
                            <a href="edit_club.php?id=<?= (int)$club['id'] ?>" class="btn btn-outline-warning">
                                <i class="fas fa-edit me-2"></i>Edit Club
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Optional: summary card showing Club ID & Cluster compactly -->
                <div class="card mt-3">
                    <div class="card-body">
                        <div class="d-flex flex-column gap-2">
                            <div>
                                <small class="text-muted d-block">Club ID</small>
                                <span class="fw-semibold"><?= e($club['club_identifier'] ?? '—') ?></span>
                            </div>
                            <div>
                                <small class="text-muted d-block">Cluster</small>
                                <?php if (!empty($club['cluster'])): ?>
                                    <span class="badge bg-<?= cluster_badge_class($club['cluster']); ?>">
                                        <?= e($club['cluster']) ?>
                                    </span>
                                <?php else: ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <small class="text-muted d-block">Club Advisor</small>
                                <span class="fw-semibold"><?= e($club['cluster_advisor']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- /optional -->
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>
