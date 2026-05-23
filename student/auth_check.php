<?php
session_start();

function checkStudentAuth() {
    // Check if student is logged in
    if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout (2 hours)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 7200)) {
        return false;
    }
    
    // Check required session variables
    if (empty($_SESSION['student_email']) || empty($_SESSION['student_id'])) {
        return false;
    }
    
    return true;
}

// If not authenticated, show alert and redirect
if (!checkStudentAuth()) {
    // Clear session
    session_unset();
    session_destroy();
    
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body style="background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0;">
        <script>
            Swal.fire({
                icon: "error",
                title: "Access Denied",
                text: "Please login first to access the dashboard",
                confirmButtonText: "Go to Login",
                allowOutsideClick: false,
                allowEscapeKey: false,
                background: "#fff",
                color: "#333"
            }).then(() => {
                window.location.href = "index.html";
            });
        </script>
    </body>
    </html>';
    exit;
}

// Update last activity
$_SESSION['last_activity'] = time();
?>