<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in - if not, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

include '../includes/db.php';
require_once __DIR__ . '/../includes/image_upload_helper.php';

// Function to validate and get correct profile picture path
function getValidProfilePath($profilePic) {
    // If it's already the default, return it
    if ($profilePic === 'default-profile.jpg' || empty($profilePic)) {
        return '../uploads/default-profile.jpg';
    }
    
    // Define possible path variations based on your database examples
    $possiblePaths = [
        $profilePic, // Original path from DB
        '../' . $profilePic, // Add ../ if missing (for paths like "uploads/profiles/...")
        '../uploads/profiles/' . basename($profilePic), // Standard profiles directory
        '../uploads/' . basename($profilePic), // General uploads directory
        'uploads/profiles/' . basename($profilePic), // Without ../
        'uploads/' . basename($profilePic) // Without ../ and profiles
    ];
    
    // Add user-specific pattern based on your examples (user_X_hash.extension)
    if (preg_match('/user_(\d+)_([a-f0-9]+)\./', $profilePic, $matches)) {
        $possiblePaths[] = '../uploads/profiles/' . $profilePic;
        $possiblePaths[] = 'uploads/profiles/' . $profilePic;
        $possiblePaths[] = '../uploads/' . $profilePic;
        $possiblePaths[] = 'uploads/' . $profilePic;
    }
    
    // Add profile-specific pattern (profile_X_timestamp_name.extension)
    if (preg_match('/profile_(\d+)_(\d+)_/', $profilePic, $matches)) {
        $possiblePaths[] = '../uploads/profiles/' . $profilePic;
        $possiblePaths[] = 'uploads/profiles/' . $profilePic;
        $possiblePaths[] = '../uploads/' . $profilePic;
        $possiblePaths[] = 'uploads/' . $profilePic;
    }
    
    // Remove duplicates and check each path
    $possiblePaths = array_unique($possiblePaths);
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // If no valid file found, return default
    return '../uploads/default-profile.jpg';
}

// Function to save base64 image to file
function saveBase64Image($base64Image, $userId) {
    $savedPath = image_upload_base64_to_file(
        $base64Image,
        __DIR__ . '/../uploads/profiles',
        'uploads/profiles',
        'user_' . (int)$userId
    );

    return $savedPath ? basename($savedPath) : false;
}

// Detect auth state and load user data
$user = null;
$profilePhoto = '../uploads/default-profile.jpg';
$profileCompletion = 0;
$brandLogo = '../uploads/aiu_logo.png';

