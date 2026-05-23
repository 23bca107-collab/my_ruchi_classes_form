<?php
// ==================== IRON-CLAD AUTH CHECK ====================
session_start();

// Debug: Check what's in session
// error_log("Session check - student_logged_in: " . (isset($_SESSION['student_logged_in']) ? 'SET' : 'NOT SET'));

// If NOT logged in, DESTROY EVERYTHING and redirect
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    // Log this security breach
    error_log("SECURITY BREACH: Direct dashboard access from IP: " . $_SERVER['REMOTE_ADDR']);
    
    // Nuke the session
    session_unset();
    session_destroy();
    
    // Nuke all cookies
    if (isset($_SERVER['HTTP_COOKIE'])) {
        $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
        foreach($cookies as $cookie) {
            $parts = explode('=', $cookie);
            $name = trim($parts[0]);
            setcookie($name, '', time() - 3600, '/');
        }
    }
    
    // Send HARD redirect with no chance of bypass
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="refresh" content="0;url=login.html">
        <script>
            // Clear localStorage too
            localStorage.clear();
            sessionStorage.clear();
            
            // Force redirect even if meta fails
            setTimeout(function() {
                window.location.replace("login.html");
            }, 100);
        </script>
        <title>Redirecting...</title>
    </head>
    <body>
        <center style="margin-top:100px;">
            <h2 style="color:red;">ACCESS DENIED</h2>
            <p>Redirecting to login page...</p>
        </center>
    </body>
    </html>
    ');
    exit;
}

// ==================== DOUBLE-CHECK SESSION ====================
// Additional checks for session hijacking
$required_session_vars = ['student_id', 'student_email', 'student_name', 'login_time'];

foreach ($required_session_vars as $var) {
    if (!isset($_SESSION[$var]) || empty($_SESSION[$var])) {
        // Session is incomplete - destroy and redirect
        session_unset();
        session_destroy();
        header("Location: login.html?error=session_corrupted");
        exit;
    }
}

// Check session age (max 2 hours)
if (time() - $_SESSION['login_time'] > 7200) {
    session_unset();
    session_destroy();
    header("Location: login.html?error=session_expired");
    exit;
}

// Now we're 100% sure user is authenticated
// Continue with database connection...
require __DIR__ . '/../db.php';
require_once __DIR__ . '/student_notifications_ui.php';

// Rest of your code continues...
$email = $_SESSION['student_email'];

// ---------------- FETCH STUDENT ----------------
$student = null;
try {
    $sql = "SELECT * FROM student_english WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $student = $res->fetch_assoc();
        $student['medium'] = 'English';
    } else {
        $sqlH = "SELECT * FROM student_hindi WHERE email = ? LIMIT 1";
        $stmtH = $conn->prepare($sqlH);
        $stmtH->bind_param('s', $email);
        $stmtH->execute();
        $resH = $stmtH->get_result();
        if ($resH && $resH->num_rows > 0) {
            $student = $resH->fetch_assoc();
            $student['medium'] = 'Hindi';
        }
    }
} catch (Throwable $e) {}

if (!$student) {
    echo "<script>alert('Profile not found. Please complete admission form.'); window.location='../profile_setup.php';</script>";
    exit;
}

// ---------------- PHOTO PATH ----------------
function resolve_photo_path(string $rawPath): string {
    if (!$rawPath) return '../assets/img/avatar-placeholder.png';

    // Case 1: DB stored as "student/uploads/..."
    if (strpos($rawPath, 'student/uploads/') === 0) {
        return '../' . $rawPath;
    }

    // Case 2: DB stored as "uploads/..."
    if (strpos($rawPath, 'uploads/') === 0) {
        return '../student/' . $rawPath;
    }

    return '../assets/img/avatar-placeholder.png';
}
$photoWeb = resolve_photo_path($student['photo'] ?? '');

// ---------------- HTML ESCAPE ----------------
function h(?string $v): string { 
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
}

$student_notifications_data = student_notifications_prepare($conn, $student, 12);

$student_id = $student['id'] ?? '';
$student_name = $student['first_name'] . ' ' . $student['last_name'];
$student_medium = $student['medium'] ?? '';
$class = $student['class'] ?? '';

