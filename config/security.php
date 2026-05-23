<?php
// Security Configuration File

// Enable error reporting only in development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 in development
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

// Session security settings
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS'])); // Set based on connection
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 7200); // 2 hours

// Security constants
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 300); // 5 minutes in seconds
define('SESSION_TIMEOUT', 7200); // 2 hours

// Function to validate session
function validateSession() {
    // Check if session exists
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_TIMEOUT)) {
        return false;
    }
    
    // Check user agent (prevent session hijacking)
    if (!isset($_SESSION['user_agent']) || $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        return false;
    }
    
    // Check IP address (optional - can be strict)
    if (!isset($_SESSION['ip_address']) || $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
        // For dynamic IPs, check first 2 octets
        $session_ip_parts = explode('.', $_SESSION['ip_address']);
        $current_ip_parts = explode('.', $_SERVER['REMOTE_ADDR']);
        if ($session_ip_parts[0] !== $current_ip_parts[0] || 
            $session_ip_parts[1] !== $current_ip_parts[1]) {
            return false;
        }
    }
    
    // Check last activity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 900)) { // 15 minutes
        return false;
    }
    
    return true;
}

// Function to destroy session securely
function destroySession() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

// Function to sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Function to generate CSRF token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to verify CSRF token
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// Function to check user role
function checkUserRole($allowed_roles = []) {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    if (empty($allowed_roles)) {
        return true;
    }
    
    return in_array($_SESSION['user_type'], $allowed_roles);
}

// Function to log security events
function logSecurityEvent($event, $details = '') {
    $log_file = '../logs/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user = $_SESSION['student_email'] ?? 'Guest';
    
    $log_entry = "[$timestamp] [$ip] [$user] [$event] $details\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Function to redirect with message
function redirectWithMessage($url, $message, $type = 'error') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit;
}
?>