<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

include 'includes/db.php';
require_once __DIR__ . '/includes/image_upload_helper.php';

$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);

function redirectWithError($message, $location = 'register.php', $saveOld = true) {
    $_SESSION['error'] = $message;

    if ($saveOld) {
        $_SESSION['old'] = $_POST;
    }

    header("Location: $location");
    exit();
}

$name = $email = $phone_number = $department = $program_of_study = '';
$intake = $country = $gender = $expected_graduation_year = '';
$area_of_interest = $date_of_birth = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        redirectWithError("Please enter a valid email address.");
    }

    $email = trim($_POST['email']);

    $allowedDomains = ['student.aiu.edu.my', 'aiu.edu.my'];
    $emailDomain = substr(strrchr($email, "@"), 1);

    if (!in_array($emailDomain, $allowedDomains, true)) {
        redirectWithError("Only official AIU emails (@student.aiu.edu.my or @aiu.edu.my) are allowed.");
    }

    $stmt = $conn->prepare("SELECT 1 FROM users WHERE email = ?");
    if (!$stmt) {
        redirectWithError("Database preparation failed: " . $conn->error);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "This email is already registered. Please log in.";
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit();
    }

    $stmt->close();

    $name = trim($_POST['name']);
    $date_of_birth = $_POST['date_of_birth'];
    $phone_number = preg_replace('/\D+/', '', $_POST['phone_number']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $department = $_POST['department'] ?? '';
    $program_of_study = $_POST['program_of_study'] ?? '';
    $intake = $_POST['intake'] ?? '';
    $country = $_POST['country'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $expected_graduation_year = $_POST['expected_graduation_year'] ?? '';
    $area_of_interest = $_POST['area_of_interest'] ?? '';

    if (
        empty($name) ||
        empty($date_of_birth) ||
        empty($phone_number) ||
        empty($department) ||
        empty($program_of_study) ||
        empty($intake) ||
        empty($country) ||
        empty($gender) ||
        empty($expected_graduation_year) ||
        empty($area_of_interest)
    ) {
        redirectWithError("Please fill in all required fields.");
    }

    if (!preg_match('/^\d{5,}$/', $phone_number)) {
        redirectWithError("Enter a valid phone number (at least 5 digits).");
    }

    if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*?&#^_\-\+=\/\.,;:{}\[\]()|]).{8,}$/', $password)) {
        redirectWithError("Password must be 8+ chars with letters, numbers, and a symbol.");
    }

    if ($password !== $confirm_password) {
        redirectWithError("Passwords do not match!");
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $profile_pic = '';

    if (
        isset($_FILES['profile_pic']) &&
        is_uploaded_file($_FILES['profile_pic']['tmp_name']) &&
        $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK
    ) {
        $validated = image_upload_validate_file($_FILES['profile_pic']);
        if (!$validated['ok']) {
            redirectWithError($validated['error'] ?: "Invalid image file.");
        }

        $upload_dir = __DIR__ . '/uploads/profiles/';
        $moved = image_upload_move_validated($validated, $upload_dir, 'uploads/profiles', 'profile');
        if (!$moved['ok']) {
            redirectWithError($moved['error'] ?: "Failed to upload profile picture.");
        }

        $profile_pic = $moved['db_path'];

    } else {
        redirectWithError("Please upload your profile picture. " . IMAGE_UPLOAD_SIZE_ERROR);
    }

    $role = 'user';

    $sql = "INSERT INTO users
            (
                name,
                date_of_birth,
                phone_number,
                email,
                password,
                role,
                profile_pic,
                department,
                program_of_study,
                intake,
                country,
                gender,
                area_of_interest,
                expected_graduation_year
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        redirectWithError("Database error: " . $conn->error);
    }

    $stmt->bind_param(
        "ssssssssssssss",
        $name,
        $date_of_birth,
        $phone_number,
        $email,
        $hashed_password,
        $role,
        $profile_pic,
        $department,
        $program_of_study,
        $intake,
        $country,
        $gender,
        $area_of_interest,
        $expected_graduation_year
    );

    if ($stmt->execute()) {

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $appUrl = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/login.php';

        $subjectUser = "Welcome to 3ZERO Club - Registration Successful";

        $bodyUser = '
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222">
                <h2 style="margin:0 0 12px;color:#1a5276">Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</h2>
                <p>Your registration to <strong>3ZERO Club</strong> was <strong>successful</strong>!</p>
                <p>Here are your details:</p>
                <ul>
                    <li><strong>Name:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Department:</strong> ' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Program:</strong> ' . htmlspecialchars($program_of_study, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Intake:</strong> ' . htmlspecialchars($intake, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Area of Interest:</strong> ' . htmlspecialchars($area_of_interest, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Expected Graduation:</strong> ' . htmlspecialchars($expected_graduation_year, ENT_QUOTES, 'UTF-8') . '</li>
                </ul>
                <p>You can now sign in here:</p>
                <p>
                    <a href="' . htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') . '"
                       style="display:inline-block;background:#1a5276;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px">
                       Go to Login
                    </a>
                </p>
                <p style="color:#555">If you did not create this account, please ignore this email.</p>
            </div>
        ';

        $headersUser = "MIME-Version: 1.0\r\n";
        $headersUser .= "Content-type: text/html; charset=UTF-8\r\n";
        $headersUser .= "From: 3ZERO Club <ace-sedi@office>\r\n";

        @mail($email, $subjectUser, $bodyUser, $headersUser);

        $adminEmails = [
            'chitko.ko@student.aiu.edu.my',
        ];

        $subjectAdmin = "New 3ZERO Club Registration: " . $name;

        $adminBody = '
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222">
                <h2 style="margin:0 0 12px;color:#1a5276">New User Registration</h2>
                <p>A new user has just registered on the 3ZERO Club portal.</p>
                <p><strong>Registration Details:</strong></p>
                <ul>
                    <li><strong>Name:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Phone:</strong> ' . htmlspecialchars($phone_number, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Country:</strong> ' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Gender:</strong> ' . htmlspecialchars($gender, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Department:</strong> ' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Program of Study:</strong> ' . htmlspecialchars($program_of_study, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Intake:</strong> ' . htmlspecialchars($intake, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Area of Interest:</strong> ' . htmlspecialchars($area_of_interest, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Expected Graduation:</strong> ' . htmlspecialchars($expected_graduation_year, ENT_QUOTES, 'UTF-8') . '</li>
                    <li><strong>Date of Birth:</strong> ' . htmlspecialchars($date_of_birth, ENT_QUOTES, 'UTF-8') . '</li>
                </ul>
                <p>You can view this user in the admin dashboard.</p>
            </div>
        ';

        $headersAdmin = "MIME-Version: 1.0\r\n";
        $headersAdmin .= "Content-type: text/html; charset=UTF-8\r\n";
        $headersAdmin .= "From: 3ZERO Club <ace-sedi@office>\r\n";
        $headersAdmin .= "Reply-To: " . $email . "\r\n";

        foreach ($adminEmails as $adminEmail) {
            if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                @mail($adminEmail, $subjectAdmin, $adminBody, $headersAdmin);
            }
        }

        $_SESSION['success'] = "Registration successful! A confirmation email has been sent.";

        $stmt->close();
        header('Location: login.php');
        exit();

    } else {
        $error = $stmt->error;
        $stmt->close();
        redirectWithError("Registration failed: " . $error);
    }
}

function oldValue($key) {
    global $old;
    return isset($old[$key]) ? htmlspecialchars($old[$key], ENT_QUOTES, 'UTF-8') : '';
}

function oldSelected($key, $value) {
    global $old;
    return isset($old[$key]) && $old[$key] == $value ? 'selected' : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - 3ZERO Club</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="uploads/aiu_logo.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        (function () {
            const saved = localStorage.getItem('zeroClubTheme');
            document.documentElement.setAttribute('data-theme', saved === 'dark' || saved === 'light' ? saved : 'light');
        })();
    </script>
    <link href="assets/css/dark-mode.css" rel="stylesheet">
    <script src="assets/js/theme.js" defer></script>

<style>
:root {
    --primary-blue: #1a5276;
    --dark-blue: #0e2a47;
    --light-blue: #e8f4fd;
    --white: #ffffff;
    --text-gray: #333333;
    --muted: #6c757d;
    --border: #dee2e6;
}

body {
    font-family: 'Poppins', sans-serif;
    background-image:
        linear-gradient(rgba(14, 42, 71, 0.01), rgba(14, 42, 71, 0.01)),
        url("https://ace-sedi.aiu.edu.my/assets/images/cfgs-pic.jpg") !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
    color: var(--text-gray);
    min-height: 100vh;
    padding-top: 80px;
}

.auth-container {
    max-width: 850px;
    width: 92%;
    margin: 2rem auto;
    padding: 2.2rem;
    background: rgba(255, 255, 255, 0.94);
    border-radius: 18px;
    box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
}

.auth-header {
    text-align: center;
    margin-bottom: 2rem;
}

.auth-title {
    font-family: 'Playfair Display', serif;
    font-weight: 700;
    color: var(--dark-blue);
    font-size: 2rem;
    margin-bottom: 0.4rem;
}

.auth-subtitle {
    color: var(--muted);
    font-size: 1rem;
}

.important-notice {
    background: #e8f7ec;
    border: 1.5px solid #28a745;
    border-radius: 14px;
    padding: 1.3rem;
    margin-bottom: 2rem;
}

.important-notice-header {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 0.7rem;
}

.important-notice-icon,
.important-notice-title,
.important-notice-content {
    color: #155724;
}

.important-notice-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0;
}

.important-notice-content {
    margin: 0;
    line-height: 1.6;
}

.form-section {
    background: #ffffff;
    border: 1px solid #edf0f2;
    border-radius: 16px;
    padding: 1.8rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.05);
}

