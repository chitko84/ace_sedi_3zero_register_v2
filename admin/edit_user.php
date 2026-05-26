<?php
// admin/edit_user.php

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function valOrNull($v) {
    $v = trim((string)$v);
    return ($v === '') ? null : $v;
}

function getProfilePicPath($profilePic) {
    $defaultPic = '../uploads/default-profile.jpg';

    if (empty($profilePic) || $profilePic === 'default-profile.jpg') {
        return $defaultPic;
    }

    if (strpos($profilePic, 'uploads/profiles/') !== false) {
        $fullPath = '../' . $profilePic;
    } else {
        $fullPath = '../uploads/profiles/' . $profilePic;
    }

    return file_exists($fullPath) ? $fullPath : $defaultPic;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$csrf_token = $_SESSION['csrf_token'];

$uid = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($uid <= 0) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: manage_users.php');
    exit();
}

$sql = "SELECT 
            id,
            name,
            date_of_birth,
            phone_number,
            email,
            role,
            profile_pic,
            department,
            program_of_study,
            intake,
            country,
            gender,
            area_of_interest,
            expected_graduation_year,
            created_at
        FROM users
        WHERE id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $uid);
$stmt->execute();

$editUser = $stmt->get_result()->fetch_assoc();

if (!$editUser) {
    $_SESSION['error'] = "User not found.";
    header('Location: manage_users.php');
    exit();
}

$departmentOptions = [
    "School Of Business & Social Sciences",
    "School Of Education & Human Sciences",
    "School Of Computing and Informatics",
    "Centre for Foundation and General Studies"
];

$graduationOptions = [
    "March 2025", "December 2025",
    "March 2026", "December 2026",
    "March 2027", "December 2027",
    "March 2028", "December 2028",
    "March 2029", "December 2029",
    "March 2030", "December 2030"
];

