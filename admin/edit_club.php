<?php
// admin/edit_club.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin gate (redirect before any output)
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// -------- Helpers --------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function valOrNull($v){ $v = trim((string)$v); return ($v === '') ? null : $v; }
function is_valid_date($v){ return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v); }

// Email helper function
function sendClubStatusEmail($clubName, $status, $recipientEmails, $conn, $clubId) {
    if (empty($recipientEmails)) return false;
    
    $subject = "Club Registration Status Update";
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
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Club Registration Approved</h1>
            </div>
            <div class='content'>
                <p>Dear Club Members,</p>
                <p>We are pleased to inform you that your club <strong>{$clubName}</strong> has been <span class='status-approved'>APPROVED</span>.</p>
                <p>Your club is now officially recognized and can begin its activities.</p>
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
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #f44336; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .status-rejected { color: #f44336; font-weight: bold; }
                .footer { background: #f4f4f4; padding: 15px; text-align: center; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Club Registration Status Update</h1>
            </div>
            <div class='content'>
                <p>Dear Club Members,</p>
                <p>We regret to inform you that your club <strong>{$clubName}</strong> has been <span class='status-rejected'>REJECTED</span>.</p>
                <p>Please contact ace-sedi department for next steps.</p>
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

// Enums and options from your schema
$CLUSTERS = [
    'Zero Poverty',
    'Zero Unemployment',
    'Zero Net Carbon Emissions',
];
$FOCUS_AREAS = [
    'Food & Nutrition','Environment & Climate Change','Microcredit','Education','Entrepreneurship',
    'Agriculture','Journalism','Mother & Child','Social Friction','Health & Well-Being',
    'Circular Economy','Waste','Energy','Economics','Literature','Transportation','Forestry',
    'History','Social Business','Sports','Employment','Technology & Innovation','WASH',
    'People with Disabilities','Tourism','Art, Culture & Music','Future Skills','Microinsurance'
];
$STATUSES = ['pending','approved','rejected'];
$MEMBER_TYPES = ['key_person', 'deputy', 'regular'];

// Additional options for dropdowns
$programmeOptions = [
    "Bachelor of Business Administration (Honours)",
    "Bachelor of Business Administration with Computer Science (Honours)",
    "Bachelor of Business Administration (Honours) (Marketing)",
    "Bachelor of Business Administration (Honours) (Human Resource Management)",
    "Bachelor of Economics (Honours)",
    "Bachelor of Social Development (Honours)",
    "Bachelor of Finance (Islamic Finance) (Honours)",
    "Bachelor of Politics and International Relations (Honours)",
    "Master of Business Management",
    "Master in Social Business",
    "Doctor of Philosophy (Business Management)",
    "Bachelor of Elementary Education (Honours)",
    "Bachelor in Early Childhood Education (Honours)",
    "Bachelor of Media and Communication (Honours)",
    "Master of Education",
    "Doctor of Philosophy (Education)",
    "Bachelor in Computer Science (Honours)",
    "Bachelor in Data Science (Honours)",
    "Foundation in Computing",
    "Foundation in Arts",
];

$countries = [
    "Afghanistan","Albania","Algeria","Andorra","Angola","Antigua and Barbuda","Argentina","Armenia","Australia","Austria","Azerbaijan","Bahamas","Bahrain","Bangladesh","Barbados","Belarus","Belgium","Belize","Benin","Bhutan","Bolivia","Bosnia and Herzegovina","Botswana","Brazil","Brunei","Bulgaria","Burkina Faso","Burundi","Cabo Verde","Cambodia","Cameroon","Canada","Central African Republic","Chad","Chile","China","Colombia","Comoros","Congo (Congo-Brazzaville)","Costa Rica","Croatia","Cuba","Cyprus","Czechia (Czech Republic)","Democratic Republic of the Congo","Denmark","Djibouti","Dominica","Dominican Republic","Ecuador","Egypt","El Salvador","Equatorial Guinea","Eritrea","Estonia","Eswatini","Ethiopia","Fiji","Finland","France","Gabon","Gambia","Georgia","Germany","Ghana","Greece","Grenada","Guatemala","Guinea","Guinea-Bissau","Guyana","Haiti","Honduras","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland","Israel","Italy","Jamaica","Japan","Jordan","Kazakhstan","Kenya","Kiribati","Kuwait","Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya","Liechtenstein","Lithuania","Luxembourg","Madagascar","Malawi","Malaysia","Maldives","Mali","Malta","Marshall Islands","Mauritania","Mauritius","Mexico","Micronesia","Moldova","Monaco","Mongolia","Montenegro","Morocco","Mozambique","Myanmar","Namibia","Nauru","Nepal","Netherlands","New Zealand","Nicaragua","Niger","Nigeria","North Korea","North Macedonia","Norway","Oman","Pakistan","Palau","Palestine","Panama","Papua New Guinea","Paraguay","Peru","Philippines","Poland","Portugal","Qatar","Romania","Russia","Rwanda","Saint Kitts and Nevis","Saint Lucia","Saint Vincent and the Grenadines","Samoa","San Marino","Sao Tome and Principe","Saudi Arabia","Senegal","Serbia","Seychelles","Sierra Leone","Singapore","Slovakia","Slovenia","Solomon Islands","Somalia","South Africa","South Korea","South Sudan","Spain","Sri Lanka","Sudan","Suriname","Sweden","Switzerland","Syria","Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tonga","Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Tuvalu","Uganda","Ukraine","United Arab Emirates","United Kingdom","United States of America","Uruguay","Uzbekistan","Vanuatu","Venezuela","Vietnam","Yemen","Zambia","Zimbabwe"
];

$currentSemesterOptions = [
    "CFGS Sem 1","CFGS Sem 2","CFGS Sem 3",
    "Year 1 Sem 1","Year 1 Sem 2","Year 1 Sem 3",
    "Year 2 Sem 1","Year 2 Sem 2","Year 2 Sem 3",
    "Year 3 Sem 1","Year 3 Sem 2","Year 3 Sem 3"
];

$graduationOptions = [
    "March 2025","December 2025",
    "March 2026","December 2026",
    "March 2027","December 2027",
    "March 2028","December 2028",
    "March 2029","December 2029",
    "March 2030","December 2030"
];

$schools = [
    "School Of Business & Social Sciences" => "School of Business & Social Sciences",
    "School Of Education & Human Sciences" => "School of Education & Human Sciences",
    "School Of Computing and Informatics"   => "School of Computing and Informatics",
    "Centre for Foundation and General Studies" => "Centre for Foundation and General Studies"
];

// ---------- CSRF ----------
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Load target club (use $editClub to avoid header.php collisions) ----------
$clubId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($clubId <= 0) {
    $_SESSION['error'] = 'Invalid club ID.';
    header('Location: manage_clubs.php');
    exit();
}

$sql = "SELECT id, club_identifier, group_name, cluster, focus_area, cluster_advisor,
               key_person_name, key_person_student_id,
               deputy_key_person_name, deputy_key_person_student_id,
               date_of_registration, status, created_at, updated_at
        FROM clubs
        WHERE id = ?
        LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('i', $clubId);
$st->execute();
$editClub = $st->get_result()->fetch_assoc();

if (!$editClub) {
    $_SESSION['error'] = 'Club not found.';
    header('Location: manage_clubs.php');
    exit();
}

// ---------- Load current members ----------
$members = [];
$memberSql = "SELECT id, full_name, student_id, programme, nationality, phone, email, 
                     school_centre, intake_month_year, expected_graduation_year, 
                     current_semester, member_type
              FROM club_members 
              WHERE club_id = ? 
              ORDER BY 
                CASE member_type 
                    WHEN 'key_person' THEN 1 
                    WHEN 'deputy' THEN 2 
                    ELSE 3 
                END, full_name";
$memberStmt = $conn->prepare($memberSql);
$memberStmt->bind_param('i', $clubId);
$memberStmt->execute();
$memberResult = $memberStmt->get_result();
while ($row = $memberResult->fetch_assoc()) {
    $members[] = $row;
}

// ---------- Handle POST ----------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        // Handle club info update
        if (isset($_POST['update_club'])) {
            // Gather + sanitize
            $club_identifier             = valOrNull($_POST['club_identifier'] ?? '');
            $group_name                  = trim($_POST['group_name'] ?? '');
            $cluster                     = trim($_POST['cluster'] ?? '');
            $focus_area                  = valOrNull($_POST['focus_area'] ?? '');
            $cluster_advisor             = trim($_POST['cluster_advisor'] ?? '');
            $key_person_name             = trim($_POST['key_person_name'] ?? '');
            $key_person_student_id       = trim($_POST['key_person_student_id'] ?? '');
            $deputy_key_person_name      = trim($_POST['deputy_key_person_name'] ?? '');
            $deputy_key_person_student_id= trim($_POST['deputy_key_person_student_id'] ?? '');
            $date_of_registration        = trim($_POST['date_of_registration'] ?? '');
            $status                      = trim($_POST['status'] ?? '');
            $old_status = $editClub['status']; // Store old status for comparison

            // Validations
            if ($group_name === '') $errors[] = "Group/Club name is required.";
            if (!in_array($cluster, $CLUSTERS, true)) $errors[] = "Invalid cluster selected.";
            if ($focus_area !== null && !in_array($focus_area, $FOCUS_AREAS, true)) $errors[] = "Invalid focus area selected.";
            if ($cluster_advisor === '') $errors[] = "Cluster advisor is required.";
            if ($key_person_name === '') $errors[] = "Key person name is required.";
            if ($key_person_student_id === '') $errors[] = "Key person student ID is required.";
            if ($deputy_key_person_name === '') $errors[] = "Deputy key person name is required.";
            if ($deputy_key_person_student_id === '') $errors[] = "Deputy key person student ID is required.";
            if ($date_of_registration === '' || !is_valid_date($date_of_registration)) $errors[] = "Valid registration date (YYYY-MM-DD) is required.";
            if (!in_array($status, $STATUSES, true)) $errors[] = "Invalid status.";

            if (!$errors) {
                // UPDATE club info
                $sqlU = "UPDATE clubs SET
                            club_identifier = ?,
                            group_name = ?,
                            cluster = ?,
                            focus_area = ?,
                            cluster_advisor = ?,
                            key_person_name = ?,
                            key_person_student_id = ?,
                            deputy_key_person_name = ?,
                            deputy_key_person_student_id = ?,
                            date_of_registration = ?,
                            status = ?
                         WHERE id = ?
                         LIMIT 1";

                $stU = $conn->prepare($sqlU);
                $stU->bind_param(
                    'sssssssssssi',
                    $club_identifier,
                    $group_name,
                    $cluster,
                    $focus_area,
                    $cluster_advisor,
                    $key_person_name,
                    $key_person_student_id,
                    $deputy_key_person_name,
                    $deputy_key_person_student_id,
                    $date_of_registration,
                    $status,
                    $clubId
                );

                if ($stU->execute()) {
                    // Check if status changed to approved or rejected
                    if (($status === 'approved' || $status === 'rejected') && $old_status !== $status) {
                        // Get all member emails
                        $memberEmails = [];
                        foreach ($members as $member) {
                            if (!empty($member['email']) && filter_var($member['email'], FILTER_VALIDATE_EMAIL)) {
                                $memberEmails[] = $member['email'];
                            }
                        }
                        
                        // Send notification emails
                        if (!empty($memberEmails)) {
                            $emailSent = sendClubStatusEmail($group_name, $status, $memberEmails, $conn, $clubId);
                            if ($emailSent) {
                                $_SESSION['success'] = "Club updated successfully and notification emails sent to members.";
                            } else {
                                $_SESSION['success'] = "Club updated successfully but failed to send notification emails.";
                            }
                        } else {
                            $_SESSION['success'] = "Club updated successfully. No valid member emails found for notification.";
                        }
                    } else {
                        $_SESSION['success'] = "Club updated successfully.";
                    }
                    
                    header('Location: edit_club.php?id='.$clubId);
                    exit();
                } else {
                    $errors[] = "Update failed. Please try again.";
                }
            }
        }

        // Handle member removal
        if (isset($_POST['remove_member'])) {
            $member_id = (int)($_POST['member_id'] ?? 0);
            if ($member_id > 0) {
                // Verify member belongs to this club
                $verifySql = "SELECT id FROM club_members WHERE id = ? AND club_id = ?";
                $verifyStmt = $conn->prepare($verifySql);
                $verifyStmt->bind_param('ii', $member_id, $clubId);
                $verifyStmt->execute();
                
                if ($verifyStmt->get_result()->fetch_assoc()) {
                    $deleteSql = "DELETE FROM club_members WHERE id = ?";
                    $deleteStmt = $conn->prepare($deleteSql);
                    $deleteStmt->bind_param('i', $member_id);
                    
                    if ($deleteStmt->execute()) {
                        $_SESSION['success'] = "Member removed successfully.";
                        header('Location: edit_club.php?id='.$clubId);
                        exit();
                    } else {
                        $errors[] = "Failed to remove member. Please try again.";
                    }
                } else {
                    $errors[] = "Invalid member ID.";
                }
            }
        }

        // Handle add member with enhanced validation
        if (isset($_POST['add_member'])) {
            // Admin rule: less than 5 is allowed, exactly 5 is allowed, more than 5 is NOT allowed.
            if (count($members) >= 5) {
                $errors[] = "Maximum member limit (5) reached. Cannot add more members.";
            } else {
                $full_name = trim($_POST['full_name'] ?? '');
                $student_id = trim($_POST['student_id'] ?? '');
                $programme = trim($_POST['programme'] ?? '');
                $nationality = trim($_POST['nationality'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $school_centre = trim($_POST['school_centre'] ?? '');
                $intake_month_year = trim($_POST['intake_month_year'] ?? '');
                $expected_graduation_year = trim($_POST['expected_graduation_year'] ?? '');
                $current_semester = trim($_POST['current_semester'] ?? '');
                $member_type = trim($_POST['member_type'] ?? 'regular');

                // All fields are compulsory for admins when adding a member.
                if ($full_name === '') $errors[] = "Full name is required.";
                if ($student_id === '') $errors[] = "Student ID is required.";
                if ($programme === '') $errors[] = "Programme is required.";
                if ($nationality === '') $errors[] = "Nationality is required.";
                if ($phone === '') $errors[] = "Phone is required.";
                if ($email === '') $errors[] = "Email is required.";
                if ($school_centre === '') $errors[] = "School/Centre is required.";
                if ($intake_month_year === '') $errors[] = "Intake Month/Year is required.";
                if ($expected_graduation_year === '') $errors[] = "Expected Graduation Year is required.";
                if ($current_semester === '') $errors[] = "Current Semester is required.";

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
                if (!in_array($member_type, $MEMBER_TYPES, true)) $errors[] = "Invalid member type.";
                if ($programme !== '' && !in_array($programme, $programmeOptions, true)) $errors[] = "Invalid programme selected.";
                if ($nationality !== '' && !in_array($nationality, $countries, true)) $errors[] = "Invalid nationality selected.";
                if ($school_centre !== '' && !array_key_exists($school_centre, $schools)) $errors[] = "Invalid school/centre selected.";
                if ($expected_graduation_year !== '' && !in_array($expected_graduation_year, $graduationOptions, true)) $errors[] = "Invalid expected graduation year selected.";
                if ($current_semester !== '' && !in_array($current_semester, $currentSemesterOptions, true)) $errors[] = "Invalid current semester selected.";

                // Check if student ID already exists in this club
                if ($student_id !== '') {
                    $checkSql = "SELECT id FROM club_members WHERE student_id = ? AND club_id = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->bind_param('si', $student_id, $clubId);
                    $checkStmt->execute();
                    if ($checkStmt->get_result()->fetch_assoc()) {
                        $errors[] = "A member with this student ID already exists in this club.";
                    }
                }

                if (!$errors) {
                    $insertSql = "INSERT INTO club_members 
                                 (club_id, full_name, student_id, programme, nationality, phone, email, 
                                  school_centre, intake_month_year, expected_graduation_year, current_semester, member_type)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param(
                        'isssssssssss',
                        $clubId,
                        $full_name,
                        $student_id,
                        $programme,
                        $nationality,
                        $phone,
                        $email,
                        $school_centre,
                        $intake_month_year,
                        $expected_graduation_year,
                        $current_semester,
                        $member_type
                    );

                    if ($insertStmt->execute()) {
                        $_SESSION['success'] = "Member added successfully.";
                        header('Location: edit_club.php?id='.$clubId);
                        exit();
                    } else {
                        $errors[] = "Failed to add member. Please try again.";
                    }
                }
            }
        }

        // Handle bulk member updates (like user-side)
        if (isset($_POST['update_members'])) {
            $members_post = $_POST['members'] ?? [];
            
            // Validate member count
            $total_members_after_operation = 0;
            
            // Count existing members that are NOT being deleted
            foreach ($members as $existing_member) {
                $is_being_deleted = false;
                foreach ($members_post as $row) {
                    $mid = isset($row['id']) ? (int)$row['id'] : 0;
                    $is_delete = isset($row['delete']) && $row['delete'] === '1';
                    if ($mid === (int)$existing_member['id'] && $is_delete) {
                        $is_being_deleted = true;
                        break;
                    }
                }
                if (!$is_being_deleted) $total_members_after_operation++;
            }

            // Count new members being added
            foreach ($members_post as $row) {
                $mid = isset($row['id']) ? (int)$row['id'] : 0;
                $is_delete = isset($row['delete']) && $row['delete'] === '1';
                if ($mid === 0 && !$is_delete) $total_members_after_operation++;
            }

            // Admin rule: no minimum member validation. 1-5 members are allowed, but more than 5 is blocked.
            if ($total_members_after_operation > 5) {
                $errors[] = "Total members cannot exceed 5. You currently have {$total_members_after_operation} members.";
            }

            // Validate every non-deleted row. Empty NEW rows are ignored.
            $seenStudentIds = [];
            foreach ($members_post as $rowIndex => $row) {
                $mid = isset($row['id']) ? (int)$row['id'] : 0;
                $is_delete = isset($row['delete']) && $row['delete'] === '1';
                if ($is_delete) continue;

                $f_full_name = trim($row['full_name'] ?? '');
                $f_student_id = trim($row['student_id'] ?? '');
                $f_programme = trim($row['programme'] ?? '');
                $f_nationality = trim($row['nationality'] ?? '');
                $f_phone = trim($row['phone'] ?? '');
                $f_email = trim($row['email'] ?? '');
                $f_school = trim($row['school_centre'] ?? '');
                $f_intake = trim($row['intake_month_year'] ?? '');
                $f_grad = trim($row['expected_graduation_year'] ?? '');
                $f_sem = trim($row['current_semester'] ?? '');
                $f_role = trim($row['member_type'] ?? 'regular');

                $has_any_content = ($f_full_name !== '' || $f_student_id !== '' || $f_programme !== '' || $f_nationality !== '' ||
                                    $f_phone !== '' || $f_email !== '' || $f_school !== '' || $f_intake !== '' ||
                                    $f_grad !== '' || $f_sem !== '');

                // Allow completely blank new rows to be ignored.
                if ($mid === 0 && !$has_any_content) continue;

                $rowNo = $rowIndex + 1;
                if ($f_full_name === '') $errors[] = "Row {$rowNo}: Full name is required.";
                if ($f_student_id === '') $errors[] = "Row {$rowNo}: Student ID is required.";
                if ($f_programme === '') $errors[] = "Row {$rowNo}: Programme is required.";
                if ($f_nationality === '') $errors[] = "Row {$rowNo}: Nationality is required.";
                if ($f_phone === '') $errors[] = "Row {$rowNo}: Phone is required.";
                if ($f_email === '') $errors[] = "Row {$rowNo}: Email is required.";
                if ($f_school === '') $errors[] = "Row {$rowNo}: School/Centre is required.";
                if ($f_intake === '') $errors[] = "Row {$rowNo}: Intake Month/Year is required.";
                if ($f_grad === '') $errors[] = "Row {$rowNo}: Expected Graduation Year is required.";
                if ($f_sem === '') $errors[] = "Row {$rowNo}: Current Semester is required.";

                if ($f_email !== '' && !filter_var($f_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Row {$rowNo}: Invalid email format.";
                if (!in_array($f_role, $MEMBER_TYPES, true)) $errors[] = "Row {$rowNo}: Invalid member type.";
                if ($f_programme !== '' && !in_array($f_programme, $programmeOptions, true)) $errors[] = "Row {$rowNo}: Invalid programme selected.";
                if ($f_nationality !== '' && !in_array($f_nationality, $countries, true)) $errors[] = "Row {$rowNo}: Invalid nationality selected.";
                if ($f_school !== '' && !array_key_exists($f_school, $schools)) $errors[] = "Row {$rowNo}: Invalid school/centre selected.";
                if ($f_grad !== '' && !in_array($f_grad, $graduationOptions, true)) $errors[] = "Row {$rowNo}: Invalid expected graduation year selected.";
                if ($f_sem !== '' && !in_array($f_sem, $currentSemesterOptions, true)) $errors[] = "Row {$rowNo}: Invalid current semester selected.";

                if ($f_student_id !== '') {
                    if (isset($seenStudentIds[$f_student_id])) {
                        $errors[] = "Row {$rowNo}: Duplicate student ID in the submitted members list.";
                    }
                    $seenStudentIds[$f_student_id] = true;
                }
            }

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    // Prepare statements for member operations
                    $upd = $conn->prepare("UPDATE club_members SET 
                            full_name=?, student_id=?, programme=?, nationality=?, phone=?, email=?, school_centre=?, 
                            intake_month_year=?, expected_graduation_year=?, current_semester=?, member_type=?
                        WHERE id=? AND club_id=?");
                    
                    $ins = $conn->prepare("INSERT INTO club_members
                        (club_id, full_name, student_id, programme, nationality, phone, email, school_centre, intake_month_year, expected_graduation_year, current_semester, member_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $del = $conn->prepare("DELETE FROM club_members WHERE id=? AND club_id=?");

                    foreach ($members_post as $row) {
                        $mid = isset($row['id']) ? (int)$row['id'] : 0;
                        $is_delete = isset($row['delete']) && $row['delete'] === '1';

                        if ($mid > 0 && $is_delete) {
                            $del->bind_param("ii", $mid, $clubId);
                            $del->execute();
                            continue;
                        }

                        // Normalize fields
                        $f_full_name = trim($row['full_name'] ?? '');
                        $f_student_id = trim($row['student_id'] ?? '');
                        $f_programme = trim($row['programme'] ?? '');
                        $f_nationality = trim($row['nationality'] ?? '');
                        $f_phone = trim($row['phone'] ?? '');
                        $f_email = trim($row['email'] ?? '');
                        $f_school = trim($row['school_centre'] ?? '');
                        $f_intake = trim($row['intake_month_year'] ?? '');
                        $f_grad = trim($row['expected_graduation_year'] ?? '');
                        $f_sem = trim($row['current_semester'] ?? '');
                        $f_role = $row['member_type'] ?? 'regular';
                        if (!in_array($f_role, ['key_person','deputy','regular'], true)) $f_role = 'regular';

                        // Skip completely empty new rows. Role alone should not make a row count as filled.
                        $has_any_content = ($f_full_name !== '' || $f_student_id !== '' || $f_programme !== '' || $f_nationality !== '' ||
                                            $f_phone !== '' || $f_email !== '' || $f_school !== '' || $f_intake !== '' ||
                                            $f_grad !== '' || $f_sem !== '');
                        if ($mid === 0 && !$has_any_content) continue;

                        if ($mid > 0) {
                            $upd->bind_param(
                                "sssssssssssii",
                                $f_full_name, $f_student_id, $f_programme, $f_nationality, $f_phone, $f_email,
                                $f_school, $f_intake, $f_grad, $f_sem, $f_role,
                                $mid, $clubId
                            );
                            $upd->execute();
                        } else {
                            $ins->bind_param(
                                "isssssssssss",
                                $clubId, $f_full_name, $f_student_id, $f_programme, $f_nationality, $f_phone, $f_email,
                                $f_school, $f_intake, $f_grad, $f_sem, $f_role
                            );
                            $ins->execute();
                        }
                    }

                    if (isset($upd)) $upd->close();
                    if (isset($ins)) $ins->close();
                    if (isset($del)) $del->close();

                    $conn->commit();
                    $_SESSION['success'] = "Members updated successfully.";
                    header('Location: edit_club.php?id='.$clubId);
                    exit();

                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Member update error: " . $e->getMessage());
                    $errors[] = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// ---------- Render (safe to output now) ----------
require_once __DIR__ . '/header.php';

// Small helpers for selects
function sel($a,$b){ return $a===$b ? 'selected' : ''; }
?>
<main class="main-content container-fluid">
    <style>
        .member-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        .member-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .member-type-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        .tab-content {
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 1.5rem;
            background: white;
        }
        .table thead th { white-space: nowrap; }
        .form-control, .form-select { min-width: 120px; }
        .member-count-alert { background-color: #fff3cd; border-color: #ffeaa7; }
    </style>

    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_clubs.php">Manage Clubs</a></li>
                    <li class="breadcrumb-item"><a href="club_details.php?id=<?= (int)$editClub['id'] ?>">Club Details</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Club</li>
                </ol>
            </nav>
            <div class="d-flex gap-2">
                <a href="club_details.php?id=<?= (int)$editClub['id'] ?>" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <div class="col-12">
            <!-- Tabs -->
            <ul class="nav nav-tabs" id="clubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="club-info-tab" data-bs-toggle="tab" data-bs-target="#club-info" type="button" role="tab">
                        <i class="fa-solid fa-info-circle me-2"></i>Club Information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="members-tab" data-bs-toggle="tab" data-bs-target="#members" type="button" role="tab">
                        <i class="fa-solid fa-users me-2"></i>Manage Members (<?= count($members) ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bulk-members-tab" data-bs-toggle="tab" data-bs-target="#bulk-members" type="button" role="tab">
                        <i class="fa-solid fa-table me-2"></i>Bulk Edit Members
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="clubTabsContent">
                <!-- Club Information Tab -->
                <div class="tab-pane fade show active" id="club-info" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <h4 class="mb-3">Edit Club: <?= h($editClub['group_name']) ?> <small class="text-muted">(#<?= (int)$editClub['id'] ?>)</small></h4>

                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <strong>Please fix the following:</strong>
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($_SESSION['success'])): ?>
                                <div class="alert alert-success">
                                    <?= h($_SESSION['success']) ?>
                                </div>
                                <?php unset($_SESSION['success']); ?>
                            <?php endif; ?>

                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                <input type="hidden" name="update_club" value="1">

                                <div class="col-md-4">
                                    <label class="form-label">Club Identifier (optional)</label>
                                    <input type="text" class="form-control" name="club_identifier" value="<?= h($editClub['club_identifier'] ?? '') ?>">
                                    <div class="form-text">If your process uses an external code, add it here (or leave blank).</div>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Group / Club Name</label>
                                    <input type="text" class="form-control" name="group_name" value="<?= h($editClub['group_name'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Cluster</label>
                                    <select name="cluster" class="form-select" required>
                                        <?php foreach ($CLUSTERS as $opt): ?>
                                            <option value="<?= h($opt) ?>" <?= sel($opt, $editClub['cluster'] ?? '') ?>><?= h($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Focus Area</label>
                                    <select name="focus_area" class="form-select">
                                        <option value="">Unknown / Missing</option>
                                        <?php foreach ($FOCUS_AREAS as $opt): ?>
                                            <option value="<?= h($opt) ?>" <?= sel($opt, $editClub['focus_area'] ?? '') ?>><?= h($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Club Advisor</label>
                                    <input type="text" class="form-control" name="cluster_advisor" value="<?= h($editClub['cluster_advisor'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Key Person Name</label>
                                    <input type="text" class="form-control" name="key_person_name" value="<?= h($editClub['key_person_name'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Key Person Student ID</label>
                                    <input type="text" class="form-control" name="key_person_student_id" value="<?= h($editClub['key_person_student_id'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Deputy Key Person Name</label>
                                    <input type="text" class="form-control" name="deputy_key_person_name" value="<?= h($editClub['deputy_key_person_name'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Deputy Key Person Student ID</label>
                                    <input type="text" class="form-control" name="deputy_key_person_student_id" value="<?= h($editClub['deputy_key_person_student_id'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Date of Registration</label>
                                    <input type="date" class="form-control" name="date_of_registration" value="<?= h($editClub['date_of_registration'] ?? '') ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" required>
                                        <?php foreach ($STATUSES as $st): ?>
                                            <option value="<?= h($st) ?>" <?= sel($st, $editClub['status'] ?? '') ?>><?= h(ucfirst($st)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label d-block"> </label>
                                    <div class="text-muted small">
                                        <div>Created: <?= !empty($editClub['created_at']) ? h(date('M j, Y g:i A', strtotime($editClub['created_at']))) : '—' ?></div>
                                        <div>Updated: <?= !empty($editClub['updated_at']) ? h(date('M j, Y g:i A', strtotime($editClub['updated_at']))) : '—' ?></div>
                                    </div>
                                </div>

                                <div class="col-12 d-flex justify-content-end gap-2">
                                    <a href="club_details.php?id=<?= (int)$editClub['id'] ?>" class="btn btn-light">Cancel</a>
                                    <button class="btn btn-primary" type="submit">
                                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Members Tab (Individual Management) -->
                <div class="tab-pane fade" id="members" role="tabpanel">
                    <div class="row g-3">
                        <!-- Member Count Alert -->
                        <div class="col-12">
                            <div class="alert member-count-alert d-flex align-items-center">
                                <i class="fas fa-users me-2 fs-5"></i>
                                <div>
                                    <strong>Member Limit:</strong> Admin can save less than 5 or exactly 5 members. Maximum 5 members allowed. 
                                    Current count: <span class="badge bg-primary"><?= count($members) ?></span>
                                    <?php if (count($members) >= 5): ?>
                                        <span class="badge bg-danger ms-2">Maximum reached</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Current Members -->
                        <div class="col-12">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Current Members</h5>
                                    <span class="badge bg-primary"><?= count($members) ?> members</span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($members)): ?>
                                        <div class="text-center text-muted py-4">
                                            <i class="fa-solid fa-users fa-2x mb-3 d-block"></i>
                                            <p>No members found for this club.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="row g-3">
                                            <?php foreach ($members as $member): ?>
                                                <div class="col-md-6 col-lg-4">
                                                    <div class="member-card">
                                                        <div class="member-header">
                                                            <h6 class="mb-0"><?= h($member['full_name']) ?></h6>
                                                            <span class="badge <?= $member['member_type'] === 'key_person' ? 'bg-success' : ($member['member_type'] === 'deputy' ? 'bg-warning text-dark' : 'bg-secondary') ?> member-type-badge">
                                                                <?= h(ucfirst(str_replace('_', ' ', $member['member_type']))) ?>
                                                            </span>
                                                        </div>
                                                        <div class="small text-muted mb-2">
                                                            <div><strong>ID:</strong> <?= h($member['student_id']) ?></div>
                                                            <div><strong>Programme:</strong> <?= h($member['programme']) ?></div>
                                                            <div><strong>Email:</strong> <?= h($member['email']) ?></div>
                                                            <div><strong>Phone:</strong> <?= h($member['phone']) ?></div>
                                                            <div><strong>Graduation:</strong> <?= h($member['expected_graduation_year']) ?></div>
                                                        </div>
                                                        <form method="post" class="mt-2" onsubmit="return confirm('Are you sure you want to remove <?= h($member['full_name']) ?> from this club?');">
                                                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                                            <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                                                            <input type="hidden" name="remove_member" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                                <i class="fa-solid fa-user-minus me-1"></i> Remove Member
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Add New Member -->
                        <div class="col-12">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">Add New Member</h5>
                                </div>
                                <div class="card-body">
                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                        <input type="hidden" name="add_member" value="1">

                                        <div class="col-md-6">
                                            <label class="form-label">Full Name</label>
                                            <input type="text" class="form-control" name="full_name" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Student ID</label>
                                            <input type="text" class="form-control" name="student_id" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Programme</label>
                                            <select class="form-select" name="programme" required>
                                                <option value="">-- Select Programme --</option>
                                                <?php foreach ($programmeOptions as $programme): ?>
                                                    <option value="<?= h($programme) ?>"><?= h($programme) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Nationality</label>
                                            <select class="form-select" name="nationality" required>
                                                <option value="">-- Select Nationality --</option>
                                                <?php foreach ($countries as $country): ?>
                                                    <option value="<?= h($country) ?>"><?= h($country) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="email" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="phone" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">School/Centre</label>
                                            <select class="form-select" name="school_centre" required>
                                                <option value="">-- Select School/Centre --</option>
                                                <?php foreach ($schools as $value => $label): ?>
                                                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Intake Month/Year</label>
                                            <select class="form-select intake-select" name="intake_month_year" required>
                                                <option value="">-- Select Intake --</option>
                                            <!-- Will be populated by JavaScript -->
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Expected Graduation Year</label>
                                            <select class="form-select" name="expected_graduation_year" required>
                                                <option value="">-- Select Graduation --</option>
                                                <?php foreach ($graduationOptions as $grad): ?>
                                                    <option value="<?= h($grad) ?>"><?= h($grad) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Current Semester</label>
                                            <select class="form-select" name="current_semester" required>
                                                <option value="">-- Select Semester --</option>
                                                <?php foreach ($currentSemesterOptions as $semester): ?>
                                                    <option value="<?= h($semester) ?>"><?= h($semester) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Member Type</label>
                                            <select name="member_type" class="form-select" required>
                                                <?php foreach ($MEMBER_TYPES as $type): ?>
                                                    <option value="<?= h($type) ?>"><?= h(ucfirst(str_replace('_', ' ', $type))) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-success" <?= count($members) >= 5 ? 'disabled' : '' ?>>
                                                <i class="fa-solid fa-user-plus me-1"></i> Add Member
                                                <?php if (count($members) >= 5): ?>
                                                    <small class="d-block">(Max 5 reached)</small>
                                                <?php endif; ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Members Tab -->
                <div class="tab-pane fade" id="bulk-members" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <h5 class="mb-3">Bulk Edit Members</h5>
                            
                            <!-- Member Count Alert -->
                            <div class="alert member-count-alert d-flex align-items-center mb-4">
                                <i class="fas fa-users me-2 fs-5"></i>
                                <div>
                                    <strong>Member Limit:</strong> Admin can save less than 5 or exactly 5 members. Maximum 5 members allowed. 
                                    Current count: <span class="badge bg-primary"><?= count($members) ?></span>
                                    <?php if (count($members) >= 5): ?>
                                        <span class="badge bg-danger ms-2">Maximum reached</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                <input type="hidden" name="update_members" value="1">

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle" id="membersTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="min-width:180px;">Full Name</th>
                                                <th>Student ID</th>
                                                <th>Programme</th>
                                                <th>Nationality</th>
                                                <th>Phone</th>
                                                <th style="min-width:200px;">Email</th>
                                                <th>School/Centre</th>
                                                <th>Intake (Month/Year)</th>
                                                <th style="min-width:160px;">Expected Grad.</th>
                                                <th>Current Semester</th>
                                                <th>Role</th>
                                                <th>Delete</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $i => $m): ?>
                                                <tr>
                                                    <input type="hidden" name="members[<?= $i ?>][id]" value="<?= (int)$m['id'] ?>">
                                                    <td>
                                                        <input type="text" class="form-control" name="members[<?= $i ?>][full_name]" value="<?= h($m['full_name']) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control" name="members[<?= $i ?>][student_id]" value="<?= h($m['student_id']) ?>">
                                                    </td>
                                                    <td>
                                                        <select class="form-select" name="members[<?= $i ?>][programme]">
                                                            <option value=""></option>
                                                            <?php foreach ($programmeOptions as $opt): ?>
                                                                <option value="<?= h($opt) ?>" <?= ($m['programme'] === $opt ? 'selected' : '') ?>><?= h($opt) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select" name="members[<?= $i ?>][nationality]">
                                                            <option value=""></option>
                                                            <?php foreach ($countries as $c): ?>
                                                                <option value="<?= h($c) ?>" <?= ($m['nationality'] === $c ? 'selected' : '') ?>><?= h($c) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control" name="members[<?= $i ?>][phone]" value="<?= h($m['phone']) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="email" class="form-control" name="members[<?= $i ?>][email]" value="<?= h($m['email']) ?>">
                                                    </td>
                                                    <td>
                                                        <select class="form-select" name="members[<?= $i ?>][school_centre]">
                                                            <option value=""></option>
                                                            <?php foreach ($schools as $val => $label): ?>
                                                                <option value="<?= h($val) ?>" <?= ($m['school_centre'] === $val ? 'selected' : '') ?>><?= h($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select intake-select" name="members[<?= $i ?>][intake_month_year]">
                                                            <option value=""></option>
                                                        </select>
                                                        <?php if (!empty($m['intake_month_year'])): ?>
                                                            <input type="hidden" class="existing-intake" value="<?= h($m['intake_month_year']) ?>">
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <select class="form-select" name="members[<?= $i ?>][expected_graduation_year]">
                                                            <option value=""></option>
                                                            <?php foreach ($graduationOptions as $g): ?>
                                                                <option value="<?= h($g) ?>" <?= ($m['expected_graduation_year'] === $g ? 'selected' : '') ?>><?= h($g) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select" name="members[<?= $i ?>][current_semester]">
                                                            <option value=""></option>
                                                            <?php foreach ($currentSemesterOptions as $sem): ?>
                                                                <option value="<?= h($sem) ?>" <?= ($m['current_semester'] === $sem ? 'selected' : '') ?>><?= h($sem) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select" name="members[<?= $i ?>][member_type]">
                                                            <option value="key_person" <?= $m['member_type']==='key_person' ? 'selected':'' ?>>Key person</option>
                                                            <option value="deputy" <?= $m['member_type']==='deputy' ? 'selected':'' ?>>Deputy</option>
                                                            <option value="regular" <?= $m['member_type']==='regular' ? 'selected':'' ?>>Regular</option>
                                                        </select>
                                                    </td>
                                                    <td class="text-center">
                                                        <input type="checkbox" class="form-check-input" name="members[<?= $i ?>][delete]" value="1">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-sm btn-success" id="addMemberBtn" <?= count($members) >= 5 ? 'disabled' : '' ?>>
                                        <i class="fa fa-plus me-1"></i> Add Member Row
                                        <?php if (count($members) >= 5): ?>
                                            <small class="d-block">(Max 5 reached)</small>
                                        <?php endif; ?>
                                    </button>
                                    <button type="submit" class="btn btn-primary">Save Member Changes</button>
                                    <a href="edit_club.php?id=<?= (int)$clubId ?>" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Add new member row dynamically for bulk editing
    const addBtn = document.getElementById('addMemberBtn');
    const tbody = document.querySelector('#membersTable tbody');

    // Data from PHP
    const programmeOptions = <?= json_encode($programmeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const countries = <?= json_encode($countries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const schools = <?= json_encode($schools, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const semesters = <?= json_encode($currentSemesterOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const gradOptions = <?= json_encode($graduationOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Populate all intake selects (existing rows)
    populateAllIntakeSelects();

    if (addBtn && tbody) {
        addBtn.addEventListener('click', function () {
            const currentRows = tbody.querySelectorAll('tr').length;
            if (currentRows >= 5) {
                alert('Maximum of 5 members allowed. Please delete existing members before adding new ones.');
                return;
            }

            const index = currentRows;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <input type="hidden" name="members[${index}][id]" value="0">
                <td><input type="text" class="form-control" name="members[${index}][full_name]" value=""></td>
                <td><input type="text" class="form-control" name="members[${index}][student_id]" value=""></td>
                <td>
                    <select class="form-select" name="members[${index}][programme]">
                        <option value=""></option>
                        ${programmeOptions.map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][nationality]">
                        <option value=""></option>
                        ${countries.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('')}
                    </select>
                </td>
                <td><input type="text" class="form-control" name="members[${index}][phone]" value=""></td>
                <td><input type="email" class="form-control" name="members[${index}][email]" value=""></td>
                <td>
                    <select class="form-select" name="members[${index}][school_centre]">
                        <option value=""></option>
                        ${Object.entries(schools).map(([v,l]) => `<option value="${escapeHtml(v)}">${escapeHtml(l)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select intake-select" name="members[${index}][intake_month_year]">
                        <option value=""></option>
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][expected_graduation_year]">
                        <option value=""></option>
                        ${gradOptions.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][current_semester]">
                        <option value=""></option>
                        ${semesters.map(s => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="form-select" name="members[${index}][member_type]">
                        <option value="key_person">Key person</option>
                        <option value="deputy">Deputy</option>
                        <option value="regular" selected>Regular</option>
                    </select>
                </td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" name="members[${index}][delete]" value="1">
                </td>
            `;
            tbody.appendChild(tr);
            populateAllIntakeSelects();
            
            if (currentRows + 1 >= 5) addBtn.disabled = true;
        });
    }

    function populateAllIntakeSelects() {
        const selects = document.querySelectorAll('select.intake-select');
        const now = new Date();
        const currentYear = now.getFullYear();
        const intakeMonths = ["March", "October"];

        selects.forEach(select => {
            const prev = select.value;
            while (select.options.length > 1) select.remove(1);
            for (let y = currentYear - 5; y <= currentYear + 2; y++) {
                intakeMonths.forEach(m => {
                    const label = `${m} ${y} / ${y + 1}`;
                    const opt = document.createElement('option');
                    opt.value = label;
                    opt.textContent = label;
                    select.appendChild(opt);
                });
            }
            const existingInput = select.parentElement.querySelector('.existing-intake');
            const saved = existingInput ? existingInput.value : prev;
            if (saved) {
                const found = Array.from(select.options).some(o => o.value === saved);
                if (!found) {
                    const opt = document.createElement('option');
                    opt.value = saved;
                    opt.textContent = saved;
                    select.appendChild(opt);
                }
                select.value = saved;
            }
        });
    }

    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }
});
</script>
