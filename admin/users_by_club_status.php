<?php
require_once __DIR__ . '/header.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qs($overrides = []) {
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $k => $v) if ($v === '' || $v === null) unset($merged[$k]);
    return '?' . http_build_query($merged);
}
function badgeStatus($s) {
    $s = strtolower(trim((string)$s));
    $map = ['approved'=>'success','pending'=>'warning text-dark','rejected'=>'danger'];
    return '<span class="badge bg-'.($map[$s] ?? 'secondary').'">'.h($s ? ucfirst($s) : 'Unknown').'</span>';
}
function badgeCluster($v) {
    $v = trim((string)$v);
    $map = ['Zero Poverty'=>'danger','Zero Unemployment'=>'primary','Zero Net Carbon Emissions'=>'success'];
    return '<span class="badge rounded-pill bg-'.($map[$v] ?? 'secondary').'">'.h($v ?: 'Unknown').'</span>';
}
function badgeFocus($v) {
    $v = trim((string)$v);
    return '<span class="badge rounded-pill bg-light text-dark border">'.h($v ?: 'Unknown').'</span>';
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
function norm_identity($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/\s+/', ' ', $value);
}
function count_unique_membership_people($conn) {
    $res = $conn->query("SELECT email, full_name FROM club_members WHERE (email IS NOT NULL AND TRIM(email) <> '') OR (full_name IS NOT NULL AND TRIM(full_name) <> '')");
    if (!$res) return 0;
    $parent = [];
    $rank = [];
    $nextId = 0;
    $tokenToId = [];
    $find = function($x) use (&$parent, &$find) {
        if ($parent[$x] !== $x) $parent[$x] = $find($parent[$x]);
        return $parent[$x];
    };
    $union = function($a, $b) use (&$parent, &$rank, $find) {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra === $rb) return;
        if ($rank[$ra] < $rank[$rb]) {
            $parent[$ra] = $rb;
        } elseif ($rank[$ra] > $rank[$rb]) {
            $parent[$rb] = $ra;
        } else {
            $parent[$rb] = $ra;
            $rank[$ra]++;
        }
    };
    while ($row = $res->fetch_assoc()) {
        $tokens = [];
        $email = norm_identity($row['email'] ?? '');
        $name = norm_identity($row['full_name'] ?? '');
        if ($email !== '') $tokens[] = 'email:' . $email;
        if ($name !== '') $tokens[] = 'name:' . $name;
        if (!$tokens) continue;
        $ids = [];
        foreach ($tokens as $token) {
            if (!isset($tokenToId[$token])) {
                $tokenToId[$token] = $nextId;
                $parent[$nextId] = $nextId;
                $rank[$nextId] = 0;
                $nextId++;
            }
            $ids[] = $tokenToId[$token];
        }
        for ($i = 1; $i < count($ids); $i++) $union($ids[0], $ids[$i]);
    }
    $roots = [];
    foreach ($parent as $id => $_) $roots[$find($id)] = true;
    return count($roots);
}
function column_exists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS value FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $res = bind_exec($stmt, 'ss', [$table, $column]);
    $row = $res->fetch_assoc();
    return (int)($row['value'] ?? 0) > 0;
}
function distinct_values($conn, $table, $column) {
    if (!in_array($table, ['users','clubs'], true)) return [];
    if (!in_array($column, ['department','program_of_study','country','cluster','focus_area'], true)) return [];
    if (!column_exists($conn, $table, $column)) return [];
    $items = [];
    $sql = "SELECT DISTINCT {$column} AS val FROM {$table}
            WHERE {$column} IS NOT NULL AND TRIM({$column}) <> ''
            ORDER BY val ASC";
    $res = $conn->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $items[] = $row['val'];
    return $items;
}
function sort_link($field, $label, $sort, $order) {
    $next = ($sort === $field && $order === 'asc') ? 'desc' : 'asc';
    $icon = $sort === $field ? ($order === 'asc' ? ' <i class="fa-solid fa-arrow-up"></i>' : ' <i class="fa-solid fa-arrow-down"></i>') : '';
    return '<a class="sort-link" href="'.h(qs(['sort'=>$field,'order'=>$next,'page'=>1])).'">'.h($label).$icon.'</a>';
}

