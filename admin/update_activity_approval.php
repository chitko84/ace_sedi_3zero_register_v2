<?php
// admin/update_activity_approval.php
require_once __DIR__ . '/header.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['activity_id'], $_POST['approval_status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$activity_id = (int)$_POST['activity_id'];
$approval_status = $_POST['approval_status'];
$reason = $_POST['reason'] ?? '';

if (!in_array($approval_status, ['approved', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid approval status']);
    exit;
}

try {
    // Get activity details for email notification - FIXED: using project_name instead of title
    $activitySql = "SELECT p.project_name as title, p.description, p.club_id, c.group_name 
                    FROM projects p 
                    JOIN clubs c ON c.id = p.club_id 
                    WHERE p.id = ?";
    $activityStmt = $conn->prepare($activitySql);
    if (!$activityStmt) {
        throw new Exception('Failed to prepare activity query');
    }
    
    $activityStmt->bind_param('i', $activity_id);
    if (!$activityStmt->execute()) {
        $activityStmt->close();
        throw new Exception('Failed to execute activity query');
    }
    
    $activityResult = $activityStmt->get_result();
    $activityData = $activityResult->fetch_assoc();
    $activityStmt->close();
    
    if (!$activityData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Activity not found']);
        exit;
    }

    // Update the activity approval status
    if ($approval_status === 'approved') {
        $sql = "UPDATE projects SET approval_status = 'approved', rejection_reason = NULL, approved_by = ?, approved_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $_SESSION['user_id'], $activity_id);
    } else {
        $sql = "UPDATE projects SET approval_status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $reason, $_SESSION['user_id'], $activity_id);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update activity');
    }
    
    $stmt->close();

    // Email helper function for activity notifications
    function sendActivityStatusEmail($activityTitle, $clubName, $status, $recipientEmails, $reason = '') {
        if (empty($recipientEmails)) return false;
        
        $subject = "Activity Status Update: " . $activityTitle;
        
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
                    .activity-details { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
                </style>
            </head>
            <body>
                <div class='header'>
                    <h1>Activity Approved</h1>
                </div>
                <div class='content'>
                    <p>Dear Club Members,</p>
                    <p>We are pleased to inform you that your activity <strong>{$activityTitle}</strong> for club <strong>{$clubName}</strong> has been <span class='status-approved'>APPROVED</span>.</p>
                    <div class='activity-details'>
                        <p><strong>Activity:</strong> {$activityTitle}</p>
                        <p><strong>Club:</strong> {$clubName}</p>
                        <p><strong>Status:</strong> Approved</p>
                    </div>
                    <p>The activity is now visible to all students and can proceed as planned.</p>
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
                    .activity-details { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
                </style>
            </head>
            <body>
                <div class='header'>
                    <h1>Activity Status Update</h1>
                </div>
                <div class='content'>
                    <p>Dear Club Members,</p>
                    <p>We regret to inform you that your activity <strong>{$activityTitle}</strong> for club <strong>{$clubName}</strong> has been <span class='status-rejected'>REJECTED</span>.</p>
                    <div class='activity-details'>
                        <p><strong>Activity:</strong> {$activityTitle}</p>
                        <p><strong>Club:</strong> {$clubName}</p>
                        <p><strong>Status:</strong> Rejected</p>
                        {$reasonText}
                    </div>
                    <p>Please review the activity details and contact ace-sedi department for next steps or clarification.</p>
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

    // Send email notifications
    $emailStatus = 'not attempted';
    
    // Get all club member emails
    $emailSql = "SELECT DISTINCT cm.email 
                 FROM club_members cm 
                 WHERE cm.club_id = ? AND cm.email IS NOT NULL AND cm.email != ''";
    $emailStmt = $conn->prepare($emailSql);
    if ($emailStmt) {
        $emailStmt->bind_param('i', $activityData['club_id']);
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
                $emailSent = sendActivityStatusEmail(
                    $activityData['title'],
                    $activityData['group_name'],
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

    echo json_encode([
        'success' => true, 
        'message' => 'Activity updated successfully',
        'email_status' => $emailStatus
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
