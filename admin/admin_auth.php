<?php
// ===========================================
// ADMIN AUTHENTICATION FUNCTIONS
// ===========================================

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
}

// Function to check if admin is authenticated
function isAdminAuthenticated() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout (8 hours)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 28800)) {
        return false;
    }
    
    // Check required session variables
    if (empty($_SESSION['admin_email']) || empty($_SESSION['admin_id'])) {
        return false;
    }
    
    return true;
}

// Function to redirect to login if not authenticated
function requireAdminAuth() {
    if (!isAdminAuthenticated()) {
        // Clear session
        session_unset();
        session_destroy();
        
        // Redirect to login
        header("Location: admin_login.php");
        exit;
    }
}

// Function to get admin info from session and database
function getAdminInfo() {
    requireAdminAuth(); // Ensure admin is logged in
    
    global $conn;
    
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT id, name, email, admin_type, first_name, last_name, phone, photo, profile_completed FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $admin_data = $result->fetch_assoc();
        
        // Update session with latest data
        $_SESSION['admin_name'] = $admin_data['name'];
        $_SESSION['admin_first_name'] = $admin_data['first_name'];
        $_SESSION['admin_last_name'] = $admin_data['last_name'];
        $_SESSION['admin_phone'] = $admin_data['phone'];
        $_SESSION['admin_photo'] = $admin_data['photo'];
        $_SESSION['admin_type'] = $admin_data['admin_type'];
        $_SESSION['profile_completed'] = $admin_data['profile_completed'];
        
        return [
            'id' => $admin_data['id'],
            'name' => $admin_data['name'],
            'email' => $admin_data['email'],
            'type' => $admin_data['admin_type'],
            'first_name' => $admin_data['first_name'],
            'last_name' => $admin_data['last_name'],
            'phone' => $admin_data['phone'],
            'photo' => $admin_data['photo'],
            'profile_completed' => $admin_data['profile_completed']
        ];
    }
    
    // If admin not found in database, logout
    session_unset();
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// Function to generate CSRF token
function generateAdminCSRFToken() {
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf_token'];
}

// Function to validate CSRF token
function validateAdminCSRFToken($token) {
    if (empty($_SESSION['admin_csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['admin_csrf_token'], $token);
}

// Function to check admin permissions
function checkAdminPermission($permission) {
    $admin_type = $_SESSION['admin_type'] ?? 'second_admin';
    
    // First admin has all permissions
    if ($admin_type === 'first_admin') {
        return true;
    }
    
    // Define permissions for second admin
    $second_admin_permissions = [
        'manage_users',
        'view_reports',
        'manage_students',
        'view_dashboard'
    ];
    
    return in_array($permission, $second_admin_permissions);
}

// Function to log admin activity
function logAdminActivity($action, $details = '') {
    global $conn;
    
    if (!isset($_SESSION['admin_id'])) {
        return;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Check if admin_activity table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'admin_activity'");
    
    if ($table_check && $table_check->num_rows == 0) {
        // Create table if not exists
        $create_table = $conn->query("CREATE TABLE IF NOT EXISTS admin_activity (
            id INT PRIMARY KEY AUTO_INCREMENT,
            admin_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_timestamp (timestamp)
        )");
    }
    
    // Insert activity log
    $stmt = $conn->prepare("INSERT INTO admin_activity (admin_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $admin_id, $action, $details, $ip_address, $user_agent);
    $stmt->execute();
}

// Function to log security events
function logSecurityEvent($event_type, $details) {
    global $conn;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $admin_id = $_SESSION['admin_id'] ?? 0;
    
    // Check if security_logs table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'security_logs'");
    
    if ($table_check && $table_check->num_rows == 0) {
        // Create table if not exists
        $create_table = $conn->query("CREATE TABLE IF NOT EXISTS security_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            admin_id INT,
            event_type VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_created_at (created_at)
        )");
    }
    
    // Insert security log
    $stmt = $conn->prepare("INSERT INTO security_logs (admin_id, event_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $admin_id, $event_type, $details, $ip_address, $user_agent);
    $stmt->execute();
}

// Function to update admin last activity
function updateAdminLastActivity() {
    if (isset($_SESSION['admin_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

// Initialize or extend session
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    updateAdminLastActivity();
}
?>