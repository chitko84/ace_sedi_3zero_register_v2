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

function bind_and_execute($stmt, $types = '', array $params = []) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function clubs_column_exists($conn, $column) {
    if (!in_array($column, ['cluster', 'focus_area'], true)) {
        return false;
    }
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM clubs LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function normalized_sql($columnSql) {
    return "CASE
        WHEN {$columnSql} IS NULL OR TRIM({$columnSql}) = '' THEN 'Unknown'
        WHEN LOWER(TRIM({$columnSql})) IN ('unknown','missing','null','n/a','na','none','-') THEN 'Unknown'
        ELSE TRIM({$columnSql})
    END";
}

function missing_sql($columnSql) {
    return "({$columnSql} IS NULL OR TRIM({$columnSql}) = '' OR LOWER(TRIM({$columnSql})) IN ('unknown','missing','null','n/a','na','none','-'))";
}

$hasFocusArea = clubs_column_exists($conn, 'focus_area');
$focusColumnSql = $hasFocusArea ? 'c.focus_area' : 'c.cluster';
$focusSelectSql = $hasFocusArea ? 'c.focus_area' : "NULL";
$clusterNormalizedSql = normalized_sql('c.cluster');
$focusNormalizedSql = normalized_sql($focusColumnSql);
$clusterMissingSql = missing_sql('c.cluster');
$focusMissingSql = missing_sql($focusColumnSql);

$q            = trim($_GET['q'] ?? '');
$status       = trim($_GET['status'] ?? '');
$cluster      = trim($_GET['cluster'] ?? '');
$focusArea    = trim($_GET['focus_area'] ?? '');
$missingData  = trim($_GET['missing_data'] ?? '');
$reg_from     = trim($_GET['reg_from'] ?? '');
$reg_to       = trim($_GET['reg_to'] ?? '');
$sort         = trim($_GET['sort'] ?? 'registered');
$order        = strtolower(trim($_GET['order'] ?? 'desc'));

if (!in_array($status, ['pending', 'approved', 'rejected', ''], true)) {
    $status = '';
}
if (!in_array($missingData, ['missing_cluster', 'missing_focus', 'missing_any', 'complete', ''], true)) {
    $missingData = '';
}
if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'desc';
}

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($q !== '') {
    $where .= " AND (
        CAST(c.id AS CHAR) LIKE ?
        OR c.club_identifier LIKE ?
        OR c.group_name LIKE ?
        OR c.cluster_advisor LIKE ?
        OR c.key_person_name LIKE ?
        OR c.key_person_student_id LIKE ?
        OR c.deputy_key_person_name LIKE ?
        OR c.deputy_key_person_student_id LIKE ?
        OR c.cluster LIKE ?";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    $types .= str_repeat('s', 9);
    if ($hasFocusArea) {
        $where .= " OR c.focus_area LIKE ? ";
        $params[] = $like;
        $types .= 's';
    }
    $where .= " ) ";
}

if ($status !== '') {
    $where .= " AND c.status = ? ";
    $params[] = $status;
    $types .= 's';
}

if ($cluster !== '') {
    $where .= " AND {$clusterNormalizedSql} = ? ";
    $params[] = $cluster;
    $types .= 's';
}

if ($focusArea !== '') {
    if ($focusArea === 'Unknown / Missing' || $focusArea === '__missing') {
        $where .= " AND {$focusMissingSql} ";
    } else {
        $where .= " AND TRIM({$focusColumnSql}) = ? ";
        $params[] = $focusArea;
        $types .= 's';
    }
}

if ($missingData === 'missing_cluster') {
    $where .= " AND {$clusterMissingSql} ";
} elseif ($missingData === 'missing_focus') {
    $where .= " AND {$focusMissingSql} ";
} elseif ($missingData === 'missing_any') {
    $where .= " AND ({$clusterMissingSql} OR {$focusMissingSql}) ";
} elseif ($missingData === 'complete') {
    $where .= " AND NOT ({$clusterMissingSql}) AND NOT ({$focusMissingSql}) ";
}

if ($reg_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_from)) {
    $where .= " AND c.date_of_registration >= ? ";
    $params[] = $reg_from;
    $types .= 's';
}

if ($reg_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_to)) {
    $where .= " AND c.date_of_registration <= ? ";
    $params[] = $reg_to;
    $types .= 's';
}

$allowedSorts = [
    'id' => 'c.id',
    'name' => 'c.group_name',
    'cluster' => $clusterNormalizedSql,
    'focus_area' => $focusNormalizedSql,
    'status' => 'c.status',
    'registered' => 'c.date_of_registration',
];
$sortSql = $allowedSorts[$sort] ?? $allowedSorts['registered'];

$sql = "
    SELECT c.id, c.club_identifier, c.group_name, c.cluster,
           {$focusSelectSql} AS focus_area,
           c.cluster_advisor, c.key_person_name, c.key_person_student_id,
           c.deputy_key_person_name, c.deputy_key_person_student_id,
           c.date_of_registration, c.status, c.created_at, c.updated_at
    FROM clubs c
    {$where}
    ORDER BY {$sortSql} {$order}, c.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('CSV export query failed.');
}
$result = bind_and_execute($stmt, $types, $params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="clubs_' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, [
    'Club ID',
    'Club Identifier',
    'Group Name',
    'Cluster',
    'Focus Area',
    'Cluster Advisor',
    'Key Person Name',
    'Key Person Student ID',
    'Deputy Key Person Name',
    'Deputy Student ID',
    'Date of Registration',
    'Status',
    'Created At',
    'Updated At',
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        csv_value($row['id'] ?? ''),
        csv_value($row['club_identifier'] ?? ''),
        csv_value($row['group_name'] ?? ''),
        csv_value($row['cluster'] ?? ''),
        csv_value($row['focus_area'] ?? ''),
        csv_value($row['cluster_advisor'] ?? ''),
        csv_value($row['key_person_name'] ?? ''),
        csv_value($row['key_person_student_id'] ?? ''),
        csv_value($row['deputy_key_person_name'] ?? ''),
        csv_value($row['deputy_key_person_student_id'] ?? ''),
        csv_value($row['date_of_registration'] ?? ''),
        csv_value($row['status'] ?? ''),
        csv_value($row['created_at'] ?? ''),
        csv_value($row['updated_at'] ?? ''),
    ]);
}

fclose($out);
exit;
