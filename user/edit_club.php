<?php
include '../includes/db.php';
session_start();

/* Make mysqli throw exceptions for cleaner error handling */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

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

/* ---------------------------
   Get user email and identity
----------------------------*/
$user_sql = "SELECT id, email FROM users WHERE id = ?";
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
$user_email = $user['email'];

/* -------------------------------------------------
   OPTIONS (needed for PHP-rendered selects + for JS)
   Keep in one place to avoid "empty dropdown" bugs
--------------------------------------------------*/
$programmeOptions = [
    "Bachelor of Business Administration (Honours)",
    "Bachelor of Business Administration with Computer Science (Honours)",
    "Bachelor of Business Administration (Honours) (Marketing)",
    "Bachelor of Business Administration (Honours) (Human Resource Management)",
    "Bachelor of Economics (Honours)",
    "Bachelor of Social Development (Honours)",
    "Bachelor of Finance (Islamic Finance) (Honours)",
    "Bachelor of Politics and International Relations (Honours)",
    "Master of Business Management",
    "Master in Social Business",
    "Doctor of Philosophy (Business Management)",
    "Bachelor of Elementary Education (Honours)",
    "Bachelor in Early Childhood Education (Honours)",
    "Bachelor of Media and Communication (Honours)",
    "Master of Education",
    "Doctor of Philosophy (Education)",
    "Bachelor in Computer Science (Honours)",
    "Bachelor in Data Science (Honours)",
    "Foundation in Computing",
    "Foundation in Arts",
];

$countries = [
    "Afghanistan","Albania","Algeria","Andorra","Angola","Antigua and Barbuda","Argentina","Armenia","Australia","Austria","Azerbaijan","Bahamas","Bahrain","Bangladesh","Barbados","Belarus","Belgium","Belize","Benin","Bhutan","Bolivia","Bosnia and Herzegovina","Botswana","Brazil","Brunei","Bulgaria","Burkina Faso","Burundi","Cabo Verde","Cambodia","Cameroon","Canada","Central African Republic","Chad","Chile","China","Colombia","Comoros","Congo (Congo-Brazzaville)","Costa Rica","Croatia","Cuba","Cyprus","Czechia (Czech Republic)","Democratic Republic of the Congo","Denmark","Djibouti","Dominica","Dominican Republic","Ecuador","Egypt","El Salvador","Equatorial Guinea","Eritrea","Estonia","Eswatini","Ethiopia","Fiji","Finland","France","Gabon","Gambia","Georgia","Germany","Ghana","Greece","Grenada","Guatemala","Guinea","Guinea-Bissau","Guyana","Haiti","Honduras","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland","Israel","Italy","Jamaica","Japan","Jordan","Kazakhstan","Kenya","Kiribati","Kuwait","Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya","Liechtenstein","Lithuania","Luxembourg","Madagascar","Malawi","Malaysia","Maldives","Mali","Malta","Marshall Islands","Mauritania","Mauritius","Mexico","Micronesia","Moldova","Monaco","Mongolia","Montenegro","Morocco","Mozambique","Myanmar","Namibia","Nauru","Nepal","Netherlands","New Zealand","Nicaragua","Niger","Nigeria","North Korea","North Macedonia","Norway","Oman","Pakistan","Palau","Palestine","Panama","Papua New Guinea","Paraguay","Peru","Philippines","Poland","Portugal","Qatar","Romania","Russia","Rwanda","Saint Kitts and Nevis","Saint Lucia","Saint Vincent and the Grenadines","Samoa","San Marino","Sao Tome and Principe","Saudi Arabia","Senegal","Serbia","Seychelles","Sierra Leone","Singapore","Slovakia","Slovenia","Solomon Islands","Somalia","South Africa","South Korea","South Sudan","Spain","Sri Lanka","Sudan","Suriname","Sweden","Switzerland","Syria","Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tonga","Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Tuvalu","Uganda","Ukraine","United Arab Emirates","United Kingdom","United States of America","Uruguay","Uzbekistan","Vanuatu","Venezuela","Vietnam","Yemen","Zambia","Zimbabwe"
];

$currentSemesterOptions = [
    "CFGS Sem 1","CFGS Sem 2","CFGS Sem 3",
    "Year 1 Sem 1","Year 1 Sem 2","Year 1 Sem 3",
    "Year 2 Sem 1","Year 2 Sem 2","Year 2 Sem 3",
    "Year 3 Sem 1","Year 3 Sem 2","Year 3 Sem 3"
];

