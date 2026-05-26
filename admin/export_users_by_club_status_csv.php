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

function missing_sql($columnSql) {
    return "({$columnSql} IS NULL OR TRIM({$columnSql}) = '' OR LOWER(TRIM({$columnSql})) IN ('unknown','missing','null','n/a','na','none','-'))";
}

$q        = trim($_GET['q'] ?? '');
$status   = trim($_GET['status'] ?? '');
$cluster  = trim($_GET['cluster'] ?? '');
$focus    = trim($_GET['focus_area'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$sort     = trim($_GET['sort'] ?? 'latest');
$order    = strtolower(trim($_GET['order'] ?? 'desc'));

if (!in_array($status, ['approved', 'pending', 'rejected', ''], true)) {
    $status = '';
}
if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'desc';
}

$where = " WHERE cm.email IS NOT NULL AND TRIM(cm.email) <> '' ";
$params = [];
$types = '';

if ($q !== '') {
    $where .= " AND (
        cm.full_name LIKE ?
        OR cm.email LIKE ?
        OR cm.student_id LIKE ?
        OR cm.programme LIKE ?
        OR cm.nationality LIKE ?
        OR cm.school_centre LIKE ?
        OR c.group_name LIKE ?
        OR c.club_identifier LIKE ?
    )";
    $like = "%{$q}%";
    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($status !== '') {
    $where .= " AND c.status = ? ";
    $params[] = $status;
    $types .= 's';
}

if ($cluster !== '') {
    $where .= " AND c.cluster = ? ";
    $params[] = $cluster;
    $types .= 's';
}

if ($focus !== '') {
    if ($focus === '__missing' || $focus === 'Unknown / Missing') {
        $where .= " AND " . missing_sql('c.focus_area') . " ";
    } else {
        $where .= " AND c.focus_area = ? ";
        $params[] = $focus;
        $types .= 's';
    }
}

if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where .= " AND c.date_of_registration >= ? ";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where .= " AND c.date_of_registration <= ? ";
    $params[] = $dateTo;
    $types .= 's';
}

$allowedSorts = [
    'email' => 'normalized_email',
    'name' => 'full_name',
    'total' => 'total_clubs',
    'latest' => 'latest_registration',
    'id' => 'normalized_email',
];
$sortSql = $allowedSorts[$sort] ?? $allowedSorts['latest'];

$sql = "
    SELECT
        LOWER(TRIM(cm.email)) AS normalized_email,
        MIN(cm.email) AS email,
        MIN(cm.full_name) AS full_name,
        MIN(cm.student_id) AS student_id,
        MIN(cm.programme) AS programme,
        MIN(cm.nationality) AS nationality,
        MIN(cm.phone) AS phone,
        MIN(cm.school_centre) AS school_centre,
        MIN(cm.intake_month_year) AS intake_month_year,
        MIN(cm.expected_graduation_year) AS expected_graduation_year,
        MIN(cm.current_semester) AS current_semester,
        COUNT(DISTINCT c.id) AS total_clubs,
        COUNT(DISTINCT CASE WHEN c.status = 'approved' THEN c.id END) AS approved_clubs,
        COUNT(DISTINCT CASE WHEN c.status = 'pending' THEN c.id END) AS pending_clubs,
        COUNT(DISTINCT CASE WHEN c.status = 'rejected' THEN c.id END) AS rejected_clubs,
        MAX(c.date_of_registration) AS latest_registration
    FROM club_members cm
    LEFT JOIN clubs c ON c.id = cm.club_id
    {$where}
    GROUP BY normalized_email
    ORDER BY {$sortSql} {$order}, normalized_email ASC
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
header('Content-Disposition: attachment; filename="users_by_club_status_' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, [
    'Email',
    'Full Name',
    'Student ID',
    'Programme',
    'Nationality',
    'Phone',
    'School/Centre',
    'Intake',
    'Expected Graduation',
    'Current Semester',
    'Total Clubs',
    'Approved Clubs',
    'Pending Clubs',
    'Rejected Clubs',
    'Latest Registration Date',
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        csv_value($row['email'] ?? ''),
        csv_value($row['full_name'] ?? ''),
        csv_value($row['student_id'] ?? ''),
        csv_value($row['programme'] ?? ''),
        csv_value($row['nationality'] ?? ''),
        csv_value($row['phone'] ?? ''),
        csv_value($row['school_centre'] ?? ''),
        csv_value($row['intake_month_year'] ?? ''),
        csv_value($row['expected_graduation_year'] ?? ''),
        csv_value($row['current_semester'] ?? ''),
        csv_value($row['total_clubs'] ?? ''),
        csv_value($row['approved_clubs'] ?? ''),
        csv_value($row['pending_clubs'] ?? ''),
        csv_value($row['rejected_clubs'] ?? ''),
        csv_value($row['latest_registration'] ?? ''),
    ]);
}

fclose($out);
exit;
