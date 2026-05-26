<?php
// admin/manage_events.php
require_once __DIR__ . '/header.php'; // enforces admin auth and opens $conn

// ---------- Load clubs for filter dropdown (unique, with total event counts) ----------
$clubsOpts = [];
$cq = $conn->query("
    SELECT c.id, c.group_name, COUNT(e.id) AS event_count
    FROM clubs c
    LEFT JOIN events e ON e.club_id = c.id
    GROUP BY c.id, c.group_name
    ORDER BY c.group_name ASC
");
while ($row = $cq->fetch_assoc()) {
    $row['event_count'] = (int)$row['event_count'];
    $clubsOpts[] = $row;
}

// ---------- Query Params ----------
$q          = isset($_GET['q']) ? trim($_GET['q']) : '';
$status     = isset($_GET['status']) ? trim($_GET['status']) : '';
$club_id    = isset($_GET['club_id']) ? (int)($_GET['club_id']) : 0;
$start_from = isset($_GET['start_from']) ? trim($_GET['start_from']) : '';
$start_to   = isset($_GET['start_to'])   ? trim($_GET['start_to'])   : '';
$approval_status = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 10))); // sane bounds

// ---------- Build WHERE (prepared) ----------
$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($q !== '') {
    $where .= " AND (e.title LIKE ? OR e.description LIKE ? OR c.group_name LIKE ? OR CAST(e.id AS CHAR) LIKE ?) ";
    $like = "%{$q}%";
    array_push($params, $like,$like,$like,$like);
    $types .= 'ssss';
}

if ($status !== '' && in_array($status, ['upcoming','ongoing','completed'], true)) {
    $where .= " AND e.status = ? ";
    $params[] = $status;
    $types   .= 's';
}

if ($club_id > 0) {
    $where .= " AND e.club_id = ? ";
    $params[] = $club_id;
    $types   .= 'i';
}

if ($start_from !== '') {
    $where .= " AND e.start_date >= ? ";
    $params[] = $start_from;
    $types   .= 's';
}
if ($start_to !== '') {
    $where .= " AND e.start_date <= ? ";
    $params[] = $start_to;
    $types   .= 's';
}

if ($approval_status !== '' && in_array($approval_status, ['pending','approved','rejected'], true)) {
    $where .= " AND e.approval_status = ? ";
    $params[] = $approval_status;
    $types   .= 's';
}

// ---------- Count (filtered) ----------
$sqlCount = "SELECT COUNT(*) AS c
             FROM events e
             JOIN clubs c ON c.id = e.club_id
             {$where}";
$stmtC = $conn->prepare($sqlCount);
if ($types) $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0);

// ---------- Count (grand total, no filters) ----------
$gtRes = $conn->query("SELECT COUNT(*) AS gt FROM events");
$grandTotal = (int)($gtRes->fetch_assoc()['gt'] ?? 0);

