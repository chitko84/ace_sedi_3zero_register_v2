<?php
// admin/header.php
// Secure session start BEFORE output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';

// --- Auth & Role Gate ---
$user = null;
$admin_id = $_SESSION['user_id'] ?? null;

if (!$admin_id) {
    header('Location: ../login.php');
    exit();
}

// Load user + role
$uSql = "SELECT id, name, email, role, COALESCE(profile_pic,'') AS profile_pic
         FROM users WHERE id = ? LIMIT 1";
$uStmt = $conn->prepare($uSql);
$uStmt->bind_param("i", $admin_id);
$uStmt->execute();
$uRes = $uStmt->get_result();
$user = $uRes->fetch_assoc();

if (!$user || $user['role'] !== 'admin') {
    // Not an admin → kick to user dashboard (or login)
    header('Location: ../user/dashboard.php');
    exit();
}

// Brand + profile image fallbacks
$brandLogo    = '../uploads/aiu_logo.png';
$profilePhoto = !empty($user['profile_pic']) ? $user['profile_pic'] : '../uploads/default-profile.jpg';

// --- Handle marking notifications as read ---
if (isset($_GET['mark_read']) && $_GET['mark_read'] === '1') {
    $markReadSql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $markReadStmt = $conn->prepare($markReadSql);
    $markReadStmt->bind_param("i", $admin_id);
    $markReadStmt->execute();
    
    // Reset unread count
    $unreadCount = 0;
}

// --- Notifications (admin-specific) ---
$notifications = [];
$unreadCount   = 0;

// latest 7 for this admin
$nSql = "SELECT id, COALESCE(title,'Notification') AS title,
                message AS msg_text,
                COALESCE(is_read,0) AS is_read,
                COALESCE(created_at, NOW()) AS created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 7";
if ($nStmt = $conn->prepare($nSql)) {
    $nStmt->bind_param("i", $admin_id);
    if ($nStmt->execute()) {
        $nRes = $nStmt->get_result();
        while ($row = $nRes->fetch_assoc()) {
            $notifications[] = $row;
        }
    }
}

// Count unread (only if we didn't just mark them all as read)
if (!isset($_GET['mark_read'])) {
    $cSql = "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND COALESCE(is_read,0) = 0";
    $cStmt = $conn->prepare($cSql);
    $cStmt->bind_param("i", $admin_id);
    $cStmt->execute();
    $unreadCount = (int)($cStmt->get_result()->fetch_assoc()['c'] ?? 0);
}

// --- Pending Counts for Sidebar ---
$pendingClubs = 0;
$pendingEvents = 0;
$pendingProjects = 0;
$pendingAchievements = 0;

if ($conn) {
    // Pending Clubs
    $pc = $conn->query("SELECT COUNT(*) AS c FROM clubs WHERE status = 'pending'");
    if ($pc) { $pendingClubs = (int)($pc->fetch_assoc()['c'] ?? 0); }
    
    // Pending Events
    $pe = $conn->query("SELECT COUNT(*) AS c FROM events WHERE approval_status = 'pending'");
    if ($pe) { $pendingEvents = (int)($pe->fetch_assoc()['c'] ?? 0); }
    
    // Pending Activities/Participations (Projects)
    $pp = $conn->query("SELECT COUNT(*) AS c FROM projects WHERE approval_status = 'pending'");
    if ($pp) { $pendingProjects = (int)($pp->fetch_assoc()['c'] ?? 0); }
    
    // Pending Achievements
    $pa = $conn->query("SELECT COUNT(*) AS c FROM achievements WHERE approval_status = 'pending'");
    if ($pa) { $pendingAchievements = (int)($pa->fetch_assoc()['c'] ?? 0); }
}

// Helper: is active link
$current = basename($_SERVER['PHP_SELF']);
function active($file) {
    global $current;
    return $current === $file ? 'active' : '';
}

