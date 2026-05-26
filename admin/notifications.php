<?php
// admin/notifications.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Admin gate ---
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// ---------- Helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function valid_type($t){ return in_array($t, ['info','success','warning','error'], true); }

// CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Load dropdown data (before POST so we can validate IDs) ----------
$users = [];
$clubs = [];

// Users (id, name, email)
$uq = $conn->query("SELECT id, name, email FROM users ORDER BY name ASC");
if ($uq) while ($row = $uq->fetch_assoc()) $users[] = $row;

// Clubs (id, group_name)
$cq = $conn->query("SELECT id, group_name FROM clubs ORDER BY group_name ASC");
if ($cq) while ($row = $cq->fetch_assoc()) $clubs[] = $row;

$errors = [];
$resultSummary = null;

// ---------- Handle POST (delete/send notifications) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        $action = trim($_POST['action'] ?? 'send');

        if ($action === 'delete_one') {
            $deleteId = (int)($_POST['notification_id'] ?? 0);
            if ($deleteId <= 0) {
                $_SESSION['error'] = "Invalid notification selected.";
            } else {
                $del = $conn->prepare("DELETE FROM notifications WHERE id = ?");
                $del->bind_param('i', $deleteId);
                $del->execute();
                $_SESSION['success'] = $del->affected_rows > 0 ? "Notification deleted." : "Notification was not found.";
            }
            header('Location: notifications.php');
            exit();
        }

        if ($action === 'delete_read') {
            $del = $conn->prepare("DELETE FROM notifications WHERE COALESCE(is_read,0) = 1");
            $del->execute();
            $_SESSION['success'] = "Deleted " . (int)$del->affected_rows . " read notification(s).";
            header('Location: notifications.php');
            exit();
        }

        if ($action === 'delete_all') {
            $del = $conn->prepare("DELETE FROM notifications");
            $del->execute();
            $_SESSION['success'] = "Deleted " . (int)$del->affected_rows . " notification(s).";
            header('Location: notifications.php');
            exit();
        }

        $mode   = trim($_POST['mode'] ?? 'single'); // single|club|all
        $type   = trim($_POST['type'] ?? 'info');
        $title  = trim($_POST['title'] ?? '');
        $body   = trim($_POST['message'] ?? '');
        $userId = (int)($_POST['user_id'] ?? 0);
        $clubId = (int)($_POST['club_id'] ?? 0);

        if ($title === '')   $errors[] = "Title is required.";
        if ($body === '')    $errors[] = "Message is required.";
        if (!valid_type($type)) $errors[] = "Invalid notification type.";

        // Build list of target user IDs to notify
        $targetUserIds   = [];
        $recipientUsers  = []; // [user_id => ['name' => ..., 'email' => ...]]
        $unmatchedEmails = []; // for club mode, members without accounts

        if (!$errors) {
            if ($mode === 'single') {
                if ($userId <= 0) {
                    $errors[] = "Please select a user.";
                } else {
                    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    if ($r) {
                        $uid = (int)$r['id'];
                        $targetUserIds[] = $uid;
                        $recipientUsers[$uid] = [
                            'name'  => $r['name'] ?? '',
                            'email' => $r['email'] ?? ''
                        ];
                    } else {
                        $errors[] = "Selected user not found.";
                    }
                }

            } elseif ($mode === 'club') {
                if ($clubId <= 0) {
                    $errors[] = "Please select a club.";
                } else {
                    // Gather club members' emails
                    $stmt = $conn->prepare("SELECT DISTINCT email FROM club_members WHERE club_id = ? AND email IS NOT NULL AND email <> ''");
                    $stmt->bind_param('i', $clubId);
                    $stmt->execute();
                    $res = $stmt->get_result();

                    $emails = [];
                    while ($row = $res->fetch_assoc()) {
                        $emails[] = trim(strtolower($row['email']));
                    }
                    $emails = array_values(array_unique($emails));

                    if (!$emails) {
                        $errors[] = "No member emails found for this club.";
                    } else {
                        // Map emails to user IDs
                        $placeholders = implode(',', array_fill(0, count($emails), '?'));
                        $typesStr = str_repeat('s', count($emails));
                        $sql = "SELECT id, name, email FROM users WHERE LOWER(email) IN ($placeholders)";
                        $st = $conn->prepare($sql);
                        $st->bind_param($typesStr, ...$emails);
                        $st->execute();
                        $rs = $st->get_result();

                        $emailToId = [];
                        while ($u = $rs->fetch_assoc()) {
                            $id = (int)$u['id'];
                            $em = strtolower($u['email']);
                            $emailToId[$em] = $id;
                            $recipientUsers[$id] = [
                                'name'  => $u['name'] ?? '',
                                'email' => $u['email'] ?? ''
                            ];
                        }
                        foreach ($emails as $em) {
                            if (isset($emailToId[$em])) {
                                $targetUserIds[] = $emailToId[$em];
                            } else {
                                $unmatchedEmails[] = $em;
                            }
                        }
                        $targetUserIds = array_values(array_unique($targetUserIds));
                    }
                }

            } elseif ($mode === 'all') {
                $rq = $conn->query("SELECT id, name, email FROM users");
                while ($r = $rq->fetch_assoc()) {
                    $id = (int)$r['id'];
                    $targetUserIds[] = $id;
                    $recipientUsers[$id] = [
                        'name'  => $r['name'] ?? '',
                        'email' => $r['email'] ?? ''
                    ];
                }
                $targetUserIds = array_values(array_unique($targetUserIds));

            } else {
                $errors[] = "Invalid send mode.";
            }
        }

        if (!$errors) {
            if (empty($targetUserIds)) {
                $errors[] = "No recipients found to notify.";
            } else {
                // Insert notifications in a transaction
                $conn->begin_transaction();
                try {
                    $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read) VALUES (?, ?, ?, ?, 0)");
                    $sent = 0;
                    foreach ($targetUserIds as $uid) {
                        $ins->bind_param('isss', $uid, $title, $body, $type);
                        if ($ins->execute()) $sent++;
                    }
                    $conn->commit();

                    $resultSummary = [
                        'sent' => $sent,
                        'mode' => $mode,
                        'club_unmatched' => $unmatchedEmails,
                    ];

                    // -------------------------------
                    // Send email copies via mail()
                    // -------------------------------
                    if ($sent > 0) {
                        $subjectPrefix = "[3ZERO Club Notification] ";
                        $subject = $subjectPrefix . $title;

                        $headers  = "MIME-Version: 1.0\r\n";
                        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
                        $headers .= "From: ace-sedi@office\r\n";

                        foreach ($targetUserIds as $uid) {
                            if (!isset($recipientUsers[$uid])) continue;
                            $toEmail = $recipientUsers[$uid]['email'] ?? '';
                            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) continue;

                            $nameSafe    = htmlspecialchars($recipientUsers[$uid]['name'] ?: 'Member', ENT_QUOTES, 'UTF-8');
                            $titleSafe   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
                            $messageSafe = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
                            $typeSafe    = htmlspecialchars(ucfirst($type), ENT_QUOTES, 'UTF-8');

                            $emailBody = '
                                <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222">
                                    <h2 style="margin:0 0 12px;color:#1a5276">Hi ' . $nameSafe . ',</h2>
                                    <p>You have a new <strong>' . $typeSafe . '</strong> notification from the 3ZERO Club portal:</p>
                                    <p style="font-weight:bold;margin-top:10px;margin-bottom:6px;">' . $titleSafe . '</p>
                                    <p>' . $messageSafe . '</p>
                                    <p style="margin-top:16px;">
                                        Please log in to your 3ZERO Club account to view more details.
                                    </p>
                                    <p style="color:#555">
                                        If you believe this message was sent to you by mistake, you can safely ignore this email.
                                    </p>
                                </div>
                            ';

                            // Suppress warnings in case mail() fails
                            @mail($toEmail, $subject, $emailBody, $headers);
                        }
                    }

                    $_SESSION['success'] = "Notification(s) sent: {$sent}.";
                    // fall-through to render page with summary
                } catch (Throwable $e) {
                    $conn->rollback();
                    $errors[] = "Failed to send notifications. Please try again.";
                }
            }
        }
    }
}

