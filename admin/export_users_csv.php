<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/db.php';

function csv_value($value) {
    return $value === null ? '' : (string)$value;
}

$missingValuesSql = "('', 'unknown', 'Unknown', 'UNKNOWN', 'missing', 'Missing', 'MISSING', 'null', 'NULL', '-', 'n/a', 'N/A', 'na', 'NA', 'none', 'None')";

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

if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $where .= " AND DATE(created_at) >= ? ";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
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

$orderBy = "created_at DESC, id DESC";
if ($sort === 'date_asc') {
    $orderBy = "created_at ASC, id ASC";
} elseif ($sort === 'name_asc') {
    $orderBy = "name ASC, id ASC";
} elseif ($sort === 'name_desc') {
    $orderBy = "name DESC, id DESC";
} elseif ($sort === 'id_desc') {
    $orderBy = "id DESC";
} elseif ($sort === 'id_asc') {
    $orderBy = "id ASC";
}

$sql = "
    SELECT id, name, email, phone_number, date_of_birth, role, department,
           program_of_study, intake, country, gender, area_of_interest,
           expected_graduation_year, created_at
    FROM users
    {$where}
    ORDER BY {$orderBy}
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('CSV export query failed.');
}
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, [
    'ID',
    'Name',
    'Email',
    'Phone',
    'Date of Birth',
    'Role',
    'Department',
    'Program of Study',
    'Intake',
    'Country',
    'Gender',
    'Area of Interest',
    'Expected Graduation',
    'Created At',
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        csv_value($row['id'] ?? ''),
        csv_value($row['name'] ?? ''),
        csv_value($row['email'] ?? ''),
        csv_value($row['phone_number'] ?? ''),
        csv_value($row['date_of_birth'] ?? ''),
        csv_value($row['role'] ?? ''),
        csv_value($row['department'] ?? ''),
        csv_value($row['program_of_study'] ?? ''),
        csv_value($row['intake'] ?? ''),
        csv_value($row['country'] ?? ''),
        csv_value($row['gender'] ?? ''),
        csv_value($row['area_of_interest'] ?? ''),
        csv_value($row['expected_graduation_year'] ?? ''),
        csv_value($row['created_at'] ?? ''),
    ]);
}

fclose($out);
exit;
