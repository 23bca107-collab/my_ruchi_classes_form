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
require_once __DIR__ . '/../includes/schedule_helper.php';

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

// ---------------- FETCH SCHEDULE ----------------
$schedule = [];
$schedule_summary = [
    'total_classes' => 0,
    'days_with_classes' => [],
    'subjects' => []
];

if (schedule_table_exists($conn)) {
    $sql = "SELECT * FROM schedule WHERE medium = ? AND class = ? ORDER BY FIELD(day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), start_time";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $student_medium, $class);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            if (schedule_is_visible($row)) {
                $schedule[] = $row;
                $schedule_summary['total_classes']++;
                
                if (!in_array($row['day'], $schedule_summary['days_with_classes'])) {
                    $schedule_summary['days_with_classes'][] = $row['day'];
                }
                if (!in_array($row['subject'], $schedule_summary['subjects'])) {
                    $schedule_summary['subjects'][] = $row['subject'];
                }
            }
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Ruchi Classes">
  <meta name="theme-color" content="#2563eb">
  <meta name="msapplication-TileColor" content="#2563eb">
  <title>My Time Table | Ruchi Classes</title>
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
  -webkit-font-smoothing: antialiased;
  -webkit-text-size-adjust: 100%;
  -webkit-user-select: none;
  -webkit-tap-highlight-color: transparent;
  text-rendering: optimizeLegibility;
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

/* ---------------- SCHEDULE CONTAINER ---------------- */
.schedule-container {
  background: var(--card-bg);
  border-radius: 20px;
  padding: 2.5rem;
  margin-bottom: 2.5rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  animation: fadeIn 0.8s ease;
}

