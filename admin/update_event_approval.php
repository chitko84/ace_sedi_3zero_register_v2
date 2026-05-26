<?php
// admin/update_event_approval.php
declare(strict_types=1);

// --- make sure any accidental output doesn't corrupt JSON ---
ob_start();
require_once __DIR__ . '/header.php'; // starts session/auth + opens $conn
// Discard anything header.php might have printed
if (ob_get_length() !== false) { ob_clean(); }

// Never leak notices/warnings into the response
@ini_set('display_errors', '0');

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$send = function (bool $success, string $message, int $status = 200, array $extra = []) {
    if (!headers_sent()) http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $send(false, 'Method not allowed', 405);
}

if (!isset($_POST['event_id'], $_POST['approval_status'])) {
    $send(false, 'Missing required fields', 400);
}

$event_id        = (int)$_POST['event_id'];
$approval_status = trim((string)$_POST['approval_status']);
$reason          = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

if (!in_array($approval_status, ['pending','approved','rejected'], true)) {
    $send(false, 'Invalid approval status', 400);
}
if (mb_strlen($reason) > 2000) {
    $send(false, 'Reason is too long', 400);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $send(false, 'Database connection not found', 500);
}

// --- Get event details for email notification ---
$eventSql = "SELECT e.title, e.club_id, c.group_name 
             FROM events e 
             JOIN clubs c ON c.id = e.club_id 
             WHERE e.id = ?";
$eventStmt = $conn->prepare($eventSql);
if (!$eventStmt) $send(false, 'Database error (prepare event): '.$conn->error, 500);

$eventStmt->bind_param('i', $event_id);
if (!$eventStmt->execute()) {
    $eventStmt->close();
    $send(false, 'Database error (execute event): '.$conn->error, 500);
}

$eventResult = $eventStmt->get_result();
$eventData = $eventResult->fetch_assoc();
$eventStmt->close();

if (!$eventData) {
    $send(false, 'Event not found', 404);
}

// --- update main record ---
$sql  = "UPDATE events SET approval_status = ?, rejection_reason = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) $send(false, 'Database error (prepare): '.$conn->error, 500);

$stmt->bind_param('ssi', $approval_status, $reason, $event_id);
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    $send(false, 'Database error (execute): '.$err, 500);
}
$stmt->close();