// ---------------- HANDLE COMPLAINT SUBMISSION ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = 'student';
    $user_id   = $student_id;
    $complaint = trim($_POST['complaint'] ?? '');

    if (!empty($complaint)) {
        // Check if complaints table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'complaints'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO complaints (user_type, user_id, complaint, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("sis", $user_type, $user_id, $complaint);
            if ($stmt->execute()) {
                $msg = "Complaint submitted successfully!";
                $msg_type = "success";
            } else {
                $msg = "Error: " . $conn->error;
                $msg_type = "error";
            }
            $stmt->close();
        } else {
            $msg = "Complaints system is temporarily unavailable.";
            $msg_type = "error";
        }
    } else {
        $msg = "Complaint cannot be empty!";
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Submit Complaint | Ruchi Classes</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
:root {
  --primary: #2563eb;
  --primary-light: #3b82f6;
  --primary-dark: #1d4ed8;
  --secondary: #f8fafc;
  --secondary-light: #f1f5f9;
  --accent: #f59e0b;
  --accent-light: #fbbf24;
  --success: #10b981;
  --warning: #f59e0b;
  --danger: #ef4444;
  --info: #06b6d4;

  --text-primary: #1e293b;
  --text-secondary: #475569;
  --text-muted: #64748b;

  --bg-primary: #ffffff;
  --bg-secondary: #f8fafc;
  --bg-card: #ffffff;
  --bg-hover: #f1f5f9;

  --border: #e2e8f0;
  --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);

  --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
  --gradient-accent: linear-gradient(135deg, var(--accent) 0%, #ea580c 100%);

  --sidebar-bg: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  --main-bg: #ffffff;
  --card-bg: #ffffff;
  --header-bg: rgba(255, 255, 255, 0.9);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: var(--main-bg);
  color: var(--text-primary);
  font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  line-height: 1.6;
  min-height: 100vh;
  overflow-x: hidden;
}

/* ----------------- INTERNET ERROR OVERLAY ----------------- */
.internet-error {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(255, 255, 255, 0.95);
  z-index: 9999;
  display: none;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  padding: 20px;
  backdrop-filter: blur(10px);
}

.internet-error.show {
  display: flex;
  animation: fadeIn 0.3s ease;
}

.error-content {
  background: white;
  border-radius: 20px;
  padding: 40px 30px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
  border: 2px solid var(--danger);
  max-width: 500px;
  width: 90%;
  animation: bounceIn 0.8s ease;
}

.error-icon {
  font-size: 80px;
  color: var(--danger);
  margin-bottom: 20px;
  animation: pulse 2s infinite;
}

.error-title {
  font-size: 28px;
  font-weight: 800;
  color: var(--text-primary);
  margin-bottom: 15px;
}

.error-message {
  color: var(--text-secondary);
  margin-bottom: 30px;
  line-height: 1.6;
  font-size: 16px;
}

.reconnect-btn {
  padding: 14px 32px;
  background: var(--gradient-primary);
  color: white;
  border: none;
  border-radius: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
  font-size: 16px;
}

.reconnect-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 15px 30px rgba(37, 99, 235, 0.3);
}

.reconnect-btn:active {
  transform: translateY(-1px);
}

.dashboard {
  display: flex;
  min-height: 100vh;
}

/* ----------------- SIDEBAR ------------------ */
.sidebar {
  width: 280px;
  background: var(--sidebar-bg);
  padding: 1.5rem 1rem;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: var(--shadow);
  position: fixed;
  height: 100vh;
  overflow-y: auto;
  border-right: 1px solid var(--border);
  z-index: 1000;
}

.sidebar.collapsed {
  width: 85px;
  padding: 1.5rem 0.5rem;
}

.logo-container {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 2rem;
  padding: 0 10px;
  transition: all 0.4s ease;
  height: 90px;
  overflow: hidden;
}

.sidebar.collapsed .logo-container {
  padding: 0 5px;
  justify-content: center;
  gap: 0;
  height: 85px;
  margin-bottom: 1.5rem;
}

.logo-img {
  width: 85px;
  height: 85px;
  border-radius: 16px;
  object-fit: contain;
  background: white;
  padding: 8px;
  border: 4px solid var(--primary);
  box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25);
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  display: block;
  flex-shrink: 0;
}

.sidebar.collapsed .logo-img {
  width: 70px;
  height: 70px;
  border-radius: 14px;
  border-width: 3px;
  padding: 6px;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

.logo-img:hover {
  transform: scale(1.05);
  box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
}

.logo-text {
  font-size: 26px;
  font-weight: 800;
  color: var(--primary);
  line-height: 1.2;
  white-space: nowrap;
  overflow: visible;
  transition: all 0.4s ease;
  min-width: 150px;
}

.logo-text span {
  display: block;
  font-size: 11px;
  font-weight: 500;
  color: var(--text-secondary);
  margin-top: 5px;
  white-space: normal;
  overflow: visible;
  word-break: keep-all;
  max-width: 180px;
}

.sidebar.collapsed .logo-text {
  opacity: 0;
  width: 0;
  height: 0;
  overflow: hidden;
  margin: 0;
  padding: 0;
  font-size: 0;
  min-width: 0;
}

.sidebar.collapsed .logo-text span {
  display: none;
}

.nav-item {
  display: flex;
  align-items: center;
  padding: 16px 18px;
  border-radius: 14px;
  margin-bottom: 10px;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  color: var(--text-secondary);
  position: relative;
  text-decoration: none;
  white-space: nowrap;
}

.nav-item::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  height: 100%;
  width: 4px;
  background: var(--primary);
  transform: scaleY(0);
  transition: 0.3s ease;
  border-radius: 0 4px 4px 0;
}

