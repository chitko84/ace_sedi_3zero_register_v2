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

$approval_status = strtolower(trim((string)($club['approval_status'] ?? $club['status'] ?? 'pending')));
$is_approved = ($approval_status === 'approved');
$certificate_club_id = !empty($club['club_identifier']) ? $club['club_identifier'] : $club['id'];
$registration_timestamp = !empty($club['date_of_registration']) ? strtotime($club['date_of_registration']) : false;
$certificate_registration_date = $registration_timestamp ? date('F j, Y', $registration_timestamp) : 'Date not available';
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
    <style>
        :root {
            --cert-blue: #1a5276;
            --cert-blue-dark: #154360;
            --cert-gold: #c9952c;
        }
        .certificate-shell {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(21, 67, 96, .12);
            padding: 1.25rem;
            margin: 1.5rem 0 2rem;
        }
        .certificate-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .certificate-paper {
            position: relative;
            background: #fff;
            border: 10px solid var(--cert-blue);
            outline: 2px solid var(--cert-gold);
            outline-offset: -18px;
            padding: 2.25rem;
            min-height: 680px;
            overflow: hidden;
        }
        .certificate-paper::before {
            content: "";
            position: absolute;
            inset: 22px;
            border: 1px solid rgba(201, 149, 44, .45);
            pointer-events: none;
        }
        .certificate-logos {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .certificate-logo {
            max-height: 86px;
            max-width: 150px;
            object-fit: contain;
        }
        .certificate-content {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 780px;
            margin: 0 auto;
        }
        .certificate-title {
            color: var(--cert-blue-dark);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2rem, 5vw, 3.25rem);
            font-weight: 700;
            letter-spacing: .02em;
            margin-bottom: 1.5rem;
        }
        .certificate-subtitle {
            color: #5c6670;
            font-size: 1.15rem;
            margin-bottom: 1rem;
        }
        .certificate-club-name {
            color: var(--cert-blue);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(1.7rem, 4vw, 2.7rem);
            font-weight: 700;
            border-bottom: 2px solid rgba(201, 149, 44, .75);
            display: inline-block;
            padding: .25rem 1.75rem .5rem;
            margin-bottom: 1.5rem;
        }
        .certificate-statement {
            color: #2f3a43;
            font-size: 1.15rem;
            line-height: 1.8;
            margin-bottom: 1.5rem;
        }
        .certificate-details {
            display: inline-grid;
            gap: .5rem;
            text-align: left;
            background: #f8fbfd;
            border: 1px solid #e6eef4;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin: .5rem auto 2rem;
            min-width: min(100%, 420px);
        }
        .certificate-detail-row {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
        }
        .certificate-detail-row strong {
            color: var(--cert-blue-dark);
        }
        .certificate-footer {
            color: var(--cert-blue-dark);
            font-weight: 700;
            margin-top: 2rem;
        }
        .certificate-note {
            border-left: 4px solid #f39c12;
            background: #fff8e8;
            color: #795200;
            padding: 1rem;
            border-radius: 8px;
            margin: 1.5rem 0 2rem;
        }
        @media (max-width: 767.98px) {
            .certificate-paper {
                padding: 1.35rem;
                border-width: 7px;
                outline-offset: -13px;
                min-height: auto;
            }
            .certificate-logos {
                margin-bottom: 1.25rem;
            }
            .certificate-logo {
                max-height: 64px;
                max-width: 110px;
            }
            .certificate-detail-row {
                flex-direction: column;
                gap: .1rem;
            }
        }
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
            body * {
                visibility: hidden !important;
            }
            #certificate, #certificate * {
                visibility: visible !important;
            }
            #certificate {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            .certificate-actions,
            .no-print {
                display: none !important;
            }
            .certificate-shell {
                box-shadow: none !important;
                padding: 0 !important;
            }
            .certificate-paper {
                min-height: 270mm;
                box-shadow: none !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
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
                            <?php if ($is_approved): ?>
                            <a href="#certificate" class="btn btn-outline-success">
                                <i class="fas fa-certificate me-2"></i>View Certificate
                            </a>
                            <?php endif; ?>
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

        <?php if ($is_approved): ?>
        <section id="certificate" class="certificate-shell">
            <div class="certificate-actions no-print">
                <div>
                    <h2 class="h4 mb-1">Approved Club Certificate</h2>
                    <p class="text-muted mb-0">Use your browser print dialog to save this certificate as PDF.</p>
                </div>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Download / Print Certificate
                </button>
            </div>

            <div class="certificate-paper">
                <div class="certificate-logos">
                    <img src="../uploads/aiu_logo.png" class="certificate-logo" alt="Albukhary International University logo">
                    <img src="../uploads/3 zero club logo.png" class="certificate-logo" alt="3ZERO Club logo">
                </div>

                <div class="certificate-content">
                    <div class="certificate-title">Certificate of 3ZERO Club Registration</div>
                    <p class="certificate-subtitle">This certifies that</p>

                    <div class="certificate-club-name">
                        <?= e($club['group_name']) ?> - <?= e($certificate_club_id) ?>
                    </div>

                    <p class="certificate-statement">
                        has been officially registered under the<br>
                        <strong>AIU 3ZERO Club Registration System</strong>
                    </p>

                    <div class="certificate-details">
                        <div class="certificate-detail-row">
                            <strong>Cluster:</strong>
                            <span><?= e($club['cluster'] ?: 'Not specified') ?></span>
                        </div>
                        <div class="certificate-detail-row">
                            <strong>Registration Date:</strong>
                            <span><?= e($certificate_registration_date) ?></span>
                        </div>
                    </div>

                    <div class="certificate-footer">
                        <div>Albukhary International University</div>
                        <div>3ZERO Club Management System</div>
                    </div>
                </div>
            </div>
        </section>
        <?php else: ?>
        <div class="certificate-note no-print">
            <strong>Certificate will be available after the club is approved.</strong>
            <div class="small mt-1">Current status: <?= e(ucfirst($approval_status ?: 'pending')) ?></div>
        </div>
        <?php endif; ?>
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
