<?php
session_start();
header('Content-Type: text/plain');

echo "=== SESSION DEBUG INFO ===\n\n";

echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "Session Name: " . session_name() . "\n";

echo "\n=== SESSION CONTENTS ===\n";
echo "Session data: " . json_encode($_SESSION, JSON_PRETTY_PRINT) . "\n";

echo "\n=== COOKIES ===\n";
echo "Cookies: " . json_encode($_COOKIE, JSON_PRETTY_PRINT) . "\n";

echo "\n=== SERVER INFO ===\n";
echo "PHP_SESSION_NAME: " . session_name() . "\n";
echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'NOT SET') . "\n";
echo "HTTP_USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'NOT SET') . "\n";

echo "\n=== CHECKING REQUIRED SESSION VARS ===\n";
$required = ['teacher_logged_in', 'teacher_id', 'teacher_email', 'teacher_name', 'login_time'];
foreach ($required as $var) {
    echo "$var: " . (isset($_SESSION[$var]) ? "SET = " . $_SESSION[$var] : "NOT SET") . "\n";
}
?>