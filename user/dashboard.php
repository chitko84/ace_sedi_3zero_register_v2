<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function clean_value($value, $fallback = 'Unknown') {
    $value = trim((string)($value ?? ''));
    return $value === '' ? $fallback : $value;
}

function is_meaningful($value) {
    $value = trim((string)($value ?? ''));
    return $value !== '' && !in_array(strtolower($value), ['unknown', 'missing', 'null', 'n/a', 'na', 'none', '-'], true);
}

function chart_json($value) {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

function status_badge_class($status) {
    $status = strtolower((string)$status);
    if ($status === 'approved') return 'success';
    if ($status === 'pending') return 'warning text-dark';
    if ($status === 'rejected') return 'danger';
    return 'secondary';
}

function cluster_badge_class($cluster) {
    $cluster = strtolower((string)$cluster);
    if (strpos($cluster, 'poverty') !== false) return 'danger';
    if (strpos($cluster, 'unemployment') !== false) return 'primary';
    if (strpos($cluster, 'carbon') !== false) return 'success';
    return 'secondary';
}

function role_label($role) {
    return ucwords(str_replace('_', ' ', clean_value($role, 'regular')));
}

function short_text($text, $limit = 92) {
    $text = trim((string)$text);
    if (strlen($text) <= $limit) return $text;
    return substr($text, 0, max(0, $limit - 3)) . '...';
}

function dashboard_profile_photo($profilePic) {
    if (!is_meaningful($profilePic) || $profilePic === 'default-profile.jpg') {
        return '../uploads/default-profile.jpg';
    }

    $candidates = array_unique([
        $profilePic,
        '../' . ltrim($profilePic, '/'),
        '../uploads/profiles/' . basename($profilePic),
        '../uploads/' . basename($profilePic),
    ]);

    foreach ($candidates as $path) {
        if (file_exists($path)) return $path;
    }

    return '../uploads/default-profile.jpg';
}

function notification_columns($conn) {
    $columns = ['exists' => false, 'message' => null, 'is_read' => false];
    $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return $columns;

    $columns['exists'] = true;
    $messageCol = $conn->query("SHOW COLUMNS FROM notifications LIKE 'message'");
    $bodyCol = $conn->query("SHOW COLUMNS FROM notifications LIKE 'body'");
    $readCol = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
    $columns['message'] = ($messageCol && $messageCol->num_rows > 0) ? 'message' : (($bodyCol && $bodyCol->num_rows > 0) ? 'body' : null);
    $columns['is_read'] = ($readCol && $readCol->num_rows > 0);

    return $columns;
}

$user_sql = "SELECT id, name, email, phone_number, date_of_birth, gender, country,
                    department, program_of_study, intake, area_of_interest,
                    expected_graduation_year, profile_pic, created_at
             FROM users WHERE id = ? LIMIT 1";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$dashboardUser = $user_stmt->get_result()->fetch_assoc();

if (!$dashboardUser) {
    header('Location: ../login.php');
    exit();
}

$user_email = (string)$dashboardUser['email'];
$firstName = trim(explode(' ', clean_value($dashboardUser['name'], 'Student'))[0]);
$profilePhoto = dashboard_profile_photo($dashboardUser['profile_pic'] ?? '');

$profileChecklist = [
    'Profile picture' => $dashboardUser['profile_pic'] ?? '',
    'Date of birth' => $dashboardUser['date_of_birth'] ?? '',
    'Phone number' => $dashboardUser['phone_number'] ?? '',
    'Department' => $dashboardUser['department'] ?? '',
    'Country' => $dashboardUser['country'] ?? '',
    'Gender' => $dashboardUser['gender'] ?? '',
    'Area of interest' => $dashboardUser['area_of_interest'] ?? '',
    'Intake' => $dashboardUser['intake'] ?? '',
    'Graduation year' => $dashboardUser['expected_graduation_year'] ?? '',
];
$completedProfileFields = 0;
$missingProfileFields = [];
foreach ($profileChecklist as $label => $value) {
    if (is_meaningful($value)) {
        $completedProfileFields++;
    } else {
        $missingProfileFields[] = $label;
    }
}
$profileCompletion = (int)round(($completedProfileFields / max(count($profileChecklist), 1)) * 100);

$clubs_sql = "SELECT DISTINCT c.id, c.club_identifier, c.group_name, c.cluster, c.focus_area,
                     c.cluster_advisor, c.date_of_registration, c.status, c.created_at,
                     cm.member_type
              FROM clubs c
              JOIN club_members cm ON cm.club_id = c.id
              WHERE LOWER(TRIM(cm.email)) = LOWER(TRIM(?))
              ORDER BY COALESCE(c.date_of_registration, DATE(c.created_at)) DESC, c.id DESC";
$clubs_stmt = $conn->prepare($clubs_sql);
$clubs_stmt->bind_param("s", $user_email);
$clubs_stmt->execute();
$clubs = $clubs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalClubs = count($clubs);
$clubIds = array_map(fn($club) => (int)$club['id'], $clubs);
$statusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
$focusCounts = [];
$clusterCounts = ['Zero Poverty' => 0, 'Zero Unemployment' => 0, 'Zero Net Carbon Emissions' => 0, 'Other' => 0];
$roleCounts = ['key_person' => 0, 'deputy' => 0, 'regular' => 0];
$timelineCounts = [];

for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} months"));
    $timelineCounts[$key] = 0;
}

foreach ($clubs as $club) {
    $status = strtolower((string)($club['status'] ?? ''));
    if (isset($statusCounts[$status])) $statusCounts[$status]++;

    $focus = clean_value($club['focus_area'] ?? '', 'Unspecified');
    $focusCounts[$focus] = ($focusCounts[$focus] ?? 0) + 1;

    $cluster = clean_value($club['cluster'] ?? '', 'Other');
    $normalizedCluster = 'Other';
    if (stripos($cluster, 'poverty') !== false) $normalizedCluster = 'Zero Poverty';
    if (stripos($cluster, 'unemployment') !== false) $normalizedCluster = 'Zero Unemployment';
    if (stripos($cluster, 'carbon') !== false) $normalizedCluster = 'Zero Net Carbon Emissions';
    $clusterCounts[$normalizedCluster] = ($clusterCounts[$normalizedCluster] ?? 0) + 1;

    $role = strtolower((string)($club['member_type'] ?? 'regular'));
    if (!isset($roleCounts[$role])) $roleCounts[$role] = 0;
    $roleCounts[$role]++;

    $date = $club['date_of_registration'] ?: $club['created_at'];
    if ($date) {
        $monthKey = date('Y-m', strtotime($date));
        if (isset($timelineCounts[$monthKey])) $timelineCounts[$monthKey]++;
    }
}

