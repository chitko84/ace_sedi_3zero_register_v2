<?php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function val($key){ return h($_POST[$key] ?? ''); }

function column_exists($conn, $table, $column) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

function distinct_club_values($conn, $column) {
    if (!in_array($column, ['cluster','focus_area'], true) || !column_exists($conn, 'clubs', $column)) return [];

    $items = [];
    $res = $conn->query("
        SELECT DISTINCT {$column} AS val
        FROM clubs
        WHERE {$column} IS NOT NULL
        AND TRIM({$column}) <> ''
        ORDER BY val ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = $row['val'];
        }
    }

    return $items;
}

function user_by_email($conn, $email) {
    $stmt = $conn->prepare("
        SELECT id, name, email, phone_number, department, program_of_study,
               intake, country, gender, area_of_interest, expected_graduation_year
        FROM users
        WHERE role='user'
        AND email COLLATE utf8mb4_unicode_ci = CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
        LIMIT 1
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function semester_options($selected = '') {
    $semesters = [
        'CFGS Sem 1',
        'CFGS Sem 2',
        'CFGS Sem 3',
        'Year 1 Sem 1',
        'Year 1 Sem 2',
        'Year 1 Sem 3',
        'Year 2 Sem 1',
        'Year 2 Sem 2',
        'Year 2 Sem 3',
        'Year 3 Sem 1',
        'Year 3 Sem 2',
        'Year 3 Sem 3'
    ];

    $html = '<option value="">Unknown / Missing</option>';

    foreach ($semesters as $sem) {
        $isSelected = ($selected === $sem) ? 'selected' : '';
        $html .= '<option value="' . h($sem) . '" ' . $isSelected . '>' . h($sem) . '</option>';
    }

    return $html;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$csrf_token = $_SESSION['csrf_token'];

$hasFocus = column_exists($conn, 'clubs', 'focus_area');

$clusters = distinct_club_values($conn, 'cluster');
if (!$clusters) {
    $clusters = [
        'Zero Poverty',
        'Zero Unemployment',
        'Zero Net Carbon Emissions'
    ];
}

$focusAreas = $hasFocus ? distinct_club_values($conn, 'focus_area') : [];
$statuses = ['pending','approved','rejected'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = 'Invalid session token.';
    }

    $club_identifier      = trim($_POST['club_identifier'] ?? '');
    $group_name           = preg_replace('/\s+/', ' ', trim($_POST['group_name'] ?? ''));
    $cluster              = trim($_POST['cluster'] ?? '');
    $focus_area           = trim($_POST['focus_area'] ?? '');
    $cluster_advisor      = trim($_POST['cluster_advisor'] ?? '');
    $date_of_registration = trim($_POST['date_of_registration'] ?? '');
    $status               = trim($_POST['status'] ?? 'pending');

    $key_email    = trim($_POST['key_person_email'] ?? '');
    $deputy_email = trim($_POST['deputy_email'] ?? '');

    $memberEmails = [];
    for ($i = 1; $i <= 3; $i++) {
        $memberEmails[$i] = trim($_POST["member{$i}_email"] ?? '');
    }

    $studentIds = [
        'key'    => trim($_POST['key_student_id'] ?? ''),
        'deputy' => trim($_POST['deputy_student_id'] ?? '')
    ];

    $semesters = [
        'key'    => trim($_POST['key_semester'] ?? ''),
        'deputy' => trim($_POST['deputy_semester'] ?? '')
    ];

    for ($i = 1; $i <= 3; $i++) {
        $studentIds[$i] = trim($_POST["member{$i}_student_id"] ?? '');
        $semesters[$i]  = trim($_POST["member{$i}_semester"] ?? '');
    }

    if ($club_identifier !== '' && !preg_match('/^[0-9A-Za-z-]+$/', $club_identifier)) {
        $errors[] = 'Club identifier may contain only letters, numbers, and dashes.';
    }

    if ($group_name === '') {
        $errors[] = 'Club/group name is required.';
    }

    if ($cluster === '') {
        $errors[] = 'Cluster is required.';
    }

    if ($cluster !== '' && !in_array($cluster, $clusters, true)) {
        $errors[] = 'Valid cluster is required.';
    }

    if ($hasFocus && $focus_area !== '' && !in_array($focus_area, $focusAreas, true)) {
        $errors[] = 'Invalid focus area selected.';
    }

    if ($date_of_registration === '') {
        $date_of_registration = date('Y-m-d');
    }

    if (!in_array($status, $statuses, true)) {
        $status = 'pending';
    }

    if ($key_email !== '' && !filter_var($key_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid key person email.';
    }

    if ($key_email === '') {
        $errors[] = 'Key person email is required.';
    }

    if ($studentIds['key'] === '') {
        $errors[] = 'Key person student ID is required.';
    }

    if ($semesters['key'] === '') {
        $errors[] = 'Key person current semester is required.';
    }

    if ($deputy_email !== '' && !filter_var($deputy_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid deputy email.';
    }

    if ($deputy_email === '') {
        $errors[] = 'Deputy key person email is required.';
    }

    if ($studentIds['deputy'] === '') {
        $errors[] = 'Deputy key person student ID is required.';
    }

    if ($semesters['deputy'] === '') {
        $errors[] = 'Deputy key person current semester is required.';
    }

    foreach ($memberEmails as $i => $email) {
        if ($email === '') {
            $errors[] = "Member {$i} email is required.";
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid Member {$i} email.";
        }
        if ($studentIds[$i] === '') {
            $errors[] = "Member {$i} student ID is required.";
        }
        if ($semesters[$i] === '') {
            $errors[] = "Member {$i} current semester is required.";
        }
    }

    $key = $key_email !== '' ? user_by_email($conn, $key_email) : null;
    $deputy = $deputy_email !== '' ? user_by_email($conn, $deputy_email) : null;

    $regularMembers = [];
    foreach ($memberEmails as $i => $email) {
        $regularMembers[$i] = $email !== '' ? user_by_email($conn, $email) : null;
    }

    if ($key_email !== '' && !$key) {
        $errors[] = 'Key person email must belong to a registered student user.';
    }

    if ($deputy_email !== '' && !$deputy) {
        $errors[] = 'Deputy key person email must belong to a registered student user.';
    }

    foreach ($regularMembers as $i => $member) {
        if (($memberEmails[$i] ?? '') !== '' && !$member) {
            $errors[] = "Member {$i} email must belong to a registered student user.";
        }
    }

    $filledEmails = [];

    if ($key_email !== '') $filledEmails[] = strtolower($key_email);
    if ($deputy_email !== '') $filledEmails[] = strtolower($deputy_email);

    foreach ($memberEmails as $email) {
        if ($email !== '') {
            $filledEmails[] = strtolower($email);
        }
    }

    if (count($filledEmails) !== count(array_unique($filledEmails))) {
        $errors[] = 'Duplicate emails are not allowed.';
    }

    if (!$errors) {
        if ($club_identifier !== '') {
            $st = $conn->prepare("SELECT 1 FROM clubs WHERE club_identifier COLLATE utf8mb4_unicode_ci = CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIMIT 1");
            $st->bind_param('s', $club_identifier);
            $st->execute();

            if ($st->get_result()->num_rows > 0) {
                $errors[] = 'Club identifier already exists.';
            }
        }

        $st = $conn->prepare("SELECT 1 FROM clubs WHERE LOWER(group_name) COLLATE utf8mb4_unicode_ci = LOWER(CAST(? AS CHAR CHARACTER SET utf8mb4)) COLLATE utf8mb4_unicode_ci LIMIT 1");
        $st->bind_param('s', $group_name);
        $st->execute();

        if ($st->get_result()->num_rows > 0) {
            $errors[] = 'Club/group name already exists.';
        }
    }

    if (!$errors) {
        $conn->begin_transaction();

        try {
            $key_name       = $key['name'] ?? 'Unknown Key Person';
            $key_student_id = $studentIds['key'] ?: 'UNKNOWN';

            $deputy_name       = $deputy['name'] ?? 'Unknown Deputy';
            $deputy_student_id = $studentIds['deputy'] ?: 'UNKNOWN';

            $advisorValue = $cluster_advisor ?: 'Unknown';
            $club_identifier_db = $club_identifier === '' ? null : $club_identifier;
            $focus_area_db = $focus_area === '' ? null : $focus_area;

            if ($hasFocus) {
                $sql = "
                    INSERT INTO clubs
                    (
                        club_identifier,
                        group_name,
                        cluster,
                        focus_area,
                        cluster_advisor,
                        key_person_name,
                        key_person_student_id,
                        deputy_key_person_name,
                        deputy_key_person_student_id,
                        date_of_registration,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    'sssssssssss',
                    $club_identifier_db,
                    $group_name,
                    $cluster,
                    $focus_area_db,
                    $advisorValue,
                    $key_name,
                    $key_student_id,
                    $deputy_name,
                    $deputy_student_id,
                    $date_of_registration,
                    $status
                );
            } else {
                $sql = "
                    INSERT INTO clubs
                    (
                        club_identifier,
                        group_name,
                        cluster,
                        cluster_advisor,
                        key_person_name,
                        key_person_student_id,
                        deputy_key_person_name,
                        deputy_key_person_student_id,
                        date_of_registration,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    'ssssssssss',
                    $club_identifier_db,
                    $group_name,
                    $cluster,
                    $advisorValue,
                    $key_name,
                    $key_student_id,
                    $deputy_name,
                    $deputy_student_id,
                    $date_of_registration,
                    $status
                );
            }

            $stmt->execute();
            $club_id = $conn->insert_id;

            $memberSql = "
                INSERT INTO club_members
                (
                    club_id,
                    full_name,
                    student_id,
                    programme,
                    nationality,
                    phone,
                    email,
                    school_centre,
                    intake_month_year,
                    expected_graduation_year,
                    current_semester,
                    member_type
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $memberStmt = $conn->prepare($memberSql);

            $memberRows = [
                [
                    'user' => $key,
                    'type' => 'key_person',
                    'student_id' => $studentIds['key'],
                    'semester' => $semesters['key']
                ],
                [
                    'user' => $deputy,
                    'type' => 'deputy',
                    'student_id' => $studentIds['deputy'],
                    'semester' => $semesters['deputy']
                ]
            ];

            foreach ($regularMembers as $i => $member) {
                $memberRows[] = [
                    'user' => $member,
                    'type' => 'regular',
                    'student_id' => $studentIds[$i],
                    'semester' => $semesters[$i]
                ];
            }

            foreach ($memberRows as $item) {
                $u = $item['user'];

                $name        = $u['name'] ?? 'Unknown';
                $email       = $u['email'] ?? 'unknown@example.com';
                $studentId   = $item['student_id'] ?: 'UNKNOWN';
                $programme   = $u['program_of_study'] ?? 'Unknown';
                $nationality = $u['country'] ?? 'Unknown';
                $phone       = $u['phone_number'] ?? 'Unknown';
                $school      = $u['department'] ?? 'Unknown';
                $intake      = $u['intake'] ?? 'Unknown';
                $grad        = $u['expected_graduation_year'] ?? 'Unknown';
                $semester    = $item['semester'] ?: 'Unknown';
                $type        = $item['type'];

                $memberStmt->bind_param(
                    'isssssssssss',
                    $club_id,
                    $name,
                    $studentId,
                    $programme,
                    $nationality,
                    $phone,
                    $email,
                    $school,
                    $intake,
                    $grad,
                    $semester,
                    $type
                );

                $memberStmt->execute();
            }

            $conn->commit();

            $_SESSION['success'] = 'Club registered successfully by admin.';
            header('Location: manage_clubs.php');
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<main class="main-content container-fluid">

<style>
.form-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 8px 24px rgba(15,42,71,.08);
}

.section-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.35rem;
    font-weight: 700;
    color: #111;
    margin-bottom: 1.25rem;
    position: relative;
    display: inline-block;
    padding-bottom: .6rem;
    border-bottom: 0;
    box-shadow: none;
}
.section-title::before { content: none; }
.section-title::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 120px;
    height: 4px;
    background: #1a5276;
    border-radius: 999px;
}

.user-search {
    position: relative;
}

.user-results {
    position: absolute;
    z-index: 20;
    left: 0;
    right: 0;
    top: 100%;
    background: #fff;
    border: 1px solid #dbe3ec;
    border-radius: 8px;
    box-shadow: 0 10px 24px rgba(0,0,0,.12);
    max-height: 260px;
    overflow: auto;
    display: none;
}

.user-result {
    padding: .7rem .85rem;
    cursor: pointer;
    border-bottom: 1px solid #eef2f6;
}

.user-result:hover {
    background: #eef7ff;
}

.readonly-grid {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1rem;
}
@media(max-width:767.98px){
    .form-card .card-body{padding:1rem!important}
    .section-title{font-size:1.2rem}
    .row{--bs-gutter-y:.75rem}
    .btn{min-height:44px}
    .d-flex.gap-2{flex-wrap:wrap}
}
</style>

<div class="mb-3">
    <h2 class="mb-1">Register Club</h2>
    <div class="text-muted">
        Admin can register clubs with optional members. Missing values will be saved as Unknown.
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach($errors as $e): ?>
            <li><?= h($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form class="card form-card" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

    <div class="card-body">

        <div class="section-title">Club Information</div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Club / Group Name</label>
                <input class="form-control" name="group_name" value="<?= val('group_name') ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Club Identifier</label>
                <input class="form-control" name="club_identifier" value="<?= val('club_identifier') ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Registration Date</label>
                <input type="date" class="form-control" name="date_of_registration"
                       value="<?= h($_POST['date_of_registration'] ?? date('Y-m-d')) ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Cluster</label>
                <select class="form-select" name="cluster">
                    <option value="">Choose cluster</option>
                    <?php foreach($clusters as $c): ?>
                        <option value="<?= h($c) ?>" <?= ($_POST['cluster'] ?? '') === $c ? 'selected' : '' ?>>
                            <?= h($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Focus Area</label>
                <select class="form-select" name="focus_area">
                    <option value="">Unknown / Missing</option>
                    <?php foreach($focusAreas as $f): ?>
                        <option value="<?= h($f) ?>" <?= ($_POST['focus_area'] ?? '') === $f ? 'selected' : '' ?>>
                            <?= h($f) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach($statuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= ($_POST['status'] ?? 'pending') === $s ? 'selected' : '' ?>>
                            <?= h(ucfirst($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Advisor</label>
                <input class="form-control" name="cluster_advisor" value="<?= val('cluster_advisor') ?>">
            </div>
        </div>

        <?php
        $roles = [
            'key' => [
                'title' => 'Key Person',
                'email' => 'key_person_email',
                'student' => 'key_student_id',
                'semester' => 'key_semester'
            ],
            'deputy' => [
                'title' => 'Deputy',
                'email' => 'deputy_email',
                'student' => 'deputy_student_id',
                'semester' => 'deputy_semester'
            ],
            1 => [
                'title' => 'Member 1',
                'email' => 'member1_email',
                'student' => 'member1_student_id',
                'semester' => 'member1_semester'
            ],
            2 => [
                'title' => 'Member 2',
                'email' => 'member2_email',
                'student' => 'member2_student_id',
                'semester' => 'member2_semester'
            ],
            3 => [
                'title' => 'Member 3',
                'email' => 'member3_email',
                'student' => 'member3_student_id',
                'semester' => 'member3_semester'
            ]
        ];
        ?>

        <?php foreach ($roles as $role): ?>
            <div class="section-title"><?= h($role['title']) ?></div>

            <div class="row g-3 mb-4 member-block">
                <div class="col-md-4 user-search">
                    <label class="form-label">Email / Name</label>
                    <input
                        class="form-control search-user"
                        name="<?= h($role['email']) ?>"
                        value="<?= val($role['email']) ?>"
                        placeholder="Optional"
                    >
                    <div class="user-results"></div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Student ID</label>
                    <input
                        class="form-control"
                        name="<?= h($role['student']) ?>"
                        value="<?= val($role['student']) ?>"
                        placeholder="Optional"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Current Semester</label>
                    <select class="form-select" name="<?= h($role['semester']) ?>">
                        <?= semester_options($_POST[$role['semester']] ?? '') ?>
                    </select>
                </div>

                <div class="col-12">
                    <div class="readonly-grid user-summary text-muted">
                        Select a registered user to auto-fill details.
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex justify-content-end gap-2">
            <a href="manage_clubs.php" class="btn btn-light">Cancel</a>

            <button class="btn btn-primary">
                <i class="fa-solid fa-plus me-1"></i>
                Register Club
            </button>
        </div>

    </div>
</form>

</main>

<script>
(function(){

    function renderSummary(box, u) {
        box.innerHTML =
            '<div class="row g-2 small">' +
            '<div class="col-sm-6"><strong>Name:</strong> ' + escapeHtml(u.name || '') + '</div>' +
            '<div class="col-sm-6"><strong>Email:</strong> ' + escapeHtml(u.email || '') + '</div>' +
            '<div class="col-sm-6"><strong>Phone:</strong> ' + escapeHtml(u.phone_number || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Department:</strong> ' + escapeHtml(u.department || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Program:</strong> ' + escapeHtml(u.program_of_study || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Intake:</strong> ' + escapeHtml(u.intake || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Country:</strong> ' + escapeHtml(u.country || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Gender:</strong> ' + escapeHtml(u.gender || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Area:</strong> ' + escapeHtml(u.area_of_interest || 'Unknown') + '</div>' +
            '<div class="col-sm-6"><strong>Graduation:</strong> ' + escapeHtml(u.expected_graduation_year || 'Unknown') + '</div>' +
            '</div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                '"':'&quot;',
                "'":'&#039;'
            }[c];
        });
    }

    document.querySelectorAll('.member-block').forEach(function(block) {
        const input = block.querySelector('.search-user');
        const results = block.querySelector('.user-results');
        const summary = block.querySelector('.user-summary');

        let timer = null;

        input.addEventListener('input', function() {
            clearTimeout(timer);

            const q = input.value.trim();

            if (q.length < 2) {
                results.style.display = 'none';
                return;
            }

            timer = setTimeout(function() {
                fetch('ajax_search_users.php?q=' + encodeURIComponent(q), {
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    results.innerHTML = '';

                    (data.users || []).forEach(function(u) {
                        const item = document.createElement('div');
                        item.className = 'user-result';

                        item.innerHTML =
                            '<div class="fw-semibold">' + escapeHtml(u.name || '') + '</div>' +
                            '<div class="small text-muted">' + escapeHtml(u.email || '') + '</div>';

                        item.addEventListener('click', function() {
                            input.value = u.email;
                            results.style.display = 'none';
                            renderSummary(summary, u);
                        });

                        results.appendChild(item);
                    });

                    results.style.display = results.children.length ? 'block' : 'none';
                })
                .catch(function() {
                    results.style.display = 'none';
                });

            }, 250);
        });

        document.addEventListener('click', function(e) {
            if (!block.contains(e.target)) {
                results.style.display = 'none';
            }
        });
    });

})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
