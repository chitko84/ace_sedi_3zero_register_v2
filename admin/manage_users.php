<?php
// admin/manage_users.php
require_once __DIR__ . '/header.php';

// ---------- Helpers ----------
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function is_missing_value($value) {
    $v = strtolower(trim((string)$value));
    return (
        $v === '' ||
        $v === 'unknown' ||
        $v === 'missing' ||
        $v === 'null' ||
        $v === '-' ||
        $v === 'n/a' ||
        $v === 'na' ||
        $v === 'none'
    );
}

function getProfilePicPath($profilePic) {
    $defaultPic = '../uploads/default-profile.jpg';

    if (empty($profilePic) || $profilePic === 'default-profile.jpg') {
        return $defaultPic;
    }

    if (strpos($profilePic, '../uploads/profiles/') === 0) {
        $actualPath = $profilePic;
    } elseif (strpos($profilePic, 'uploads/profiles/') !== false) {
        $actualPath = '../' . $profilePic;
    } else {
        $actualPath = '../uploads/profiles/' . $profilePic;
    }

    return file_exists($actualPath) ? $actualPath : $defaultPic;
}

function qs(array $overrides = []) {
    $merged = array_merge($_GET, $overrides);

    foreach ($merged as $key => $value) {
        if ($value === null || $value === '') {
            unset($merged[$key]);
        }
    }

    return '?' . http_build_query($merged);
}

$missingValuesSql = "('', 'unknown', 'Unknown', 'UNKNOWN', 'missing', 'Missing', 'MISSING', 'null', 'NULL', '-', 'n/a', 'N/A', 'na', 'NA', 'none', 'None')";

// ---------- Query Params ----------
$q           = trim($_GET['q'] ?? '');
$role        = trim($_GET['role'] ?? '');
$gender      = trim($_GET['gender'] ?? '');
$grad_year   = trim($_GET['grad_year'] ?? '');
$department  = trim($_GET['department'] ?? '');
$program     = trim($_GET['program'] ?? '');
$country     = trim($_GET['country'] ?? '');
$area        = trim($_GET['area'] ?? '');
$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to'] ?? '');
$missing     = trim($_GET['missing'] ?? '');
$sort        = trim($_GET['sort'] ?? 'date_desc');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = min(100, max(5, (int)($_GET['per_page'] ?? 10)));

// ---------- Missing Counters ----------
$missingStats = [
    'any'        => 0,
    'gender'     => 0,
    'phone'      => 0,
    'department' => 0,
    'program'    => 0,
    'area'       => 0,
    'country'    => 0,
];

$statsSql = "
    SELECT
        SUM(
            CASE WHEN
                gender IS NULL OR TRIM(gender) IN {$missingValuesSql}
                OR phone_number IS NULL OR TRIM(phone_number) IN {$missingValuesSql}
                OR department IS NULL OR TRIM(department) IN {$missingValuesSql}
                OR program_of_study IS NULL OR TRIM(program_of_study) IN {$missingValuesSql}
                OR area_of_interest IS NULL OR TRIM(area_of_interest) IN {$missingValuesSql}
                OR country IS NULL OR TRIM(country) IN {$missingValuesSql}
            THEN 1 ELSE 0 END
        ) AS any_missing,

        SUM(CASE WHEN gender IS NULL OR TRIM(gender) IN {$missingValuesSql} THEN 1 ELSE 0 END) AS missing_gender,
        SUM(CASE WHEN phone_number IS NULL OR TRIM(phone_number) IN {$missingValuesSql} THEN 1 ELSE 0 END) AS missing_phone,
        SUM(CASE WHEN department IS NULL OR TRIM(department) IN {$missingValuesSql} THEN 1 ELSE 0 END) AS missing_department,
        SUM(CASE WHEN program_of_study IS NULL OR TRIM(program_of_study) IN {$missingValuesSql} THEN 1 ELSE 0 END) AS missing_program,
        SUM(CASE WHEN area_of_interest IS NULL OR TRIM(area_of_interest) IN {$missingValuesSql} THEN 1 ELSE 0 END) AS missing_area,
        SUM(CASE WHEN country IS NULL OR TRIM(country) IN {$missingValuesSql} THEN 1 ELSE 0 END) AS missing_country
    FROM users
";

$statsRes = $conn->query($statsSql);
if ($statsRes) {
    $s = $statsRes->fetch_assoc();

    $missingStats['any']        = (int)($s['any_missing'] ?? 0);
    $missingStats['gender']     = (int)($s['missing_gender'] ?? 0);
    $missingStats['phone']      = (int)($s['missing_phone'] ?? 0);
    $missingStats['department'] = (int)($s['missing_department'] ?? 0);
    $missingStats['program']    = (int)($s['missing_program'] ?? 0);
    $missingStats['area']       = (int)($s['missing_area'] ?? 0);
    $missingStats['country']    = (int)($s['missing_country'] ?? 0);
}


