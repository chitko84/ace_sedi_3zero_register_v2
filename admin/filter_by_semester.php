<?php
require_once __DIR__ . '/header.php';

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
function norm_identity($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/\s+/', ' ', $value);
}
function count_unique_membership_people_from_result($res) {
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
function count_unique_membership_people($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    $res = bind_exec($stmt, $types, $params);
    return count_unique_membership_people_from_result($res);
}
function distinct_values($conn, $table, $column) {
    $allowed = [
        'club_members' => ['current_semester','member_type','programme','school_centre','nationality'],
        'clubs' => ['cluster','focus_area','status'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) return [];
    $values = [];
    $sql = "SELECT DISTINCT {$column} AS val FROM {$table}
            WHERE {$column} IS NOT NULL AND TRIM({$column}) <> ''
            ORDER BY val ASC";
    $res = $conn->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $values[] = $row['val'];
    return $values;
}
function sort_link($field, $label, $sort, $order) {
    $next = ($sort === $field && $order === 'asc') ? 'desc' : 'asc';
    $icon = $sort === $field ? ($order === 'asc' ? ' <i class="fa-solid fa-arrow-up"></i>' : ' <i class="fa-solid fa-arrow-down"></i>') : '';
    return '<a class="sort-link" href="'.h(qs(['sort'=>$field,'order'=>$next,'page'=>1])).'">'.h($label).$icon.'</a>';
}
function badge_status($s) {
    $s = strtolower(trim((string)$s));
    $cls = $s === 'approved' ? 'success' : ($s === 'rejected' ? 'danger' : 'warning text-dark');
    return '<span class="badge bg-'.$cls.'">'.h(ucfirst($s ?: 'unknown')).'</span>';
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
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10,20,50,100], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$sortMap = [
    'member_id'=>'cm.id',
    'club_id'=>'c.id',
    'club_name'=>'c.group_name',
    'full_name'=>'cm.full_name',
    'student_id'=>'cm.student_id',
    'programme'=>'cm.programme',
    'semester'=>'cm.current_semester',
    'member_type'=>'cm.member_type',
    'created'=>'cm.created_at',
];
$sort = trim($_GET['sort'] ?? 'created');
if (!isset($sortMap[$sort])) $sort = 'created';
$order = strtolower(trim($_GET['order'] ?? 'desc'));
if (!in_array($order, ['asc','desc'], true)) $order = 'desc';

$where = " WHERE 1=1 ";
$params = [];
$types = '';
if ($q !== '') {
    $where .= " AND (cm.full_name LIKE ? OR cm.email LIKE ? OR cm.student_id LIKE ? OR c.group_name LIKE ? OR c.club_identifier LIKE ?) ";
    $like = "%{$q}%";
    array_push($params, $like,$like,$like,$like,$like);
    $types .= 'sssss';
}
$filters = [
    'cm.current_semester' => $semester,
    'cm.member_type' => $memberType,
    'cm.programme' => $programme,
    'cm.school_centre' => $school,
    'cm.nationality' => $nationality,
    'c.cluster' => $cluster,
    'c.focus_area' => $focusArea,
    'c.status' => $clubStatus,
];
foreach ($filters as $col => $value) {
    if ($value !== '') {
        $where .= " AND {$col} = ? ";
        $params[] = $value;
        $types .= 's';
    }
}

$fromSql = " FROM club_members cm LEFT JOIN clubs c ON c.id = cm.club_id {$where} ";
$usersWithMemberships = count_unique_membership_people($conn, "SELECT email, full_name FROM club_members WHERE (email IS NOT NULL AND TRIM(email) <> '') OR (full_name IS NOT NULL AND TRIM(full_name) <> '')");
$totalMembershipRecords = scalar($conn, "SELECT COUNT(*) AS value FROM club_members");
$total = scalar($conn, "SELECT COUNT(*) AS value {$fromSql}", $types, $params);
$filteredUniqueMembers = count_unique_membership_people($conn, "SELECT cm.email, cm.full_name {$fromSql} AND ((cm.email IS NOT NULL AND TRIM(cm.email) <> '') OR (cm.full_name IS NOT NULL AND TRIM(cm.full_name) <> ''))", $types, $params);
$uniqueClubs = scalar($conn, "SELECT COUNT(DISTINCT cm.club_id) AS value {$fromSql}", $types, $params);
$semesterCount = $semester !== ''
    ? scalar($conn, "SELECT COUNT(*) AS value FROM club_members WHERE current_semester = ?", 's', [$semester])
    : scalar($conn, "SELECT COUNT(DISTINCT current_semester) AS value FROM club_members WHERE current_semester IS NOT NULL AND TRIM(current_semester) <> ''");

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT cm.id AS member_id, cm.club_id, c.group_name, c.club_identifier,
               cm.full_name, cm.student_id, cm.email, cm.phone, cm.programme, cm.nationality,
               cm.school_centre, cm.intake_month_year, cm.expected_graduation_year,
               cm.current_semester, cm.member_type, cm.created_at,
               c.status AS club_status, c.cluster, c.focus_area
        {$fromSql}
        ORDER BY {$sortMap[$sort]} ".strtoupper($order).", cm.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$rowTypes = $types . 'ii';
$rowParams = array_merge($params, [$perPage, $offset]);
$res = bind_exec($stmt, $rowTypes, $rowParams);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

$semesters = distinct_values($conn, 'club_members', 'current_semester');
$memberTypes = distinct_values($conn, 'club_members', 'member_type');
$programmes = distinct_values($conn, 'club_members', 'programme');
$schools = distinct_values($conn, 'club_members', 'school_centre');
$nationalities = distinct_values($conn, 'club_members', 'nationality');
$clusters = distinct_values($conn, 'clubs', 'cluster');
$focusAreas = distinct_values($conn, 'clubs', 'focus_area');
$statuses = distinct_values($conn, 'clubs', 'status');
?>
<main class="main-content container-fluid">
<style>
.stat-card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.08);position:relative;overflow:hidden}.stat-card:before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:#1a5276}.sort-link{color:#1f2937;text-decoration:none;font-weight:700}.filter-card,.result-card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.07)}.chip{border-radius:999px;padding:.35rem .6rem;font-weight:700}@media(max-width:767.98px){.table-stack thead{display:none}.table-stack,.table-stack tbody,.table-stack tr,.table-stack td{display:block;width:100%}.table-stack tr{background:#fff;margin-bottom:.9rem;border:1px solid #e9ecef;border-radius:10px;padding:.35rem .6rem}.table-stack td{display:flex;justify-content:space-between;gap:1rem;border:none!important;border-bottom:1px dashed #eef2f6!important;text-align:right}.table-stack td:before{content:attr(data-label);font-weight:700;color:#0f2f47;text-align:left}.table-stack td:last-child{border-bottom:none!important}}
</style>

