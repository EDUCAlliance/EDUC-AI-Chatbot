<?php
/**
 * EDUC AI TalkBot Enhanced - Admin Logout
 */

session_start();

require_once '../vendor/autoload.php';
require_once '../educ-bootstrap.php';

use EDUC\Utils\Logger;

// Log the logout if user was authenticated
if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
    Logger::info('Admin logout', [
        'username' => $_SESSION['admin_username'] ?? 'unknown',
        'session_duration' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : 0
    ]);
}

// Clear all session data
$_SESSION = [];

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>