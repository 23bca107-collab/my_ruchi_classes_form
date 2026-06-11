<?php
session_start();

// Log logout activity
if (isset($_SESSION['admin_id'])) {
    require_once 'admin_auth.php';
    logAdminActivity('LOGOUT', 'Admin logged out');
}

// Destroy all session data
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear any remaining cookies
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
    foreach($cookies as $cookie) {
        $parts = explode('=', $cookie);
        $name = trim($parts[0]);
        setcookie($name, '', time() - 3600, '/');
        setcookie($name, '', time() - 3600);
    }
}

// Show SweetAlert logout confirmation and redirect
require_once __DIR__ . '/../includes/site_settings.php';
site_settings_start_favicon_buffer(null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout | Admin Panel</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a2980 0%, #26d0ce 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
    </style>
</head>
<body>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'Logged Out Successfully!',
            text: 'You have been successfully logged out from your admin account.',
            html: `
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-sign-out-alt" style="font-size: 60px; color: #27ae60; margin-bottom: 20px; display: block;"></i>
                    <h3 style="color: #2c3e50; margin: 15px 0;">See you soon!</h3>
                    <p style="color: #7f8c8d;">You have been successfully logged out from your admin account.</p>
                </div>
            `,
            confirmButtonText: 'Go to Login',
            confirmButtonColor: '#27ae60',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                // Auto-redirect after 3 seconds
                setTimeout(() => {
                    Swal.clickConfirm();
                }, 3000);
            }
        }).then(() => {
            window.location.href = 'admin_login.php';
        });
    </script>
</body>
</html>