// ---------- List recent notifications (browse) ----------
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 10)));

$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($q !== '') {
    $where .= " AND (n.title LIKE ? OR n.message LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR CAST(n.id AS CHAR) LIKE ?) ";
    $like = "%{$q}%";
    array_push($params, $like,$like,$like,$like,$like);
    $types .= 'sssss';
}

$sqlCount = "SELECT COUNT(*) AS c
             FROM notifications n
             JOIN users u ON u.id = n.user_id
             {$where}";
$stmtC = $conn->prepare($sqlCount);
if ($types) $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0);

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sqlList = "SELECT n.id, n.user_id, n.title, n.message, n.type, n.is_read, n.created_at,
                   u.name AS user_name, u.email AS user_email
            FROM notifications n
            JOIN users u ON u.id = n.user_id
            {$where}
            ORDER BY n.created_at DESC, n.id DESC
            LIMIT ? OFFSET ?";
$stmtL = $conn->prepare($sqlList);
if ($types) {
    $bindTypes = $types . 'ii';
    $bindVals  = array_merge($params, [$perPage, $offset]);
    $stmtL->bind_param($bindTypes, ...$bindVals);
} else {
    $stmtL->bind_param('ii', $perPage, $offset);
}
$stmtL->execute();
$res = $stmtL->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) $rows[] = $row;

