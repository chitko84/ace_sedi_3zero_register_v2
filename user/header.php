<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../includes/db.php';

// Detect auth state and load user basics for header
$user = null;
$profilePhoto = '../uploads/default-profile.jpg';
$brandLogo    = '../uploads/aiu_logo.png';

$notifications = [];
$unreadCount   = 0;

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];

    // Load user for profile image/name/email
    $uSql = "SELECT id, name, email, COALESCE(profile_pic,'') AS profile_pic FROM users WHERE id = ? LIMIT 1";
    if ($uStmt = $conn->prepare($uSql)) {
        $uStmt->bind_param("i", $user_id);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        $user = $uRes->fetch_assoc();
        
        // Check if user has a profile picture and if the file exists
        if ($user && !empty($user['profile_pic'])) {
            $profilePicPath = $user['profile_pic'];
            
            // Function to check and normalize profile picture path
            function getValidProfilePath($profilePic) {
                // If it's already the default, return it
                if ($profilePic === 'default-profile.jpg') {
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
            
            $profilePhoto = getValidProfilePath($profilePicPath);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3ZERO Club Registration System</title>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        (function () {
            const saved = localStorage.getItem('zeroClubTheme');
            document.documentElement.setAttribute('data-theme', saved === 'dark' || saved === 'light' ? saved : 'light');
        })();
    </script>
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
@media (max-width: 992px) {
    body { padding-left: 0; overflow-x:hidden; }
    .main-header{height:var(--header-h)}
    .sidebar{
        width:min(86vw,320px);
        box-shadow:0 20px 60px rgba(0,0,0,.28);
        will-change:transform;
    }
    .overlay{
        background:rgba(7,18,28,.58);
        backdrop-filter:blur(2px);
    }
    .brand-logo{width:38px;height:38px}
    .user-actions{gap:.35rem}
    .profile-image{width:38px;height:38px}
    .notification-dropdown{
        position:fixed!important;
        top:calc(var(--header-h) + .5rem)!important;
        left:.75rem!important;
        right:.75rem!important;
        width:auto;
        max-width:none;
        transform:none!important;
    }
    .container,.container-fluid{max-width:100%; padding-left:.85rem; padding-right:.85rem}
    .card,.glass-card,.club-card,.search-container{border-radius:10px!important}
    .card-body{padding:1rem}
    .row{--bs-gutter-x:.85rem}
    .btn,.form-control,.form-select,.input-group-text{min-height:42px}
    .btn{white-space:normal}
    .btn-group{display:flex; flex-wrap:wrap; gap:.35rem}
    .btn-group>.btn{border-radius:8px!important; flex:1 1 auto}
    .badge,.club-chip,.status-badge,.cluster-pill{white-space:normal; line-height:1.25}
    .table-responsive{border-radius:10px; overflow-x:auto; -webkit-overflow-scrolling:touch}
}

@media (max-width: 767.98px) {
    .table-stack thead{display:none}
    .table-stack,.table-stack tbody,.table-stack tr,.table-stack td{display:block;width:100%}
    .table-stack tr{
        background:#fff;
        margin-bottom:.9rem;
        border:1px solid #e9eef4;
        border-radius:10px;
        box-shadow:0 4px 14px rgba(15,47,71,.06);
        padding:.35rem .65rem;
    }
    .table-stack td{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:1rem;
        text-align:right;
        border:0!important;
        border-bottom:1px dashed #e8edf3!important;
        padding:.65rem .15rem!important;
        word-break:break-word;
    }
    .table-stack td::before{
        content:attr(data-label);
        font-weight:700;
        color:#0f2f47;
        text-align:left;
        flex:0 0 42%;
    }
    .table-stack td:last-child{border-bottom:0!important}
    .table-stack .text-end{text-align:left!important}
}

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
                <img src="<?= htmlspecialchars($brandLogo) ?>" alt="3ZERO Club Logo" class="brand-logo" onerror="this.style.display='none';">

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
                <i class="fas fa-tasks"></i> <span>Activites / Participations</span>
            </a></li>
            <li><a href="achievements.php" class="<?= basename($_SERVER['PHP_SELF']) == 'achievements.php' ? 'active' : '' ?>">
                <i class="fas fa-certificate"></i> <span>Achievements</span>
            </a></li>
            <li><a href="developer_info.php" class="<?= basename($_SERVER['PHP_SELF']) == 'developer_info.php' ? 'active' : '' ?>">
                <i class="fas fa-code"></i> <span>Developer Info (Contact for help here)</span>
            </a></li>
            <li><a href="profile.php" class="<?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>">
                <i class="fas fa-user-edit"></i> <span>Profile</span>
            </a></li>
            <?php if (isset($_SESSION['user_id'])): ?>
            <li><a href="logout.php">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a></li>
            <?php endif; ?>
        </ul>
    </aside>