.nav-item:hover {
  background: var(--bg-hover);
  color: var(--text-primary);
  transform: translateX(5px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.nav-item:hover::before {
  transform: scaleY(1);
}

.nav-item.active {
  background: var(--gradient-primary);
  color: white;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

.nav-item.active::before {
  transform: scaleY(1);
  background: var(--accent-light);
}

.nav-icon {
  margin-right: 16px;
  font-size: 20px;
  width: 28px;
  text-align: center;
  transition: all 0.3s ease;
  flex-shrink: 0;
}

.nav-item:hover .nav-icon {
  transform: scale(1.1);
}

.nav-text {
  font-size: 15px;
  font-weight: 500;
  transition: all 0.4s ease;
  white-space: nowrap;
  overflow: hidden;
}

.sidebar.collapsed .nav-text {
  opacity: 0;
  width: 0;
  height: 0;
  overflow: hidden;
  margin: 0;
  padding: 0;
  font-size: 0;
}

.sidebar.collapsed .nav-item {
  justify-content: center;
  padding: 18px 0;
  margin: 0 5px 10px;
}

.sidebar.collapsed .nav-icon {
  margin-right: 0;
  font-size: 22px;
  width: 30px;
}

.sidebar.collapsed .dropdown-icon {
  display: none;
}

.sidebar.collapsed .dropdown-menu {
  display: none !important;
}

/* ---------------- MOBILE SIDEBAR OVERLAY ---------------- */
.sidebar-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  z-index: 999;
  backdrop-filter: blur(5px);
}

.sidebar-overlay.active {
  display: block;
  animation: fadeIn 0.3s ease;
}

/* ---------------- MAIN CONTENT ----------------- */

.main-content {
  flex: 1;
  margin-left: 280px;
  padding: 2rem;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  background: var(--main-bg);
  position: relative;
  min-height: 100vh;
}

.main-content.expanded {
  margin-left: 85px;
}

.main-content::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 20% 80%, rgba(37, 99, 235, 0.05) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(245, 158, 11, 0.05) 0%, transparent 50%),
    radial-gradient(circle at 40% 40%, rgba(16, 185, 129, 0.03) 0%, transparent 50%);
  pointer-events: none;
}

/* ---------------- HEADER ----------------- */

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
  padding: 1.5rem;
  background: var(--header-bg);
  border-radius: 16px;
  backdrop-filter: blur(10px);
  border: 1px solid var(--border);
  z-index: 1;
  position: relative;
  box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

