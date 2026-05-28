<?php
// user/events.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../includes/db.php';

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

// Handle add event submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $club_id     = intval($_POST['club_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date  = $_POST['start_date'] ?? null;
    $start_time  = $_POST['start_time'] ?? '00:00';
    $end_date    = $_POST['end_date'] ?? null;
    $end_time    = $_POST['end_time'] ?? '23:59';
    $status      = $_POST['status'] ?? 'upcoming'; // upcoming | ongoing | completed
    $approval_status = 'pending'; // All new events require admin approval

    // Combine date and time
    $start_datetime = $start_date && $start_time ? $start_date . ' ' . $start_time : null;
    $end_datetime = $end_date && $end_time ? $end_date . ' ' . $end_time : null;

    // Verify access to club
    $verify_sql = "SELECT 1 FROM club_members WHERE club_id = ? AND email = ? LIMIT 1";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("is", $club_id, $user_email);
    $verify_stmt->execute();
    if ($verify_stmt->get_result()->num_rows < 1) {
        $_SESSION['error'] = "You don't have permission to add events for this club.";
        header('Location: events.php'); exit();
    }

    // Validate dates
    if (!$start_date || !$end_date) {
        $_SESSION['error'] = "Start and end date are required.";
        header('Location: events.php'); exit();
    }
    
    // Validate datetime
    $start_timestamp = strtotime($start_datetime);
    $end_timestamp = strtotime($end_datetime);
    
    if ($end_timestamp < $start_timestamp) {
        $_SESSION['error'] = "End date/time cannot be before start date/time.";
        header('Location: events.php'); exit();
    }

    // Images validation (1–3, total ≤ 10MB)
    $files = $_FILES['photos'] ?? null;
    $fileCount = 0;
    $totalBytes = 0;
    if ($files && isset($files['name']) && is_array($files['name'])) {
        for ($i=0; $i<count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK && $files['size'][$i] > 0 && $files['name'][$i] !== '') {
                $fileCount++;
                $totalBytes += (int)$files['size'][$i];
            }
        }
    }
    if ($fileCount < 1 || $fileCount > 3) {
        $_SESSION['error'] = "Please upload between 1 and 3 images or Your Image Size is Too Big. Please compress your image at this link (https://imagecompressor.com/) <- copy this link and paste it in your browser tab";
        header('Location: events.php'); exit();
    }
    if ($totalBytes > 10 * 1024 * 1024) {
        $_SESSION['error'] = "Each Image Size has to be 1.9 MB or less. Please compress your image if you get this error at (https://imagecompressor.com/)";
        header('Location: events.php'); exit();
    }

    // Insert event
    $insert_sql = "INSERT INTO events (club_id, title, description, start_date, end_date, start_time, end_time, status, created_by, approval_status) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("isssssssis", $club_id, $title, $description, $start_date, $end_date, $start_time, $end_time, $status, $user_id, $approval_status);
    if (!$insert_stmt->execute()) {
        $_SESSION['error'] = "Error saving event. Please try again.";
        header('Location: events.php'); exit();
    }
    $event_id = $insert_stmt->insert_id;

    // Uploads directory (absolute) and DB path (relative for <img>)
    $uploadDirAbs = __DIR__ . '/../uploads/events'; // absolute filesystem path
    if (!is_dir($uploadDirAbs)) { @mkdir($uploadDirAbs, 0775, true); }

    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = 0;

    for ($i=0; $i<count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK && $files['size'][$i] > 0 && $files['name'][$i] !== '') {
            $tmpPath = $files['tmp_name'][$i];
            $mime = $finfo->file($tmpPath);
            if (!in_array($mime, $allowedMime, true)) {
                $_SESSION['error'] = "Only JPG, PNG, or WEBP images are allowed.";
                // Rollback event
                $conn->query("DELETE FROM events WHERE id = ".intval($event_id));
                header('Location: events.php'); exit();
            }

            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default => 'bin'
            };

            $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($files['name'][$i], PATHINFO_FILENAME));
            $newName = uniqid('evt_', true) . '_' . $safeBase . '.' . $ext;

            $destAbs = $uploadDirAbs . '/' . $newName;
            $destRel = '../uploads/events/' . $newName; // <- REQUIRED per your path rule

            if (!move_uploaded_file($tmpPath, $destAbs)) {
                $_SESSION['error'] = "Failed to upload one of the images.";
                $conn->query("DELETE FROM events WHERE id = ".intval($event_id));
                header('Location: events.php'); exit();
            }

            $photo_sql = "INSERT INTO event_photos (event_id, file_path, original_name) VALUES (?, ?, ?)";
            $photo_stmt = $conn->prepare($photo_sql);
            $orig = $files['name'][$i];
            $photo_stmt->bind_param("iss", $event_id, $destRel, $orig);
            $photo_stmt->execute();
            $saved++;
        }
    }

    if ($saved < 1) {
        $_SESSION['error'] = "No valid images were uploaded.";
        $conn->query("DELETE FROM events WHERE id = ".intval($event_id));
    } else {
        $_SESSION['success'] = "Event added successfully! It is now pending admin approval.";
    }

    header('Location: events.php'); exit();
}

