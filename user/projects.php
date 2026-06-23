<?php
// projects.php - Updated with approval system and simplified statuses
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../includes/db.php';
require_once __DIR__ . '/../includes/image_upload_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_email = '';

// Get user's email
$user_sql = "SELECT email FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

if ($user) {
    $user_email = $user['email'];
}

// Fetch user's clubs
$clubs = [];
$sql = "SELECT DISTINCT c.*, cm.member_type 
        FROM clubs c 
        JOIN club_members cm ON c.id = cm.club_id 
        WHERE cm.email = ? 
        ORDER BY c.group_name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_email);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $clubs[] = $row;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_activity'])) {
        // Add new activity - set to pending approval
        $club_id = $_POST['club_id'];
        $activity_name = $_POST['activity_name'];
        $objectives = $_POST['objectives'];
        $description = $_POST['description'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'] ?? 'ongoing'; // Default to ongoing
        
        // Validate that photos are uploaded
        if (!isset($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
            $_SESSION['error'] = "Please upload at least one photo for the activity.";
            header('Location: projects.php');
            exit();
        }
        
        // Insert with pending approval status
        $insert_sql = "INSERT INTO projects (club_id, project_name, objectives, description, start_date, end_date, status, created_by, approval_status) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssssi", $club_id, $activity_name, $objectives, $description, $start_date, $end_date, $status, $user_id);
        
        if ($insert_stmt->execute()) {
            $activity_id = $insert_stmt->insert_id;
            
            // Handle photo uploads (now mandatory)
            $files = $_FILES['photos'];
            
            // Count non-empty uploads
            $fileCount = 0;
            $totalBytes = 0;
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK && $files['size'][$i] > 0 && $files['name'][$i] !== '') {
                    if ((int)$files['size'][$i] > IMAGE_UPLOAD_MAX_BYTES) {
                        $_SESSION['error'] = IMAGE_UPLOAD_SIZE_ERROR;
                        $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                        header('Location: projects.php');
                        exit();
                    }
                    $fileCount++;
                    $totalBytes += (int)$files['size'][$i];
                }
            }

            // Validate file count (at least 1, max 3)
            if ($fileCount < 1) {
                $_SESSION['error'] = "Please upload at least one photo.";
                // Rollback activity
                $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                header('Location: projects.php');
                exit();
            }
            
            if ($fileCount > 3) {
                $_SESSION['error'] = "Please upload maximum 3 photos.";
                // Rollback activity
                $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                header('Location: projects.php');
                exit();
            }

            // 5 MB total limit
            $maxTotal = 3 * IMAGE_UPLOAD_MAX_BYTES;
            if ($totalBytes > $maxTotal) {
                $_SESSION['error'] = IMAGE_UPLOAD_SIZE_ERROR;
                // Rollback
                $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                header('Location: projects.php');
                exit();
            }

            // Prepare uploads dir
            $uploadDir = __DIR__ . '/../uploads/activities';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }

            // Validate and move each file
            $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $saved = 0;

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK && $files['size'][$i] > 0 && $files['name'][$i] !== '') {
                    $tmpPath = $files['tmp_name'][$i];
                    if ((int)$files['size'][$i] > IMAGE_UPLOAD_MAX_BYTES) {
                        $_SESSION['error'] = IMAGE_UPLOAD_SIZE_ERROR;
                        $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                        header('Location: projects.php');
                        exit();
                    }
                    $mime = $finfo->file($tmpPath);
                    if (!in_array($mime, $allowedMime, true)) {
                        $_SESSION['error'] = "Only JPG, PNG, or WEBP images are allowed.";
                        // Rollback activity + any saved photos
                        $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                        header('Location: projects.php');
                        exit();
                    }

                    $ext = match ($mime) {
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        default      => 'bin'
                    };

                    $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($files['name'][$i], PATHINFO_FILENAME));
                    $newName = uniqid('act_', true) . '_' . $safeBase . '.' . $ext;
                    $destAbs = $uploadDir . '/' . $newName;
                    $destRel = 'uploads/activities/' . $newName;

                    if (!move_uploaded_file($tmpPath, $destAbs)) {
                        $_SESSION['error'] = "Failed to upload one of the images.";
                        // Rollback
                        $conn->query("DELETE FROM projects WHERE id = " . intval($activity_id));
                        header('Location: projects.php');
                        exit();
                    }

                    // Save photo record
                    $photo_sql  = "INSERT INTO activity_photos (activity_id, file_path, original_name) VALUES (?, ?, ?)";
                    $photo_stmt = $conn->prepare($photo_sql);
                    $orig = $files['name'][$i];
                    $photo_stmt->bind_param("iss", $activity_id, $destRel, $orig);
                    $photo_stmt->execute();
                    $saved++;
                }
            }
            
            $_SESSION['success'] = "Activity submitted successfully! Waiting for admin approval.";
        } else {
            $_SESSION['error'] = "Error adding activity. Please try again.";
        }
    }
    
    if (isset($_POST['update_activity'])) {
        // Update activity - set back to pending when edited
        $activity_id = $_POST['activity_id'];
        $activity_name = $_POST['activity_name'];
        $objectives = $_POST['objectives'];
        $description = $_POST['description'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'];
        
        $update_sql = "UPDATE projects SET project_name=?, objectives=?, description=?, start_date=?, end_date=?, status=?, approval_status='pending', rejection_reason=NULL WHERE id=?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssssssi", $activity_name, $objectives, $description, $start_date, $end_date, $status, $activity_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Activity updated successfully! Resubmitted for admin approval.";
        } else {
            $_SESSION['error'] = "Error updating activity. Please try again.";
        }
    }

    if (isset($_POST['reupload_activity_photos'])) {
        $activity_id = (int)($_POST['activity_id'] ?? 0);

        $verify_sql = "SELECT id FROM projects WHERE id = ? AND created_by = ? LIMIT 1";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $activity_id, $user_id);
        $verify_stmt->execute();

        if ($verify_stmt->get_result()->num_rows < 1) {
            $_SESSION['error'] = "You don't have permission to replace photos for this activity.";
        } else {
            $validatedPhotos = image_upload_validate_many($_FILES['photos'] ?? [], 1, 3);

            if (!$validatedPhotos['ok']) {
                $_SESSION['error'] = $validatedPhotos['error'];
            } else {
                $oldPhotos = [];
                $photos_stmt = $conn->prepare("SELECT file_path FROM activity_photos WHERE activity_id = ?");
                $photos_stmt->bind_param("i", $activity_id);
                $photos_stmt->execute();
                $photos_result = $photos_stmt->get_result();
                while ($photo = $photos_result->fetch_assoc()) {
                    $oldPhotos[] = $photo['file_path'];
                }

                $uploadDir = __DIR__ . '/../uploads/activities';
                $conn->begin_transaction();

                try {
                    $delete_stmt = $conn->prepare("DELETE FROM activity_photos WHERE activity_id = ?");
                    $delete_stmt->bind_param("i", $activity_id);
                    $delete_stmt->execute();

                    foreach ($validatedPhotos['files'] as $photoFile) {
                        $moved = image_upload_move_validated($photoFile, $uploadDir, 'uploads/activities', 'act');
                        if (!$moved['ok']) {
                            throw new RuntimeException($moved['error']);
                        }

                        $photo_sql  = "INSERT INTO activity_photos (activity_id, file_path, original_name) VALUES (?, ?, ?)";
                        $photo_stmt = $conn->prepare($photo_sql);
                        $photo_stmt->bind_param("iss", $activity_id, $moved['db_path'], $moved['original_name']);
                        $photo_stmt->execute();
                    }

                    $conn->commit();
                    foreach ($oldPhotos as $oldPath) {
                        image_upload_delete_db_path($oldPath, __DIR__ . '/..');
                    }
                    $_SESSION['success'] = "Activity images updated successfully.";
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['error'] = "Could not update activity images. Please try again.";
                }
            }
        }
    }
    
    if (isset($_POST['delete_activity'])) {
        // Delete activity
        $activity_id = $_POST['activity_id'];
        
        // Verify user has permission to delete this activity
        $verify_sql = "SELECT p.id FROM projects p 
                       JOIN clubs c ON p.club_id = c.id 
                       JOIN club_members cm ON c.id = cm.club_id 
                       WHERE p.id = ? AND cm.email = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("is", $activity_id, $user_email);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            // Get photos to delete files
            $photos_sql = "SELECT file_path FROM activity_photos WHERE activity_id = ?";
            $photos_stmt = $conn->prepare($photos_sql);
            $photos_stmt->bind_param("i", $activity_id);
            $photos_stmt->execute();
            $photos_result = $photos_stmt->get_result();
            
            // Delete physical files
            while ($photo = $photos_result->fetch_assoc()) {
                $file_path = __DIR__ . '/../' . $photo['file_path'];
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            $delete_sql = "DELETE FROM projects WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $activity_id);
            
            if ($delete_stmt->execute()) {
                $_SESSION['success'] = "Activity deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting activity. Please try again.";
            }
        } else {
            $_SESSION['error'] = "You don't have permission to delete this activity.";
        }
    }
    
    // Redirect to avoid form resubmission
    header('Location: projects.php');
    exit();
}

