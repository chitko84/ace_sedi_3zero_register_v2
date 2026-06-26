<?php
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "acesedi_3zeroclubdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!$conn->set_charset('utf8mb4')) {
    die("Error loading character set utf8mb4: " . $conn->error);
}

$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

require_once __DIR__ . '/default_admin.php';
ensure_default_admin($conn);

require_once __DIR__ . '/default_local_user.php';
ensure_default_local_user($conn);
?>