.section-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.7rem !important;
    font-weight: 700;
    color: #111;
    position: relative;
    display: inline-block;
    margin-top: 0;
    margin-bottom: 1.5rem;
    padding-bottom: 0.6rem;
    border-bottom: 0;
    box-shadow: none;
}

.section-title::before {
    content: none;
}

.section-title::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 120px;
    max-width: 100%;
    height: 4px;
    background: #1a5276;
    border-radius: 999px;
}

.form-label {
    font-weight: 600;
    color: #444;
    margin-bottom: 0.45rem;
}

.form-label.required::after {
    content: " *";
    color: #dc3545;
}

.input-group {
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
}

.input-group:focus-within {
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 0.2rem rgba(26, 82, 118, 0.15);
}

.input-group-text {
    width: 52px;
    justify-content: center;
    background: #f8fafc !important;
    border: none;
    color: var(--primary-blue);
    font-size: 1rem;
}

.form-control,
.form-select,
select.form-control {
    border: none;
    border-radius: 0;
    padding: 0.9rem 1rem;
    font-size: 0.97rem;
    background-color: #fff;
    box-shadow: none !important;
}

.form-control:focus,
.form-select:focus,
select.form-control:focus {
    border: none;
    box-shadow: none !important;
}

small,
.form-text {
    color: var(--muted) !important;
    font-size: 0.85rem;
}

