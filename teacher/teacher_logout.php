<?php
session_start();

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log logout activity
if (isset($_SESSION['teacher_id'])) {
    $teacher_id = $_SESSION['teacher_id'];
    $teacher_name = $_SESSION['teacher_name'] ?? 'Teacher';
    $teacher_email = $_SESSION['teacher_email'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $login_time = $_SESSION['login_time'] ?? time();
    $duration = time() - $login_time;
    
    // Format duration
    $hours = floor($duration / 3600);
    $minutes = floor(($duration % 3600) / 60);
    $seconds = $duration % 60;
    $duration_str = "{$hours}h {$minutes}m {$seconds}s";
    
    // Check if teacher_audit table exists
    $conn->query("CREATE TABLE IF NOT EXISTS teacher_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT,
        email VARCHAR(100),
        teacher_name VARCHAR(100),
        ip_address VARCHAR(45),
        action ENUM('login', 'logout', 'failed_attempt', 'timeout', 'password_change', 'profile_update') DEFAULT 'login',
        timestamp DATETIME,
        user_agent TEXT,
        session_duration INT NULL,
        details TEXT,
        INDEX (teacher_id),
        INDEX (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Log to teacher_audit
    $audit = $conn->prepare("INSERT INTO teacher_audit (teacher_id, email, teacher_name, ip_address, action, timestamp, user_agent, session_duration) VALUES (?, ?, ?, ?, 'logout', NOW(), ?, ?)");
    $audit->bind_param("issssi", $teacher_id, $teacher_email, $teacher_name, $ip_address, $user_agent, $duration);
    $audit->execute();
    
    // Clear session token and update last_activity
    $update = $conn->prepare("UPDATE teachers SET session_token = NULL, last_activity = NULL WHERE id = ?");
    $update->bind_param("i", $teacher_id);
    $update->execute();
    
    // Check if teacher_sessions table exists
    $conn->query("CREATE TABLE IF NOT EXISTS teacher_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        session_token VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        login_time DATETIME,
        last_activity DATETIME,
        is_active TINYINT(1) DEFAULT 1,
        INDEX (teacher_id),
        INDEX (session_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Deactivate session in teacher_sessions
    if (isset($_SESSION['session_token'])) {
        $deactivate = $conn->prepare("UPDATE teacher_sessions SET is_active = 0 WHERE teacher_id = ? AND session_token = ?");
        $deactivate->bind_param("is", $teacher_id, $_SESSION['session_token']);
        $deactivate->execute();
    }
    
    // Log to file
    $log_entry = date('Y-m-d H:i:s') . " | Teacher Logout | " . 
                  "Email: $teacher_email | " .
                  "Name: $teacher_name | " .
                  "Duration: $duration_str | " .
                  "IP: $ip_address\n";
    
    if (!file_exists('../logs')) {
        mkdir('../logs', 0777, true);
    }
    file_put_contents('../logs/teacher_logouts.log', $log_entry, FILE_APPEND);
}

// Clear all teacher session variables
$teacher_vars = [
    'teacher_logged_in', 'teacher_id', 'teacher_email', 'teacher_name',
    'teacher_first_name', 'teacher_last_name', 'teacher_mobile',
    'teacher_photo', 'teacher_subject', 'teacher_csrf_token',
    'session_token', 'last_activity', 'login_time', 'ip_address',
    'user_agent', 'profile_completed'
];

foreach ($teacher_vars as $var) {
    unset($_SESSION[$var]);
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear all teacher-related cookies
setcookie('teacher_remember', '', time() - 3600, '/');
setcookie('teacher_session', '', time() - 3600, '/');

// Start new session to show confirmation
session_start();
$_SESSION['logout_message'] = 'Teacher logout successful';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Logout - Ruchi Classes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .logout-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logout-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #2c3e50, #3498db);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.7); }
            70% { box-shadow: 0 0 0 20px rgba(52, 152, 219, 0); }
            100% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0); }
        }
        .logout-icon i {
            font-size: 48px;
            color: white;
        }
        h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 28px;
        }
        p {
            color: #666;
            margin-bottom: 25px;
            font-size: 16px;
        }
        .session-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-left: 4px solid #3498db;
        }
        .session-info p {
            margin: 8px 0;
            color: #555;
            font-size: 14px;
        }
        .session-info i {
            width: 20px;
            color: #3498db;
            margin-right: 8px;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            margin: 25px 0 15px;
            overflow: hidden;
        }
        .progress-fill {
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, #2c3e50, #3498db);
            animation: progress 5s linear forwards;
        }
        @keyframes progress {
            from { width: 100%; }
            to { width: 0%; }
        }
        .redirect-message {
            color: #777;
            font-size: 14px;
            margin-top: 15px;
        }
        .redirect-message i {
            color: #3498db;
            margin-right: 5px;
        }
        .footer {
            margin-top: 25px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-icon">
            <i class="fas fa-chalkboard-teacher"></i>
        </div>
        
        <h2>Teacher Logout Successful</h2>
        <p>You have been securely logged out from your account.</p>
        
        <div class="session-info">
            <p><i class="fas fa-clock"></i> Session Duration: <?php echo isset($duration_str) ? $duration_str : 'N/A'; ?></p>
            <p><i class="fas fa-calendar"></i> Logout Time: <?php echo date('h:i:s A'); ?></p>
            <p><i class="fas fa-calendar-alt"></i> Date: <?php echo date('d M, Y'); ?></p>
            <p><i class="fas fa-globe"></i> IP Address: <?php echo $_SERVER['REMOTE_ADDR']; ?></p>
        </div>
        
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        
        <div class="redirect-message">
            <i class="fas fa-spinner fa-pulse"></i> Redirecting to login page in 5 seconds...
        </div>
        
        <div class="footer">
            <i class="fas fa-shield-alt"></i> Secure Logout • Session Terminated
        </div>
    </div>

    <script>
        // Auto redirect after 5 seconds
        setTimeout(function() {
            window.location.href = 'teacher_login.php';
        }, 5000);
    </script>
</body>
</html>