// Handle edit event submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_event'])) {
    $event_id    = intval($_POST['event_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date  = $_POST['start_date'] ?? null;
    $start_time  = $_POST['start_time'] ?? '00:00';
    $end_date    = $_POST['end_date'] ?? null;
    $end_time    = $_POST['end_time'] ?? '23:59';
    $status      = $_POST['status'] ?? 'upcoming';

    // Combine date and time
    $start_datetime = $start_date && $start_time ? $start_date . ' ' . $start_time : null;
    $end_datetime = $end_date && $end_time ? $end_date . ' ' . $end_time : null;

    // Verify user owns this event
    $verify_sql = "SELECT 1 FROM events WHERE id = ? AND created_by = ? LIMIT 1";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $event_id, $user_id);
    $verify_stmt->execute();
    if ($verify_stmt->get_result()->num_rows < 1) {
        $_SESSION['error'] = "You don't have permission to edit this event.";
        header('Location: events.php'); exit();
    }

    // Validate dates
    if (!$start_date || !$end_date) {
        $_SESSION['error'] = "Start and end date are required.";
        header('Location: events.php'); exit();
    }
    
    // Validate datetime
    $start_timestamp = strtotime($start_datetime);
    $end_timestamp = strtotime($end_datetime);
    
    if ($end_timestamp < $start_timestamp) {
        $_SESSION['error'] = "End date/time cannot be before start date/time.";
        header('Location: events.php'); exit();
    }

    // Update event - reset approval status to pending when edited
    $update_sql = "UPDATE events SET title = ?, description = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, status = ?, approval_status = 'pending' WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sssssssi", $title, $description, $start_date, $end_date, $start_time, $end_time, $status, $event_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "Event updated successfully! It is now pending admin approval again.";
    } else {
        $_SESSION['error'] = "Error updating event. Please try again.";
    }

    header('Location: events.php'); exit();
}

// Handle delete event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event'])) {
    $event_id = intval($_POST['event_id'] ?? 0);

    // Verify user owns this event
    $verify_sql = "SELECT 1 FROM events WHERE id = ? AND created_by = ? LIMIT 1";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $event_id, $user_id);
    $verify_stmt->execute();
    if ($verify_stmt->get_result()->num_rows < 1) {
        $_SESSION['error'] = "You don't have permission to delete this event.";
        header('Location: events.php'); exit();
    }

    // Get photos to delete from filesystem
    $photos_sql = "SELECT file_path FROM event_photos WHERE event_id = ?";
    $photos_stmt = $conn->prepare($photos_sql);
    $photos_stmt->bind_param("i", $event_id);
    $photos_stmt->execute();
    $photos_result = $photos_stmt->get_result();
    
    while ($photo = $photos_result->fetch_assoc()) {
        $file_path = str_replace('../', __DIR__ . '/../', $photo['file_path']);
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    // Delete event (photos will be deleted via CASCADE if foreign key is set, otherwise delete manually)
    $delete_sql = "DELETE FROM events WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $event_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Event deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting event. Please try again.";
    }

    header('Location: events.php'); exit();
}

