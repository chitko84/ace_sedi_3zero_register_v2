<?php
// admin/dashboard.php
require_once __DIR__ . '/header.php'; // brings $conn, $user (admin), CSS, layout

// ======================================================
// Safe helpers
// ======================================================
function table_exists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return ($res && $res->num_rows > 0);
}

function columns_for($conn, $table) {
    $cols = array();
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function has_col($cols, $col) {
    return in_array($col, $cols, true);
}

function quick_count($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return isset($row['c']) ? (int)$row['c'] : 0;
}

function safe_text($value) {
    if ($value === null || trim((string)$value) === '') {
        return 'Unknown';
    }
    return trim((string)$value);
}

function display_text($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normal_case_label($value) {
    $value = safe_text($value);
    if ($value === 'Unknown') {
        return $value;
    }

    $upper = strtoupper($value);
    $known = array(
        'AIU' => 'AIU',
        'SCI' => 'SCI',
        'SBSS' => 'SBSS',
        'SEHS' => 'SEHS'
    );

    if (isset($known[$upper])) {
        return $known[$upper];
    }

    return ucwords(strtolower($value));
}

function normalize_gender($gender) {
    $g = strtolower(trim((string)$gender));

    if ($g === 'male' || $g === 'm') {
        return 'Male';
    }

    if ($g === 'female' || $g === 'f') {
        return 'Female';
    }

    if ($g === '' || $g === 'unknown' || $g === '-' || $g === 'null') {
        return 'Unknown';
    }

    return ucwords($g);
}

function normalize_cluster($cluster) {
    $original = trim((string)$cluster);
    $c = strtolower($original);

    $c = str_replace(array('.', ',', '-', '_', '/', '\\', '(', ')', '&'), ' ', $c);
    $c = preg_replace('/\s+/', ' ', $c);
    $c = trim($c);

    if ($c === '' || $c === 'unknown' || $c === 'null' || $c === '-' || $c === 'na' || $c === 'n a' || $c === 'not specified') {
        return 'Unknown';
    }

    if (strpos($c, 'poverty') !== false) {
        return 'Zero Poverty';
    }

    if (strpos($c, 'unemployment') !== false || strpos($c, 'unemploy') !== false) {
        return 'Zero Unemployment';
    }

    if (strpos($c, 'carbon') !== false || strpos($c, 'net zero') !== false || strpos($c, 'netcarbon') !== false || strpos($c, 'emission') !== false) {
        return 'Zero Net Carbon Emission';
    }

    return 'Unknown';
}

// Full programme normalization, not abbreviations
function normalize_programme($programme) {
    $original = trim((string)$programme);
    $p = strtolower($original);

    $p = str_replace(array('.', ',', '-', '_', '/', '\\', '(', ')'), ' ', $p);
    $p = preg_replace('/\s+/', ' ', $p);
    $p = trim($p);

    if ($p === '' || $p === 'unknown' || $p === 'null' || $p === 'na' || $p === 'n a') {
        return 'Unknown';
    }

    if (strpos($p, 'master of computing') !== false) {
        return 'Master of Computing (by Research)';
    }

    if (strpos($p, 'doctor of philosophy in computer science') !== false ||
        (strpos($p, 'phd') !== false && strpos($p, 'computer science') !== false)) {
        return 'Doctor of Philosophy in Computer Science';
    }

    if ($p === 'bba' ||
        strpos($p, 'business administration') !== false ||
        strpos($p, 'bachelor in business admin') !== false ||
        strpos($p, 'bachelor of business admin') !== false) {
        return 'Bachelor of Business Administration (Honours)';
    }

    if ($p === 'bcs' ||
        strpos($p, 'computer science') !== false ||
        strpos($p, 'bachelor in computer science') !== false ||
        strpos($p, 'bachelor of computer science') !== false) {
        return 'Bachelor in Computer Science (Honours)';
    }

    if (strpos($p, 'islamic finance') !== false ||
        $p === 'finance' ||
        strpos($p, 'bachelor of finance') !== false ||
        strpos($p, 'bachelor in finance') !== false) {
        return 'Bachelor of Finance (Islamic Finance) (Honours)';
    }

    if (strpos($p, 'media') !== false && strpos($p, 'communication') !== false) {
        return 'Bachelor of Media and Communication (Honours)';
    }

    if (strpos($p, 'economics') !== false || strpos($p, 'economic') !== false) {
        return 'Bachelor of Economics (Honours)';
    }

    if (strpos($p, 'politics') !== false || strpos($p, 'international relations') !== false) {
        return 'Bachelor of Politics and International Relations (Honours)';
    }

    if (strpos($p, 'elementary education') !== false) {
        return 'Bachelor of Elementary Education (Honours)';
    }

    if (strpos($p, 'early childhood') !== false) {
        return 'Bachelor in Early Childhood Education (Honours)';
    }

    if (strpos($p, 'foundation') !== false && strpos($p, 'arts') !== false) {
        return 'Foundation in Arts';
    }

    return ucwords(strtolower($original));
}

function add_count(&$arr, $key) {
    $key = safe_text($key);
    if (!isset($arr[$key])) {
        $arr[$key] = 0;
    }
    $arr[$key]++;
}

function first_key_or_dash($arr) {
    if (empty($arr)) {
        return '—';
    }
    $keys = array_keys($arr);
    return isset($keys[0]) ? $keys[0] : '—';
}

function sum_values($arr) {
    return array_sum($arr);
}

function norm_membership_identity($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/\s+/', ' ', $value);
}

function get_unique_membership_people($conn) {
    // Source of truth: club_members.
    // Same email OR same full_name is treated as the same membership person.
    // Then we attach user profile details when a matching registered user exists.
    if (!table_exists($conn, 'club_members')) return array();

    $res = $conn->query("SELECT club_id, email, full_name FROM club_members WHERE (email IS NOT NULL AND TRIM(email) <> '') OR (full_name IS NOT NULL AND TRIM(full_name) <> '')");
    if (!$res) return array();

    $parent = array();
    $rank = array();
    $tokenToId = array();
    $idToToken = array();
    $rowTokenIds = array();
    $nextId = 0;

    $find = function($x) use (&$parent, &$find) {
        if ($parent[$x] !== $x) $parent[$x] = $find($parent[$x]);
        return $parent[$x];
    };

    $union = function($a, $b) use (&$parent, &$rank, $find) {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra === $rb) return;
        if ($rank[$ra] < $rank[$rb]) {
            $parent[$ra] = $rb;
        } elseif ($rank[$ra] > $rank[$rb]) {
            $parent[$rb] = $ra;
        } else {
            $parent[$rb] = $ra;
            $rank[$ra]++;
        }
    };

    while ($row = $res->fetch_assoc()) {
        $tokens = array();
        $email = norm_membership_identity(isset($row['email']) ? $row['email'] : '');
        $name = norm_membership_identity(isset($row['full_name']) ? $row['full_name'] : '');

        if ($email !== '') $tokens[] = 'email:' . $email;
        if ($name !== '') $tokens[] = 'name:' . $name;
        if (empty($tokens)) continue;

        $ids = array();
        foreach ($tokens as $token) {
            if (!isset($tokenToId[$token])) {
                $tokenToId[$token] = $nextId;
                $idToToken[$nextId] = $token;
                $parent[$nextId] = $nextId;
                $rank[$nextId] = 0;
                $nextId++;
            }
            $ids[] = $tokenToId[$token];
        }

        for ($i = 1; $i < count($ids); $i++) {
            $union($ids[0], $ids[$i]);
        }

        $rowTokenIds[] = $ids;
    }

    $components = array();
    foreach ($rowTokenIds as $ids) {
        if (empty($ids)) continue;
        $root = $find($ids[0]);
        if (!isset($components[$root])) {
            $components[$root] = array(
                'emails' => array(),
                'names' => array()
            );
        }

        foreach ($ids as $id) {
            $token = isset($idToToken[$id]) ? $idToToken[$id] : '';
            if (strpos($token, 'email:') === 0) {
                $components[$root]['emails'][substr($token, 6)] = true;
            } elseif (strpos($token, 'name:') === 0) {
                $components[$root]['names'][substr($token, 5)] = true;
            }
        }
    }

    // Build lookup maps from users so we can enrich membership people with profile data.
    $usersByEmail = array();
    $usersByName = array();
    if (table_exists($conn, 'users')) {
        $resUsers = $conn->query("SELECT id, name, email, program_of_study, department, country, gender FROM users ORDER BY id ASC");
        if ($resUsers) {
            while ($u = $resUsers->fetch_assoc()) {
                $emailKey = norm_membership_identity(isset($u['email']) ? $u['email'] : '');
                $nameKey = norm_membership_identity(isset($u['name']) ? $u['name'] : '');
                if ($emailKey !== '' && !isset($usersByEmail[$emailKey])) {
                    $usersByEmail[$emailKey] = $u;
                }
                if ($nameKey !== '' && !isset($usersByName[$nameKey])) {
                    $usersByName[$nameKey] = $u;
                }
            }
        }
    }

    $people = array();
    foreach ($components as $component) {
        $matchedUser = null;

        foreach (array_keys($component['emails']) as $emailKey) {
            if (isset($usersByEmail[$emailKey])) {
                $matchedUser = $usersByEmail[$emailKey];
                break;
            }
        }

        if ($matchedUser === null) {
            foreach (array_keys($component['names']) as $nameKey) {
                if (isset($usersByName[$nameKey])) {
                    $matchedUser = $usersByName[$nameKey];
                    break;
                }
            }
        }

        if ($matchedUser !== null) {
            $people[] = $matchedUser;
        } else {
            // This is the important part for your 388 total:
            // the person exists in club_members, but no matching users profile exists,
            // so analytics should count them as Unknown instead of disappearing.
            $people[] = array(
                'id' => null,
                'name' => 'Unknown',
                'email' => 'Unknown',
                'program_of_study' => 'Unknown',
                'department' => 'Unknown',
                'country' => 'Unknown',
                'gender' => 'Unknown'
            );
        }
    }

    return $people;
}

function count_unique_membership_people($conn) {
    return count(get_unique_membership_people($conn));
}

function render_count_table($title, $icon, $iconClass, $labelHeader, $data, $expectedTotal) {
    $actualTotal = sum_values($data);
    ?>
    <div class="card shadow-sm border-0 h-100">
        <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
            <span><i class="<?php echo display_text($icon . ' me-2 ' . $iconClass); ?>"></i><?php echo display_text($title); ?></span>
            <span class="badge bg-light text-dark border">Total: <?php echo number_format($actualTotal); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive dashboard-table-wrap">
                <table class="table table-sm table-hover align-middle mb-0 dashboard-table">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo display_text($labelHeader); ?></th>
                            <th class="text-end">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($data)): ?>
                            <?php foreach ($data as $label => $count): ?>
                                <tr>
                                    <td><?php echo display_text($label); ?></td>
                                    <td class="text-end fw-semibold"><?php echo number_format($count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-bold">
                                <td>TOTAL</td>
                                <td class="text-end"><?php echo number_format($actualTotal); ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted py-3">No data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}
// ======================================================
// Main counters
// ======================================================
$total_registered_users = 0;
$total_with_membership = 0;
$total_without_membership = 0;

$clubs_total = 0;
$clubs_pending = 0;
$events_total = 0;
$active_projects = 0;

$cluster_counts = array(
    'total_clubs' => 0,
    'zero_poverty' => 0,
    'zero_unemployment' => 0,
    'zero_net_carbon' => 0,
    'unknown' => 0
);

$cluster_breakdown = array(
    'Zero Poverty' => 0,
    'Zero Unemployment' => 0,
    'Zero Net Carbon Emission' => 0,
    'Unknown' => 0
);

$users_with_membership = array();
$users_without_membership = array();

$with_data = array(
    'male' => 0,
    'female' => 0,
    'programmes' => array(),
    'schools' => array(),
    'countries' => array(),
    'genders' => array()
);

$without_data = array(
    'male' => 0,
    'female' => 0,
    'programmes' => array(),
    'schools' => array(),
    'countries' => array(),
    'genders' => array()
);

$membership_country_count = 0;
$top_programme = '—';
$top_school = '—';
$top_gender = '—';
$top_cluster = '—';

// ======================================================
// Users membership split
// Source of truth for membership analytics: club_members
// Membership rule:
// same normalized email OR same normalized full_name/name = one membership person.
// If a membership person cannot be matched to users, keep them in analytics as Unknown.
// This makes Programme / School / Country / Gender totals reconcile with 388.
// ======================================================
if (table_exists($conn, 'users')) {
    $total_registered_users = quick_count($conn, "SELECT COUNT(*) AS c FROM users");
}

if (table_exists($conn, 'club_members')) {
    $users_with_membership = get_unique_membership_people($conn);
    $total_with_membership = count($users_with_membership);
} else {
    $users_with_membership = array();
    $total_with_membership = 0;
}

// Users without membership still comes from registered users only.
if (table_exists($conn, 'users')) {
    if (table_exists($conn, 'club_members')) {
        $sqlWithoutUsers = "
            SELECT
                u.id,
                u.name,
                u.email,
                u.program_of_study,
                u.department,
                u.country,
                u.gender
            FROM users u
            WHERE NOT EXISTS (
                SELECT 1
                FROM club_members cm
                WHERE
                    (
                        u.email IS NOT NULL
                        AND cm.email IS NOT NULL
                        AND TRIM(u.email) <> ''
                        AND TRIM(cm.email) <> ''
                        AND LOWER(TRIM(u.email)) = LOWER(TRIM(cm.email))
                    )
                    OR
                    (
                        u.name IS NOT NULL
                        AND cm.full_name IS NOT NULL
                        AND TRIM(u.name) <> ''
                        AND TRIM(cm.full_name) <> ''
                        AND LOWER(TRIM(u.name)) = LOWER(TRIM(cm.full_name))
                    )
            )
            ORDER BY u.id ASC
        ";
    } else {
        $sqlWithoutUsers = "
            SELECT id, name, email, program_of_study, department, country, gender
            FROM users
            ORDER BY id ASC
        ";
    }

    $resWithoutUsers = $conn->query($sqlWithoutUsers);
    if ($resWithoutUsers) {
        while ($row = $resWithoutUsers->fetch_assoc()) {
            $users_without_membership[] = $row;
        }
    }
}

$matched_users_with_membership = 0;
foreach ($users_with_membership as $u) {
    if (isset($u['id']) && $u['id'] !== null) {
        $matched_users_with_membership++;
    }
}
$total_without_membership = count($users_without_membership);

// Build section analytics from membership people, not only users table.
// That means unmatched club_members people become Unknown and totals equal 388.
foreach ($users_with_membership as $u) {
    $gender = normalize_gender(isset($u['gender']) ? $u['gender'] : '');
    if ($gender === 'Male') {
        $with_data['male']++;
    }
    if ($gender === 'Female') {
        $with_data['female']++;
    }

    add_count($with_data['genders'], $gender);
    add_count($with_data['programmes'], normalize_programme(isset($u['program_of_study']) ? $u['program_of_study'] : ''));
    add_count($with_data['schools'], normal_case_label(isset($u['department']) ? $u['department'] : ''));
    add_count($with_data['countries'], normal_case_label(isset($u['country']) ? $u['country'] : ''));
}

foreach ($users_without_membership as $u) {
    $gender = normalize_gender(isset($u['gender']) ? $u['gender'] : '');
    if ($gender === 'Male') {
        $without_data['male']++;
    }
    if ($gender === 'Female') {
        $without_data['female']++;
    }

    add_count($without_data['genders'], $gender);
    add_count($without_data['programmes'], normalize_programme(isset($u['program_of_study']) ? $u['program_of_study'] : ''));
    add_count($without_data['schools'], normal_case_label(isset($u['department']) ? $u['department'] : ''));
    add_count($without_data['countries'], normal_case_label(isset($u['country']) ? $u['country'] : ''));
}

arsort($with_data['genders']);
arsort($with_data['programmes']);
arsort($with_data['schools']);
arsort($with_data['countries']);

arsort($without_data['genders']);
arsort($without_data['programmes']);
arsort($without_data['schools']);
arsort($without_data['countries']);

$membership_country_count = count($with_data['countries']);
$top_programme = first_key_or_dash($with_data['programmes']);
$top_school = first_key_or_dash($with_data['schools']);
$top_gender = first_key_or_dash($with_data['genders']);

// ======================================================
// Clubs
// ======================================================
if (table_exists($conn, 'clubs')) {
    $clubs_total = quick_count($conn, "SELECT COUNT(*) AS c FROM clubs");
    $cluster_counts['total_clubs'] = $clubs_total;

    $clubCols = columns_for($conn, 'clubs');

    if (has_col($clubCols, 'status')) {
        $clubs_pending = quick_count($conn, "SELECT COUNT(*) AS c FROM clubs WHERE LOWER(TRIM(status)) = 'pending'");
    }

    if (has_col($clubCols, 'cluster')) {
        $sqlClusterCounts = "
            SELECT
                CASE
                    WHEN cluster IS NULL OR TRIM(cluster) = '' THEN 'Unknown'
                    WHEN LOWER(TRIM(cluster)) LIKE '%poverty%' THEN 'Zero Poverty'
                    WHEN LOWER(TRIM(cluster)) LIKE '%unemployment%' THEN 'Zero Unemployment'
                    WHEN LOWER(TRIM(cluster)) LIKE '%carbon%' THEN 'Zero Net Carbon Emission'
                    ELSE 'Unknown'
                END AS normalized_cluster,
                COUNT(*) AS count
            FROM clubs
            GROUP BY normalized_cluster
            ORDER BY count DESC
        ";

        $resClusterCounts = $conn->query($sqlClusterCounts);
        if ($resClusterCounts) {
            while ($row = $resClusterCounts->fetch_assoc()) {
                $label = isset($row['normalized_cluster']) ? $row['normalized_cluster'] : 'Unknown';
                $count = isset($row['count']) ? (int)$row['count'] : 0;

                if ($label === 'Zero Poverty') {
                    $cluster_counts['zero_poverty'] = $count;
                    $cluster_breakdown['Zero Poverty'] = $count;
                } elseif ($label === 'Zero Unemployment') {
                    $cluster_counts['zero_unemployment'] = $count;
                    $cluster_breakdown['Zero Unemployment'] = $count;
                } elseif ($label === 'Zero Net Carbon Emission') {
                    $cluster_counts['zero_net_carbon'] = $count;
                    $cluster_breakdown['Zero Net Carbon Emission'] = $count;
                } else {
                    $cluster_counts['unknown'] += $count;
                    $cluster_breakdown['Unknown'] += $count;
                }
            }
        }
    } else {
        $cluster_counts['unknown'] = $clubs_total;
        $cluster_breakdown['Unknown'] = $clubs_total;
    }
}
// Top cluster among registered users with memberships
if (table_exists($conn, 'users') && table_exists($conn, 'club_members') && table_exists($conn, 'clubs')) {
    $sqlTopCluster = "
        SELECT
            CASE
                WHEN c.cluster IS NULL OR TRIM(c.cluster) = '' THEN 'Unknown'
                WHEN LOWER(TRIM(c.cluster)) LIKE '%poverty%' THEN 'Zero Poverty'
                WHEN LOWER(TRIM(c.cluster)) LIKE '%unemployment%' THEN 'Zero Unemployment'
                WHEN LOWER(TRIM(c.cluster)) LIKE '%carbon%' THEN 'Zero Net Carbon Emission'
                ELSE 'Unknown'
            END AS cluster_name,
            COUNT(DISTINCT u.id) AS c
        FROM users u
        INNER JOIN club_members cm
            ON (
                u.email IS NOT NULL
                AND cm.email IS NOT NULL
                AND TRIM(u.email) <> ''
                AND TRIM(cm.email) <> ''
                AND LOWER(TRIM(u.email)) = LOWER(TRIM(cm.email))
            )
            OR
            (
                u.name IS NOT NULL
                AND cm.full_name IS NOT NULL
                AND TRIM(u.name) <> ''
                AND TRIM(cm.full_name) <> ''
                AND LOWER(TRIM(u.name)) = LOWER(TRIM(cm.full_name))
            )
        INNER JOIN clubs c ON c.id = cm.club_id
        GROUP BY cluster_name
        ORDER BY c DESC, cluster_name ASC
        LIMIT 1
    ";
    $resTopCluster = $conn->query($sqlTopCluster);
    if ($resTopCluster && $resTopCluster->num_rows > 0) {
        $rowTopCluster = $resTopCluster->fetch_assoc();
        $top_cluster = isset($rowTopCluster['cluster_name']) ? $rowTopCluster['cluster_name'] : '—';
    }
}

// ======================================================
// Events, projects, recent users, upcoming events, notifications
// ======================================================
$recentUsers = array();
$upcomingEvents = array();
$recentNotifs = array();

if (table_exists($conn, 'events')) {
    $events_total = quick_count($conn, "SELECT COUNT(*) AS c FROM events");

    $eCols = columns_for($conn, 'events');
    $dateCol = null;
    $titleCol = null;
    $locCol = null;

    $dateCandidates = array('event_date', 'start_date', 'start_time', 'date', 'created_at');
    $titleCandidates = array('title', 'event_title', 'name');
    $locCandidates = array('location', 'venue', 'place');

    foreach ($dateCandidates as $c) {
        if (has_col($eCols, $c)) {
            $dateCol = $c;
            break;
        }
    }

    foreach ($titleCandidates as $c) {
        if (has_col($eCols, $c)) {
            $titleCol = $c;
            break;
        }
    }

    foreach ($locCandidates as $c) {
        if (has_col($eCols, $c)) {
            $locCol = $c;
            break;
        }
    }

    if ($dateCol !== null) {
        $titleSelect = ($titleCol !== null) ? "`{$titleCol}` AS title" : "'' AS title";
        $locSelect = ($locCol !== null) ? "`{$locCol}` AS location" : "'' AS location";
        $sqlUpcoming = "
            SELECT {$titleSelect}, {$locSelect}, `{$dateCol}` AS dt
            FROM events
            WHERE `{$dateCol}` >= CURDATE()
            ORDER BY `{$dateCol}` ASC
            LIMIT 5
        ";
        $resUpcoming = $conn->query($sqlUpcoming);
        if ($resUpcoming) {
            while ($row = $resUpcoming->fetch_assoc()) {
                $upcomingEvents[] = $row;
            }
        }
    }
}

if (table_exists($conn, 'projects')) {
    $pCols = columns_for($conn, 'projects');
    if (has_col($pCols, 'status')) {
        $active_projects = quick_count($conn, "SELECT COUNT(*) AS c FROM projects WHERE LOWER(TRIM(status)) IN ('active','planning','in_progress','in progress','ongoing')");
    } else {
        $active_projects = quick_count($conn, "SELECT COUNT(*) AS c FROM projects");
    }
}

if (table_exists($conn, 'users')) {
    $sqlRecentUsers = "
        SELECT id, name, email, created_at
        FROM users
        ORDER BY created_at DESC, id DESC
        LIMIT 6
    ";
    $resRecentUsers = $conn->query($sqlRecentUsers);
    if ($resRecentUsers) {
        while ($row = $resRecentUsers->fetch_assoc()) {
            $recentUsers[] = $row;
        }
    }
}

if (table_exists($conn, 'notifications') && isset($user['id'])) {
    $nCols = columns_for($conn, 'notifications');

    $titleCol = has_col($nCols, 'title') ? 'title' : null;
    $msgCol = has_col($nCols, 'message') ? 'message' : (has_col($nCols, 'body') ? 'body' : null);
    $dateCol = has_col($nCols, 'created_at') ? 'created_at' : null;

    if (has_col($nCols, 'user_id')) {
        $titleSelect = ($titleCol !== null) ? "`{$titleCol}` AS title" : "'Notification' AS title";
        $msgSelect = ($msgCol !== null) ? "`{$msgCol}` AS msg" : "'' AS msg";
        $dateSelect = ($dateCol !== null) ? "`{$dateCol}` AS created_at" : "NOW() AS created_at";
        $orderCol = ($dateCol !== null) ? "`{$dateCol}`" : "id";

        $stmt = $conn->prepare("SELECT id, {$titleSelect}, {$msgSelect}, {$dateSelect} FROM notifications WHERE user_id = ? ORDER BY {$orderCol} DESC LIMIT 6");
        if ($stmt) {
            $adminId = (int)$user['id'];
            $stmt->bind_param("i", $adminId);
            if ($stmt->execute()) {
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $recentNotifs[] = $row;
                }
            }
        }
    }
}

$with_prog_total = sum_values($with_data['programmes']);
$with_school_total = sum_values($with_data['schools']);
$with_country_total = sum_values($with_data['countries']);
$with_gender_total = sum_values($with_data['genders']);

$without_prog_total = sum_values($without_data['programmes']);
$without_school_total = sum_values($without_data['schools']);
$without_country_total = sum_values($without_data['countries']);
$without_gender_total = sum_values($without_data['genders']);

$membership_tables_ok = ($total_with_membership == $with_prog_total && $total_with_membership == $with_school_total && $total_with_membership == $with_country_total && $total_with_membership == $with_gender_total);
?>

<style>
    .dashboard-page {
        padding-bottom: 2rem;
    }

    .dashboard-title {
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .dashboard-section {
        margin-bottom: 2rem;
    }

    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 1rem;
        color: #1f2937;
    }

    .kpi-card {
        border: 1px solid rgba(15, 23, 42, 0.08) !important;
        border-radius: 1rem;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 0.65rem 1.4rem rgba(15, 23, 42, 0.08) !important;
    }

    .kpi-icon {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
    }

    .kpi-label {
        color: #6b7280;
        font-size: 0.82rem;
        font-weight: 600;
    }

    .kpi-value {
        font-size: 1.65rem;
        font-weight: 800;
        color: #111827;
        line-height: 1.15;
    }

    .mini-value {
        font-weight: 700;
        color: #111827;
        line-height: 1.25;
    }

    .soft-card {
        border-radius: 1rem;
        border: 1px solid rgba(15, 23, 42, 0.08) !important;
    }

    .dashboard-table-wrap {
        max-height: 420px;
        overflow-y: auto;
    }

    .dashboard-table th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: #6b7280;
        white-space: nowrap;
    }

    .dashboard-table td {
        font-size: 0.9rem;
        vertical-align: middle;
    }

    .reconcile-box {
        border-radius: 1rem;
        border: 1px solid #d1fae5;
        background: #f0fdf4;
    }

    .reconcile-box.danger {
        border-color: #fecaca;
        background: #fef2f2;
    }

    .quick-action-btn {
        border-radius: 0.75rem;
    }

    @media (max-width: 768px) {
        .kpi-value {
            font-size: 1.35rem;
        }

        .dashboard-table-wrap {
            max-height: 340px;
        }
    }