// Fetch activities for the user's clubs - show approved OR user's own activities
$activities = [];
$photosByActivity = [];

if (!empty($clubs)) {
    $club_ids = array_column($clubs, 'id');
    $placeholders = str_repeat('?,', count($club_ids) - 1) . '?';
    
    // Modified query to show approved activities OR user's own activities
    $activities_sql = "SELECT p.*, c.group_name 
                     FROM projects p 
                     JOIN clubs c ON p.club_id = c.id 
                     WHERE (p.club_id IN ($placeholders) AND p.approval_status = 'approved')
                     OR (p.created_by = ?)
                     ORDER BY p.approval_status, p.created_at DESC";
    $activities_stmt = $conn->prepare($activities_sql);
    
    // Bind club IDs and user ID
    $params = array_merge($club_ids, [$user_id]);
    $activities_stmt->bind_param(str_repeat('i', count($club_ids)) . 'i', ...$params);
    $activities_stmt->execute();
    $activities_result = $activities_stmt->get_result();
    
    while ($row = $activities_result->fetch_assoc()) {
        $activities[] = $row;
    }

    // Fetch photos for activities
    if (!empty($activities)) {
        $activity_ids = array_column($activities, 'id');
        $photo_placeholders = str_repeat('?,', count($activity_ids) - 1) . '?';
        
        $photos_sql = "SELECT activity_id, file_path, original_name 
                      FROM activity_photos 
                      WHERE activity_id IN ($photo_placeholders)
                      ORDER BY id ASC";
        $photos_stmt = $conn->prepare($photos_sql);
        $photos_stmt->bind_param(str_repeat('i', count($activity_ids)), ...$activity_ids);
        $photos_stmt->execute();
        $photos_result = $photos_stmt->get_result();
        
        while ($photo = $photos_result->fetch_assoc()) {
            $activity_id = $photo['activity_id'];
            if (!isset($photosByActivity[$activity_id])) {
                $photosByActivity[$activity_id] = [];
            }
            $photosByActivity[$activity_id][] = $photo;
        }
    }
}