$hasFocus = column_exists($conn, 'clubs', 'focus_area');
$focusSelect = $hasFocus ? 'c.focus_area' : "NULL";

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$cluster = trim($_GET['cluster'] ?? '');
$focus = trim($_GET['focus_area'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10,20,50,100], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$detailId = max(0, (int)($_GET['details_id'] ?? 0));

$sortMap = [
    'id'=>'u.id',
    'name'=>'u.name',
    'email'=>'u.email',
    'department'=>'u.department',
    'program'=>'u.program_of_study',
    'total'=>'total_clubs',
    'latest'=>'latest_registration',
];
$sort = trim($_GET['sort'] ?? 'total');
if (!isset($sortMap[$sort])) $sort = 'total';
$order = strtolower(trim($_GET['order'] ?? 'desc'));
if (!in_array($order, ['asc','desc'], true)) $order = 'desc';

$memberJoin = "LEFT JOIN club_members cm ON (
                    (
                        u.email IS NOT NULL
                        AND cm.email IS NOT NULL
                        AND TRIM(u.email) <> ''
                        AND TRIM(cm.email) <> ''
                        AND LOWER(TRIM(u.email)) = LOWER(TRIM(cm.email))
                    )
                    OR
                    (
                        u.name IS NOT NULL
                        AND cm.full_name IS NOT NULL
                        AND TRIM(u.name) <> ''
                        AND TRIM(cm.full_name) <> ''
                        AND LOWER(TRIM(u.name)) = LOWER(TRIM(cm.full_name))
                    )
               )
               LEFT JOIN clubs c ON c.id = cm.club_id";
$where = " WHERE 1=1 ";
$having = "";
$params = [];
$types = '';
$clubFiltered = false;

if ($q !== '') {
    $where .= " AND (CAST(u.id AS CHAR) LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.program_of_study LIKE ? OR u.country LIKE ? OR cm.student_id LIKE ?) ";
    $like = "%{$q}%";
    array_push($params, $like,$like,$like,$like,$like,$like,$like);
    $types .= 'sssssss';
}
if ($status !== '' && in_array($status, ['approved','pending','rejected'], true)) {
    $where .= " AND c.status = ? ";
    $params[] = $status; $types .= 's'; $clubFiltered = true;
}
if ($cluster !== '') {
    $where .= " AND c.cluster = ? ";
    $params[] = $cluster; $types .= 's'; $clubFiltered = true;
}
if ($focus !== '' && $hasFocus) {
    if ($focus === '__missing') {
        $where .= " AND (c.focus_area IS NULL OR TRIM(c.focus_area) = '') ";
    } else {
        $where .= " AND c.focus_area = ? ";
        $params[] = $focus; $types .= 's';
    }
    $clubFiltered = true;
}
if ($dateFrom !== '') {
    $where .= " AND c.date_of_registration >= ? ";
    $params[] = $dateFrom; $types .= 's'; $clubFiltered = true;
}
if ($dateTo !== '') {
    $where .= " AND c.date_of_registration <= ? ";
    $params[] = $dateTo; $types .= 's'; $clubFiltered = true;
}
if ($clubFiltered) $having = " HAVING total_clubs > 0 ";

$groupSql = " FROM users u {$memberJoin} {$where}
              GROUP BY u.id, u.name, u.email, u.department, u.program_of_study, u.intake, u.country, u.area_of_interest ";

$countSql = "SELECT COUNT(*) AS value FROM (
    SELECT u.id, COUNT(DISTINCT c.id) AS total_clubs {$groupSql} {$having}
) x";
$total = scalar($conn, $countSql, $types, $params);

$totalUsers = scalar($conn, "SELECT COUNT(*) AS value FROM users");
$usersWithClubs = count_unique_membership_people($conn);
$totalRegs = scalar($conn, "SELECT COUNT(*) AS value FROM club_members");
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT
            u.id, u.name, u.email, u.department, u.program_of_study, u.intake, u.country, u.area_of_interest,
            COUNT(DISTINCT c.id) AS total_clubs,
            COUNT(DISTINCT CASE WHEN c.status='approved' THEN c.id END) AS approved_clubs,
            COUNT(DISTINCT CASE WHEN c.status='pending' THEN c.id END) AS pending_clubs,
            COUNT(DISTINCT CASE WHEN c.status='rejected' THEN c.id END) AS rejected_clubs,
            MAX(c.date_of_registration) AS latest_registration
        {$groupSql}
        {$having}
        ORDER BY {$sortMap[$sort]} ".strtoupper($order).", u.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$rowTypes = $types . 'ii';
$rowParams = array_merge($params, [$perPage, $offset]);
$res = bind_exec($stmt, $rowTypes, $rowParams);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

