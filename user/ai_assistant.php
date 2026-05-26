<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../includes/db.php';
$configPath = __DIR__ . '/../includes/ai_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

function send_json($payload, $status = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    send_json(['reply' => 'Please log in first to use the assistant.', 'suggestions' => ['Login']], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['reply' => 'Invalid request method.', 'suggestions' => ['Help']], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$message = trim((string)($input['message'] ?? ''));
$message = substr($message, 0, 500);

if ($message === '') {
    send_json(['reply' => 'Send me a question and I will help with your 3ZERO dashboard.', 'suggestions' => ['Show my clubs', 'Check my profile', 'Any events?']]);
}

function htext($value) {
    return trim((string)($value ?? ''));
}

function safe_label($value, $fallback = 'Unknown') {
    $value = htext($value);
    return $value === '' ? $fallback : $value;
}

function fmt_date($value) {
    if (!$value) return 'Unknown date';
    $ts = strtotime($value);
    return $ts ? date('M j, Y', $ts) : 'Unknown date';
}

function fmt_datetime($date, $time = '') {
    $dateText = fmt_date($date);
    if ($time && $time !== '00:00:00') {
        $ts = strtotime($time);
        if ($ts) return $dateText . ' at ' . date('g:i A', $ts);
    }
    return $dateText;
}

function table_exists($conn, $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c
                            FROM information_schema.tables
                            WHERE table_schema = DATABASE()
                              AND table_name = ?
                            LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int)($row['c'] ?? 0)) > 0;
}

function table_columns($conn, $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return [];
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
    }
    return $cols;
}

function has_col($cols, $col) {
    return isset($cols[$col]);
}

function normalize_message($text) {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function fuzzy_has($message, $terms) {
    foreach ($terms as $term) {
        $term = strtolower($term);
        if (strlen($term) <= 3) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/', $message)) return true;
        } elseif (strpos($message, $term) !== false) {
            return true;
        }
    }

    $words = explode(' ', $message);
    foreach ($words as $word) {
        if (strlen($word) < 4) continue;
        foreach ($terms as $term) {
            foreach (explode(' ', strtolower($term)) as $termWord) {
                if (strlen($termWord) < 4) continue;
                $distance = levenshtein($word, $termWord);
                if ($distance <= (strlen($termWord) > 7 ? 2 : 1)) return true;
            }
        }
    }
    return false;
}

function detect_intent($message) {
    $intents = [
        'greeting' => ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'salam'],
        'clubs' => ['what clubs', 'show my clubs', 'my club list', 'which clubs', 'joined', 'am i in any club', 'club list', 'my clubs'],
        'club_status' => ['club status', 'approved clubs', 'pending clubs', 'rejected clubs', 'approved', 'pending', 'rejected', 'status'],
        'profile' => ['profile completion', 'profile complete', 'missing profile', 'complete my profile', 'what is missing', 'profile'],
        'registration' => ['register club', 'create club', 'new club', 'how to register', 'club registration', 'start club'],
        'notifications' => ['notifications', 'announcement', 'announcements', 'latest updates', 'updates', 'inbox'],
        'events' => ['events', 'upcoming events', 'club events', 'coming event', 'schedule'],
        'semester' => ['semester', 'current semester', 'what semester', 'my semester', 'intake', 'graduation'],
        'focus' => ['focus area', 'focus areas', 'environment', 'food', 'education', 'health', 'active in'],
        'cluster' => ['cluster', 'zero poverty', 'poverty', 'zero unemployment', 'unemployment', 'carbon', 'net carbon'],
        'leadership' => ['key person', 'deputy', 'leader', 'leadership', 'my role', 'what is my role', 'roles'],
        'achievements' => ['achievements', 'accomplishments', 'awards', 'show achievements', 'trophy'],
        'projects' => ['projects', 'activities', 'participations', 'ongoing projects', 'completed projects'],
        'help' => ['help', 'commands', 'what can you do', 'assist', 'guide me'],
        'three_zero' => ['what is 3zero', 'what is three zero', 'explain 3zero', 'three zeros', '3zero club'],
    ];

    $scores = [];
    foreach ($intents as $intent => $terms) {
        $scores[$intent] = fuzzy_has($message, $terms) ? 1 : 0;
    }

    if (($scores['clubs'] ?? 0) && preg_match('/status|approved|pending|rejected/', $message)) return 'club_status';
    if (($scores['events'] ?? 0) && preg_match('/achievement|project|activity/', $message) === 1) {
        if (strpos($message, 'achievement') !== false) return 'achievements';
        if (strpos($message, 'project') !== false || strpos($message, 'activity') !== false) return 'projects';
    }

    arsort($scores);
    $top = array_key_first($scores);
    return ($scores[$top] ?? 0) > 0 ? $top : 'fallback';
}