// --- Email helper function for event notifications ---
function sendEventStatusEmail($eventTitle, $clubName, $status, $recipientEmails, $reason = '') {
    if (empty($recipientEmails)) return false;
    
    $subject = "Event Status Update: " . $eventTitle;
    $statusDisplay = ucfirst($status);
    
    if ($status === 'approved') {
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .status-approved { color: #4CAF50; font-weight: bold; }
                .footer { background: #f4f4f4; padding: 15px; text-align: center; font-size: 14px; }
                .event-details { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Event Approved</h1>
            </div>
            <div class='content'>
                <p>Dear Club Members,</p>
                <p>We are pleased to inform you that your event <strong>{$eventTitle}</strong> for club <strong>{$clubName}</strong> has been <span class='status-approved'>APPROVED</span>.</p>
                <div class='event-details'>
                    <p><strong>Event:</strong> {$eventTitle}</p>
                    <p><strong>Club:</strong> {$clubName}</p>
                    <p><strong>Status:</strong> Approved</p>
                </div>
                <p>The event is now visible to all students and can proceed as planned.</p>
                <p>If you have any questions, please contact the ace-sedi office.</p>
                <p>Best regards,<br>AIU Centre of Excellence in Socio-Economic Development and Innovation (ACE-SEDI)</p>
            </div>
            <div class='footer'>
                <p>This is an automated notification. Please do not reply to this email.</p>
            </div>
        </body>
        </html>
        ";
    } else {
        $reasonText = $reason ? "<p><strong>Reason:</strong> {$reason}</p>" : "";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #f44336; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .status-rejected { color: #f44336; font-weight: bold; }
                .footer { background: #f4f4f4; padding: 15px; text-align: center; font-size: 14px; }
                .event-details { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Event Status Update</h1>
            </div>
            <div class='content'>
                <p>Dear Club Members,</p>
                <p>We regret to inform you that your event <strong>{$eventTitle}</strong> for club <strong>{$clubName}</strong> has been <span class='status-rejected'>REJECTED</span>.</p>
                <div class='event-details'>
                    <p><strong>Event:</strong> {$eventTitle}</p>
                    <p><strong>Club:</strong> {$clubName}</p>
                    <p><strong>Status:</strong> Rejected</p>
                    {$reasonText}
                </div>
                <p>Please review the event details and contact ace-sedi department for next steps or clarification.</p>
                <p>Best regards,<br>AIU Centre of Excellence in Socio-Economic Development and Innovation (ACE-SEDI)</p>
            </div>
            <div class='footer'>
                <p>This is an automated notification. Please do not reply to this email.</p>
            </div>
        </body>
        </html>
        ";
    }
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: ace-sedi@office" . "\r\n";
    
    $successCount = 0;
    foreach ($recipientEmails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Use @ to suppress warnings for mail function
            if (@mail($email, $subject, $message, $headers)) {
                $successCount++;
            }
        }
    }
    
    return $successCount > 0;
}

// --- Send email notifications for approval/rejection ---
if (in_array($approval_status, ['approved','rejected'], true)) {
    // Get all club member emails
    $emailSql = "SELECT DISTINCT cm.email 
                 FROM club_members cm 
                 WHERE cm.club_id = ? AND cm.email IS NOT NULL AND cm.email != ''";
    $emailStmt = $conn->prepare($emailSql);
    if ($emailStmt) {
        $emailStmt->bind_param('i', $eventData['club_id']);
        if ($emailStmt->execute()) {
            $emailResult = $emailStmt->get_result();
            $memberEmails = [];
            while ($row = $emailResult->fetch_assoc()) {
                if (!empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $memberEmails[] = $row['email'];
                }
            }
            
            // Send emails if we have recipients
            if (!empty($memberEmails)) {
                $emailSent = sendEventStatusEmail(
                    $eventData['title'],
                    $eventData['group_name'],
                    $approval_status,
                    $memberEmails,
                    $reason
                );
                
                $emailStatus = $emailSent ? 'emails sent' : 'email sending failed';
            } else {
                $emailStatus = 'no valid member emails found';
            }
        } else {
            $emailStatus = 'failed to fetch member emails';
        }
        $emailStmt->close();
    } else {
        $emailStatus = 'failed to prepare email query';
    }
} else {
    $emailStatus = 'no email needed for pending status';
}

// --- ensure logs table (match your schema types) ---
$createTableSql = "CREATE TABLE IF NOT EXISTS event_approval_logs (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    action ENUM('approved','rejected') NOT NULL,
    reason TEXT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_eal_event  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eal_admin  FOREIGN KEY (admin_id) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($createTableSql)) {
    // Non-fatal: return success with warning, but keep JSON clean.
    $send(true, 'Event approval status updated (log table warning)', 200, [
        'warning' => 'Log table not ensured: '.$conn->error,
        'email_status' => $emailStatus ?? 'not attempted'
    ]);
}

// --- insert log only for approved/rejected ---
if (in_array($approval_status, ['approved','rejected'], true)) {
    $admin_id = (int)($_SESSION['user_id'] ?? 0); // will FK to users.id (UNSIGNED)
    // If for some reason no admin id, still avoid failing the whole request:
    if ($admin_id > 0) {
        $logSql  = "INSERT INTO event_approval_logs (event_id, admin_id, action, reason, created_at)
                    VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        if ($logStmt) {
            $logStmt->bind_param('iiss', $event_id, $admin_id, $approval_status, $reason);
            $logStmt->execute(); // ignore failures to keep endpoint success
            $logStmt->close();
        }
    }
}

$send(true, 'Event approval status updated', 200, [
    'email_status' => $emailStatus ?? 'not attempted'
]);
