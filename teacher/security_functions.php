<?php
/**
 * Security Functions for Teacher Portal
 */

/**
 * Get client IP address
 */
function getClientIP() {
    $ip_keys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR', 
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($ip_keys as $key) {
        if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            
            if (filter_var($ip, FILTER_VALIDATE_IP, 
                FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | 
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check rate limiting
 */
function isRateLimited($conn, $ip_address, $endpoint) {
    // Clean old entries first
    $conn->query("DELETE FROM rate_limits WHERE last_attempt < NOW() - INTERVAL 1 HOUR");
    
    $stmt = $conn->prepare("SELECT attempts, lockout_until FROM rate_limits WHERE ip_address = ? AND endpoint = ?");
    $stmt->bind_param("ss", $ip_address, $endpoint);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $data = $result->fetch_assoc();
        
        // Check if locked out
        if ($data['lockout_until'] && strtotime($data['lockout_until']) > time()) {
            return true;
        }
        
        // Update attempts
        $new_attempts = $data['attempts'] + 1;
        
        if ($new_attempts >= 5) {
            $lockout_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $update_stmt = $conn->prepare("UPDATE rate_limits SET attempts = ?, lockout_until = ?, last_attempt = NOW() WHERE ip_address = ? AND endpoint = ?");
            $update_stmt->bind_param("isss", $new_attempts, $lockout_until, $ip_address, $endpoint);
        } else {
            $update_stmt = $conn->prepare("UPDATE rate_limits SET attempts = ?, last_attempt = NOW() WHERE ip_address = ? AND endpoint = ?");
            $update_stmt->bind_param("iss", $new_attempts, $ip_address, $endpoint);
        }
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // First attempt
        $stmt = $conn->prepare("INSERT INTO rate_limits (ip_address, endpoint, attempts, first_attempt, last_attempt) VALUES (?, ?, 1, NOW(), NOW())");
        $stmt->bind_param("ss", $ip_address, $endpoint);
        $stmt->execute();
        $stmt->close();
    }
    
    return false;
}

/**
 * Reset rate limit
 */
function resetRateLimit($conn, $ip_address, $endpoint) {
    $stmt = $conn->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND endpoint = ?");
    $stmt->bind_param("ss", $ip_address, $endpoint);
    $stmt->execute();
    $stmt->close();
}

/**
 * Log security event
 */
function logSecurityEvent($conn, $ip_address, $event_type, $details = '', $severity = 'medium') {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $conn->prepare("INSERT INTO security_events (event_type, severity, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $event_type, $severity, $ip_address, $user_agent, $details);
    $stmt->execute();
    $stmt->close();
}

/**
 * Record failed login attempt
 */
function recordFailedAttempt($conn, $ip_address, $email) {
    logSecurityEvent($conn, $ip_address, 'LOGIN_FAILURE', "Failed login attempt for email: $email", 'medium');
}

/**
 * Create security tables if they don't exist
 */
function createSecurityTables($conn) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS security_events (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_ip_address (ip_address),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        
        "CREATE TABLE IF NOT EXISTS login_audit (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            user_type ENUM('teacher', 'student', 'admin') NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            action VARCHAR(50) NOT NULL,
            status ENUM('success', 'failure', 'blocked') NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_ip_address (ip_address),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        
        "CREATE TABLE IF NOT EXISTS rate_limits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            attempts INT DEFAULT 0,
            first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            lockout_until TIMESTAMP NULL,
            INDEX idx_ip_endpoint (ip_address, endpoint),
            INDEX idx_lockout (lockout_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];
    
    foreach ($tables as $sql) {
        if (!$conn->query($sql)) {
            error_log("Failed to create table: " . $conn->error);
        }
    }
}
?>