$graduationOptions = [
    "March 2025","December 2025",
    "March 2026","December 2026",
    "March 2027","December 2027",
    "March 2028","December 2028",
    "March 2029","December 2029",
    "March 2030","December 2030"
];

/* ---------------------------
   Get club + user's role
----------------------------*/
$sql = "SELECT c.*, cm.member_type 
        FROM clubs c 
        JOIN club_members cm ON c.id = cm.club_id 
        WHERE c.id = ? AND cm.email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $club_id, $user_email);
$stmt->execute();
$result = $stmt->get_result();
$club = $result->fetch_assoc();
$stmt->close();

if (!$club) {
    header('Location: myclubs.php');
    exit();
}

$user_role = $club['member_type']; // key_person | deputy | regular
$user_can_edit_all = in_array($user_role, ['key_person', 'deputy'], true);

/* ---------------------------
   Fetch all members of club
----------------------------*/
$members = [];
$mstmt = $conn->prepare("SELECT * FROM club_members WHERE club_id = ? ORDER BY 
               CASE member_type WHEN 'key_person' THEN 1 WHEN 'deputy' THEN 2 ELSE 3 END, id ASC");
$mstmt->bind_param("i", $club_id);
$mstmt->execute();
$mres = $mstmt->get_result();
while ($row = $mres->fetch_assoc()) $members[] = $row;
$mstmt->close();

/* ---------------------------
   Handle POST (club + members)
----------------------------*/
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- CLUB FIELDS (all optional on edit) ---
    $club_identifier = trim($_POST['club_identifier'] ?? '');
    $group_name = trim($_POST['group_name'] ?? '');
    $cluster_advisor = trim($_POST['cluster_advisor'] ?? '');
    $key_person_name = trim($_POST['key_person_name'] ?? '');
    $key_person_student_id = trim($_POST['key_person_student_id'] ?? '');
    $deputy_key_person_name = trim($_POST['deputy_key_person_name'] ?? '');
    $deputy_key_person_student_id = trim($_POST['deputy_key_person_student_id'] ?? '');
    $cluster = trim($_POST['cluster'] ?? '');

    // Light validation only
    if ($club_identifier !== '' && !preg_match('/^[0-9-]+$/', $club_identifier)) {
        $errors[] = "Club ID must contain only digits and dashes";
    }
    $allowed_clusters = ['Zero Poverty', 'Zero Unemployment', 'Zero Net Carbon Emissions'];
    if ($cluster !== '' && !in_array($cluster, $allowed_clusters, true)) {
        $errors[] = "Invalid cluster selected";
    }

    // --- MEMBERS ARRAYS (each row optional; user can edit anything) ---
    $members_post = $_POST['members'] ?? [];

    // Validate member count (max 5)
    $total_members_after_operation = 0;
    
    // count existing members that are NOT being deleted
    foreach ($members as $existing_member) {
        $is_being_deleted = false;
        foreach ($members_post as $row) {
            $mid = isset($row['id']) ? (int)$row['id'] : 0;
            $is_delete = isset($row['delete']) && $row['delete'] === '1';
            if ($mid === (int)$existing_member['id'] && $is_delete) {
                $is_being_deleted = true;
                break;
            }
        }
        if (!$is_being_deleted) $total_members_after_operation++;
    }

    // count new members being added
    foreach ($members_post as $row) {
        $mid = isset($row['id']) ? (int)$row['id'] : 0;
        $is_delete = isset($row['delete']) && $row['delete'] === '1';
        if ($mid === 0 && !$is_delete) $total_members_after_operation++;
    }

    if ($total_members_after_operation > 5) {
        $errors[] = "Total members cannot exceed 5. You currently have {$total_members_after_operation} members.";
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // UPDATE CLUB (set status back to pending to re-approve changes)
            $update_sql = "UPDATE clubs SET 
                              club_identifier = ?, 
                              group_name = ?, 
                              cluster = ?, 
                              cluster_advisor = ?, 
                              key_person_name = ?, 
                              key_person_student_id = ?,
                              deputy_key_person_name = ?,
                              deputy_key_person_student_id = ?,
                              status = 'pending'
                           WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            
            $club_identifier_value = ($club_identifier === '') ? NULL : $club_identifier;
            
            $update_stmt->bind_param(
                "ssssssssi",
                $club_identifier_value,
                $group_name,
                $cluster,
                $cluster_advisor,
                $key_person_name,
                $key_person_student_id,
                $deputy_key_person_name,
                $deputy_key_person_student_id,
                $club_id
            );
            $update_stmt->execute();
            $update_stmt->close();

            // MEMBERS UPSERT/DELETE
            $upd = $conn->prepare("UPDATE club_members SET 
                    full_name=?, student_id=?, programme=?, nationality=?, phone=?, email=?, school_centre=?, 
                    intake_month_year=?, expected_graduation_year=?, current_semester=?, member_type=?
                WHERE id=? AND club_id=?");
            
            $ins = $conn->prepare("INSERT INTO club_members
                (club_id, full_name, student_id, programme, nationality, phone, email, school_centre, intake_month_year, expected_graduation_year, current_semester, member_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $del = $conn->prepare("DELETE FROM club_members WHERE id=? AND club_id=?");

            // map (id => row) for permission checks
            $current_rows_map = [];
            foreach ($members as $m) $current_rows_map[(int)$m['id']] = $m;

            foreach ($members_post as $row) {
                $mid = isset($row['id']) ? (int)$row['id'] : 0;
                $is_delete = isset($row['delete']) && $row['delete'] === '1';

                // Permission: regular users may only touch their own row
                if (!$user_can_edit_all) {
                    if ($mid > 0) {
                        if (!isset($current_rows_map[$mid]) || $current_rows_map[$mid]['email'] !== $user_email) {
                            continue;
                        }
                    } else {
                        // insert allowed only if email equals current user's email
                        if (trim($row['email'] ?? '') !== $user_email) continue;
                    }
                }

                if ($mid > 0 && $is_delete) {
                    $del->bind_param("ii", $mid, $club_id);
                    $del->execute();
                    continue;
                }

                // Normalize fields (all optional)
                $f_full_name = trim($row['full_name'] ?? '');
                $f_student_id = trim($row['student_id'] ?? '');
                $f_programme = trim($row['programme'] ?? '');
                $f_nationality = trim($row['nationality'] ?? '');
                $f_phone = trim($row['phone'] ?? '');
                $f_email = trim($row['email'] ?? '');
                $f_school = trim($row['school_centre'] ?? '');
                $f_intake = trim($row['intake_month_year'] ?? '');
                $f_grad = trim($row['expected_graduation_year'] ?? '');
                $f_sem = trim($row['current_semester'] ?? '');
                $f_role = $row['member_type'] ?? 'regular';
                if (!in_array($f_role, ['key_person','deputy','regular'], true)) $f_role = 'regular';

                // Skip empty new rows
                $has_any_content = ($f_full_name !== '' || $f_student_id !== '' || $f_programme !== '' || $f_nationality !== '' ||
                                    $f_phone !== '' || $f_email !== '' || $f_school !== '' || $f_intake !== '' ||
                                    $f_grad !== '' || $f_sem !== '' || $f_role !== '');
                if ($mid === 0 && !$has_any_content) continue;

                if ($mid > 0) {
                    $upd->bind_param(
                        "sssssssssssii",
                        $f_full_name, $f_student_id, $f_programme, $f_nationality, $f_phone, $f_email,
                        $f_school, $f_intake, $f_grad, $f_sem, $f_role,
                        $mid, $club_id
                    );
                    $upd->execute();
                } else {
                    $ins->bind_param(
                        "isssssssssss",
                        $club_id, $f_full_name, $f_student_id, $f_programme, $f_nationality, $f_phone, $f_email,
                        $f_school, $f_intake, $f_grad, $f_sem, $f_role
                    );
                    $ins->execute();
                }
            }

            if (isset($upd)) $upd->close();
            if (isset($ins)) $ins->close();
            if (isset($del)) $del->close();

            $conn->commit();
            $_SESSION['success'] = "Club and members updated. Status reset to Pending for approval.";
            header('Location: myclubs.php');
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Club update error: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Rest of your HTML code remains the same...
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?= htmlspecialchars($club['group_name']) ?> - 3ZERO Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .table thead th { white-space: nowrap; }
        .form-control, .form-select { min-width: 120px; }
        .badge-role { text-transform: capitalize; }
        .sticky-actions { position: sticky; bottom: 0; background: #fff; padding-top: .5rem; padding-bottom: .5rem; }
        .member-count-alert { background-color: #fff3cd; border-color: #ffeaa7; }
    </style>
</head>
<body>
<?php include('header.php'); ?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="myclubs.php">My Clubs</a></li>
            <li class="breadcrumb-item"><a href="club_details.php?id=<?= (int)$club_id ?>"><?= htmlspecialchars($club['group_name']) ?></a></li>
            <li class="breadcrumb-item active">Edit Club & Members</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <div class="card">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h3 class="mb-0">Edit Club: <?= htmlspecialchars($club['group_name']) ?></h3>
                    <?php if (!empty($club['club_identifier'])): ?>
                        <span class="badge bg-dark-subtle text-dark">
                            <i class="fa-solid fa-id-card-clip me-1"></i> Club ID: <?= htmlspecialchars($club['club_identifier']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['success']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <!-- Member Count Alert -->
                    <div class="alert member-count-alert d-flex align-items-center mb-4">
                        <i class="fas fa-users me-2 fs-5"></i>
                        <div>
                            <strong>Member Limit:</strong> Maximum 5 members allowed. 
                            Current count: <span class="badge bg-primary"><?= count($members) ?></span>
                            <?php if (count($members) >= 5): ?>
                                <span class="badge bg-danger ms-2">Maximum reached</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST">
                        <!-- ===== Club core info (no *required) ===== -->
                        <h5 class="mb-3">Club Information</h5>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="club_identifier" class="form-label">Club ID</label>
                                <input type="text" class="form-control" id="club_identifier" name="club_identifier"
                                       value="<?= htmlspecialchars($_POST['club_identifier'] ?? $club['club_identifier'] ?? '') ?>"
                                       placeholder="e.g., 2025-001-07"
                                       pattern="^[0-9-]+$" title="Digits and dashes only">
                            </div>
                            <div class="col-md-8">
                                <label for="group_name" class="form-label">Group Name</label>
                                <input type="text" class="form-control" id="group_name" name="group_name"
                                       value="<?= htmlspecialchars($_POST['group_name'] ?? $club['group_name']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="cluster" class="form-label">Cluster</label>
                                <?php $currentCluster = $_POST['cluster'] ?? $club['cluster'] ?? ''; ?>
                                <select class="form-select" id="cluster" name="cluster">
                                    <option value="" <?= $currentCluster === '' ? 'selected' : '' ?>>-- Choose cluster --</option>
                                    <option value="Zero Poverty" <?= $currentCluster === 'Zero Poverty' ? 'selected' : '' ?>>Zero Poverty</option>
                                    <option value="Zero Unemployment" <?= $currentCluster === 'Zero Unemployment' ? 'selected' : '' ?>>Zero Unemployment</option>
                                    <option value="Zero Net Carbon Emissions" <?= $currentCluster === 'Zero Net Carbon Emissions' ? 'selected' : '' ?>>Zero Net Carbon Emissions</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="cluster_advisor" class="form-label">Cluster Advisor</label>
                                <input type="text" class="form-control" id="cluster_advisor" name="cluster_advisor"
                                       value="<?= htmlspecialchars($_POST['cluster_advisor'] ?? $club['cluster_advisor']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="key_person_name" class="form-label">Key Person Name</label>
                                <input type="text" class="form-control" id="key_person_name" name="key_person_name"
                                       value="<?= htmlspecialchars($_POST['key_person_name'] ?? $club['key_person_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="key_person_student_id" class="form-label">Key Person Student ID</label>
                                <input type="text" class="form-control" id="key_person_student_id" name="key_person_student_id"
                                       value="<?= htmlspecialchars($_POST['key_person_student_id'] ?? $club['key_person_student_id']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="deputy_key_person_name" class="form-label">Deputy Key Person Name</label>
                                <input type="text" class="form-control" id="deputy_key_person_name" name="deputy_key_person_name"
                                       value="<?= htmlspecialchars($_POST['deputy_key_person_name'] ?? $club['deputy_key_person_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="deputy_key_person_student_id" class="form-label">Deputy Key Person Student ID</label>
                                <input type="text" class="form-control" id="deputy_key_person_student_id" name="deputy_key_person_student_id"
                                       value="<?= htmlspecialchars($_POST['deputy_key_person_student_id'] ?? $club['deputy_key_person_student_id']) ?>">
                            </div>

                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    After saving, the club status is set to <strong>Pending</strong> for approval.
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <!-- ===== Members editable table (dropdowns like registration; no required) ===== -->
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h5 class="mb-0">Club Members</h5>
                            <button type="button" class="btn btn-sm btn-success" id="addMemberBtn" <?= ($user_can_edit_all && count($members) < 5) ? '' : 'disabled' ?>>
                                <i class="fa fa-plus me-1"></i> Add Member
                                <?php if (!$user_can_edit_all): ?>
                                    <small class="d-block">(Only key person/deputy can add)</small>
                                <?php elseif (count($members) >= 5): ?>
                                    <small class="d-block">(Max 5 reached)</small>
                                <?php endif; ?>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle" id="membersTable">
                                <thead class="table-light">
                                <tr>
                                    <th style="min-width:180px;">Full Name</th>
                                    <th>Student ID</th>
                                    <th>Programme</th>
                                    <th>Nationality</th>
                                    <th>Phone</th>
                                    <th style="min-width:200px;">Email</th>
                                    <th>School/Centre</th>
                                    <th>Intake (Month/Year)</th>
                                    <th style="min-width:160px;">Expected Grad.</th>
                                    <th>Current Semester</th>
                                    <th>Role</th>
                                    <th>Delete</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($members as $i => $m):
                                    $is_self_row = ($m['email'] === $user_email);
                                    $row_disabled = (!$user_can_edit_all && !$is_self_row) ? 'disabled' : '';
                                ?>
                                    <tr>
                                        <input type="hidden" name="members[<?= $i ?>][id]" value="<?= (int)$m['id'] ?>">
                                        <td>
                                            <input type="text" class="form-control" name="members[<?= $i ?>][full_name]" value="<?= htmlspecialchars($m['full_name']) ?>" <?= $row_disabled ?>>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="members[<?= $i ?>][student_id]" value="<?= htmlspecialchars($m['student_id']) ?>" <?= $row_disabled ?>>
                                        </td>
                                        <td>
                                            <select class="form-select" name="members[<?= $i ?>][programme]" <?= $row_disabled ?>>
                                                <option value=""></option>
                                                <?php foreach ($programmeOptions as $opt): ?>
                                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($m['programme'] === $opt ? 'selected' : '') ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select" name="members[<?= $i ?>][nationality]" <?= $row_disabled ?>>
                                                <option value=""></option>
                                                <?php foreach ($countries as $c): ?>
                                                    <option value="<?= htmlspecialchars($c) ?>" <?= ($m['nationality'] === $c ? 'selected' : '') ?>><?= htmlspecialchars($c) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="members[<?= $i ?>][phone]" value="<?= htmlspecialchars($m['phone']) ?>" <?= $row_disabled ?>>
                                        </td>
                                        <td>
                                            <input type="email" class="form-control" name="members[<?= $i ?>][email]" value="<?= htmlspecialchars($m['email']) ?>" <?= $row_disabled ?>>
                                        </td>
                                        <td>
                                            <select class="form-select" name="members[<?= $i ?>][school_centre]" <?= $row_disabled ?>>
                                                <?php
                                                $schools = [
                                                    "School Of Business & Social Sciences" => "School of Business & Social Sciences",
                                                    "School Of Education & Human Sciences" => "School of Education & Human Sciences",
                                                    "School Of Computing and Informatics"   => "School of Computing and Informatics",
                                                    "Centre for Foundation and General Studies" => "Centre for Foundation and General Studies"
                                                ];
                                                ?>
                                                <option value=""></option>
                                                <?php foreach ($schools as $val => $label): ?>
                                                    <option value="<?= htmlspecialchars($val) ?>" <?= ($m['school_centre'] === $val ? 'selected' : '') ?>><?= htmlspecialchars($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select intake-select" name="members[<?= $i ?>][intake_month_year]" <?= $row_disabled ?>>
                                                <option value=""></option>
                                            </select>
                                            <?php if (!empty($m['intake_month_year'])): ?>
                                                <input type="hidden" class="existing-intake" value="<?= htmlspecialchars($m['intake_month_year']) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <!-- Expected Graduation as STRING dropdown -->
                                            <select class="form-select" name="members[<?= $i ?>][expected_graduation_year]" <?= $row_disabled ?>>
                                                <option value=""></option>
                                                <?php foreach ($graduationOptions as $g): ?>
                                                    <option value="<?= htmlspecialchars($g) ?>" <?= ($m['expected_graduation_year'] === $g ? 'selected' : '') ?>><?= htmlspecialchars($g) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select" name="members[<?= $i ?>][current_semester]" <?= $row_disabled ?>>
                                                <option value=""></option>
                                                <?php foreach ($currentSemesterOptions as $sem): ?>
                                                    <option value="<?= htmlspecialchars($sem) ?>" <?= ($m['current_semester'] === $sem ? 'selected' : '') ?>><?= htmlspecialchars($sem) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select" name="members[<?= $i ?>][member_type]" <?= $row_disabled ?>>
                                                <option value="key_person" <?= $m['member_type']==='key_person' ? 'selected':'' ?>>Key person</option>
                                                <option value="deputy" <?= $m['member_type']==='deputy' ? 'selected':'' ?>>Deputy</option>
                                                <option value="regular" <?= $m['member_type']==='regular' ? 'selected':'' ?>>Regular</option>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input" name="members[<?= $i ?>][delete]" value="1" <?= $user_can_edit_all ? '' : 'disabled title="Only key person/deputy can delete"' ?>>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex gap-2 sticky-actions mt-3">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <a href="club_details.php?id=<?= (int)$club_id ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<?php include('footer.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Add new member row dynamically (front-end only; saving happens on submit)
    const addBtn = document.getElementById('addMemberBtn');
    const tbody = document.querySelector('#membersTable tbody');

    // These are guaranteed because we define them in PHP above
    const programmeOptions = <?= json_encode($programmeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const countries = <?= json_encode($countries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const schools = {
        "School Of Business & Social Sciences": "School of Business & Social Sciences",
        "School Of Education & Human Sciences": "School of Education & Human Sciences",
        "School Of Computing and Informatics": "School of Computing and Informatics",
        "Centre for Foundation and General Studies": "Centre for Foundation and General Studies"
    };
    const semesters = <?= json_encode($currentSemesterOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const gradOptions = <?= json_encode($graduationOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Populate all intake selects (existing rows)
    populateAllIntakeSelects();

    if (addBtn && tbody) {
        addBtn.addEventListener('click', function () {
            const currentRows = tbody.querySelectorAll('tr').length;
            if (currentRows >= 5) {
                alert('Maximum of 5 members allowed. Please delete existing members before adding new ones.');
                return;
            }

            const index = currentRows;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <input type="hidden" name="members[${index}][id]" value="">
                <td><input type="text" class="form-control" name="members[${index}][full_name]" value=""></td>
                <td><input type="text" class="form-control" name="members[${index}][student_id]" value=""></td>
                <td>
                    <select class="form-select" name="members[${index}][programme]">
                        <option value=""></option>
                        ${programmeOptions.map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][nationality]">
                        <option value=""></option>
                        ${countries.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('')}
                    </select>
                </td>
                <td><input type="text" class="form-control" name="members[${index}][phone]" value=""></td>
                <td><input type="email" class="form-control" name="members[${index}][email]" value=""></td>
                <td>
                    <select class="form-select" name="members[${index}][school_centre]">
                        <option value=""></option>
                        ${Object.entries(schools).map(([v,l]) => `<option value="${escapeHtml(v)}">${escapeHtml(l)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select intake-select" name="members[${index}][intake_month_year]">
                        <option value=""></option>
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][expected_graduation_year]">
                        <option value=""></option>
                        ${gradOptions.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][current_semester]">
                        <option value=""></option>
                        ${semesters.map(s => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][member_type]">
                        <option value="key_person">Key person</option>
                        <option value="deputy">Deputy</option>
                        <option value="regular" selected>Regular</option>
                    </select>
                </td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" name="members[${index}][delete]" value="1" disabled title="Can only delete after saved">
                </td>
            `;
            tbody.appendChild(tr);
            populateAllIntakeSelects();
            
            if (currentRows + 1 >= 5) addBtn.disabled = true;
        });
    }

    function populateAllIntakeSelects() {
        const selects = document.querySelectorAll('select.intake-select');
        const now = new Date();
        const currentYear = now.getFullYear();
        const intakeMonths = ["March", "October"];

        selects.forEach(select => {
            const prev = select.value;
            while (select.options.length > 1) select.remove(1);
            for (let y = currentYear - 5; y <= currentYear + 2; y++) {
                intakeMonths.forEach(m => {
                    const label = `${m} ${y} / ${y + 1}`;
                    const opt = document.createElement('option');
                    opt.value = label;
                    opt.textContent = label;
                    select.appendChild(opt);
                });
            }
            const existingInput = select.parentElement.querySelector('.existing-intake');
            const saved = existingInput ? existingInput.value : prev;
            if (saved) {
                const found = Array.from(select.options).some(o => o.value === saved);
                if (!found) {
                    const opt = document.createElement('option');
                    opt.value = saved;
                    opt.textContent = saved;
                    select.appendChild(opt);
                }
                select.value = saved;
            }
        });
    }

    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }

    // Header dropdowns (if any in your header.php)
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
    });
});
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

</body>
</html>