.password-strength {
    height: 5px;
    background-color: #e9ecef;
    border-radius: 50px;
    margin-top: 0.7rem;
    overflow: hidden;
}

.password-strength-bar {
    height: 100%;
    width: 0%;
    border-radius: 50px;
    transition: width 0.3s ease, background-color 0.3s ease;
}

.btn-auth {
    width: 100%;
    padding: 0.95rem;
    border-radius: 12px;
    background: var(--primary-blue);
    border: none;
    font-weight: 600;
    font-size: 1.05rem;
    transition: 0.3s ease;
}

.btn-auth:hover {
    background: var(--dark-blue);
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(26, 82, 118, 0.3);
}

.auth-footer {
    text-align: center;
    background: #ffffff;
    border-radius: 14px;
    padding: 1rem;
    margin-top: 1.5rem;
}

.auth-footer p {
    margin-bottom: 0.4rem;
}

.auth-footer a {
    color: var(--primary-blue);
    font-weight: 700;
    text-decoration: none;
}

.auth-footer a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .auth-container {
        padding: 1.5rem;
        width: 94%;
    }

    .form-section {
        padding: 1.3rem;
    }

    .section-title {
        font-size: 1.4rem !important;
    }
}

@media (max-width: 576px) {
    body {
        padding-top: 70px;
    }

    .auth-container {
        margin: 1rem auto;
        padding: 1.2rem;
    }

    .auth-title {
        font-size: 1.6rem;
    }
}
/* Mobile-first polish and consistent auth form behavior */
body {
    background-size: cover !important;
    background-position: center top !important;
}

.auth-container {
    width: min(850px, calc(100vw - 2rem));
    border: 1px solid rgba(255,255,255,.45);
    backdrop-filter: blur(14px);
    animation: authFade .35s ease both;
}

.form-control,
.form-select,
.input-group-text {
    min-height: 46px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 .22rem rgba(26,82,118,.18);
}

