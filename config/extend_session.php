<?php
session_start();
require_once 'config/security.php';

// Check if user is logged in
if (!validateSession()) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

// Extend session by updating login time
$_SESSION['login_time'] = time();
$_SESSION['last_regeneration'] = time();

// Update session cookie
session_regenerate_id(true);

echo json_encode(['success' => true, 'message' => 'Session extended']);
?>