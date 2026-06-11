<?php
$siteSettingsPath = __DIR__ . '/includes/site_settings.php';
if (is_file($siteSettingsPath)) {
    require_once $siteSettingsPath;
}

$host = "localhost";
$user = "root";  // XAMPP default
$pass = "";      // XAMPP default (blank password)
$db   = "ruchi_classes"; // apna database name yahan likho

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

if (function_exists('site_settings_start_favicon_buffer')) {
    site_settings_start_favicon_buffer($conn);
}
?>