// ---------- Safe to print HTML ----------
require_once __DIR__ . '/header.php';
?>

<main class="main-content container-fluid">
    <style>
        /* small mobile niceties */
        @media (max-width: 576px) {
            .send-actions .btn { width: 100%; }
        }
        .stat-card {
            border:0; border-radius:12px; box-shadow:0 4px 18px rgba(0,0,0,.08); background:white; position:relative; overflow:hidden;
        }
        .stat-card::before {
            content:''; position:absolute; top:0; left:0; right:0; height:4px;
            background: linear-gradient(90deg, #1a5276, #154360);
        }
        .type-pill.info { background:#e8f4fd; color:#1a5276; }
        .type-pill.success { background:#e7f7ee; color:#198754; }
        .type-pill.warning { background:#fff7e6; color:#c97b00; }
        .type-pill.error { background:#fdecea; color:#b02a37; }
        .type-pill { font-weight:600; padding:.25rem .5rem; border-radius:999px; font-size:.78rem; }
    </style>

    <div class="row g-3 align-items-center">
        <div class="col-12 col-lg-7">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Notifications</li>
                </ol>
            </nav>
            <h2 class="mb-0">Notifications</h2>
        </div>
        <div class="col-12 col-lg-5">
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success mb-0">
                    <?= h($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-0">
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($resultSummary): ?>
        <div class="row g-3 mt-1">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div><strong>Sent:</strong> <?= (int)$resultSummary['sent'] ?> notification(s)</div>
                            <div><strong>Mode:</strong> <?= h(ucfirst($resultSummary['mode'])) ?></div>
                            <?php if ($resultSummary['mode']==='club' && !empty($resultSummary['club_unmatched'])): ?>
                                <div class="w-100 mt-2">
                                    <details>
                                        <summary class="text-danger" style="cursor:pointer">
                                            <?= count($resultSummary['club_unmatched']) ?> club member(s) had no account (skipped). Show emails
                                        </summary>
                                        <div class="small mt-2">
                                            <?php foreach ($resultSummary['club_unmatched'] as $em): ?>
                                                <code class="me-2"><?= h($em) ?></code>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger mt-3"><?= h($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Send Notification Form -->
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <strong>Send a Notification</strong>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="send">

                        <div class="col-12 col-md-4">
                            <label class="form-label">Send To</label>
                            <select class="form-select" name="mode" id="modeSelect" required>
                                <option value="single">One User</option>
                                <option value="club">All Members of a Club</option>
                                <option value="all">All Users</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4 mode-single">
                            <label class="form-label">Select User</label>
                            <select class="form-select" name="user_id" id="userSelect">
                                <option value="0">-- choose a user --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>">
                                        <?= h($u['name'] ?: $u['email']) ?> (<?= h($u['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4 mode-club d-none">
                            <label class="form-label">Select Club</label>
                            <select class="form-select" name="club_id" id="clubSelect">
                                <option value="0">-- choose a club --</option>
                                <?php foreach ($clubs as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= h($c['group_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <!-- <div class="form-text">Sends to members found in <code>club_members</code> whose emails match user accounts.</div> -->
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="type" required>
                                <option value="info">Info</option>
                                <option value="success">Success</option>
                                <option value="warning">Warning</option>
                                <option value="error">Error</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" maxlength="255" required placeholder="e.g., Meeting Reminder: Project Sync at 3pm">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" name="message" rows="4" required placeholder="Write your message..."></textarea>
                            <!-- <div class="form-text">Plain text only (stored in <code>notifications.message</code>).</div> -->
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 send-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-regular fa-paper-plane me-1"></i> Send
                            </button>
                            <button type="reset" class="btn btn-light">Reset</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Notifications -->
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong>Recent Notifications</strong>
                        <span class="text-muted ms-1">(<?= number_format($total) ?>)</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#deleteReadModal">
                            <i class="fa-solid fa-broom me-1"></i> Delete Read
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal">
                            <i class="fa-solid fa-trash-can me-1"></i> Delete All
                        </button>
                    </div>
                    <form class="d-flex gap-2" method="get" action="">
                        <input type="text" name="q" class="form-control" placeholder="Search title, message, user name/email or ID" value="<?= h($q) ?>" style="min-width:280px">
                        <select name="per_page" class="form-select">
                            <?php foreach ([10,20,30,50,100] as $n): ?>
                                <option value="<?= $n ?>" <?= $perPage===$n?'selected':''; ?>><?= $n ?>/page</option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Recipient</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Read?</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">No notifications found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($r['user_name'] ?: '—') ?></div>
                                        <div class="small text-muted"><?= h($r['user_email'] ?: '—') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= h($r['title']) ?></div>
                                        <?php if (!empty($r['message'])): ?>
                                            <div class="small text-muted" style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?= h($r['message']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="type-pill <?= h($r['type']) ?>"><?= h(ucfirst($r['type'])) ?></span></td>
                                    <td><?= ((int)$r['is_read'] === 1) ? '<span class="badge bg-secondary">Read</span>' : '<span class="badge bg-primary">Unread</span>' ?></td>
                                    <td><?= h(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteOneModal"
                                                data-notification-id="<?= (int)$r['id'] ?>"
                                                data-notification-title="<?= h($r['title']) ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white">
                    <?php
                    // Simple pagination links
                    $window = 2;
                    $startP = max(1, $page - $window);
                    $endP   = min($totalPages, $page + $window);

                    // Helper to keep query
                    function qs_keep($overrides = []) {
                        $merged = array_merge($_GET, $overrides);
                        return '?' . http_build_query($merged);
                    }
                    ?>
                    <nav>
                        <ul class="pagination mb-0 justify-content-end flex-wrap">
                            <?php if ($startP > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= h(qs_keep(['page'=>1])) ?>">&laquo;</a></li>
                            <?php endif; ?>
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= h(qs_keep(['page'=>$page-1])) ?>">&lsaquo;</a></li>
                            <?php endif; ?>
                            <?php for ($p=$startP; $p<=$endP; $p++): ?>
                                <li class="page-item <?= $p===$page?'active':''; ?>"><a class="page-link" href="<?= h(qs_keep(['page'=>$p])) ?>"><?= $p ?></a></li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="<?= h(qs_keep(['page'=>$page+1])) ?>">&rsaquo;</a></li>
                            <?php endif; ?>
                            <?php if ($endP < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="<?= h(qs_keep(['page'=>$totalPages])) ?>">&raquo;</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>

            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="deleteOneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action" value="delete_one">
            <input type="hidden" name="notification_id" id="deleteOneId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Delete Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Delete this notification?
                <div class="small text-muted mt-2" id="deleteOneTitle"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteReadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action" value="delete_read">
            <div class="modal-header">
                <h5 class="modal-title">Delete Read Notifications</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Delete all read notifications? Unread notifications will remain.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning">Delete Read</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deleteAllModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action" value="delete_all">
            <div class="modal-header">
                <h5 class="modal-title">Delete All Notifications</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-danger fw-semibold">This will permanently delete every notification.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Delete All</button>
            </div>
        </form>
    </div>
</div>

<script>
const deleteOneModal = document.getElementById('deleteOneModal');
if (deleteOneModal) {
    deleteOneModal.addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        document.getElementById('deleteOneId').value = btn.getAttribute('data-notification-id') || '';
        document.getElementById('deleteOneTitle').textContent = btn.getAttribute('data-notification-title') || '';
    });
}

// Toggle single/club/all form pieces
(function(){
    const mode = document.getElementById('modeSelect');
    const singleBox = document.querySelector('.mode-single');
    const clubBox = document.querySelector('.mode-club');

    function syncMode(){
        const val = mode.value;
        if (val === 'single') {
            singleBox.classList.remove('d-none');
            clubBox.classList.add('d-none');
        } else if (val === 'club') {
            clubBox.classList.remove('d-none');
            singleBox.classList.add('d-none');
        } else {
            // 'all'
            singleBox.classList.add('d-none');
            clubBox.classList.add('d-none');
        }
    }
    mode.addEventListener('change', syncMode);
    syncMode();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
