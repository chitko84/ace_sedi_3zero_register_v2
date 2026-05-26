<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qs($overrides = []) {
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $k => $v) if ($v === '' || $v === null) unset($merged[$k]);
    return '?' . http_build_query($merged);
}
function bind_exec($stmt, $types = '', $params = []) {
    if ($types !== '' && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}
function scalar($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    $res = bind_exec($stmt, $types, $params);
    $row = $res ? $res->fetch_assoc() : [];
    return (int)($row['value'] ?? 0);
}
function birthday_status($dob) {
    if (!$dob) return 'Unknown';
    $today = date('m-d');
    $md = date('m-d', strtotime($dob));
    if ($md === $today) return 'Today';
    $thisYear = date('Y') . '-' . $md;
    $ts = strtotime($thisYear);
    if ($ts < strtotime(date('Y-m-d'))) $ts = strtotime((date('Y') + 1) . '-' . $md);
    $days = (int)floor(($ts - strtotime(date('Y-m-d'))) / 86400);
    return $days <= 7 ? 'This Week' : 'This Month';
}
function send_birthday_email($to, $name) {
    $subject = 'Happy Birthday from 3ZERO Club';
    $safeName = h($name ?: 'Member');
    $body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#222">
        <h2 style="color:#1a5276">Happy Birthday, '.$safeName.'!</h2>
        <p>Wishing you a joyful birthday and a meaningful year ahead from the 3ZERO Club community.</p>
        <p>Thank you for being part of the Albukhary International University 3ZERO movement.</p>
    </div>';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: 3ZERO Club <acesediaiuedu@ace-sedi.aiu.edu.my>\r\n";
    return @mail($to, $subject, $body, $headers);
}
function table_column_exists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS value FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $res = bind_exec($stmt, 'ss', [$table, $column]);
    $row = $res ? $res->fetch_assoc() : [];
    return (int)($row['value'] ?? 0) > 0;
}

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf_token = $_SESSION['csrf_token'];

$conn->query("CREATE TABLE IF NOT EXISTS birthday_email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT(10) UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    sent_by INT(10) UNSIGNED NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id),
    INDEX(sent_at)
)");
if (!table_column_exists($conn, 'birthday_email_logs', 'sent_by')) {
    if (!table_column_exists($conn, 'birthday_email_logs', 'email')) {
        $conn->query("ALTER TABLE birthday_email_logs ADD COLUMN email VARCHAR(255) NULL AFTER user_id");
    }
    $conn->query("ALTER TABLE birthday_email_logs ADD COLUMN sent_by INT(10) UNSIGNED NULL AFTER email");
}
if (!table_column_exists($conn, 'birthday_email_logs', 'email')) {
    $conn->query("ALTER TABLE birthday_email_logs ADD COLUMN email VARCHAR(255) NULL AFTER user_id");
}
if (!table_column_exists($conn, 'birthday_email_logs', 'status')) {
    $conn->query("ALTER TABLE birthday_email_logs ADD COLUMN status ENUM('sent','failed') NOT NULL DEFAULT 'sent' AFTER sent_by");
}
if (!table_column_exists($conn, 'birthday_email_logs', 'error_message')) {
    $conn->query("ALTER TABLE birthday_email_logs ADD COLUMN error_message TEXT NULL AFTER status");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_email') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid session token. Please try again.';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ? AND email IS NOT NULL LIMIT 1");
        $res = bind_exec($stmt, 'i', [$userId]);
        $u = $res->fetch_assoc();
        if (!$u) {
            $_SESSION['error'] = 'User not found.';
        } else {
            $ok = send_birthday_email($u['email'], $u['name']);
            $status = $ok ? 'sent' : 'failed';
            $err = $ok ? null : 'mail() returned false';
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            $log = $conn->prepare("INSERT INTO birthday_email_logs (user_id, email, sent_by, status, error_message) VALUES (?, ?, ?, ?, ?)");
            $log->bind_param('isiss', $userId, $u['email'], $adminId, $status, $err);
            $log->execute();
            $_SESSION[$ok ? 'success' : 'error'] = $ok ? 'Birthday email sent successfully.' : 'Birthday email could not be sent.';
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'birthday_email.php'));
    exit;
}