.btn-auth {
    min-height: 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
}

.btn-auth.is-loading {
    pointer-events: none;
    opacity: .82;
}

@keyframes authFade {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 768px) {
    body {
        background-attachment: scroll !important;
    }
    .container {
        max-width: 100%;
        padding-left: .75rem;
        padding-right: .75rem;
    }
    .auth-container {
        margin: .75rem auto;
        padding: 1.25rem;
        border-radius: 16px;
        box-shadow: 0 18px 46px rgba(0,0,0,.28);
    }
    .auth-title { font-size: 1.9rem; }
    .auth-subtitle { font-size: .95rem; }
    .form-section {
        padding: 1rem;
        border-radius: 12px;
        margin-bottom: 1rem;
    }
    .section-title {
        font-size: 1.35rem !important;
        margin-bottom: 1rem;
    }
    .section-title::after {
        width: min(120px, 70vw);
        height: 4px;
    }
}

@media (max-width: 420px) {
    .auth-container {
        width: min(100%, calc(100vw - 1rem));
        padding: 1rem;
    }
}
</style>
</head>

<body>
<?php include('header.php'); ?>

<div class="container py-4 flex-grow-1">
    <div class="auth-container">
        <div class="auth-header">
            <h2 class="auth-title">Join 3ZERO Club</h2>
            <p class="auth-subtitle">Create your personal account to start your journey towards a better world</p>
        </div>

        <div class="important-notice">
            <div class="important-notice-header">
                <i class="bi bi-info-circle-fill important-notice-icon"></i>
                <h3 class="important-notice-title">Important Registration Notice</h3>
            </div>
            <p class="important-notice-content">
                <strong>Please ensure you register using your official student email address and enter all information accurately.</strong>
                This information will be used for club registrations and official communications. Incorrect information may affect your ability to join clubs and participate in activities.
            </p>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <form action="register.php" method="POST" enctype="multipart/form-data" autocomplete="on">

            <div class="form-section">
                <h4 class="section-title">Personal Information</h4>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label required">Full Name</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="name" name="name" placeholder="Enter your full name" required value="<?= oldValue('name') ?>">
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="date_of_birth" class="form-label required">Date of Birth</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-calendar"></i></span>
                            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required value="<?= oldValue('date_of_birth') ?>">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="phone_number" class="form-label required">Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-phone"></i></span>
                            <input type="text" class="form-control" id="phone_number" name="phone_number" placeholder="01123456789" required value="<?= oldValue('phone_number') ?>">
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label required">Email Address (Student Email)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="your@student.aiu.edu.my" required value="<?= oldValue('email') ?>">
                        </div>
                        <small class="text-muted">Please use your official student email address</small>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="country" class="form-label required">Country</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-globe"></i></span>
                            <select class="form-control" id="country" name="country" required>
                                <option value="">-- Select Country --</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="gender" class="form-label required">Gender</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-gender-ambiguous"></i></span>
                            <select class="form-control" id="gender" name="gender" required>
                                <option value="">-- Select Gender --</option>
                                <option value="Male" <?= oldSelected('gender', 'Male') ?>>Male</option>
                                <option value="Female" <?= oldSelected('gender', 'Female') ?>>Female</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label for="area_of_interest" class="form-label required">Area of Interest</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-stars"></i></span>
                            <select class="form-control" id="area_of_interest" name="area_of_interest" required>
                                <option value="">-- Select Area of Interest --</option>
                                <option value="Zero Poverty" <?= oldSelected('area_of_interest', 'Zero Poverty') ?>>Zero Poverty</option>
                                <option value="Zero Unemployment" <?= oldSelected('area_of_interest', 'Zero Unemployment') ?>>Zero Unemployment</option>
                                <option value="Zero Net Carbon Emission" <?= oldSelected('area_of_interest', 'Zero Net Carbon Emission') ?>>Zero Net Carbon Emission</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label for="profile_pic" class="form-label required">Upload Your Image <small class="text-muted">(JPG, PNG, WEBP, max 1MB)</small></label>
                        <label for="profile_pic" style="color:green;">Disclaimer: Please ensure that you upload your own photo. Do not upload photos of other individuals.</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-image"></i></span>
                            <input type="file" class="form-control" id="profile_pic" name="profile_pic" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                        </div>
                        <small class="text-muted"><?= htmlspecialchars(IMAGE_UPLOAD_DISCLAIMER, ENT_QUOTES, 'UTF-8') ?> You must reselect the image after an error.</small>
                        <div id="profilePicPreview" class="mt-2"></div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h4 class="section-title">Academic Information</h4>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="department" class="form-label required">Department</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-building"></i></span>
                            <select class="form-control" id="department" name="department" required>
                                <option value="">-- Select Department --</option>
                                <option value="School Of Business & Social Sciences" <?= oldSelected('department', 'School Of Business & Social Sciences') ?>>School of Business & Social Sciences</option>
                                <option value="School Of Education & Human Sciences" <?= oldSelected('department', 'School Of Education & Human Sciences') ?>>School of Education & Human Sciences</option>
                                <option value="School Of Computing and Informatics" <?= oldSelected('department', 'School Of Computing and Informatics') ?>>School of Computing and Informatics</option>
                                <option value="Centre for Foundation and General Studies" <?= oldSelected('department', 'Centre for Foundation and General Studies') ?>>Centre for Foundation and General Studies</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="program_of_study" class="form-label required">Program of Study</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-journal"></i></span>
                            <select class="form-control" id="program_of_study" name="program_of_study" required>
                                <option value="">-- Select Program --</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="intake" class="form-label required">Intake</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                            <select class="form-control" id="intake" name="intake" required>
                                <option value="">-- Select Intake --</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="expected_graduation_year" class="form-label required">Expected Graduation</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-mortarboard"></i></span>
                            <select class="form-control" id="expected_graduation_year" name="expected_graduation_year" required>
                                <option value="">-- Select Graduation --</option>
                                <option value="March 2025" <?= oldSelected('expected_graduation_year', 'March 2025') ?>>March 2025</option>
                                <option value="December 2025" <?= oldSelected('expected_graduation_year', 'December 2025') ?>>December 2025</option>
                                <option value="March 2026" <?= oldSelected('expected_graduation_year', 'March 2026') ?>>March 2026</option>
                                <option value="December 2026" <?= oldSelected('expected_graduation_year', 'December 2026') ?>>December 2026</option>
                                <option value="March 2027" <?= oldSelected('expected_graduation_year', 'March 2027') ?>>March 2027</option>
                                <option value="December 2027" <?= oldSelected('expected_graduation_year', 'December 2027') ?>>December 2027</option>
                                <option value="March 2028" <?= oldSelected('expected_graduation_year', 'March 2028') ?>>March 2028</option>
                                <option value="December 2028" <?= oldSelected('expected_graduation_year', 'December 2028') ?>>December 2028</option>
                                <option value="March 2029" <?= oldSelected('expected_graduation_year', 'March 2029') ?>>March 2029</option>
                                <option value="December 2029" <?= oldSelected('expected_graduation_year', 'December 2029') ?>>December 2029</option>
                                <option value="March 2030" <?= oldSelected('expected_graduation_year', 'March 2030') ?>>March 2030</option>
                                <option value="December 2030" <?= oldSelected('expected_graduation_year', 'December 2030') ?>>December 2030</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h4 class="section-title">Account Security</h4>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label required">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Create a password" required>
                            <span class="input-group-text" id="togglePassword" style="cursor: pointer;">
                                <i class="bi bi-eye-slash-fill"></i>
                            </span>
                        </div>
                        <div class="password-strength mt-2">
                            <div class="password-strength-bar" id="passwordStrengthBar"></div>
                        </div>
                        <small class="form-text text-muted">Minimum 8 characters with letters, numbers, and special characters</small>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="confirm_password" class="form-label required">Confirm Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                            <span class="input-group-text" id="toggleConfirmPassword" style="cursor: pointer;">
                                <i class="bi bi-eye-slash-fill"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-auth">
                <i class="bi bi-person-plus-fill me-2"></i> Create Account
            </button>
        </form>

        <div class="auth-footer text-center mt-4">
            <p>Already have an account? <a href="login.php" class="text-primary text-decoration-none fw-bold">Sign in here</a></p>
            <p>Need help? <a href="contacts.php" class="text-primary text-decoration-none fw-bold">Contact us here</a></p>
            <small class="text-muted">After registration, you can register your club from your dashboard</small>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