$areaOptions = [
    "Zero Poverty",
    "Zero Unemployment",
    "Zero Net Carbon Emission"
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = "Invalid session token.";
    } else {

        $name      = valOrNull($_POST['name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = valOrNull($_POST['phone_number'] ?? '');
        $dob       = valOrNull($_POST['date_of_birth'] ?? '');
        $role      = trim($_POST['role'] ?? 'user');
        $dept      = valOrNull($_POST['department'] ?? '');
        $prog      = valOrNull($_POST['program_of_study'] ?? '');
        $intake    = valOrNull($_POST['intake'] ?? '');
        $country   = valOrNull($_POST['country'] ?? '');
        $gender    = valOrNull($_POST['gender'] ?? '');
        $interest  = valOrNull($_POST['area_of_interest'] ?? '');
        $expyr     = valOrNull($_POST['expected_graduation_year'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter valid email.";
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            $errors[] = "Invalid role.";
        }

        if ($email !== '') {

            $uq = $conn->prepare("
                SELECT id 
                FROM users 
                WHERE email = ? AND id <> ?
                LIMIT 1
            ");

            $uq->bind_param("si", $email, $uid);
            $uq->execute();

            if ($uq->get_result()->fetch_assoc()) {
                $errors[] = "Email already exists.";
            }
        }

        if (!$errors) {

            $sqlU = "UPDATE users SET
                        name = ?,
                        email = ?,
                        phone_number = ?,
                        date_of_birth = ?,
                        role = ?,
                        department = ?,
                        program_of_study = ?,
                        intake = ?,
                        country = ?,
                        gender = ?,
                        area_of_interest = ?,
                        expected_graduation_year = ?
                     WHERE id = ?
                     LIMIT 1";

            $stU = $conn->prepare($sqlU);

            $types = "ssssssssssssi";

            $stU->bind_param(
                $types,
                $name,
                $email,
                $phone,
                $dob,
                $role,
                $dept,
                $prog,
                $intake,
                $country,
                $gender,
                $interest,
                $expyr,
                $uid
            );

            if ($stU->execute()) {

                $_SESSION['success'] = "User updated successfully.";

                header('Location: view_user.php?id=' . $uid);
                exit();

            } else {
                $errors[] = "Update failed.";
            }
        }
    }
}

require_once __DIR__ . '/header.php';

$profilePhoto = getProfilePicPath($editUser['profile_pic']);
?>

<main class="main-content container-fluid">

    <div class="row g-3">

        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">

            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <a href="dashboard.php">Dashboard</a>
                    </li>

                    <li class="breadcrumb-item">
                        <a href="manage_users.php">Manage Users</a>
                    </li>

                    <li class="breadcrumb-item active">
                        Edit User
                    </li>
                </ol>
            </nav>

            <a href="view_user.php?id=<?= (int)$uid ?>" class="btn btn-light">
                Back
            </a>

        </div>

        <div class="col-12">

            <div class="card border-0 shadow-sm">

                <div class="card-body">

                    <div class="d-flex align-items-center gap-3 mb-4">

                        <img
                            src="<?= h($profilePhoto) ?>"
                            onerror="this.src='../uploads/default-profile.jpg'"
                            class="rounded-circle"
                            style="width:80px;height:80px;object-fit:cover;"
                        >

                        <div>
                            <h4 class="mb-1">
                                <?= h($editUser['name'] ?: 'Unnamed User') ?>
                            </h4>

                            <div class="text-muted small">
                                <?= h($editUser['email']) ?>
                            </div>
                        </div>

                    </div>

                    <?php if (!empty($errors)): ?>

                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li><?= h($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                    <?php endif; ?>

                    <form method="POST" class="row g-3">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($csrf_token) ?>"
                        >

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                value="<?= h($editUser['name'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                value="<?= h($editUser['email'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>

                            <input
                                type="text"
                                name="phone_number"
                                class="form-control"
                                value="<?= h($editUser['phone_number'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Date of Birth</label>

                            <input
                                type="date"
                                name="date_of_birth"
                                class="form-control"
                                value="<?= h($editUser['date_of_birth'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Role</label>

                            <select name="role" class="form-select">

                                <option value="user"
                                    <?= ($editUser['role'] ?? '') === 'user' ? 'selected' : '' ?>>
                                    User
                                </option>

                                <option value="admin"
                                    <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>
                                    Admin
                                </option>

                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Department</label>

                            <select class="form-select" name="department">

                                <option value="">
                                    -- Select Department --
                                </option>

                                <?php foreach ($departmentOptions as $option): ?>

                                    <option
                                        value="<?= h($option) ?>"
                                        <?= ($editUser['department'] ?? '') === $option ? 'selected' : '' ?>
                                    >
                                        <?= h($option) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Program of Study</label>

                            <input
                                type="text"
                                name="program_of_study"
                                class="form-control"
                                value="<?= h($editUser['program_of_study'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Intake</label>

                            <input
                                type="text"
                                name="intake"
                                class="form-control"
                                value="<?= h($editUser['intake'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Country</label>

                            <input
                                type="text"
                                name="country"
                                class="form-control"
                                value="<?= h($editUser['country'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Gender</label>

                            <select name="gender" class="form-select">

                                <option value="">
                                    -- Select Gender --
                                </option>

                                <option value="Male"
                                    <?= ($editUser['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>
                                    Male
                                </option>

                                <option value="Female"
                                    <?= ($editUser['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>
                                    Female
                                </option>

                                <option value="Other"
                                    <?= ($editUser['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>
                                    Other
                                </option>

                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Area of Interest</label>

                            <select name="area_of_interest" class="form-select">

                                <option value="">
                                    -- Select Area of Interest --
                                </option>

                                <?php foreach ($areaOptions as $option): ?>

                                    <option
                                        value="<?= h($option) ?>"
                                        <?= ($editUser['area_of_interest'] ?? '') === $option ? 'selected' : '' ?>
                                    >
                                        <?= h($option) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Expected Graduation
                            </label>

                            <select
                                name="expected_graduation_year"
                                class="form-select"
                            >

                                <option value="">
                                    -- Select Graduation --
                                </option>

                                <?php foreach ($graduationOptions as $option): ?>

                                    <option
                                        value="<?= h($option) ?>"
                                        <?= ($editUser['expected_graduation_year'] ?? '') === $option ? 'selected' : '' ?>
                                    >
                                        <?= h($option) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">

                            <a
                                href="view_user.php?id=<?= (int)$uid ?>"
                                class="btn btn-light"
                            >
                                Cancel
                            </a>

                            <button type="submit" class="btn btn-primary">
                                Save Changes
                            </button>

                        </div>

                    </form>

                </div>

                <div class="card-footer bg-white">

                    <small class="text-muted">

                        Created:

                        <?php if (!empty($editUser['created_at'])): ?>

                            <?= h(date('M j, Y g:i A', strtotime($editUser['created_at']))) ?>

                        <?php else: ?>

                            Unknown

                        <?php endif; ?>

                    </small>

                </div>

            </div>

        </div>

    </div>

</main>

<?php require_once __DIR__ . '/footer.php'; ?>