// ---------- User Analytics ----------
function fetch_count_value($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int)($row['value'] ?? 0);
}

function fetch_group_counts($conn, $sql) {
    $items = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'label' => (string)($row['label'] ?? 'Unknown'),
                'value' => (int)($row['value'] ?? 0),
            ];
        }
    }
    return $items;
}

$totalUsersAll = fetch_count_value($conn, "SELECT COUNT(*) AS value FROM users");
$totalAdmins = fetch_count_value($conn, "SELECT COUNT(*) AS value FROM users WHERE role = 'admin'");
$totalNormalUsers = fetch_count_value($conn, "SELECT COUNT(*) AS value FROM users WHERE role = 'user'");
$newUsersThisMonth = fetch_count_value($conn, "SELECT COUNT(*) AS value FROM users WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
$newUsersLast30Days = fetch_count_value($conn, "SELECT COUNT(*) AS value FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

$completeProfiles = fetch_count_value($conn, "
    SELECT COUNT(*) AS value
    FROM users
    WHERE NOT (
        gender IS NULL OR TRIM(gender) IN {$missingValuesSql}
        OR phone_number IS NULL OR TRIM(phone_number) IN {$missingValuesSql}
        OR department IS NULL OR TRIM(department) IN {$missingValuesSql}
        OR program_of_study IS NULL OR TRIM(program_of_study) IN {$missingValuesSql}
        OR area_of_interest IS NULL OR TRIM(area_of_interest) IN {$missingValuesSql}
        OR country IS NULL OR TRIM(country) IN {$missingValuesSql}
    )
");

$profileCompletenessPercent = $totalUsersAll > 0 ? round(($completeProfiles / $totalUsersAll) * 100, 1) : 0;
$missingProfilePercent = $totalUsersAll > 0 ? round(($missingStats['any'] / $totalUsersAll) * 100, 1) : 0;

$genderAnalytics = fetch_group_counts($conn, "
    SELECT
        CASE
            WHEN gender IS NULL OR TRIM(gender) IN {$missingValuesSql} THEN 'Missing'
            ELSE gender
        END AS label,
        COUNT(*) AS value
    FROM users
    GROUP BY label
    ORDER BY value DESC
");

$roleAnalytics = fetch_group_counts($conn, "
    SELECT role AS label, COUNT(*) AS value
    FROM users
    GROUP BY role
    ORDER BY value DESC
");

$topDepartments = fetch_group_counts($conn, "
    SELECT department AS label, COUNT(*) AS value
    FROM users
    WHERE department IS NOT NULL AND TRIM(department) NOT IN {$missingValuesSql}
    GROUP BY department
    ORDER BY value DESC
    LIMIT 5
");

$topPrograms = fetch_group_counts($conn, "
    SELECT program_of_study AS label, COUNT(*) AS value
    FROM users
    WHERE program_of_study IS NOT NULL AND TRIM(program_of_study) NOT IN {$missingValuesSql}
    GROUP BY program_of_study
    ORDER BY value DESC
    LIMIT 5
");

$topCountries = fetch_group_counts($conn, "
    SELECT country AS label, COUNT(*) AS value
    FROM users
    WHERE country IS NOT NULL AND TRIM(country) NOT IN {$missingValuesSql}
    GROUP BY country
    ORDER BY value DESC
    LIMIT 5
");

$topAreas = fetch_group_counts($conn, "
    SELECT area_of_interest AS label, COUNT(*) AS value
    FROM users
    WHERE area_of_interest IS NOT NULL AND TRIM(area_of_interest) NOT IN {$missingValuesSql}
    GROUP BY area_of_interest
    ORDER BY value DESC
    LIMIT 5
");

$topIntakes = fetch_group_counts($conn, "
    SELECT intake AS label, COUNT(*) AS value
    FROM users
    WHERE intake IS NOT NULL AND TRIM(intake) NOT IN {$missingValuesSql}
    GROUP BY intake
    ORDER BY value DESC
    LIMIT 5
");

$gradYearAnalytics = fetch_group_counts($conn, "
    SELECT expected_graduation_year AS label, COUNT(*) AS value
    FROM users
    WHERE expected_graduation_year IS NOT NULL AND TRIM(expected_graduation_year) NOT IN {$missingValuesSql}
    GROUP BY expected_graduation_year
    ORDER BY expected_graduation_year ASC
");

$ageAnalytics = fetch_group_counts($conn, "
    SELECT
        CASE
            WHEN date_of_birth IS NULL THEN 'Missing DOB'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 18 THEN 'Under 18'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 20 THEN '18 - 20'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 21 AND 24 THEN '21 - 24'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 25 AND 30 THEN '25 - 30'
            ELSE '31+'
        END AS label,
        COUNT(*) AS value
    FROM users
    GROUP BY label
    ORDER BY
        CASE label
            WHEN 'Under 18' THEN 1
            WHEN '18 - 20' THEN 2
            WHEN '21 - 24' THEN 3
            WHEN '25 - 30' THEN 4
            WHEN '31+' THEN 5
            ELSE 6
        END
");

$monthlyRegistrations = fetch_group_counts($conn, "
    SELECT DATE_FORMAT(created_at, '%b %Y') AS label, COUNT(*) AS value
    FROM users
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%b %Y')
    ORDER BY YEAR(created_at), MONTH(created_at)
");

$analyticsMax = 1;
foreach ([$genderAnalytics, $roleAnalytics, $topDepartments, $topPrograms, $topCountries, $topAreas, $topIntakes, $gradYearAnalytics, $ageAnalytics, $monthlyRegistrations] as $group) {
    foreach ($group as $item) {
        $analyticsMax = max($analyticsMax, (int)$item['value']);
    }
}

// ---------- Dropdown Data ----------
function get_distinct_values($conn, $column) {
    $allowed = [
        'role',
        'gender',
        'expected_graduation_year',
        'department',
        'program_of_study',
        'country',
        'area_of_interest'
    ];

    if (!in_array($column, $allowed, true)) {
        return [];
    }

    $values = [];

    $sql = "
        SELECT DISTINCT {$column} AS val
        FROM users
        WHERE {$column} IS NOT NULL
          AND TRIM({$column}) NOT IN ('', 'unknown', 'Unknown', 'UNKNOWN', 'missing', 'Missing', 'MISSING', 'null', 'NULL', '-', 'n/a', 'N/A', 'na', 'NA', 'none', 'None')
        ORDER BY val ASC
    ";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $values[] = $row['val'];
        }
    }

    return $values;
}

$roles       = get_distinct_values($conn, 'role');
$genders     = get_distinct_values($conn, 'gender');
$gradYears   = get_distinct_values($conn, 'expected_graduation_year');
$departments = get_distinct_values($conn, 'department');
$programs    = get_distinct_values($conn, 'program_of_study');
$countries   = get_distinct_values($conn, 'country');
$areas       = get_distinct_values($conn, 'area_of_interest');

// ---------- Build WHERE ----------
$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($q !== '') {
    $where .= " AND (
        CAST(id AS CHAR) LIKE ?
        OR name LIKE ?
        OR email LIKE ?
        OR phone_number LIKE ?
        OR gender LIKE ?
        OR country LIKE ?
        OR department LIKE ?
        OR program_of_study LIKE ?
        OR area_of_interest LIKE ?
        OR expected_graduation_year LIKE ?
        OR role LIKE ?
    )";

    $like = "%{$q}%";

    for ($i = 0; $i < 11; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($role !== '') {
    $where .= " AND role = ? ";
    $params[] = $role;
    $types .= 's';
}

if ($gender !== '') {
    $where .= " AND gender = ? ";
    $params[] = $gender;
    $types .= 's';
}

if ($grad_year !== '') {
    $where .= " AND expected_graduation_year = ? ";
    $params[] = $grad_year;
    $types .= 's';
}

if ($department !== '') {
    $where .= " AND department = ? ";
    $params[] = $department;
    $types .= 's';
}

if ($program !== '') {
    $where .= " AND program_of_study = ? ";
    $params[] = $program;
    $types .= 's';
}

if ($country !== '') {
    $where .= " AND country = ? ";
    $params[] = $country;
    $types .= 's';
}

if ($area !== '') {
    $where .= " AND area_of_interest = ? ";
    $params[] = $area;
    $types .= 's';
}

if ($date_from !== '') {
    $where .= " AND DATE(created_at) >= ? ";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where .= " AND DATE(created_at) <= ? ";
    $params[] = $date_to;
    $types .= 's';
}

if ($missing === 'any') {
    $where .= " AND (
        gender IS NULL OR TRIM(gender) IN {$missingValuesSql}
        OR phone_number IS NULL OR TRIM(phone_number) IN {$missingValuesSql}
        OR department IS NULL OR TRIM(department) IN {$missingValuesSql}
        OR program_of_study IS NULL OR TRIM(program_of_study) IN {$missingValuesSql}
        OR area_of_interest IS NULL OR TRIM(area_of_interest) IN {$missingValuesSql}
        OR country IS NULL OR TRIM(country) IN {$missingValuesSql}
    )";
} elseif ($missing === 'gender') {
    $where .= " AND (gender IS NULL OR TRIM(gender) IN {$missingValuesSql}) ";
} elseif ($missing === 'phone') {
    $where .= " AND (phone_number IS NULL OR TRIM(phone_number) IN {$missingValuesSql}) ";
} elseif ($missing === 'department') {
    $where .= " AND (department IS NULL OR TRIM(department) IN {$missingValuesSql}) ";
} elseif ($missing === 'program') {
    $where .= " AND (program_of_study IS NULL OR TRIM(program_of_study) IN {$missingValuesSql}) ";
} elseif ($missing === 'area') {
    $where .= " AND (area_of_interest IS NULL OR TRIM(area_of_interest) IN {$missingValuesSql}) ";
} elseif ($missing === 'country') {
    $where .= " AND (country IS NULL OR TRIM(country) IN {$missingValuesSql}) ";
}

// ---------- Sorting ----------
$orderBy = "created_at DESC, id DESC";

if ($sort === 'date_asc') {
    $orderBy = "created_at ASC, id ASC";
} elseif ($sort === 'name_asc') {
    $orderBy = "name ASC";
} elseif ($sort === 'name_desc') {
    $orderBy = "name DESC";
} elseif ($sort === 'id_desc') {
    $orderBy = "id DESC";
} elseif ($sort === 'id_asc') {
    $orderBy = "id ASC";
}

// ---------- Count ----------
$sqlCount = "SELECT COUNT(*) AS c FROM users {$where}";
$stmtC = $conn->prepare($sqlCount);

if ($types !== '') {
    $stmtC->bind_param($types, ...$params);
}

$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0);

// ---------- Pagination ----------
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// ---------- Fetch Rows ----------
$sql = "
    SELECT
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
        expected_graduation_year,
        area_of_interest,
        created_at
    FROM users
    {$where}
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if ($types !== '') {
    $bindTypes = $types . 'ii';
    $bindParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($bindTypes, ...$bindParams);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
?>

<style>
    .users-page {
        padding-bottom: 2rem;
    }

    .page-title {
        font-size: 1.15rem;
        font-weight: 700;
        margin-bottom: 0;
    }

    .page-subtitle {
        font-size: 0.9rem;
        color: #6c757d;
    }

    .missing-card {
        display: block;
        background: #fff8dc;
        border: 1px solid #f6df88;
        border-radius: 8px;
        padding: 14px 16px;
        min-height: 67px;
        transition: 0.15s ease;
        text-decoration: none;
    }

    .missing-card:hover {
        background: #fff3bd;
        border-color: #f0c94b;
        transform: translateY(-1px);
    }

    .missing-card.active {
        background: #e8f1ff;
        border-color: #0d6efd;
    }

    .missing-card .label {
        color: #555;
        font-size: 0.9rem;
    }

    .missing-card .value {
        font-weight: 800;
        font-size: 1.05rem;
        color: #111827;
    }

    .filter-card {
        border-radius: 8px;
        border: 0;
    }

    .filter-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: #495057;
        border-bottom: 1px solid #ddd;
        padding-bottom: 8px;
        margin-bottom: 18px;
        text-transform: uppercase;
    }

    .filter-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #495057;
        margin-bottom: 6px;
    }

    .table-card {
        border-radius: 8px;
        border: 0;
    }

    .users-table {
        font-size: 0.84rem;
    }

    .users-table th {
        white-space: nowrap;
        color: #6b7280;
        font-size: 0.75rem;
        text-transform: uppercase;
    }

    .users-table td {
        vertical-align: middle;
    }

    @media (max-width: 767.98px) {
        .users-table thead { display: none; }
        .users-table, .users-table tbody, .users-table tr, .users-table td { display: block; width: 100%; }
        .users-table tr {
            background: #fff;
            margin-bottom: .9rem;
            border: 1px solid #e9eef4;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(15,47,71,.06);
            padding: .35rem .65rem;
        }
        .users-table td {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            text-align: right;
            border: 0 !important;
            border-bottom: 1px dashed #e8edf3 !important;
            padding: .65rem .15rem !important;
            word-break: break-word;
        }
        .users-table td:last-child { border-bottom: 0 !important; }
        .users-table td::before {
            font-weight: 700;
            color: #0f2f47;
            text-align: left;
            flex: 0 0 40%;
        }
        .users-table td:nth-child(1)::before { content: 'ID'; }
        .users-table td:nth-child(2)::before { content: 'Profile'; }
        .users-table td:nth-child(3)::before { content: 'Name'; }
        .users-table td:nth-child(4)::before { content: 'Email'; }
        .users-table td:nth-child(5)::before { content: 'Phone'; }
        .users-table td:nth-child(6)::before { content: 'Gender'; }
        .users-table td:nth-child(7)::before { content: 'Country'; }
        .users-table td:nth-child(8)::before { content: 'Department'; }
        .users-table td:nth-child(9)::before { content: 'Program'; }
        .users-table td:nth-child(10)::before { content: 'Area'; }
        .users-table td:nth-child(11)::before { content: 'Grad Year'; }
        .users-table td:nth-child(12)::before { content: 'Role'; }
        .users-table td:nth-child(13)::before { content: 'Created'; }
        .users-table td:nth-child(14)::before { content: 'Actions'; }
        .users-table .text-end { text-align: left !important; }
    }

    .profile-img {
        width: 38px;
        height: 38px;
        object-fit: cover;
        border-radius: 50%;
    }

    .area-badge {
        background: #cff4fc;
        color: #055160;
        font-size: 0.72rem;
        padding: 5px 8px;
        border-radius: 5px;
        display: inline-block;
        font-weight: 600;
    }

    .role-badge {
        font-size: 0.72rem;
        border-radius: 5px;
        padding: 5px 8px;
    }

    .missing-text {
        color: #dc3545;
        font-weight: 700;
    }

    .btn-icon {
        width: 30px;
        height: 30px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .top-actions .btn {
        border-radius: 6px;
    }

    .analytics-panel {
        display: none;
    }

    .analytics-panel.show {
        display: block;
    }

    .analytics-card {
        border: 0;
        border-radius: 8px;
        height: 100%;
    }

    .analytics-stat {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 14px 16px;
        height: 100%;
    }

    .analytics-stat .label {
        color: #6b7280;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .analytics-stat .value {
        color: #111827;
        font-size: 1.35rem;
        font-weight: 800;
        margin-top: 4px;
    }

    .analytics-stat .hint {
        color: #6b7280;
        font-size: 0.78rem;
        margin-top: 3px;
    }

    .analytics-title {
        font-size: 0.9rem;
        font-weight: 800;
        color: #374151;
        margin-bottom: 12px;
    }

    .analytics-row {
        display: grid;
        grid-template-columns: minmax(120px, 1fr) 3fr auto;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
        font-size: 0.82rem;
    }

    .analytics-label {
        color: #374151;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .analytics-bar-wrap {
        height: 8px;
        background: #e5e7eb;
        border-radius: 999px;
        overflow: hidden;
    }

    .analytics-bar {
        height: 100%;
        background: #6c757d;
        border-radius: 999px;
    }

    .analytics-count {
        color: #111827;
        font-weight: 700;
        min-width: 32px;
        text-align: right;
    }

    .completeness-wrap {
        height: 12px;
        background: #e5e7eb;
        border-radius: 999px;
        overflow: hidden;
        margin-top: 8px;
    }

    .completeness-bar {
        height: 100%;
        background: #198754;
    }

</style>

<main class="main-content container-fluid users-page">
    <div class="row g-3">

        <div class="col-12 d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="page-title">
                    <i class="fa-solid fa-users text-primary me-2"></i>User Management
                </h2>
                <div class="page-subtitle">Manage users and find missing profile details</div>
            </div>

            <div class="top-actions d-flex gap-2">
                <button type="button" id="toggleAnalyticsBtn" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-chart-line me-1"></i> <span id="toggleAnalyticsText">Show Analytics</span>
                </button>
                <a href="create_user.php" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-user-plus me-1"></i> New User
                </a>
            </div>
        </div>


        <div class="col-12 analytics-panel" id="analyticsPanel">
            <div class="card shadow-sm analytics-card">
                <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <strong>
                            <i class="fa-solid fa-chart-pie me-1"></i> User Analytics
                        </strong>
                        <span class="text-muted small">Overview of registered users</span>
                    </div>
                    <span class="badge bg-secondary">Live from users table</span>
                </div>

                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="analytics-stat">
                                <div class="label">Total Users</div>
                                <div class="value"><?= number_format($totalUsersAll) ?></div>
                                <div class="hint">All registered accounts</div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="analytics-stat">
                                <div class="label">Normal Users</div>
                                <div class="value"><?= number_format($totalNormalUsers) ?></div>
                                <div class="hint">Role: user</div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="analytics-stat">
                                <div class="label">Admins</div>
                                <div class="value"><?= number_format($totalAdmins) ?></div>
                                <div class="hint">Role: admin</div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="analytics-stat">
                                <div class="label">New Last 30 Days</div>
                                <div class="value"><?= number_format($newUsersLast30Days) ?></div>
                                <div class="hint"><?= number_format($newUsersThisMonth) ?> this month</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-xl-6">
                            <div class="analytics-stat">
                                <div class="label">Profile Completeness</div>
                                <div class="value"><?= h($profileCompletenessPercent) ?>%</div>
                                <div class="hint">
                                    <?= number_format($completeProfiles) ?> complete profiles,
                                    <?= number_format($missingStats['any']) ?> with missing/unknown important details
                                    (<?= h($missingProfilePercent) ?>%)
                                </div>
                                <div class="completeness-wrap">
                                    <div class="completeness-bar" style="width: <?= h($profileCompletenessPercent) ?>%;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="analytics-stat">
                                <div class="label">Missing Data Summary</div>
                                <div class="row g-2 mt-1 small">
                                    <div class="col-6 col-md-4">Gender: <strong><?= number_format($missingStats['gender']) ?></strong></div>
                                    <div class="col-6 col-md-4">Phone: <strong><?= number_format($missingStats['phone']) ?></strong></div>
                                    <div class="col-6 col-md-4">Department: <strong><?= number_format($missingStats['department']) ?></strong></div>
                                    <div class="col-6 col-md-4">Program: <strong><?= number_format($missingStats['program']) ?></strong></div>
                                    <div class="col-6 col-md-4">Area: <strong><?= number_format($missingStats['area']) ?></strong></div>
                                    <div class="col-6 col-md-4">Country: <strong><?= number_format($missingStats['country']) ?></strong></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    function render_analytics_list($title, $items, $analyticsMax) {
                        ?>
                        <div class="analytics-title"><?= h($title) ?></div>

                        <?php if (empty($items)): ?>
                            <div class="text-muted small">No data available.</div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                    $value = (int)$item['value'];
                                    $width = $analyticsMax > 0 ? max(4, round(($value / $analyticsMax) * 100)) : 0;
                                ?>
                                <div class="analytics-row">
                                    <div class="analytics-label" title="<?= h($item['label']) ?>"><?= h($item['label']) ?></div>
                                    <div class="analytics-bar-wrap">
                                        <div class="analytics-bar" style="width: <?= h($width) ?>%;"></div>
                                    </div>
                                    <div class="analytics-count"><?= number_format($value) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif;
                    }
                    ?>

                    <div class="row g-3">
                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Users by Role', $roleAnalytics, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Users by Gender', $genderAnalytics, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Users by Age Group', $ageAnalytics, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Top Departments', $topDepartments, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Top Programs', $topPrograms, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Top Countries', $topCountries, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Top Areas of Interest', $topAreas, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Top Intakes', $topIntakes, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 col-xl-4">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Graduation Years', $gradYearAnalytics, $analyticsMax); ?>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="analytics-stat">
                                <?php render_analytics_list('Monthly Registrations - Last 12 Months', $monthlyRegistrations, $analyticsMax); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <div class="col-12">
            <div class="row g-2">
                <?php
                $cards = [
                    ['label' => 'Any Missing', 'key' => 'any'],
                    ['label' => 'Gender', 'key' => 'gender'],
                    ['label' => 'Phone', 'key' => 'phone'],
                    ['label' => 'Department', 'key' => 'department'],
                    ['label' => 'Program', 'key' => 'program'],
                    ['label' => 'Area', 'key' => 'area'],
                    ['label' => 'Country', 'key' => 'country'],
                ];
                ?>

                <?php foreach ($cards as $card): ?>
                    <div class="col-12 col-md-6 col-xl">
                        <a href="<?= h(qs(['missing' => $card['key'], 'page' => 1])) ?>"
                           class="missing-card <?= $missing === $card['key'] ? 'active' : '' ?>">
                            <div class="label"><?= h($card['label']) ?></div>
                            <div class="value"><?= number_format($missingStats[$card['key']]) ?></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-12">
            <form method="get" action="" class="card shadow-sm filter-card">
                <div class="card-body">
                    <div class="filter-title">
                        <i class="fa-solid fa-sliders me-1"></i> Filters
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="filter-label">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Search
                            </label>
                            <input type="text" name="q" class="form-control"
                                   placeholder="Name, email, phone, program..."
                                   value="<?= h($q) ?>">
                        </div>

                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="filter-label">
                                <i class="fa-solid fa-user-tag me-1"></i> Role
                            </label>
                            <select name="role" class="form-select">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= h($r) ?>" <?= $role === $r ? 'selected' : '' ?>>
                                        <?= h(ucfirst($r)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="filter-label">
                                <i class="fa-solid fa-venus-mars me-1"></i> Gender
                            </label>
                            <select name="gender" class="form-select">
                                <option value="">All Genders</option>
                                <?php foreach ($genders as $g): ?>
                                    <option value="<?= h($g) ?>" <?= $gender === $g ? 'selected' : '' ?>>
                                        <?= h($g) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="filter-label">Graduation</label>
                            <select name="grad_year" class="form-select">
                                <option value="">All Years</option>
                                <?php foreach ($gradYears as $y): ?>
                                    <option value="<?= h($y) ?>" <?= $grad_year === $y ? 'selected' : '' ?>>
                                        <?= h($y) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="filter-label">
                                <i class="fa-solid fa-calendar-days me-1"></i> Date From
                            </label>
                            <input type="date" name="date_from" class="form-control" value="<?= h($date_from) ?>">
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="filter-label">
                                <i class="fa-solid fa-calendar-days me-1"></i> Date To
                            </label>
                            <input type="date" name="date_to" class="form-control" value="<?= h($date_to) ?>">
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="filter-label">
                                <i class="fa-solid fa-book-open me-1"></i> Program
                            </label>
                            <select name="program" class="form-select">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?= h($p) ?>" <?= $program === $p ? 'selected' : '' ?>>
                                        <?= h($p) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="filter-label">
                                <i class="fa-solid fa-building me-1"></i> Department
                            </label>
                            <select name="department" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= h($d) ?>" <?= $department === $d ? 'selected' : '' ?>>
                                        <?= h($d) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="filter-label">
                                <i class="fa-solid fa-globe me-1"></i> Country
                            </label>
                            <select name="country" class="form-select">
                                <option value="">All Countries</option>
                                <?php foreach ($countries as $c): ?>
                                    <option value="<?= h($c) ?>" <?= $country === $c ? 'selected' : '' ?>>
                                        <?= h($c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-6">
                            <label class="filter-label">
                                <i class="fa-solid fa-bullseye me-1"></i> Area of Interest
                            </label>
                            <select name="area" class="form-select">
                                <option value="">All Areas</option>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= h($a) ?>" <?= $area === $a ? 'selected' : '' ?>>
                                        <?= h($a) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-6">
                            <label class="filter-label">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Missing Data
                            </label>
                            <select name="missing" class="form-select">
                                <option value="" <?= $missing === '' ? 'selected' : '' ?>>All Records</option>
                                <option value="any" <?= $missing === 'any' ? 'selected' : '' ?>>Any Missing / Unknown Value</option>
                                <option value="gender" <?= $missing === 'gender' ? 'selected' : '' ?>>Missing / Unknown Gender</option>
                                <option value="phone" <?= $missing === 'phone' ? 'selected' : '' ?>>Missing / Unknown Phone</option>
                                <option value="department" <?= $missing === 'department' ? 'selected' : '' ?>>Missing / Unknown Department</option>
                                <option value="program" <?= $missing === 'program' ? 'selected' : '' ?>>Missing / Unknown Program</option>
                                <option value="area" <?= $missing === 'area' ? 'selected' : '' ?>>Missing / Unknown Area of Interest</option>
                                <option value="country" <?= $missing === 'country' ? 'selected' : '' ?>>Missing / Unknown Country</option>
                            </select>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fa-solid fa-filter me-1"></i> Apply Filters
                            </button>
                            <a href="manage_users.php" class="btn btn-secondary px-4">
                                <i class="fa-solid fa-rotate-left me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-12">
            <div class="card shadow-sm table-card">
                <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <strong>
                            <i class="fa-solid fa-table me-1"></i> User List
                        </strong>
                        <span class="text-muted">(<?= number_format($total) ?> records)</span>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="export_users_csv.php<?= h(qs(['page' => null])) ?>" class="btn btn-sm btn-success">
                            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
                        </a>

                        <select class="form-select form-select-sm" style="width:auto"
                                onchange="window.location='<?= h(qs(['sort' => 'SORT_VALUE', 'page' => 1])) ?>'.replace('SORT_VALUE', this.value)">
                            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                            <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>ID Desc</option>
                            <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>>ID Asc</option>
                        </select>

                        <select class="form-select form-select-sm" style="width:auto"
                                onchange="window.location='<?= h(qs(['per_page' => 'PER_VALUE', 'page' => 1])) ?>'.replace('PER_VALUE', this.value)">
                            <?php foreach ([10, 20, 30, 50, 100] as $n): ?>
                                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 users-table">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Profile</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Gender</th>
                                <th>Country</th>
                                <th>Dept</th>
                                <th>Program</th>
                                <th>Area</th>
                                <th>Grad Year</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="14" class="text-center text-muted py-4">
                                        No users found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $profilePicPath = getProfilePicPath($r['profile_pic'] ?? '');
                                        $roleClass = ($r['role'] ?? '') === 'admin' ? 'bg-danger' : 'bg-secondary';

                                        $phoneText = is_missing_value($r['phone_number'] ?? '') ? '<span class="missing-text">Missing</span>' : h($r['phone_number']);
                                        $genderText = is_missing_value($r['gender'] ?? '') ? '<span class="missing-text">Missing</span>' : h($r['gender']);
                                        $countryText = is_missing_value($r['country'] ?? '') ? '<span class="missing-text">Missing</span>' : h($r['country']);
                                        $deptText = is_missing_value($r['department'] ?? '') ? '<span class="missing-text">Missing</span>' : h($r['department']);
                                        $programText = is_missing_value($r['program_of_study'] ?? '') ? '<span class="missing-text">Missing</span>' : h($r['program_of_study']);
                                        $areaText = is_missing_value($r['area_of_interest'] ?? '') ? '<span class="missing-text">Missing</span>' : '<span class="area-badge">' . h($r['area_of_interest']) . '</span>';
                                    ?>

                                    <tr>
                                        <td><?= (int)$r['id'] ?></td>

                                        <td>
                                            <img src="<?= h($profilePicPath) ?>"
                                                 onerror="this.src='../uploads/default-profile.jpg'"
                                                 class="profile-img"
                                                 alt="Profile">
                                        </td>

                                        <td><?= h($r['name'] ?? '—') ?></td>
                                        <td><?= h($r['email'] ?? '—') ?></td>
                                        <td><?= $phoneText ?></td>
                                        <td><?= $genderText ?></td>
                                        <td><?= $countryText ?></td>
                                        <td><?= $deptText ?></td>
                                        <td><?= $programText ?></td>
                                        <td><?= $areaText ?></td>
                                        <td><?= is_missing_value($r['expected_graduation_year'] ?? '') ? '<span class="missing-text">Missing</span>' : h($r['expected_graduation_year']) ?></td>

                                        <td>
                                            <span class="badge role-badge <?= h($roleClass) ?>">
                                                <?= h($r['role'] ?? 'user') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php
                                                if (!empty($r['created_at'])) {
                                                    echo h(date('M j, Y', strtotime($r['created_at'])));
                                                } else {
                                                    echo '—';
                                                }
                                            ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="view_user.php?id=<?= (int)$r['id'] ?>"
                                                   class="btn btn-sm btn-outline-secondary btn-icon"
                                                   title="View">
                                                    <i class="fa-regular fa-eye"></i>
                                                </a>

                                                <a href="edit_user.php?id=<?= (int)$r['id'] ?>"
                                                   class="btn btn-sm btn-outline-primary btn-icon"
                                                   title="Edit">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>

                                                <a href="delete_user.php?id=<?= (int)$r['id'] ?>"
                                                   class="btn btn-sm btn-outline-danger btn-icon js-delete-confirm"
                                                   title="Delete"
                                                   data-delete-title="Delete User"
                                                   data-delete-message="Delete this user?"
                                                   data-delete-item="<?= h($r['name'] ?? $r['email'] ?? ('User #' . (int)$r['id'])) ?>"
                                                   data-delete-confirm-label="<i class='fa-solid fa-trash me-1'></i> Delete User">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="text-muted small">
                        Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                    </div>

                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= h(qs(['page' => 1])) ?>">&laquo;</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="<?= h(qs(['page' => $page - 1])) ?>">&lsaquo;</a>
                                </li>
                            <?php endif; ?>

                            <?php
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                for ($p = $start; $p <= $end; $p++):
                            ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= h(qs(['page' => $p])) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= h(qs(['page' => $page + 1])) ?>">&rsaquo;</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="<?= h(qs(['page' => $totalPages])) ?>">&raquo;</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

    </div>
</main>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('toggleAnalyticsBtn');
    const panel = document.getElementById('analyticsPanel');
    const text = document.getElementById('toggleAnalyticsText');

    if (!btn || !panel || !text) {
        return;
    }

    btn.addEventListener('click', function () {
        panel.classList.toggle('show');
        const isShown = panel.classList.contains('show');
        text.textContent = isShown ? 'Hide Analytics' : 'Show Analytics';

        if (isShown) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
</script>


<?php require_once __DIR__ . '/footer.php'; ?>