// Get activity data for editing (if activity_id is provided via GET for editing)
$edit_activity_data = null;
if (isset($_GET['edit_activity'])) {
    $activity_id = $_GET['edit_activity'];
    
    // Verify user has access to this activity
    $verify_sql = "SELECT p.*, c.group_name 
                   FROM projects p 
                   JOIN clubs c ON p.club_id = c.id 
                   JOIN club_members cm ON c.id = cm.club_id 
                   WHERE p.id = ? AND cm.email = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("is", $activity_id, $user_email);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        $edit_activity_data = $verify_result->fetch_assoc();
    }
}

// Helper function for approval status badges
function getApprovalBadgeClass($status) {
    switch ($status) {
        case 'approved': return 'bg-success';
        case 'rejected': return 'bg-danger';
        case 'pending': 
        default: return 'bg-warning';
    }
}

// Helper function for activity status badges
function getActivityStatusClass($status) {
    switch ($status) {
        case 'completed': return 'bg-success';
        case 'ongoing': 
        default: return 'bg-primary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities / Participations - 3ZERO Club</title>
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

        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding-left: 12px;
            padding-right: 12px;
        }

        .activity-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
            transition: var(--transition);
            border-left: 4px solid var(--primary);
            position: relative;
        }

        .activity-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .activity-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .activity-body { 
            padding: 1.5rem; 
        }

        .status-badge {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

        .badge-ongoing { background: #17a2b8; color: white; }
        .badge-completed { background: var(--secondary); color: white; }

        /* Approval status styles */
        .approval-badge {
            font-size: 0.65rem;
            padding: 3px 6px;
            border-radius: 6px;
            font-weight: 600;
        }

        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; color: var(--gray-light); }

        .action-buttons { display: flex; gap: 10px; margin-top: 15px; }
        .btn-sm { padding: 0.25rem 0.75rem; font-size: 0.875rem; }

        .search-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .activity-dates { font-size: 0.85rem; color: var(--gray); }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        /* Activity photos grid */
        .activity-photos {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 12px;
        }
        .activity-photo {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
        }
        .photo-count {
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.7);
            color: white;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
        }

        /* Photo preview in modal */
        .photo-preview {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .photo-preview img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
        }

        /* Rejection reason */
        .rejection-reason {
            font-size: 0.8rem;
            color: #dc3545;
            background: #f8f9fa;
            border-left: 3px solid #dc3545;
            padding: 8px 12px;
            margin-top: 8px;
            border-radius: 4px;
        }

        /* Dropdown styles */
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            left: auto;
            z-index: 1000;
        }
        
        .dropdown-menu.show {
            display: block;
        }

        .dropdown-toggle::after { display: none !important; }

        /* Title wrapper to prevent overlap with action buttons */
        .title-wrapper {
            padding-right: 80px;
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>

    <!-- Main Content Wrapper -->
    <!-- Note about converting photos -->
    <div class="alert alert-info mt-2 p-2 small">
        <strong><i class="bi bi-info-circle me-1"></i>Having trouble uploading?</strong><br>
        If your photos won't upload, try compressing them<br>
        You can compress your image at this link (https://imagecompressor.com/)
    </div>
    
    <div class="main-content container my-4" id="mainContent">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-1">Activities / Participations</h1>
                <p class="text-muted">Manage and track your club activities and participations. Activities require admin approval.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                <i class="fas fa-plus me-2"></i>Add New Activity
            </button>
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
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="Search activities by name..." id="searchInput">
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="approvalFilter">
                        <option value="">All Approval</option>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Activities Grid -->
        <div class="row" id="activitiesContainer">
            <?php if (empty($activities)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <h3>No Activities Found</h3>
                        <p>You haven't created any activities yet. Start by adding your first activity!</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($activities as $activity): ?>
                    <?php 
                    $activity_photos = $photosByActivity[$activity['id']] ?? []; 
                    $photos_json = htmlspecialchars(json_encode($activity_photos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                    $isOwner = ($activity['created_by'] == $user_id);
                    ?>
                    <div class="col-lg-6 col-xl-4 mb-4" data-status="<?= htmlspecialchars($activity['status']) ?>" data-club="<?= (int)$activity['club_id'] ?>" data-approval="<?= htmlspecialchars($activity['approval_status']) ?>">
                        <div class="activity-card">
                            <div class="activity-header">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="title-wrapper">
                                        <h3 class="h5 mb-2"><?= htmlspecialchars($activity['project_name']) ?></h3>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="status-badge badge badge-<?= htmlspecialchars($activity['status']) ?>">
                                                <?= ucfirst($activity['status']) ?>
                                            </span>
                                            <span class="approval-badge badge <?= getApprovalBadgeClass($activity['approval_status']) ?>">
                                                <?= ucfirst($activity['approval_status']) ?>
                                            </span>
                                            <small class="text-muted"><?= htmlspecialchars($activity['group_name']) ?></small>
                                        </div>

                                        <!-- Rejection reason -->
                                        <?php if ($activity['approval_status'] === 'rejected' && !empty($activity['rejection_reason']) && $isOwner): ?>
                                            <div class="rejection-reason mt-2">
                                                <strong><i class="fas fa-comment me-1"></i>Reason:</strong> 
                                                <?= nl2br(htmlspecialchars($activity['rejection_reason'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Actions dropdown - only show for owner -->
                                    <?php if ($isOwner): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle dropdown-toggle" type="button" 
                                                id="dropdownMenuButton<?= (int)$activity['id'] ?>">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        
                                        <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton<?= (int)$activity['id'] ?>">
                                            <li>
                                                <a class="dropdown-item" href="projects.php?edit_activity=<?= (int)$activity['id'] ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit Activity
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#reuploadActivityPhotosModal"
                                                   onclick="setReuploadActivityId(<?= (int)$activity['id'] ?>); return false;">
                                                    <i class="fas fa-image me-2"></i>Replace Images
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#"
                                                   onclick="confirmDelete(<?= (int)$activity['id'] ?>, '<?= htmlspecialchars($activity['project_name'], ENT_QUOTES) ?>'); return false;">
                                                    <i class="fas fa-trash me-2"></i>Delete Activity
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="activity-body">
                                <?php if (!empty($activity['objectives'])): ?>
                                    <div class="mb-3">
                                        <strong>Objectives:</strong>
                                        <p class="text-muted mb-1"><?= htmlspecialchars(mb_strimwidth($activity['objectives'] ?? '', 0, 120, '...')) ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($activity['description'])): ?>
                                    <div class="mb-3">
                                        <strong>Description:</strong>
                                        <p class="text-muted mb-1"><?= htmlspecialchars(mb_strimwidth($activity['description'] ?? '', 0, 120, '...')) ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="activity-dates mb-3">
                                    <div><i class="fas fa-calendar-alt me-1"></i> Start: <?= $activity['start_date'] ? date('M j, Y', strtotime($activity['start_date'])) : '—' ?></div>
                                    <div><i class="fas fa-flag-checkered me-1"></i> End: <?= $activity['end_date'] ? date('M j, Y', strtotime($activity['end_date'])) : '—' ?></div>
                                </div>

                                <!-- Activity Photos Preview -->
                                <?php if (!empty($activity_photos)): ?>
                                    <div class="activity-photos">
                                        <?php 
                                        $display_photos = array_slice($activity_photos, 0, 3);
                                        foreach ($display_photos as $index => $photo): 
                                        ?>
                                            <div>
                                                <img src="../<?= htmlspecialchars($photo['file_path']) ?>" 
                                                     alt="Activity photo <?= $index + 1 ?>"
                                                     class="activity-photo"
                                                     onclick="window.open('../<?= htmlspecialchars($photo['file_path']) ?>', '_blank')">
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (count($activity_photos) > 3): ?>
                                            <div class="photo-count">
                                                +<?= count($activity_photos) - 3 ?> more
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="action-buttons">
                                    <!-- View Button -->
                                    <button 
                                        class="btn btn-primary btn-sm flex-fill"
                                        onclick="openViewModal(this)"
                                        data-activity-id="<?= (int)$activity['id'] ?>"
                                        data-name="<?= htmlspecialchars($activity['project_name'], ENT_QUOTES) ?>"
                                        data-club="<?= htmlspecialchars($activity['group_name'], ENT_QUOTES) ?>"
                                        data-status="<?= htmlspecialchars($activity['status'], ENT_QUOTES) ?>"
                                        data-approval="<?= htmlspecialchars($activity['approval_status'], ENT_QUOTES) ?>"
                                        data-start="<?= htmlspecialchars($activity['start_date'] ?? '', ENT_QUOTES) ?>"
                                        data-end="<?= htmlspecialchars($activity['end_date'] ?? '', ENT_QUOTES) ?>"
                                        data-objectives="<?= htmlspecialchars($activity['objectives'] ?? '', ENT_QUOTES) ?>"
                                        data-description="<?= htmlspecialchars($activity['description'] ?? '', ENT_QUOTES) ?>"
                                        data-rejection-reason="<?= htmlspecialchars($activity['rejection_reason'] ?? '', ENT_QUOTES) ?>"
                                        data-photos='<?= $photos_json ?>'
                                    >
                                        <i class="fas fa-eye me-1"></i>View
                                    </button>

                                    <!-- Edit Button - only for owner -->
                                    <?php if ($isOwner): ?>
                                    <a href="projects.php?edit_activity=<?= (int)$activity['id'] ?>" class="btn btn-outline-primary btn-sm flex-fill">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </a>
                                    <button class="btn btn-outline-danger btn-sm flex-fill" 
                                            onclick="confirmDelete(<?= (int)$activity['id'] ?>, '<?= htmlspecialchars($activity['project_name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-trash me-1"></i>Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Activity Modal -->
    <div class="modal fade" id="addActivityModal" tabindex="-1" aria-labelledby="addActivityModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="projects.php" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addActivityModalLabel">Add New Activity / Participation</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Activities require admin approval before being visible to others.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="club_id" class="form-label">Club</label>
                                    <select class="form-select" id="club_id" name="club_id" required>
                                        <option value="">Select Club</option>
                                        <?php foreach ($clubs as $club): ?>
                                            <option value="<?= (int)$club['id'] ?>"><?= htmlspecialchars($club['group_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="activity_name" class="form-label">Activity Name</label>
                                    <input type="text" class="form-control" id="activity_name" name="activity_name" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="objectives" class="form-label">Objectives</label>
                            <textarea class="form-control" id="objectives" name="objectives" rows="2" placeholder="Enter the main objectives of this activity..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="Provide a detailed description of the activity such as the time, venue, organizer, program name (if any), etc..."></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <!-- Photo Upload Field -->
                        <div class="mb-3">
                            <label for="photos" class="form-label">Photos <span class="text-danger">*</span> <small class="text-muted">(1-3, each <= 1MB)</small></label>
                            <input type="file" name="photos[]" id="photos" class="form-control" accept=".jpg,.jpeg,.png,.webp" multiple required>
                            <div class="form-text"><?= htmlspecialchars(IMAGE_UPLOAD_DISCLAIMER, ENT_QUOTES, 'UTF-8') ?></div>
                            
                            <!-- Note about converting photos -->
                            <div class="alert alert-info mt-2 p-2 small">
                                <strong><i class="bi bi-info-circle me-1"></i>Having trouble uploading?</strong><br>
                                If your photos are larger than 1MB, try compressing them.<br>
                                You can compress your image at this website (https://imagecompressor.com/)
                            </div>
                            
                            <div id="photoPreview" class="photo-preview"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_activity" class="btn btn-primary">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Activity Modal -->
    <div class="modal fade <?= $edit_activity_data ? 'show' : '' ?>" id="editActivityModal" tabindex="-1" aria-labelledby="editActivityModalLabel" aria-hidden="true" <?= $edit_activity_data ? 'style="display: block;"' : '' ?>>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="projects.php">
                    <input type="hidden" id="edit_activity_id" name="activity_id" value="<?= $edit_activity_data['id'] ?? '' ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editActivityModalLabel">Edit Activity / Participation</h5>
                        <button type="button" class="btn-close btn-close-white" onclick="closeEditModal()" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Editing will set this activity back to <strong>pending</strong> and require admin approval again.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_club_id" class="form-label">Club</label>
                                    <select class="form-select" id="edit_club_id" name="club_id" required>
                                        <option value="">Select Club</option>
                                        <?php foreach ($clubs as $club): ?>
                                            <option value="<?= (int)$club['id'] ?>" <?= ($edit_activity_data['club_id'] ?? '') == $club['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($club['group_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_activity_name" class="form-label">Activity Name</label>
                                    <input type="text" class="form-control" id="edit_activity_name" name="activity_name" value="<?= htmlspecialchars($edit_activity_data['project_name'] ?? '') ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_objectives" class="form-label">Objectives</label>
                            <textarea class="form-control" id="edit_objectives" name="objectives" rows="2" placeholder="Enter the main objectives of this activity..."><?= htmlspecialchars($edit_activity_data['objectives'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" placeholder="Provide a detailed description of the activity..."><?= htmlspecialchars($edit_activity_data['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="edit_start_date" name="start_date" value="<?= $edit_activity_data['start_date'] ?? '' ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="edit_end_date" name="end_date" value="<?= $edit_activity_data['end_date'] ?? '' ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="ongoing" <?= ($edit_activity_data['status'] ?? '') == 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                <option value="completed" <?= ($edit_activity_data['status'] ?? '') == 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>

                        <!-- Show existing photos -->
                        <?php if ($edit_activity_data): ?>
                            <?php $edit_photos = $photosByActivity[$edit_activity_data['id']] ?? []; ?>
                            <?php if (!empty($edit_photos)): ?>
                                <div class="mb-3">
                                    <label class="form-label">Current Photos</label>
                                    <div class="activity-photos">
                                        <?php foreach ($edit_photos as $photo): ?>
                                            <div>
                                                <img src="../<?= htmlspecialchars($photo['file_path']) ?>" 
                                                     alt="Activity photo"
                                                     class="activity-photo"
                                                     onclick="window.open('../<?= htmlspecialchars($photo['file_path']) ?>', '_blank')">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Note: Photos cannot be edited. To change photos, delete and recreate the activity.</div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="update_activity" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reuploadActivityPhotosModal" tabindex="-1" aria-labelledby="reuploadActivityPhotosLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="projects.php" enctype="multipart/form-data">
                    <input type="hidden" name="activity_id" id="reupload_activity_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reuploadActivityPhotosLabel">Replace Activity Images</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">New Photos (1-3, JPG/PNG/WEBP, each <= 1MB)</label>
                        <input type="file" name="photos[]" id="reupload_activity_photos" class="form-control" accept=".jpg,.jpeg,.png,.webp" multiple required>
                        <div class="form-text"><?= htmlspecialchars(IMAGE_UPLOAD_DISCLAIMER, ENT_QUOTES, 'UTF-8') ?></div>
                        <div id="reuploadActivityPreview" class="photo-preview mt-2"></div>
                        <small class="text-muted d-block mt-2">Existing activity images will be replaced after you submit.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reupload_activity_photos" class="btn btn-primary">Replace Images</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="projects.php">
                    <input type="hidden" id="delete_activity_id" name="activity_id">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the activity "<span id="delete_activity_name" class="fw-bold"></span>"?</p>
                        <p class="text-danger">This action cannot be undone. All photos and data will be permanently deleted.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_activity" class="btn btn-danger">Delete Activity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Activity Modal -->
    <div class="modal fade" id="viewActivityModal" tabindex="-1" aria-labelledby="viewActivityModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewActivityModalLabel">Activity Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><strong>Name:</strong> <span id="vName">—</span></div>
                    <div class="mb-2"><strong>Club:</strong> <span id="vClub">—</span></div>
                    <div class="mb-2"><strong>Status:</strong> <span id="vStatus">—</span></div>
                    <div class="mb-2"><strong>Approval:</strong> <span id="vApproval">—</span></div>
                    <div class="mb-3">
                        <strong>Dates:</strong> 
                        <span id="vDates">—</span>
                    </div>

                    <div class="mb-3" id="vRejectionReasonRow" style="display: none;">
                        <strong>Rejection Reason:</strong>
                        <div id="vRejectionReason" class="rejection-reason"></div>
                    </div>

                    <div class="mb-3">
                        <strong>Objectives:</strong>
                        <pre id="vObjectives" class="mb-0" style="white-space: pre-wrap; font-family: inherit;">—</pre>
                    </div>

                    <div class="mb-3">
                        <strong>Description:</strong>
                        <pre id="vDescription" class="mb-0" style="white-space: pre-wrap; font-family: inherit;">—</pre>
                    </div>

                    <div class="mb-2"><strong>Photos:</strong></div>
                    <div id="vPhotos" class="activity-photos"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search and Filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const approvalFilter = document.getElementById('approvalFilter');
            const activityCols = document.querySelectorAll('#activitiesContainer .col-lg-6, #activitiesContainer .col-xl-4');
            
            function filterActivities() {
                const searchTerm = (searchInput.value || '').toLowerCase();
                const statusValue = statusFilter.value;
                const approvalValue = approvalFilter.value;
                
                activityCols.forEach(col => {
                    const titleEl = col.querySelector('h3');
                    const activityName = titleEl ? titleEl.textContent.toLowerCase() : '';
                    const activityStatus = col.getAttribute('data-status');
                    const activityApproval = col.getAttribute('data-approval');

                    const matchesSearch = activityName.includes(searchTerm);
                    const matchesStatus = !statusValue || activityStatus === statusValue;
                    const matchesApproval = !approvalValue || activityApproval === approvalValue;

                    const show = matchesSearch && matchesStatus && matchesApproval;
                    col.classList.toggle('d-none', !show);
                });
            }
            
            searchInput.addEventListener('input', filterActivities);
            statusFilter.addEventListener('change', filterActivities);
            approvalFilter.addEventListener('change', filterActivities);

            function previewProjectImages(input, previewId) {
                const preview = document.getElementById(previewId);
                if (!preview) return;
                preview.innerHTML = '';
                const files = Array.from(input.files || []);

                if (files.length < 1 || files.length > 3) {
                    alert('Please upload between 1 and 3 images.');
                    input.value = '';
                    return;
                }

                if (files.some(file => file.size > 1024 * 1024)) {
                    alert('Image size must be less than or equal to 1MB. Please compress the image and upload again.');
                    input.value = '';
                    return;
                }

                if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
                    alert('Only JPG, JPEG, PNG, and WEBP images are allowed.');
                    input.value = '';
                    return;
                }

                files.forEach(file => {
                    const url = URL.createObjectURL(file);
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = 'Preview';
                    img.onload = () => URL.revokeObjectURL(url);
                    preview.appendChild(img);
                });
            }

            document.getElementById('photos')?.addEventListener('change', function() {
                previewProjectImages(this, 'photoPreview');
            });

            document.getElementById('reupload_activity_photos')?.addEventListener('change', function() {
                previewProjectImages(this, 'reuploadActivityPreview');
            });

            <?php if ($edit_activity_data): ?>
                // Show edit modal if edit activity data is loaded
                const editModal = new bootstrap.Modal(document.getElementById('editActivityModal'));
                editModal.show();
            <?php endif; ?>

            // Manual dropdown initialization
            var dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(function(dropdown) {
                dropdown.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var menu = this.nextElementSibling;
                    menu.classList.toggle('show');
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.matches('.dropdown-toggle') && !e.target.closest('.dropdown-menu')) {
                    var openMenus = document.querySelectorAll('.dropdown-menu.show');
                    openMenus.forEach(function(menu) {
                        menu.classList.remove('show');
                    });
                }
            });
        });
        
        function confirmDelete(activityId, activityName) {
            document.getElementById('delete_activity_id').value = activityId;
            document.getElementById('delete_activity_name').textContent = activityName;
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            deleteModal.show();
        }

        function setReuploadActivityId(activityId) {
            document.getElementById('reupload_activity_id').value = activityId;
        }
        
        function closeEditModal() {
            const editModalEl = document.getElementById('editActivityModal');
            const editModal = bootstrap.Modal.getInstance(editModalEl) || new bootstrap.Modal(editModalEl);
            editModal.hide();
            // Remove the edit parameter from URL
            const url = new URL(window.location.href);
            url.searchParams.delete('edit_activity');
            window.history.replaceState({}, document.title, url.toString());
        }

        // VIEW feature
        function openViewModal(btn) {
            const name = btn.getAttribute('data-name') || '—';
            const club = btn.getAttribute('data-club') || '—';
            const status = btn.getAttribute('data-status') || '—';
            const approval = btn.getAttribute('data-approval') || '—';
            const start = btn.getAttribute('data-start') || '';
            const end = btn.getAttribute('data-end') || '';
            const objectives = btn.getAttribute('data-objectives') || '—';
            const description = btn.getAttribute('data-description') || '—';
            const rejectionReason = btn.getAttribute('data-rejection-reason') || '';
            let photos = [];

            try {
                photos = JSON.parse(btn.getAttribute('data-photos') || '[]');
            } catch(e) {
                photos = [];
            }

            // Dates formatting
            function fmt(d) {
                if (!d) return '';
                const dd = new Date(d + 'T00:00:00');
                if (isNaN(dd.getTime())) return d;
                return dd.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            const dates = (start || end) ? `${fmt(start) || '—'} — ${fmt(end) || '—'}` : '—';

            // Populate modal
            document.getElementById('vName').textContent = name;
            document.getElementById('vClub').textContent = club;
            document.getElementById('vStatus').textContent = (status || '').replace(/^./, c => c.toUpperCase()) || '—';
            document.getElementById('vApproval').textContent = (approval || '').replace(/^./, c => c.toUpperCase()) || '—';
            document.getElementById('vDates').textContent = dates;
            document.getElementById('vObjectives').textContent = objectives || '—';
            document.getElementById('vDescription').textContent = description || '—';

            // Rejection reason
            const rejectionRow = document.getElementById('vRejectionReasonRow');
            const rejectionEl = document.getElementById('vRejectionReason');
            if (rejectionReason) {
                rejectionEl.innerHTML = rejectionReason.replace(/\n/g, '<br>');
                rejectionRow.style.display = 'block';
            } else {
                rejectionRow.style.display = 'none';
            }

            // Photos
            const vPhotos = document.getElementById('vPhotos');
            vPhotos.innerHTML = '';
            if (Array.isArray(photos) && photos.length) {
                photos.forEach((p, idx) => {
                    const wrap = document.createElement('div');
                    const img = document.createElement('img');
                    img.className = 'activity-photo';
                    const src = '../' + String(p.file_path || '');
                    img.src = src;
                    img.alt = (p.original_name || ('Photo ' + (idx + 1)));
                    img.onclick = () => window.open(src, '_blank');
                    wrap.appendChild(img);
                    vPhotos.appendChild(wrap);
                });
            } else {
                vPhotos.innerHTML = '<div class="text-muted">No photos</div>';
            }

            // Show modal
            const vm = new bootstrap.Modal(document.getElementById('viewActivityModal'));
            vm.show();
        }
    </script>
    
</body>
</html>