$detailUser = null;
$detailClubs = [];
if ($detailId > 0) {
    $st = $conn->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
    $dr = bind_exec($st, 'i', [$detailId]);
    $detailUser = $dr->fetch_assoc();
    if ($detailUser) {
        $detailSql = "SELECT c.id, c.group_name, c.club_identifier, c.cluster, {$focusSelect} AS focus_area, c.status,
                             c.cluster_advisor, c.date_of_registration, c.key_person_name, c.deputy_key_person_name,
                             cm.member_type
                      FROM club_members cm
                      JOIN clubs c ON c.id = cm.club_id
                      WHERE LOWER(TRIM(cm.email)) = LOWER(TRIM(?))
                      ORDER BY c.date_of_registration DESC, c.id DESC";
        $ds = $conn->prepare($detailSql);
        $dres = bind_exec($ds, 's', [$detailUser['email']]);
        while ($r = $dres->fetch_assoc()) $detailClubs[] = $r;
    }
}

$departments = distinct_values($conn, 'users', 'department');
$programs = distinct_values($conn, 'users', 'program_of_study');
$countries = distinct_values($conn, 'users', 'country');
$clusters = distinct_values($conn, 'clubs', 'cluster');
$focusAreas = $hasFocus ? distinct_values($conn, 'clubs', 'focus_area') : [];
?>
<main class="main-content container-fluid">
<style>
.stat-card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.08);position:relative;overflow:hidden}.stat-card:before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:#1a5276}.sort-link{color:#1f2937;text-decoration:none;font-weight:700}.filter-card,.result-card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.07)}@media(max-width:767.98px){.table-stack thead{display:none}.table-stack,.table-stack tbody,.table-stack tr,.table-stack td{display:block;width:100%}.table-stack tr{background:#fff;margin-bottom:.9rem;border:1px solid #e9ecef;border-radius:10px;padding:.35rem .6rem}.table-stack td{display:flex;justify-content:space-between;gap:1rem;border:none!important;border-bottom:1px dashed #eef2f6!important;text-align:right}.table-stack td:before{content:attr(data-label);font-weight:700;color:#0f2f47;text-align:left}.table-stack td:last-child{border-bottom:none!important}}
</style>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h2 class="mb-1">Users by Club Status</h2><div class="text-muted">User participation and club registration status overview.</div></div>
</div>

