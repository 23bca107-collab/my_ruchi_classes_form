<?php
session_start();

// Set JSON header
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Credentials: true');

// Only allow from same origin for security
$allowed_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
header('Access-Control-Allow-Origin: ' . $allowed_origin);

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid admin session',
        'redirect' => 'admin_login_form.php'
    ]);
    exit;
}

// Validate session data
$required_vars = ['admin_id', 'admin_email', 'admin_name', 'login_time'];
foreach ($required_vars as $var) {
    if (empty($_SESSION[$var])) {
        echo json_encode([
            'success' => false, 
            'message' => 'Session data corrupted',
            'redirect' => 'admin_login_form.php'
        ]);
        exit;
    }
}

// Check if session is too old to extend (max 8 hours)
$max_session_age = 8 * 3600; // 8 hours in seconds
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $max_session_age)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Session expired. Please login again.',
        'redirect' => 'admin_login_form.php'
    ]);
    exit;
}

// Extend admin session by updating activity timestamps
$_SESSION['last_activity'] = time();

// Only extend login_time if within reasonable limits (prevent infinite sessions)
$current_session_age = time() - $_SESSION['login_time'];
if ($current_session_age < 6 * 3600) { // Only extend if less than 6 hours old
    $_SESSION['login_time'] = time();
}

// Update session cookie with extended lifetime
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    $new_lifetime = 1800; // 30 minutes
    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => time() + $new_lifetime,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Strict'
        ]
    );
}

// Regenerate session ID for security
session_regenerate_id(true);

// Log the extension (optional)
$log_entry = date('Y-m-d H:i:s') . " | Session Extended | " . 
              "Admin: " . ($_SESSION['admin_email'] ?? 'Unknown') . " | " .
              "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";

if (!file_exists('../logs')) {
    mkdir('../logs', 0777, true);
}
file_put_contents('../logs/admin_sessions.log', $log_entry, FILE_APPEND);

// Calculate new expiry time for client-side display
$expires_in = 1800; // 30 minutes
$new_expiry = time() + $expires_in;

echo json_encode([
    'success' => true, 
    'message' => 'Admin session extended',
    'session_age' => $current_session_age,
    'new_expiry' => $new_expiry,
    'expires_in_minutes' => floor($expires_in / 60),
    'admin_name' => $_SESSION['admin_name'] ?? 'Administrator',
    'admin_type' => $_SESSION['admin_type'] ?? 'Admin'
]);
exit;
?>