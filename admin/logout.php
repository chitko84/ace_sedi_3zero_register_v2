<?php
// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Wipe all session data
$_SESSION = [];

// Delete the session cookie (if any)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy current session
session_destroy();

// Start a fresh session for flash message
session_start();
$_SESSION['success'] = 'You have been logged out successfully.';

// Redirect to login
header('Location: ../login.php');
exit();