function profile_completion($user) {
    $fields = [
        'Profile picture' => $user['profile_pic'] ?? '',
        'Date of birth' => $user['date_of_birth'] ?? '',
        'Phone number' => $user['phone_number'] ?? '',
        'Department' => $user['department'] ?? '',
        'Country' => $user['country'] ?? '',
        'Gender' => $user['gender'] ?? '',
        'Area of interest' => $user['area_of_interest'] ?? '',
        'Intake' => $user['intake'] ?? '',
        'Graduation year' => $user['expected_graduation_year'] ?? '',
    ];
    $complete = [];
    $missing = [];
    foreach ($fields as $label => $value) {
        $value = trim((string)$value);
        if ($value !== '' && !in_array(strtolower($value), ['unknown', 'missing', 'null', 'n/a', 'na', 'none', '-'], true)) {
            $complete[] = $label;
        } else {
            $missing[] = $label;
        }
    }
    return [
        'percent' => (int)round((count($complete) / max(count($fields), 1)) * 100),
        'complete' => $complete,
        'missing' => $missing,
    ];
}

function in_clause($ids) {
    return implode(',', array_fill(0, count($ids), '?'));
}

$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, name, email, phone_number, date_of_birth, gender, country, department, program_of_study, intake, area_of_interest, expected_graduation_year, profile_pic FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    send_json(['reply' => 'I could not load your user profile. Please log in again.', 'suggestions' => ['Login']], 401);
}

$firstName = explode(' ', safe_label($user['name'], 'there'))[0];
$userEmail = (string)$user['email'];

