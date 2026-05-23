<?php
session_start();
require_once 'config/security.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!validateSession()) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

// Extend session by updating login time
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();

// Update session cookie
session_regenerate_id(true);

// Log the extension
logSecurityEvent('SESSION_EXTENDED', 'User extended session');

echo json_encode(['success' => true, 'message' => 'Session extended']);
?>