<div class="row g-3 mb-3">
<?php foreach ([['Users with Memberships',$usersWithMemberships,'fa-users'],['Total Membership Records',$totalMembershipRecords,'fa-list-check'],['Filtered Members',$filteredUniqueMembers,'fa-filter'],['Unique Clubs',$uniqueClubs,'fa-people-group'],['Current Semester Count',$semesterCount,'fa-calendar-check']] as $card): ?>
    <div class="col-12 col-sm-6 col-xl"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small fw-bold text-uppercase"><?= h($card[0]) ?></div><div class="h4 mb-0"><?= number_format($card[1]) ?></div></div><i class="fa-solid <?= h($card[2]) ?> fa-2x text-primary"></i></div></div></div>
<?php endforeach; ?>
</div>
<form class="card filter-card mb-3" method="get">
    <input type="hidden" name="page" value="1"><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="order" value="<?= h($order) ?>">
    <div class="card-body"><div class="row g-3 align-items-end">
        <div class="col-12 col-xl-3"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name, email, student ID, club name"></div>
        <?php $selects=[['current_semester','Current Semester',$semester,$semesters],['member_type','Member Type',$memberType,$memberTypes],['programme','Programme',$programme,$programmes],['school_centre','School/Centre',$school,$schools],['nationality','Nationality',$nationality,$nationalities],['cluster','Cluster',$cluster,$clusters],['focus_area','Focus Area',$focusArea,$focusAreas],['club_status','Club Status',$clubStatus,$statuses]]; ?>
        <?php foreach ($selects as $s): ?>
            <div class="col-6 col-md-4 col-xl-2"><label class="form-label"><?= h($s[1]) ?></label><select class="form-select" name="<?= h($s[0]) ?>"><option value="">All</option><?php foreach($s[3] as $v): ?><option value="<?= h($v) ?>" <?= $s[2]===$v?'selected':'' ?>><?= h(ucwords(str_replace('_',' ',$v))) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="col-6 col-md-4 col-xl-1"><label class="form-label">Per Page</label><select class="form-select" name="per_page"><?php foreach([10,20,50,100] as $n): ?><option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-xl-4">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary flex-fill"><i class="fa-solid fa-filter me-1"></i>Apply</button>
                <a href="filter_by_semester.php" class="btn btn-light flex-fill"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a>
                <a href="export_filter_by_semester_csv.php?<?= h(http_build_query(array_diff_key($_GET, ['page' => true]))) ?>" class="btn btn-success flex-fill"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>
                <a href="export_club_members_csv.php?<?= h(http_build_query(array_diff_key($_GET, ['page' => true]))) ?>" class="btn btn-outline-success flex-fill"><i class="fa-solid fa-users me-1"></i>Club Members CSV</a>
            </div>
        </div>
    </div></div>