$timelineLabels = array_map(fn($key) => date('M Y', strtotime($key . '-01')), array_keys($timelineCounts));
$timelineValues = array_values($timelineCounts);

$inClause = '';
$bindTypes = '';
$bindValues = [];
if ($totalClubs > 0) {
    $inClause = implode(',', array_fill(0, $totalClubs, '?'));
    $bindTypes = str_repeat('i', $totalClubs);
    $bindValues = $clubIds;
}

$events = [];
$achievements = [];
$activityCounts = ['events' => 0, 'activities' => 0, 'achievements' => 0];

if ($totalClubs > 0) {
    $events_sql = "SELECT e.id, e.title, e.start_date, e.start_time, e.end_date, e.description, c.group_name
                   FROM events e
                   JOIN clubs c ON c.id = e.club_id
                   WHERE e.club_id IN ($inClause)
                     AND (e.status = 'upcoming' OR e.start_date >= CURDATE())
                   ORDER BY e.start_date ASC, e.start_time ASC
                   LIMIT 5";
    $events_stmt = $conn->prepare($events_sql);
    $events_stmt->bind_param($bindTypes, ...$bindValues);
    $events_stmt->execute();
    $events = $events_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $ach_sql = "SELECT a.id, a.title, a.description, a.achieved_on, a.created_at, c.group_name
                FROM achievements a
                JOIN clubs c ON c.id = a.club_id
                WHERE a.club_id IN ($inClause)
                ORDER BY COALESCE(a.achieved_on, a.created_at) DESC
                LIMIT 5";
    $ach_stmt = $conn->prepare($ach_sql);
    $ach_stmt->bind_param($bindTypes, ...$bindValues);
    $ach_stmt->execute();
    $achievements = $ach_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $activity_sql = "SELECT
                        (SELECT COUNT(*) FROM events WHERE club_id IN ($inClause)) AS event_count,
                        (SELECT COUNT(*) FROM projects WHERE club_id IN ($inClause)) AS project_count,
                        (SELECT COUNT(*) FROM achievements WHERE club_id IN ($inClause)) AS achievement_count";
    $activity_stmt = $conn->prepare($activity_sql);
    $tripleTypes = $bindTypes . $bindTypes . $bindTypes;
    $tripleValues = array_merge($bindValues, $bindValues, $bindValues);
    $activity_stmt->bind_param($tripleTypes, ...$tripleValues);
    $activity_stmt->execute();
    $activityRow = $activity_stmt->get_result()->fetch_assoc();
    $activityCounts = [
        'events' => (int)($activityRow['event_count'] ?? 0),
        'activities' => (int)($activityRow['project_count'] ?? 0),
        'achievements' => (int)($activityRow['achievement_count'] ?? 0),
    ];
}

