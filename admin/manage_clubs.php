<?php
// admin/manage_clubs.php
require_once __DIR__ . '/header.php'; // enforces admin auth and opens $conn

// ---------- Helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function qs(array $overrides = []) {
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }
    return '?' . http_build_query($merged);
}

function statusBadge($s){
    $s = strtolower(trim((string)$s));
    $map = ['approved'=>'success','pending'=>'warning','rejected'=>'danger'];
    $cls = $map[$s] ?? 'secondary';
    $label = $s !== '' ? ucfirst($s) : 'Unknown';
    $textClass = $cls === 'warning' ? ' text-dark' : '';
    return '<span class="badge rounded-pill bg-'.$cls.$textClass.'">'.h($label).'</span>';
}

function isMissingValue($value) {
    $v = strtolower(trim((string)$value));
    return $v === '' || in_array($v, ['unknown', 'missing', 'unknown / missing', 'n/a', 'na', '-', '--'], true);
}

function cleanLabel($value) {
    return isMissingValue($value) ? 'Unknown' : trim((string)$value);
}

function clusterBadgeClass($cluster){
    $cluster = cleanLabel($cluster);
    switch ($cluster) {
        case 'Zero Poverty': return 'danger';
        case 'Zero Unemployment': return 'primary';
        case 'Zero Net Carbon Emissions':
        case 'Zero Net Carbon Emission': return 'success';
        default: return 'secondary';
    }
}

function focusBadgeClass($focus){
    $focus = cleanLabel($focus);
    switch ($focus) {
        case 'Zero Poverty': return 'danger-subtle text-danger border border-danger-subtle';
        case 'Zero Unemployment': return 'primary-subtle text-primary border border-primary-subtle';
        case 'Zero Net Carbon Emissions':
        case 'Zero Net Carbon Emission': return 'success-subtle text-success border border-success-subtle';
        default: return 'secondary-subtle text-secondary border border-secondary-subtle';
    }
}

function bindAndExecute($stmt, $types = '', array $params = []) {
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function scalarPrepared($conn, $sql, $types = '', array $params = [], $key = 'c') {
    $stmt = $conn->prepare($sql);
    $res = bindAndExecute($stmt, $types, $params);
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row[$key] ?? 0);
}

function clubsColumnExists($conn, $column) {
    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    return scalarPrepared($conn, $sql, 'ss', ['clubs', $column]) > 0;
}

function distinctClubValues($conn, $column) {
    if (!in_array($column, ['cluster', 'focus_area'], true)) return [];
    if (!clubsColumnExists($conn, $column)) return [];
    $values = [];
    $sql = "SELECT DISTINCT {$column} AS val
            FROM clubs
            WHERE {$column} IS NOT NULL
              AND TRIM({$column}) <> ''
              AND LOWER(TRIM({$column})) NOT IN ('unknown','missing','unknown / missing','n/a','na','-','--')
            ORDER BY val ASC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $values[] = $row['val'];
        }
    }
    return $values;
}

function normalizedSql($columnSql) {
    return "CASE
                WHEN {$columnSql} IS NULL OR TRIM({$columnSql}) = ''
                     OR LOWER(TRIM({$columnSql})) IN ('unknown','missing','unknown / missing','n/a','na','-','--')
                    THEN 'Unknown / Missing'
                WHEN LOWER(TRIM({$columnSql})) LIKE '%poverty%' THEN 'Zero Poverty'
                WHEN LOWER(TRIM({$columnSql})) LIKE '%unemployment%' THEN 'Zero Unemployment'
                WHEN LOWER(TRIM({$columnSql})) LIKE '%carbon%' THEN 'Zero Net Carbon Emissions'
                ELSE TRIM({$columnSql})
            END";
}

function missingSql($columnSql) {
    return "({$columnSql} IS NULL OR TRIM({$columnSql}) = ''
            OR LOWER(TRIM({$columnSql})) IN ('unknown','missing','unknown / missing','n/a','na','-','--'))";
}

function sortLink($field, $label, $currentSort, $currentOrder) {
    $nextOrder = ($currentSort === $field && $currentOrder === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $field) {
        $arrow = $currentOrder === 'asc' ? ' <i class="fa-solid fa-arrow-up-short-wide ms-1"></i>' : ' <i class="fa-solid fa-arrow-down-wide-short ms-1"></i>';
    }
    return '<a class="sort-link" href="'.h(qs(['sort'=>$field, 'order'=>$nextOrder, 'page'=>1])).'">'.h($label).$arrow.'</a>';
}

