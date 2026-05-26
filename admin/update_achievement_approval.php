<?php
// admin/update_achievement_approval.php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/header.php'; // must start session/auth + $conn
if (ob_get_length() !== false) { ob_clean(); }
@ini_set('display_errors', '0');
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

$send = function (bool $success, string $message, int $status = 200, array $extra = []) {
    if (!headers_sent()) http_response_code($status);
    echo json_encode(array_merge(['success'=>$success,'message'=>$message], $extra));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $send(false, 'Method not allowed', 405);
}

$achievement_id  = isset($_POST['achievement_id']) ? (int)$_POST['achievement_id'] : 0;
$approval_status = isset($_POST['approval_status']) ? trim((string)$_POST['approval_status']) : '';
$reason          = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

if ($achievement_id <= 0 || $approval_status === '') {
    $send(false, 'Missing required fields', 400);
}
if (!in_array($approval_status, ['pending','approved','rejected'], true)) {
    $send(false, 'Invalid approval status', 400);
}
if (mb_strlen($reason) > 2000) {
    $send(false, 'Reason too long', 400);
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    $send(false, 'Database connection not found', 500);
}

// --- Get achievement details for email notification ---
$achievementSql = "SELECT a.title, a.description, a.club_id, c.group_name 
                   FROM achievements a 
                   JOIN clubs c ON c.id = a.club_id 
                   WHERE a.id = ?";
$achievementStmt = $conn->prepare($achievementSql);
if (!$achievementStmt) $send(false, 'Database error (prepare achievement): '.$conn->error, 500);

$achievementStmt->bind_param('i', $achievement_id);
if (!$achievementStmt->execute()) {
    $achievementStmt->close();
    $send(false, 'Database error (execute achievement): '.$conn->error, 500);
}

$achievementResult = $achievementStmt->get_result();
$achievementData = $achievementResult->fetch_assoc();
$achievementStmt->close();

if (!$achievementData) {
    $send(false, 'Achievement not found', 404);
}

// --- Update main record ---
$u = $conn->prepare("UPDATE achievements SET approval_status = ?, rejection_reason = ? WHERE id = ?");
if (!$u) $send(false, 'Database error (prepare): '.$conn->error, 500);
$u->bind_param('ssi', $approval_status, $reason, $achievement_id);
if (!$u->execute()) {
    $err = $u->error; $u->close();
    $send(false, 'Database error (execute): '.$err, 500);
}
$u->close();

// --- Email helper function for achievement notifications ---
function sendAchievementStatusEmail($achievementTitle, $clubName, $status, $recipientEmails, $reason = '') {
    if (empty($recipientEmails)) return false;
    
    $subject = "Achievement Status Update: " . $achievementTitle;
    
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
                .achievement-details { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Achievement Approved</h1>
            </div>
            <div class='content'>
                <p>Dear Club Members,</p>
                <p>We are pleased to inform you that your achievement <strong>{$achievementTitle}</strong> for club <strong>{$clubName}</strong> has been <span class='status-approved'>APPROVED</span>.</p>
                <div class='achievement-details'>
                    <p><strong>Achievement:</strong> {$achievementTitle}</p>
                    <p><strong>Club:</strong> {$clubName}</p>
                    <p><strong>Status:</strong> Approved</p>
                </div>
                <p>The achievement is now visible to all students and will be featured in your club's profile.</p>
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
                .achievement-details { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Achievement Status Update</h1>
            </div>
            <div class='content'>
                <p>Dear Club Members,</p>
                <p>We regret to inform you that your achievement <strong>{$achievementTitle}</strong> for club <strong>{$clubName}</strong> has been <span class='status-rejected'>REJECTED</span>.</p>
                <div class='achievement-details'>
                    <p><strong>Achievement:</strong> {$achievementTitle}</p>
                    <p><strong>Club:</strong> {$clubName}</p>
                    <p><strong>Status:</strong> Rejected</p>
                    {$reasonText}
                </div>
                <p>Please review the achievement details and contact ace-sedi department for next steps or clarification.</p>
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
        $emailStmt->bind_param('i', $achievementData['club_id']);
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
                $emailSent = sendAchievementStatusEmail(
                    $achievementData['title'],
                    $achievementData['group_name'],
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

/* Ensure log table exists (idempotent) */
$conn->query("CREATE TABLE IF NOT EXISTS achievement_approval_logs (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  achievement_id INT NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  action ENUM('approved','rejected') NOT NULL,
  reason TEXT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_aal_ach   FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
  CONSTRAINT fk_aal_admin FOREIGN KEY (admin_id)       REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Log approved/rejected */
if (in_array($approval_status, ['approved','rejected'], true)) {
    $admin_id = (int)($_SESSION['user_id'] ?? 0);
    if ($admin_id > 0) {
        $log = $conn->prepare("INSERT INTO achievement_approval_logs
            (achievement_id, admin_id, action, reason, created_at)
            VALUES (?, ?, ?, ?, NOW())");
        if ($log) {
            $log->bind_param('iiss', $achievement_id, $admin_id, $approval_status, $reason);
            $log->execute();
            $log->close();
        }
    }
}

$send(true, 'Achievement approval status updated', 200, [
    'email_status' => $emailStatus ?? 'not attempted'
]);
