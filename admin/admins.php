<?php
// admin/admins.php
require_once __DIR__ . '/header.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_display_value($value) {
    $value = trim((string)$value);
    return $value === '' ? '<span class="text-muted">Not available</span>' : h($value);
}

function admin_users_column_exists(mysqli $conn, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("s", $column);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

$admins = [];
$hasStatusColumn = admin_users_column_exists($conn, 'status');
$hasLastLoginColumn = admin_users_column_exists($conn, 'last_login');
$statusSelect = $hasStatusColumn ? "COALESCE(status, '') AS status" : "'' AS status";
$lastLoginSelect = $hasLastLoginColumn ? "last_login" : "NULL AS last_login";
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT
        id,
        name,
        email,
        phone_number,
        role,
        {$statusSelect},
        {$lastLoginSelect},
        created_at
    FROM users
    WHERE role = ?
";

$role = 'admin';
$params = [$role];
$types = "s";

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$sql .= " ORDER BY created_at DESC, name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $admins[] = $row;
}
$stmt->close();
?>

<main class="main-content container-fluid py-4">
    <style>
        .admin-page-header {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 6px 16px rgba(0,0,0,.08);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }
        .admin-table-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 6px 16px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .admin-toolbar {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 6px 16px rgba(0,0,0,.08);
            padding: 1rem;
            margin-bottom: 1.25rem;
        }
        .admin-count-badge {
            background: #e8f4fd;
            color: #1a5276;
            border-radius: 999px;
            padding: .45rem .75rem;
            font-weight: 700;
        }
        .role-badge {
            background: #1a5276;
            color: #fff;
            border-radius: 999px;
            padding: .35rem .65rem;
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-badge {
            border-radius: 999px;
            padding: .35rem .65rem;
            font-size: .78rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
        }
        .status-badge::before {
            content: "";
            width: .48rem;
            height: .48rem;
            border-radius: 50%;
            background: currentColor;
        }
        .status-active { background:#e7f7ed; color:#146c3c; border:1px solid #c4ebd2; }
        .status-inactive { background:#fdecee; color:#a71d2a; border:1px solid #f6c8ce; }
        .status-unknown { background:#f1f3f5; color:#5c6670; border:1px solid #dde3ea; }
        .you-badge {
            background: #fff4df;
            color: #9a5b00;
            border: 1px solid #f5d79a;
            border-radius: 999px;
            padding: .22rem .5rem;
            font-size: .72rem;
            font-weight: 700;
            margin-left: .35rem;
            vertical-align: middle;
        }
        .empty-admins {
            padding: 3rem 1rem;
            text-align: center;
            color: #6c757d;
        }
        @media (max-width: 767.98px) {
            .admin-page-header {
                padding: 1rem;
            }
        }
    </style>

    <div class="admin-page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Admins</li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">Admins</h1>
            <p class="text-muted mb-0">View all administrator accounts in the system.</p>
        </div>
        <div class="admin-count-badge">
            <i class="fa-solid fa-user-shield me-1"></i>
            <?= count($admins) ?> Admin<?= count($admins) === 1 ? '' : 's' ?>
        </div>
    </div>

    <form method="get" class="admin-toolbar">
        <label for="adminSearch" class="form-label fw-semibold">Search Admins</label>
        <div class="row g-2 align-items-center">
            <div class="col-12 col-md">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input
                        type="search"
                        class="form-control"
                        id="adminSearch"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Search by name or email"
                    >
                </div>
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter me-1"></i>Search
                </button>
                <a href="admins.php" class="btn btn-light">
                    <i class="fa-solid fa-rotate-left me-1"></i>Reset
                </a>
            </div>
        </div>
    </form>

    <div class="admin-table-card">
        <?php if (empty($admins)): ?>
            <div class="empty-admins">
                <i class="fa-solid fa-user-shield mb-3" style="font-size:3rem;color:#d7dee8;"></i>
                <h2 class="h5">No admin accounts found.</h2>
                <p class="mb-0"><?= $search !== '' ? 'No admins match your search.' : 'Admin users will appear here when their role is set to admin.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone Number</th>
                            <th>Created Date</th>
                            <?php if ($hasLastLoginColumn): ?>
                                <th>Last Login</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <?php
                                $status = strtolower(trim((string)($admin['status'] ?? '')));
                                $statusLabel = $status === '' ? 'Not available' : ucfirst($status);
                                $statusClass = 'status-unknown';
                                if (in_array($status, ['active', 'approved', 'enabled'], true)) {
                                    $statusClass = 'status-active';
                                } elseif (in_array($status, ['inactive', 'disabled', 'suspended', 'blocked'], true)) {
                                    $statusClass = 'status-inactive';
                                }

                                $createdAt = '';
                                if (!empty($admin['created_at'])) {
                                    $timestamp = strtotime($admin['created_at']);
                                    $createdAt = $timestamp ? date('M j, Y', $timestamp) : '';
                                }

                                $lastLogin = '';
                                if ($hasLastLoginColumn && !empty($admin['last_login'])) {
                                    $loginTimestamp = strtotime($admin['last_login']);
                                    $lastLogin = $loginTimestamp ? date('M j, Y g:i A', $loginTimestamp) : '';
                                }
                            ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= admin_display_value($admin['name'] ?? '') ?>
                                    <?php if ((int)($admin['id'] ?? 0) === (int)$admin_id): ?>
                                        <span class="you-badge">You</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= admin_display_value($admin['email'] ?? '') ?></td>
                                <td><?= admin_display_value($admin['phone_number'] ?? '') ?></td>
                                <td><?= $createdAt !== '' ? h($createdAt) : '<span class="text-muted">Not available</span>' ?></td>
                                <?php if ($hasLastLoginColumn): ?>
                                    <td><?= $lastLogin !== '' ? h($lastLogin) : '<span class="text-muted">Not available</span>' ?></td>
                                <?php endif; ?>
                                <td>
                                    <span class="status-badge <?= h($statusClass) ?>">
                                        <?= h($statusLabel) ?>
                                    </span>
                                </td>
                                <td><span class="role-badge"><?= h($admin['role'] ?? 'admin') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