// Helper: display pending badge
function pendingBadge($count) {
    if ($count > 0) {
        return '<span class="badge bg-warning text-dark ms-auto">' . $count . '</span>';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>3ZERO | Admin</title>

    <!-- Icons & Bootstrap -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>
    <script>
        (function () {
            const saved = localStorage.getItem('zeroClubTheme');
            document.documentElement.setAttribute('data-theme', saved === 'dark' || saved === 'light' ? saved : 'light');
        })();
    </script>
    <link rel="stylesheet" href="../assets/css/dark-mode.css"/>
    <script src="../assets/js/theme.js" defer></script>

    <style>
    :root{
        --primary:#1a5276;
        --primary-dark:#154360;
        --secondary:#28b463;
        --accent:#f39c12;
        --light:#f8f9fa;
        --dark:#212529;
        --gray:#6c757d;
        --gray-light:#e9ecef;
        --radius:10px;
        --shadow:0 6px 16px rgba(0,0,0,.12);
        --transition:.25s ease;

        --header-h:70px;
        --sidebar-w: 280px;
        --notif-max-h: 420px;
    }

    html,body{box-sizing:border-box}
    *,*:before,*:after{box-sizing:inherit}
    body{margin:0; background:#f6f7fb; padding-top:var(--header-h)}
    @media(min-width:993px){ body{padding-left:var(--sidebar-w)} }

    /* Header */
    .admin-header{
        position:fixed; top:0; left:0; right:0; height:var(--header-h);
        display:flex; align-items:center; z-index:1100;
        background:linear-gradient(135deg,var(--primary),var(--primary-dark));
        color:#fff; box-shadow:var(--shadow);
    }
    .admin-header .inner{
        width:100%; padding:0 1rem; display:flex; align-items:center; justify-content:space-between;
    }
    .brand{display:flex; align-items:center; gap:.75rem}
    .brand img{width:42px;height:42px;object-fit:contain;border-radius:8px;background:#fff9;padding:3px}
    .brand .title{line-height:1}
    .brand .title .h1{font-size:1.2rem; margin:0; font-weight:700}
    .brand .title small{opacity:.85}

    /* Actions */
    .actions{display:flex; align-items:center; gap:.5rem}
    .icon-btn{
        width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center;
        border-radius:50%; border:0; background:transparent; color:#fff;
    }
    .icon-btn:hover{background:rgba(255,255,255,.12)}
    .badge-dot{position:absolute; top:4px; right:4px; background:var(--accent); color:#fff; border-radius:999px; font-size:.7rem; padding:.15rem .35rem; border:2px solid var(--primary-dark)}

    .profile img{
        width:42px; height:42px; border-radius:50%; object-fit:cover; border:2px solid #ffffff55
    }

    /* Sidebar */
    .sidebar{
        position:fixed; top:var(--header-h); left:0; bottom:0; width:var(--sidebar-w);
        background:linear-gradient(180deg,var(--primary),var(--primary-dark));
        color:#fff; overflow-y:auto; z-index:1090; box-shadow:var(--shadow);
        transform:translateX(0); transition:transform var(--transition);
    }
    @media(max-width:992px){
        .sidebar{transform:translateX(-100%)}
        .sidebar.show{transform:translateX(0)}
    }
    .menu{list-style:none; margin:0; padding:1rem 0}
    .menu a{
        display:flex; align-items:center; gap:.75rem; padding:.75rem 1rem; color:#fff;
        text-decoration:none; border-left:4px solid transparent;
    }
    .menu a:hover, .menu a.active{background:rgba(255,255,255,.10); border-left-color:var(--secondary)}
    .menu i{width:20px; text-align:center}

    /* Notification dropdown */
    .dropdown-menu.notifs{
        width:360px; max-width:92vw; max-height:var(--notif-max-h); overflow:auto; padding:0; border:none;
        box-shadow:0 10px 30px rgba(0,0,0,.15); border-radius:10px;
    }
    .dropdown-menu.notifs .dropdown-header,
    .dropdown-menu.notifs .dropdown-footer{
        position:sticky; background:#fff; z-index:1;
    }
    .dropdown-menu.notifs .dropdown-header{ top:0; border-bottom:1px solid var(--gray-light) }
    .dropdown-menu.notifs .dropdown-footer{ bottom:0; border-top:1px solid var(--gray-light) }
    .notif-item{padding:.75rem 1rem; border-bottom:1px solid var(--gray-light); cursor:pointer; background:#fff}
    .notif-item.unread{background:#fff8e6}
    .notif-item:last-child{border-bottom:none}
    .notif-title{font-weight:600}
    .notif-msg{color:var(--gray); font-size:.92rem; margin:.25rem 0 0}
    .notif-time{font-size:.8rem; color:var(--gray)}

    /* Mobile menu overlay */
    .overlay{
        display:none; position:fixed; top:var(--header-h); left:0; right:0; bottom:0; background:rgba(0,0,0,.4); z-index:1080;
    }
    .overlay.show{display:block}
    @media (max-width: 992px) {
        body{padding-left:0; overflow-x:hidden}
        .admin-header .inner{padding:0 .75rem}
        .brand img{width:36px;height:36px}
        .brand .title .h1{font-size:1rem}
        .brand .title small{display:none}
        .actions{gap:.25rem}
        .icon-btn{width:38px;height:38px}
        .profile img{width:38px;height:38px}
        .sidebar{
            width:min(86vw,320px);
            box-shadow:0 20px 60px rgba(0,0,0,.28);
            will-change:transform;
        }
        .overlay{
            background:rgba(7,18,28,.58);
            backdrop-filter:blur(2px);
        }
        .dropdown-menu.notifs {
            position:fixed!important;
            top:calc(var(--header-h) + .5rem)!important;
            left:.75rem!important;
            right:.75rem!important;
            width:auto;
            max-width:none;
            transform:none!important;
        }
        .container,.container-fluid{max-width:100%; padding-left:.85rem; padding-right:.85rem}
        .card,.filter-card,.result-card,.stat-card{border-radius:10px!important}
        .card-body{padding:1rem}
        .row{--bs-gutter-x:.85rem}
        .btn,.form-control,.form-select,.input-group-text{min-height:42px}
        .btn{white-space:normal}
        .btn-group{display:flex; flex-wrap:wrap; gap:.35rem}
        .btn-group>.btn{border-radius:8px!important; flex:1 1 auto}
        .badge,.chip,.type-pill{white-space:normal; line-height:1.25}
        .table-responsive{border-radius:10px; overflow-x:auto; -webkit-overflow-scrolling:touch}
        .filter-card .row>[class*="col-"],
        form .row>[class*="col-"]{min-width:0}
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
        .table-stack .actions-group,.table-stack form,.table-stack .btn-group{justify-content:flex-end; width:100%}
    }
    </style>
</head>
<body>

<header class="admin-header">
    <div class="inner">
        <div class="brand">
            <button class="icon-btn d-inline-flex d-lg-none" id="btnSidebar" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
            <img src="<?= htmlspecialchars($brandLogo) ?>" alt="Logo" onerror="this.style.display='none'"/>
            <div class="title">
                <div class="h1">3ZERO Admin</div>
                <small>Control Panel</small>
            </div>
        </div>

        <div class="actions">
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                <i class="fa-solid fa-moon"></i>
            </button>
            <!-- Notifications -->
            <div class="dropdown">
                <button class="icon-btn position-relative" id="ddNotifs" data-bs-toggle="dropdown" aria-expanded="false" onclick="markNotificationsAsRead()">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge-dot" id="notificationBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                    <?php endif; ?>
                </button>

                <ul class="dropdown-menu dropdown-menu-end notifs" aria-labelledby="ddNotifs">
                    <li class="dropdown-header px-3 py-2 d-flex align-items-center justify-content-between">
                        <strong>Notifications</strong>
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $unreadCount ?> new</span>
                        <?php endif; ?>
                    </li>

                    <li>
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $n): ?>
                                <div class="notif-item <?= ((int)$n['is_read'] === 0) ? 'unread' : '' ?>">
                                    <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                                    <?php if (!empty($n['msg_text'])): ?>
                                        <div class="notif-msg"><?= htmlspecialchars($n['msg_text']) ?></div>
                                    <?php endif; ?>
                                    <div class="notif-time"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notif-item text-center text-muted">No notifications</div>
                        <?php endif; ?>
                    </li>

                    <li class="dropdown-footer">
                        <a class="dropdown-item text-center py-2" href="notifications.php">
                            <i class="fa-regular fa-bell me-1"></i> View all notifications
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Profile -->
            <div class="profile ms-2">
                <a href="settings.php" class="d-inline-flex align-items-center text-white text-decoration-none">
                    <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="Admin" onerror="this.src='../uploads/default-profile.jpg'"/>
                    <span class="ms-2 d-none d-md-inline"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></span>
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <ul class="menu">
        <li><a href="dashboard.php" class="<?= active('dashboard.php') ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>

        <li class="mt-2 px-3 text-uppercase text-white-50 small">Management</li>
        <li><a href="manage_users.php" class="<?= active('manage_users.php') ?>"><i class="fa-solid fa-users-gear"></i> Users</a></li>
        <li><a href="admins.php" class="<?= active('admins.php') ?>"><i class="fa-solid fa-user-shield"></i> Admins</a></li>
        <li><a href="users_by_club_status.php" class="<?= active('users_by_club_status.php') ?>"><i class="fa-solid fa-user-check"></i> Users by Club Status</a></li>
        <li><a href="filter_by_semester.php" class="<?= active('filter_by_semester.php') ?>"><i class="fa-solid fa-calendar-week"></i> Filter by Semester</a></li>
        <li><a href="manage_clubs.php" class="<?= active('manage_clubs.php') ?>"><i class="fa-solid fa-people-group"></i> Clubs
            <?= pendingBadge($pendingClubs) ?>
        </a></li>
        <li><a href="register_club.php" class="<?= active('register_club.php') ?>"><i class="fa-solid fa-circle-plus"></i> Register Club</a></li>
        <li><a href="manage_events.php" class="<?= active('manage_events.php') ?>"><i class="fa-solid fa-calendar-days"></i> Events
            <?= pendingBadge($pendingEvents) ?>
        </a></li>
        <li><a href="manage_projects.php" class="<?= active('manage_projects.php') ?>"><i class="fa-solid fa-diagram-project"></i> Activities / Participations
            <?= pendingBadge($pendingProjects) ?>
        </a></li>
        <li><a href="manage_achievements.php" class="<?= active('manage_achievements.php') ?>"><i class="fa-solid fa-trophy"></i> Achievements
            <?= pendingBadge($pendingAchievements) ?>
        </a></li>
        <li><a href="birthday_email.php" class="<?= active('birthday_email.php') ?>"><i class="fa-solid fa-cake-candles"></i> Birthday Email</a></li>
        <li><a href="notifications.php" class="<?= active('notifications.php') ?>"><i class="fa-regular fa-bell"></i> Notifications</a></li>

        <li class="mt-2 px-3 text-uppercase text-white-50 small">System</li>
        <li><a href="backup.php" class="<?= active('backup.php') ?>"><i class="fa-solid fa-database"></i> Backup Management</a></li>
        <li><a href="settings.php" class="<?= active('settings.php') ?>"><i class="fa-solid fa-sliders"></i> Settings</a></li>
        <li><a href="logout.php"><i class="fa-solid fa-right-from-bracket fa-rotate-180"></i> Logout</a></li>
    </ul>
</aside>
<div class="overlay" id="overlay"></div>
