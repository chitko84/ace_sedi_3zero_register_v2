<?php
// admin/edit_event.php
require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin authentication check
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Helper function
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($event_id <= 0) {
    $_SESSION['error'] = "Invalid event ID.";
    header('Location: manage_events.php');
    exit();
}

// Fetch event data
$sql = "SELECT e.*, c.group_name 
        FROM events e 
        JOIN clubs c ON e.club_id = c.id 
        WHERE e.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

if (!$event) {
    $_SESSION['error'] = "Event not found.";
    header('Location: manage_events.php');
    exit();
}

// Fetch clubs for dropdown
$clubs = [];
$clubs_sql = "SELECT id, group_name FROM clubs ORDER BY group_name ASC";
$clubs_result = $conn->query($clubs_sql);
while ($club = $clubs_result->fetch_assoc()) {
    $clubs[] = $club;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $club_id = (int)($_POST['club_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $status = $_POST['status'] ?? 'upcoming';

    // Validation
    $errors = [];
    if (empty($title)) $errors[] = "Event title is required.";
    if (empty($club_id)) $errors[] = "Club selection is required.";
    if (empty($start_date)) $errors[] = "Start date is required.";
    if (!empty($end_date) && $end_date < $start_date) $errors[] = "End date cannot be before start date.";

    if (empty($errors)) {
        $update_sql = "UPDATE events SET 
                      title = ?, description = ?, club_id = ?, 
                      start_date = ?, end_date = ?, start_time = ?, end_time = ?, status = ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssisssssi", 
            $title, $description, $club_id,
            $start_date, $end_date, $start_time, $end_time, $status,
            $event_id
        );

        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Event updated successfully!";
            header('Location: manage_events.php');
            exit();
        } else {
            $_SESSION['error'] = "Error updating event. Please try again.";
        }
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
}

require_once __DIR__ . '/header.php';
?>

<main class="main-content container-fluid">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
    </style>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-10">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-edit me-2"></i>Edit Event
                        </h4>
                        <a href="manage_events.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Events
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?= $_SESSION['error'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <form method="POST" class="form-container">
                        <div class="row g-3">
                            <!-- Event Title -->
                            <div class="col-12">
                                <label for="title" class="form-label">Event Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?= h($event['title']) ?>" required>
                            </div>

                            <!-- Club Selection -->
                            <div class="col-12 col-md-6">
                                <label for="club_id" class="form-label">Club <span class="text-danger">*</span></label>
                                <select class="form-select" id="club_id" name="club_id" required>
                                    <option value="">Select Club</option>
                                    <?php foreach ($clubs as $club): ?>
                                        <option value="<?= (int)$club['id'] ?>" 
                                            <?= $event['club_id'] == $club['id'] ? 'selected' : '' ?>>
                                            <?= h($club['group_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Status -->
                            <div class="col-12 col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="upcoming" <?= $event['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                    <option value="ongoing" <?= $event['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                    <option value="completed" <?= $event['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>

                            <!-- Start Date & Time -->
                            <div class="col-12 col-md-6">
                                <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="start_date" name="start_date" 
                                       value="<?= h($event['start_date']) ?>" required>
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="start_time" name="start_time" 
                                       value="<?= h($event['start_time']) ?>">
                            </div>

                            <!-- End Date & Time -->
                            <div class="col-12 col-md-6">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" 
                                       value="<?= h($event['end_date']) ?>">
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="end_time" name="end_time" 
                                       value="<?= h($event['end_time']) ?>">
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="5" placeholder="Enter event description..."><?= h($event['description']) ?></textarea>
                            </div>

                            <!-- Form Actions -->
                            <div class="col-12">
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="manage_events.php" class="btn btn-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Update Event
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Event Info -->
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Current Event Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <strong>Current Club:</strong> <?= h($event['group_name']) ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <strong>Current Status:</strong> 
                            <span class="badge bg-<?= 
                                $event['status'] === 'completed' ? 'success' : 
                                ($event['status'] === 'ongoing' ? 'warning' : 'info')
                            ?>">
                                <?= ucfirst($event['status']) ?>
                            </span>
                        </div>
                        <div class="col-12 col-md-6">
                            <strong>Approval Status:</strong> 
                            <span class="badge bg-<?= 
                                $event['approval_status'] === 'approved' ? 'success' : 
                                ($event['approval_status'] === 'rejected' ? 'danger' : 'warning')
                            ?>">
                                <?= ucfirst($event['approval_status']) ?>
                            </span>
                        </div>
                        <div class="col-12 col-md-6">
                            <strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($event['created_at'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set min date for end date based on start date
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    startDate.addEventListener('change', function() {
        endDate.min = this.value;
    });
    
    // Initialize min date if start date is already set
    if (startDate.value) {
        endDate.min = startDate.value;
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>