$notifications = [];
$unreadCount = 0;
$success_message = '';
$error_message = '';

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];

    // Load user data for profile page
    $uSql = "SELECT id, name, email, date_of_birth, phone_number, gender, country,
                    department, program_of_study, intake, area_of_interest, expected_graduation_year,
                    COALESCE(profile_pic,'') AS profile_pic 
             FROM users WHERE id = ? LIMIT 1";
    if ($uStmt = $conn->prepare($uSql)) {
        $uStmt->bind_param("i", $user_id);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        $user = $uRes->fetch_assoc();
        
        // Check if user has a profile picture and if the file exists
        if ($user && !empty($user['profile_pic'])) {
            $profilePhoto = getValidProfilePath($user['profile_pic']);
        }
    }

    if ($user) {
        $profileFields = ['name','email','phone_number','date_of_birth','gender','country','department','program_of_study','intake','area_of_interest','expected_graduation_year','profile_pic'];
        $filled = 0;
        foreach ($profileFields as $field) {
            $value = trim((string)($user[$field] ?? ''));
            if ($value !== '' && !in_array(strtolower($value), ['unknown','missing','null','n/a','na','none','-'], true)) {
                $filled++;
            }
        }
        $profileCompletion = (int)round(($filled / count($profileFields)) * 100);
    }

    // Handle form submissions for profile update and password change
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_profile'])) {
            // Handle profile update logic here
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            $phone_number = $_POST['phone_number'] ?? '';
            $gender = $_POST['gender'] ?? '';
            $country = $_POST['country'] ?? '';
            $department = $_POST['department'] ?? '';
            $program_of_study = $_POST['program_of_study'] ?? '';
            $intake = $_POST['intake'] ?? '';
            $area_of_interest = $_POST['area_of_interest'] ?? '';
            $expected_graduation_year = $_POST['expected_graduation_year'] ?? '';
            
            // Start building the update query
            $updateSql = "UPDATE users SET name = ?, email = ?, date_of_birth = ?, phone_number = ?, 
                         gender = ?, country = ?, department = ?, program_of_study = ?, intake = ?,
                         area_of_interest = ?, expected_graduation_year = ?";
            $params = [$name, $email, $date_of_birth, $phone_number, $gender, $country, 
                      $department, $program_of_study, $intake, $area_of_interest, $expected_graduation_year];
            $types = "sssssssssss";
            
            // Handle profile picture upload if provided
            $newProfilePic = null;
            if (!empty($_POST['profile_pic_data'])) {
                $profile_pic_data = $_POST['profile_pic_data'];
                $newProfilePic = saveBase64Image($profile_pic_data, $user_id);
                
                if ($newProfilePic) {
                    $updateSql .= ", profile_pic = ?";
                    $params[] = $newProfilePic;
                    $types .= "s";
                } else {
                    $error_message = IMAGE_UPLOAD_SIZE_ERROR;
                }
            }
            
            if ($error_message === '') {
                // Complete the query
                $updateSql .= " WHERE id = ?";
                $params[] = $user_id;
                $types .= "i";
                
                // Update user in database
                if ($updateStmt = $conn->prepare($updateSql)) {
                    $updateStmt->bind_param($types, ...$params);
                    if ($updateStmt->execute()) {
                        $success_message = "Profile updated successfully!";
                        
                        // Reload user data to reflect changes immediately
                        $uStmt->execute();
                        $uRes = $uStmt->get_result();
                        $user = $uRes->fetch_assoc();
                        
                        // Update profile photo if a new one was uploaded
                        if ($newProfilePic) {
                            $profilePhoto = getValidProfilePath($newProfilePic);
                        }
                    } else {
                        $error_message = "Error updating profile: " . $conn->error;
                    }
                    $updateStmt->close();
                } else {
                    $error_message = "Database error: " . $conn->error;
                }
            }
            
        } elseif (isset($_POST['change_password'])) {
            // Handle password change logic
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate passwords
            if ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match!";
            } elseif (strlen($new_password) < 8) {
                $error_message = "New password must be at least 8 characters long!";
            } else {
                // Verify current password
                $checkSql = "SELECT password FROM users WHERE id = ?";
                if ($checkStmt = $conn->prepare($checkSql)) {
                    $checkStmt->bind_param("i", $user_id);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $userData = $checkResult->fetch_assoc();
                    
                    if ($userData && password_verify($current_password, $userData['password'])) {
                        // Current password is correct, update to new password
                        $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                        $updatePassSql = "UPDATE users SET password = ? WHERE id = ?";
                        
                        if ($updatePassStmt = $conn->prepare($updatePassSql)) {
                            $updatePassStmt->bind_param("si", $hashedPassword, $user_id);
                            if ($updatePassStmt->execute()) {
                                $success_message = "Password changed successfully!";
                            } else {
                                $error_message = "Error changing password: " . $conn->error;
                            }
                            $updatePassStmt->close();
                        }
                    } else {
                        $error_message = "Current password is incorrect!";
                    }
                    $checkStmt->close();
                }
            }
        }
    }

    // Load notifications
    if ($conn) {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $hasMessage = false;
            $hasBody    = false;

            $col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'message'");
            if ($col && $col->num_rows > 0) { $hasMessage = true; }
            $col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'body'");
            if ($col && $col->num_rows > 0) { $hasBody = true; }

            $msgExpr = $hasMessage ? "message" : ($hasBody ? "body" : "NULL");

            $nSql = "SELECT id,
                            COALESCE(title,'Notification') AS title,
                            $msgExpr AS msg_text,
                            COALESCE(is_read,0) AS is_read,
                            COALESCE(created_at, NOW()) AS created_at
                     FROM notifications
                     WHERE user_id = ?
                     ORDER BY created_at DESC
                     LIMIT 5";
            if ($nStmt = $conn->prepare($nSql)) {
                $nStmt->bind_param("i", $user_id);
                if ($nStmt->execute()) {
                    $nRes = $nStmt->get_result();
                    while ($row = $nRes->fetch_assoc()) {
                        $notifications[] = $row;
                    }
                }
            }

            $hasIsRead = false;
            $col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
            if ($col && $col->num_rows > 0) { $hasIsRead = true; }

            if ($hasIsRead) {
                $cSql = "SELECT COUNT(*) AS c
                         FROM notifications
                         WHERE user_id = ? AND COALESCE(is_read,0) = 0";
                if ($cStmt = $conn->prepare($cSql)) {
                    $cStmt->bind_param("i", $user_id);
                    if ($cStmt->execute()) {
                        $cRes = $cStmt->get_result()->fetch_assoc();
                        $unreadCount = (int)($cRes['c'] ?? 0);
                    }
                }
            } else {
                $unreadCount = 0;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Profile - 3ZERO Club</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
  <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script>
    (function () {
      const saved = localStorage.getItem('zeroClubTheme');
      document.documentElement.setAttribute('data-theme', saved === 'dark' || saved === 'light' ? saved : 'light');
    })();
  </script>
  <link href="../assets/css/dark-mode.css" rel="stylesheet">
  <script src="../assets/js/theme.js" defer></script>
  <style>
    :root{ --primary:#1a5276; --primary-dark:#154360; --secondary:#2ecc71; --radius:12px; --shadow:0 4px 18px rgba(0,0,0,.08); }
    body { background:#f5f7fb; }
    .profile-wrap{ max-width:1200px; margin:24px auto; }
    .card-soft{ border:0; border-radius:var(--radius); box-shadow:var(--shadow); }
    .profile-pic-container { position:relative; width:150px; height:150px; margin:0 auto 10px; }
    .profile-pic-container img { width:100%; height:100%; object-fit:cover; border-radius:50%; border:4px solid #d1e7dd; }
    .profile-pic-upload { position:absolute; bottom:6px; right:6px; background:var(--primary); color:#fff; width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:.2s; }
    .profile-pic-upload:hover{ background:var(--primary-dark); transform:scale(1.05); }
    .stats-badge { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; border-radius:20px; padding:.25rem .75rem; font-weight:600; display:inline-flex; align-items:center; gap:.4rem; }
    .nav-tabs .nav-link { color:var(--primary); font-weight:600; }
    .nav-tabs .nav-link.active { color:#fff !important; background-color:var(--primary) !important; border-color:var(--primary) var(--primary) #fff !important; }
    .form-control, .form-select { border-radius:10px; }
    .btn-primary { background:var(--primary); border-color:var(--primary); border-radius:10px; }
    .btn-primary:hover { background:var(--primary-dark); border-color:var(--primary-dark); }
    .modal-header { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; }
    .profile-completion { height:10px; border-radius:999px; background:#e9eef4; overflow:hidden; }
    .profile-completion span { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg,var(--primary),var(--secondary)); }
    .area-pill { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; background:#eaf4fb; color:var(--primary); padding:.4rem .75rem; font-weight:700; }
    @media(max-width:767.98px){
      .profile-wrap{margin:12px auto}
      .profile-pic-container{width:118px;height:118px}
      .nav-tabs{gap:.35rem; border-bottom:0}
      .nav-tabs .nav-link{border-radius:10px; border:1px solid #dde5ee; width:100%}
      .nav-tabs .nav-item{flex:1 1 100%}
      .text-end{text-align:stretch!important}
      .text-end .btn{width:100%}
    }
    .cropper-preview{ width:150px; height:150px; overflow:hidden; border-radius:50%; margin:10px auto; border:3px solid #eee; }
  </style>
</head>
<body>

<!-- Success/Error Messages -->
<?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3ZERO Club Registration System</title>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <script src="../assets/js/theme.js" defer></script>
    <style>
:root {
    --primary: #1a5276;
    --primary-dark: #154360;
    --secondary: #28b463;
    --accent: #f39c12;
    --light: #f8f9fa;
    --dark: #212529;
    --gray: #6c757d;
    --gray-light: #e9ecef;
    --border-radius: 8px;
    --shadow: 0 4px 6px rgba(0,0,0,0.1);
    --transition: all 0.3s ease;

    --header-h: 70px;
    --sidebar-w: 250px;

    /* Notifications layout vars */
    --notif-max-h: 420px;   /* overall dropdown max height */
}

/* Reset body margins */
body {
    margin: 0;
    padding: 0;
    background: #f8f9fa;
}

/* Header */
.main-header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: 0.75rem 0;
    box-shadow: var(--shadow);
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 1000;
    height: var(--header-h);
}
.header-content {
    height: 100%;
    display: flex; justify-content: space-between; align-items: center;
    padding: 0 2rem;
}
.logo-section { display: flex; align-items: center; gap: 15px; }
.brand-logo {
    width: 45px; height: 45px; object-fit: contain;
    border-radius: 6px; background: rgba(255,255,255,0.9); padding: 3px;
}
.logo-text h1 { font-size: 1.4rem; margin: 0; font-weight: 700; }
.logo-text p { font-size: 0.85rem; opacity: 0.9; margin: 0; }

/* Mobile Menu Button */
.mobile-menu-btn { 
    display: none; background: none; border: none; color: white; 
    font-size: 1.3rem; cursor: pointer; padding: 5px; width: 40px; height: 40px; border-radius: 4px;
}
.mobile-menu-btn:hover { background: rgba(255,255,255,0.1); }

/* User Actions */
.user-actions { display: flex; align-items: center; gap: 15px; }

/* Notification Dropdown trigger */
.notification-wrapper { position: relative; }
.notification-icon { 
    position: relative; cursor: pointer; display: flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; border-radius: 50%; transition: var(--transition);
    border: none; background: transparent;
}
.notification-icon:hover { background: rgba(255,255,255,0.1); }
.notification-icon i { font-size: 1.2rem; color: white; }
.notification-badge {
    position: absolute; top: -2px; right: -2px; background: var(--accent); color: white;
    border-radius: 50%; min-width: 18px; height: 18px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center;
    padding: 0 4px; border: 2px solid var(--primary-dark);
}

/* User Profile - Simple Circle Image */
.user-profile { display: flex; align-items: center; }
.profile-image {
    width: 42px; height: 42px; border-radius: 50%; object-fit: cover;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

/* Sidebar */
.sidebar {
    width: var(--sidebar-w);
    background: linear-gradient(to bottom, var(--primary), var(--primary-dark));
    color: white;
    padding: 20px 0;
    transition: var(--transition);
    box-shadow: var(--shadow);
    z-index: 999;
    position: fixed;
    top: var(--header-h);
    left: 0; bottom: 0;
    overflow-y: auto;
}
.sidebar-menu { list-style: none; margin: 0; padding: 0; }
.sidebar-menu li { margin-bottom: 5px; }
.sidebar-menu a {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 20px; color: rgba(255,255,255,0.9); text-decoration: none;
    transition: var(--transition); border-left: 3px solid transparent;
}
.sidebar-menu a:hover, .sidebar-menu a.active { 
    background: rgba(255,255,255,0.1); color: white; border-left-color: var(--secondary);
}
.sidebar-menu i { width: 20px; text-align: center; }

/* Main Content */
.main-content {
    margin: 0;
    padding: 30px;
    transition: var(--transition);
    min-height: calc(100vh - var(--header-h));
    background: #f8f9fa;
    position: relative;
    z-index: 1;
}

/* Mobile */
@media (max-width: 992px) {
    .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
    .sidebar { transform: translateX(-100%); width: 280px; }
    .sidebar.active { transform: translateX(0); }
    .main-content { padding: 20px 15px; }
    .header-content { padding: 0 1rem; }
    .logo-text h1 { font-size: 1.2rem; }
    .logo-text p { display: none; }
}

/* Overlay for mobile menu */
.overlay {
    display: none; position: fixed; top: var(--header-h); left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 998;
}
.overlay.active { display: block; }

/* --- LAYOUT FIX --- */
html, body { box-sizing: border-box; }
*, *::before, *::after { box-sizing: inherit; }

/* Keep content below fixed header */
body { padding-top: var(--header-h); }

/* Leave space for sidebar on desktop */
@media (min-width: 993px) { body { padding-left: var(--sidebar-w); } }
/* On mobile, sidebar slides over content */
@media (max-width: 992px) { body { padding-left: 0; } }

/* ===========================
   Notification Dropdown (fixed)
   =========================== */
/* Make whole menu scrollable and keep header/footer sticky */
.notification-dropdown {
    width: 350px;
    max-width: 90vw;
    max-height: var(--notif-max-h);
    overflow: auto;            /* scroll the whole menu */
    padding: 0;                /* remove default padding */
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-radius: 8px;
}

/* Sticky header and footer inside the scroll container */
.notification-dropdown .dropdown-header,
.notification-dropdown .dropdown-footer {
    position: sticky;
    background: #fff;
    z-index: 1;
}
.notification-dropdown .dropdown-header { top: 0; }
.notification-dropdown .dropdown-footer { bottom: 0; border-top: 1px solid var(--gray-light); }

/* Inner list just flows normally now (no extra overflow) */
.dropdown-inner { display: block; }

/* Items */
.notification-item { 
    padding: 10px 15px; 
    border-bottom: 1px solid var(--gray-light); 
    cursor: pointer; 
}
.notification-item:last-child { border-bottom: none; }
.notification-item.unread { background-color: rgba(0, 123, 255, 0.05); }
.notification-item:hover { background-color: var(--gray-light); }
.notification-title { font-weight: 600; margin-bottom: 5px; }
.notification-message { font-size: 0.9rem; color: var(--gray); margin-bottom: 5px; word-wrap: break-word; }
.notification-time { font-size: 0.8rem; color: var(--gray); }

/* Improve sticky header look */
.notification-dropdown .dropdown-header > .d-flex {
    background: #fff;
}

/* Remove duplicate/conflicting .dropdown-menu rules */
    </style>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <div class="logo-section">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- Logo Image -->
                <img src="../uploads/aiu_logo.png" alt="3ZERO Club Logo" class="brand-logo" onerror="this.style.display='none';">

                <div class="logo-text">
                    <h1>3ZERO Club</h1>
                    <p>Registration System</p>
                </div>
            </div>

            <div class="user-actions">
                <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                    <i class="fa-solid fa-moon"></i>
                </button>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Notifications Dropdown -->
                    <div class="notification-wrapper dropdown">
                        <button class="notification-icon dropdown-toggle" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if (($unreadCount ?? 0) > 0): ?>
                                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                            <?php endif; ?>
                        </button>

                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                            <!-- Sticky Header -->
                            <li class="dropdown-header">
                                <div class="d-flex justify-content-between align-items-center px-3 py-2">
                                    <strong>Notifications</strong>
                                    <?php if (($unreadCount ?? 0) > 0): ?>
                                        <span class="badge bg-warning"><?= $unreadCount ?> new</span>
                                    <?php endif; ?>
                                </div>
                            </li>

                            <!-- Scrollable body -->
                            <li>
                                <div class="dropdown-inner">
                                    <?php if (!empty($notifications)): ?>
                                        <?php foreach ($notifications as $notification): ?>
                                            <div class="notification-item <?= ((int)$notification['is_read'] === 0) ? 'unread' : '' ?>">
                                                <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                                <?php if (!empty($notification['msg_text'])): ?>
                                                    <div class="notification-message"><?= htmlspecialchars($notification['msg_text']) ?></div>
                                                <?php endif; ?>
                                                <div class="notification-time">
                                                    <?= htmlspecialchars(date('M j, Y g:i A', strtotime($notification['created_at']))) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="notification-item text-center text-muted py-3">No notifications</div>
                                    <?php endif; ?>
                                </div>
                            </li>

                            <!-- Sticky Footer with link (always visible) -->
                            <li class="dropdown-footer">
                                <a class="dropdown-item text-center py-2" href="notifications.php">
                                    <i class="fas fa-bell me-2"></i>View All Notifications
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- User Profile - Simple Circle Image Only -->
                    <div class="user-profile">
                        <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="User Profile" class="profile-image" onerror="this.onerror=null; this.src='../uploads/default-profile.jpg';">
                    </div>
                <?php else: ?>
                    <a href="login.php" class="btn btn-sm btn-light"><i class="fas fa-sign-in-alt me-1"></i> Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Mobile Overlay -->
    <div class="overlay" id="overlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <ul class="sidebar-menu">
            <li><a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> <span>Dashboard</span>
            </a></li>
            <li><a href="club_registration.php" class="<?= basename($_SERVER['PHP_SELF']) == 'club_registration.php' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i> <span>Register Club</span>
            </a></li>
            <li><a href="myclubs.php" class="<?= basename($_SERVER['PHP_SELF']) == 'myclubs.php' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> <span>My Clubs</span>
            </a></li>
            <li><a href="events.php" class="<?= basename($_SERVER['PHP_SELF']) == 'events.php' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt"></i> <span>Events</span>
            </a></li>
            <li><a href="projects.php" class="<?= basename($_SERVER['PHP_SELF']) == 'projects.php' ? 'active' : '' ?>">
                <i class="fas fa-tasks"></i> <span>Projects</span>
            </a></li>
            <li><a href="achievements.php" class="<?= basename($_SERVER['PHP_SELF']) == 'achievements.php' ? 'active' : '' ?>">
                <i class="fas fa-certificate"></i> <span>Achievements</span>
            </a></li>
            <li><a href="developer_info.php" class="<?= basename($_SERVER['PHP_SELF']) == 'developer_info.php' ? 'active' : '' ?>">
                <i class="fas fa-code"></i> <span>Developer Info</span>
            </a></li>
            <li><a href="profile.php" class="active">
                <i class="fas fa-user-edit"></i> <span>Profile</span>
            </a></li>
            <?php if (isset($_SESSION['user_id'])): ?>
            <li><a href="logout.php">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a></li>
            <?php endif; ?>
        </ul>
    </aside>
    
<div class="container profile-wrap">
  
  
  <div class="row g-3">
    <!-- Left: Profile Summary -->
    <div class="col-lg-4">
      <div class="card card-soft p-3">
        <div class="text-center">
          <div class="profile-pic-container">
            <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="Profile Picture" id="profile-pic-preview"
                 onerror="this.src='../uploads/default-profile.jpg'">
            <label for="profile_pic" class="profile-pic-upload" title="Change photo">
              <i class="bi bi-camera"></i>
            </label>
          </div>
          <small class="text-muted d-block mt-2"><?= htmlspecialchars(IMAGE_UPLOAD_DISCLAIMER, ENT_QUOTES, 'UTF-8') ?></small>
          <h4 class="mb-0"><?= htmlspecialchars($user['name'] ?? 'User') ?></h4>
          <small class="text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></small>
          <div class="d-flex justify-content-center gap-2 mt-3">
            <span class="stats-badge"><i class="bi bi-person-badge"></i> User ID: <?= htmlspecialchars($user['id'] ?? '') ?></span>
          </div>
          <div class="mt-3 text-start">
            <div class="d-flex justify-content-between small fw-semibold mb-2">
              <span>Profile completion</span>
              <span><?= (int)$profileCompletion ?>%</span>
            </div>
            <div class="profile-completion" aria-label="Profile completion <?= (int)$profileCompletion ?> percent">
              <span style="width:<?= (int)$profileCompletion ?>%"></span>
            </div>
          </div>
          <?php if (!empty($user['area_of_interest'])): ?>
            <div class="mt-3">
              <span class="area-pill"><i class="bi bi-stars"></i><?= htmlspecialchars($user['area_of_interest']) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right: Forms -->
    <div class="col-lg-8">
      <div class="card card-soft p-3">
        <ul class="nav nav-tabs mb-3" id="profileTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">
              Profile Information
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
              Change Password
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <!-- Profile Info -->
          <div class="tab-pane fade show active" id="profile" role="tabpanel">
            <form method="POST" action="profile.php" enctype="multipart/form-data" class="mt-2">
              <input type="hidden" name="csrf" value="4409fb35d2767076554fd7c056361f4dcafcde685a1fce112dd8239360e5d5e4">
              <input type="file" class="d-none" id="profile_pic" name="profile_pic" accept="image/*">
              <input type="hidden" id="profile_pic_data" name="profile_pic_data">

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Full Name</label>
                  <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Date of Birth</label>
                  <input type="date" class="form-control" name="date_of_birth" value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone Number</label>
                  <input type="tel" class="form-control" name="phone_number" value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Gender</label>
                  <select class="form-select" name="gender">
                    <option value="Male" <?= ($user['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($user['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                    <option value="Other" <?= ($user['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Country</label>
                  <input type="text" class="form-control" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Program of Study</label>
                  <input type="text" class="form-control" name="program_of_study" value="<?= htmlspecialchars($user['program_of_study'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Department</label>
                  <input type="text" class="form-control" name="department" value="<?= htmlspecialchars($user['department'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Intake</label>
                  <input type="text" class="form-control" name="intake" value="<?= htmlspecialchars($user['intake'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Area of Interest</label>
                  <input type="text" class="form-control" name="area_of_interest" value="<?= htmlspecialchars($user['area_of_interest'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Expected Graduation Year</label>
                  <input type="text" class="form-control" name="expected_graduation_year" placeholder="e.g., May 2025, Fall 2026" value="<?= htmlspecialchars($user['expected_graduation_year'] ?? '') ?>">
                </div>
              </div>

              <div class="text-end mt-3">
                <button class="btn btn-primary" type="submit" name="update_profile">
                  <i class="bi bi-save me-1"></i> Update Profile
                </button>
              </div>
            </form>
          </div>

          <!-- Change Password -->
          <div class="tab-pane fade" id="password" role="tabpanel">
            <form method="POST" action="profile.php" class="mt-2">
              <input type="hidden" name="csrf" value="4409fb35d2767076554fd7c056361f4dcafcde685a1fce112dd8239360e5d5e4">
              <div class="mb-3">
                <label class="form-label">Current Password</label>
                <input type="password" class="form-control" name="current_password" required>
              </div>
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" name="new_password" minlength="8" required>
                <div class="form-text">At least 8 characters.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" name="confirm_password" minlength="8" required>
              </div>
              <div class="text-end">
                <button class="btn btn-primary" type="submit" name="change_password">
                  <i class="bi bi-key me-1"></i> Change Password
                </button>
              </div>
            </form>
          </div>

        </div><!-- tab-content -->
      </div>
    </div>
  </div>
</div>

<!-- Crop Modal -->
<div class="modal fade" id="profilePicModal" tabindex="-1" aria-labelledby="profilePicModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="profilePicModalLabel">Crop Profile Picture</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8">
            <div class="img-container">
              <img id="image-to-crop" src="#" alt="Profile Picture" style="max-width:100%;">
            </div>
          </div>
          <div class="col-md-4">
            <div class="cropper-preview"></div>
            <div class="d-grid gap-2 mt-3">
              <button class="btn btn-light border" id="rotate-left" type="button"><i class="bi bi-arrow-counterclockwise me-1"></i> Rotate Left</button>
              <button class="btn btn-light border" id="rotate-right" type="button"><i class="bi bi-arrow-clockwise me-1"></i> Rotate Right</button>
              <button class="btn btn-primary" id="crop-btn" type="button"><i class="bi bi-crop me-1"></i> Crop & Save</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

    <!-- REQUIRED: Bootstrap JS with Popper (bundle includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // ===== App UI script (mobile menu + dropdowns) =====
    (function () {
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar       = document.getElementById('sidebar');
        const overlay       = document.getElementById('overlay');

        function toggleMenu() {
            const isActive = sidebar.classList.toggle('active');
            overlay.classList.toggle('active');

            // Prevent body scroll when menu is open
            document.body.style.overflow = isActive ? 'hidden' : '';
        }

        // Mobile menu open/close
        if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleMenu);
        if (overlay) overlay.addEventListener('click', toggleMenu);

        // Close menu when clicking a link on mobile
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                    toggleMenu();
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', function () {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Active page highlighting
        const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
        document.querySelectorAll('.sidebar-menu a').forEach(item => {
            const href = item.getAttribute('href');
            if (href === currentPage) item.classList.add('active');
        });

        // --- Bootstrap 5 dropdowns (NO stopPropagation) ---
        document.addEventListener('DOMContentLoaded', function () {
            // Ensure header doesn't clip dropdown (safety)
            const headerEl = document.querySelector('.main-header');
            if (headerEl) headerEl.style.overflow = 'visible';

            // Initialize all dropdown toggles with autoClose 'outside'
            document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
                // If you didn't add data-bs-auto-close="outside" in HTML, this enforces it via JS
                new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
            });

            // Defensive: also toggle programmatically on bell click (in case of custom markup)
            const bellBtn = document.getElementById('notificationDropdown');
            if (bellBtn) {
                bellBtn.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        const dd = bootstrap.Dropdown.getOrCreateInstance(bellBtn, { autoClose: 'outside', display: 'static' });
                        dd.toggle();
                    }
                });
            }
        });
    })();
    </script>
</body>
</html>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
<script>
let cropper;
const profilePicInput  = document.getElementById('profile_pic');
const profilePicModal  = new bootstrap.Modal(document.getElementById('profilePicModal'));
const imageToCrop      = document.getElementById('image-to-crop');
const profilePicPreview= document.getElementById('profile-pic-preview');
const profilePicData   = document.getElementById('profile_pic_data');
const preview          = document.querySelector('.cropper-preview');

setTimeout(() => {
  document.querySelectorAll('.alert.alert-dismissible').forEach(a => {
    try { new bootstrap.Alert(a).close(); } catch(e){}
  });
}, 5000);

profilePicInput?.addEventListener('change', function(){
  if (this.files && this.files[0]) {
    const file = this.files[0];
    if (file.size > 1024 * 1024) {
      alert('Image size must be less than or equal to 1MB. Please compress the image and upload again.');
      this.value = '';
      return;
    }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      alert('Only JPG, JPEG, PNG, and WEBP images are allowed.');
      this.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = function(e){
      imageToCrop.src = e.target.result;
      profilePicModal.show();
      document.getElementById('profilePicModal').addEventListener('shown.bs.modal', function initOnce(){
        if (cropper) cropper.destroy();
        cropper = new Cropper(imageToCrop, {
          aspectRatio:1, viewMode:1, autoCropArea:0.85, responsive:true,
          preview:preview, guides:false, center:false, highlight:false,
          cropBoxMovable:true, cropBoxResizable:true, toggleDragModeOnDblclick:false
        });
        document.getElementById('rotate-left').onclick  = () => cropper.rotate(-90);
        document.getElementById('rotate-right').onclick = () => cropper.rotate(90);
        document.getElementById('crop-btn').onclick = () => {
          const canvas = cropper.getCroppedCanvas({
            width: 600, height: 600, minWidth: 256, minHeight: 256, maxWidth: 1200, maxHeight: 1200,
            fillColor:'#fff', imageSmoothingEnabled:true, imageSmoothingQuality:'high'
          });
          if (canvas) {
            const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
            profilePicPreview.src = dataUrl;
            profilePicData.value  = dataUrl;
            profilePicModal.hide();
          }
        };
        document.getElementById('profilePicModal').removeEventListener('shown.bs.modal', initOnce);
      }, { once:true });
    };
    reader.readAsDataURL(this.files[0]);
  }
});

document.getElementById('profilePicModal').addEventListener('hidden.bs.modal', function(){
  if (cropper) { cropper.destroy(); cropper = null; }
});


document.addEventListener('DOMContentLoaded', function(){
  const urlParams = new URLSearchParams(window.location.search);
  const tab = urlParams.get('tab');
  if (tab) {
    const trigger = document.querySelector(`[data-bs-target="#${tab}"]`);
    if (trigger) new bootstrap.Tab(trigger).show();
  }
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
