<?php
include '../includes/db.php';
session_start();

/*
 * Make mysqli throw exceptions so try/catch works consistently.
 * If your db.php already enables this, this line is harmless.
 */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

// Include PHPMailer for email functionality
require_once '../PHPMailer/src/Exception.php';
require_once '../PHPMailer/src/PHPMailer.php';
require_once '../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

/* -----------------------------------------------------------
   Fetch all users with role='user' for the email search UI
------------------------------------------------------------*/
$users = [];
$user_query = $conn->query("
    SELECT id, name, email, phone_number, program_of_study, intake, country, gender, expected_graduation_year, department
    FROM users
    WHERE role = 'user'
    ORDER BY name ASC
");
if ($user_query) {
    while ($row = $user_query->fetch_assoc()) {
        $users[] = $row;
    }
}

function getClubEnumValues(mysqli $conn, $column) {
    $allowed = ['cluster', 'focus_area'];

    if (!in_array($column, $allowed, true)) {
        return [];
    }

    // Safe because column is whitelist validated
    $column = $conn->real_escape_string($column);

    $sql = "SHOW COLUMNS FROM clubs LIKE '$column'";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows === 0) {
        return [];
    }

    $row = $result->fetch_assoc();

    if (!isset($row['Type']) || strpos($row['Type'], 'enum(') !== 0) {
        return [];
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $row['Type'], $matches);

    return array_map('stripslashes', $matches[1] ?? []);
}

$allowed_clusters = getClubEnumValues($conn, 'cluster');
if (!$allowed_clusters) {
    $allowed_clusters = ['Zero Poverty', 'Zero Unemployment', 'Zero Net Carbon Emissions'];
}
$focus_area_options = getClubEnumValues($conn, 'focus_area');

/* -----------------------------------------------------------
   Email sending functions
------------------------------------------------------------*/
function createClubRegistrationMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host          = 'ace-sedi.aiu.edu.my';
    $mail->SMTPAuth      = true;
    $mail->Username      = 'acesediaiuedu';
    $mail->Password      = 'acesedi2024';
    $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port          = 465;
    $mail->Timeout       = 15;
    $mail->SMTPKeepAlive = true;

    $mail->setFrom('acesediaiuedu@ace-sedi.aiu.edu.my', '3ZERO Club System');
    $mail->isHTML(true);

    return $mail;
}