// ---------- Count by approval status ----------
$approvalStats = [];
$statsRes = $conn->query("
    SELECT approval_status, COUNT(*) as count 
    FROM events 
    GROUP BY approval_status
");
while ($row = $statsRes->fetch_assoc()) {
    $approvalStats[$row['approval_status']] = (int)$row['count'];
}

// ---------- Count by event status ----------
$eventStats = [];
$eventStatsRes = $conn->query("
    SELECT status, COUNT(*) as count 
    FROM events 
    GROUP BY status
");
while ($row = $eventStatsRes->fetch_assoc()) {
    $eventStats[$row['status']] = (int)$row['count'];
}

// ---------- Pagination ----------
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ---------- Fetch rows (filtered page) ----------
$sql = "SELECT
            e.id, e.club_id, e.title, e.description, e.start_date, e.end_date,
            e.start_time, e.end_time, e.status, e.created_by, e.created_at,
            e.approval_status, e.rejection_reason,
            c.group_name
        FROM events e
        JOIN clubs c ON c.id = e.club_id
        {$where}
        ORDER BY e.start_date DESC, e.start_time DESC, e.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if ($types) {
    $typesWith = $types . 'ii';
    $bindVals  = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($typesWith, ...$bindVals);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();
$res  = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) $rows[] = $row;

// helper to keep query string
function qs(array $overrides = []) {
    $merged = array_merge($_GET, $overrides);
    return '?' . http_build_query($merged);
}

// small helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function statusBadgeClass($s){ 
    return match($s) {
        'completed' => 'success',
        'ongoing' => 'warning', 
        default => 'info' // upcoming
    };
}
function approvalBadgeClass($s){ 
    return $s==='approved' ? 'success' : ($s==='rejected' ? 'danger' : 'warning'); 
}
?>
<main class="main-content container-fluid">
    <style>
        /* Mobile stacked table */
        @media (max-width: 767.98px) {
            .table-stack thead { display: none; }
            .table-stack, .table-stack tbody, .table-stack tr, .table-stack td {
                display: block; width: 100%;
            }
            .table-stack tr {
                background: #fff; margin-bottom: .9rem; border-radius: 10px;
                border: 1px solid #e9ecef; box-shadow: 0 2px 8px rgba(0,0,0,.03);
                padding: .25rem .5rem;
            }
            .table-stack td {
                display: flex; justify-content: space-between; align-items: center;
                padding: .5rem .25rem; border: none !important;
                border-bottom: 1px dashed #eef2f6 !important;
            }
            .table-stack td:last-child { border-bottom: none !important; }
            .table-stack td::before {
                content: attr(data-label); font-weight: 600; margin-right: 1rem; color: #0f2f47;
                min-width: 80px; flex-shrink: 0;
            }
            .table-stack .text-end { text-align: left !important; }
            .table-stack .btn-group { justify-content: flex-end; width: 100%; }
        }
        
        /* Enhanced stat card */
        .stat-card {
            border: 0; border-radius: 12px; box-shadow: 0 4px 18px rgba(0,0,0,.08); 
            position: relative; overflow: hidden; transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .stat-card::before {
            content:''; position:absolute; top:0; left:0; right:0; height:4px;
        }
        .stat-card.total { background: linear-gradient(135deg, #1a5276, #154360); color: white; }
        .stat-card.total::before { background: linear-gradient(90deg, #28b463, #f39c12); }
        .stat-card.pending { background: linear-gradient(135deg, #ffc107, #e0a800); color: white; }
        .stat-card.pending::before { background: linear-gradient(90deg, #ffeb3b, #ffc107); }
        .stat-card.approved { background: linear-gradient(135deg, #28a745, #218838); color: white; }
        .stat-card.approved::before { background: linear-gradient(90deg, #4cd964, #28a745); }
        .stat-card.rejected { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .stat-card.rejected::before { background: linear-gradient(90deg, #ff6b6b, #dc3545); }
        
        .stat-card .card-body {
            padding: 1.25rem 1.5rem;
        }
        .stat-card .text-muted {
            color: rgba(255,255,255,0.8) !important;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .stat-card .h3 {
            font-size: 2.2rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .stat-card i {
            opacity: 0.9;
            filter: drop-shadow(0 2px 3px rgba(0,0,0,0.2));
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 575.98px) {
            .stat-card .card-body {
                padding: 1rem;
            }
            .stat-card .h3 {
                font-size: 1.8rem;
            }
            .stat-card i {
                font-size: 1.5rem !important;
            }
        }
        
        /* Filter form mobile improvements */
        @media (max-width: 991.98px) {
            .filter-form .col-lg-4,
            .filter-form .col-sm-6,
            .filter-form .col-lg-2,
            .filter-form .col-lg-3 {
                margin-bottom: 0.5rem;
            }
        }
        
        /* Club dropdown mobile optimization */
        .club-select-wrapper {
            position: relative;
        }
        @media (max-width: 575.98px) {
            .club-select-wrapper select {
                font-size: 0.9rem;
            }
            .club-select-wrapper .form-text {
                font-size: 0.8rem;
            }
        }
        
        /* Pagination mobile improvements */
        @media (max-width: 575.98px) {
            .pagination {
                justify-content: center !important;
            }
            .pagination .page-item .page-link {
                padding: 0.375rem 0.5rem;
                font-size: 0.85rem;
            }
        }

        /* Approval action buttons */
        .approve-btn:hover { background-color: #198754; border-color: #198754; color: white; }
        .reject-btn:hover { background-color: #dc3545; border-color: #dc3545; color: white; }
    </style>

    <!-- Top: Title + Stats Cards -->
    <div class="row g-3 align-items-stretch mb-4">
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card stat-card total h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted">Total Events</div>
                        <div class="h3 mb-0"><?= number_format($grandTotal) ?></div>
                        <small class="opacity-75">All events</small>
                    </div>
                    <i class="fa-regular fa-calendar fa-2x"></i>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card stat-card pending h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted">Pending</div>
                        <div class="h3 mb-0"><?= number_format($approvalStats['pending'] ?? 0) ?></div>
                        <small class="opacity-75">Awaiting approval</small>
                    </div>
                    <i class="fa-regular fa-clock fa-2x"></i>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card stat-card approved h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted">Approved</div>
                        <div class="h3 mb-0"><?= number_format($approvalStats['approved'] ?? 0) ?></div>
                        <small class="opacity-75">Public events</small>
                    </div>
                    <i class="fa-regular fa-circle-check fa-2x"></i>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card stat-card rejected h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted">Rejected</div>
                        <div class="h3 mb-0"><?= number_format($approvalStats['rejected'] ?? 0) ?></div>
                        <small class="opacity-75">Not approved</small>
                    </div>
                    <i class="fa-regular fa-circle-xmark fa-2x"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Status Stats -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card bg-info bg-opacity-10 border-info h-100">
                <div class="card-body text-center">
                    <div class="h3 text-info mb-1"><?= number_format($eventStats['upcoming'] ?? 0) ?></div>
                    <div class="text-muted">Upcoming Events</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card bg-warning bg-opacity-10 border-warning h-100">
                <div class="card-body text-center">
                    <div class="h3 text-warning mb-1"><?= number_format($eventStats['ongoing'] ?? 0) ?></div>
                    <div class="text-muted">Ongoing Events</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card bg-success bg-opacity-10 border-success h-100">
                <div class="card-body text-center">
                    <div class="h3 text-success mb-1"><?= number_format($eventStats['completed'] ?? 0) ?></div>
                    <div class="text-muted">Completed Events</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search / Filters -->
    <div class="row g-3 mt-1">
        <div class="col-12">
            <form class="card shadow-sm border-0 filter-form" method="get" action="">
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-lg-3">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" name="q" class="form-control"
                                       placeholder="Search title, description, club name, or ID"
                                       value="<?= h($q) ?>">
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-2">
                            <label class="form-label">Event Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="upcoming" <?= $status==='upcoming'?'selected':''; ?>>Upcoming</option>
                                <option value="ongoing" <?= $status==='ongoing'?'selected':''; ?>>Ongoing</option>
                                <option value="completed" <?= $status==='completed'?'selected':''; ?>>Completed</option>
                            </select>
                        </div>

                        <div class="col-sm-6 col-lg-2">
                            <label class="form-label">Approval</label>
                            <select name="approval_status" class="form-select">
                                <option value="">All Approval</option>
                                <option value="pending" <?= $approval_status==='pending'?'selected':''; ?>>Pending</option>
                                <option value="approved" <?= $approval_status==='approved'?'selected':''; ?>>Approved</option>
                                <option value="rejected" <?= $approval_status==='rejected'?'selected':''; ?>>Rejected</option>
                            </select>
                        </div>

                        <div class="col-sm-6 col-lg-2">
                            <label class="form-label">Club</label>
                            <div class="club-select-wrapper">
                                <select name="club_id" class="form-select">
                                    <option value="0">All Clubs</option>
                                    <?php foreach ($clubsOpts as $co): ?>
                                        <option value="<?= (int)$co['id'] ?>" <?= $club_id===(int)$co['id']?'selected':''; ?>>
                                            <?= h($co['group_name']) ?> (<?= (int)$co['event_count'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-1">
                            <label class="form-label">Per page</label>
                            <select name="per_page" class="form-select">
                                <?php foreach ([10,20,30,50,100] as $n): ?>
                                    <option value="<?= $n ?>" <?= $perPage===$n?'selected':''; ?>><?= $n ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-sm-6 col-lg-1">
                            <label class="form-label">Start (from)</label>
                            <input type="date" name="start_from" class="form-control" value="<?= h($start_from) ?>">
                        </div>

                        <div class="col-sm-6 col-lg-1">
                            <label class="form-label">Start (to)</label>
                            <input type="date" name="start_to" class="form-control" value="<?= h($start_to) ?>">
                        </div>

                        <div class="col-12 col-lg-2 text-end mt-2">
                            <button class="btn btn-success w-100">
                                <i class="fa-solid fa-filter me-1"></i> Apply
                            </button>
                            <?php if ($q || $status || $club_id || $start_from || $start_to || $approval_status): ?>
                                <a href="manage_events.php" class="btn btn-outline-secondary w-100 mt-1">
                                    <i class="fa-solid fa-refresh me-1"></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                    <div class="mb-2 mb-md-0">
                        <strong>Event Results</strong> 
                        <span class="text-muted">(<?= number_format($total) ?> events found)</span>
                    </div>
                    <div class="small text-muted d-flex align-items-center">
                        <i class="fa-solid fa-layer-group me-1"></i>
                        Page <?= $page ?> of <?= $totalPages ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Club</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>Approval</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="9" class="text-muted text-center py-5">
                                    <i class="fa-regular fa-calendar-xmark fa-2x mb-3 d-block"></i>
                                    No events found matching your criteria.
                                    <?php if ($q || $status || $club_id || $start_from || $start_to || $approval_status): ?>
                                        <div class="mt-2">
                                            <a href="manage_events.php" class="btn btn-sm btn-outline-primary">
                                                Clear filters
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td data-label="ID">
                                        <span class="badge bg-light text-dark">#<?= (int)$r['id'] ?></span>
                                    </td>
                                    <td data-label="Title" class="fw-semibold"><?= h($r['title']) ?></td>
                                    <td data-label="Club">
                                        <span class="badge bg-primary bg-opacity-10 text-primary">
                                            <?= h($r['group_name']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Start">
                                        <div class="fw-medium"><?= h(date('M j, Y', strtotime($r['start_date']))) ?></div>
                                        <?php if (!empty($r['start_time']) && $r['start_time'] !== '00:00:00'): ?>
                                            <div class="text-muted small"><?= h(date('g:i A', strtotime($r['start_time']))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="End">
                                        <div class="fw-medium"><?= h(date('M j, Y', strtotime($r['end_date']))) ?></div>
                                        <?php if (!empty($r['end_time']) && $r['end_time'] !== '00:00:00'): ?>
                                            <div class="text-muted small"><?= h(date('g:i A', strtotime($r['end_time']))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge bg-<?= statusBadgeClass($r['status']) ?>">
                                            <i class="fa-solid fa-<?= 
                                                $r['status']==='upcoming'?'clock':
                                                ($r['status']==='ongoing'?'play':'check')
                                            ?> me-1"></i>
                                            <?= h(ucfirst($r['status'])) ?>
                                        </span>
                                    </td>
                                    <td data-label="Approval">
                                        <span class="badge bg-<?= approvalBadgeClass($r['approval_status']) ?>">
                                            <?= h(ucfirst($r['approval_status'])) ?>
                                        </span>
                                        <?php if ($r['approval_status'] === 'rejected' && !empty($r['rejection_reason'])): ?>
                                            <small class="text-muted d-block mt-1" title="<?= h($r['rejection_reason']) ?>">
                                                <i class="fa-solid fa-comment"></i> Reason provided
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Created">
                                        <div class="small"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></div>
                                        <div class="text-muted small"><?= h(date('g:i A', strtotime($r['created_at']))) ?></div>
                                    </td>
                                    <td data-label="Actions" class="text-end">
                                        <div class="btn-group">
                                            <!-- View Button -->
                                            <a href="view_event.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="View">
                                                <i class="fa-regular fa-eye"></i>
                                                <span class="d-none d-md-inline ms-1">View</span>
                                            </a>
                                            
                                            <!-- Edit Button -->
                                            <a href="edit_event.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                                <span class="d-none d-md-inline ms-1">Edit</span>
                                            </a>
                                            
                                            <?php if ($r['approval_status'] === 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success approve-btn" 
                                                        data-event-id="<?= (int)$r['id'] ?>" title="Approve">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-warning reject-btn" 
                                                        data-event-id="<?= (int)$r['id'] ?>" title="Reject">
                                                    <i class="fa-solid fa-times"></i>
                                                </button>
                                            <?php elseif ($r['approval_status'] === 'approved'): ?>
                                                <span class="badge bg-success me-1">Approved</span>
                                                <button type="button" class="btn btn-sm btn-outline-warning reject-btn" 
                                                        data-event-id="<?= (int)$r['id'] ?>" title="Reject">
                                                    <i class="fa-solid fa-times"></i>
                                                </button>
                                            <?php elseif ($r['approval_status'] === 'rejected'): ?>
                                                <span class="badge bg-danger me-1">Rejected</span>
                                                <button type="button" class="btn btn-sm btn-outline-success approve-btn" 
                                                        data-event-id="<?= (int)$r['id'] ?>" title="Approve">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <!-- Delete Button -->
                                            <a href="delete_event.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this event? This action cannot be undone.');">
                                                <i class="fa-solid fa-trash"></i>
                                                <span class="d-none d-md-inline ms-1">Delete</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination mb-0 justify-content-end flex-wrap">
                            <?php
                            $window = 2;
                            $start = max(1, $page - $window);
                            $end   = min($totalPages, $page + $window);
                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>1])).'">&laquo;</a></li>';
                            }
                            if ($page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page-1])).'">&lsaquo;</a></li>';
                            }
                            for ($p = $start; $p <= $end; $p++) {
                                $active = $p === $page ? ' active' : '';
                                echo '<li class="page-item'.$active.'"><a class="page-link" href="'.h(qs(['page'=>$p])).'">'.$p.'</a></li>';
                            }
                            if ($page < $totalPages) {
                                echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page+1])).'">&rsaquo;</a></li>';
                            }
                            if ($end < $totalPages) {
                                echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$totalPages])).'">&raquo;</a></li>';
                            }
                            ?>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.approve-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const eventId = this.dataset.eventId;
            if (confirm('Approve this event? It will be visible in public events.')) {
                updateApprovalStatus(eventId, 'approved');
            }
        });
    });

    document.querySelectorAll('.reject-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const eventId = this.dataset.eventId;
            const reason = prompt('Please provide a reason for rejection (optional):') || '';
            updateApprovalStatus(eventId, 'rejected', reason);
        });
    });

    async function updateApprovalStatus(eventId, status, reason = '') {
        const formData = new FormData();
        formData.append('event_id', eventId);
        formData.append('approval_status', status);
        formData.append('reason', reason);
        formData.append('action', 'update_approval');

        try {
            const resp = await fetch('update_event_approval.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const raw = await resp.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (_) { /* ignore parse error */ }

            if (!resp.ok) {
                const msg = (data && data.message) ? data.message : (raw || (resp.status + ' ' + resp.statusText));
                throw new Error(msg);
            }

            // If JSON parsed and success flag present, honor it. Otherwise, assume success.
            if (data && typeof data.success !== 'undefined') {
                if (!data.success) throw new Error(data.message || 'Unknown error');
            }

            // Successful HTTP and (likely) DB updated — refresh UI
            location.reload();

        } catch (err) {
            console.error('Update approval failed:', err);
            alert('Error updating approval status:\n' + (err && err.message ? err.message : err));
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>