</style>

<main class="main-content container-fluid dashboard-page">
    <div class="row g-3">

        <!-- Page Title -->
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div>
                    <h2 class="dashboard-title mb-1">Admin Dashboard</h2>
                    <div class="text-muted small">Dashboard overview</div>
                </div>
                <div class="text-muted">Welcome, <?php echo display_text(isset($user['name']) ? $user['name'] : 'Admin'); ?></div>
            </div>
        </div>

        <!-- Top KPI Cards -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon text-primary"><i class="fa-solid fa-users"></i></div>
                    <div class="flex-grow-1">
                        <div class="kpi-label">Total Users</div>
                        <div class="kpi-value"><?php echo number_format($total_registered_users); ?></div>
                    </div>
                    <a href="manage_users.php" class="btn btn-sm btn-outline-primary">Manage</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon text-success"><i class="fa-solid fa-people-group"></i></div>
                    <div class="flex-grow-1">
                        <div class="kpi-label">Clubs (Total / Pending)</div>
                        <div class="kpi-value"><?php echo number_format($clubs_total); ?> / <span class="text-warning"><?php echo number_format($clubs_pending); ?></span></div>
                    </div>
                    <a href="manage_clubs.php" class="btn btn-sm btn-outline-success">Review</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon text-info"><i class="fa-solid fa-calendar-days"></i></div>
                    <div class="flex-grow-1">
                        <div class="kpi-label">Events</div>
                        <div class="kpi-value"><?php echo number_format($events_total); ?></div>
                    </div>
                    <a href="manage_events.php" class="btn btn-sm btn-outline-info">Open</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon text-secondary"><i class="fa-solid fa-diagram-project"></i></div>
                    <div class="flex-grow-1">
                        <div class="kpi-label">Active Projects</div>
                        <div class="kpi-value"><?php echo number_format($active_projects); ?></div>
                    </div>
                    <a href="manage_projects.php" class="btn btn-sm btn-outline-secondary">Track</a>
                </div>
            </div>
        </div>

        <!-- Club Membership Insights -->
        <div class="col-12">
            <div class="card soft-card shadow-sm">
                <div class="card-header bg-white d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-1">
                    <strong><i class="fa-solid fa-id-card-clip me-1 text-success"></i> Club Membership Insights</strong>
                    <span class="text-muted small">Membership summary</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="kpi-label">Users with Memberships</div>
                                <div class="kpi-value text-success"><?php echo number_format($total_with_membership); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="kpi-label">Participating Countries</div>
                                <div class="kpi-value text-primary"><?php echo number_format($membership_country_count); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="kpi-label">Top Programme</div>
                                <div class="mini-value"><?php echo display_text($top_programme); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="kpi-label">Top School/Centre</div>
                                <div class="mini-value"><?php echo display_text($top_school); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="kpi-label">Top Gender</div>
                                <div class="mini-value"><?php echo display_text($top_gender); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="kpi-label">Top Cluster</div>
                                <div class="mini-value"><?php echo display_text($top_cluster); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 1 -->
        <div class="col-12 dashboard-section">
            <h3 class="section-title"><i class="fa-solid fa-people-group me-2 text-success"></i>Club Cluster Counts</h3>
            <div class="row g-3">
                <div class="col-12 col-sm-6 col-xl">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Total Clubs</div>
                            <div class="kpi-value"><?php echo number_format($cluster_counts['total_clubs']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Clubs under Zero Poverty</div>
                            <div class="kpi-value text-info"><?php echo number_format($cluster_counts['zero_poverty']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Clubs under Zero Unemployment</div>
                            <div class="kpi-value text-warning"><?php echo number_format($cluster_counts['zero_unemployment']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Clubs under Zero Net Carbon Emission</div>
                            <div class="kpi-value text-success"><?php echo number_format($cluster_counts['zero_net_carbon']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Unknown</div>
                            <div class="kpi-value text-secondary"><?php echo number_format($cluster_counts['unknown']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- Section 2 -->
        <div class="col-12 dashboard-section">
            <h3 class="section-title"><i class="fa-solid fa-id-card me-2 text-primary"></i>Registered Users With Club Memberships</h3>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Total Users With Memberships</div>
                            <div class="kpi-value text-primary"><?php echo number_format($total_with_membership); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Male Count</div>
                            <div class="kpi-value text-info"><?php echo number_format($with_data['male']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Female Count</div>
                            <div class="kpi-value text-danger"><?php echo number_format($with_data['female']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <?php render_count_table('Programme Counts', 'fa-solid fa-graduation-cap', 'text-primary', 'Programme', $with_data['programmes'], $total_with_membership); ?>
                </div>
                <div class="col-12 col-xl-6">
                    <?php render_count_table('School Counts', 'fa-solid fa-building', 'text-success', 'School/Centre', $with_data['schools'], $total_with_membership); ?>
                </div>
                <div class="col-12 col-xl-6">
                    <?php render_count_table('Country Counts', 'fa-solid fa-globe', 'text-warning', 'Country', $with_data['countries'], $total_with_membership); ?>
                </div>
                <div class="col-12 col-xl-6">
                    <?php render_count_table('Gender Counts', 'fa-solid fa-venus-mars', 'text-danger', 'Gender', $with_data['genders'], $total_with_membership); ?>
                </div>
            </div>
        </div>

        <!-- Section 3 -->
        <div class="col-12 dashboard-section">
            <h3 class="section-title"><i class="fa-solid fa-user-slash me-2 text-danger"></i>Registered Users Without Club Memberships</h3>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Total Users Without Memberships</div>
                            <div class="kpi-value text-secondary"><?php echo number_format($total_without_membership); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Male Count</div>
                            <div class="kpi-value text-info"><?php echo number_format($without_data['male']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="kpi-label">Female Count</div>
                            <div class="kpi-value text-danger"><?php echo number_format($without_data['female']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <?php render_count_table('Programme Counts', 'fa-solid fa-graduation-cap', 'text-primary', 'Programme', $without_data['programmes'], $total_without_membership); ?>
                </div>
                <div class="col-12 col-xl-6">
                    <?php render_count_table('School Counts', 'fa-solid fa-building', 'text-success', 'School/Centre', $without_data['schools'], $total_without_membership); ?>
                </div>
                <div class="col-12 col-xl-6">
                    <?php render_count_table('Country Counts', 'fa-solid fa-globe', 'text-warning', 'Country', $without_data['countries'], $total_without_membership); ?>
                </div>
                <div class="col-12 col-xl-6">
                    <?php render_count_table('Gender Counts', 'fa-solid fa-venus-mars', 'text-danger', 'Gender', $without_data['genders'], $total_without_membership); ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Events & Recent Users -->
        <div class="col-12 col-xl-6">
            <div class="card soft-card shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <strong><i class="fa-regular fa-calendar me-2 text-info"></i>Upcoming Events</strong>
                    <a href="manage_events.php" class="btn btn-sm btn-light">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (!empty($upcomingEvents)): ?>
                            <?php foreach ($upcomingEvents as $e): ?>
                                <div class="list-group-item d-flex align-items-center">
                                    <div class="me-3"><i class="fa-regular fa-calendar text-muted"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold"><?php echo display_text(safe_text(isset($e['title']) ? $e['title'] : 'Untitled Event')); ?></div>
                                        <div class="small text-muted">
                                            <?php echo display_text(safe_text(isset($e['location']) ? $e['location'] : 'Location TBA')); ?>
                                            •
                                            <?php
                                            if (!empty($e['dt'])) {
                                                echo display_text(date('M j, Y g:i A', strtotime($e['dt'])));
                                            } else {
                                                echo 'Date TBA';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <a href="manage_events.php" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item text-muted">No upcoming events found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card soft-card shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <strong><i class="fa-solid fa-users me-2 text-primary"></i>Recent Users</strong>
                    <a href="manage_users.php" class="btn btn-sm btn-light">Manage users</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 dashboard-table">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentUsers)): ?>
                                    <?php foreach ($recentUsers as $u): ?>
                                        <tr>
                                            <td><?php echo (int)$u['id']; ?></td>
                                            <td><?php echo display_text(safe_text(isset($u['name']) ? $u['name'] : '')); ?></td>
                                            <td><?php echo display_text(safe_text(isset($u['email']) ? $u['email'] : '')); ?></td>
                                            <td>
                                                <?php
                                                if (!empty($u['created_at'])) {
                                                    echo display_text(date('M j, Y g:i A', strtotime($u['created_at'])));
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-muted text-center py-3">No users found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notifications -->
        <div class="col-12">
            <div class="card soft-card shadow-sm">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <strong><i class="fa-solid fa-bell me-2 text-warning"></i>Recent Notifications</strong>
                    <a href="notifications.php" class="btn btn-sm btn-light">Open inbox</a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (!empty($recentNotifs)): ?>
                            <?php foreach ($recentNotifs as $n): ?>
                                <div class="list-group-item">
                                    <div class="fw-semibold"><?php echo display_text(safe_text(isset($n['title']) ? $n['title'] : 'Notification')); ?></div>
                                    <?php if (!empty($n['msg'])): ?>
                                        <div class="small text-muted"><?php echo display_text($n['msg']); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">
                                        <?php
                                        if (!empty($n['created_at'])) {
                                            echo display_text(date('M j, Y g:i A', strtotime($n['created_at'])));
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item text-muted">No notifications.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="col-12">
            <div class="card soft-card shadow-sm">
                <div class="card-header bg-white">
                    <strong><i class="fa-solid fa-bolt me-2 text-primary"></i>Quick Actions</strong>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary quick-action-btn" href="manage_clubs.php">
                            <i class="fa-solid fa-clipboard-check me-1"></i> Review Club Approvals
                        </a>
                        <a class="btn btn-outline-dark quick-action-btn" href="settings.php">
                            <i class="fa-solid fa-sliders me-1"></i> System Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