// Handle delete photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    $photo_id = intval($_POST['photo_id'] ?? 0);

    // Verify user owns this photo's event
    $verify_sql = "SELECT 1 FROM event_photos ep 
                   JOIN events e ON ep.event_id = e.id 
                   WHERE ep.id = ? AND e.created_by = ? LIMIT 1";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $photo_id, $user_id);
    $verify_stmt->execute();
    if ($verify_stmt->get_result()->num_rows < 1) {
        $_SESSION['error'] = "You don't have permission to delete this photo.";
        header('Location: events.php'); exit();
    }

    // Get photo path and delete from filesystem
    $photo_sql = "SELECT file_path FROM event_photos WHERE id = ?";
    $photo_stmt = $conn->prepare($photo_sql);
    $photo_stmt->bind_param("i", $photo_id);
    $photo_stmt->execute();
    $photo_result = $photo_stmt->get_result();
    $photo = $photo_result->fetch_assoc();
    
    if ($photo) {
        $file_path = str_replace('../', __DIR__ . '/../', $photo['file_path']);
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    // Delete photo from database
    $delete_sql = "DELETE FROM event_photos WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $photo_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Photo deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting photo. Please try again.";
    }

    header('Location: events.php'); exit();
}

// Fetch events for user's clubs
$events = [];
$photosByEvent = [];

if (!empty($clubs)) {
    $club_ids = array_column($clubs, 'id');
    $placeholders = implode(',', array_fill(0, count($club_ids), '?'));

    $ev_sql = "SELECT e.*, c.group_name 
               FROM events e
               JOIN clubs c ON e.club_id = c.id
               WHERE e.club_id IN ($placeholders)
               ORDER BY e.start_date DESC, e.start_time DESC";
    $ev_stmt = $conn->prepare($ev_sql);
    $types = str_repeat('i', count($club_ids));
    $ev_stmt->bind_param($types, ...$club_ids);
    $ev_stmt->execute();
    $ev_res = $ev_stmt->get_result();
    while ($row = $ev_res->fetch_assoc()) { $events[] = $row; }

    if (!empty($events)) {
        $ev_ids = array_column($events, 'id');
        $ph_place = implode(',', array_fill(0, count($ev_ids), '?'));
        $ph_sql = "SELECT id, event_id, file_path, original_name
                   FROM event_photos
                   WHERE event_id IN ($ph_place)
                   ORDER BY id ASC";
        $ph_stmt = $conn->prepare($ph_sql);
        $ph_types = str_repeat('i', count($ev_ids));
        $ph_stmt->bind_param($ph_types, ...$ev_ids);
        $ph_stmt->execute();
        $ph_res = $ph_stmt->get_result();
        while ($p = $ph_res->fetch_assoc()) {
            $eid = $p['event_id'];
            if (!isset($photosByEvent[$eid])) $photosByEvent[$eid] = [];
            $photosByEvent[$eid][] = $p;
        }
    }
}