$range = trim($_GET['range'] ?? 'today');
$customFrom = trim($_GET['date_from'] ?? '');
$customTo = trim($_GET['date_to'] ?? '');
$q = trim($_GET['q'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10,20,50,100], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = " WHERE u.role='user' AND u.date_of_birth IS NOT NULL ";
$params = [];
$types = '';

if ($range === 'today') {
    $where .= " AND DATE_FORMAT(u.date_of_birth, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d') ";
} elseif ($range === 'week') {
    $where .= " AND DAYOFYEAR(CONCAT(YEAR(CURDATE()), '-', DATE_FORMAT(u.date_of_birth, '%m-%d'))) BETWEEN DAYOFYEAR(CURDATE()) AND DAYOFYEAR(DATE_ADD(CURDATE(), INTERVAL 7 DAY)) ";
} elseif ($range === 'month') {
    $where .= " AND MONTH(u.date_of_birth) = MONTH(CURDATE()) ";
} elseif ($range === 'custom' && $customFrom !== '' && $customTo !== '') {
    $where .= " AND DATE_FORMAT(u.date_of_birth, '%m-%d') BETWEEN DATE_FORMAT(?, '%m-%d') AND DATE_FORMAT(?, '%m-%d') ";
    $params[] = $customFrom; $params[] = $customTo; $types .= 'ss';
}
if ($q !== '') {
    $where .= " AND (CAST(u.id AS CHAR) LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.program_of_study LIKE ? OR u.country LIKE ?) ";
    $like = "%{$q}%";
    array_push($params, $like,$like,$like,$like,$like,$like);
    $types .= 'ssssss';
}

$todayCount = scalar($conn, "SELECT COUNT(*) AS value FROM users WHERE role='user' AND date_of_birth IS NOT NULL AND DATE_FORMAT(date_of_birth, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')");
$monthCount = scalar($conn, "SELECT COUNT(*) AS value FROM users WHERE role='user' AND date_of_birth IS NOT NULL AND MONTH(date_of_birth) = MONTH(CURDATE())");
$dobCount = scalar($conn, "SELECT COUNT(*) AS value FROM users WHERE role='user' AND date_of_birth IS NOT NULL");
$sentToday = scalar($conn, "SELECT COUNT(*) AS value FROM birthday_email_logs WHERE DATE(sent_at) = CURDATE() AND status='sent'");

$total = scalar($conn, "SELECT COUNT(*) AS value FROM users u {$where}", $types, $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql = "SELECT u.id, u.name, u.email, u.date_of_birth, TIMESTAMPDIFF(YEAR, u.date_of_birth, CURDATE()) AS age,
               u.department, u.program_of_study, u.country
        FROM users u
        {$where}
        ORDER BY DATE_FORMAT(u.date_of_birth, '%m-%d') ASC, u.name ASC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$rowTypes = $types . 'ii';
$rowParams = array_merge($params, [$perPage, $offset]);
$res = bind_exec($stmt, $rowTypes, $rowParams);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

require_once __DIR__ . '/header.php';
?>
<main class="main-content container-fluid">
<style>
.stat-card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.08);position:relative;overflow:hidden}.stat-card:before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:#1a5276}.filter-card,.result-card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.07)}@media(max-width:767.98px){.table-stack thead{display:none}.table-stack,.table-stack tbody,.table-stack tr,.table-stack td{display:block;width:100%}.table-stack tr{background:#fff;margin-bottom:.9rem;border:1px solid #e9ecef;border-radius:10px;padding:.35rem .6rem}.table-stack td{display:flex;justify-content:space-between;gap:1rem;border:none!important;border-bottom:1px dashed #eef2f6!important;text-align:right}.table-stack td:before{content:attr(data-label);font-weight:700;color:#0f2f47;text-align:left}.table-stack td:last-child{border-bottom:none!important}}
</style>
<div class="mb-3"><h2 class="mb-1">Birthday Email</h2><div class="text-muted">Review upcoming birthdays and send birthday emails.</div></div>
<?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= h($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger"><?= h($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>
<div class="row g-3 mb-3">
<?php foreach ([["Today's Birthdays",$todayCount,'fa-cake-candles'],['This Month Birthdays',$monthCount,'fa-calendar-days'],['Total Users with DOB',$dobCount,'fa-id-card'],['Emails Sent Today',$sentToday,'fa-paper-plane']] as $card): ?>
    <div class="col-12 col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small fw-bold text-uppercase"><?= h($card[0]) ?></div><div class="h4 mb-0"><?= number_format($card[1]) ?></div></div><i class="fa-solid <?= h($card[2]) ?> fa-2x text-primary"></i></div></div></div>
<?php endforeach; ?>
</div>
<form class="card filter-card mb-3" method="get">
    <input type="hidden" name="page" value="1">
    <div class="card-body"><div class="row g-3 align-items-end">
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">Range</label><select class="form-select" name="range"><option value="today" <?= $range==='today'?'selected':'' ?>>Today</option><option value="week" <?= $range==='week'?'selected':'' ?>>This Week</option><option value="month" <?= $range==='month'?'selected':'' ?>>This Month</option><option value="custom" <?= $range==='custom'?'selected':'' ?>>Custom</option></select></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">From</label><input type="date" class="form-control" name="date_from" value="<?= h($customFrom) ?>"></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">To</label><input type="date" class="form-control" name="date_to" value="<?= h($customTo) ?>"></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name, email, department, program, country"></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Per Page</label><select class="form-select" name="per_page"><?php foreach([10,20,50,100] as $n): ?><option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 col-xl-2"><button class="btn btn-success w-100"><i class="fa-solid fa-filter me-1"></i>Apply</button></div>
    </div></div>
</form>
<div class="card result-card">
    <div class="card-header bg-white d-flex justify-content-between"><strong>Birthdays <span class="text-muted">(<?= number_format($total) ?>)</span></strong><span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?></span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 table-stack">
        <thead class="table-light"><tr><th>User ID</th><th>Name</th><th>Email</th><th>DOB</th><th>Age</th><th>Department</th><th>Program</th><th>Country</th><th>Birthday Status</th><th>Send Email</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-muted text-center py-4">No birthdays found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr><td data-label="User ID"><?= (int)$r['id'] ?></td><td data-label="Name" class="fw-semibold"><?= h($r['name']) ?></td><td data-label="Email"><?= h($r['email']) ?></td><td data-label="DOB"><?= h(date('M j, Y', strtotime($r['date_of_birth']))) ?></td><td data-label="Age"><?= (int)$r['age'] ?></td><td data-label="Department"><?= h($r['department'] ?: 'Unknown') ?></td><td data-label="Program"><?= h($r['program_of_study'] ?: 'Unknown') ?></td><td data-label="Country"><?= h($r['country'] ?: 'Unknown') ?></td><td data-label="Status"><span class="badge bg-info text-dark"><?= h(birthday_status($r['date_of_birth'])) ?></span></td><td data-label="Send"><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="action" value="send_email"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-primary" onclick="return confirm('Send birthday email to this user?')"><i class="fa-regular fa-paper-plane me-1"></i>Send</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="card-footer bg-white"><nav><ul class="pagination mb-0 justify-content-end flex-wrap"><?php $start=max(1,$page-2);$end=min($totalPages,$page+2); if($page>1) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page-1])).'">&lsaquo;</a></li>'; for($p=$start;$p<=$end;$p++) echo '<li class="page-item '.($p===$page?'active':'').'"><a class="page-link" href="'.h(qs(['page'=>$p])).'">'.$p.'</a></li>'; if($page<$totalPages) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page+1])).'">&rsaquo;</a></li>'; ?></ul></nav></div>
</div>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