const programsByDept = {
    "School Of Business & Social Sciences": [
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
        "Doctor of Philosophy (Business Management)"
    ],
    "School Of Education & Human Sciences": [
        "Bachelor of Elementary Education (Honours)",
        "Bachelor in Early Childhood Education (Honours)",
        "Bachelor of Media and Communication (Honours)",
        "Master of Education",
        "Doctor of Philosophy (Education)"
    ],
    "School Of Computing and Informatics": [
        "Bachelor in Computer Science (Honours)",
        "Bachelor in Data Science (Honours)",
        "Bachelor in Information Technology (Cybersecurity) (Honours)",
        "Master of Computing (by Research)",
        "Doctor of Philosophy in Computer Science",
    ],
    "Centre for Foundation and General Studies": [
        "Foundation in Computing",
        "Foundation in Arts"
    ]
};

const countries = [
    "Afghanistan", "Albania", "Algeria", "Andorra", "Angola", "Antigua and Barbuda",
    "Argentina", "Armenia", "Australia", "Austria", "Azerbaijan", "Bahamas",
    "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium", "Belize", "Benin",
    "Bhutan", "Bolivia", "Bosnia and Herzegovina", "Botswana", "Brazil", "Brunei",
    "Bulgaria", "Burkina Faso", "Burundi", "Cabo Verde", "Cambodia", "Cameroon",
    "Canada", "Central African Republic", "Chad", "Chile", "China", "Colombia",
    "Comoros", "Congo (Congo-Brazzaville)", "Costa Rica", "Croatia", "Cuba",
    "Cyprus", "Czechia (Czech Republic)", "Democratic Republic of the Congo",
    "Denmark", "Djibouti", "Dominica", "Dominican Republic", "Ecuador", "Egypt",
    "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Eswatini",
    "Ethiopia", "Fiji", "Finland", "France", "Gabon", "Gambia", "Georgia",
    "Germany", "Ghana", "Greece", "Grenada", "Guatemala", "Guinea", "Guinea-Bissau",
    "Guyana", "Haiti", "Honduras", "Hungary", "Iceland", "India", "Indonesia",
    "Iran", "Iraq", "Ireland", "Israel", "Italy", "Jamaica", "Japan", "Jordan",
    "Kazakhstan", "Kenya", "Kiribati", "Kuwait", "Kyrgyzstan", "Laos", "Latvia",
    "Lebanon", "Lesotho", "Liberia", "Libya", "Liechtenstein", "Lithuania",
    "Luxembourg", "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali",
    "Malta", "Marshall Islands", "Mauritania", "Mauritius", "Mexico", "Micronesia",
    "Moldova", "Monaco", "Mongolia", "Montenegro", "Morocco", "Mozambique",
    "Myanmar", "Namibia", "Nauru", "Nepal", "Netherlands", "New Zealand",
    "Nicaragua", "Niger", "Nigeria", "North Korea", "North Macedonia", "Norway",
    "Oman", "Pakistan", "Palau", "Palestine", "Panama", "Papua New Guinea",
    "Paraguay", "Peru", "Philippines", "Poland", "Portugal", "Qatar", "Romania",
    "Russia", "Rwanda", "Saint Kitts and Nevis", "Saint Lucia",
    "Saint Vincent and the Grenadines", "Samoa", "San Marino", "Sao Tome and Principe",
    "Saudi Arabia", "Senegal", "Serbia", "Seychelles", "Sierra Leone", "Singapore",
    "Slovakia", "Slovenia", "Solomon Islands", "Somalia", "South Africa",
    "South Korea", "South Sudan", "Spain", "Sri Lanka", "Sudan", "Suriname",
    "Sweden", "Switzerland", "Syria", "Tajikistan", "Tanzania", "Thailand",
    "Timor-Leste", "Togo", "Tonga", "Trinidad and Tobago", "Tunisia", "Turkey",
    "Turkmenistan", "Tuvalu", "Uganda", "Ukraine", "United Arab Emirates",
    "United Kingdom", "United States of America", "Uruguay", "Uzbekistan",
    "Vanuatu", "Venezuela", "Vietnam", "Yemen", "Zambia", "Zimbabwe"
];