// Prepare events for FullCalendar (JSON)
$calendarEvents = [];
foreach ($events as $e) {
    $start_datetime = $e['start_date'] . ($e['start_time'] ? 'T' . $e['start_time'] : '');
    $end_datetime = $e['end_date'] . ($e['end_time'] ? 'T' . $e['end_time'] : '');
    
    // Set different colors based on status
    $backgroundColor = match($e['status']) {
        'completed' => '#6c757d', // gray
        'ongoing' => '#ffc107',   // yellow
        default => '#198754'      // green (upcoming)
    };
    
    $calendarEvents[] = [
        'id'    => $e['id'],
        'title' => $e['title'] . ' (' . $e['group_name'] . ')',
        'start' => $start_datetime,
        'end'   => $end_datetime,
        'display' => 'block',
        'backgroundColor' => $backgroundColor,
        'borderColor' => $backgroundColor,
        'allDay' => !$e['start_time'] && !$e['end_time'] // Treat as all-day if no times specified
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Events - 3ZERO Club</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <!-- FullCalendar -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
  <style>
    :root {
        --primary:#1a5276; --primary-dark:#154360;
        --gray:#6c757d; --gray-light:#e9ecef;
        --shadow:0 4px 6px rgba(0,0,0,.08);
        --radius:10px;
    }
    .card-ev { border:0; border-left:4px solid var(--primary); box-shadow:var(--shadow); border-radius:var(--radius); overflow:hidden; transition:transform 0.2s; }
    .card-ev:hover { transform:translateY(-2px); }
    .card-ev .head { background:linear-gradient(135deg,#f8f9fa,#eef2f5); padding:1rem 1.25rem; border-bottom:1px solid var(--gray-light); }
    .thumbs img { height:84px; width:100%; object-fit:cover; border-radius:6px; transition:transform 0.2s; }
    .thumbs img:hover { transform:scale(1.05); }
    .thumbs .col-4 { margin-bottom:.5rem; position:relative; }
    .photo-delete { position:absolute; top:5px; right:5px; background:rgba(0,0,0,0.7); color:white; border:none; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px; }
    .photo-delete:hover { background:rgba(220,53,69,0.9); }
    #calendar { background:#fff; border-radius:var(--radius); box-shadow:var(--shadow); padding:10px; }
    .event-actions { opacity:0; transition:opacity 0.2s; }
    .card-ev:hover .event-actions { opacity:1; }
    .stats-card { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; border-radius:var(--radius); }
    .time-input-group { display: flex; gap: 10px; }
    .time-input-group .form-control { flex: 1; }
    
    /* Approval status badges */
    .approval-pending { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
    .approval-approved { background: #d1edff; color: #0c5460; border: 1px solid #bee5eb; }
    .approval-rejected { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    
    /* Event status badges */
    .status-upcoming { background: #d1edff; color: #0c5460; border: 1px solid #bee5eb; }
    .status-ongoing { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
    .status-completed { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
    
    /* Mobile Optimizations */
    @media (max-width: 768px) {
        .container { padding-left: 15px; padding-right: 15px; }
        .d-flex.justify-content-between.align-items-center.mb-4 {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 1rem;
        }
        .d-flex.justify-content-between.align-items-center.mb-4 .btn {
            align-self: stretch;
            width: 100%;
        }
        .stats-card { margin-bottom: 1rem; }
        .stats-card h3 { font-size: 1.5rem; }
        .card-ev .head { padding: 0.75rem 1rem; }
        .card-ev .head h5 { font-size: 1.1rem; margin-bottom: 0.25rem; }
        .card-ev .head small { font-size: 0.8rem; line-height: 1.3; }
        .event-actions { opacity: 1; margin-top: 0.5rem; }
        .event-actions .btn { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
        .thumbs img { height: 70px; }
        .modal-dialog { margin: 1rem; }
        .modal-content { border-radius: 12px; }
        .modal-body { padding: 1rem; }
        .modal-footer { padding: 0.75rem 1rem; }
        .form-control, .form-select { font-size: 16px; } /* Prevent zoom on iOS */
        #calendar .fc-toolbar { flex-direction: column; gap: 0.5rem; }
        #calendar .fc-toolbar-title { font-size: 1.2rem; }
        #calendar .fc-button { padding: 0.4rem 0.6rem; font-size: 0.85rem; }
        .time-input-group { flex-direction: column; }
    }
    
    @media (max-width: 576px) {
        .row.g-3 { margin-left: -0.5rem; margin-right: -0.5rem; }
        .row.g-3 > [class*="col-"] { padding-left: 0.5rem; padding-right: 0.5rem; }
        .thumbs .col-4 { flex: 0 0 50%; max-width: 50%; }
        .card-ev .head { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
        .event-actions { align-self: flex-end; }
        .modal-dialog { margin: 0.5rem; }
        .modal-header h5 { font-size: 1.1rem; }
        .btn { font-size: 0.9rem; padding: 0.5rem 1rem; }
    }
    
    /* Improve touch targets */
    .btn, .form-control, .form-select { min-height: 44px; }
    .photo-delete { min-width: 32px; min-height: 32px; }
    
    /* Better spacing for mobile */
    @media (max-width: 768px) {
        .my-4 { margin-top: 1rem !important; margin-bottom: 1rem !important; }
        .mb-4 { margin-bottom: 1.5rem !important; }
        .py-5 { padding-top: 2rem !important; padding-bottom: 2rem !important; }
    }
  </style>
</head>
<body>
<?php include('header.php'); ?>

<div class="container my-4" id="mainContent">
      <!-- Note about converting photos -->
    <div class="alert alert-info mt-2 p-2 small">
        <strong><i class="bi bi-info-circle me-1"></i>Having trouble uploading?</strong><br>
        If your photos won't upload, try compressing your image<br>
        You may compress your image at this website (https://imagecompressor.com/)
    </div>
    
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h2 mb-1">My Events</h1>
      <p class="text-muted mb-0">Manage your events and see their approval status.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">
      <i class="bi bi-calendar-plus me-2"></i>Add Event
    </button>
  </div>

  <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
  <?php endif; ?>

  <!-- Stats Cards -->
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="stats-card p-3 text-center">
        <h3 class="mb-0"><?= count($events) ?></h3>
        <small>Total Events</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stats-card p-3 text-center">
        <h3 class="mb-0"><?= count(array_filter($events, fn($e) => $e['status'] === 'upcoming')) ?></h3>
        <small>Upcoming</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stats-card p-3 text-center">
        <h3 class="mb-0"><?= count(array_filter($events, fn($e) => $e['status'] === 'ongoing')) ?></h3>
        <small>Ongoing</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stats-card p-3 text-center">
        <h3 class="mb-0"><?= count(array_filter($events, fn($e) => $e['approval_status'] === 'approved')) ?></h3>
        <small>Approved</small>
      </div>
    </div>
  </div>

  <!-- Calendar -->
  <div class="mb-4" id="calendar"></div>

  <!-- Simple list view -->
  <?php if (empty($events)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-calendar2-week" style="font-size:3rem;"></i>
      <h4 class="mt-3">No events yet</h4>
      <p>Tap "Add Event" to create your first one.</p>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($events as $e): ?>
        <div class="col-lg-6">
          <div class="card-ev">
            <div class="head d-flex justify-content-between align-items-start">
              <div class="flex-grow-1">
                <h5 class="mb-1"><?= htmlspecialchars($e['title']) ?></h5>
                <small class="text-muted">
                  <?= htmlspecialchars($e['group_name']) ?> &middot;
                  <?= date('M j, Y', strtotime($e['start_date'])) ?>
                  <?php if ($e['start_time'] && $e['start_time'] != '00:00'): ?>
                    at <?= date('g:i A', strtotime($e['start_time'])) ?>
                  <?php endif; ?>
                  <?php if ($e['end_date'] != $e['start_date']): ?>
                    – <?= date('M j, Y', strtotime($e['end_date'])) ?>
                  <?php endif; ?>
                  <?php if ($e['end_time'] && $e['end_time'] != '23:59'): ?>
                    until <?= date('g:i A', strtotime($e['end_time'])) ?>
                  <?php endif; ?>
                  &middot; <span class="badge rounded-pill status-<?= $e['status'] ?>">
                    <?= ucfirst($e['status']) ?>
                  </span>
                </small>
                <!-- Approval Status -->
                <div class="mt-1">
                  <span class="badge rounded-pill approval-<?= $e['approval_status'] ?>">
                    <?= ucfirst($e['approval_status']) ?>
                  </span>
                  <?php if ($e['approval_status'] === 'rejected' && !empty($e['rejection_reason'])): ?>
                    <small class="text-muted d-block mt-1">Reason: <?= htmlspecialchars($e['rejection_reason']) ?></small>
                  <?php endif; ?>
                </div>
              </div>
              <div class="event-actions">
                <button class="btn btn-sm btn-outline-primary me-1" 
                        data-bs-toggle="modal" 
                        data-bs-target="#editEventModal" 
                        onclick="loadEditForm(<?= $e['id'] ?>, '<?= htmlspecialchars(addslashes($e['title'])) ?>', '<?= htmlspecialchars(addslashes($e['description'])) ?>', '<?= $e['start_date'] ?>', '<?= $e['start_time'] ?>', '<?= $e['end_date'] ?>', '<?= $e['end_time'] ?>', '<?= $e['status'] ?>')">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" 
                        data-bs-toggle="modal" 
                        data-bs-target="#deleteEventModal" 
                        onclick="setDeleteId(<?= $e['id'] ?>)">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
            <div class="p-3">
              <?php if (!empty($e['description'])): ?>
                <p class="mb-3"><?= nl2br(htmlspecialchars($e['description'])) ?></p>
              <?php endif; ?>

              <?php $phs = $photosByEvent[$e['id']] ?? []; ?>
              <?php if (!empty($phs)): ?>
                <div class="row thumbs">
                  <?php foreach (array_slice($phs, 0, 3) as $p): ?>
                    <div class="col-4">
                      <a href="<?= htmlspecialchars($p['file_path']) ?>" target="_blank" rel="noopener">
                        <img src="<?= htmlspecialchars($p['file_path']) ?>" alt="Event photo">
                      </a>
                      <?php if ($e['created_by'] == $user_id): ?>
                        <form method="POST" class="d-inline js-delete-confirm"
                              data-delete-title="Delete Photo"
                              data-delete-message="Are you sure you want to delete this photo?"
                              data-delete-confirm-label="<i class='bi bi-trash me-1'></i> Delete Photo">
                          <input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
                          <button type="submit" name="delete_photo" class="photo-delete" title="Delete photo">
                            <i class="bi bi-x"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <?php if (count($phs) > 3): ?>
                    <div class="col-12 text-center mt-2">
                      <small class="text-muted">+<?= count($phs) - 3 ?> more photos</small>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" action="events.php" enctype="multipart/form-data">
        <div class="modal-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;">
          <h5 class="modal-title" id="addEventLabel">Add Event</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Club</label>
              <select name="club_id" class="form-select" required>
                <option value="">Select Club</option>
                <?php foreach ($clubs as $club): ?>
                  <option value="<?= $club['id'] ?>"><?= htmlspecialchars($club['group_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="upcoming">Upcoming</option>
                <option value="ongoing">Ongoing</option>
                <option value="completed">Completed</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Title</label>
              <input type="text" name="title" class="form-control" placeholder="e.g., AI Workshop 2025" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description (optional)</label>
              <textarea name="description" class="form-control" rows="3" placeholder="Add a short description..."></textarea>
            </div>
            
            <!-- Start Date/Time -->
            <div class="col-md-6">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Start Time (Optional)</label>
              <input type="time" name="start_time" class="form-control" value="00:00">
              <small class="form-text text-muted">Leave as 00:00 for all-day events</small>
            </div>
            
            <!-- End Date/Time -->
            <div class="col-md-6">
              <label class="form-label">End Date</label>
              <input type="date" name="end_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Time (Optional)</label>
              <input type="time" name="end_time" class="form-control" value="23:59">
              <small class="form-text text-muted">Leave as 23:59 for all-day events</small>
            </div>
            
            <div class="col-12">
              <label class="form-label">Photos (1–3, JPG/PNG/WEBP, total ≤ 10 MB)</label>
              <input type="file" name="photos[]" id="photos" class="form-control" accept=".jpg,.jpeg,.png,.webp" multiple required>
              <div class="form-text">You can select up to 3 images.</div>
              <div id="preview" class="d-flex gap-2 mt-2 flex-wrap"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit" name="add_event">Save Event</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Event Modal -->
<div class="modal fade" id="editEventModal" tabindex="-1" aria-labelledby="editEventLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" action="events.php">
        <input type="hidden" name="event_id" id="edit_event_id">
        <div class="modal-header" style="background:linear-gradient(135deg,var(--primary),var(--primarydark));color:#fff;">
          <h5 class="modal-title" id="editEventLabel">Edit Event</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-select">
                <option value="upcoming">Upcoming</option>
                <option value="ongoing">Ongoing</option>
                <option value="completed">Completed</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Title</label>
              <input type="text" name="title" id="edit_title" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description (optional)</label>
              <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
            </div>
            
            <!-- Start Date/Time -->
            <div class="col-md-6">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Start Time (Optional)</label>
              <input type="time" name="start_time" id="edit_start_time" class="form-control">
              <small class="form-text text-muted">Leave empty for all-day events</small>
            </div>
            
            <!-- End Date/Time -->
            <div class="col-md-6">
              <label class="form-label">End Date</label>
              <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Time (Optional)</label>
              <input type="time" name="end_time" id="edit_end_time" class="form-control">
              <small class="form-text text-muted">Leave empty for all-day events</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit" name="edit_event">Update Event</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Event Modal -->
<div class="modal fade" id="deleteEventModal" tabindex="-1" aria-labelledby="deleteEventLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="events.php">
        <input type="hidden" name="event_id" id="delete_event_id">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="deleteEventLabel">Confirm Delete</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to delete this event? This action cannot be undone.</p>
          <p class="text-muted"><small>All photos associated with this event will also be deleted.</small></p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" type="submit" name="delete_event">Delete Event</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
// FullCalendar initialization
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            events: <?= json_encode($calendarEvents) ?>,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            eventClick: function(info) {
                // Scroll to the event in the list
                const eventElement = document.querySelector(`[data-event-id="${info.event.id}"]`);
                if (eventElement) {
                    eventElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Add highlight effect
                    eventElement.style.backgroundColor = '#fff3cd';
                    setTimeout(() => {
                        eventElement.style.backgroundColor = '';
                    }, 2000);
                }
            },
            eventDisplay: 'block',
            height: 'auto',
            dayMaxEvents: 2,
            views: {
                timeGridWeek: {
                    dayMaxEvents: 4
                },
                timeGridDay: {
                    dayMaxEvents: 6
                }
            },
            eventTimeFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            }
        });
        calendar.render();
    }
});

// File upload preview and validation
document.getElementById('photos')?.addEventListener('change', function() {
    const files = Array.from(this.files || []);
    const preview = document.getElementById('preview');
    preview.innerHTML = '';

    if (files.length < 1) {
        this.setCustomValidity('Please select at least 1 image.');
    } else if (files.length > 3) {
        this.setCustomValidity('You can upload a maximum of 3 images.');
    } else {
        this.setCustomValidity('');
    }

    const total = files.reduce((s, f) => s + f.size, 0);
    if (total > 10 * 1024 * 1024) {
        this.setCustomValidity('Total size exceeds 10 MB.');
    }

    files.slice(0, 3).forEach(f => {
        const url = URL.createObjectURL(f);
        const img = document.createElement('img');
        img.src = url;
        img.style.width = '90px';
        img.style.height = '90px';
        img.style.objectFit = 'cover';
        img.style.borderRadius = '6px';
        img.onload = () => URL.revokeObjectURL(url);
        preview.appendChild(img);
    });
});

// Edit form functions
function loadEditForm(id, title, description, startDate, startTime, endDate, endTime, status) {
    document.getElementById('edit_event_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_start_date').value = startDate;
    document.getElementById('edit_start_time').value = startTime;
    document.getElementById('edit_end_date').value = endDate;
    document.getElementById('edit_end_time').value = endTime;
    document.getElementById('edit_status').value = status;
}

function setDeleteId(id) {
    document.getElementById('delete_event_id').value = id;
}

// Date and time validation for forms
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const startDate = this.querySelector('input[name="start_date"]');
        const startTime = this.querySelector('input[name="start_time"]');
        const endDate = this.querySelector('input[name="end_date"]');
        const endTime = this.querySelector('input[name="end_time"]');
        
        if (startDate && endDate) {
            const startDateTime = new Date(startDate.value + ' ' + (startTime?.value || '00:00'));
            const endDateTime = new Date(endDate.value + ' ' + (endTime?.value || '23:59'));
            
            if (endDateTime < startDateTime) {
                e.preventDefault();
                alert('End date/time cannot be before start date/time.');
                endDate.focus();
            }
        }
    });
});

// Auto-close alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        try {
            if (a.classList.contains('alert-dismissible')) {
                const alert = new bootstrap.Alert(a);
                alert.close();
            }
        } catch(e) {}
    });
}, 5000);

// Mobile menu enhancements
document.addEventListener('DOMContentLoaded', function() {
    // Improve touch interactions
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(btn => {
        btn.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        });
        btn.addEventListener('touchend', function() {
            this.style.transform = '';
        });
    });

    // Prevent zoom on input focus (iOS)
    const inputs = document.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.style.fontSize = '16px';
        });
    });

    // Set default times for new events
    const startTimeInput = document.querySelector('input[name="start_time"]');
    const endTimeInput = document.querySelector('input[name="end_time"]');
    if (startTimeInput && !startTimeInput.value) {
        startTimeInput.value = '00:00';
    }
    if (endTimeInput && !endTimeInput.value) {
        endTimeInput.value = '23:59';
    }
});

