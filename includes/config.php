<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'ruchi_classes');
define('DB_USER', 'root');
define('DB_PASS', '');

// OTP Configuration
define('OTP_EXPIRY_MINUTES', 5);
define('MAX_OTP_ATTEMPTS', 3);
define('OTP_LENGTH', 6);

// Email Configuration (Gmail SMTP)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');     // Change this
define('SMTP_PASS', 'your-app-password');        // Change this
define('SMTP_FROM', 'noreply@ruchiclasses.com');
define('SMTP_FROM_NAME', 'Ruchi Classes');

// Test Mode (for development)
define('TEST_MODE', true);  // Set false in production
define('TEST_OTP', '123456'); // Fixed OTP for testing

// Paths
define('BASE_PATH', dirname(__DIR__) . '/');
define('UPLOAD_PATH', BASE_PATH . 'uploads/students/');
define('UPLOAD_URL', '/ruchi_classes/uploads/students/');
?>