document.addEventListener('DOMContentLoaded', function() {
    const countrySelect = document.getElementById('country');
    const selectedCountry = "<?= oldValue('country') ?>";

    countries.sort().forEach(country => {
        let option = document.createElement('option');
        option.value = country;
        option.textContent = country;

        if (country === selectedCountry) {
            option.selected = true;
        }

        countrySelect.appendChild(option);
    });

    const intakeSelect = document.getElementById('intake');
    const selectedIntake = "<?= oldValue('intake') ?>";
    const currentYear = new Date().getFullYear();
    const intakeMonths = ["March", "October"];

    for (let year = currentYear - 5; year <= currentYear + 2; year++) {
        intakeMonths.forEach(month => {
            const label = `${month} ${year} / ${year + 1}`;
            let option = document.createElement('option');
            option.value = label;
            option.textContent = label;

            if (label === selectedIntake) {
                option.selected = true;
            }

            intakeSelect.appendChild(option);
        });
    }

    const departmentSelect = document.getElementById('department');
    const programSelect = document.getElementById('program_of_study');
    const selectedProgram = "<?= oldValue('program_of_study') ?>";

    function updatePrograms() {
        const dept = departmentSelect.value;
        programSelect.innerHTML = '<option value="">-- Select Program --</option>';

        if (programsByDept[dept]) {
            programsByDept[dept].forEach(program => {
                let option = document.createElement('option');
                option.value = program;
                option.textContent = program;

                if (program === selectedProgram) {
                    option.selected = true;
                }

                programSelect.appendChild(option);
            });
        }
    }

    departmentSelect.addEventListener('change', updatePrograms);
    updatePrograms();
});