// ---------- Column detection ----------
$hasFocusArea = clubsColumnExists($conn, 'focus_area');
$focusColumnSql = $hasFocusArea ? 'c.focus_area' : 'c.cluster';
$focusSelectSql = $hasFocusArea ? 'c.focus_area' : "NULL";
$clusterNormalizedSql = normalizedSql('c.cluster');
$focusNormalizedSql = normalizedSql($focusColumnSql);
$clusterMissingSql = missingSql('c.cluster');
$focusMissingSql = missingSql($focusColumnSql);

// ---------- Query params ----------
$q            = isset($_GET['q']) ? trim($_GET['q']) : '';
$status       = isset($_GET['status']) ? trim($_GET['status']) : '';
$cluster      = isset($_GET['cluster']) ? trim($_GET['cluster']) : '';
$focusArea    = isset($_GET['focus_area']) ? trim($_GET['focus_area']) : '';
$missingData  = isset($_GET['missing_data']) ? trim($_GET['missing_data']) : '';
$reg_from     = isset($_GET['reg_from']) ? trim($_GET['reg_from']) : '';
$reg_to       = isset($_GET['reg_to']) ? trim($_GET['reg_to']) : '';

$allowedPerPage = [10, 20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 10;

$page = max(1, (int)($_GET['page'] ?? 1));

$allowedSorts = [
    'id' => 'c.id',
    'name' => 'c.group_name',
    'cluster' => $clusterNormalizedSql,
    'focus_area' => $focusNormalizedSql,
    'status' => 'c.status',
    'registered' => 'c.date_of_registration',
];
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'registered';
if (!isset($allowedSorts[$sort])) $sort = 'registered';

$order = strtolower(trim($_GET['order'] ?? 'desc'));
if (!in_array($order, ['asc', 'desc'], true)) $order = 'desc';

$validStatuses = ['pending', 'approved', 'rejected'];
$focusOptions = $hasFocusArea ? distinctClubValues($conn, 'focus_area') : ['Zero Poverty', 'Zero Unemployment', 'Zero Net Carbon Emissions'];
$focusOptions[] = 'Unknown / Missing';
$clusterOptions = distinctClubValues($conn, 'cluster');
if (!$clusterOptions) {
    $clusterOptions = ['Zero Poverty', 'Zero Unemployment', 'Zero Net Carbon Emissions'];
}
$missingOptions = ['missing_cluster', 'missing_focus', 'missing_any', 'complete'];

if ($status !== '' && !in_array($status, $validStatuses, true)) $status = '';
if ($cluster !== '' && !in_array($cluster, $clusterOptions, true)) $cluster = '';
if ($focusArea !== '' && !in_array($focusArea, $focusOptions, true)) $focusArea = '';
if ($missingData !== '' && !in_array($missingData, $missingOptions, true)) $missingData = '';
if ($reg_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_from)) $reg_from = '';
if ($reg_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_to)) $reg_to = '';

// ---------- Build WHERE (prepared) ----------
$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($q !== '') {
    $where .= " AND (
        CAST(c.id AS CHAR) LIKE ? OR
        c.club_identifier LIKE ? OR
        c.group_name LIKE ? OR
        c.cluster_advisor LIKE ? OR
        c.key_person_name LIKE ? OR
        c.key_person_student_id LIKE ? OR
        c.deputy_key_person_name LIKE ? OR
        c.deputy_key_person_student_id LIKE ? OR
        c.cluster LIKE ? ";
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
    $types   .= 's';
}

if ($cluster !== '') {
    $where .= " AND {$clusterNormalizedSql} = ? ";
    $params[] = $cluster;
    $types   .= 's';
}

