<?php
// logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log the logout
$teacher_id = $_SESSION['teacher_id'] ?? 'UNKNOWN';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
error_log("TEACHER LOGOUT: Teacher ID $teacher_id from IP $ip");

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if (!$conn->connect_error && isset($_SESSION['teacher_id'])) {
    require_once 'security_functions.php';
    
    $log_stmt = $conn->prepare("INSERT INTO login_audit (user_id, user_type, ip_address, user_agent, action, status) VALUES (?, 'teacher', ?, ?, 'logout', 'success')");
    $log_stmt->bind_param("iss", $_SESSION['teacher_id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN');
    $log_stmt->execute();
    $log_stmt->close();
}

// Destroy session
session_unset();
session_destroy();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login
header('Location: teacher_login.php?logout=success');
exit;
?>