</form>
<div class="card result-card">
    <div class="card-header bg-white d-flex justify-content-between flex-wrap gap-2"><strong>Club Members <span class="text-muted">(<?= number_format($total) ?>)</span></strong><span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?></span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 table-stack">
        <thead class="table-light"><tr><th><?= sort_link('member_id','Member ID',$sort,$order) ?></th><th><?= sort_link('club_id','Club ID',$sort,$order) ?></th><th><?= sort_link('club_name','Club Name',$sort,$order) ?></th><th><?= sort_link('full_name','Full Name',$sort,$order) ?></th><th><?= sort_link('student_id','Student ID',$sort,$order) ?></th><th>Email</th><th>Phone</th><th><?= sort_link('programme','Programme',$sort,$order) ?></th><th>Nationality</th><th>School/Centre</th><th>Intake Month Year</th><th>Expected Graduation Year</th><th><?= sort_link('semester','Current Semester',$sort,$order) ?></th><th><?= sort_link('member_type','Member Type',$sort,$order) ?></th><th>Club Status</th><th>Cluster</th><th>Focus Area</th><th><?= sort_link('created','Created At',$sort,$order) ?></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="18" class="text-muted text-center py-4">No club members found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Member ID"><?= (int)$r['member_id'] ?></td><td data-label="Club ID"><?= (int)$r['club_id'] ?></td><td data-label="Club Name"><?= h($r['group_name'] ?: 'Unknown') ?></td><td data-label="Full Name" class="fw-semibold"><?= h($r['full_name']) ?></td><td data-label="Student ID"><?= h($r['student_id']) ?></td><td data-label="Email"><?= h($r['email']) ?></td><td data-label="Phone"><?= h($r['phone']) ?></td><td data-label="Programme"><?= h($r['programme']) ?></td><td data-label="Nationality"><?= h($r['nationality']) ?></td><td data-label="School/Centre"><?= h($r['school_centre']) ?></td><td data-label="Intake"><?= h($r['intake_month_year']) ?></td><td data-label="Graduation"><?= h($r['expected_graduation_year']) ?></td><td data-label="Semester"><span class="badge bg-primary"><?= h($r['current_semester'] ?: 'Unknown') ?></span></td><td data-label="Member Type"><span class="badge bg-dark"><?= h(ucwords(str_replace('_',' ',$r['member_type']))) ?></span></td><td data-label="Club Status"><?= badge_status($r['club_status']) ?></td><td data-label="Cluster"><span class="badge bg-secondary"><?= h($r['cluster'] ?: 'Unknown') ?></span></td><td data-label="Focus Area"><span class="badge bg-light text-dark border"><?= h($r['focus_area'] ?: 'Unknown') ?></span></td><td data-label="Created"><?= h($r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : 'Unknown') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="card-footer bg-white"><nav><ul class="pagination mb-0 justify-content-end flex-wrap"><?php $start=max(1,$page-2);$end=min($totalPages,$page+2); if($page>1) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page-1])).'">&lsaquo;</a></li>'; for($p=$start;$p<=$end;$p++) echo '<li class="page-item '.($p===$page?'active':'').'"><a class="page-link" href="'.h(qs(['page'=>$p])).'">'.$p.'</a></li>'; if($page<$totalPages) echo '<li class="page-item"><a class="page-link" href="'.h(qs(['page'=>$page+1])).'">&rsaquo;</a></li>'; ?></ul></nav></div>
</div>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
