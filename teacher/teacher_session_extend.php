<?php
session_start();

// Set JSON header
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Credentials: true');

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Only allow from same origin for security
$allowed_origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
header('Access-Control-Allow-Origin: ' . $allowed_origin);

// Check if teacher is logged in
if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid teacher session',
        'redirect' => 'teacher_login.php'
    ]);
    exit;
}

// Validate session data
$required_vars = ['teacher_id', 'teacher_email', 'teacher_name', 'login_time'];
foreach ($required_vars as $var) {
    if (empty($_SESSION[$var])) {
        echo json_encode([
            'success' => false, 
            'message' => 'Session data corrupted',
            'redirect' => 'teacher_login.php'
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
        'redirect' => 'teacher_login.php'
    ]);
    exit;
}

// Extend teacher session by updating activity timestamps
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

// Update last_activity in database
$update = $conn->prepare("UPDATE teachers SET last_activity = NOW() WHERE id = ?");
$update->bind_param("i", $_SESSION['teacher_id']);
$update->execute();

// Update teacher_sessions
$session_update = $conn->prepare("UPDATE teacher_sessions SET last_activity = NOW() WHERE teacher_id = ? AND session_token = ? AND is_active = 1");
$session_update->bind_param("is", $_SESSION['teacher_id'], $_SESSION['session_token']);
$session_update->execute();

// Regenerate session ID for security (every 30 minutes)
if (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration'] > 1800)) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Calculate new expiry time for client-side display
$expires_in = 1800; // 30 minutes
$new_expiry = time() + $expires_in;

echo json_encode([
    'success' => true, 
    'message' => 'Teacher session extended',
    'session_age' => $current_session_age,
    'new_expiry' => $new_expiry,
    'expires_in_minutes' => floor($expires_in / 60),
    'teacher_name' => $_SESSION['teacher_name'] ?? 'Teacher',
    'teacher_subject' => $_SESSION['teacher_subject'] ?? ''
]);
exit;
?>