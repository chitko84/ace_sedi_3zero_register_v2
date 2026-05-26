<?php
include 'includes/db.php';
session_start();

$token = $_GET['token'] ?? '';

if (!$token) {
    $_SESSION['error'] = "Invalid or missing token.";
    header("Location: login.php");
    exit();
}

// Check if the token is valid and not expired in users table
$stmt = $conn->prepare("SELECT id, email, name, reset_token_expiry FROM users WHERE reset_token = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    $_SESSION['error'] = "This reset link is invalid or has expired.";
    header("Location: forgot_password.php");
    exit();
}

// Check if token has expired
if (strtotime($user['reset_token_expiry']) < time()) {
    $_SESSION['error'] = "This reset link has expired. Please request a new one.";
    
    // Clear the expired token
    $clear_stmt = $conn->prepare("UPDATE users SET reset_token = NULL, reset_token_expiry = NULL WHERE reset_token = ?");
    $clear_stmt->bind_param("s", $token);
    $clear_stmt->execute();
    
    header("Location: forgot_password.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters.";
    } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/', $new_password)) {
        $_SESSION['error'] = "Password must contain letters, numbers, and special characters.";
    } else {
        // Hash the new password
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        // Update the user's password and clear reset token
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE reset_token = ?");
        $update->bind_param("ss", $hashed, $token);
        
        if ($update->execute()) {
            // Send confirmation email
            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
            $login_link = $base_url . "/login.php";
            
            $subject = "Password Reset Successful - 3ZERO Club";
            $message = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                    .button { display: inline-block; padding: 12px 30px; background: #1a5276; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                    .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>3ZERO Club</h2>
                        <p>Password Reset Successful</p>
                    </div>
                    <div class='content'>
                        <h3>Hello " . htmlspecialchars($user['name']) . ",</h3>
                        <p>Your 3ZERO Club account password has been successfully reset.</p>
                        <p>If you did not make this change, please contact support immediately.</p>
                        <p>You can now login with your new password:</p>
                        <p style='text-align: center;'>
                            <a href='" . $login_link . "' class='button'>Login to 3ZERO Club</a>
                        </p>
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
            
            mail($user['email'], $subject, $message, $headers);
            
            $_SESSION['success'] = "Your password has been reset successfully! You can now log in.";
            header("Location: login.php");
            exit();
        } else {
            $_SESSION['error'] = "Error resetting password. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - 3ZERO Club</title>
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

    .password-strength {
        height: 4px;
        background-color: #e0e0e0;
        border-radius: 2px;
        margin-top: 0.5rem;
        overflow: hidden;
    }

    .password-strength-bar {
        height: 100%;
        width: 0%;
        transition: width 0.3s ease, background-color 0.3s ease;
    }

    @media (max-width: 576px) {
        .auth-container {
            padding: 2rem 1.5rem;
            margin: 1rem auto;
        }
        
        body {
            padding-top: 70px;
        }
    }

    .info-box {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        border: 2px solid #2196f3;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
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
    </style>
</head>

<body>
    <?php include('header.php'); ?>
    
    <div class="container py-4">
        <div class="auth-container">
            <div class="auth-header">
                <h2 class="auth-title">Reset Password</h2>
                <p class="auth-subtitle">Enter a new password for your account</p>
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="info-box">
                <div class="info-box-header">
                    <i class="bi bi-shield-lock info-box-icon"></i>
                    <h3 class="info-box-title">Password Requirements</h3>
                </div>
                <p class="info-box-content">
                    Your new password must be at least 8 characters long and include letters, numbers, and special characters.
                </p>
            </div>

            <form method="POST">
                <div class="mb-3">
                    <label for="password" class="form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required minlength="8" id="password" placeholder="Enter new password">
                        <span class="input-group-text" id="togglePassword" style="cursor: pointer;">
                            <i class="bi bi-eye-slash-fill"></i>
                        </span>
                    </div>
                    <div class="password-strength mt-2">
                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                    </div>
                    <small class="form-text text-muted">Minimum 8 characters with letters, numbers, and special characters</small>
                </div>
                
                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8" id="confirm_password" placeholder="Confirm new password">
                        <span class="input-group-text" id="toggleConfirmPassword" style="cursor: pointer;">
                            <i class="bi bi-eye-slash-fill"></i>
                        </span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-auth">
                    <i class="bi bi-key me-2"></i> Reset Password
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="login.php" class="text-primary text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> Back to Login
                </a>
            </div>
        </div>
    </div>

    <?php include('footer.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            if (password.type === 'password') {
                password.type = 'text';
                icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
            } else {
                password.type = 'password';
                icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
            }
        });

        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const confirmPassword = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            if (confirmPassword.type === 'password') {
                confirmPassword.type = 'text';
                icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
            } else {
                confirmPassword.type = 'password';
                icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
            }
        });

        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrengthBar');
            let strength = 0;
            
            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 25;
            if (/[A-Z]/.test(password)) strength += 15;
            if (/[0-9]/.test(password)) strength += 15;
            if (/[^A-Za-z0-9]/.test(password)) strength += 20;
            
            strengthBar.style.width = Math.min(strength, 100) + '%';
            
            if (strength < 50) {
                strengthBar.style.backgroundColor = '#dc3545';
            } else if (strength < 75) {
                strengthBar.style.backgroundColor = '#fd7e14';
            } else {
                strengthBar.style.backgroundColor = '#28a745';
            }
        });

        // Auto-focus on password field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('password').focus();
        });
    </script>
</body>
</html>
