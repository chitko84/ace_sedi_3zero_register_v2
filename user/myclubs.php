<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Fetch user's clubs from the database
$user_id = (int)$_SESSION['user_id'];
$clubs = [];
$user_email = '';

// First, get the user's email from the users table
$user_sql = "SELECT email FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

if ($user) {
    $user_email = $user['email'];

    // Query to get clubs where the user is a member (matching by email in club_members)
    $sql = "SELECT DISTINCT c.*, cm.member_type 
            FROM clubs c 
            JOIN club_members cm ON c.id = cm.club_id 
            WHERE LOWER(TRIM(cm.email)) = LOWER(TRIM(?))
            ORDER BY c.date_of_registration DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $clubs[] = $row;
    }
    $stmt->close();

    // Get member counts for each club
    // (1) by-reference loop to attach member_count
    foreach ($clubs as &$club) {
        $count_sql = "SELECT COUNT(*) as member_count FROM club_members WHERE club_id = ?";
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->bind_param("i", $club['id']);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $club['member_count'] = (int)$count_result->fetch_assoc()['member_count'];
        $count_stmt->close();
    }
    // IMPORTANT: break the reference to prevent accidental overwrites later
    unset($club);
}

// Handle club deletion if requested
if (isset($_GET['delete_club']) && $user_email !== '') {
    $club_id = (int)$_GET['delete_club'];

    // Verify user owns this club before deletion (check if user's email exists in club_members for this club)
    $verify_sql = "SELECT cm.id FROM club_members cm 
                   WHERE cm.club_id = ? AND cm.email = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("is", $club_id, $user_email);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    $verify_stmt->close();

    if ($verify_result->num_rows > 0) {
        // Delete club members first (foreign key constraint)
        $delete_members_sql = "DELETE FROM club_members WHERE club_id = ?";
        $delete_members_stmt = $conn->prepare($delete_members_sql);
        $delete_members_stmt->bind_param("i", $club_id);
        $delete_members_stmt->execute();
        $delete_members_stmt->close();

        // Then delete the club
        $delete_club_sql = "DELETE FROM clubs WHERE id = ?";
        $delete_club_stmt = $conn->prepare($delete_club_sql);
        $delete_club_stmt->bind_param("i", $club_id);

        if ($delete_club_stmt->execute()) {
            $delete_club_stmt->close();
            $_SESSION['success'] = "Club deleted successfully!";
            header('Location: myclubs.php');
            exit();
        } else {
            $delete_club_stmt->close();
            $_SESSION['error'] = "Error deleting club. Please try again.";
        }
    } else {
        $_SESSION['error'] = "You don't have permission to delete this club.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Clubs - 3ZERO Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a5276;
            --primary-dark: #154360;
            --secondary: #28b463;
            --accent: #f39c12;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border-radius: 8px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        .club-card { background: white; border-radius: var(--border-radius); box-shadow: var(--shadow); margin-bottom: 20px; overflow: hidden; transition: var(--transition); }
        .club-card:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .club-header { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; padding: 1.5rem; }
        .club-body { padding: 1.5rem; }
        .club-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin: 15px 0; }
        .stat-item { text-align: center; padding: 10px; background: var(--light); border-radius: var(--border-radius); }
        .stat-number { font-size: 1.5rem; font-weight: bold; color: var(--primary); }
        .stat-label { font-size: 0.8rem; color: var(--gray); }
        .member-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; margin-right: 5px; }
        .badge-primary { background: var(--primary); color: white; }
        .badge-success { background: var(--secondary); color: white; }
        .badge-warning { background: var(--accent); color: white; }
        .badge-secondary { background: var(--gray); color: white; }
        .badge-danger { background: #dc3545; color: white; }
        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; color: var(--gray-light); }
        .action-buttons { display: flex; gap: 10px; margin-top: 15px; }
        .btn-sm { padding: 0.25rem 0.75rem; font-size: 0.875rem; }
        .club-role { font-size: 0.9rem; opacity: 0.9; margin-top: 5px; }
        .search-container { background: white; border-radius: var(--border-radius); padding: 1.5rem; margin-bottom: 20px; box-shadow: var(--shadow); }
        .status-badge { font-size: 0.7rem; padding: 4px 8px; border-radius: 10px; }
        .dropdown-menu { display: none; }
        .dropdown-menu.show { display: block; }
        .club-meta { display:grid; gap:.65rem; margin-bottom:1rem; }
        .club-meta-row { display:flex; justify-content:space-between; gap:1rem; padding:.55rem .7rem; border-radius:8px; background:#f8fafc; }
        .club-meta-row strong { color:var(--primary); }
        .filter-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
        @media (max-width: 767.98px) {
            .page-header { text-align:left!important; }
            .search-container { padding:1rem; }
            .search-container .row { row-gap:.75rem; }
            .club-header { padding:1.1rem; }
            .club-body { padding:1rem; }
            .club-stats { grid-template-columns:1fr; gap:.65rem; }
            .stat-item { display:flex; justify-content:space-between; align-items:center; text-align:left; }
            .stat-number { font-size:1rem; }
            .action-buttons { flex-direction:column; }
            .club-meta-row { flex-direction:column; gap:.15rem; }
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>
    
    <div class="main-content" id="mainContent">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-1">My Clubs</h1>
                <p class="text-muted">Manage and view all your registered clubs</p>
            </div>
            <a href="club_registration.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Register New Club
            </a>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Search and Filter -->
        <div class="search-container">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-5">
                    <label class="form-label fw-semibold" for="searchInput">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="Search club, cluster, focus area, advisor..." id="searchInput">
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label fw-semibold" for="statusFilter">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label fw-semibold" for="clusterFilter">Cluster</label>
                    <select class="form-select" id="clusterFilter">
                        <option value="">All Clusters</option>
                        <option value="Zero Poverty">Zero Poverty</option>
                        <option value="Zero Unemployment">Zero Unemployment</option>
                        <option value="Zero Net Carbon Emissions">Zero Net Carbon Emissions</option>
                    </select>
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label fw-semibold" for="sortFilter">Sort</label>
                    <select class="form-select" id="sortFilter">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="name">Name A-Z</option>
                        <option value="status">Status</option>
                    </select>
                </div>
                <div class="col-12 filter-actions">
                    <button type="button" class="btn btn-primary" id="applyFilters"><i class="fas fa-filter me-1"></i>Apply Filters</button>
                    <button type="button" class="btn btn-light" id="resetFilters"><i class="fas fa-rotate-left me-1"></i>Reset</button>
                    <a href="club_registration.php" class="btn btn-success"><i class="fas fa-plus me-1"></i>Register Club</a>
                </div>
            </div>
        </div>

        <!-- Clubs Grid -->
        <div class="row" id="clubsContainer">
            <?php if (empty($clubs)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Clubs Found</h3>
                        <p>You haven't registered any clubs yet. Start by creating your first club!</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($clubs as $club): ?>
                    <div class="col-12 col-lg-6 col-xl-4 mb-4 club-card-wrap"
                         data-status="<?= htmlspecialchars($club['status']) ?>"
                         data-cluster="<?= htmlspecialchars($club['cluster'] ?? '') ?>"
                         data-search="<?= htmlspecialchars(strtolower(($club['group_name'] ?? '') . ' ' . ($club['cluster'] ?? '') . ' ' . ($club['focus_area'] ?? '') . ' ' . ($club['cluster_advisor'] ?? '') . ' ' . ($club['club_identifier'] ?? ''))) ?>">
                        <div class="club-card">
                            <div class="club-header">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h3 class="h5 mb-2"><?= htmlspecialchars($club['group_name']) ?></h3>
                                        <div class="club-role">
                                            <span class="badge <?= 
                                                $club['member_type'] == 'key_person' ? 'badge-primary' : 
                                                ($club['member_type'] == 'deputy' ? 'badge-success' : 'badge-warning')
                                            ?>">
                                                <?= ucfirst(str_replace('_', ' ', htmlspecialchars($club['member_type']))) ?>
                                            </span>
                                            <span class="status-badge badge <?= 
                                                $club['status'] == 'approved' ? 'badge-success' : 
                                                ($club['status'] == 'pending' ? 'badge-warning' : 'badge-danger')
                                            ?>">
                                                <?= ucfirst(htmlspecialchars($club['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle dropdown-toggle" type="button" 
                                                id="dropdownMenuButton<?= (int)$club['id'] ?>"
                                                data-bs-toggle="dropdown" 
                                                aria-expanded="false">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton<?= (int)$club['id'] ?>">
                                            <li>
                                                <a class="dropdown-item" href="club_details.php?id=<?= (int)$club['id'] ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit_club.php?id=<?= (int)$club['id'] ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit Club
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" 
                                                   onclick="confirmDelete(<?= (int)$club['id'] ?>, '<?= htmlspecialchars($club['group_name'], ENT_QUOTES) ?>')">
                                                    <i class="fas fa-trash me-2"></i>Delete Club
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="club-body">
                                <div class="club-stats">
                                    <div class="stat-item">
                                        <div class="stat-number"><?= (int)$club['member_count'] ?></div>
                                        <div class="stat-label">Members</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number"><?= htmlspecialchars(date('M Y', strtotime($club['date_of_registration']))) ?></div>
                                        <div class="stat-label">Registered</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number">
                                            <?= 
                                                $club['status'] == 'approved' ? 'Active' : 
                                                ($club['status'] == 'pending' ? 'Pending' : 'Rejected')
                                            ?>
                                        </div>
                                        <div class="stat-label">Status</div>
                                    </div>
                                </div>
                                
                                <div class="club-meta">
                                    <div class="club-meta-row">
                                        <strong>Cluster</strong>
                                        <span class="text-muted"><?= htmlspecialchars($club['cluster'] ?: 'Unknown') ?></span>
                                    </div>
                                    <div class="club-meta-row">
                                        <strong>Focus Area</strong>
                                        <span class="text-muted"><?= htmlspecialchars($club['focus_area'] ?: 'Unknown') ?></span>
                                    </div>
                                    <div class="club-meta-row">
                                        <strong>Advisor</strong>
                                        <span class="text-muted"><?= htmlspecialchars($club['cluster_advisor'] ?: 'Unknown') ?></span>
                                    </div>
                                    <div class="club-meta-row">
                                        <strong>Identifier</strong>
                                        <code><?= htmlspecialchars($club['club_identifier'] ?: 'Unknown') ?></code>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Key Persons:</strong>
                                    <div>
                                        <span class="member-badge badge-primary"><?= htmlspecialchars($club['key_person_name']) ?></span>
                                        <?php if (!empty($club['deputy_key_person_name'])): ?>
                                            <span class="member-badge badge-success"><?= htmlspecialchars($club['deputy_key_person_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="action-buttons">
                                    <a href="club_details.php?id=<?= (int)$club['id'] ?>" class="btn btn-outline-primary btn-sm flex-fill">
                                        <i class="fas fa-eye me-1"></i>View
                                    </a>
                                    <a href="edit_club.php?id=<?= (int)$club['id'] ?>" class="btn btn-outline-secondary btn-sm flex-fill">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Initialize all Bootstrap dropdowns
    var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
    var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
        return new bootstrap.Dropdown(dropdownToggleEl);
    });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const clusterFilter = document.getElementById('clusterFilter');
    const sortFilter = document.getElementById('sortFilter');
    const resetFilters = document.getElementById('resetFilters');
    const clubsContainer = document.getElementById('clubsContainer');
    const clubCards = clubsContainer.querySelectorAll('.club-card-wrap');

    function filterClubs() {
        const searchTerm = (searchInput?.value || '').toLowerCase();
        const statusValue = statusFilter?.value || '';
        const clusterValue = clusterFilter?.value || '';
        const sortValue = sortFilter?.value || 'newest';

        let visibleClubs = [];

        clubCards.forEach(card => {
            const nameEl = card.querySelector('.club-header h3');
            const clubName = nameEl ? nameEl.textContent.toLowerCase() : '';
            const haystack = card.getAttribute('data-search') || clubName;
            const clubStatus = card.getAttribute('data-status');
            const clubCluster = card.getAttribute('data-cluster') || '';
            const isVisible = 
                haystack.includes(searchTerm) &&
                (statusValue === '' || clubStatus === statusValue) &&
                (clusterValue === '' || clubCluster === clusterValue);
            
            card.style.display = isVisible ? '' : 'none';
            
            if (isVisible) {
                visibleClubs.push({
                    element: card,
                    name: clubName,
                    status: clubStatus,
                    date: card.querySelector('.stat-item:nth-child(2) .stat-number')?.textContent || ''
                });
            }
        });

        // Sort clubs
        sortClubs(visibleClubs, sortValue);
        
        // Re-append sorted clubs
        visibleClubs.forEach(club => {
            clubsContainer.appendChild(club.element);
        });
    }

    function sortClubs(clubs, sortBy) {
        switch(sortBy) {
            case 'newest':
                clubs.sort((a, b) => new Date(b.date) - new Date(a.date));
                break;
            case 'oldest':
                clubs.sort((a, b) => new Date(a.date) - new Date(b.date));
                break;
            case 'name':
                clubs.sort((a, b) => a.name.localeCompare(b.name));
                break;
            case 'status':
                const statusOrder = { 'approved': 1, 'pending': 2, 'rejected': 3 };
                clubs.sort((a, b) => (statusOrder[a.status] || 99) - (statusOrder[b.status] || 99));
                break;
        }
    }

    // Event listeners for filtering
    if (searchInput) searchInput.addEventListener('input', filterClubs);
    if (statusFilter) statusFilter.addEventListener('change', filterClubs);
    if (clusterFilter) clusterFilter.addEventListener('change', filterClubs);
    if (sortFilter) sortFilter.addEventListener('change', filterClubs);
    if (resetFilters) resetFilters.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        if (clusterFilter) clusterFilter.value = '';
        if (sortFilter) sortFilter.value = 'newest';
        filterClubs();
    });

    // Delete confirmation function
    window.confirmDelete = function(clubId, clubName) {
        if (confirm(`Are you sure you want to delete the club "${clubName}"? This action cannot be undone.`)) {
            window.location.href = `myclubs.php?delete_club=${clubId}`;
        }
    };

    // Fix for dropdown menus - prevent them from closing when clicking inside
    document.querySelectorAll('.dropdown-menu').forEach(function(dropdown) {
        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    // Ensure dropdowns work on mobile
    document.querySelectorAll('.dropdown-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
                if (menu !== this.nextElementSibling) {
                    menu.classList.remove('show');
                }
            }.bind(this));
            const dropdownMenu = this.nextElementSibling;
            if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
                dropdownMenu.classList.toggle('show');
            }
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', function() {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            menu.classList.remove('show');
        });
    });

    // Handle escape key to close dropdowns
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
                menu.classList.remove('show');
            });
        }
    });
});
</script>
<?php include('footer.php'); ?>
</body>
</html>