function buildClubRegistrationEmailBody($toName, $clubName, $cluster, $registrationDate) {
    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeClubName = htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8');
    $safeCluster = htmlspecialchars($cluster, ENT_QUOTES, 'UTF-8');
    $safeRegistrationDate = htmlspecialchars($registrationDate, ENT_QUOTES, 'UTF-8');

    return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1a5276, #0e2a47); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; }
                .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
                .club-info { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #1a5276; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>3ZERO Club Registration</h1>
                    <p>Albukhary International University</p>
                </div>
                <div class='content'>
                    <h2>Registration Confirmed!</h2>
                    <p>Dear <strong>$safeName</strong>,</p>
                    
                    <p>We are pleased to inform you that your registration for the 3ZERO Club. We will notify on further updates.</p>
                    
                    <div class='club-info'>
                        <h3>Club Details:</h3>
                        <p><strong>Club Name:</strong> $safeClubName</p>
                        <p><strong>Cluster:</strong> $safeCluster</p>
                        <p><strong>Registration Date:</strong> $safeRegistrationDate</p>
                    </div>
                    
                    <p>As a registered member, you are now part of our initiative to create positive social impact through the 3ZERO principles:</p>
                    <ul>
                        <li><strong>Zero Poverty</strong> - Creating economic opportunities for all</li>
                        <li><strong>Zero Unemployment</strong> - Empowering youth through skills development</li>
                        <li><strong>Zero Net Carbon Emissions</strong> - Building a sustainable future</li>
                    </ul>
                    
                    <p>You will be notified about upcoming meetings, events, and activities through this email address.</p>
                    
                    <p>Thank you for joining us in making a difference!</p>
                    
                    <p>Best regards,<br>
                    <strong>3ZERO Club Management Team</strong><br>
                    Albukhary International University</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
}

function sendClubRegistrationEmails(array $recipients, $clubName, $cluster, $registrationDate) {
    $sentCount = 0;
    $mail = null;

    try {
        $mail = createClubRegistrationMailer();
        $mail->smtpConnect();

        foreach ($recipients as $recipient) {
            $email = trim($recipient['email'] ?? '');
            $name = trim($recipient['name'] ?? '');
            $role = trim($recipient['role'] ?? 'Member');

            try {
                $mail->clearAddresses();
                $mail->clearCCs();
                $mail->clearBCCs();
                $mail->clearReplyTos();
                $mail->clearAttachments();
                $mail->clearCustomHeaders();

                $mail->addAddress($email, $name);
                $mail->Subject = '3ZERO Club Registration Confirmation - ' . $clubName;
                $mail->Body = buildClubRegistrationEmailBody($name, $clubName, $cluster, $registrationDate);
                $mail->AltBody = "Dear $name,\n\nYour registration for the 3ZERO Club '$clubName' ($cluster) has been confirmed on $registrationDate.\n\nThank you for joining us!\n\n3ZERO Club Management Team\nAlbukhary International University";

                $mail->send();
                $sentCount++;
            } catch (Throwable $e) {
                $details = $mail->ErrorInfo ?: $e->getMessage();
                error_log("Club registration email failed for {$role} ({$email}): {$details}");
            }
        }
    } catch (Throwable $e) {
        error_log("Club registration email SMTP connection failed: " . $e->getMessage());
        foreach ($recipients as $recipient) {
            $email = trim($recipient['email'] ?? '');
            $role = trim($recipient['role'] ?? 'Member');
            error_log("Club registration email failed for {$role} ({$email}): SMTP connection could not be opened.");
        }
    } finally {
        if ($mail instanceof PHPMailer) {
            $mail->smtpClose();
        }
    }

    return $sentCount;
}

/* -----------------------------------------------------------
   Handle POST
------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic fields
    $club_identifier      = trim($_POST['club_identifier'] ?? '');
    $group_name           = trim($_POST['group_name'] ?? '');
    $cluster              = trim($_POST['cluster'] ?? '');
    $focus_area           = trim($_POST['focus_area'] ?? '');
    $cluster_advisor      = trim($_POST['cluster_advisor'] ?? '');
    $date_of_registration = trim($_POST['date_of_registration'] ?? '');

    // Key Person (graduation as STRING)
    $key_person_email             = trim($_POST['key_person_email'] ?? '');
    $key_person_student_id        = trim($_POST['key_person_student_id'] ?? '');
    $key_person_programme         = trim($_POST['key_person_programme'] ?? '');
    $key_person_nationality       = trim($_POST['key_person_nationality'] ?? '');
    $key_person_phone             = trim($_POST['key_person_phone'] ?? '');
    $key_person_name              = trim($_POST['key_person_name'] ?? '');
    $key_person_school_centre     = trim($_POST['key_person_school_centre'] ?? '');
    $key_person_intake            = trim($_POST['key_person_intake'] ?? '');
    $key_person_graduation_year   = trim($_POST['key_person_graduation_year'] ?? ''); // STRING e.g., "March 2028"
    $key_person_current_semester  = trim($_POST['key_person_current_semester'] ?? '');

    // Deputy (graduation as STRING)
    $deputy_email                 = trim($_POST['deputy_email'] ?? '');
    $deputy_key_person_student_id = trim($_POST['deputy_key_person_student_id'] ?? '');
    $deputy_programme             = trim($_POST['deputy_programme'] ?? '');
    $deputy_nationality           = trim($_POST['deputy_nationality'] ?? '');
    $deputy_phone                 = trim($_POST['deputy_phone'] ?? '');
    $deputy_key_person_name       = trim($_POST['deputy_key_person_name'] ?? '');
    $deputy_school_centre         = trim($_POST['deputy_school_centre'] ?? '');
    $deputy_intake                = trim($_POST['deputy_intake'] ?? '');
    $deputy_graduation_year       = trim($_POST['deputy_graduation_year'] ?? ''); // STRING
    $deputy_current_semester      = trim($_POST['deputy_current_semester'] ?? '');

    // 3 required members (all fields required). Graduation as STRING
    $members = [];
    for ($i = 1; $i <= 3; $i++) {
        $members[] = [
            'email'                     => trim($_POST["member{$i}_email"] ?? ''),
            'student_id'                => trim($_POST["member{$i}_student_id"] ?? ''),
            'programme'                 => trim($_POST["member{$i}_programme"] ?? ''),
            'nationality'               => trim($_POST["member{$i}_nationality"] ?? ''),
            'phone'                     => trim($_POST["member{$i}_phone"] ?? ''),
            'full_name'                 => trim($_POST["member{$i}_name"] ?? ''),
            'school_centre'             => trim($_POST["member{$i}_school_centre"] ?? ''),
            'intake_month_year'         => trim($_POST["member{$i}_intake"] ?? ''),
            'expected_graduation_year'  => trim($_POST["member{$i}_graduation_year"] ?? ''), // STRING
            'current_semester'          => trim($_POST["member{$i}_current_semester"] ?? ''),
        ];
    }

    /* -----------------------------------------------------------
       Validation
    ------------------------------------------------------------*/
    $errors = [];

    // Club ID pattern (optional)
    if ($club_identifier !== '' && !preg_match('/^[0-9-]+$/', $club_identifier)) {
        $errors[] = "Club ID must contain only digits and dashes";
    }

    if ($group_name === '') {
        $errors[] = "Group Name is required";
    }

    // Cluster allowlist
    if ($cluster === '') {
        $errors[] = "Cluster is required";
    } elseif (!in_array($cluster, $allowed_clusters, true)) {
        $errors[] = "Invalid cluster selected";
    }

    if ($focus_area !== '' && !in_array($focus_area, $focus_area_options, true)) {
        $errors[] = "Invalid focus area selected";
    }

    if ($date_of_registration === '') {
        $errors[] = "Date of Registration is required";
    }

    // Key Person requireds
    if ($key_person_name === '') $errors[] = "Key Person Name is required";
    if ($key_person_student_id === '') $errors[] = "Key Person Student ID is required";
    if ($key_person_programme === '') $errors[] = "Key Person Programme is required";
    if ($key_person_nationality === '') $errors[] = "Key Person Nationality is required";
    if ($key_person_phone === '') $errors[] = "Key Person Phone is required";
    if ($key_person_email === '') $errors[] = "Key Person Email is required";
    if ($key_person_school_centre === '') $errors[] = "Key Person School/Centre is required";
    if ($key_person_intake === '') $errors[] = "Key Person Intake is required";
    if ($key_person_graduation_year === '') $errors[] = "Key Person Expected Year of Graduation is required";
    if ($key_person_current_semester === '') $errors[] = "Key Person Current Semester is required";

    // Deputy requireds
    if ($deputy_key_person_name === '') $errors[] = "Deputy Key Person Name is required";
    if ($deputy_key_person_student_id === '') $errors[] = "Deputy Key Person Student ID is required";
    if ($deputy_programme === '') $errors[] = "Deputy Programme is required";
    if ($deputy_nationality === '') $errors[] = "Deputy Nationality is required";
    if ($deputy_phone === '') $errors[] = "Deputy Phone is required";
    if ($deputy_email === '') $errors[] = "Deputy Email is required";
    if ($deputy_school_centre === '') $errors[] = "Deputy School/Centre is required";
    if ($deputy_intake === '') $errors[] = "Deputy Intake is required";
    if ($deputy_graduation_year === '') $errors[] = "Deputy Expected Year of Graduation is required";
    if ($deputy_current_semester === '') $errors[] = "Deputy Current Semester is required";

    // Email formats
    if ($key_person_email !== '' && !filter_var($key_person_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format for Key Person";
    }
    if ($deputy_email !== '' && !filter_var($deputy_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format for Deputy Key Person";
    }

    // Members: validate presence + email formats
    foreach ($members as $idx => $m) {
        $n = $idx + 1;
        if ($m['full_name'] === '') $errors[] = "Member {$n}: Full Name is required";
        if ($m['student_id'] === '') $errors[] = "Member {$n}: Student ID is required";
        if ($m['programme'] === '') $errors[] = "Member {$n}: Programme is required";
        if ($m['nationality'] === '') $errors[] = "Member {$n}: Nationality is required";
        if ($m['phone'] === '') $errors[] = "Member {$n}: Phone is required";
        if ($m['email'] === '') $errors[] = "Member {$n}: Email is required";
        if ($m['school_centre'] === '') $errors[] = "Member {$n}: School/Centre is required";
        if ($m['intake_month_year'] === '') $errors[] = "Member {$n}: Intake (Month/Year) is required";
        if ($m['expected_graduation_year'] === '') $errors[] = "Member {$n}: Expected Year of Graduation is required";
        if ($m['current_semester'] === '') $errors[] = "Member {$n}: Current Semester is required";

        if ($m['email'] !== '' && !filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Member {$n}: Invalid email format";
        }
    }

    // Normalize
    $group_name_norm      = preg_replace('/\s+/', ' ', trim($group_name));
    $club_identifier_norm = trim($club_identifier);

    // Build all 5 emails for uniqueness + existence checks
    $all_emails = [
        strtolower($key_person_email),
        strtolower($deputy_email),
        strtolower($members[0]['email']),
        strtolower($members[1]['email']),
        strtolower($members[2]['email']),
    ];

    // Uniqueness among 5 emails
    if (count(array_unique($all_emails)) !== 5) {
        $errors[] = "All five emails (Key, Deputy, 3 members) must be unique.";
    }

    // Ensure each email exists in users table with role='user'
    if (empty($errors)) {
        $ph = implode(',', array_fill(0, 5, '?'));
        $q = $conn->prepare("SELECT LOWER(email) AS e FROM users WHERE role='user' AND LOWER(email) IN ($ph)");
        $q->bind_param("sssss", $all_emails[0], $all_emails[1], $all_emails[2], $all_emails[3], $all_emails[4]);
        $q->execute();
        $found = [];
        $res = $q->get_result();
        while ($r = $res->fetch_assoc()) $found[] = $r['e'];
        if (count($found) !== 5) {
            $missing = array_diff($all_emails, $found);
            if (!empty($missing)) {
                $errors[] = "These emails are not registered users: " . implode(', ', $missing);
            }
        }
    }

    // Duplicate checks (only Club ID and Group Name should be unique)
    if (empty($errors)) {
        if ($club_identifier_norm !== '') {
            $check_identifier_sql = "SELECT 1 FROM clubs WHERE club_identifier = ? LIMIT 1";
            $stmt = $conn->prepare($check_identifier_sql);
            $stmt->bind_param("s", $club_identifier_norm);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = "Club ID '{$club_identifier}' already exists. Please use a unique ID.";
            }
        }

        $check_group_sql = "SELECT 1 FROM clubs WHERE LOWER(group_name) = LOWER(?) LIMIT 1";
        $stmt = $conn->prepare($check_group_sql);
        $stmt->bind_param("s", $group_name_norm);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Group Name '{$group_name}' already exists. Please choose a different name.";
        }
    }

    /* -----------------------------------------------------------
       If errors → flash and do not insert
    ------------------------------------------------------------*/
    if (!empty($errors)) {
        $_SESSION['error'] = implode("\n", $errors);
    } else {
        // Transaction
        $conn->begin_transaction();
        try {
            // Insert into clubs (status defaults in DB; store blank Club ID as NULL)
            $club_sql = "INSERT INTO clubs (
                club_identifier,
                group_name,
                cluster,
                focus_area,
                cluster_advisor,
                key_person_name,
                key_person_student_id,
                deputy_key_person_name,
                deputy_key_person_student_id,
                date_of_registration
            ) VALUES (NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($club_sql);
            $stmt->bind_param(
                "ssssssssss",
                $club_identifier_norm,
                $group_name_norm,
                $cluster,
                $focus_area,
                $cluster_advisor,
                $key_person_name,
                $key_person_student_id,
                $deputy_key_person_name,
                $deputy_key_person_student_id,
                $date_of_registration
            );
            $stmt->execute();
            $club_id = $conn->insert_id;

            // Prepare re-usable member insert (graduation as STRING)
            // 11 placeholders → "issssssssss" (1 int + 10 strings)
            $member_insert_sql = "INSERT INTO club_members (
                club_id, full_name, student_id, programme, nationality,
                phone, email, school_centre, intake_month_year, expected_graduation_year,
                current_semester, member_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt_member = $conn->prepare($member_insert_sql);

            // Insert Key Person
            $role = 'key_person';
            $stmt_member->bind_param(
                "isssssssssss",
                $club_id,
                $key_person_name,
                $key_person_student_id,
                $key_person_programme,
                $key_person_nationality,
                $key_person_phone,
                $key_person_email,
                $key_person_school_centre,
                $key_person_intake,
                $key_person_graduation_year,   // STRING
                $key_person_current_semester,
                $role
            );
            $stmt_member->execute();

            // Insert Deputy
            $role = 'deputy';
            $stmt_member->bind_param(
                "isssssssssss",
                $club_id,
                $deputy_key_person_name,
                $deputy_key_person_student_id,
                $deputy_programme,
                $deputy_nationality,
                $deputy_phone,
                $deputy_email,
                $deputy_school_centre,
                $deputy_intake,
                $deputy_graduation_year,       // STRING
                $deputy_current_semester,
                $role
            );
            $stmt_member->execute();

            // Insert 3 Regular Members
            foreach ($members as $m) {
                $role = 'regular';
                $stmt_member->bind_param(
                    "isssssssssss",
                    $club_id,
                    $m['full_name'],
                    $m['student_id'],
                    $m['programme'],
                    $m['nationality'],
                    $m['phone'],
                    $m['email'],
                    $m['school_centre'],
                    $m['intake_month_year'],
                    $m['expected_graduation_year'], // STRING
                    $m['current_semester'],
                    $role
                );
                $stmt_member->execute();
            }

            $conn->commit();
            
            /* -----------------------------------------------------------
               Send confirmation emails after successful database commit.
               Email failures are logged but never fail the registration.
            ------------------------------------------------------------*/
            $email_recipients = [
                ['email' => $key_person_email, 'name' => $key_person_name, 'role' => 'Key Person'],
                ['email' => $deputy_email, 'name' => $deputy_key_person_name, 'role' => 'Deputy'],
            ];

            foreach ($members as $index => $m) {
                $email_recipients[] = [
                    'email' => $m['email'],
                    'name' => $m['full_name'],
                    'role' => 'Member ' . ($index + 1),
                ];
            }

            $total_members = count($email_recipients);
            $email_sent_count = sendClubRegistrationEmails($email_recipients, $group_name_norm, $cluster, $date_of_registration);

            $success_message = "Club registration submitted successfully! Confirmation emails sent to $email_sent_count out of $total_members members.";
            if ($email_sent_count < $total_members) {
                $success_message .= " Some confirmation emails could not be sent, but registration was successful.";
            }

            $_SESSION['success'] = $success_message;
            header('Location: myclubs.php');
            exit();

        } catch (mysqli_sql_exception $e) {
            $conn->rollback();

            if ($e->getCode() === 1062) {
                $em = $e->getMessage();
                if (stripos($em, 'club_identifier') !== false) {
                    $_SESSION['error'] = "Club ID '{$club_identifier}' already exists. Please use a unique ID.";
                } elseif (stripos($em, 'group_name') !== false) {
                    $_SESSION['error'] = "Group Name '{$group_name}' already exists. Please choose a different name.";
                } else {
                    $_SESSION['error'] = "Registration failed due to a duplicate value (Club ID or Group Name). Please adjust and try again.";
                }
            } else {
                $_SESSION['error'] = "Registration failed: " . $e->getMessage();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Registration failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3ZERO Club Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary-blue:#1a5276; --light-blue:#e8f4fd; --dark-blue:#0e2a47; }
        body { background: linear-gradient(135deg, #e8f4fd 0%, #f0f8ff 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .registration-container { max-width: 1200px; margin: 2rem auto; background: #fff; border-radius: 15px; box-shadow: 0 10px 30px rgba(26,82,118,.15); overflow: hidden; }
        .registration-header { background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue)); color: #fff; padding: 2rem; text-align: center; }
        .registration-header h1 { margin: 0; font-size: 2.2rem; font-weight: 700; }
        .registration-header p { opacity: .9; margin: .5rem 0 0; }
        .form-section { padding: 2rem; border-bottom: 1px solid #e0e0e0; }
        .section-title { font-family:'Playfair Display',serif; color:#111; border-bottom:0; box-shadow:none; padding-bottom:.6rem; margin-bottom:1.5rem; font-size:1.35rem; font-weight:700; position:relative; display:inline-block; }
        .section-title::before { content:none; }
        .section-title::after { content:''; position:absolute; left:0; bottom:0; width:120px; height:4px; background:var(--primary-blue); border-radius:999px; }
        .member-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; position: relative; }
        .member-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
        .member-title { color: var(--primary-blue); font-weight: 600; font-size: 1.1rem; }
        .btn-primary { background: var(--primary-blue); border: none; padding: .75rem 1.5rem; font-weight: 600; }
        .btn-primary:hover { background: var(--dark-blue); transform: translateY(-1px); }
        .form-label { font-weight: 500; color: #2d3748; margin-bottom: .5rem; }
        .form-control, .form-select { border-radius: 8px; padding: .75rem 1rem; border: 1px solid #cbd5e0; }
        .form-control:focus, .form-select:focus { border-color: var(--primary-blue); box-shadow: 0 0 0 .2rem rgba(26, 82, 118, .25); }
        @media(max-width:767.98px){
            .registration-container{margin:.75rem auto; border-radius:12px}
            .registration-header{padding:1.35rem 1rem; text-align:left}
            .registration-header h1{font-size:1.55rem}
            .form-section{padding:1rem}
            .section-title{font-size:1.2rem}
            .member-card{padding:1rem}
            .btn{width:100%; min-height:44px}
            .row{--bs-gutter-y:.75rem}
        }
        .required::after { content: " *"; color: #e53e3e; }
        .important-note{ background:linear-gradient(135deg,#fff3cd,#ffeaa7); border:2px solid #ffc107; border-radius:12px; padding:1.5rem; margin:1.5rem; box-shadow:0 4px 12px rgba(255,193,7,.15)}
        .important-note-title{ color:#856404; font-weight:700; font-size:1.1rem; margin:0}
        .important-note-content{ color:#856404; font-size:.95rem; line-height:1.5; margin:0}

        /* ============================
           Enhanced Search Styles
        ============================ */
        .email-search-container { position: relative; margin-bottom: 10px; }
        .search-results {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: rgba(255,255,255,0.96);
            backdrop-filter: saturate(120%) blur(6px);
            border: 1px solid #dbe5ee;
            border-radius: 12px;
            max-height: 320px;
            overflow-y: auto;
            z-index: 1055;
            display: none;
            box-shadow: 0 14px 30px rgba(16, 42, 67, .12);
        }
        .search-results .results-head {
            position: sticky; top: 0;
            display:flex; align-items:center; gap:.5rem;
            background: linear-gradient(180deg, #f7fbff, #ffffff);
            border-bottom: 1px solid #e9f1f7;
            padding: .55rem .9rem;
            font-size: .82rem; color: #36566f;
            z-index: 1;
        }
        .results-head .pill {
            font-size: .72rem; background:#eaf4ff; color:#215982;
            padding:.15rem .45rem; border-radius:999px; border:1px solid #d7e8fa;
        }
        .search-result-item {
            padding: .65rem .9rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f6f9;
            transition: background-color .16s ease, border-left-color .16s ease;
            font-size: 0.92rem;
            display: grid;
            grid-template-columns: 36px 1fr auto;
            grid-template-areas:
                "avatar main meta";
            column-gap: .75rem;
            align-items: center;
        }
        .search-result-item:last-child { border-bottom: none; }
        .search-result-item:hover { background: #f5fbff; }
        .search-result-item.active { background: #e8f4fd; outline: 2px solid #b9dcff; }
        .sr-avatar {
            grid-area: avatar;
            width: 36px; height: 36px; border-radius: 50%;
            display:flex; align-items:center; justify-content:center;
            font-weight:700; color:#1a5276;
            background: #eaf4ff; border:1px solid #d4e9ff;
        }
        .sr-main { grid-area: main; min-width: 0; }
        .sr-email {
            color: var(--primary-blue);
            font-weight: 600; line-height: 1.05;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sr-name {
            color: #6b7b88; font-size: .83rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sr-meta {
            grid-area: meta;
            display:flex; gap:.35rem; align-items:center; flex-wrap: wrap;
        }
        .badge-soft {
            border: 1px solid #e2ebf3; background: #f7fbff; color:#3a5e77;
            padding: .15rem .45rem; border-radius: 999px; font-size: .72rem; white-space: nowrap;
        }
        .search-empty {
            text-align: center; padding: 1.1rem .9rem; color:#6b7b88;
            font-size:.9rem;
        }
        .search-empty i { color:#9bb4c7; }
        .auto-fill-notice {
            font-size: 0.8rem;
            color: #20744a;
            margin-top: 6px;
            display: none;
            padding: 6px 10px;
            background: #f1fff6;
            border: 1px solid #cbeed8;
            border-radius: 6px;
        }
        .kbd {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: .72rem;
            border: 1px solid #d6dde5;
            border-bottom-width: 2px;
            background: #fff;
            border-radius: 4px;
            padding: 0 .3rem;
            color:#476177;
        }
        mark {
            background: #ffedb5;
            padding: 0 .1rem;
            border-radius: 3px;
        }
        /* ensure parent doesn't clip dropdown */
        .form-section { position: relative; overflow: visible; }
    </style>
</head>
<body>
<?php include('header.php'); ?>

<div class="registration-container">
    <div class="registration-header">
        <h1><i class="bi bi-people-fill me-2"></i>3ZERO Club Registration</h1>
        <p>Register your club with all required member information</p>
    </div>

    <div class="important-note">
        <div class="d-flex align-items-center mb-2">
            <i class="bi bi-exclamation-circle-fill me-2" style="font-size:1.4rem;color:#856404"></i>
            <h3 class="important-note-title">Important Notice</h3>
        </div>
        <p class="important-note-content">
            <strong>Please ensure all 5 members of your club (Key Person, Deputy Key Person, and 3 regular members) are registered as users in the system using their student emails before submitting this club registration form.</strong>
            The system requires that all members have existing user accounts to successfully complete the club registration process. However, only one person needs to register for the club.
        </p>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= nl2br(htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <form action="club_registration.php" method="POST">
        <!-- Club Information -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-info-circle me-2"></i>Club Information</h3>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="date_of_registration" class="form-label required">Date of Registration</label>
                    <input type="date" class="form-control" id="date_of_registration" name="date_of_registration"
                           value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="club_identifier" class="form-label">Club ID</label>
                    <input type="text" class="form-control" id="club_identifier" name="club_identifier"
                           placeholder="e.g., 2025-001-07" pattern="^[0-9-]+$" title="Digits and dashes only">
                    <small class="text-muted">Optional — leave blank if you don't have the <strong>Club ID</strong> yet</small>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="group_name" class="form-label required">Group Name</label>
                    <input type="text" class="form-control" id="group_name" name="group_name" placeholder="Enter group name" required>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="cluster_advisor" class="form-label">Advisor</label>
                    <input type="text" class="form-control" id="cluster_advisor" name="cluster_advisor" placeholder="Enter cluster advisor name">
                    <small class="text-muted">Optional</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="cluster" class="form-label required">Cluster</label>
                    <select class="form-select" id="cluster" name="cluster" required>
                        <option value="" selected disabled>Choose cluster</option>
                        <?php foreach ($allowed_clusters as $opt): ?>
                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="focus_area" class="form-label">Focus Area</label>
                    <select class="form-select" id="focus_area" name="focus_area">
                        <option value="">Choose focus area</option>
                        <?php foreach ($focus_area_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Key Person -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-person-badge me-2"></i>Key Person</h3>
            <div class="member-card">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="key_person_email" class="form-label required">Email (Please enter the student email)</label>
                        <div class="email-search-container">
                            <input type="email" class="form-control" id="key_person_email" name="key_person_email" required
                                   placeholder="Start typing email to search for registered users...">
                            <div class="search-results" id="key_person_search_results"></div>
                        </div>
                        <div class="auto-fill-notice" id="key_person_auto_fill_notice">
                            <i class="fas fa-check-circle me-1"></i>User found! Other fields will be auto-filled.
                            <span class="ms-2 text-muted">Tip: Use <span class="kbd">↑</span><span class="kbd">↓</span> and <span class="kbd">Enter</span>.</span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="key_person_student_id" class="form-label required">Student ID</label>
                        <input type="text" class="form-control" id="key_person_student_id" name="key_person_student_id" placeholder="AIU12345678" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="key_person_programme" class="form-label required">Programme</label>
                        <select class="form-select" id="key_person_programme" name="key_person_programme" required>
                            <option value="">-- Select Programme --</option>
                            <option value="Bachelor of Business Administration (Honours)">Bachelor of Business Administration (Honours)</option>
                            <option value="Bachelor of Business Administration with Computer Science (Honours)">Bachelor of Business Administration with Computer Science (Honours)</option>
                            <option value="Bachelor of Business Administration (Honours) (Marketing)">Bachelor of Business Administration (Honours) (Marketing)</option>
                            <option value="Bachelor of Business Administration (Honours) (Human Resource Management)">Bachelor of Business Administration (Honours) (Human Resource Management)</option>
                            <option value="Bachelor of Economics (Honours)">Bachelor of Economics (Honours)</option>
                            <option value="Bachelor of Social Development (Honours)">Bachelor of Social Development (Honours)</option>
                            <option value="Bachelor of Finance (Islamic Finance) (Honours)">Bachelor of Finance (Islamic Finance) (Honours)</option>
                            <option value="Bachelor of Politics and International Relations (Honours)">Bachelor of Politics and International Relations (Honours)</option>
                            <option value="Master of Business Management">Master of Business Management</option>
                            <option value="Master in Social Business">Master in Social Business</option>
                            <option value="Doctor of Philosophy (Business Management)">Doctor of Philosophy (Business Management)</option>
                            <option value="Bachelor of Elementary Education (Honours)">Bachelor of Elementary Education (Honours)</option>
                            <option value="Bachelor in Early Childhood Education (Honours)">Bachelor in Early Childhood Education (Honours)</option>
                            <option value="Bachelor of Media and Communication (Honours)">Bachelor of Media and Communication (Honours)</option>
                            <option value="Master of Education">Master of Education</option>
                            <option value="Doctor of Philosophy (Education)">Doctor of Philosophy (Education)</option>
                            <option value="Bachelor in Computer Science (Honours)">Bachelor in Computer Science (Honours)</option>
                            <option value="Bachelor in Data Science (Honours)">Bachelor in Data Science (Honours)</option>
                            <option value="Master of Computing (by Research)">Master of Computing (by Research)</option>
                            <option value="Doctor of Philosophy in Computer Science">Doctor of Philosophy in Computer Science</option>
                            <option value="Foundation in Computing">Foundation in Computing</option>
                            <option value="Foundation in Arts">Foundation in Arts</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="key_person_nationality" class="form-label required">Nationality</label>
                        <select class="form-select" id="key_person_nationality" name="key_person_nationality" required>
                            <option value="">-- Select Nationality --</option>
                            <?php
                            $countries = ["Afghanistan","Albania","Algeria","Andorra","Angola","Antigua and Barbuda","Argentina","Armenia","Australia","Austria","Azerbaijan","Bahamas","Bahrain","Bangladesh","Barbados","Belarus","Belgium","Belize","Benin","Bhutan","Bolivia","Bosnia and Herzegovina","Botswana","Brazil","Brunei","Bulgaria","Burkina Faso","Burundi","Cabo Verde","Cambodia","Cameroon","Canada","Central African Republic","Chad","Chile","China","Colombia","Comoros","Congo (Congo-Brazzaville)","Costa Rica","Croatia","Cuba","Cyprus","Czechia (Czech Republic)","Democratic Republic of the Congo","Denmark","Djibouti","Dominica","Dominican Republic","Ecuador","Egypt","El Salvador","Equatorial Guinea","Eritrea","Estonia","Eswatini","Ethiopia","Fiji","Finland","France","Gabon","Gambia","Georgia","Germany","Ghana","Greece","Grenada","Guatemala","Guinea","Guinea-Bissau","Guyana","Haiti","Honduras","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland","Israel","Italy","Jamaica","Japan","Jordan","Kazakhstan","Kenya","Kiribati","Kuwait","Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya","Liechtenstein","Lithuania","Luxembourg","Madagascar","Malawi","Malaysia","Maldives","Mali","Malta","Marshall Islands","Mauritania","Mauritius","Mexico","Micronesia","Moldova","Monaco","Mongolia","Montenegro","Morocco","Mozambique","Myanmar","Namibia","Nauru","Nepal","Netherlands","New Zealand","Nicaragua","Niger","Nigeria","North Korea","North Macedonia","Norway","Oman","Pakistan","Palau","Palestine","Panama","Papua New Guinea","Paraguay","Peru","Philippines","Poland","Portugal","Qatar","Romania","Russia","Rwanda","Saint Kitts and Nevis","Saint Lucia","Saint Vincent and the Grenadines","Samoa","San Marino","Sao Tome and Principe","Saudi Arabia","Senegal","Serbia","Seychelles","Sierra Leone","Singapore","Slovakia","Slovenia","Solomon Islands","Somalia","South Africa","South Korea","South Sudan","Spain","Sri Lanka","Sudan","Suriname","Sweden","Switzerland","Syria","Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tonga","Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Tuvalu","Uganda","Ukraine","United Arab Emirates","United Kingdom","United States of America","Uruguay","Uzbekistan","Vanuatu","Venezuela","Vietnam","Yemen","Zambia","Zimbabwe"];
                            foreach ($countries as $c) {
                                echo '<option value="'.htmlspecialchars($c).'">'.htmlspecialchars($c).'</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="key_person_phone" class="form-label required">Phone</label>
                        <input type="tel" class="form-control" id="key_person_phone" name="key_person_phone" placeholder="01123456789" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="key_person_name" class="form-label required">Full Name</label>
                        <input type="text" class="form-control" id="key_person_name" name="key_person_name" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="key_person_school_centre" class="form-label required">School/Centre</label>
                        <select class="form-select" id="key_person_school_centre" name="key_person_school_centre" required>
                            <option value="">-- Select School/Centre --</option>
                            <option value="School Of Business & Social Sciences">School of Business & Social Sciences</option>
                            <option value="School Of Education & Human Sciences">School of Education & Human Sciences</option>
                            <option value="School Of Computing and Informatics">School of Computing and Informatics</option>
                            <option value="Centre for Foundation and General Studies">Centre for Foundation and General Studies</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="key_person_intake" class="form-label required">Intake (Month/Year)</label>
                        <select class="form-select" id="key_person_intake" name="key_person_intake" required>
                            <option value="">-- Select Intake --</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="key_person_graduation_year" class="form-label required">Expected Year of Graduation</label>
                        <select class="form-select" id="key_person_graduation_year" name="key_person_graduation_year" required>
                            <option value="">-- Select Graduation --</option>
                            <option value="March 2025">March 2025</option>
                            <option value="December 2025">December 2025</option>
                            <option value="March 2026">March 2026</option>
                            <option value="December 2026">December 2026</option>
                            <option value="March 2027">March 2027</option>
                            <option value="December 2027">December 2027</option>
                            <option value="March 2028">March 2028</option>
                            <option value="December 2028">December 2028</option>
                            <option value="March 2029">March 2029</option>
                            <option value="December 2029">December 2029</option>
                            <option value="March 2030">March 2030</option>
                            <option value="December 2030">December 2030</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="key_person_current_semester" class="form-label required">Current Semester</label>
                        <select class="form-select" id="key_person_current_semester" name="key_person_current_semester" required>
                            <option value="">-- Select Semester --</option>
                            <option value="CFGS Sem 1">CFGS Sem 1</option>
                            <option value="CFGS Sem 2">CFGS Sem 2</option>
                            <option value="CFGS Sem 3">CFGS Sem 3</option>
                            <option value="Year 1 Sem 1">Year 1 Sem 1</option>
                            <option value="Year 1 Sem 2">Year 1 Sem 2</option>
                            <option value="Year 1 Sem 3">Year 1 Sem 3</option>
                            <option value="Year 2 Sem 1">Year 2 Sem 1</option>
                            <option value="Year 2 Sem 2">Year 2 Sem 2</option>
                            <option value="Year 2 Sem 3">Year 2 Sem 3</option>
                            <option value="Year 3 Sem 1">Year 3 Sem 1</option>
                            <option value="Year 3 Sem 2">Year 3 Sem 2</option>
                            <option value="Year 3 Sem 3">Year 3 Sem 3</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Deputy -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-person-check me-2"></i>Deputy Key Person</h3>
            <div class="member-card">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="deputy_email" class="form-label required">Email (Please enter the student email)</label>
                        <div class="email-search-container">
                            <input type="email" class="form-control" id="deputy_email" name="deputy_email" required
                                   placeholder="Start typing email to search for registered users...">
                            <div class="search-results" id="deputy_search_results"></div>
                        </div>
                        <div class="auto-fill-notice" id="deputy_auto_fill_notice">
                            <i class="fas fa-check-circle me-1"></i>User found! Other fields will be auto-filled.
                            <span class="ms-2 text-muted">Use <span class="kbd">↑</span><span class="kbd">↓</span> + <span class="kbd">Enter</span>.</span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="deputy_key_person_student_id" class="form-label required">Student ID</label>
                        <input type="text" class="form-control" id="deputy_key_person_student_id" name="deputy_key_person_student_id" placeholder="AIU12345678" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="deputy_programme" class="form-label required">Programme</label>
                        <select class="form-select" id="deputy_programme" name="deputy_programme" required>
                            <option value="">-- Select Programme --</option>
                            <!-- same programme list as above -->
                            <option value="Bachelor of Business Administration (Honours)">Bachelor of Business Administration (Honours)</option>
                            <option value="Bachelor of Business Administration with Computer Science (Honours)">Bachelor of Business Administration with Computer Science (Honours)</option>
                            <option value="Bachelor of Business Administration (Honours) (Marketing)">Bachelor of Business Administration (Honours) (Marketing)</option>
                            <option value="Bachelor of Business Administration (Honours) (Human Resource Management)">Bachelor of Business Administration (Honours) (Human Resource Management)</option>
                            <option value="Bachelor of Economics (Honours)">Bachelor of Economics (Honours)</option>
                            <option value="Bachelor of Social Development (Honours)">Bachelor of Social Development (Honours)</option>
                            <option value="Bachelor of Finance (Islamic Finance) (Honours)">Bachelor of Finance (Islamic Finance) (Honours)</option>
                            <option value="Bachelor of Politics and International Relations (Honours)">Bachelor of Politics and International Relations (Honours)</option>
                            <option value="Master of Business Management">Master of Business Management</option>
                            <option value="Master in Social Business">Master in Social Business</option>
                            <option value="Doctor of Philosophy (Business Management)">Doctor of Philosophy (Business Management)</option>
                            <option value="Bachelor of Elementary Education (Honours)">Bachelor of Elementary Education (Honours)</option>
                            <option value="Bachelor in Early Childhood Education (Honours)">Bachelor in Early Childhood Education (Honours)</option>
                            <option value="Bachelor of Media and Communication (Honours)">Bachelor of Media and Communication (Honours)</option>
                            <option value="Master of Education">Master of Education</option>
                            <option value="Doctor of Philosophy (Education)">Doctor of Philosophy (Education)</option>
                            <option value="Bachelor in Computer Science (Honours)">Bachelor in Computer Science (Honours)</option>
                            <option value="Bachelor in Data Science (Honours)">Bachelor in Data Science (Honours)</option>
                            <option value="Master of Computing (by Research)">Master of Computing (by Research)</option>
                            <option value="Doctor of Philosophy in Computer Science">Doctor of Philosophy in Computer Science</option>
                            <option value="Foundation in Computing">Foundation in Computing</option>
                            <option value="Foundation in Arts">Foundation in Arts</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="deputy_nationality" class="form-label required">Nationality</label>
                        <select class="form-select" id="deputy_nationality" name="deputy_nationality" required>
                            <option value="">-- Select Nationality --</option>
                            <?php foreach ($countries as $c) { echo '<option value="'.htmlspecialchars($c).'">'.htmlspecialchars($c).'</option>'; } ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="deputy_phone" class="form-label required">Phone</label>
                        <input type="tel" class="form-control" id="deputy_phone" name="deputy_phone" placeholder="01123456789" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="deputy_key_person_name" class="form-label required">Full Name</label>
                        <input type="text" class="form-control" id="deputy_key_person_name" name="deputy_key_person_name" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="deputy_school_centre" class="form-label required">School/Centre</label>
                        <select class="form-select" id="deputy_school_centre" name="deputy_school_centre" required>
                            <option value="">-- Select School/Centre --</option>
                            <option value="School Of Business & Social Sciences">School of Business & Social Sciences</option>
                            <option value="School Of Education & Human Sciences">School of Education & Human Sciences</option>
                            <option value="School Of Computing and Informatics">School of Computing and Informatics</option>
                            <option value="Centre for Foundation and General Studies">Centre for Foundation and General Studies</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="deputy_intake" class="form-label required">Intake (Month/Year)</label>
                        <select class="form-select" id="deputy_intake" name="deputy_intake" required>
                            <option value="">-- Select Intake --</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="deputy_graduation_year" class="form-label required">Expected Year of Graduation</label>
                        <select class="form-select" id="deputy_graduation_year" name="deputy_graduation_year" required>
                            <option value="">-- Select Graduation --</option>
                            <option value="March 2025">March 2025</option>
                            <option value="December 2025">December 2025</option>
                            <option value="March 2026">March 2026</option>
                            <option value="December 2026">December 2026</option>
                            <option value="March 2027">March 2027</option>
                            <option value="December 2027">December 2027</option>
                            <option value="March 2028">March 2028</option>
                            <option value="December 2028">December 2028</option>
                            <option value="March 2029">March 2029</option>
                            <option value="December 2029">December 2029</option>
                            <option value="March 2030">March 2030</option>
                            <option value="December 2030">December 2030</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="deputy_current_semester" class="form-label required">Current Semester</label>
                        <select class="form-select" id="deputy_current_semester" name="deputy_current_semester" required>
                            <option value="">-- Select Semester --</option>
                            <option value="CFGS Sem 1">CFGS Sem 1</option>
                            <option value="CFGS Sem 2">CFGS Sem 2</option>
                            <option value="CFGS Sem 3">CFGS Sem 3</option>
                            <option value="Year 1 Sem 1">Year 1 Sem 1</option>
                            <option value="Year 1 Sem 2">Year 1 Sem 2</option>
                            <option value="Year 1 Sem 3">Year 1 Sem 3</option>
                            <option value="Year 2 Sem 1">Year 2 Sem 1</option>
                            <option value="Year 2 Sem 2">Year 2 Sem 2</option>
                            <option value="Year 2 Sem 3">Year 2 Sem 3</option>
                            <option value="Year 3 Sem 1">Year 3 Sem 1</option>
                            <option value="Year 3 Sem 2">Year 3 Sem 2</option>
                            <option value="Year 3 Sem 3">Year 3 Sem 3</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3 Members -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-people me-2"></i>Club Members (3 Required)</h3>
            <p class="text-muted mb-3">Please provide details for exactly three members.</p>

            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="member-card" id="member-<?= $i ?>">
                <div class="member-header">
                    <span class="member-title">Member <?= $i ?></span>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="member<?= $i ?>_email" class="form-label required">Email (Please enter the student email)</label>
                        <div class="email-search-container">
                            <input type="email" class="form-control" id="member<?= $i ?>_email" name="member<?= $i ?>_email" required
                                   placeholder="Start typing email to search for registered users...">
                            <div class="search-results" id="member<?= $i ?>_search_results"></div>
                        </div>
                        <div class="auto-fill-notice" id="member<?= $i ?>_auto_fill_notice">
                            <i class="fas fa-check-circle me-1"></i>User found! Other fields will be auto-filled.
                            <span class="ms-2 text-muted"><span class="kbd">↑</span><span class="kbd">↓</span> + <span class="kbd">Enter</span> to pick.</span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="member<?= $i ?>_student_id" class="form-label required">Student ID</label>
                        <input type="text" class="form-control" id="member<?= $i ?>_student_id" name="member<?= $i ?>_student_id" placeholder="AIU12345678" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="member<?= $i ?>_programme" class="form-label required">Programme</label>
                        <select class="form-select" id="member<?= $i ?>_programme" name="member<?= $i ?>_programme" required>
                            <option value="">-- Select Programme --</option>
                            <!-- same programme list as above -->
                            <option value="Bachelor of Business Administration (Honours)">Bachelor of Business Administration (Honours)</option>
                            <option value="Bachelor of Business Administration with Computer Science (Honours)">Bachelor of Business Administration with Computer Science (Honours)</option>
                            <option value="Bachelor of Business Administration (Honours) (Marketing)">Bachelor of Business Administration (Honours) (Marketing)</option>
                            <option value="Bachelor of Business Administration (Honours) (Human Resource Management)">Bachelor of Business Administration (Honours) (Human Resource Management)</option>
                            <option value="Bachelor of Economics (Honours)">Bachelor of Economics (Honours)</option>
                            <option value="Bachelor of Social Development (Honours)">Bachelor of Social Development (Honours)</option>
                            <option value="Bachelor of Finance (Islamic Finance) (Honours)">Bachelor of Finance (Islamic Finance) (Honours)</option>
                            <option value="Bachelor of Politics and International Relations (Honours)">Bachelor of Politics and International Relations (Honours)</option>
                            <option value="Master of Business Management">Master of Business Management</option>
                            <option value="Master in Social Business">Master in Social Business</option>
                            <option value="Doctor of Philosophy (Business Management)">Doctor of Philosophy (Business Management)</option>
                            <option value="Bachelor of Elementary Education (Honours)">Bachelor of Elementary Education (Honours)</option>
                            <option value="Bachelor in Early Childhood Education (Honours)">Bachelor in Early Childhood Education (Honours)</option>
                            <option value="Bachelor of Media and Communication (Honours)">Bachelor of Media and Communication (Honours)</option>
                            <option value="Master of Education">Master of Education</option>
                            <option value="Doctor of Philosophy (Education)">Doctor of Philosophy (Education)</option>
                            <option value="Bachelor in Computer Science (Honours)">Bachelor in Computer Science (Honours)</option>
                            <option value="Bachelor in Data Science (Honours)">Bachelor in Data Science (Honours)</option>
                            <option value="Master of Computing (by Research)">Master of Computing (by Research)</option>
                            <option value="Doctor of Philosophy in Computer Science">Doctor of Philosophy in Computer Science</option>
                            <option value="Foundation in Computing">Foundation in Computing</option>
                            <option value="Foundation in Arts">Foundation in Arts</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="member<?= $i ?>_nationality" class="form-label required">Nationality</label>
                        <select class="form-select" id="member<?= $i ?>_nationality" name="member<?= $i ?>_nationality" required>
                            <option value="">-- Select Nationality --</option>
                            <?php foreach ($countries as $c) { echo '<option value="'.htmlspecialchars($c).'">'.htmlspecialchars($c).'</option>'; } ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="member<?= $i ?>_phone" class="form-label required">Phone</label>
                        <input type="tel" class="form-control" id="member<?= $i ?>_phone" name="member<?= $i ?>_phone" placeholder="01123456789" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="member<?= $i ?>_name" class="form-label required">Full Name</label>
                        <input type="text" class="form-control" id="member<?= $i ?>_name" name="member<?= $i ?>_name" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="member<?= $i ?>_school_centre" class="form-label required">School/Centre</label>
                        <select class="form-select" id="member<?= $i ?>_school_centre" name="member<?= $i ?>_school_centre" required>
                            <option value="">-- Select School/Centre --</option>
                            <option value="School Of Business & Social Sciences">School of Business & Social Sciences</option>
                            <option value="School Of Education & Human Sciences">School of Education & Human Sciences</option>
                            <option value="School Of Computing and Informatics">School of Computing and Informatics</option>
                            <option value="Centre for Foundation and General Studies">Centre for Foundation and General Studies</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="member<?= $i ?>_intake" class="form-label required">Intake (Month/Year)</label>
                        <select class="form-select" id="member<?= $i ?>_intake" name="member<?= $i ?>_intake" required>
                            <option value="">-- Select Intake --</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="member<?= $i ?>_graduation_year" class="form-label required">Expected Year of Graduation</label>
                        <select class="form-select" id="member<?= $i ?>_graduation_year" name="member<?= $i ?>_graduation_year" required>
                            <option value="">-- Select Graduation --</option>
                            <option value="March 2025">March 2025</option>
                            <option value="December 2025">December 2025</option>
                            <option value="March 2026">March 2026</option>
                            <option value="December 2026">December 2026</option>
                            <option value="March 2027">March 2027</option>
                            <option value="December 2027">December 2027</option>
                            <option value="March 2028">March 2028</option>
                            <option value="December 2028">December 2028</option>
                            <option value="March 2029">March 2029</option>
                            <option value="December 2029">December 2029</option>
                            <option value="March 2030">March 2030</option>
                            <option value="December 2030">December 2030</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="member<?= $i ?>_current_semester" class="form-label required">Current Semester</label>
                        <select class="form-select" id="member<?= $i ?>_current_semester" name="member<?= $i ?>_current_semester" required>
                            <option value="">-- Select Semester --</option>
                            <option value="CFGS Sem 1">CFGS Sem 1</option>
                            <option value="CFGS Sem 2">CFGS Sem 2</option>
                            <option value="CFGS Sem 3">CFGS Sem 3</option>
                            <option value="Year 1 Sem 1">Year 1 Sem 1</option>
                            <option value="Year 1 Sem 2">Year 1 Sem 2</option>
                            <option value="Year 1 Sem 3">Year 1 Sem 3</option>
                            <option value="Year 2 Sem 1">Year 2 Sem 1</option>
                            <option value="Year 2 Sem 2">Year 2 Sem 2</option>
                            <option value="Year 2 Sem 3">Year 2 Sem 3</option>
                            <option value="Year 3 Sem 1">Year 3 Sem 1</option>
                            <option value="Year 3 Sem 2">Year 3 Sem 2</option>
                            <option value="Year 3 Sem 3">Year 3 Sem 3</option>
                        </select>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- Submit -->
        <div class="form-section text-center">
            <button type="submit" class="btn btn-primary btn-lg px-5">
                <i class="bi bi-send-check me-2"></i>Submit Registration
            </button>
            <p class="text-muted mt-3">All fields marked with * are required</p>
        </div>
    </form>
</div>

<!-- Submission Wait Notice Modal -->
<div class="modal fade" id="submissionWaitModal" tabindex="-1" aria-labelledby="submissionWaitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="submissionWaitModalLabel">Before You Submit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    This submission may take about 1-3 minutes to process. Please do not close this window or refresh the page after continuing.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Review Form</button>
                <button type="button" class="btn btn-primary" id="confirmSubmissionWait">
                    OK, Submit Now
                </button>
            </div>
        </div>
    </div>
</div>

<?php include('footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // User data from PHP
    const users = <?php echo json_encode($users, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        // Set date if empty
        const dateInput = document.getElementById('date_of_registration');
        if (dateInput && !dateInput.value) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }

        // Populate all intake selects
        const intakeSelects = document.querySelectorAll('select[id$="_intake"]');
        const now = new Date();
        const currentYear = now.getFullYear();
        const intakeMonths = ["March", "October"];

        intakeSelects.forEach(select => {
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
        });

        // Wire email search for all email fields
        initEmailSearch('key_person_email', 'key_person_search_results', 'key_person_auto_fill_notice');
        initEmailSearch('deputy_email', 'deputy_search_results', 'deputy_auto_fill_notice');
        for (let i = 1; i <= 3; i++) {
            initEmailSearch(`member${i}_email`, `member${i}_search_results`, `member${i}_auto_fill_notice`);
        }
        
        // Reset button state if there are errors or success messages on page load
        const errorAlert = document.querySelector('.alert-danger');
        const successAlert = document.querySelector('.alert-success');
        const submitBtn = document.querySelector('button[type="submit"]');
        
        if ((errorAlert || successAlert) && submitBtn) {
            submitBtn.classList.remove('is-loading');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-send-check me-2"></i>Submit Registration';
        }
    });

    /* ============================
       Enhanced Typeahead Logic
    ============================ */
    function initEmailSearch(inputId, resultsId, noticeId) {
        const input  = document.getElementById(inputId);
        const box    = document.getElementById(resultsId);
        const notice = document.getElementById(noticeId);
        if (!input || !box) return;

        let activeIndex = -1;
        let currentItems = [];

        // Debounce to keep UI smooth
        const debouncedRender = debounce(renderResults, 120);

        input.setAttribute('autocomplete', 'off');

        input.addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            activeIndex = -1;
            if (term.length < 2) {
                hideResults();
                if (notice) notice.style.display = 'none';
                return;
            }
            const filtered = filterUsers(term);
            currentItems = filtered;
            debouncedRender(term, filtered);
        });

        input.addEventListener('keydown', (e) => {
            if (box.style.display !== 'block') return;

            const max = currentItems.length;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = (activeIndex + 1 + max) % max;
                reflectActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = (activeIndex - 1 + max) % max;
                reflectActive();
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && activeIndex < max) {
                    e.preventDefault();
                    pick(currentItems[activeIndex]);
                }
            } else if (e.key === 'Escape') {
                hideResults();
            }
        });

        // Hide on click outside
        document.addEventListener('click', (e) => {
            if (!box.contains(e.target) && e.target !== input) hideResults();
        });

        function hideResults() {
            box.style.display = 'none';
            box.innerHTML = '';
            activeIndex = -1;
            currentItems = [];
        }

        function reflectActive() {
            const items = box.querySelectorAll('.search-result-item');
            items.forEach((el, i) => {
                if (i === activeIndex) {
                    el.classList.add('active');
                    ensureVisible(el, box);
                } else {
                    el.classList.remove('active');
                }
            });
        }

        function pick(u) {
            autofillByUser(inputId, u);
            input.value = u.email || '';
            hideResults();
            if (notice) {
                notice.style.display = 'block';
                setTimeout(() => { notice.style.display = 'none'; }, 3000);
            }
        }

        function renderResults(term, list) {
            box.innerHTML = '';
            const head = document.createElement('div');
            head.className = 'results-head';
            head.innerHTML = `<i class="bi bi-search"></i>
                              <span><strong>${escapeHtml(term)}</strong></span>
                              <span class="pill">${list.length} result${list.length===1?'':'s'}</span>`;
            box.appendChild(head);

            if (list.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'search-empty';
                empty.innerHTML = `<i class="bi bi-emoji-neutral me-2"></i>No users found. Check the email spelling.`;
                box.appendChild(empty);
            } else {
                list.forEach((u, idx) => {
                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.innerHTML = renderItemHtml(u, term);
                    item.addEventListener('mouseenter', () => { activeIndex = idx; reflectActive(); });
                    item.addEventListener('mouseleave', () => { activeIndex = -1; reflectActive(); });
                    item.addEventListener('click', () => pick(u));
                    box.appendChild(item);
                });
            }
            box.style.display = 'block';
            activeIndex = -1;
        }
    }

    function filterUsers(term) {
        const t = term.toLowerCase();
        // Prioritize email matches over name matches
        const scored = users.map(u => {
            const email = (u.email || '').toLowerCase();
            const name  = (u.name || '').toLowerCase();
            const emailScore = email.includes(t) ? 2 : 0;
            const nameScore  = name.includes(t)  ? 1 : 0;
            return { u, s: emailScore + nameScore, emailMatch: email.includes(t) };
        }).filter(x => x.s > 0);

        scored.sort((a,b) => {
            if (a.s !== b.s) return b.s - a.s;
            // If same score, shorter email first
            return (a.u.email || '').length - (b.u.email || '').length;
        });
        return scored.map(x => x.u);
    }

    function initialsFromName(name, email) {
        const source = (name || '').trim() || (email || '').split('@')[0];
        const parts = source.split(/\s+/).filter(Boolean);
        if (parts.length === 0) return 'U';
        if (parts.length === 1) return parts[0].substring(0,2).toUpperCase();
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    function highlight(text, term) {
        if (!text) return '';
        const esc = text.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        if (!term) return esc;
        const t = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return esc.replace(new RegExp(`(${t})`, 'ig'), '<mark>$1</mark>');
    }

    function renderItemHtml(u, term) {
        const email = u.email || '';
        const name  = u.name || 'No name';
        const prog  = u.program_of_study || u.program || '';
        const intake= u.intake || '';
        const avatar = initialsFromName(name, email);

        return `
            <div class="sr-avatar">${escapeHtml(avatar)}</div>
            <div class="sr-main">
                <div class="sr-email">${highlight(email, term)}</div>
                <div class="sr-name">${highlight(name, term)}</div>
            </div>
            <div class="sr-meta">
                ${prog ? `<span class="badge-soft" title="${escapeHtml(prog)}">${escapeHtml(shorten(prog, 22))}</span>` : ''}
                ${intake ? `<span class="badge-soft" title="${escapeHtml(intake)}">${escapeHtml(intake)}</span>` : ''}
            </div>
        `;
    }

    function shorten(s, n) { return (s && s.length > n) ? s.slice(0, n-1) + '…' : (s || ''); }

    function ensureVisible(el, container) {
        const cTop = container.scrollTop;
        const cBot = cTop + container.clientHeight;
        const eTop = el.offsetTop;
        const eBot = eTop + el.offsetHeight;
        if (eTop < cTop) container.scrollTop = eTop - 36;
        else if (eBot > cBot) container.scrollTop = eBot - container.clientHeight + 36;
    }

    function autofillByUser(emailFieldId, u) {
        const fieldMappings = {
            'key_person_email': {
                '_name': 'name',
                '_phone': 'phone_number', 
                '_programme': 'program_of_study',
                '_nationality': 'country',
                '_intake': 'intake',
                '_graduation_year': 'expected_graduation_year',
                '_school_centre': 'department'
            },
            'deputy_email': {
                '_key_person_name': 'name',
                '_phone': 'phone_number',
                '_programme': 'program_of_study', 
                '_nationality': 'country',
                '_intake': 'intake',
                '_graduation_year': 'expected_graduation_year',
                '_school_centre': 'department'
            },
            'member1_email': {
                '_name': 'name',
                '_phone': 'phone_number',
                '_programme': 'program_of_study',
                '_nationality': 'country', 
                '_intake': 'intake',
                '_graduation_year': 'expected_graduation_year',
                '_school_centre': 'department'
            },
            'member2_email': {
                '_name': 'name',
                '_phone': 'phone_number',
                '_programme': 'program_of_study',
                '_nationality': 'country',
                '_intake': 'intake',
                '_graduation_year': 'expected_graduation_year',
                '_school_centre': 'department'
            },
            'member3_email': {
                '_name': 'name',
                '_phone': 'phone_number',
                '_programme': 'program_of_study',
                '_nationality': 'country',
                '_intake': 'intake',
                '_graduation_year': 'expected_graduation_year',
                '_school_centre': 'department'
            }
        };

        const mappings = fieldMappings[emailFieldId];
        if (!mappings) return;

        Object.entries(mappings).forEach(([suffix, userKey]) => {
            const fieldId = emailFieldId.replace('_email', '') + suffix;
            const field = document.getElementById(fieldId);
            if (field && u[userKey]) {
                field.value = u[userKey];
            }
        });
    }

    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }

    function debounce(fn, wait) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(null, args), wait);
        };
    }

    // FORM SUBMIT HANDLER - FIX FOR INFINITE LOADING
    const clubForm = document.querySelector('form[action="club_registration.php"]');
    if (clubForm) {
        // Remove any existing submit handlers to prevent duplicates
        clubForm.removeEventListener('submit', clubForm._submitHandler);
        const waitModalEl = document.getElementById('submissionWaitModal');
        const confirmSubmissionWaitBtn = document.getElementById('confirmSubmissionWait');
        const waitModal = waitModalEl ? new bootstrap.Modal(waitModalEl) : null;
        
        function setSubmittingState(form) {
            const btn = form.querySelector('button[type="submit"]');
            if (!btn) return;
            btn.classList.add('is-loading');
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.setAttribute('data-original-html', originalHtml);
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><span>Submitting...</span>';
            
            // Reset only if the request appears stuck beyond the expected processing window.
            setTimeout(() => {
                if (btn.disabled && !document.querySelector('.alert-success')) {
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                    btn.innerHTML = btn.getAttribute('data-original-html') || '<i class="bi bi-send-check me-2"></i>Submit Registration';
                    form.dataset.submissionConfirmed = 'false';
                }
            }, 210000);
        }
        
        // Create new handler
        clubForm._submitHandler = function(e) {
            const btn = this.querySelector('button[type="submit"]');
            if (btn && !btn.classList.contains('is-loading')) {
                // Check if there are any PHP errors already displayed
                const existingError = document.querySelector('.alert-danger');
                if (existingError) {
                    // Don't show loading if there's already an error
                    return;
                }
                
                // Quick client-side validation for required fields
                let isValid = true;
                const requiredFields = this.querySelectorAll('[required]');
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.classList.add('is-invalid');
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });
                
                if (isValid) {
                    if (this.dataset.submissionConfirmed === 'true') {
                        setSubmittingState(this);
                        return;
                    }

                    e.preventDefault();
                    if (waitModal) {
                        waitModal.show();
                    } else if (window.confirm('This submission may take about 1-3 minutes to process. Please do not close this window or refresh the page after continuing.')) {
                        this.dataset.submissionConfirmed = 'true';
                        this.requestSubmit();
                    }
                } else {
                    e.preventDefault();
                    // Show error message
                    const firstInvalid = document.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstInvalid.focus();
                    }
                }
            }
        };
        
        clubForm.addEventListener('submit', clubForm._submitHandler);

        confirmSubmissionWaitBtn?.addEventListener('click', function() {
            clubForm.dataset.submissionConfirmed = 'true';
            waitModal?.hide();
            clubForm.requestSubmit();
        });
    }

    // Header dropdowns (optional)
    document.addEventListener('DOMContentLoaded', function () {
        const headerEl = document.querySelector('.main-header');
        if (headerEl) headerEl.style.overflow = 'visible';
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
        });
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
