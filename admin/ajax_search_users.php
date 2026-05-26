<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'users' => []]);
    exit;
}

$like = '%' . $q . '%';
$sql = "SELECT id, name, email, phone_number, department, program_of_study, intake, country, gender,
               area_of_interest, expected_graduation_year
        FROM users
        WHERE role = 'user'
          AND (email LIKE ? OR name LIKE ? OR CAST(id AS CHAR) LIKE ?)
        ORDER BY
          CASE WHEN email LIKE ? THEN 0 ELSE 1 END,
          name ASC
        LIMIT 10";
$stmt = $conn->prepare($sql);
$prefix = $q . '%';
$stmt->bind_param('ssss', $like, $like, $like, $prefix);
$stmt->execute();
$res = $stmt->get_result();
$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'] ?? '',
        'email' => $row['email'] ?? '',
        'phone_number' => $row['phone_number'] ?? '',
        'department' => $row['department'] ?? '',
        'program_of_study' => $row['program_of_study'] ?? '',
        'intake' => $row['intake'] ?? '',
        'country' => $row['country'] ?? '',
        'gender' => $row['gender'] ?? '',
        'area_of_interest' => $row['area_of_interest'] ?? '',
        'expected_graduation_year' => $row['expected_graduation_year'] ?? '',
    ];
}

echo json_encode(['success' => true, 'users' => $users]);
