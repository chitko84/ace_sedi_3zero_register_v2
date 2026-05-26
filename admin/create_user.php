<?php
// admin/create_user.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin gate (redirect before any output)
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// ---------- Helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function valOrNull($v){ $v = trim((string)$v); return ($v === '') ? null : $v; }
function is_valid_year4($v){
    if ($v === '' || $v === null) return true; // allow blank (NULL)
    return preg_match('/^\d{4}$/', $v) === 1;
}

// ---------- CSRF ----------
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Defaults / State ----------
$errors = [];
$success = "";
// For sticky form values
$form = [
    'name'   => '',
    'email'  => '',
    'phone_number' => '',
    'date_of_birth' => '',
    'role'   => 'user',
    'department' => '',
    'program_of_study' => '',
    'intake' => '',
    'country' => '',
    'gender' => '',
    'expected_graduation_year' => '',
];

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        // Collect
        $form['name']   = trim($_POST['name'] ?? '');
        $form['email']  = trim($_POST['email'] ?? '');
        $form['phone_number'] = trim($_POST['phone_number'] ?? '');
        $form['date_of_birth'] = trim($_POST['date_of_birth'] ?? '');
        $form['role']   = trim($_POST['role'] ?? 'user');
        $form['department'] = trim($_POST['department'] ?? '');
        $form['program_of_study'] = trim($_POST['program_of_study'] ?? '');
        $form['intake'] = trim($_POST['intake'] ?? '');
        $form['country'] = trim($_POST['country'] ?? '');
        $form['gender'] = trim($_POST['gender'] ?? '');
        $form['expected_graduation_year'] = trim($_POST['expected_graduation_year'] ?? '');

        $password  = trim($_POST['password'] ?? '');
        $password2 = trim($_POST['confirm_password'] ?? '');

        // Validate basics
        if ($form['name'] === '') $errors[] = "Full name is required.";
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
        if ($form['phone_number'] === '') $errors[] = "Phone number is required.";
        if ($form['date_of_birth'] === '') $errors[] = "Date of Birth is required.";
        if (!in_array($form['role'], ['admin','user'], true)) $form['role'] = 'user';
        if (!is_valid_year4($form['expected_graduation_year'])) $errors[] = "Expected graduation year must be 4 digits (e.g., 2029).";

        // Password
        if ($password === '' || $password2 === '') {
            $errors[] = "Password and confirmation are required.";
        } elseif ($password !== $password2) {
            $errors[] = "Password confirmation does not match.";
        } elseif (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters.";
        }

        // Unique email
        $uq = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uq->bind_param("s", $form['email']);
        $uq->execute();
        if ($uq->get_result()->fetch_assoc()) {
            $errors[] = "Email is already in use.";
        }

        // Insert if no errors
        if (!$errors) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Nullable fields
            $dept   = valOrNull($form['department']);
            $prog   = valOrNull($form['program_of_study']);
            $intake = valOrNull($form['intake']);
            $country= valOrNull($form['country']);
            $gender = valOrNull($form['gender']);
            $expyr  = valOrNull($form['expected_graduation_year']);

            // Insert without profile_pic - will use default
            $sql = "INSERT INTO users
                    (name, date_of_birth, phone_number, email, password, role,
                     department, program_of_study, intake, country, gender, expected_graduation_year)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssssssssssss",
                $form['name'],
                $form['date_of_birth'],
                $form['phone_number'],
                $form['email'],
                $hash,
                $form['role'],
                $dept,
                $prog,
                $intake,
                $country,
                $gender,
                $expyr
            );

            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                $_SESSION['success'] = "User created successfully.";
                header('Location: view_user.php?id='.(int)$newId);
                exit();
            } else {
                $errors[] = "Failed to create user. Please try again.";
            }
        }
    }
}

// From here, safe to output HTML
require_once __DIR__ . '/header.php';
?>

<main class="main-content container-fluid">
    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_users.php">Manage Users</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Create User</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="manage_users.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-user-plus me-2"></i>New User</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <strong>Please fix the following:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name" value="<?= h($form['name']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= h($form['email']) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-control" name="phone_number" value="<?= h($form['phone_number']) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth" value="<?= h($form['date_of_birth']) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="user"  <?= $form['role']==='user'  ? 'selected':''; ?>>User</option>
                                <option value="admin" <?= $form['role']==='admin' ? 'selected':''; ?>>Admin</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" value="<?= h($form['department']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Program of Study</label>
                            <input type="text" class="form-control" name="program_of_study" value="<?= h($form['program_of_study']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Intake</label>
                            <input type="text" class="form-control" name="intake" value="<?= h($form['intake']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Country</label>
                            <input type="text" class="form-control" name="country" value="<?= h($form['country']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <input type="text" class="form-control" name="gender" value="<?= h($form['gender']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Expected Graduation Year</label>
                            <input type="text" class="form-control" name="expected_graduation_year" placeholder="e.g. 2029" value="<?= h($form['expected_graduation_year']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" minlength="8" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" minlength="8" required>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <a href="manage_users.php" class="btn btn-light">Cancel</a>
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-user-plus me-1"></i> Create User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
