<?php
// admin/manage_projects.php - Admin approval system for activities
require_once __DIR__ . '/header.php'; // enforces admin auth and opens $conn

$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$club_id  = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$approval = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';
$year     = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$month    = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 10)));

$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($q !== '') {
    $where .= " AND (p.project_name LIKE ? OR p.description LIKE ? OR p.objectives LIKE ? OR c.group_name LIKE ? OR CAST(p.id AS CHAR) LIKE ?) ";
    $like = "%{$q}%";
    array_push($params, $like,$like,$like,$like,$like);
    $types .= 'sssss';
}
if ($club_id > 0) {
    $where .= " AND p.club_id = ? ";
    $params[] = $club_id;
    $types   .= 'i';
}
if ($approval !== '' && in_array($approval, ['pending','approved','rejected'], true)) {
    $where .= " AND p.approval_status = ? ";
    $params[] = $approval;
    $types   .= 's';
}
if ($year > 0) {
    $where .= " AND YEAR(p.start_date) = ? ";
    $params[] = $year;
    $types   .= 'i';
}
if ($month >= 1 && $month <= 12) {
    $where .= " AND MONTH(p.start_date) = ? ";
    $params[] = $month;
    $types   .= 'i';
}

// Clubs for filter
$clubs = [];
$cq = $conn->query("
    SELECT c.id, c.group_name, COUNT(p.id) AS cnt
    FROM clubs c
    LEFT JOIN projects p ON p.club_id = c.id
    GROUP BY c.id, c.group_name
    ORDER BY c.group_name ASC
");
while ($row = $cq->fetch_assoc()) {
    $row['cnt'] = (int)$row['cnt'];
    $clubs[] = $row;
}

// Counts
$gt = (int)($conn->query("SELECT COUNT(*) AS c FROM projects")->fetch_assoc()['c'] ?? 0);
$byApproval = ['pending'=>0,'approved'=>0,'rejected'=>0];
$appr = $conn->query("SELECT approval_status, COUNT(*) c FROM projects GROUP BY approval_status");
while ($r = $appr->fetch_assoc()) $byApproval[$r['approval_status']] = (int)$r['c'];

// Pagination
$stmtC = $conn->prepare("SELECT COUNT(*) AS c FROM projects p JOIN clubs c ON c.id=p.club_id {$where}");
if ($types) $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0);

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch page
$sql = "SELECT p.id, p.club_id, p.project_name, p.objectives, p.description, p.start_date, p.end_date,
               p.status, p.approval_status, p.rejection_reason, p.created_at,
               c.group_name, u.email as creator_email
        FROM projects p
        JOIN clubs c ON c.id = p.club_id
        LEFT JOIN users u ON u.id = p.created_by
        {$where}
        ORDER BY p.approval_status, p.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types.'ii', ...array_merge($params, [$perPage,$offset]));
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) $rows[] = $row;

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qs(array $o=[]){ return '?'.http_build_query(array_merge($_GET,$o)); }
function apprClass($s){ return $s==='approved'?'success':($s==='rejected'?'danger':'warning'); }
function statusClass($s){ 
    switch($s) {
        case 'completed': return 'success';
        case 'ongoing': 
        default: return 'primary';
    }
}
?>
<main class="main-content container-fluid">
    <style>
        .stat-card{border:0;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.08);overflow:hidden}
        .stat-card.total{background:linear-gradient(135deg,#1a5276,#154360);color:#fff}
        .stat-card.pending{background:linear-gradient(135deg,#ffc107,#e0a800);color:#fff}
        .stat-card.approved{background:linear-gradient(135deg,#28a745,#218838);color:#fff}
        .stat-card.rejected{background:linear-gradient(135deg,#dc3545,#c82333);color:#fff}
        
        /* Custom Modal Styles */
        .confirmation-modal .modal-header {
            background: linear-gradient(135deg, #1a5276, #154360);
            color: white;
        }
        .confirmation-modal.approve .modal-header {
            background: linear-gradient(135deg, #28a745, #218838);
        }
        .confirmation-modal.reject .modal-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }
        
        /* Action buttons styling */
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
        }
        
        /* Mobile responsive table */
        @media(max-width:768px){
            .table-stack thead{display:none}
            .table-stack,.table-stack tbody,.table-stack tr,.table-stack td{display:block;width:100%}
            .table-stack tr{background:#fff;margin-bottom:.9rem;border-radius:10px;border:1px solid #e9ecef;box-shadow:0 2px 8px rgba(0,0,0,.03);padding:.25rem .5rem}
            .table-stack td{display:flex;justify-content:space-between;align-items:center;padding:.5rem .25rem;border:none!important;border-bottom:1px dashed #eef2f6!important}
            .table-stack td:last-child{border-bottom:none!important}
            .table-stack td::before{content:attr(data-label);font-weight:600;margin-right:1rem;color:#0f2f47;min-width:80px;flex-shrink:0}
            
            /* Mobile action buttons */
            .table-stack .btn-group {
                justify-content: flex-end;
                width: 100%;
                flex-wrap: wrap;
            }
            .table-stack .btn-group .btn {
                flex: 1;
                min-width: 0;
                margin: 2px;
            }
        }
    </style>

    <!-- Confirmation Modals -->
    <div class="modal fade confirmation-modal" id="approveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-check-circle me-2"></i>Confirm Approval</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to <strong class="text-success">approve</strong> this activity?</p>
                    <p class="text-muted small mb-0">This activity will be visible to all users.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmApproveBtn">Yes, Approve</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade confirmation-modal reject" id="rejectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-times-circle me-2"></i>Confirm Rejection</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to <strong class="text-danger">reject</strong> this activity?</p>
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Reason for rejection (optional):</label>
                        <textarea class="form-control" id="rejectionReason" rows="3" placeholder="Provide a reason for rejection..."></textarea>
                    </div>
                    <p class="text-muted small mb-0">The user will be able to see this reason and may resubmit.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">Yes, Reject</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-3"><div class="card stat-card total"><div class="card-body"><div>Total Activities</div><div class="h3 mb-0"><?= number_format($gt) ?></div></div></div></div>
        <div class="col-12 col-md-3"><div class="card stat-card pending"><div class="card-body"><div>Pending</div><div class="h3 mb-0"><?= number_format($byApproval['pending'] ?? 0) ?></div></div></div></div>
        <div class="col-12 col-md-3"><div class="card stat-card approved"><div class="card-body"><div>Approved</div><div class="h3 mb-0"><?= number_format($byApproval['approved'] ?? 0) ?></div></div></div></div>
        <div class="col-12 col-md-3"><div class="card stat-card rejected"><div class="card-body"><div>Rejected</div><div class="h3 mb-0"><?= number_format($byApproval['rejected'] ?? 0) ?></div></div></div></div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <form class="card-body row g-2">
            <div class="col-lg-3">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Title, desc, club, or ID">
            </div>
            <div class="col-lg-2">
                <label class="form-label">Club</label>
                <select name="club_id" class="form-select">
                    <option value="0">All Clubs</option>
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $club_id===(int)$c['id']?'selected':''; ?>>
                            <?= h($c['group_name']) ?> (<?= (int)$c['cnt'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">Approval</label>
                <select name="approval_status" class="form-select">
                    <option value="">All</option>
                    <option value="pending"  <?= $approval==='pending'?'selected':''; ?>>Pending</option>
                    <option value="approved" <?= $approval==='approved'?'selected':''; ?>>Approved</option>
                    <option value="rejected" <?= $approval==='rejected'?'selected':''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">Year</label>
                <input type="number" name="year" class="form-control" value="<?= $year ?: '' ?>" placeholder="YYYY">
            </div>
            <div class="col-lg-1">
                <label class="form-label">Month</label>
                <input type="number" name="month" class="form-control" min="1" max="12" value="<?= $month ?: '' ?>">
            </div>
            <div class="col-lg-2 d-flex align-items-end">
                <button class="btn btn-success w-100"><i class="fa-solid fa-filter me-1"></i> Apply</button>
            </div>
        </form>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between">
            <div><strong>Activity Results</strong> <span class="text-muted">(<?= number_format($total) ?> found)</span></div>
            <div class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle table-stack mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th><th>Title</th><th>Club</th><th>Status</th><th>Dates</th>
                        <th>Approval</th><th>Created</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">No activities found.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td data-label="ID"><span class="badge bg-light text-dark">#<?= (int)$r['id'] ?></span></td>
                        <td data-label="Title" class="fw-semibold"><?= h($r['project_name']) ?></td>
                        <td data-label="Club"><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($r['group_name']) ?></span></td>
                        <td data-label="Status">
                            <span class="badge bg-<?= statusClass($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span>
                        </td>
                        <td data-label="Dates">
                            <div class="small"><?= h($r['start_date'] ? date('M j, Y', strtotime($r['start_date'])) : '—') ?></div>
                            <div class="text-muted small"><?= h($r['end_date'] ? date('M j, Y', strtotime($r['end_date'])) : '—') ?></div>
                        </td>
                        <td data-label="Approval">
                            <span class="badge bg-<?= apprClass($r['approval_status']) ?>"><?= h(ucfirst($r['approval_status'])) ?></span>
                            <?php if ($r['approval_status']==='rejected' && !empty($r['rejection_reason'])): ?>
                                <div class="small text-muted mt-1"><i class="fa-solid fa-comment"></i> Reason provided</div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Created">
                            <div class="small"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></div>
                            <?php if ($r['creator_email']): ?>
                                <div class="text-muted small">by <?= h($r['creator_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions" class="text-end">
                            <div class="btn-group">
                                <!-- View Button -->
                                <a href="view_project.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="fa-regular fa-eye"></i>
                                    <span class="d-none d-md-inline ms-1">View</span>
                                </a>
                                
                                <!-- Edit Button -->
                                <a href="edit_project.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                    <span class="d-none d-md-inline ms-1">Edit</span>
                                </a>

                                <!-- Approval/Rejection Buttons -->
                                <?php if ($r['approval_status']==='pending'): ?>
                                    <button class="btn btn-sm btn-outline-success appr-btn" data-id="<?= (int)$r['id'] ?>" data-title="<?= h($r['project_name']) ?>" title="Approve">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning rej-btn" data-id="<?= (int)$r['id'] ?>" data-title="<?= h($r['project_name']) ?>" title="Reject">
                                        <i class="fa-solid fa-times"></i>
                                    </button>
                                <?php elseif ($r['approval_status']==='approved'): ?>
                                    <span class="badge bg-success me-1">Approved</span>
                                    <button class="btn btn-sm btn-outline-warning rej-btn" data-id="<?= (int)$r['id'] ?>" data-title="<?= h($r['project_name']) ?>" title="Reject">
                                        <i class="fa-solid fa-times"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="badge bg-danger me-1">Rejected</span>
                                    <button class="btn btn-sm btn-outline-success appr-btn" data-id="<?= (int)$r['id'] ?>" data-title="<?= h($r['project_name']) ?>" title="Approve">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <!-- Delete Button -->
                                <a href="delete_project.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger js-delete-confirm" title="Delete"
                                   data-delete-title="Delete Activity"
                                   data-delete-message="Are you sure you want to delete this activity?"
                                   data-delete-item="<?= h($r['project_name'] ?? ('Activity #' . (int)$r['id'])) ?>"
                                   data-delete-confirm-label="<i class='fa-solid fa-trash me-1'></i> Delete Activity">
                                    <i class="fa-solid fa-trash"></i>
                                    <span class="d-none d-md-inline ms-1">Delete</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white">
            <ul class="pagination justify-content-end mb-0">
                <?php
                $window=2; $start=max(1,$page-$window); $end=min($totalPages,$page+$window);
                if ($start>1) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>1])).'">&laquo;</a></li>';
                if ($page>1)  echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page-1])).'">&lsaquo;</a></li>';
                for ($p=$start;$p<=$end;$p++){
                    $active=$p===$page?' active':''; echo '<li class="page-item'.$active.'"><a class="page-link" href="'.h(qs(['page'=>$p])).'">'.$p.'</a></li>';
                }
                if ($page<$totalPages) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page+1])).'">&rsaquo;</a></li>';
                if ($end<$totalPages)   echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$totalPages])).'">&raquo;</a></li>';
                ?>
            </ul>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let currentActivityId = null;
    let currentActivityTitle = null;
    
    const approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
    const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    
    // Approve button handlers
    document.querySelectorAll('.appr-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            currentActivityId = btn.dataset.id;
            currentActivityTitle = btn.dataset.title;
            
            // Update modal content with activity title
            const modalBody = document.querySelector('#approveModal .modal-body p:first-child');
            if (currentActivityTitle) {
                modalBody.innerHTML = `Are you sure you want to <strong class="text-success">approve</strong> the activity: <br><strong>"${currentActivityTitle}"</strong>?`;
            }
            
            approveModal.show();
        });
    });
    
    // Reject button handlers
    document.querySelectorAll('.rej-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            currentActivityId = btn.dataset.id;
            currentActivityTitle = btn.dataset.title;
            
            // Update modal content with activity title
            const modalBody = document.querySelector('#rejectModal .modal-body p:first-child');
            if (currentActivityTitle) {
                modalBody.innerHTML = `Are you sure you want to <strong class="text-danger">reject</strong> the activity: <br><strong>"${currentActivityTitle}"</strong>?`;
            }
            
            // Clear previous reason
            document.getElementById('rejectionReason').value = '';
            rejectModal.show();
        });
    });
    
    // Confirm approve action
    document.getElementById('confirmApproveBtn').addEventListener('click', () => {
        if (currentActivityId) {
            callAPI(currentActivityId, 'approved', '');
            approveModal.hide();
        }
    });
    
    // Confirm reject action
    document.getElementById('confirmRejectBtn').addEventListener('click', () => {
        if (currentActivityId) {
            const reason = document.getElementById('rejectionReason').value || '';
            callAPI(currentActivityId, 'rejected', reason);
            rejectModal.hide();
        }
    });
    
    async function callAPI(id, status, reason='') {
        const fd = new FormData();
        fd.append('activity_id', id);
        fd.append('approval_status', status);
        fd.append('reason', reason);

        try {
            const resp = await fetch('update_activity_approval.php', { 
                method:'POST', 
                body: fd, 
                credentials:'same-origin' 
            });
            
            const raw  = await resp.text();
            let data = null; 
            try { 
                data = JSON.parse(raw); 
            } catch(e){}
            
            if (!resp.ok) {
                const msg = (data && data.message) ? data.message : (raw || (resp.status+' '+resp.statusText));
                throw new Error(msg);
            }
            
            if (data && data.success === false) {
                throw new Error(data.message || 'Unknown error');
            }
            
            location.reload();
            
        } catch (err) {
            console.error(err);
            alert('Error updating approval:\n' + (err && err.message ? err.message : err));
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