<?php if ($detailUser): ?>
<div class="card result-card mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Club Details for <?= h($detailUser['name']) ?></strong>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(qs(['details_id'=>null])) ?>">Close</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Club ID</th><th>Club Name</th><th>Identifier</th><th>Cluster</th><th>Focus Area</th><th>Status</th><th>Advisor</th><th>Registration Date</th><th>Key Person</th><th>Deputy</th></tr></thead>
            <tbody>
            <?php if (!$detailClubs): ?><tr><td colspan="10" class="text-center text-muted py-4">No club records found.</td></tr><?php endif; ?>
            <?php foreach ($detailClubs as $c): ?>
                <tr>
                    <td data-label="Club ID"><?= (int)$c['id'] ?></td>
                    <td data-label="Club Name"><?= h($c['group_name']) ?></td>
                    <td data-label="Identifier"><code><?= h($c['club_identifier'] ?: 'Unknown') ?></code></td>
                    <td data-label="Cluster"><?= badgeCluster($c['cluster']) ?></td>
                    <td data-label="Focus Area"><?= badgeFocus($c['focus_area']) ?></td>
                    <td data-label="Status"><?= badgeStatus($c['status']) ?></td>
                    <td data-label="Advisor"><?= h($c['cluster_advisor']) ?></td>
                    <td data-label="Registration Date"><?= h($c['date_of_registration']) ?></td>
                    <td data-label="Key Person"><?= h($c['key_person_name']) ?></td>
                    <td data-label="Deputy"><?= h($c['deputy_key_person_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
<?php foreach ([['Total Users',$totalUsers,'fa-users'],['Users with Clubs',$usersWithClubs,'fa-user-check'],['Total Membership Records',$totalRegs,'fa-id-card']] as $card): ?>
    <div class="col-12 col-sm-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small fw-bold text-uppercase"><?= h($card[0]) ?></div><div class="h4 mb-0"><?= number_format($card[1]) ?></div></div><i class="fa-solid <?= h($card[2]) ?> fa-2x text-primary"></i></div></div></div>
    <?php endforeach; ?>
</div>

<form class="card filter-card mb-3" method="get">
    <input type="hidden" name="page" value="1"><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="order" value="<?= h($order) ?>">
    <div class="card-body"><div class="row g-3 align-items-end">
        <div class="col-12 col-xl-3"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name, email, student ID, department, program, country"></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option><?php foreach(['approved','pending','rejected'] as $v): ?><option value="<?= $v ?>" <?= $status===$v?'selected':'' ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">Cluster</label><select class="form-select" name="cluster"><option value="">All</option><?php foreach($clusters as $v): ?><option <?= $cluster===$v?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label">Focus Area</label><select class="form-select" name="focus_area"><option value="">All</option><?php foreach($focusAreas as $v): ?><option <?= $focus===$v?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?><option value="__missing" <?= $focus==='__missing'?'selected':'' ?>>Unknown / Missing</option></select></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label">From</label><input type="date" class="form-control" name="date_from" value="<?= h($dateFrom) ?>"></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label">To</label><input type="date" class="form-control" name="date_to" value="<?= h($dateTo) ?>"></div>
        <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Per Page</label><select class="form-select" name="per_page"><?php foreach([10,20,50,100] as $n): ?><option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-xl-3">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary flex-fill"><i class="fa-solid fa-filter me-1"></i>Apply</button>
                <a href="users_by_club_status.php" class="btn btn-light flex-fill"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a>
                <a href="export_users_by_club_status_csv.php?<?= h(http_build_query(array_diff_key($_GET, ['page' => true]))) ?>" class="btn btn-success flex-fill"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>
            </div>
        </div>
    </div></div>
</form>

<div class="card result-card">
    <div class="card-header bg-white d-flex justify-content-between flex-wrap gap-2"><strong>Results <span class="text-muted">(<?= number_format($total) ?>)</span></strong><span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?></span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 table-stack">
        <thead class="table-light"><tr><th><?= sort_link('id','User ID',$sort,$order) ?></th><th><?= sort_link('name','Name',$sort,$order) ?></th><th><?= sort_link('email','Email',$sort,$order) ?></th><th><?= sort_link('department','Department',$sort,$order) ?></th><th><?= sort_link('program','Program',$sort,$order) ?></th><th>Intake</th><th>Country</th><th>Area of Interest</th><th><?= sort_link('total','Total Clubs',$sort,$order) ?></th><th>Approved</th><th>Pending</th><th>Rejected</th><th><?= sort_link('latest','Latest Registration',$sort,$order) ?></th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="14" class="text-muted text-center py-4">No users found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td data-label="User ID"><?= (int)$r['id'] ?></td><td data-label="Name" class="fw-semibold"><?= h($r['name']) ?></td><td data-label="Email"><?= h($r['email']) ?></td><td data-label="Department"><?= h($r['department'] ?: 'Unknown') ?></td><td data-label="Program"><?= h($r['program_of_study'] ?: 'Unknown') ?></td><td data-label="Intake"><?= h($r['intake'] ?: 'Unknown') ?></td><td data-label="Country"><?= h($r['country'] ?: 'Unknown') ?></td><td data-label="Area"><?= h($r['area_of_interest'] ?: 'Unknown') ?></td><td data-label="Total Clubs"><span class="badge bg-dark"><?= (int)$r['total_clubs'] ?></span></td><td data-label="Approved"><span class="badge bg-success"><?= (int)$r['approved_clubs'] ?></span></td><td data-label="Pending"><span class="badge bg-warning text-dark"><?= (int)$r['pending_clubs'] ?></span></td><td data-label="Rejected"><span class="badge bg-danger"><?= (int)$r['rejected_clubs'] ?></span></td><td data-label="Latest"><?= h($r['latest_registration'] ?: 'None') ?></td><td data-label="Action"><a class="btn btn-sm btn-outline-primary" href="<?= h(qs(['details_id'=>(int)$r['id']])) ?>"><i class="fa-regular fa-eye me-1"></i>Details</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="card-footer bg-white"><nav><ul class="pagination mb-0 justify-content-end flex-wrap"><?php $start=max(1,$page-2);$end=min($totalPages,$page+2); if($page>1) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page-1])).'">&lsaquo;</a></li>'; for($p=$start;$p<=$end;$p++) echo '<li class="page-item '.($p===$page?'active':'').'"><a class="page-link" href="'.h(qs(['page'=>$p])).'">'.$p.'</a></li>'; if($page<$totalPages) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page+1])).'">&rsaquo;</a></li>'; ?></ul></nav></div>
</div>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
