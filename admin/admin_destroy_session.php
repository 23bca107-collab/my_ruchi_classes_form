<?php
session_start();

// Log the admin logout action
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $admin_name = $_SESSION['admin_name'] ?? 'Unknown Admin';
    $admin_email = $_SESSION['admin_email'] ?? 'Unknown';
    $login_time = $_SESSION['login_time'] ?? time();
    $duration = time() - $login_time;
    
    // Format duration
    $hours = floor($duration / 3600);
    $minutes = floor(($duration % 3600) / 60);
    $seconds = $duration % 60;
    
    // Log to file (optional)
    $log_entry = date('Y-m-d H:i:s') . " | Admin Logout | " . 
                  "Email: $admin_email | " .
                  "Name: $admin_name | " .
                  "Duration: {$hours}h {$minutes}m {$seconds}s | " .
                  "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
    
    // Create logs directory if not exists
    if (!file_exists('../logs')) {
        mkdir('../logs', 0777, true);
    }
    
    // Write to admin logout log
    file_put_contents('../logs/admin_logouts.log', $log_entry, FILE_APPEND);
}

// Clear all admin session variables
$admin_vars = [
    'admin_logged_in', 'admin_id', 'admin_email', 'admin_name',
    'admin_type', 'admin_first_name', 'admin_last_name', 'admin_phone',
    'admin_photo', 'admin_profile_completed', 'admin_csrf_token',
    'admin_csrf_token_time', 'last_activity', 'login_time', 'ip_address',
    'user_agent'
];

foreach ($admin_vars as $var) {
    unset($_SESSION[$var]);
}

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear all admin-specific cookies
$admin_cookies = ['admin_remember', 'admin_session_data'];
foreach ($admin_cookies as $cookie_name) {
    setcookie($cookie_name, '', time() - 3600, '/');
    setcookie($cookie_name, '', time() - 3600);
}

// Clear all cookies (additional security)
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
    foreach($cookies as $cookie) {
        $parts = explode('=', $cookie);
        $name = trim($parts[0]);
        setcookie($name, '', time() - 3600, '/');
        setcookie($name, '', time() - 3600);
    }
}

// Start new session to show confirmation message
session_start();
$_SESSION['logout_message'] = 'Admin logout successful';
$_SESSION['logout_time'] = time();

// Redirect to logout confirmation page
header('Location: admin_logout_success.php');
exit;
?>