$clubs = [];
if (table_exists($conn, 'club_members') && table_exists($conn, 'clubs')) {
    $clubCols = table_columns($conn, 'clubs');
    $memberCols = table_columns($conn, 'club_members');
    if (has_col($clubCols, 'id') && has_col($memberCols, 'club_id') && has_col($memberCols, 'email')) {
        $select = [
            "c.id AS id",
            has_col($clubCols, 'club_identifier') ? "c.club_identifier AS club_identifier" : "'' AS club_identifier",
            has_col($clubCols, 'group_name') ? "c.group_name AS group_name" : "CONCAT('Club #', c.id) AS group_name",
            has_col($clubCols, 'status') ? "c.status AS status" : "'unknown' AS status",
            has_col($clubCols, 'cluster') ? "c.cluster AS cluster" : "'' AS cluster",
            has_col($clubCols, 'focus_area') ? "c.focus_area AS focus_area" : "'' AS focus_area",
            has_col($clubCols, 'date_of_registration') ? "c.date_of_registration AS date_of_registration" : "NULL AS date_of_registration",
            has_col($memberCols, 'member_type') ? "cm.member_type AS member_type" : "'regular' AS member_type",
            has_col($memberCols, 'current_semester') ? "cm.current_semester AS current_semester" : "'' AS current_semester",
        ];
        $order = has_col($clubCols, 'date_of_registration') ? "c.date_of_registration DESC, c.id DESC" : "c.id DESC";
        $sql = "SELECT " . implode(', ', $select) . "
                FROM club_members cm
                LEFT JOIN clubs c ON c.id = cm.club_id
                WHERE LOWER(TRIM(cm.email)) = LOWER(TRIM(?))
                ORDER BY $order";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('s', $userEmail);
            $st->execute();
            $clubs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$clubIds = [];
foreach ($clubs as $club) {
    if (!empty($club['id'])) $clubIds[] = (int)$club['id'];
}
$clubIds = array_values(array_unique($clubIds));

$notifications = [];
if (table_exists($conn, 'notifications')) {
    $cols = table_columns($conn, 'notifications');
    $msgCol = has_col($cols, 'message') ? 'message' : (has_col($cols, 'body') ? 'body' : null);
    $msgExpr = $msgCol ? "`$msgCol`" : "''";
    $titleExpr = has_col($cols, 'title') ? "COALESCE(title, 'Notification')" : "'Notification'";
    $readExpr = has_col($cols, 'is_read') ? "COALESCE(is_read, 0)" : "0";
    $dateExpr = has_col($cols, 'created_at') ? "created_at" : "NOW()";
    $sql = "SELECT id, $titleExpr AS title, $msgExpr AS msg_text, $readExpr AS is_read, $dateExpr AS created_at
            FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();
        $notifications = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$events = [];
if ($clubIds && table_exists($conn, 'events') && table_exists($conn, 'clubs')) {
    $cols = table_columns($conn, 'events');
    $clubCols = table_columns($conn, 'clubs');
    if (has_col($cols, 'club_id') && has_col($clubCols, 'id')) {
        $approval = has_col($cols, 'approval_status') ? " AND COALESCE(e.approval_status, 'approved') = 'approved'" : "";
        $dateFilter = has_col($cols, 'start_date') ? " AND (e.start_date >= CURDATE()" . (has_col($cols, 'status') ? " OR COALESCE(e.status, '') = 'upcoming'" : "") . ")" : "";
        $select = [
            has_col($cols, 'id') ? "e.id AS id" : "0 AS id",
            has_col($cols, 'title') ? "e.title AS title" : "'Untitled event' AS title",
            has_col($cols, 'start_date') ? "e.start_date AS start_date" : "NULL AS start_date",
            has_col($cols, 'start_time') ? "e.start_time AS start_time" : "'' AS start_time",
            has_col($cols, 'end_date') ? "e.end_date AS end_date" : "NULL AS end_date",
            has_col($clubCols, 'group_name') ? "c.group_name AS group_name" : "CONCAT('Club #', c.id) AS group_name",
        ];
        $order = has_col($cols, 'start_date') ? "e.start_date ASC" . (has_col($cols, 'start_time') ? ", e.start_time ASC" : "") : (has_col($cols, 'id') ? "e.id DESC" : "e.club_id DESC");
        $sql = "SELECT " . implode(', ', $select) . "
                FROM events e
                JOIN clubs c ON c.id = e.club_id
                WHERE e.club_id IN (" . in_clause($clubIds) . ")
                $dateFilter
                $approval
                ORDER BY $order
                LIMIT 5";
        if ($st = $conn->prepare($sql)) {
            $types = str_repeat('i', count($clubIds));
            $st->bind_param($types, ...$clubIds);
            $st->execute();
            $events = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$achievements = [];
if ($clubIds && table_exists($conn, 'achievements') && table_exists($conn, 'clubs')) {
    $cols = table_columns($conn, 'achievements');
    $clubCols = table_columns($conn, 'clubs');
    if (has_col($cols, 'club_id') && has_col($clubCols, 'id')) {
        $approval = has_col($cols, 'approval_status') ? " AND COALESCE(a.approval_status, 'approved') = 'approved'" : "";
        $select = [
            has_col($cols, 'id') ? "a.id AS id" : "0 AS id",
            has_col($cols, 'title') ? "a.title AS title" : "'Untitled achievement' AS title",
            has_col($cols, 'description') ? "a.description AS description" : "'' AS description",
            has_col($cols, 'achieved_on') ? "a.achieved_on AS achieved_on" : "NULL AS achieved_on",
            has_col($cols, 'created_at') ? "a.created_at AS created_at" : "NULL AS created_at",
            has_col($clubCols, 'group_name') ? "c.group_name AS group_name" : "CONCAT('Club #', c.id) AS group_name",
        ];
        $order = has_col($cols, 'achieved_on') && has_col($cols, 'created_at') ? "COALESCE(a.achieved_on, a.created_at) DESC" : (has_col($cols, 'id') ? "a.id DESC" : "a.club_id DESC");
        $sql = "SELECT " . implode(', ', $select) . "
                FROM achievements a
                JOIN clubs c ON c.id = a.club_id
                WHERE a.club_id IN (" . in_clause($clubIds) . ")
                $approval
                ORDER BY $order
                LIMIT 5";
        if ($st = $conn->prepare($sql)) {
            $types = str_repeat('i', count($clubIds));
            $st->bind_param($types, ...$clubIds);
            $st->execute();
            $achievements = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$projects = [];
if ($clubIds && table_exists($conn, 'projects') && table_exists($conn, 'clubs')) {
    $cols = table_columns($conn, 'projects');
    $clubCols = table_columns($conn, 'clubs');
    if (has_col($cols, 'club_id') && has_col($clubCols, 'id')) {
        $approval = has_col($cols, 'approval_status') ? " AND COALESCE(p.approval_status, 'approved') IN ('approved', 'pending')" : "";
        $select = [
            has_col($cols, 'id') ? "p.id AS id" : "0 AS id",
            has_col($cols, 'project_name') ? "p.project_name AS project_name" : "'Untitled activity' AS project_name",
            has_col($cols, 'status') ? "p.status AS status" : "'unknown' AS status",
            has_col($cols, 'start_date') ? "p.start_date AS start_date" : "NULL AS start_date",
            has_col($cols, 'end_date') ? "p.end_date AS end_date" : "NULL AS end_date",
            has_col($clubCols, 'group_name') ? "c.group_name AS group_name" : "CONCAT('Club #', c.id) AS group_name",
        ];
        $order = has_col($cols, 'start_date') && has_col($cols, 'created_at') ? "COALESCE(p.start_date, p.created_at) DESC" : (has_col($cols, 'id') ? "p.id DESC" : "p.club_id DESC");
        $sql = "SELECT " . implode(', ', $select) . "
                FROM projects p
                JOIN clubs c ON c.id = p.club_id
                WHERE p.club_id IN (" . in_clause($clubIds) . ")
                $approval
                ORDER BY $order
                LIMIT 6";
        if ($st = $conn->prepare($sql)) {
            $types = str_repeat('i', count($clubIds));
            $st->bind_param($types, ...$clubIds);
            $st->execute();
            $projects = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$statusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
$focusCounts = [];
$clusterCounts = [];
$roleCounts = ['key_person' => 0, 'deputy' => 0, 'regular' => 0];
$semesterCounts = [];

foreach ($clubs as $club) {
    $status = strtolower(safe_label($club['status'] ?? '', 'unknown'));
    if (isset($statusCounts[$status])) $statusCounts[$status]++;
    $focus = safe_label($club['focus_area'] ?? '', 'Unspecified');
    $cluster = safe_label($club['cluster'] ?? '', 'Unknown cluster');
    $role = strtolower(safe_label($club['member_type'] ?? '', 'regular'));
    $semester = safe_label($club['current_semester'] ?? '', '');
    $focusCounts[$focus] = ($focusCounts[$focus] ?? 0) + 1;
    $clusterCounts[$cluster] = ($clusterCounts[$cluster] ?? 0) + 1;
    $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
    if ($semester !== '') $semesterCounts[$semester] = ($semesterCounts[$semester] ?? 0) + 1;
}
arsort($focusCounts);
arsort($clusterCounts);
arsort($semesterCounts);

$normalized = normalize_message($message);
$intent = detect_intent($normalized);
$suggestions = ['Show my clubs', 'Check my profile', 'Any upcoming events?', 'How to register a club?'];
$reply = '';

switch ($intent) {
    case 'greeting':
        $reply = "Hi $firstName! I am your local 3ZERO dashboard assistant.\n\nI can help you check:\n• Your clubs and approval status\n• Profile completion and missing fields\n• Upcoming events and notifications\n• Focus areas, clusters, roles, projects, and achievements\n• How to register a new club\n\nWhat would you like to explore first?";
        break;

    case 'clubs':
        if (!$clubs) {
            $reply = "Here’s what I found: you are not listed as a member of any club yet.\n\nNext step:\n• Register a club: club_registration.php\n• Make sure your student email matches the email used in club member records.";
        } else {
            $lines = ["Here are your clubs:"];
            foreach ($clubs as $club) {
                $lines[] = "\n• " . safe_label($club['group_name'], 'Unnamed club')
                    . "\n  Status: " . ucfirst(safe_label($club['status']))
                    . "\n  Cluster: " . safe_label($club['cluster'])
                    . "\n  Focus area: " . safe_label($club['focus_area'], 'Unspecified')
                    . "\n  Role: " . ucwords(str_replace('_', ' ', safe_label($club['member_type'], 'regular')))
                    . "\n  Registered: " . fmt_date($club['date_of_registration'] ?? '')
                    . "\n  Details: club_details.php?id=" . (int)$club['id'];
            }
            $reply = implode("\n", $lines);
        }
        $suggestions = ['What is my club status?', 'My focus areas', 'Am I a key person?'];
        break;

    case 'club_status':
        $total = count($clubs);
        $reply = "Here’s your club status summary:\n\n✅ Approved: {$statusCounts['approved']}\n⏳ Pending: {$statusCounts['pending']}\n❌ Rejected: {$statusCounts['rejected']}\n📌 Total clubs: $total\n\n";
        if ($total === 0) {
            $reply .= "You do not have any club memberships yet. You can start with club_registration.php.";
        } elseif ($statusCounts['pending'] > 0) {
            $reply .= "You still have pending club registration(s). Keep an eye on notifications for approval updates.";
        } elseif ($statusCounts['approved'] === $total) {
            $reply .= "All your listed clubs are approved. Good to continue with events, activities, and achievements.";
        } else {
            $reply .= "Some clubs may need attention. Review your club cards on myclubs.php.";
        }
        $suggestions = ['Show my clubs', 'Any notifications?', 'Upcoming events'];
        break;

    case 'profile':
        $profile = profile_completion($user);
        $reply = "Based on your current profile data:\n\n📊 Profile completion: {$profile['percent']}%\n✅ Completed fields: " . count($profile['complete']) . "\n⚠️ Missing fields: " . count($profile['missing']) . "\n";
        if ($profile['missing']) {
            $reply .= "\nMissing items:\n• " . implode("\n• ", $profile['missing']);
            $reply .= "\n\nNext step: update your profile here: profile.php";
        } else {
            $reply .= "\nYour profile looks complete.";
        }
        $suggestions = ['What semester am I in?', 'Show my clubs', 'Help'];
        break;

    case 'registration':
        $reply = "Here is how to register a new 3ZERO club:\n\n1. Open club_registration.php\n2. Fill in the club name, cluster, focus area, advisor, and registration date.\n3. Add exactly 5 members:\n   • Key Person\n   • Deputy Key Person\n   • 3 Regular Members\n4. Every member must already have a registered user account using their student email.\n5. Submit the form and wait for admin approval.\n\nNext step: go to club_registration.php and prepare your members’ student emails before submitting.";
        $suggestions = ['What is 3ZERO?', 'Check my profile', 'Show my clubs'];
        break;

    case 'notifications':
        if (!$notifications) {
            $reply = "I did not find recent notifications for you yet.\n\nYou can still check the full notification page here: notifications.php";
        } else {
            $lines = ["Here are your latest notifications:"];
            foreach ($notifications as $n) {
                $prefix = ((int)$n['is_read'] === 0) ? '🔔 New' : '•';
                $summary = safe_label($n['msg_text'] ?? '', '');
                if (strlen($summary) > 120) $summary = substr($summary, 0, 117) . '...';
                $lines[] = "\n$prefix " . safe_label($n['title'], 'Notification') . "\n  Date: " . fmt_date($n['created_at']) . ($summary ? "\n  Summary: $summary" : '');
            }
            $lines[] = "\nOpen all notifications: notifications.php";
            $reply = implode("\n", $lines);
        }
        $suggestions = ['Any upcoming events?', 'Club status', 'Help'];
        break;

    case 'events':
        if (!$events) {
            $reply = "I do not see upcoming approved events for your clubs right now.\n\nNext step: check events.php later or create an event from your user Events page if you manage a club.";
        } else {
            $lines = ["Here are upcoming events for your clubs:"];
            foreach ($events as $event) {
                $lines[] = "\n• " . safe_label($event['title'], 'Untitled event')
                    . "\n  Club: " . safe_label($event['group_name'])
                    . "\n  When: " . fmt_datetime($event['start_date'] ?? '', $event['start_time'] ?? '')
                    . "\n  Events page: events.php";
            }
            $reply = implode("\n", $lines);
        }
        $suggestions = ['Show notifications', 'My clubs', 'My projects'];
        break;

    case 'semester':
        $semester = $semesterCounts ? array_key_first($semesterCounts) : '';
        $reply = "Here is your semester information:\n\n• Current semester from club memberships: " . ($semester ?: 'Not available yet') . "\n• Intake: " . safe_label($user['intake'] ?? '', 'Not set') . "\n• Expected graduation year: " . safe_label($user['expected_graduation_year'] ?? '', 'Not set');
        if (!$semester) {
            $reply .= "\n\nIf the semester is missing, it may not have been filled in your club member record yet.";
        }
        $suggestions = ['Check my profile', 'Show my clubs', 'My role'];
        break;

    case 'focus':
        if (!$focusCounts) {
            $reply = "I could not find focus area data yet because you do not have club records with focus areas.";
        } else {
            $top = array_key_first($focusCounts);
            $lines = ["Your focus area distribution:", "\nMost active focus area: $top"];
            foreach ($focusCounts as $focus => $count) $lines[] = "• $focus: $count club(s)";
            $reply = implode("\n", $lines) . "\n\nInsight: this can help you describe your strongest participation theme in reports or presentations.";
        }
        $suggestions = ['Which cluster am I in?', 'Show my clubs', 'Leadership roles'];
        break;

    case 'cluster':
        if (!$clusterCounts) {
            $reply = "I could not find cluster data yet.";
        } else {
            $top = array_key_first($clusterCounts);
            $lines = ["Your 3ZERO cluster distribution:", "\nMost active cluster: $top"];
            foreach ($clusterCounts as $cluster => $count) {
                $clubNames = [];
                foreach ($clubs as $club) {
                    if (safe_label($club['cluster']) === $cluster) $clubNames[] = safe_label($club['group_name'], 'Unnamed club');
                }
                $lines[] = "• $cluster: $count club(s)" . ($clubNames ? " — " . implode(', ', $clubNames) : '');
            }
            $reply = implode("\n", $lines);
        }
        $suggestions = ['My focus areas', 'What is 3ZERO?', 'Show my clubs'];
        break;

    case 'leadership':
        $leadership = [];
        foreach ($clubs as $club) {
            $role = strtolower(safe_label($club['member_type'], 'regular'));
            if (in_array($role, ['key_person', 'deputy'], true)) {
                $leadership[] = safe_label($club['group_name']) . ' (' . ucwords(str_replace('_', ' ', $role)) . ')';
            }
        }
        $reply = "Here is your role breakdown:\n\n👑 Key person: " . ($roleCounts['key_person'] ?? 0) . "\n🤝 Deputy: " . ($roleCounts['deputy'] ?? 0) . "\n👤 Regular member: " . ($roleCounts['regular'] ?? 0);
        $reply .= $leadership ? "\n\nLeadership clubs:\n• " . implode("\n• ", $leadership) : "\n\nYou do not currently appear as key person or deputy in your club records.";
        $suggestions = ['Show my clubs', 'My achievements', 'My projects'];
        break;

    case 'achievements':
        if (!$achievements) {
            $reply = "I do not see approved achievements for your clubs yet.\n\nNext step: if your club has accomplishments, submit them from achievements.php for admin approval.";
        } else {
            $lines = ["Latest achievements connected to your clubs:"];
            foreach ($achievements as $a) {
                $lines[] = "\n• " . safe_label($a['title'], 'Untitled achievement')
                    . "\n  Club: " . safe_label($a['group_name'])
                    . "\n  Date: " . fmt_date($a['achieved_on'] ?: $a['created_at']);
            }
            $lines[] = "\nOpen achievements: achievements.php";
            $reply = implode("\n", $lines);
        }
        $suggestions = ['My projects', 'Upcoming events', 'Show my clubs'];
        break;

    case 'projects':
        $ongoing = 0; $completed = 0;
        foreach ($projects as $p) {
            $status = strtolower(safe_label($p['status'], ''));
            if ($status === 'completed') $completed++;
            if (in_array($status, ['ongoing', 'active', 'in_progress', 'in progress'], true)) $ongoing++;
        }
        if (!$projects) {
            $reply = "I do not see activities or projects for your clubs yet.\n\nNext step: open projects.php to add or manage activities.";
        } else {
            $lines = ["Here is your project/activity summary:", "\n🚀 Ongoing: $ongoing\n✅ Completed: $completed\n📌 Showing latest: " . count($projects)];
            foreach ($projects as $p) {
                $lines[] = "\n• " . safe_label($p['project_name'], 'Untitled activity')
                    . "\n  Club: " . safe_label($p['group_name'])
                    . "\n  Status: " . ucfirst(safe_label($p['status'], 'Unknown'));
            }
            $lines[] = "\nManage activities: projects.php";
            $reply = implode("\n", $lines);
        }
        $suggestions = ['My achievements', 'Upcoming events', 'Club status'];
        break;

    case 'help':
        $reply = "Here are things I can answer using your dashboard data:\n\n• Show my clubs\n• What is my club status?\n• Is my profile complete?\n• How do I register a club?\n• Any notifications?\n• Any upcoming events?\n• What semester am I in?\n• My focus areas\n• Which cluster am I in?\n• Am I a key person or deputy?\n• Show achievements\n• My projects\n• What is 3ZERO?";
        break;

    case 'three_zero':
        $reply = "3ZERO is a movement inspired by Professor Muhammad Yunus’s vision of a world with three zeros:\n\n🌍 Zero Net Carbon Emissions\nReducing environmental harm and building climate-friendly habits.\n\n🤝 Zero Poverty\nCreating inclusive opportunities and community support systems.\n\n💼 Zero Unemployment\nEncouraging entrepreneurship, skills, and meaningful work.\n\nA 3ZERO Club turns these goals into student-led activities, events, projects, and measurable impact.";
        $suggestions = ['How to register a club?', 'Which cluster am I in?', 'My focus areas'];
        break;

    default:
        $reply = "I could not fully understand that yet, but I can still help.\n\nTry asking me something like:\n• What clubs am I in?\n• Is my profile complete?\n• Any upcoming events?\n• How do I register a club?\n• What is 3ZERO?";
        $suggestions = ['Show my clubs', 'Check my profile', 'Any notifications?', 'Help'];
        break;
}

send_json(['reply' => $reply, 'suggestions' => $suggestions]);
