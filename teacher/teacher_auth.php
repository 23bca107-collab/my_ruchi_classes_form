<?php
// ===========================================
// TEACHER AUTHENTICATION FUNCTIONS
// ===========================================

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to check if teacher is authenticated
function isTeacherAuthenticated() {
    if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout (8 hours)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 28800)) {
        return false;
    }
    
    // Check required session variables
    if (empty($_SESSION['teacher_id']) || empty($_SESSION['teacher_email'])) {
        return false;
    }
    
    return true;
}

// Function to redirect to login if not authenticated
function requireTeacherAuth() {
    if (!isTeacherAuthenticated()) {
        // Log unauthorized access attempt
        logTeacherSecurityEvent('UNAUTHORIZED_ACCESS', 'Attempted to access protected page');
        
        // Clear session
        session_unset();
        session_destroy();
        
        // Redirect to login
        header("Location: teacher_login.php");
        exit;
    }
}

// Function to get teacher info from session and database
function getTeacherInfo() {
    requireTeacherAuth(); // Ensure teacher is logged in
    
    global $conn;
    
    $teacher_id = $_SESSION['teacher_id'];
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $teacher_data = $result->fetch_assoc();
        
        // Update session with latest data
        $_SESSION['teacher_first_name'] = $teacher_data['first_name'];
        $_SESSION['teacher_last_name'] = $teacher_data['last_name'];
        $_SESSION['teacher_name'] = trim($teacher_data['first_name'] . ' ' . $teacher_data['last_name']);
        $_SESSION['teacher_mobile'] = $teacher_data['mobile'];
        $_SESSION['teacher_photo'] = $teacher_data['photo'];
        $_SESSION['teacher_subject'] = $teacher_data['subject'];
        $_SESSION['profile_completed'] = $teacher_data['profile_completed'];
        
        return $teacher_data;
    }
    
    // If teacher not found in database, logout
    session_unset();
    session_destroy();
    header("Location: teacher_login.php");
    exit;
}

// Function to generate CSRF token
function generateTeacherCSRFToken() {
    if (empty($_SESSION['teacher_csrf_token'])) {
        $_SESSION['teacher_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['teacher_csrf_token'];
}

// Function to validate CSRF token
function validateTeacherCSRFToken($token) {
    if (empty($_SESSION['teacher_csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['teacher_csrf_token'], $token);
}

// Function to log teacher activity
function logTeacherActivity($action, $details = '') {
    global $conn;
    
    if (!isset($_SESSION['teacher_id'])) {
        return;
    }
    
    $teacher_id = $_SESSION['teacher_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Check if teacher_activity table exists
    $conn->query("CREATE TABLE IF NOT EXISTS teacher_activity (
        id INT PRIMARY KEY AUTO_INCREMENT,
        teacher_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_teacher_id (teacher_id),
        INDEX idx_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Insert activity log
    $stmt = $conn->prepare("INSERT INTO teacher_activity (teacher_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $teacher_id, $action, $details, $ip_address, $user_agent);
    $stmt->execute();
}

// Function to log security events
function logTeacherSecurityEvent($event_type, $details) {
    global $conn;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $teacher_id = $_SESSION['teacher_id'] ?? 0;
    
    // Check if teacher_security_logs table exists
    $conn->query("CREATE TABLE IF NOT EXISTS teacher_security_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        teacher_id INT,
        event_type VARCHAR(100) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_type (event_type),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Insert security log
    $stmt = $conn->prepare("INSERT INTO teacher_security_logs (teacher_id, event_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $teacher_id, $event_type, $details, $ip_address, $user_agent);
    $stmt->execute();
}

// Function to update teacher last activity
function updateTeacherLastActivity() {
    if (isset($_SESSION['teacher_id'])) {
        $_SESSION['last_activity'] = time();
        
        // Update database
        global $conn;
        $stmt = $conn->prepare("UPDATE teachers SET last_activity = NOW() WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['teacher_id']);
        $stmt->execute();
    }
}

// Function to check if profile is complete
function isTeacherProfileComplete() {
    global $conn;
    
    if (!isset($_SESSION['teacher_id'])) {
        return false;
    }
    
    $stmt = $conn->prepare("SELECT profile_completed FROM teachers WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['teacher_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    return ($data && $data['profile_completed'] == 1);
}

// Function to get user IP
function getTeacherUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// Initialize or extend session
if (isset($_SESSION['teacher_logged_in']) && $_SESSION['teacher_logged_in'] === true) {
    updateTeacherLastActivity();
}
?>