.schedule-container:hover {
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

/* ---------------- ACTION BUTTONS ---------------- */
.action-buttons {
  display: flex;
  gap: 15px;
  margin-bottom: 25px;
  flex-wrap: wrap;
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  background: var(--gradient-primary);
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  text-decoration: none;
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

.action-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
}

.action-btn:active {
  transform: translateY(-1px);
}

.action-btn.secondary {
  background: var(--bg-card);
  color: var(--text-primary);
  border: 2px solid var(--border);
}

.action-btn.secondary:hover {
  background: var(--bg-hover);
  box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

/* ---------------- SUMMARY CARDS ---------------- */
.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.summary-card {
  background: var(--gradient-primary);
  color: white;
  border-radius: 16px;
  padding: 1.5rem;
  text-align: center;
  box-shadow: 0 8px 25px rgba(37, 99, 235, 0.25);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  opacity: 0;
  animation: fadeIn 0.6s ease forwards;
}

.summary-card:nth-child(1) { animation-delay: 0.1s; }
.summary-card:nth-child(2) { animation-delay: 0.2s; }
.summary-card:nth-child(3) { animation-delay: 0.3s; }

.summary-card:hover {
  transform: translateY(-5px) scale(1.05);
  box-shadow: 0 12px 30px rgba(37, 99, 235, 0.35);
}

.summary-icon {
  font-size: 32px;
  margin-bottom: 12px;
}

.summary-value {
  font-size: 36px;
  font-weight: 800;
  margin-bottom: 8px;
  line-height: 1;
}

.summary-label {
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 1px;
  opacity: 0.9;
}

/* ---------------- SCHEDULE TABLE ---------------- */
.schedule-table-container {
  background: var(--bg-card);
  border-radius: 16px;
  overflow: hidden;
  border: 1px solid var(--border);
  box-shadow: 0 4px 15px rgba(0,0,0,0.05);
  margin-top: 25px;
  overflow-x: auto;
}

.schedule-table {
  width: 100%;
  border-collapse: collapse;
  background: var(--bg-card);
  min-width: 800px;
}

.schedule-table th {
  background: var(--gradient-primary);
  color: white;
  padding: 1.2rem 1.5rem;
  text-align: left;
  font-weight: 600;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 1px;
  border: none;
  position: sticky;
  top: 0;
  z-index: 10;
}

.schedule-table td {
  padding: 1.2rem 1.5rem;
  border-bottom: 1px solid var(--border);
  color: var(--text-primary);
  font-size: 15px;
  font-weight: 500;
}

.schedule-table tr:last-child td {
  border-bottom: none;
}

.schedule-table tr {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.schedule-table tr:hover {
  background-color: var(--bg-hover);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.day-cell {
  font-weight: 600;
  color: var(--primary);
  background: rgba(37, 99, 235, 0.05);
}

.subject-cell {
  font-weight: 600;
  color: var(--primary);
}

.time-cell {
  font-family: 'Courier New', monospace;
  font-weight: 600;
  color: var(--text-secondary);
}

.teacher-cell {
  color: var(--text-primary);
}

/* ---------------- NO SCHEDULE MESSAGE ---------------- */
.no-schedule {
  text-align: center;
  padding: 4rem;
  font-size: 18px;
  color: var(--text-muted);
  background: var(--bg-card);
  border-radius: 20px;
  border: 2px dashed var(--border);
}

.no-schedule i {
  font-size: 48px;
  margin-bottom: 1rem;
  color: var(--border);
}

/* ---------------- INFO CARD ---------------- */
.info-card {
  background: var(--bg-card);
  border-radius: 20px;
  padding: 2rem;
  margin-top: 2rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
}

.info-card h3 {
  color: var(--primary);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: 10px;
}

.info-card p {
  margin-bottom: 1rem;
  line-height: 1.6;
  color: var(--text-secondary);
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
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
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
  
  .schedule-container {
    padding: 2rem;
  }
  
  .summary-cards {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .action-buttons {
    flex-direction: column;
  }
  
  .action-btn {
    width: 100%;
    justify-content: center;
  }
}

/* Mobile */
@media (max-width: 768px) {
  html {
    font-size: 14px;
  }
  
  .main-content {
    padding: 1rem;
  }
  
  .header {
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 14px;
    gap: 12px;
  }
  
  .page-title {
    font-size: 26px;
    padding-bottom: 12px;
  }
  
  .schedule-container {
    padding: 1.5rem;
    border-radius: 14px;
  }
  
  .welcome-info {
    padding: 1rem;
    flex-direction: column;
    text-align: center;
    border-radius: 12px;
  }
  
  .welcome-icon {
    font-size: 28px;
  }
  
  .welcome-text h4 {
    font-size: 16px;
  }
  
  .welcome-text p {
    font-size: 13px;
  }
  
  .summary-cards {
    grid-template-columns: 1fr;
    gap: 15px;
  }
  
  .summary-card {
    padding: 1.2rem;
    border-radius: 12px;
  }
  
  .summary-value {
    font-size: 28px;
  }
  
  .summary-label {
    font-size: 12px;
  }
  
  .schedule-table th,
  .schedule-table td {
    padding: 0.75rem 1rem;
    font-size: 14px;
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
    min-width: 45px;
  }
  
  .user-avatar {
    width: 42px;
    height: 42px;
  }
  
  .nav-item {
    padding: 14px 16px;
    margin-bottom: 8px;
    min-height: 48px;
    display: flex;
    align-items: center;
  }
  
  .action-btn {
    padding: 12px 20px;
    font-size: 14px;
    min-height: 44px;
    display: flex;
    align-items: center;
    border-radius: 10px;
  }
  
  .action-buttons {
    flex-wrap: wrap;
    gap: 10px;
  }
  
  .error-content {
    padding: 30px 20px;
    border-radius: 16px;
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
  
/* Mobile table improvements */
  .schedule-table-container {
    border-radius: 12px;
  }
  
  .schedule-table {
    min-width: 100%;
    display: block;
  }
  
  /* Hide table headers on mobile */
  .schedule-table thead {
    display: none;
  }
  
  /* Convert table rows to cards */
  .schedule-table tbody {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  
  .schedule-table tr {
    min-height: auto;
    display: block;
    background: white;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  }
  
  .schedule-table tr:hover {
    background-color: white;
    transform: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  }
  
  /* Table cells display as blocks */
  .schedule-table td {
    display: block;
    padding: 6px 0;
    border: none;
    font-size: 12px;
  }
  
  /* Day cell - header style */
  .day-cell {
    background: rgba(37, 99, 235, 0.1);
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 8px;
    display: block;
  }
  
  /* Cell labels for mobile */
  .schedule-table td:nth-child(2)::before {
    content: '📚 Class: ';
    font-weight: 600;
    color: var(--text-secondary);
  }
  
  .schedule-table td:nth-child(3)::before {
    content: '📖 Subject: ';
    font-weight: 600;
    color: var(--text-secondary);
  }
  
  .schedule-table td:nth-child(4)::before {
    content: '👨‍🏫 Teacher: ';
    font-weight: 600;
    color: var(--text-secondary);
  }
  
  .schedule-table td:nth-child(5)::before {
    content: '⏰ Starting Time: ';
    font-weight: 600;
    color: var(--text-secondary);
  }
  
  .schedule-table td:nth-child(6) {
    display: inline;
    padding: 0;
  }
  
  .schedule-table td:nth-child(6)::before {
    content: '';
  }
  
  .schedule-table td:nth-child(5),
  .schedule-table td:nth-child(6) {
    display: inline;
  }
  
  .schedule-table td:nth-child(5) {
    display: block;
    padding-bottom: 6px;
    padding-top: 8px;
    margin-top: 8px;
    border-top: 1px solid var(--border);
    padding-top: 8px;
  }
  
  .schedule-table td:nth-child(6) {
    display: block;
    padding: 0;
    padding-top: 6px;
    padding-bottom: 0;
    border: none;
  }
  
  .info-card {
    padding: 1.2rem;
    border-radius: 12px;
    margin-top: 15px;
  }
  
  .info-card h3 {
    font-size: 16px;
  }
  
  .info-card p {
    font-size: 13px;
  }
}

/* Small Mobile */
@media (max-width: 480px) {
  html {
    font-size: 13px;
  }
  
  body {
    -webkit-font-smoothing: antialiased;
    -webkit-text-size-adjust: 100%;
  }
  
  .main-content {
    padding: 0.75rem;
  }
  
  .header {
    padding: 0.75rem;
    gap: 10px;
    margin-bottom: 0.75rem;
  }
  
  .header-left {
    flex: 1;
    min-width: 0;
  }
  
  .toggle-sidebar {
    width: 40px;
    height: 40px;
    min-width: 40px;
    font-size: 16px;
  }
  
  .user-avatar {
    width: 38px;
    height: 38px;
  }
  
  .notifications {
    padding: 10px;
    width: 38px;
    height: 38px;
    min-width: 38px;
  }
  
  .notification-badge {
    width: 16px;
    height: 16px;
    font-size: 9px;
  }
  
  .user-name {
    font-size: 12px;
    max-width: 90px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  
  .page-title {
    font-size: 20px;
    padding-bottom: 10px;
  }
  
  .class-info-badge {
    padding: 8px 12px;
    font-size: 12px;
    margin: 0.75rem 0 1rem 0;
  }
  
  .schedule-container {
    padding: 1rem;
    border-radius: 12px;
  }
  
  .welcome-info {
    padding: 0.75rem;
    gap: 10px;
  }
  
  .welcome-icon {
    font-size: 24px;
    flex-shrink: 0;
  }
  
  .welcome-text h4 {
    font-size: 15px;
    margin-bottom: 3px;
  }
  
  .welcome-text p {
    font-size: 11px;
    line-height: 1.3;
  }
  
  .summary-card {
    padding: 0.9rem;
    border-radius: 10px;
  }
  
  .summary-icon {
    font-size: 24px;
    margin-bottom: 6px;
  }
  
  .summary-value {
    font-size: 22px;
  }
  
  .summary-label {
    font-size: 11px;
  }
  
  .no-schedule {
    padding: 1.5rem;
    font-size: 13px;
  }
  
  .no-schedule i {
    font-size: 32px;
    margin-bottom: 10px;
  }
  
  .schedule-table th {
    padding: 8px 6px;
    font-size: 10px;
    word-break: break-word;
  }
  
  .schedule-table td {
    padding: 8px 6px;
    font-size: 11px;
    word-break: break-word;
  }
  
  .day-cell {
    font-size: 11px;
  }
  
  .subject-cell {
    font-size: 11px;
  }
  
  .time-cell {
    font-size: 10px;
  }
  
  .teacher-cell {
    font-size: 11px;
  }
  
  /* Mobile card view improvements for small devices */
  .schedule-table tr {
    padding: 10px;
  }
  
  .day-cell {
    padding: 6px 8px;
    font-size: 12px;
    margin-bottom: 6px;
  }
  
  .schedule-table td {
    font-size: 11px;
    padding: 4px 0;
  }
  
  .schedule-table td:nth-child(2)::before {
    content: 'Class: ';
    font-size: 10px;
  }
  
  .schedule-table td:nth-child(3)::before {
    content: 'Subject: ';
    font-size: 10px;
  }
  
  .schedule-table td:nth-child(4)::before {
    content: 'Teacher: ';
    font-size: 10px;
  }
  
  .schedule-table td:nth-child(5)::before {
    content: 'Starting Time: ';
    font-size: 10px;
  }
  
  .schedule-table td:nth-child(6)::before {
    content: 'Ending Time: ';
    font-size: 10px;
  }
  
  .subject-cell,
  .teacher-cell,
  .time-cell {
    font-size: 11px;
    color: var(--text-primary);
    font-weight: 500;
  }
  
  .action-btn {
    padding: 10px 16px;
    font-size: 12px;
    min-height: 40px;
    border-radius: 8px;
  }
  
  .action-buttons {
    gap: 8px;
  }
  
  .error-content {
    padding: 24px 16px;
    border-radius: 14px;
  }
  
  .error-icon {
    font-size: 48px;
    margin-bottom: 12px;
  }
  
  .error-title {
    font-size: 18px;
  }
  
  .error-message {
    font-size: 13px;
  }
  
  .reconnect-btn {
    padding: 10px 20px;
    font-size: 12px;
    border-radius: 8px;
    min-height: 40px;
  }
  
  .info-card {
    padding: 1rem;
    border-radius: 10px;
    margin-top: 12px;
  }
  
  .info-card h3 {
    font-size: 14px;
  }
  
  .info-card p {
    font-size: 12px;
    line-height: 1.4;
  }
  
  .nav-item {
    padding: 12px 14px;
    min-height: 44px;
  }
  
  .nav-icon {
    font-size: 18px;
    width: 24px;
  }
  
  .nav-text {
    font-size: 13px;
  }
  
  .sidebar {
    width: 85%;
    max-width: 280px;
  }
  
  .logo-container {
    margin-bottom: 1rem;
    height: 80px;
  }
  
  .logo-img {
    width: 70px;
    height: 70px;
  }
  
  .logo-text {
    font-size: 18px;
  }
}

/* Extra small screens (less than 360px) */
@media (max-width: 359px) {
  .page-title {
    font-size: 18px;
  }
  
  .class-info-badge {
    font-size: 10px;
    padding: 6px 10px;
  }
  
  .welcome-text h4 {
    font-size: 14px;
  }
  
  .summary-value {
    font-size: 20px;
  }
  
  .action-btn {
    padding: 9px 14px;
    font-size: 11px;
  }
  
  .user-name {
    display: none;
  }
}

/* Landscape orientation on mobile */
@media (max-height: 500px) and (orientation: landscape) {
  .main-content {
    padding: 0.5rem;
  }
  
  .header {
    padding: 0.5rem;
    margin-bottom: 0.5rem;
  }
  
  .page-title {
    font-size: 18px;
    margin-bottom: 0.5rem;
  }
  
  .schedule-container {
    padding: 0.75rem;
  }
  
  .welcome-info {
    padding: 0.5rem;
  }
  
  .summary-cards {
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
  }
  
  .summary-card {
    padding: 0.8rem;
  }
  
  .summary-value {
    font-size: 20px;
  }
  
  .summary-label {
    font-size: 10px;
  }
}

/* Touch device optimizations */
@media (hover: none) and (pointer: coarse) {
  .nav-item,
  .action-btn,
  .toggle-sidebar,
  .notifications,
  .user-profile {
    min-height: 48px;
    min-width: 48px;
    padding: 12px;
  }
  
  button,
  a {
    -webkit-tap-highlight-color: transparent;
  }
  
  .schedule-table tr {
    min-height: 44px;
  }
  
  input,
  select,
  textarea {
    font-size: 16px !important;
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
  
  /* Desktop table - restore normal view */
  .schedule-table {
    display: table;
    min-width: 800px;
  }
  
  .schedule-table thead {
    display: table-header-group;
  }
  
  .schedule-table tbody {
    display: table-row-group;
    flex-direction: unset;
    gap: 0;
  }
  
  .schedule-table tr {
    display: table-row;
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 0;
    box-shadow: none;
  }
  
  .schedule-table tr:hover {
    background-color: var(--bg-hover);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
  }
  
  .schedule-table th {
    display: table-cell;
  }
  
  .schedule-table td {
    display: table-cell;
    padding: 1.2rem 1.5rem;
    border-bottom: 1px solid var(--border);
  }
  
  .schedule-table td::before {
    content: '' !important;
  }
  
  .day-cell,
  .subject-cell,
  .time-cell,
  .teacher-cell {
    background: transparent !important;
    padding: 1.2rem 1.5rem !important;
    margin: 0 !important;
    border: none !important;
  }
  
  .day-cell {
    background: rgba(37, 99, 235, 0.05) !important;
    font-weight: 600;
    color: var(--primary);
  }
}

/* Print Styles */
@media print {
  .sidebar,
  .toggle-sidebar,
  .notifications,
  .user-profile,
  .internet-error,
  .sidebar-overlay,
  .action-buttons {
    display: none !important;
  }
  
  .main-content {
    margin-left: 0 !important;
    padding: 0 !important;
  }
  
  .schedule-container,
  .schedule-table-container,
  .info-card {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    break-inside: avoid;
  }
  
  body {
    background: white !important;
    color: black !important;
  }
  
  .summary-card {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    background: #f8f9fa !important;
    color: black !important;
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

      <a href="complain.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-comment-dots"></i></div>
        <div class="nav-text">Complaint</div>
      </a>

      <a href="view_schedule.php" class="nav-item active">
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
      <h1 class="page-title">📅 My Time Table</h1>

      <!-- Class Info -->
      <div style="text-align: center; margin-bottom: 1rem;">
        <div class="class-info-badge">
          <i class="fas fa-graduation-cap"></i>
          Class <?php echo h($class); ?> | <?php echo ucfirst(h($student_medium)); ?> Medium
        </div>
      </div>

      <!-- Schedule Container -->
      <div class="schedule-container">
        <!-- Welcome Info -->
        <div class="welcome-info">
          <div class="welcome-icon">
            <i class="fas fa-calendar-alt"></i>
          </div>
          <div class="welcome-text">
            <h4>Welcome, <?php echo h($student_name); ?></h4>
            <p>Here's your complete weekly class schedule for Class <?php echo h($class); ?> (<?php echo ucfirst(h($student_medium)); ?> Medium)</p>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
          <a href="dashboard.php" class="action-btn">
            <i class="fas fa-tachometer-alt"></i>
            Back to Dashboard
          </a>
          <button class="action-btn secondary" onclick="window.print()">
            <i class="fas fa-print"></i>
            Print Schedule
          </button>
        </div>

        <?php if (!empty($schedule)): ?>
        <!-- Summary Cards -->
        <div class="summary-cards">
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-book-open"></i>
            </div>
            <div class="summary-value"><?php echo $schedule_summary['total_classes']; ?></div>
            <div class="summary-label">Total Classes</div>
          </div>
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-calendar-day"></i>
            </div>
            <div class="summary-value"><?php echo count($schedule_summary['days_with_classes']); ?></div>
            <div class="summary-label">Days with Classes</div>
          </div>
          <div class="summary-card">
            <div class="summary-icon">
              <i class="fas fa-subject"></i>
            </div>
            <div class="summary-value"><?php echo count($schedule_summary['subjects']); ?></div>
            <div class="summary-label">Different Subjects</div>
          </div>
        </div>

        <!-- Schedule Table -->
        <div class="schedule-table-container">
          <table class="schedule-table">
            <thead>
              <tr>
                <th>Day</th>
                <th>Class</th>
                <th>Subject</th>
                <th>Teacher</th>
                <th>Start Time</th>
                <th>End Time</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($schedule as $row): 
                $start_time = date('h:i A', strtotime($row['start_time']));
                $end_time = date('h:i A', strtotime($row['end_time']));
              ?>
              <tr>
                <td class="day-cell"><?php echo h($row['day']); ?></td>
                <td><?php echo h($row['class']); ?></td>
                <td class="subject-cell"><?php echo h($row['subject']); ?></td>
                <td class="teacher-cell"><?php echo h($row['teacher']); ?></td>
                <td class="time-cell"><?php echo h($start_time); ?></td>
                <td class="time-cell"><?php echo h($end_time); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <!-- No Schedule Message -->
        <div class="no-schedule">
          <i class="far fa-calendar-times"></i>
          <p>No schedule available for Class <?php echo h($class); ?> (<?php echo ucfirst(h($student_medium)); ?> Medium).</p>
          <p>Your class schedule will appear here once it's published.</p>
        </div>
        <?php endif; ?>

        <!-- Information Card -->
        <div class="info-card">
          <h3><i class="fas fa-info-circle"></i> Schedule Information</h3>
          <p>This is your personalized class schedule for the <strong><?php echo ucfirst(h($student_medium)); ?> medium</strong>, Class <strong><?php echo h($class); ?></strong>.</p>
          <p>The schedule is organized by day of the week and shows all your classes with their respective timings and teachers.</p>
          <p>If you notice any discrepancies in your schedule or have questions about your classes, please contact your academic advisor or class teacher.</p>
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
      
      // Add interactivity to schedule table rows
      const tableRows = document.querySelectorAll('.schedule-table tbody tr');
      tableRows.forEach(row => {
        row.addEventListener('click', function() {
          // Remove highlight from all rows
          tableRows.forEach(r => r.style.backgroundColor = '');
          
          // Highlight clicked row
          this.style.backgroundColor = 'rgba(37, 99, 235, 0.1)';
          
          // Get row data
          const day = this.cells[0].textContent;
          const className = this.cells[1].textContent;
          const subject = this.cells[2].textContent;
          const teacher = this.cells[3].textContent;
          const time = `${this.cells[4].textContent} - ${this.cells[5].textContent}`;
          
          // Show toast with class details
          showToast(`${day}: ${subject} with ${teacher} at ${time}`, 'info');
        });
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
      
      document.querySelectorAll('.schedule-container, .summary-card, .info-card').forEach(card => {
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
        
        // Add toast styles if not already added
        if (!document.getElementById('toast-styles')) {
          const toastStyles = document.createElement('style');
          toastStyles.id = 'toast-styles';
          toastStyles.textContent = `
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
            
            @media (max-width: 768px) {
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
          `;
          document.head.appendChild(toastStyles);
        }
        
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
      
    });
  </script>
  <?php student_notifications_render_script($student_notifications_data); ?>
</body>
</html>