// Image loading error handling
document.querySelectorAll('img').forEach(img => {
    img.addEventListener('error', function() {
        this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2Y4ZjlmYSIvPjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTgiIGZpbGw9IiM2Yzc1N2QiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGR5PSIuM2VtIj5JbWFnZSBub3QgZm91bmQ8L3RleHQ+PC9zdmc+';
        this.alt = 'Image not available';
    });
});

// Responsive calendar adjustments
function handleCalendarResize() {
    const calendarEl = document.getElementById('calendar');
    if (calendarEl && window.FullCalendar) {
        const calendar = FullCalendar.getApi(calendarEl);
        if (window.innerWidth < 768) {
            calendar.setOption('headerToolbar', {
                left: 'prev,next',
                center: 'title',
                right: 'today'
            });
        } else {
            calendar.setOption('headerToolbar', {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            });
        }
        calendar.render();
    }
}

window.addEventListener('resize', handleCalendarResize);
window.addEventListener('load', handleCalendarResize);

// Form validation enhancements
document.addEventListener('DOMContentLoaded', function() {
    // Real-time date validation
    const startDates = document.querySelectorAll('input[name="start_date"]');
    const startTimes = document.querySelectorAll('input[name="start_time"]');
    const endDates = document.querySelectorAll('input[name="end_date"]');
    const endTimes = document.querySelectorAll('input[name="end_time"]');
    
    function validateDateTime() {
        startDates.forEach((startDate, index) => {
            const startTime = startTimes[index] || { value: '00:00' };
            const endDate = endDates[index];
            const endTime = endTimes[index] || { value: '23:59' };
            
            if (startDate.value && endDate.value) {
                const startDateTime = new Date(startDate.value + ' ' + startTime.value);
                const endDateTime = new Date(endDate.value + ' ' + endTime.value);
                
                if (endDateTime < startDateTime) {
                    endDate.setCustomValidity('End date/time must be after start date/time');
                } else {
                    endDate.setCustomValidity('');
                }
            }
        });
    }

    startDates.forEach(startDate => startDate.addEventListener('change', validateDateTime));
    startTimes.forEach(startTime => startTime?.addEventListener('change', validateDateTime));
    endDates.forEach(endDate => endDate.addEventListener('change', validateDateTime));
    endTimes.forEach(endTime => endTime?.addEventListener('change', validateDateTime));

    // Auto-close modals on successful submission
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const modal = this.closest('.modal');
            if (modal) {
                setTimeout(() => {
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                }, 1000);
            }
        });
    });
});