$notificationPreview = [];
$dashboardUnreadCount = 0;
$notificationMeta = notification_columns($conn);
if ($notificationMeta['exists']) {
    $messageExpr = $notificationMeta['message'] ? $notificationMeta['message'] : "NULL";
    $notif_sql = "SELECT id, COALESCE(title, 'Notification') AS title, {$messageExpr} AS msg_text,
                         COALESCE(is_read, 0) AS is_read, COALESCE(created_at, NOW()) AS created_at
                  FROM notifications
                  WHERE user_id = ?
                  ORDER BY created_at DESC
                  LIMIT 4";
    $notif_stmt = $conn->prepare($notif_sql);
    $notif_stmt->bind_param("i", $user_id);
    $notif_stmt->execute();
    $notificationPreview = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($notificationMeta['is_read']) {
        $unread_sql = "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND COALESCE(is_read, 0) = 0";
        $unread_stmt = $conn->prepare($unread_sql);
        $unread_stmt->bind_param("i", $user_id);
        $unread_stmt->execute();
        $dashboardUnreadCount = (int)($unread_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }
}

$leadershipRoles = ($roleCounts['key_person'] ?? 0) + ($roleCounts['deputy'] ?? 0);
$approvedRate = $totalClubs > 0 ? (int)round(($statusCounts['approved'] / $totalClubs) * 100) : 0;
arsort($focusCounts);

$smartInsights = [];
if ($totalClubs === 0) {
    $smartInsights[] = ['icon' => 'fa-compass', 'title' => 'Start your journey', 'text' => 'Register your first club to unlock analytics, achievements, and participation insights.'];
} else {
    $topFocus = array_key_first($focusCounts);
    $smartInsights[] = ['icon' => 'fa-bullseye', 'title' => 'Primary focus', 'text' => 'You are most active in ' . clean_value($topFocus, 'unspecified') . ' related clubs.'];
    $smartInsights[] = ['icon' => 'fa-chart-line', 'title' => 'Approval health', 'text' => $approvedRate . '% of your club memberships are currently approved.'];
    $smartInsights[] = ['icon' => 'fa-user-shield', 'title' => 'Leadership signal', 'text' => 'You hold ' . $leadershipRoles . ' leadership role' . ($leadershipRoles === 1 ? '' : 's') . ' across your clubs.'];
}
if ($profileCompletion < 100) {
    $smartInsights[] = ['icon' => 'fa-id-card', 'title' => 'Profile opportunity', 'text' => 'Complete ' . count($missingProfileFields) . ' remaining profile item' . (count($missingProfileFields) === 1 ? '' : 's') . ' to improve your portal readiness.'];
}

$activityTimeline = [];
foreach (array_slice($clubs, 0, 3) as $club) {
    $activityTimeline[] = [
        'icon' => 'fa-people-group',
        'title' => 'Joined ' . clean_value($club['group_name']),
        'meta' => role_label($club['member_type'] ?? 'regular') . ' role',
        'date' => $club['date_of_registration'] ?: $club['created_at'],
    ];
    if (($club['status'] ?? '') === 'approved') {
        $activityTimeline[] = [
            'icon' => 'fa-circle-check',
            'title' => clean_value($club['group_name']) . ' approved',
            'meta' => 'Club registration approved',
            'date' => $club['created_at'],
        ];
    }
}
foreach (array_slice($achievements, 0, 2) as $achievement) {
    $activityTimeline[] = [
        'icon' => 'fa-trophy',
        'title' => clean_value($achievement['title']),
        'meta' => clean_value($achievement['group_name']),
        'date' => $achievement['achieved_on'] ?: $achievement['created_at'],
    ];
}
usort($activityTimeline, fn($a, $b) => strtotime($b['date'] ?? '1970-01-01') <=> strtotime($a['date'] ?? '1970-01-01'));
$activityTimeline = array_slice($activityTimeline, 0, 6);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
$taglines = [
    'Turn campus participation into measurable impact.',
    'Track your 3ZERO journey with clarity and confidence.',
    'Build leadership, service, and sustainability momentum today.',
];
$tagline = $taglines[$totalClubs % count($taglines)];

include('header.php');
?>

<link rel="stylesheet" href="../assets/css/ai-assistant.css">

<style>
    :root {
        --dash-primary: #1a5276;
        --dash-primary-dark: #123b57;
        --dash-secondary: #28b463;
        --dash-accent: #f39c12;
        --dash-danger: #dc3545;
        --dash-ink: #123044;
        --dash-muted: #6b7a88;
        --dash-soft: #f4f8fb;
        --dash-card: rgba(255, 255, 255, .86);
        --dash-border: rgba(26, 82, 118, .12);
        --dash-shadow: 0 18px 48px rgba(15, 47, 71, .12);
        --dash-shadow-sm: 0 10px 30px rgba(15, 47, 71, .09);
        --dash-radius: 18px;
    }

    body {
        background:
            radial-gradient(circle at top left, rgba(40,180,99,.14), transparent 30rem),
            radial-gradient(circle at top right, rgba(26,82,118,.16), transparent 34rem),
            linear-gradient(135deg, #f7fbfd 0%, #edf4f8 48%, #f8fafc 100%);
    }

    .dashboard-shell {
        padding: 1.4rem 1.2rem 2.5rem;
        max-width: 1480px;
    }

    .dash-hero,
    .dash-card,
    .stat-tile,
    .club-analytics-card,
    .action-tile {
        background: var(--dash-card);
        border: 1px solid rgba(255,255,255,.7);
        box-shadow: var(--dash-shadow-sm);
        backdrop-filter: blur(18px);
    }

    .dash-hero {
        position: relative;
        overflow: hidden;
        border-radius: 24px;
        color: #fff;
        background:
            linear-gradient(135deg, rgba(26,82,118,.98), rgba(18,59,87,.95) 48%, rgba(40,180,99,.86)),
            url('../uploads/aiu_logo.png') right 3rem center / 220px no-repeat;
        box-shadow: 0 24px 70px rgba(18,59,87,.24);
    }

    .dash-hero::before {
        content: "";
        position: absolute;
        inset: 1px;
        border-radius: 23px;
        border: 1px solid rgba(255,255,255,.18);
        pointer-events: none;
    }

    .dash-hero::after {
        content: "";
        position: absolute;
        width: 460px;
        height: 460px;
        right: -180px;
        top: -210px;
        background: radial-gradient(circle, rgba(255,255,255,.22), transparent 62%);
        animation: floatPulse 8s ease-in-out infinite;
    }

    .hero-content {
        position: relative;
        z-index: 1;
        padding: clamp(1.35rem, 3vw, 2.4rem);
    }

    .hero-avatar {
        width: 86px;
        height: 86px;
        border-radius: 24px;
        object-fit: cover;
        border: 3px solid rgba(255,255,255,.72);
        box-shadow: 0 18px 42px rgba(0,0,0,.25);
    }

    .hero-time {
        background: rgba(255,255,255,.13);
        border: 1px solid rgba(255,255,255,.2);
        border-radius: 16px;
        padding: .85rem 1rem;
        min-width: 210px;
    }

    .hero-stat {
        border-radius: 16px;
        padding: 1rem;
        background: rgba(255,255,255,.13);
        border: 1px solid rgba(255,255,255,.18);
        transition: transform .25s ease, background .25s ease;
        height: 100%;
    }

    .hero-stat:hover {
        transform: translateY(-4px);
        background: rgba(255,255,255,.2);
    }

    .hero-stat .value {
        font-size: clamp(1.35rem, 3vw, 1.9rem);
        font-weight: 800;
        line-height: 1;
    }

    .dash-card {
        border-radius: var(--dash-radius);
        color: var(--dash-ink);
        overflow: hidden;
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }

    .dash-card:hover,
    .stat-tile:hover,
    .club-card:hover,
    .action-tile:hover {
        transform: translateY(-5px);
        box-shadow: var(--dash-shadow);
        border-color: rgba(40,180,99,.28);
    }

    .dash-card-header {
        padding: 1.15rem 1.25rem;
        border-bottom: 1px solid rgba(26,82,118,.09);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .dash-card-header h2,
    .dash-card-header h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: var(--dash-ink);
    }

    .stat-tile {
        position: relative;
        overflow: hidden;
        border-radius: 18px;
        padding: 1.15rem;
        color: var(--dash-ink);
        transition: transform .25s ease, box-shadow .25s ease;
        height: 100%;
    }

    .stat-tile::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: linear-gradient(180deg, var(--tile-color, var(--dash-primary)), rgba(255,255,255,0));
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 15px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, var(--tile-color, var(--dash-primary)), rgba(18,59,87,.88));
        box-shadow: 0 12px 28px rgba(26,82,118,.18);
    }

    .stat-number {
        font-size: 1.9rem;
        font-weight: 850;
        letter-spacing: 0;
        line-height: 1;
    }

    .chart-box {
        position: relative;
        min-height: 280px;
        padding: 1rem;
    }

    .chart-box canvas {
        width: 100% !important;
        height: 280px !important;
    }

    .chart-box.chart-sm canvas {
        height: 230px !important;
    }

    .profile-ring {
        --progress: <?= max(0, min(100, $profileCompletion)) ?>;
        width: 150px;
        height: 150px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        margin: 0 auto;
        background: conic-gradient(var(--dash-secondary) calc(var(--progress) * 1%), #dbe7ee 0);
        position: relative;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.6);
    }

    .profile-ring::before {
        content: "";
        position: absolute;
        width: 112px;
        height: 112px;
        border-radius: 50%;
        background: #fff;
        box-shadow: inset 0 0 0 1px rgba(26,82,118,.08);
    }

    .profile-ring span {
        position: relative;
        font-size: 2rem;
        font-weight: 850;
        color: var(--dash-ink);
    }

    .missing-chip,
    .mini-badge {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        border-radius: 999px;
        padding: .42rem .7rem;
        font-weight: 700;
        font-size: .78rem;
        background: rgba(26,82,118,.08);
        color: var(--dash-primary-dark);
        border: 1px solid rgba(26,82,118,.1);
    }

    .club-card {
        border-radius: 18px;
        border: 1px solid rgba(26,82,118,.1);
        background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,255,255,.78));
        box-shadow: 0 10px 30px rgba(15,47,71,.08);
        padding: 1.15rem;
        height: 100%;
        transition: transform .25s ease, box-shadow .25s ease;
    }

    .club-card .club-icon {
        width: 50px;
        height: 50px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(26,82,118,.1);
        color: var(--dash-primary);
    }

    .achievement-tile {
        border-radius: 16px;
        padding: 1rem;
        background: linear-gradient(180deg, #fff, #f6fafc);
        border: 1px solid rgba(26,82,118,.09);
        height: 100%;
    }

    .achievement-tile.locked {
        opacity: .72;
        filter: grayscale(.25);
    }

    .achievement-icon {
        width: 46px;
        height: 46px;
        border-radius: 15px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, var(--dash-accent), #d97706);
    }

    .timeline {
        position: relative;
        padding-left: 1.35rem;
    }

    .timeline::before {
        content: "";
        position: absolute;
        left: .42rem;
        top: .4rem;
        bottom: .4rem;
        width: 2px;
        background: linear-gradient(180deg, rgba(26,82,118,.28), rgba(40,180,99,.22));
    }

    .timeline-item {
        position: relative;
        padding: 0 0 1.05rem 1.2rem;
    }

    .timeline-item:last-child {
        padding-bottom: 0;
    }

    .timeline-dot {
        position: absolute;
        left: -.04rem;
        top: .15rem;
        width: 32px;
        height: 32px;
        transform: translateX(-50%);
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, var(--dash-primary), var(--dash-secondary));
        box-shadow: 0 8px 20px rgba(26,82,118,.2);
        font-size: .78rem;
    }

    .action-tile {
        display: flex;
        align-items: center;
        gap: .85rem;
        padding: 1rem;
        border-radius: 16px;
        color: var(--dash-ink);
        text-decoration: none;
        height: 100%;
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }

    .action-tile i {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, var(--dash-primary), var(--dash-secondary));
        flex: 0 0 auto;
    }

    .notification-preview-item {
        padding: .9rem 0;
        border-bottom: 1px solid rgba(26,82,118,.08);
    }

    .notification-preview-item:last-child {
        border-bottom: 0;
    }

    .empty-state {
        text-align: center;
        padding: 2.4rem 1.2rem;
    }

    .empty-state i {
        width: 78px;
        height: 78px;
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(26,82,118,.12), rgba(40,180,99,.12));
        color: var(--dash-primary);
        font-size: 2rem;
        margin-bottom: 1rem;
    }

    .btn-dash {
        border: 0;
        border-radius: 999px;
        color: #fff;
        background: linear-gradient(135deg, var(--dash-primary), var(--dash-secondary));
        box-shadow: 0 12px 26px rgba(26,82,118,.2);
        font-weight: 800;
        padding: .72rem 1.1rem;
    }

    .btn-dash:hover {
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 16px 32px rgba(26,82,118,.25);
    }

    .btn-register-compact {
        width: auto;
        min-width: 148px;
        min-height: 38px;
        padding: .52rem .92rem;
        font-size: .9rem;
        line-height: 1.15;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
    }

    .btn-register-compact i {
        font-size: .82rem;
        margin-right: .15rem !important;
    }

    .counter {
        font-variant-numeric: tabular-nums;
    }

    @keyframes floatPulse {
        0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
        50% { transform: translate3d(-18px, 18px, 0) scale(1.04); }
    }

    @media (max-width: 991.98px) {
        .dashboard-shell {
            padding: 1rem .8rem 2rem;
        }

        .hero-time {
            min-width: 100%;
        }

        .chart-box,
        .chart-box canvas {
            min-height: 240px;
            height: 240px !important;
        }
    }

    @media (max-width: 767.98px) {
        html,
        body {
            overflow-x: hidden;
        }

        .dashboard-shell {
            width: 100%;
            max-width: 100%;
            padding: .75rem .65rem 1.4rem;
        }

        .dashboard-shell > section,
        .dashboard-shell .row.mb-4,
        .dashboard-shell .dash-card.mb-4 {
            margin-bottom: .85rem !important;
        }

        .dashboard-shell .row {
            --bs-gutter-x: .75rem;
            --bs-gutter-y: .75rem;
        }

        .dashboard-shell * {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .dash-hero {
            border-radius: 18px;
            padding: 1rem;
        }

        .dash-hero::after {
            width: 220px;
            height: 220px;
            right: -120px;
            bottom: -105px;
            opacity: .45;
        }

        .hero-content {
            padding: .95rem;
        }

        .hero-content .row {
            --bs-gutter-y: 1rem;
        }

        .hero-content h1 {
            font-size: clamp(1.45rem, 7vw, 2.1rem);
            line-height: 1.12;
        }

        .hero-content p,
        .hero-content .text-white-50,
        .hero-content .small {
            font-size: .88rem;
            line-height: 1.45;
        }

        .hero-avatar {
            width: 64px;
            height: 64px;
            border-radius: 18px;
        }

        .dash-card,
        .stat-tile,
        .club-card,
        .action-tile {
            border-radius: 16px;
        }

        .dash-card:hover,
        .stat-tile:hover,
        .club-card:hover,
        .action-tile:hover {
            transform: none;
        }

        .dash-card-header {
            padding: .9rem .95rem;
            align-items: stretch;
            flex-direction: column;
            gap: .65rem;
        }

        .dash-card-header > div {
            width: 100%;
        }

        .dash-card-header h2,
        .dash-card-header h3 {
            font-size: .98rem;
            line-height: 1.28;
        }

        .dash-card-header .btn,
        .dash-card-header .mini-badge {
            width: 100%;
            justify-content: center;
            text-align: center;
        }

        .dashboard-shell .p-4 {
            padding: .95rem !important;
        }

        .dashboard-shell .px-4 {
            padding-left: .95rem !important;
            padding-right: .95rem !important;
        }

        .dashboard-shell .pb-4 {
            padding-bottom: .95rem !important;
        }

        .stat-tile {
            padding: .95rem;
        }

        .stat-tile .d-flex {
            gap: .65rem !important;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            font-size: 1rem;
        }

        .stat-number {
            font-size: 1.48rem;
        }

        .chart-box {
            min-height: 220px;
            padding: .75rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .chart-box canvas {
            min-width: 260px;
            height: 220px !important;
        }

        .chart-box.chart-sm canvas {
            height: 210px !important;
        }

        .profile-ring {
            width: 124px;
            height: 124px;
            margin-left: auto;
            margin-right: auto;
        }

        .profile-ring::before {
            width: 92px;
            height: 92px;
        }

        .profile-ring span {
            font-size: 1.55rem;
        }

        .missing-chip,
        .mini-badge {
            max-width: 100%;
            white-space: normal;
            line-height: 1.3;
        }

        .btn,
        .btn-dash,
        .action-tile {
            min-height: 44px;
        }

        .btn-dash,
        .empty-state .btn,
        .notification-preview-item + .btn,
        .club-card .btn {
            width: 100%;
            justify-content: center;
        }

        .empty-state .btn-register-compact {
            width: auto;
            min-width: 136px;
            max-width: 220px;
            min-height: 40px;
            padding: .5rem .85rem;
            font-size: .88rem;
            margin-left: auto;
            margin-right: auto;
        }

        .club-card {
            padding: 1rem;
        }

        .club-card .d-flex {
            align-items: flex-start !important;
        }

        .club-card .badge {
            max-width: 58%;
            white-space: normal;
            text-align: center;
            line-height: 1.25;
        }

        .notification-preview-item {
            padding: .75rem 0;
        }

        .notification-preview-item .d-flex {
            flex-direction: column;
            gap: .4rem !important;
        }

        .notification-preview-item .badge {
            align-self: flex-start;
        }

        .timeline {
            padding-left: .95rem;
        }

        .timeline-item {
            padding-left: 1.05rem;
            padding-bottom: .85rem;
        }

        .timeline-dot {
            left: -.12rem;
            width: 28px;
            height: 28px;
            font-size: .72rem;
        }

        .action-tile {
            width: 100%;
            padding: .9rem;
            align-items: flex-start;
        }

        .action-tile span {
            display: block;
            line-height: 1.35;
        }

        .action-tile i {
            width: 40px;
            height: 40px;
            border-radius: 13px;
        }

        .empty-state {
            padding: 1.35rem .8rem;
        }

        .empty-state i {
            width: 58px;
            height: 58px;
            font-size: 1.35rem;
            margin-bottom: .8rem;
        }

        .table-responsive,
        .club-analytics-card {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    }

    @media (max-width: 575.98px) {
        .dash-hero {
            border-radius: 18px;
            background:
                linear-gradient(135deg, rgba(26,82,118,.98), rgba(18,59,87,.95) 54%, rgba(40,180,99,.88));
        }

        .hero-content {
            padding: 1.05rem;
        }

        .hero-avatar {
            width: 68px;
            height: 68px;
            border-radius: 18px;
        }

        .dash-card-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .stat-number {
            font-size: 1.55rem;
        }

        .profile-ring {
            width: 132px;
            height: 132px;
        }

        .profile-ring::before {
            width: 98px;
            height: 98px;
        }
    }

    @media (max-width: 479.98px) {
        .dashboard-shell {
            padding: .55rem .5rem 1.15rem;
        }

        .dashboard-shell .row {
            --bs-gutter-x: .6rem;
            --bs-gutter-y: .6rem;
        }

        .dash-hero {
            padding: .75rem;
            border-radius: 16px;
        }

        .hero-content {
            padding: .75rem;
            text-align: center;
        }

        .hero-content .d-flex {
            align-items: center !important;
        }

        .hero-content h1 {
            font-size: clamp(1.32rem, 8vw, 1.8rem);
        }

        .hero-avatar {
            width: 58px;
            height: 58px;
        }

        .dash-card-header {
            padding: .8rem;
        }

        .dashboard-shell .p-4,
        .dashboard-shell .px-4 {
            padding-left: .8rem !important;
            padding-right: .8rem !important;
        }

        .dashboard-shell .p-4,
        .dashboard-shell .pb-4 {
            padding-bottom: .8rem !important;
        }

        .stat-tile {
            padding: .82rem;
        }

        .stat-number {
            font-size: 1.34rem;
        }

        .stat-icon {
            width: 38px;
            height: 38px;
        }

        .chart-box {
            min-height: 200px;
            padding: .55rem;
        }

        .chart-box canvas,
        .chart-box.chart-sm canvas {
            min-width: 240px;
            height: 200px !important;
        }

        .profile-ring {
            width: 112px;
            height: 112px;
        }

        .profile-ring::before {
            width: 82px;
            height: 82px;
        }

        .profile-ring span {
            font-size: 1.38rem;
        }

        .club-card {
            padding: .9rem;
        }

        .club-card .club-icon {
            width: 42px;
            height: 42px;
            border-radius: 13px;
        }

        .club-card .badge {
            max-width: 62%;
            font-size: .68rem;
            padding: .42rem .55rem;
        }

        .action-tile {
            padding: .82rem;
        }

        .action-tile i {
            width: 38px;
            height: 38px;
        }

        .btn,
        .btn-dash {
            min-height: 46px;
            white-space: normal;
        }

        .empty-state .btn-register-compact {
            width: auto;
            min-width: 128px;
            max-width: 200px;
            min-height: 40px;
            padding: .48rem .78rem;
            font-size: .84rem;
        }

        .empty-state .btn-register-compact i {
            font-size: .78rem;
        }

        .empty-state {
            padding: 1.1rem .65rem;
        }
    }
</style>

<main class="main-content dashboard-shell container-fluid" id="mainContent">
    <section class="dash-hero mb-4">
        <div class="hero-content">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3">
                        <img src="<?= h($profilePhoto) ?>" class="hero-avatar" alt="Profile picture" onerror="this.onerror=null; this.src='../uploads/default-profile.jpg';">
                        <div>
                            <div class="text-white-50 fw-semibold mb-1">Student analytics portal</div>
                            <h1 class="display-6 fw-bold mb-2" id="heroGreeting"><?= h($greeting) ?>, <?= h($firstName) ?></h1>
                            <p class="mb-0 text-white-75"><?= h($tagline) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="hero-time ms-lg-auto">
                        <div class="small text-white-50 fw-semibold">Today</div>
                        <div class="h5 mb-1 fw-bold" id="currentDate"><?= h(date('l, F j, Y')) ?></div>
                        <div class="fs-5 fw-bold" id="currentTime"><?= h(date('g:i A')) ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-3">
                <?php
                $heroStats = [
                    ['Total Clubs', $totalClubs, 'fa-people-group'],
                    ['Approved', $statusCounts['approved'], 'fa-circle-check'],
                    ['Pending', $statusCounts['pending'], 'fa-hourglass-half'],
                    ['Rejected', $statusCounts['rejected'], 'fa-circle-xmark'],
                    ['Profile', $profileCompletion . '%', 'fa-id-badge'],
                ];
                foreach ($heroStats as $stat):
                ?>
                    <div class="col-6 col-lg-2">
                        <div class="hero-stat">
                            <i class="fa-solid <?= h($stat[2]) ?> mb-2"></i>
                            <div class="value <?= is_numeric($stat[1]) ? 'counter' : '' ?>" data-count="<?= is_numeric($stat[1]) ? (int)$stat[1] : '' ?>"><?= h($stat[1]) ?></div>
                            <div class="small text-white-50 fw-semibold"><?= h($stat[0]) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <?php
        $statCards = [
            ['Total Clubs', $totalClubs, 'fa-users', '#1a5276', 'Membership portfolio'],
            ['Approved Clubs', $statusCounts['approved'], 'fa-check-double', '#28b463', $approvedRate . '% approval rate'],
            ['Activities', $activityCounts['activities'], 'fa-diagram-project', '#f39c12', 'Participation records'],
            ['Achievements', $activityCounts['achievements'], 'fa-trophy', '#8e44ad', 'Impact highlights'],
        ];
        foreach ($statCards as $card):
        ?>
            <div class="col-6 col-xl-3">
                <div class="stat-tile" style="--tile-color: <?= h($card[3]) ?>;">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="text-muted small fw-bold text-uppercase"><?= h($card[0]) ?></div>
                            <div class="stat-number counter mt-2" data-count="<?= (int)$card[1] ?>"><?= (int)$card[1] ?></div>
                            <div class="small text-muted mt-2"><?= h($card[4]) ?></div>
                        </div>
                        <span class="stat-icon"><i class="fa-solid <?= h($card[2]) ?>"></i></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <div>
                        <h2><i class="fa-solid fa-chart-line me-2 text-primary"></i>Participation Timeline</h2>
                        <div class="small text-muted">Monthly club registrations and growth trend</div>
                    </div>
                    <span class="mini-badge"><i class="fa-solid fa-arrow-trend-up"></i> Last 6 months</span>
                </div>
                <div class="chart-box">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <div>
                        <h2><i class="fa-solid fa-circle-notch me-2 text-primary"></i>Club Status</h2>
                        <div class="small text-muted">Approved, pending, and rejected distribution</div>
                    </div>
                </div>
                <div class="chart-box chart-sm">
                    <canvas id="statusChart"></canvas>
                </div>
                <div class="px-3 pb-3 d-flex flex-wrap gap-2">
                    <span class="mini-badge">Approved <?= (int)$statusCounts['approved'] ?></span>
                    <span class="mini-badge">Pending <?= (int)$statusCounts['pending'] ?></span>
                    <span class="mini-badge">Rejected <?= (int)$statusCounts['rejected'] ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <h2><i class="fa-solid fa-bullseye me-2 text-primary"></i>Focus Area Analytics</h2>
                </div>
                <div class="chart-box chart-sm">
                    <canvas id="focusChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <h2><i class="fa-solid fa-globe me-2 text-primary"></i>Cluster Distribution</h2>
                </div>
                <div class="chart-box chart-sm">
                    <canvas id="clusterChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <h2><i class="fa-solid fa-user-group me-2 text-primary"></i>Member Role Breakdown</h2>
                </div>
                <div class="chart-box chart-sm">
                    <canvas id="roleChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <div>
                        <h2><i class="fa-solid fa-id-card me-2 text-primary"></i>Profile Completion</h2>
                        <div class="small text-muted"><?= count($missingProfileFields) ?> item<?= count($missingProfileFields) === 1 ? '' : 's' ?> remaining</div>
                    </div>
                </div>
                <div class="p-4">
                    <div class="profile-ring mb-3"><span><?= (int)$profileCompletion ?>%</span></div>
                    <?php if (empty($missingProfileFields)): ?>
                        <div class="alert alert-success mb-3"><i class="fa-solid fa-check-circle me-2"></i>Your profile is complete.</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($missingProfileFields as $missing): ?>
                                <span class="missing-chip"><i class="fa-solid fa-plus"></i><?= h($missing) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="profile.php" class="btn btn-dash w-100"><i class="fa-solid fa-pen-to-square me-2"></i>Complete your profile</a>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                        <h2><i class="fa-solid fa-lightbulb me-2 text-primary"></i>Smart Insights</h2>
                </div>
                <div class="p-4">
                    <?php foreach (array_slice($smartInsights, 0, 4) as $insight): ?>
                        <div class="d-flex gap-3 mb-3">
                            <span class="stat-icon flex-shrink-0" style="width:42px;height:42px;"><i class="fa-solid <?= h($insight['icon']) ?>"></i></span>
                            <div>
                                <div class="fw-bold"><?= h($insight['title']) ?></div>
                                <div class="small text-muted"><?= h($insight['text']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <div>
                        <h2><i class="fa-solid fa-bell me-2 text-primary"></i>Notifications</h2>
                        <div class="small text-muted"><?= (int)$dashboardUnreadCount ?> unread update<?= $dashboardUnreadCount === 1 ? '' : 's' ?></div>
                    </div>
                    <a href="notifications.php" class="btn btn-sm btn-outline-primary rounded-pill">Open</a>
                </div>
                <div class="px-4 pb-4">
                    <?php if (empty($notificationPreview)): ?>
                        <div class="empty-state py-4">
                            <i class="fa-regular fa-bell"></i>
                            <h3 class="h6 fw-bold">No notifications yet</h3>
                            <p class="small text-muted mb-0">Important updates will appear here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notificationPreview as $notification): ?>
                            <div class="notification-preview-item">
                                <div class="d-flex justify-content-between gap-3">
                                    <div class="fw-bold <?= ((int)$notification['is_read'] === 0) ? 'text-primary' : '' ?>"><?= h($notification['title']) ?></div>
                                    <?php if ((int)$notification['is_read'] === 0): ?><span class="badge bg-warning text-dark">New</span><?php endif; ?>
                                </div>
                                <?php if (!empty($notification['msg_text'])): ?>
                                    <div class="small text-muted mt-1"><?= h(short_text($notification['msg_text'], 92)) ?></div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1"><i class="fa-regular fa-clock me-1"></i><?= h(date('M j, Y g:i A', strtotime($notification['created_at']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($dashboardUnreadCount > 0): ?>
                            <a href="notifications.php?mark_all_read=1" class="btn btn-outline-primary btn-sm rounded-pill mt-2">Mark all as read</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dash-card mb-4">
        <div class="dash-card-header">
            <div>
                <h2><i class="fa-solid fa-people-roof me-2 text-primary"></i>My Clubs</h2>
                <div class="small text-muted">Your memberships, roles, advisors, registration dates, and approval status</div>
            </div>
            <a href="myclubs.php" class="btn btn-sm btn-outline-primary rounded-pill">View all</a>
        </div>
        <div class="p-4">
            <?php if ($totalClubs === 0): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-people-group"></i>
                    <h3 class="h5 fw-bold mb-2">No clubs joined yet</h3>
                    <p class="text-muted mb-4">Create your first 3ZERO club registration and your analytics will start filling in automatically.</p>
                    <a href="club_registration.php" class="btn btn-dash btn-register-compact"><i class="fa-solid fa-plus me-2"></i>Register Club</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach (array_slice($clubs, 0, 6) as $club): ?>
                        <div class="col-md-6 col-xl-4">
                            <article class="club-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                    <span class="club-icon"><i class="fa-solid fa-people-group"></i></span>
                                    <span class="badge bg-<?= h(status_badge_class($club['status'])) ?> rounded-pill"><?= h(ucfirst(clean_value($club['status']))) ?></span>
                                </div>
                                <h3 class="h5 fw-bold mb-2"><?= h($club['group_name']) ?></h3>
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <span class="badge bg-<?= h(cluster_badge_class($club['cluster'])) ?>"><?= h(clean_value($club['cluster'])) ?></span>
                                    <span class="badge bg-light text-dark border"><?= h(clean_value($club['focus_area'], 'Unspecified')) ?></span>
                                </div>
                                <div class="small text-muted d-grid gap-2 mb-3">
                                    <div><i class="fa-solid fa-user-tag me-2 text-primary"></i><strong>Role:</strong> <?= h(role_label($club['member_type'])) ?></div>
                                    <div><i class="fa-solid fa-user-tie me-2 text-primary"></i><strong>Advisor:</strong> <?= h(clean_value($club['cluster_advisor'])) ?></div>
                                    <div><i class="fa-regular fa-calendar me-2 text-primary"></i><strong>Registered:</strong> <?= $club['date_of_registration'] ? h(date('M j, Y', strtotime($club['date_of_registration']))) : 'Unknown' ?></div>
                                </div>
                                <a href="club_details.php?id=<?= (int)$club['id'] ?>" class="btn btn-outline-primary btn-sm rounded-pill"><i class="fa-solid fa-eye me-1"></i>View Club</a>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-12">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <h2><i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Recent Activity</h2>
                </div>
                <div class="p-4">
                    <?php if (empty($activityTimeline)): ?>
                        <div class="empty-state py-4">
                            <i class="fa-solid fa-stream"></i>
                            <h3 class="h6 fw-bold">No activity yet</h3>
                            <p class="small text-muted mb-0">Your club journey timeline will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($activityTimeline as $item): ?>
                                <div class="timeline-item">
                                    <span class="timeline-dot"><i class="fa-solid <?= h($item['icon']) ?>"></i></span>
                                    <div class="fw-bold"><?= h($item['title']) ?></div>
                                    <div class="small text-muted"><?= h($item['meta']) ?></div>
                                    <div class="small text-muted"><?= !empty($item['date']) ? h(date('M j, Y', strtotime($item['date']))) : 'Recently' ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <?php
        $actions = [
            ['Register Club', 'Create a new club registration', 'club_registration.php', 'fa-user-plus'],
            ['My Clubs', 'Manage club memberships', 'myclubs.php', 'fa-users'],
            ['Notifications', 'Read latest updates', 'notifications.php', 'fa-bell'],
            ['Update Profile', 'Improve profile completion', 'profile.php', 'fa-user-pen'],
            ['View Semester Info', 'Review club semester details', 'myclubs.php', 'fa-calendar-days'],
        ];
        foreach ($actions as $action):
        ?>
            <div class="col-12 col-sm-6 col-xl">
                <a class="action-tile" href="<?= h($action[2]) ?>">
                    <i class="fa-solid <?= h($action[3]) ?>"></i>
                    <span><strong><?= h($action[0]) ?></strong><br><small class="text-muted"><?= h($action[1]) ?></small></span>
                </a>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="row g-4">
        <div class="col-12">
            <div class="dash-card h-100">
                <div class="dash-card-header">
                    <h2><i class="fa-regular fa-calendar me-2 text-primary"></i>Upcoming Events</h2>
                    <a href="events.php" class="btn btn-sm btn-outline-primary rounded-pill">View all</a>
                </div>
                <div class="p-4">
                    <?php if (empty($events)): ?>
                        <div class="empty-state py-4">
                            <i class="fa-regular fa-calendar"></i>
                            <h3 class="h6 fw-bold">No upcoming events</h3>
                            <p class="small text-muted mb-0">Approved club events will appear here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <div class="notification-preview-item">
                                <div class="fw-bold"><?= h($event['title']) ?></div>
                                <div class="small text-muted"><?= h($event['group_name']) ?></div>
                                <div class="small text-muted"><i class="fa-regular fa-clock me-1"></i><?= h(date('M j, Y', strtotime($event['start_date']))) ?><?= !empty($event['start_time']) && $event['start_time'] !== '00:00:00' ? ' at ' . h(date('g:i A', strtotime($event['start_time']))) : '' ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <button type="button" class="ai-chat-launcher" id="aiChatLauncher" aria-label="Ask AI Assistant" title="Ask AI Assistant">
        <i class="fa-solid fa-robot"></i>
    </button>

    <section class="ai-chat-panel" id="aiChatPanel" aria-hidden="true" aria-label="3ZERO AI Assistant">
        <div class="ai-chat-header">
            <div class="ai-chat-title">
                <span class="ai-avatar"><i class="fa-solid fa-robot"></i></span>
                <div>
                    <strong>3ZERO AI Assistant</strong>
                    <small>Your helper</small>
                </div>
            </div>
            <button type="button" class="ai-chat-close" id="aiChatClose" aria-label="Close assistant">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="ai-chat-body" id="aiChatBody"></div>

        <div class="ai-quick-prompts" id="aiQuickPrompts">
            <button type="button" class="ai-chip" data-ai-prompt="Show my clubs">Show my clubs</button>
            <button type="button" class="ai-chip" data-ai-prompt="Check my profile">Check my profile</button>
            <button type="button" class="ai-chip" data-ai-prompt="Any upcoming events?">Upcoming events</button>
            <button type="button" class="ai-chip" data-ai-prompt="How to register a club?">Register club</button>
        </div>

        <form class="ai-chat-input" id="aiChatForm">
            <textarea id="aiChatInput" maxlength="500" rows="1" placeholder="Ask about clubs, profile, events..." aria-label="Message AI Assistant"></textarea>
            <button type="submit" class="ai-send-btn" id="aiChatSend" aria-label="Send message">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </form>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const userFirstName = <?= chart_json($firstName) ?>;
    const statusData = <?= chart_json(array_values($statusCounts)) ?>;
    const timelineLabels = <?= chart_json($timelineLabels) ?>;
    const timelineValues = <?= chart_json($timelineValues) ?>;
    const focusLabels = <?= chart_json(array_keys($focusCounts)) ?>;
    const focusValues = <?= chart_json(array_values($focusCounts)) ?>;
    const clusterLabels = <?= chart_json(array_keys($clusterCounts)) ?>;
    const clusterValues = <?= chart_json(array_values($clusterCounts)) ?>;
    const roleLabels = <?= chart_json(array_map('role_label', array_keys($roleCounts))) ?>;
    const roleValues = <?= chart_json(array_values($roleCounts)) ?>;

    function updateClock() {
        const now = new Date();
        const hour = now.getHours();
        const greeting = hour < 12 ? 'Good Morning' : (hour < 18 ? 'Good Afternoon' : 'Good Evening');
        const dateEl = document.getElementById('currentDate');
        const timeEl = document.getElementById('currentTime');
        const greetingEl = document.getElementById('heroGreeting');

        if (dateEl) dateEl.textContent = now.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        if (timeEl) timeEl.textContent = now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        if (greetingEl) greetingEl.textContent = `${greeting}, ${userFirstName}`;
    }

    updateClock();
    setInterval(updateClock, 30000);

    document.querySelectorAll('.counter[data-count]').forEach((el) => {
        const target = parseInt(el.dataset.count || '0', 10);
        if (!Number.isFinite(target)) return;
        let current = 0;
        const duration = 900;
        const start = performance.now();
        function tick(now) {
            const progress = Math.min((now - start) / duration, 1);
            current = Math.round(target * (1 - Math.pow(1 - progress, 3)));
            el.textContent = current.toLocaleString();
            if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    });

    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
    Chart.defaults.color = '#5f7180';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(18, 48, 68, .94)';
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 10;

    const palette = ['#28b463', '#f39c12', '#dc3545', '#1a5276', '#8e44ad', '#17a2b8', '#64748b'];
    const emptyPlugin = {
        id: 'emptyState',
        afterDraw(chart) {
            const data = chart.data.datasets[0]?.data || [];
            const total = data.reduce((sum, value) => sum + Number(value || 0), 0);
            if (total > 0) return;
            const { ctx, chartArea } = chart;
            ctx.save();
            ctx.fillStyle = '#6b7a88';
            ctx.textAlign = 'center';
            ctx.font = '700 14px Segoe UI';
            ctx.fillText('No data yet', (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2);
            ctx.restore();
        }
    };

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Pending', 'Rejected'],
            datasets: [{ data: statusData, backgroundColor: ['#28b463', '#f39c12', '#dc3545'], borderWidth: 0, hoverOffset: 10 }]
        },
        plugins: [emptyPlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '66%',
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label(context) {
                            const total = context.dataset.data.reduce((sum, value) => sum + Number(value || 0), 0);
                            const value = Number(context.parsed || 0);
                            const pct = total ? Math.round((value / total) * 100) : 0;
                            return `${context.label}: ${value} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('timelineChart'), {
        type: 'line',
        data: {
            labels: timelineLabels,
            datasets: [{
                label: 'Registrations',
                data: timelineValues,
                tension: .42,
                fill: true,
                borderColor: '#1a5276',
                backgroundColor: 'rgba(26,82,118,.12)',
                pointBackgroundColor: '#28b463',
                pointBorderColor: '#fff',
                pointBorderWidth: 3,
                pointRadius: 5
            }]
        },
        plugins: [emptyPlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(26,82,118,.08)' } }
            },
            plugins: { legend: { display: false } }
        }
    });

    new Chart(document.getElementById('focusChart'), {
        type: 'bar',
        data: {
            labels: focusLabels.length ? focusLabels : ['No focus area'],
            datasets: [{ label: 'Clubs', data: focusValues.length ? focusValues : [0], backgroundColor: palette, borderRadius: 10 }]
        },
        plugins: [emptyPlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(26,82,118,.08)' } }
            },
            plugins: { legend: { display: false } }
        }
    });

    new Chart(document.getElementById('clusterChart'), {
        type: 'polarArea',
        data: {
            labels: clusterLabels,
            datasets: [{ data: clusterValues, backgroundColor: ['rgba(220,53,69,.76)', 'rgba(26,82,118,.78)', 'rgba(40,180,99,.78)', 'rgba(100,116,139,.58)'], borderWidth: 0 }]
        },
        plugins: [emptyPlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { r: { ticks: { precision: 0, backdropColor: 'transparent' }, grid: { color: 'rgba(26,82,118,.08)' } } },
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('roleChart'), {
        type: 'bar',
        data: {
            labels: roleLabels,
            datasets: [{ label: 'Roles', data: roleValues, backgroundColor: ['#1a5276', '#28b463', '#f39c12'], borderRadius: 10 }]
        },
        plugins: [emptyPlugin],
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(26,82,118,.08)' } },
                y: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });
});
</script>
<script src="../assets/js/ai-assistant.js"></script>

<?php include('footer.php'); ?>
