<?php
session_start();

// Log logout action
$student_email = $_SESSION['student_email'] ?? 'Unknown';
error_log("Student logged out: " . $student_email);

// Destroy session
session_unset();
session_destroy();

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirect directly without message
header("Location: student_login.html");
exit;
?>