// Keyboard navigation improvements
document.addEventListener('keydown', function(e) {
    // Close modals with Escape key
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            const modalInstance = bootstrap.Modal.getInstance(openModal);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
    }
});

// Performance optimization: Lazy load images
if ('IntersectionObserver' in window) {
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.remove('lazy');
                imageObserver.unobserve(img);
            }
        });
    });

    document.querySelectorAll('img[data-src]').forEach(img => {
        imageObserver.observe(img);
    });
}

// Add event IDs to event cards for calendar scrolling
document.querySelectorAll('.card-ev').forEach(card => {
    const eventId = card.querySelector('[onclick*="loadEditForm"]')?.getAttribute('onclick')?.match(/\d+/)?.[0];
    if (eventId) {
        card.setAttribute('data-event-id', eventId);
    }
});

// Time input formatting
document.querySelectorAll('input[type="time"]').forEach(input => {
    input.addEventListener('change', function() {
        if (this.value) {
            // Ensure time is in correct format
            const timeParts = this.value.split(':');
            if (timeParts.length === 2) {
                const hours = timeParts[0].padStart(2, '0');
                const minutes = timeParts[1].padStart(2, '0');
                this.value = `${hours}:${minutes}`;
            }
        }
    });
});
</script>
<script>
// ✅ Stand-alone notification dropdown toggle script
document.addEventListener('DOMContentLoaded', function () {
    // Ensure header doesn't clip the dropdown
    const headerEl = document.querySelector('.main-header');
    if (headerEl) headerEl.style.overflow = 'visible';

    // Initialize all Bootstrap dropdowns on the page
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
    });

    // Optional: direct manual toggle for the bell icon (if Bootstrap fails to auto-bind)
    const bellBtn = document.getElementById('notificationDropdown');
    if (bellBtn) {
        bellBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const dd = bootstrap.Dropdown.getOrCreateInstance(bellBtn, { autoClose: 'outside', display: 'static' });
            dd.toggle();
        });
    }
});
</script>
</body>
</html>