.toggle-sidebar {
  background: var(--gradient-primary);
  border: none;
  width: 50px;
  height: 50px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  color: white;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

.toggle-sidebar:hover {
  transform: rotate(90deg) scale(1.1);
  box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
}

.toggle-sidebar:active {
  transform: rotate(90deg) scale(0.95);
}

.user-menu {
  display: flex;
  align-items: center;
  gap: 20px;
}

.notifications {
  position: relative;
  padding: 12px;
  border-radius: 12px;
  cursor: pointer;
  transition: 0.3s ease;
  color: var(--text-secondary);
  background: var(--bg-card);
  border: 1px solid var(--border);
}

.notifications:hover {
  background: var(--bg-hover);
  color: var(--text-primary);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.notification-badge {
  position: absolute;
  top: -5px;
  right: -5px;
  background: var(--danger);
  color: white;
  font-size: 11px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  border: 2px solid white;
}

.user-profile {
  display: flex;
  align-items: center;
  gap: 15px;
  cursor: pointer;
  padding: 10px 18px;
  border-radius: 14px;
  transition: 0.3s ease;
  background: var(--bg-card);
  border: 1px solid var(--border);
}

.user-profile:hover {
  background: var(--bg-hover);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.user-avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid var(--primary);
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

/* ---------------- PAGE TITLE ---------------- */

.page-title {
  font-size: 32px;
  font-weight: 800;
  margin-bottom: 1.5rem;
  color: var(--text-primary);
  text-align: center;
  position: relative;
  padding-bottom: 15px;
}

.page-title::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 100px;
  height: 4px;
  background: var(--gradient-primary);
  border-radius: 2px;
}

/* ---------------- CLASS INFO BADGE ---------------- */
.class-info-badge {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  background: var(--gradient-primary);
  color: white;
  padding: 10px 20px;
  border-radius: 50px;
  font-weight: 600;
  margin: 1rem 0 2rem 0;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
  justify-content: center;
  width: fit-content;
  margin-left: auto;
  margin-right: auto;
}

/* ---------------- COMPLAINT CARD ---------------- */
.complaint-container {
  background: var(--card-bg);
  border-radius: 20px;
  padding: 2.5rem;
  margin-bottom: 2.5rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  animation: fadeIn 0.8s ease;
  max-width: 800px;
  margin-left: auto;
  margin-right: auto;
}

.complaint-container:hover {
  transform: translateY(-5px);
  box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.welcome-info {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 2rem;
  padding: 1.5rem;
  background: var(--gradient-primary);
  border-radius: 16px;
  color: white;
}

.welcome-icon {
  font-size: 32px;
}

.welcome-text h4 {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
}

.welcome-text p {
  margin: 5px 0 0 0;
  opacity: 0.9;
  font-size: 14px;
}

/* ---------------- ALERT MESSAGES ---------------- */
.alert {
  padding: 16px 20px;
  border-radius: 12px;
  margin-bottom: 25px;
  display: flex;
  align-items: center;
  animation: slideDown 0.5s ease-out;
  border-left: 5px solid transparent;
}

.alert-success {
  background-color: rgba(16, 185, 129, 0.15);
  color: var(--text-primary);
  border-left-color: var(--success);
}

.alert-error {
  background-color: rgba(239, 68, 68, 0.15);
  color: var(--text-primary);
  border-left-color: var(--danger);
}

.alert-icon {
  margin-right: 15px;
  font-size: 22px;
}

/* ---------------- COMPLAINT FORM ---------------- */
.complaint-form {
  background: var(--bg-secondary);
  border-radius: 16px;
  padding: 2rem;
  border: 2px solid var(--border);
}

.form-group {
  margin-bottom: 25px;
}

.form-label {
  display: block;
  margin-bottom: 12px;
  font-weight: 600;
  color: var(--text-primary);
  font-size: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.form-label i {
  color: var(--primary);
}

.complaint-textarea {
  width: 100%;
  padding: 18px;
  border: 2px solid var(--border);
  border-radius: 12px;
  resize: vertical;
  min-height: 200px;
  font-size: 16px;
  font-family: inherit;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  background: var(--bg-card);
  color: var(--text-primary);
}

.complaint-textarea:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
  background: white;
}

.char-count {
  text-align: right;
  font-size: 14px;
  color: var(--text-muted);
  margin-top: 10px;
  font-weight: 500;
}

.char-count.warning {
  color: var(--warning);
}

.char-count.danger {
  color: var(--danger);
}

.submit-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: var(--gradient-primary);
  color: white;
  border: none;
  padding: 16px 32px;
  border-radius: 12px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  text-align: center;
  width: 100%;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

.submit-btn:hover {
  background: var(--gradient-primary);
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
}

.submit-btn:active {
  transform: translateY(-1px);
}

.submit-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
  transform: none !important;
  box-shadow: none !important;
}

/* ---------------- ACTION LINKS ---------------- */
.action-links {
  display: flex;
  justify-content: space-between;
  margin-top: 30px;
  padding-top: 25px;
  border-top: 1px solid var(--border);
}

.action-link {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  color: var(--primary);
  text-decoration: none;
  font-weight: 500;
  font-size: 15px;
  padding: 10px 20px;
  border-radius: 10px;
  transition: all 0.3s ease;
  background: var(--bg-card);
  border: 1px solid var(--border);
}

.action-link:hover {
  background: var(--gradient-primary);
  color: white;
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
  text-decoration: none;
}

/* ---------------- DROPDOWN ---------------- */
.dropdown {
  position: relative;
  cursor: pointer;
}

.dropdown-icon {
  margin-left: auto;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  font-size: 16px;
  opacity: 0.7;
}

.dropdown-menu {
  display: none;
  flex-direction: column;
  margin-left: 50px;
  margin-top: 10px;
  background: var(--bg-card);
  border-radius: 12px;
  border: 1px solid var(--border);
  overflow: hidden;
  box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.dropdown.open + .dropdown-menu {
  display: flex;
  animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.dropdown.open .dropdown-icon {
  transform: rotate(180deg);
  opacity: 1;
}

.dropdown-item {
  padding: 15px 20px;
  text-decoration: none;
  font-size: 15px;
  margin: 0;
  color: var(--text-secondary);
  transition: all 0.3s ease;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}

.dropdown-item:last-child {
  border-bottom: none;
}

.dropdown-item:hover {
  background: var(--gradient-primary);
  color: white;
  transform: translateX(8px);
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

/* ---------------- ANIMATIONS ---------------- */
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes bounceIn {
  0% { opacity: 0; transform: scale(0.3); }
  50% { opacity: 0.9; transform: scale(1.05); }
  80% { opacity: 1; transform: scale(0.95); }
  100% { opacity: 1; transform: scale(1); }
}

@keyframes pulse {
  0% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.1); opacity: 0.8; }
  100% { transform: scale(1); opacity: 1; }
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-15px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes slideInLeft {
  from { transform: translateX(-100%); }
  to { transform: translateX(0); }
}

@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.05); }
  100% { transform: scale(1); }
}

.pulse {
  animation: pulse 2s infinite;
}

/* ---------------- TOAST NOTIFICATION ---------------- */
.toast {
  position: fixed;
  top: 20px;
  right: 20px;
  padding: 18px 24px;
  background: white;
  border-radius: 14px;
  box-shadow: 0 15px 35px rgba(0,0,0,0.25);
  display: flex;
  align-items: center;
  gap: 15px;
  z-index: 9999;
  transform: translateX(150%);
  transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
  border-left: 5px solid var(--primary);
  max-width: 400px;
  backdrop-filter: blur(10px);
  background: rgba(255, 255, 255, 0.95);
}

.toast.show {
  transform: translateX(0);
}

.toast-success {
  border-left-color: var(--success);
}

.toast-error {
  border-left-color: var(--danger);
}

.toast i {
  font-size: 24px;
}

.toast-success i {
  color: var(--success);
}

.toast-error i {
  color: var(--danger);
}

.toast span {
  font-size: 15px;
  font-weight: 600;
}

/* ---------------- RESPONSIVE DESIGN ---------------- */

/* Tablet */
@media (max-width: 1024px) {
  .sidebar {
    transform: translateX(-100%);
    z-index: 1000;
    box-shadow: 0 0 50px rgba(0, 0, 0, 0.2);
    width: 320px;
  }
  
  .sidebar.active {
    transform: translateX(0);
    animation: slideInLeft 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .main-content {
    margin-left: 0;
    padding: 1.5rem;
  }
  
  .header {
    padding: 1.2rem;
    margin-bottom: 1.5rem;
  }
  
  .page-title {
    font-size: 28px;
  }
  
  .complaint-container {
    padding: 2rem;
  }
  
  .action-links {
    flex-direction: column;
    gap: 15px;
  }
  
  .action-link {
    justify-content: center;
  }
  
  .toast {
    left: 20px;
    right: 20px;
    max-width: calc(100% - 40px);
    transform: translateY(-150%);
    padding: 16px 20px;
  }
  
  .toast.show {
    transform: translateY(0);
  }
}

/* Mobile */
@media (max-width: 768px) {
  .main-content {
    padding: 1rem;
  }
  
  .header {
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 14px;
  }
  
  .page-title {
    font-size: 26px;
  }
  
  .complaint-container {
    padding: 1.5rem;
    border-radius: 14px;
  }
  
  .welcome-info {
    padding: 1rem;
    flex-direction: column;
    text-align: center;
  }
  
  .welcome-icon {
    font-size: 28px;
  }
  
  .complaint-form {
    padding: 1.5rem;
  }
  
  .complaint-textarea {
    min-height: 180px;
    padding: 15px;
  }
  
  .submit-btn {
    padding: 14px 24px;
  }
  
  .logo-img {
    width: 75px;
    height: 75px;
  }
  
  .logo-text {
    font-size: 22px;
  }
  
  .user-menu {
    gap: 15px;
  }
  
  .toggle-sidebar {
    width: 45px;
    height: 45px;
  }
  
  .error-content {
    padding: 30px 20px;
  }
  
  .error-icon {
    font-size: 60px;
  }
  
  .error-title {
    font-size: 24px;
  }
  
  .error-message {
    font-size: 15px;
  }
}

/* Small Mobile */
@media (max-width: 480px) {
  .main-content {
    padding: 0.75rem;
  }
  
  .header {
    padding: 0.75rem;
  }
  
  .toggle-sidebar {
    width: 40px;
    height: 40px;
    font-size: 16px;
  }
  
  .user-avatar {
    width: 40px;
    height: 40px;
  }
  
  .notifications {
    padding: 10px;
  }
  
  .notification-badge {
    width: 18px;
    height: 18px;
    font-size: 10px;
  }
  
  .page-title {
    font-size: 22px;
  }
  
  .complaint-container {
    padding: 1rem;
  }
  
  .welcome-info {
    padding: 0.75rem;
  }
  
  .welcome-text h4 {
    font-size: 18px;
  }
  
  .welcome-text p {
    font-size: 13px;
  }
  
  .complaint-form {
    padding: 1rem;
  }
  
  .complaint-textarea {
    min-height: 150px;
    padding: 12px;
  }
  
  .submit-btn {
    padding: 12px 20px;
    font-size: 15px;
  }
  
  .action-link {
    padding: 8px 16px;
    font-size: 14px;
  }
  
  .error-content {
    padding: 25px 15px;
  }
  
  .error-icon {
    font-size: 50px;
  }
  
  .error-title {
    font-size: 20px;
  }
  
  .error-message {
    font-size: 14px;
  }
  
  .reconnect-btn {
    padding: 12px 24px;
    font-size: 14px;
  }
}

/* Desktop */
@media (min-width: 1025px) {
  .sidebar {
    width: 280px;
  }
  
  .main-content {
    margin-left: 280px;
  }
  
  .sidebar.collapsed {
    width: 85px;
  }
  
  .main-content.expanded {
    margin-left: 85px;
  }
}

/* Print Styles */
@media print {
  .sidebar,
  .toggle-sidebar,
  .notifications,
  .user-profile,
  .internet-error,
  .sidebar-overlay {
    display: none !important;
  }
  
  .main-content {
    margin-left: 0 !important;
    padding: 0 !important;
  }
  
  .complaint-container {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    break-inside: avoid;
  }
  
  body {
    background: white !important;
    color: black !important;
  }
  
  .submit-btn {
    display: none !important;
  }
}
</style>
<?php student_notifications_render_styles(); ?>
</head>
<body>
  <!-- Internet Connection Error Overlay -->
  <div class="internet-error" id="internetError">
    <div class="error-content">
      <div class="error-icon">
        <i class="fas fa-wifi-slash"></i>
      </div>
      <h2 class="error-title">No Internet Connection</h2>
      <p class="error-message">
        Oops! It seems you've lost connection to the internet.<br>
        Please check your network settings and try again.
      </p>
      <button class="reconnect-btn" id="reconnectBtn">
        <i class="fas fa-sync-alt"></i> Reconnect Now
      </button>
    </div>
  </div>

  <!-- Mobile Sidebar Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="dashboard">
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
      <div class="logo-container">
        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo-img" id="logoImg">
        <div class="logo-text" id="logoText">
          Ruchi <br>Classes
          <span>Education for Excellence</span>
        </div>
      </div>
      <a href="dashboard.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-home"></i></div>
        <div class="nav-text">Dashboard</div>
      </a>
      <a href="profile.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-user"></i></div>
        <div class="nav-text">Profile</div>
      </a>
      <a href="subject.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-book"></i></div>
        <div class="nav-text">Courses</div>
      </a>
      
      <a href="attendance.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="nav-text">Attendance</div>
      </a>

      <!-- Exams Dropdown -->
      <div class="nav-item dropdown">
        <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
        <div class="nav-text">Exams</div>
        <i class="fas fa-caret-down dropdown-icon"></i>
      </div>
      <div class="dropdown-menu">
        <a href="student_exams.php" class="dropdown-item">➤ Exam List</a>
        <a href="student_marks.php" class="dropdown-item">➤ Marks</a>
      </div>

      <a href="complain.php" class="nav-item active">
        <div class="nav-icon"><i class="fas fa-comment-dots"></i></div>
        <div class="nav-text">Complaint</div>
      </a>

      <a href="view_schedule.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-calendar-alt"></i></div>
        <div class="nav-text">Time Table</div>
      </a>

      <a href="student_videos.php" class="nav-item">
        <div class="nav-icon">
        <i class="fas fa-play-circle"></i>
    </div>
    <div class="nav-text">Watch Videos</div>
      </a>    
      <a href="../logout.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-sign-out-alt"></i></div>
        <div class="nav-text">Logout</div>
      </a>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
      <!-- Header -->
      <div class="header">
        <button class="toggle-sidebar" id="toggleSidebar">
          <i class="fas fa-bars" id="toggleIcon"></i>
        </button>
        <div class="user-menu">
          <?php student_notifications_render_button($student_notifications_data); ?>
          <div class="user-profile">
            <img src="<?php echo h($photoWeb); ?>" alt="Profile" class="user-avatar"
              onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student_name); ?>&background=1a56db&color=fff'">
            <div class="user-name"><?php echo h($student_name); ?></div>
          </div>
        </div>
      </div>

      <!-- Page Title -->
      <h1 class="page-title">📝 Submit Complaint</h1>

      <!-- Class Info -->
      <div style="text-align: center; margin-bottom: 1rem;">
        <div class="class-info-badge">
          <i class="fas fa-graduation-cap"></i>
          Class <?php echo h($class); ?> | <?php echo ucfirst(h($student_medium)); ?> Medium
        </div>
      </div>

      <!-- Complaint Container -->
      <div class="complaint-container">
        <!-- Welcome Info -->
        <div class="welcome-info">
          <div class="welcome-icon">
            <i class="fas fa-headset"></i>
          </div>
          <div class="welcome-text">
            <h4>Welcome, <?php echo h($student_name); ?></h4>
            <p>We're here to help. Please describe your issue in detail.</p>
          </div>
        </div>

        <?php if (isset($msg)): ?>
          <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>">
            <span class="alert-icon">
              <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            </span>
            <?php echo h($msg); ?>
          </div>
        <?php endif; ?>
        
        <!-- Complaint Form -->
        <form method="POST" id="complaintForm" class="complaint-form">
          <div class="form-group">
            <label for="complaint" class="form-label">
              <i class="fas fa-comment-medical"></i>
              Complaint Details
            </label>
            <textarea 
              name="complaint" 
              id="complaint" 
              class="complaint-textarea"
              placeholder="Please provide a detailed description of your complaint or issue. Be specific about what happened, when it happened, and what you would like us to do about it..."
              required
              maxlength="1000"
            ><?php echo isset($_POST['complaint']) ? h($_POST['complaint']) : ''; ?></textarea>
            <div class="char-count" id="charCountDisplay">
              <span id="charCount">0</span>/1000 characters
            </div>
          </div>
          
          <button type="submit" class="submit-btn pulse">
            <i class="fas fa-paper-plane"></i>
            Submit Complaint
          </button>
        </form>
        
        <div class="action-links">
          <a href="dashboard.php" class="action-link">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
          </a>
          <a href="complaint_history.php" class="action-link">
            <i class="fas fa-history"></i>
            View Complaint History
          </a>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const toggleBtn = document.getElementById('toggleSidebar');
      const toggleIcon = document.getElementById('toggleIcon');
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      const sidebarOverlay = document.getElementById('sidebarOverlay');
      const internetError = document.getElementById('internetError');
      const reconnectBtn = document.getElementById('reconnectBtn');
      const logoImg = document.getElementById('logoImg');
      const logoText = document.getElementById('logoText');
      const complaintTextarea = document.getElementById('complaint');
      const charCount = document.getElementById('charCount');
      const charCountDisplay = document.getElementById('charCountDisplay');
      const form = document.getElementById('complaintForm');
      const submitBtn = form.querySelector('button[type="submit"]');
      
      // Check internet connection
      function checkInternetConnection() {
        if (!navigator.onLine) {
          internetError.classList.add('show');
        } else {
          internetError.classList.remove('show');
        }
      }
      
      // Initial check
      checkInternetConnection();
      
      // Listen for connection changes
      window.addEventListener('online', function() {
        internetError.classList.remove('show');
        showToast('Internet connection restored!', 'success');
      });
      
      window.addEventListener('offline', function() {
        internetError.classList.add('show');
        showToast('You are offline. Please check your connection.', 'error');
      });
      
      // Reconnect button
      reconnectBtn.addEventListener('click', function() {
        checkInternetConnection();
        if (navigator.onLine) {
          showToast('Reconnected successfully!', 'success');
        } else {
          showToast('Still offline. Check your connection.', 'error');
        }
      });
      
      // Toggle sidebar function
      function toggleSidebar() {
        if (window.innerWidth < 1025) {
          // Mobile/tablet view
          sidebar.classList.toggle('active');
          sidebarOverlay.classList.toggle('active');
          document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
          
          // Ensure logo is properly sized for mobile
          if (sidebar.classList.contains('active')) {
            logoImg.style.width = '85px';
            logoImg.style.height = '85px';
            logoText.style.display = 'block';
          }
        } else {
          // Desktop view - toggle collapsed state
          sidebar.classList.toggle('collapsed');
          mainContent.classList.toggle('expanded');
          
          // Update toggle icon
          if (sidebar.classList.contains('collapsed')) {
            toggleIcon.classList.replace('fa-bars', 'fa-ellipsis-h');
            // Adjust logo size for collapsed state
            logoImg.style.width = '70px';
            logoImg.style.height = '70px';
            logoImg.style.margin = '0 auto';
            logoText.style.opacity = '0';
            logoText.style.width = '0';
            logoText.style.height = '0';
            logoText.style.overflow = 'hidden';
            logoText.style.margin = '0';
            logoText.style.padding = '0';
            logoText.style.fontSize = '0';
          } else {
            toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
            // Restore logo size for expanded state
            logoImg.style.width = '85px';
            logoImg.style.height = '85px';
            logoImg.style.margin = '0';
            logoText.style.opacity = '1';
            logoText.style.width = 'auto';
            logoText.style.height = 'auto';
            logoText.style.overflow = 'visible';
            logoText.style.margin = '';
            logoText.style.padding = '';
            logoText.style.fontSize = '26px';
          }
        }
      }
      
      // Toggle sidebar button
      toggleBtn.addEventListener('click', toggleSidebar);
      
      // Close sidebar when clicking on overlay
      sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
        document.body.style.overflow = '';
      });
      
      // Close sidebar when clicking on mobile links (but NOT on dropdown trigger)
      if (window.innerWidth < 1025) {
        document.querySelectorAll('.nav-item:not(.dropdown), .dropdown-item').forEach(link => {
          link.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
          });
        });
      }
      
      // Dropdown toggle for Exams
      document.querySelectorAll('.dropdown').forEach(drop => {
        drop.addEventListener('click', function(e) {
          e.stopPropagation();
          this.classList.toggle('open');
        });
      });
      
      // Close dropdowns when clicking outside
      document.addEventListener('click', function() {
        document.querySelectorAll('.dropdown').forEach(drop => {
          drop.classList.remove('open');
        });
      });
      
      // Character counter for textarea
      complaintTextarea.addEventListener('input', function() {
        const currentLength = this.value.length;
        charCount.textContent = currentLength;
        
        // Change color when approaching limit
        if (currentLength > 900) {
          charCountDisplay.classList.add('danger');
          charCountDisplay.classList.remove('warning');
        } else if (currentLength > 700) {
          charCountDisplay.classList.add('warning');
          charCountDisplay.classList.remove('danger');
        } else {
          charCountDisplay.classList.remove('warning', 'danger');
        }
      });
      
      // Initialize character count on page load
      charCount.textContent = complaintTextarea.value.length;
      
      // Form submission animation
      form.addEventListener('submit', function(e) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        submitBtn.disabled = true;
        submitBtn.classList.remove('pulse');
      });
      
      // Add animation to cards on scroll
      const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
      };
      
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.style.animation = 'fadeIn 0.6s ease-out forwards';
          }
        });
      }, observerOptions);
      
      document.querySelectorAll('.complaint-container').forEach(card => {
        observer.observe(card);
      });
      
      // Toast notification function
      function showToast(message, type = 'info') {
        // Remove existing toast
        const existingToast = document.querySelector('.toast');
        if (existingToast) existingToast.remove();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
          <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
          <span>${message}</span>
        `;
        
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);
        
        // Remove after 4 seconds
        setTimeout(() => {
          toast.classList.remove('show');
          setTimeout(() => {
            if (toast.parentNode) {
              toast.parentNode.removeChild(toast);
            }
          }, 500);
        }, 4000);
      }
      
      // Handle window resize
      let resizeTimer;
      window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
          if (window.innerWidth >= 1025) {
            // Desktop - ensure sidebar is not in mobile active state
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
            
            // Ensure toggle button icon is correct
            if (sidebar.classList.contains('collapsed')) {
              toggleIcon.classList.replace('fa-bars', 'fa-ellipsis-h');
              // Adjust logo for collapsed state
              logoImg.style.width = '70px';
              logoImg.style.height = '70px';
              logoText.style.opacity = '0';
              logoText.style.width = '0';
              logoText.style.height = '0';
            } else {
              toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
              // Restore logo for expanded state
              logoImg.style.width = '85px';
              logoImg.style.height = '85px';
              logoText.style.opacity = '1';
              logoText.style.width = 'auto';
              logoText.style.height = 'auto';
            }
          } else {
            // Mobile/tablet - ensure sidebar is not in collapsed state
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
            toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
            
            // Restore logo for mobile
            logoImg.style.width = '85px';
            logoImg.style.height = '85px';
            logoText.style.display = 'block';
            logoText.style.opacity = '1';
            logoText.style.width = 'auto';
            logoText.style.height = 'auto';
            logoText.style.fontSize = '26px';
          }
        }, 250);
      });
      
      // Initialize based on current screen size
      if (window.innerWidth < 1025) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
      } else {
        // Initialize logo size based on initial state
        if (sidebar.classList.contains('collapsed')) {
          logoImg.style.width = '70px';
          logoImg.style.height = '70px';
        } else {
          logoImg.style.width = '85px';
          logoImg.style.height = '85px';
        }
      }
      
      // Auto-hide success message after 5 seconds
      const successAlert = document.querySelector('.alert-success');
      if (successAlert) {
        setTimeout(() => {
          successAlert.style.opacity = '0';
          successAlert.style.transform = 'translateY(-10px)';
          setTimeout(() => {
            successAlert.style.display = 'none';
          }, 500);
        }, 5000);
      }
    });
  </script>
  <?php student_notifications_render_script($student_notifications_data); ?>
</body>
</html>
