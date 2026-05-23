<?php
session_start();

// Set security headers
header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN'] ?? '');
header("Access-Control-Allow-Credentials: true");

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Return token as JSON
echo json_encode([
    'csrf_token' => $_SESSION['csrf_token']
]);
?>