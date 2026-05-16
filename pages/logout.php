<?php
require_once "../includes/auth.php";
require_once "../config/db.php";
require_once "../includes/functions.php";
start_session();
session_security_check();

// Log the logout event before the session is destroyed
if (isset($_SESSION['user_id'])) {
    $ip_logout = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    log_user_event(
        $conn,
        'logout',
        $ip_logout,
        "User '{$_SESSION['email']}' logged out",
        (int)$_SESSION['user_id']
    );
}

logout_user();
header("Location: portal-login.php");
exit();
?>
