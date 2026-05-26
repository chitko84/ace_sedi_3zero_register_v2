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

$q = trim($_GET['q'] ?? '');
$semester = trim($_GET['current_semester'] ?? '');
$memberType = trim($_GET['member_type'] ?? '');
$programme = trim($_GET['programme'] ?? '');
$school = trim($_GET['school_centre'] ?? '');
$nationality = trim($_GET['nationality'] ?? '');
$cluster = trim($_GET['cluster'] ?? '');
$focusArea = trim($_GET['focus_area'] ?? '');
$clubStatus = trim($_GET['club_status'] ?? '');
$sort = trim($_GET['sort'] ?? 'created');
$order = strtolower(trim($_GET['order'] ?? 'desc'));

if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'desc';
}

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($q !== '') {
    $where .= " AND (cm.full_name LIKE ? OR cm.email LIKE ? OR cm.student_id LIKE ? OR c.group_name LIKE ? OR c.club_identifier LIKE ?) ";
    $like = "%{$q}%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

$filters = [
    ['value' => $semester, 'sql' => 'cm.current_semester = ?'],
    ['value' => $memberType, 'sql' => 'cm.member_type = ?'],
    ['value' => $programme, 'sql' => 'cm.programme = ?'],
    ['value' => $school, 'sql' => 'cm.school_centre = ?'],
    ['value' => $nationality, 'sql' => 'cm.nationality = ?'],
    ['value' => $cluster, 'sql' => 'c.cluster = ?'],
    ['value' => $focusArea, 'sql' => 'c.focus_area = ?'],
    ['value' => $clubStatus, 'sql' => 'c.status = ?'],
];

foreach ($filters as $filter) {
    if ($filter['value'] !== '') {
        $where .= " AND {$filter['sql']} ";
        $params[] = $filter['value'];
        $types .= 's';
    }
}

$sortMap = [
    'member_id' => 'cm.id',
    'club_id' => 'cm.club_id',
    'club_name' => 'c.group_name',
    'full_name' => 'cm.full_name',
    'student_id' => 'cm.student_id',
    'programme' => 'cm.programme',
    'semester' => 'cm.current_semester',
    'member_type' => 'cm.member_type',
    'created' => 'cm.created_at',
];
$sortSql = $sortMap[$sort] ?? $sortMap['created'];

$sql = "
    SELECT
        cm.id AS member_id,
        cm.club_id,
        c.group_name,
        c.club_identifier,
        cm.full_name,
        cm.student_id,
        cm.email,
        cm.phone,
        cm.programme,
        cm.nationality,
        cm.school_centre,
        cm.intake_month_year,
        cm.expected_graduation_year,
        cm.current_semester,
        cm.member_type,
        c.cluster,
        c.focus_area,
        c.status AS club_status,
        cm.created_at
    FROM club_members cm
    LEFT JOIN clubs c ON c.id = cm.club_id
    {$where}
    ORDER BY {$sortSql} {$order}, cm.id DESC
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
header('Content-Disposition: attachment; filename="filter_by_semester_' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, [
    'Member ID',
    'Club ID',
    'Club Name',
    'Club Identifier',
    'Full Name',
    'Student ID',
    'Email',
    'Phone',
    'Programme',
    'Nationality',
    'School/Centre',
    'Intake Month Year',
    'Expected Graduation Year',
    'Current Semester',
    'Member Type',
    'Club Cluster',
    'Club Focus Area',
    'Club Status',
    'Created At',
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        csv_value($row['member_id'] ?? ''),
        csv_value($row['club_id'] ?? ''),
        csv_value($row['group_name'] ?? ''),
        csv_value($row['club_identifier'] ?? ''),
        csv_value($row['full_name'] ?? ''),
        csv_value($row['student_id'] ?? ''),
        csv_value($row['email'] ?? ''),
        csv_value($row['phone'] ?? ''),
        csv_value($row['programme'] ?? ''),
        csv_value($row['nationality'] ?? ''),
        csv_value($row['school_centre'] ?? ''),
        csv_value($row['intake_month_year'] ?? ''),
        csv_value($row['expected_graduation_year'] ?? ''),
        csv_value($row['current_semester'] ?? ''),
        csv_value($row['member_type'] ?? ''),
        csv_value($row['cluster'] ?? ''),
        csv_value($row['focus_area'] ?? ''),
        csv_value($row['club_status'] ?? ''),
        csv_value($row['created_at'] ?? ''),
    ]);
}

fclose($out);
exit;
