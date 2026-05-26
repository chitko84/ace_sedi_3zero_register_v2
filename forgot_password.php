<?php
include 'includes/db.php';
session_start();

// Email sending function
function sendPasswordResetEmail($email, $name, $reset_token) {
    // Dynamic base URL that includes the subdirectory
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
    $reset_link = $base_url . "/reset_password.php?token=" . $reset_token;
    
    $subject = "Password Reset Request - 3ZERO Club";
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1a5276, #154360); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; padding: 12px 30px; background: #1a5276; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>3ZERO Club</h2>
                <p>Password Reset Request</p>
            </div>
            <div class='content'>
                <h3>Hello " . htmlspecialchars($name) . ",</h3>
                <p>We received a request to reset your password for your 3ZERO Club account.</p>
                <p>Click the button below to reset your password:</p>
                <p style='text-align: center;'>
                    <a href='" . $reset_link . "' class='button'>Reset Your Password</a>
                </p>
                <p>Or copy and paste this link in your browser:<br>
                <code>" . $reset_link . "</code></p>
                <p><strong>This link will expire in 1 hour.</strong></p>
                <p>If you didn't request this reset, please ignore this email. Your password will remain unchanged.</p>
            </div>
            <div class='footer'>
                <p>This is an automated message from 3ZERO Club System.<br>
                Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: 3ZERO Club <no-reply@ace-sedi.aiu.edu.my>" . "\r\n";
    $headers .= "Reply-To: no-reply@ace-sedi.aiu.edu.my" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($email, $subject, $message, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        header('Location: forgot_password.php');
        exit();
    }

    // Check if email exists in the database
    $query = "SELECT id, name, email FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Generate a unique reset token
        $reset_token = bin2hex(random_bytes(32));
        $expiry_time = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour
        
        // Store the reset token in the database
        $update_query = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("sss", $reset_token, $expiry_time, $email);
        
        if ($update_stmt->execute()) {
            // Send actual email
            $email_sent = sendPasswordResetEmail($user['email'], $user['name'], $reset_token);
            
            if ($email_sent) {
                $_SESSION['success'] = "Password reset link has been sent to your email! Please check your inbox (and spam folder).";
            } else {
                $_SESSION['error'] = "Failed to send email. Please try again or contact support.";
            }
        } else {
            $_SESSION['error'] = "Error generating reset token. Please try again.";
        }
    } else {
        $_SESSION['error'] = "No account found with this email address.";
    }
    
    header('Location: forgot_password.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - 3ZERO Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="uploads/aiu_logo.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --primary-blue: #1a5276;
        --light-blue: #e8f4fd;
        --dark-blue: #0e2a47;
        --accent-blue: #3498db;
        --white: #FFFFFF;
        --light-gray: #F9FBF8;
        --text-gray: #3E3E3E;
    }

    body {
        font-family: 'Poppins', sans-serif;
        background: linear-gradient(135deg, #e8f4fd 0%, #f0f8ff 100%);
        color: var(--text-gray);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding-top: 80px;
    }

    .auth-container {
        max-width: 500px;
        margin: 2rem auto;
        padding: 3rem 2.5rem;
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 40px rgba(26, 82, 118, 0.12);
        width: 90%;
        flex: 1;
    }

    .auth-header {
        text-align: center;
        margin-bottom: 2.5rem;
    }

    .auth-title {
        font-family: 'Playfair Display', serif;
        font-weight: 700;
        color: var(--dark-blue);
        margin-bottom: 0.5rem;
        font-size: 1.8rem;
    }

    .auth-subtitle {
        color: var(--text-gray);
        opacity: 0.8;
        font-weight: 400;
        font-size: 1rem;
    }

    .form-label {
        font-weight: 500;
        color: var(--text-gray);
        margin-bottom: 0.5rem;
    }

    .form-control {
        padding: 0.85rem 1.25rem;
        border-radius: 12px;
        border: 1px solid #E0E0E0;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 0.25rem rgba(26, 82, 118, 0.18);
    }

    .input-group-text {
        background-color: var(--light-gray);
        cursor: pointer;
        border-radius: 0 12px 12px 0;
    }

    .btn-auth {
        width: 100%;
        padding: 1rem;
        border-radius: 12px;
        background-color: var(--primary-blue);
        border: none;
        font-weight: 600;
        transition: all 0.3s ease;
        margin-top: 1rem;
        font-size: 1.1rem;
    }

    .btn-auth:hover {
        background-color: var(--dark-blue);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(26, 82, 118, 0.3);
    }

    @media (max-width: 576px) {
        .auth-container {
            padding: 2rem 1.5rem;
            margin: 1rem auto;
        }
        
        .main-content {
            padding-top: 70px;
        }
    }

    .back-to-login {
        text-align: center;
        margin-top: 1.5rem;
    }
    
    .back-to-login a {
        color: var(--primary-blue);
        text-decoration: none;
        font-size: 0.9rem;
    }
    
    .back-to-login a:hover {
        text-decoration: underline;
    }
    
    .info-box {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        border: 2px solid #2196f3;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 4px 12px rgba(33, 150, 243, 0.15);
    }
    
    .info-box-header {
        display: flex;
        align-items: center;
        margin-bottom: 0.75rem;
    }
    
    .info-box-icon {
        font-size: 1.5rem;
        color: #1976d2;
        margin-right: 0.75rem;
    }
    
    .info-box-title {
        color: #1976d2;
        font-weight: 700;
        font-size: 1.1rem;
        margin: 0;
    }
    
    .info-box-content {
        color: #1976d2;
        font-size: 0.95rem;
        line-height: 1.5;
        margin: 0;
    }

    .email-note {
        background: #e8f5e8;
        border: 1px solid #4caf50;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
        font-size: 0.9rem;
    }
    </style>
</head>

<body>
    <?php include('header.php'); ?>
    
    <div class="main-content">
        <div class="container py-4">
            <div class="auth-container">
                <div class="auth-header">
                    <h2 class="auth-title">Reset Your Password</h2>
                    <p class="auth-subtitle">Enter your email to receive a password reset link</p>
                </div>
                
                <!-- Information Box -->
                <div class="info-box">
                    <div class="info-box-header">
                        <i class="bi bi-info-circle-fill info-box-icon"></i>
                        <h3 class="info-box-title">Password Reset Process</h3>
                    </div>
                    <p class="info-box-content">
                        Enter your registered email address. We'll send you a secure link to reset your password. The link will expire in 1 hour for security reasons.
                    </p>
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
                    
                    <div class="email-note">
                        <i class="bi bi-envelope-check me-2"></i>
                        <strong>Email Sent!</strong> Please check your inbox and spam folder. If you don't receive the email within 5 minutes, please try again.
                    </div>
                    
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <form action="forgot_password.php" method="POST">
                    <div class="mb-4">
                        <label for="email" class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="your@student.aiu.edu.my" required 
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                        </div>
                        <small class="text-muted">Use the same email you used during registration</small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-auth">
                        <i class="bi bi-send me-2"></i> Send Reset Link
                    </button>
                </form>

                <div class="back-to-login">
                    <a href="login.php" class="text-primary text-decoration-none fw-bold">
                        <i class="bi bi-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>