document.getElementById('togglePassword').addEventListener('click', function() {
    const password = document.getElementById('password');
    const icon = this.querySelector('i');

    if (password.type === 'password') {
        password.type = 'text';
        icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
    } else {
        password.type = 'password';
        icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
    }
});

document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
    const confirmPassword = document.getElementById('confirm_password');
    const icon = this.querySelector('i');

    if (confirmPassword.type === 'password') {
        confirmPassword.type = 'text';
        icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
    } else {
        confirmPassword.type = 'password';
        icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
    }
});

document.getElementById('password').addEventListener('input', function() {
    const password = this.value;
    const strengthBar = document.getElementById('passwordStrengthBar');
    let strength = 0;

    if (password.length >= 8) strength += 25;
    if (password.length >= 12) strength += 25;
    if (/[A-Z]/.test(password)) strength += 15;
    if (/[0-9]/.test(password)) strength += 15;
    if (/[^A-Za-z0-9]/.test(password)) strength += 20;

    strengthBar.style.width = Math.min(strength, 100) + '%';

    if (strength < 50) {
        strengthBar.style.backgroundColor = '#dc3545';
    } else if (strength < 75) {
        strengthBar.style.backgroundColor = '#fd7e14';
    } else {
        strengthBar.style.backgroundColor = '#28a745';
    }
});

document.getElementById('profile_pic')?.addEventListener('change', function() {
    const preview = document.getElementById('profilePicPreview');
    preview.innerHTML = '';
    const file = this.files && this.files[0] ? this.files[0] : null;
    if (!file) return;

    if (file.size > 1024 * 1024) {
        preview.innerHTML = '<small class="text-danger">Image size must be less than or equal to 1MB. Please compress the image and upload again.</small>';
        this.value = '';
        return;
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        preview.innerHTML = '<small class="text-danger">Only JPG, JPEG, PNG, and WEBP images are allowed.</small>';
        this.value = '';
        return;
    }

    const img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    img.alt = 'Selected profile preview';
    img.style.maxWidth = '140px';
    img.style.maxHeight = '140px';
    img.style.objectFit = 'cover';
    img.style.borderRadius = '10px';
    img.onload = () => URL.revokeObjectURL(img.src);
    preview.appendChild(img);
});

const menuToggle = document.getElementById('menuToggle');
const mobileMenu = document.getElementById('mobileMenu');

if (menuToggle && mobileMenu) {
    menuToggle.addEventListener('click', () => {
        mobileMenu.classList.toggle('show');
        menuToggle.innerHTML = mobileMenu.classList.contains('show')
            ? '<i class="fas fa-times"></i>'
            : '<i class="fas fa-bars"></i>';
    });
}

document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .register-btn').forEach(link => {
    link.addEventListener('click', () => {
        if (mobileMenu) mobileMenu.classList.remove('show');
        if (menuToggle) menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
    });
});

document.querySelector('form[action="register.php"]')?.addEventListener('submit', function () {
    const btn = this.querySelector('.btn-auth');
    if (!btn) return;
    btn.classList.add('is-loading');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Creating account...</span>';
});
</script>

</body>
</html>