if ($focusArea !== '') {
    if ($focusArea === 'Unknown / Missing') {
        $where .= " AND {$focusMissingSql} ";
    } else {
        $where .= " AND TRIM({$focusColumnSql}) = ? ";
        $params[] = $focusArea;
        $types   .= 's';
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

if ($reg_from !== '') {
    $where .= " AND c.date_of_registration >= ? ";
    $params[] = $reg_from;
    $types   .= 's';
}
if ($reg_to !== '') {
    $where .= " AND c.date_of_registration <= ? ";
    $params[] = $reg_to;
    $types   .= 's';
}

// ---------- Counts ----------
$total = scalarPrepared($conn, "SELECT COUNT(*) AS c FROM clubs c {$where}", $types, $params);
$grandTotal = scalarPrepared($conn, "SELECT COUNT(*) AS c FROM clubs c");

$countsByStatus = ['approved'=>0, 'pending'=>0, 'rejected'=>0];
$scSql = "SELECT c.status, COUNT(*) AS cnt FROM clubs c {$where} GROUP BY c.status";
$sc = $conn->prepare($scSql);
$scRes = bindAndExecute($sc, $types, $params);
while ($row = $scRes->fetch_assoc()) {
    $s = strtolower(trim((string)$row['status']));
    if (isset($countsByStatus[$s])) $countsByStatus[$s] = (int)$row['cnt'];
}

$missingAnyCount = scalarPrepared(
    $conn,
    "SELECT COUNT(*) AS c FROM clubs c {$where} AND ({$clusterMissingSql} OR {$focusMissingSql})",
    $types,
    $params
);
$missingClusterCount = scalarPrepared(
    $conn,
    "SELECT COUNT(*) AS c FROM clubs c {$where} AND {$clusterMissingSql}",
    $types,
    $params
);
$missingFocusCount = scalarPrepared(
    $conn,
    "SELECT COUNT(*) AS c FROM clubs c {$where} AND {$focusMissingSql}",
    $types,
    $params
);

// ---------- Pagination ----------
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$fromRow    = $total > 0 ? $offset + 1 : 0;
$toRow      = min($offset + $perPage, $total);

// ---------- Fetch rows ----------
$orderSql = strtoupper($order);
$sortSql = $allowedSorts[$sort];
$sql = "SELECT
            c.id,
            c.club_identifier,
            c.group_name,
            c.cluster,
            {$focusSelectSql} AS focus_area,
            c.cluster_advisor,
            c.key_person_name,
            c.key_person_student_id,
            c.deputy_key_person_name,
            c.deputy_key_person_student_id,
            c.date_of_registration,
            c.status,
            c.created_at,
            c.updated_at
        FROM clubs c
        {$where}
        ORDER BY {$sortSql} {$orderSql}, c.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$rowTypes = $types . 'ii';
$rowParams = array_merge($params, [$perPage, $offset]);
$res  = bindAndExecute($stmt, $rowTypes, $rowParams);
$rows = [];
while ($row = $res->fetch_assoc()) $rows[] = $row;

$focusFallbackNotice = $hasFocusArea
    ? ''
    : 'Focus Area column was not found; focus filters use cluster values while the table safely shows Unknown.';
?>
<main class="main-content container-fluid">
    <style>
        .page-toolbar {
            gap: .75rem;
        }
        .page-title {
            color: #102a43;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .stat-card {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(15, 42, 71, .08);
            background: #fff;
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1a5276, #2874a6);
        }
        .stat-label {
            color: #64748b;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .stat-value {
            color: #0f172a;
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .warning-card {
            background: #fff8e1;
            border: 1px solid #ffe08a;
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(146, 100, 0, .08);
            height: 100%;
        }
        .warning-card .stat-value {
            color: #8a5a00;
        }
        .status-summary-card .small,
        .status-summary-card .h4 {
            color: #0f172a !important;
        }
        .status-summary-card .small {
            opacity: .82;
        }
        .filter-card,
        .results-card {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(15, 42, 71, .08);
        }
        .filter-card .form-label {
            color: #334155;
            font-size: .82rem;
            font-weight: 700;
            margin-bottom: .35rem;
        }
        .cluster-pill,
        .focus-pill {
            padding: .4rem .65rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 700;
            white-space: normal;
            text-align: left;
        }
        .sort-link {
            color: #1f2937;
            text-decoration: none;
            font-weight: 700;
            white-space: nowrap;
        }
        .sort-link:hover {
            color: #1a5276;
        }
        .table thead th {
            color: #334155;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            vertical-align: middle;
        }
        .table td {
            color: #1f2937;
            vertical-align: middle;
        }
        .person-name {
            font-weight: 700;
            color: #172033;
        }
        .empty-dash {
            color: #94a3b8;
        }
        .actions-group .btn {
            width: 34px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        @media (max-width: 767.98px) {
            .table-stack thead { display: none; }
            .table-stack,
            .table-stack tbody,
            .table-stack tr,
            .table-stack td {
                display: block;
                width: 100%;
            }
            .table-stack tr {
                background: #fff;
                margin-bottom: .9rem;
                border-radius: 12px;
                border: 1px solid #e9eef5;
                box-shadow: 0 4px 12px rgba(15, 42, 71, .04);
                padding: .35rem .65rem;
            }
            .table-stack td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 1rem;
                padding: .65rem .25rem;
                border: none !important;
                border-bottom: 1px dashed #e6edf5 !important;
                text-align: right;
            }
            .table-stack td:last-child {
                border-bottom: none !important;
            }
            .table-stack td::before {
                content: attr(data-label);
                color: #0f2f47;
                font-weight: 800;
                min-width: 110px;
                text-align: left;
            }
            .table-stack .text-end {
                text-align: right !important;
            }
            .table-stack .actions-group {
                justify-content: flex-end;
            }
        }
    </style>

    <div class="d-flex flex-wrap align-items-center justify-content-between page-toolbar mb-3">
        <div>
            <h2 class="page-title mb-1">Manage Clubs</h2>
            <div class="text-muted">Review, filter, and maintain registered 3ZERO Clubs.</div>
        </div>
    </div>

    <?php if ($focusFallbackNotice): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <i class="fa-solid fa-circle-info me-1"></i> <?= h($focusFallbackNotice) ?>
        </div>
    <?php endif; ?>

    <!-- Top stat cards -->
    <div class="row g-3 align-items-stretch mb-3">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label mb-2">Total Clubs</div>
                        <div class="stat-value"><?= number_format($grandTotal) ?></div>
                    </div>
                    <i class="fa-solid fa-people-group fa-2x text-primary"></i>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label mb-2">Filtered</div>
                        <div class="stat-value"><?= number_format($total) ?></div>
                    </div>
                    <i class="fa-solid fa-filter fa-2x text-primary"></i>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-label mb-3">By Status</div>
                    <div class="row g-2">
                        <div class="col-12 col-sm-4">
                            <div class="p-3 rounded-3 bg-success-subtle status-summary-card">
                                <div class="small text-success fw-bold">Approved</div>
                                <div class="h4 mb-0 text-success"><?= number_format($countsByStatus['approved']) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="p-3 rounded-3 bg-warning-subtle status-summary-card">
                                <div class="small text-warning-emphasis fw-bold">Pending</div>
                                <div class="h4 mb-0 text-warning-emphasis"><?= number_format($countsByStatus['pending']) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="p-3 rounded-3 bg-danger-subtle status-summary-card">
                                <div class="small text-danger fw-bold">Rejected</div>
                                <div class="h4 mb-0 text-danger"><?= number_format($countsByStatus['rejected']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Warning/stat cards -->
    <div class="row g-3 align-items-stretch mb-3">
        <div class="col-12 col-md-4">
            <div class="warning-card p-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label text-warning-emphasis mb-2">Any Missing Cluster / Focus Area</div>
                    <div class="stat-value"><?= number_format($missingAnyCount) ?></div>
                </div>
                <i class="fa-solid fa-triangle-exclamation fa-2x text-warning"></i>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="warning-card p-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label text-warning-emphasis mb-2">Unknown / Missing Cluster</div>
                    <div class="stat-value"><?= number_format($missingClusterCount) ?></div>
                </div>
                <i class="fa-solid fa-layer-group fa-2x text-warning"></i>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="warning-card p-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label text-warning-emphasis mb-2">Unknown / Missing Focus Area</div>
                    <div class="stat-value"><?= number_format($missingFocusCount) ?></div>
                </div>
                <i class="fa-solid fa-bullseye fa-2x text-warning"></i>
            </div>
        </div>
    </div>

    <!-- Search / Filters -->
    <form class="card filter-card mb-3" method="get" action="">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="order" value="<?= h($order) ?>">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-xl-4">
                    <label class="form-label">Search</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" name="q" class="form-control"
                               placeholder="ID, identifier, name, advisor, key/deputy name or student ID"
                               value="<?= h($q) ?>">
                    </div>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="approved" <?= $status==='approved'?'selected':''; ?>>Approved</option>
                        <option value="pending"  <?= $status==='pending'?'selected':''; ?>>Pending</option>
                        <option value="rejected" <?= $status==='rejected'?'selected':''; ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label">Cluster</label>
                    <select name="cluster" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($clusterOptions as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= $cluster===$opt?'selected':''; ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label">Focus Area</label>
                    <select name="focus_area" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($focusOptions as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= $focusArea===$opt?'selected':''; ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label">Missing Data</label>
                    <select name="missing_data" class="form-select">
                        <option value="">All Records</option>
                        <option value="missing_cluster" <?= $missingData==='missing_cluster'?'selected':''; ?>>Missing Cluster</option>
                        <option value="missing_focus" <?= $missingData==='missing_focus'?'selected':''; ?>>Missing Focus Area</option>
                        <option value="missing_any" <?= $missingData==='missing_any'?'selected':''; ?>>Missing Cluster or Focus Area</option>
                        <option value="complete" <?= $missingData==='complete'?'selected':''; ?>>Complete Records</option>
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label">Registered From</label>
                    <input type="date" name="reg_from" class="form-control" value="<?= h($reg_from) ?>">
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label">Registered To</label>
                    <input type="date" name="reg_to" class="form-control" value="<?= h($reg_to) ?>">
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label">Per Page</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ($allowedPerPage as $n): ?>
                            <option value="<?= $n ?>" <?= $perPage===$n?'selected':''; ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <button class="btn btn-success w-100">
                        <i class="fa-solid fa-filter me-1"></i> Apply
                    </button>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <a href="export_clubs_csv.php?<?= h(http_build_query(array_diff_key($_GET, ['page' => true]))) ?>" class="btn btn-success w-100">
                        <i class="fa-solid fa-file-csv me-1"></i> Export CSV
                    </a>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <a href="manage_clubs.php" class="btn btn-outline-secondary w-100">
                        <i class="fa-solid fa-rotate-left me-1"></i> Reset
                    </a>
                </div>
            </div>
        </div>
    </form>

    <!-- Results -->
    <div class="card results-card">
        <div class="card-header bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div>
                <strong>Results</strong>
                <span class="text-muted">(<?= number_format($total) ?> clubs)</span>
                <span class="text-muted small ms-2">Showing <?= number_format($fromRow) ?>-<?= number_format($toRow) ?></span>
            </div>
            <div class="small text-muted">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th><?= sortLink('id', 'ID', $sort, $order) ?></th>
                        <th>Identifier</th>
                        <th><?= sortLink('name', 'Name', $sort, $order) ?></th>
                        <th><?= sortLink('cluster', 'Cluster', $sort, $order) ?></th>
                        <th><?= sortLink('focus_area', 'Focus Area', $sort, $order) ?></th>
                        <th><?= sortLink('status', 'Status', $sort, $order) ?></th>
                        <th>Advisor</th>
                        <th>Key Person</th>
                        <th>Deputy</th>
                        <th><?= sortLink('registered', 'Registered', $sort, $order) ?></th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="text-muted text-center py-4">No clubs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $clusterLabel = cleanLabel($r['cluster'] ?? '');
                            $focusLabel = $hasFocusArea ? cleanLabel($r['focus_area'] ?? '') : 'Unknown';
                            $registeredRaw = $r['date_of_registration'] ?? '';
                            $registeredTs = $registeredRaw ? strtotime($registeredRaw) : false;
                            $registeredLabel = $registeredTs ? date('M j, Y', $registeredTs) : 'Unknown';
                        ?>
                        <tr>
                            <td data-label="ID"><?= (int)$r['id'] ?></td>
                            <td data-label="Identifier">
                                <?php if (!empty($r['club_identifier'])): ?>
                                    <code><?= h($r['club_identifier']) ?></code>
                                <?php else: ?>
                                    <span class="empty-dash">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Name" class="fw-semibold"><?= h(cleanLabel($r['group_name'] ?? '')) ?></td>
                            <td data-label="Cluster">
                                <span class="badge cluster-pill bg-<?= clusterBadgeClass($clusterLabel); ?>">
                                    <?= h($clusterLabel) ?>
                                </span>
                            </td>
                            <td data-label="Focus Area">
                                <span class="badge focus-pill bg-<?= focusBadgeClass($focusLabel); ?>">
                                    <?= h($focusLabel) ?>
                                </span>
                            </td>
                            <td data-label="Status"><?= statusBadge($r['status'] ?? '') ?></td>
                            <td data-label="Advisor"><?= h(cleanLabel($r['cluster_advisor'] ?? '')) ?></td>
                            <td data-label="Key Person">
                                <div class="person-name"><?= h(cleanLabel($r['key_person_name'] ?? '')) ?></div>
                                <div class="text-muted small"><?= h(cleanLabel($r['key_person_student_id'] ?? '')) ?></div>
                            </td>
                            <td data-label="Deputy">
                                <div class="person-name"><?= h(cleanLabel($r['deputy_key_person_name'] ?? '')) ?></div>
                                <div class="text-muted small"><?= h(cleanLabel($r['deputy_key_person_student_id'] ?? '')) ?></div>
                            </td>
                            <td data-label="Registered"><?= h($registeredLabel) ?></td>
                            <td data-label="Actions" class="text-end">
                                <div class="btn-group actions-group">
                                    <a href="club_details.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View" aria-label="View club">
                                        <i class="fa-regular fa-eye"></i>
                                    </a>
                                    <a href="edit_club.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit" aria-label="Edit club">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <a href="delete_club.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" aria-label="Delete club"
                                       onclick="return confirm('Delete this club? This action cannot be undone.');">
                                        <i class="fa-solid fa-trash"></i>
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
        <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="small text-muted">
                <?= number_format($perPage) ?> rows per page
            </div>
            <nav aria-label="Club pagination">
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
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
