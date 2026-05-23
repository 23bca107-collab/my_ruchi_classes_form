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
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$student_notifications_data = student_notifications_prepare($conn, $student, 12);

$student_id = $student['id'] ?? 0;

// ✅ Overall Attendance
$overall = [
    'present_days' => 0,
    'absent_days' => 0,
    'suspended_days' => 0,
    'remaining_days' => 0,
    'percentage' => 0
];

// ✅ Monthly Attendance
$monthlyResults = [];

// ✅ Subject-wise Attendance
$subjectRows = [];

// Check if attendance table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    // ✅ Overall Attendance
    $queryOverall = "
        SELECT 
            SUM(status='P') AS present_days,
            SUM(status='A') AS absent_days,
            SUM(status='S') AS suspended_days,
            SUM(status='R') AS remaining_days,
            ROUND((SUM(status='P') / (SUM(status='P') + SUM(status='A'))) * 100, 2) AS percentage
        FROM attendance
        WHERE student_id = ?
    ";
    $stmt = $conn->prepare($queryOverall);
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $overall = $result->fetch_assoc() ?? $overall;
    }

    // ✅ Monthly Attendance
    $queryMonthly = "
        SELECT 
            YEAR(date) AS year,
            MONTH(date) AS month,
            SUM(status='P') AS present_days,
            SUM(status='A') AS absent_days,
            SUM(status='S') AS suspended_days,
            SUM(status='R') AS remaining_days,
            ROUND((SUM(status='P') / (SUM(status='P') + SUM(status='A'))) * 100, 2) AS percentage
        FROM attendance
        WHERE student_id = ?
        GROUP BY YEAR(date), MONTH(date)
        ORDER BY year DESC, month DESC
    ";
    $stmtMonthly = $conn->prepare($queryMonthly);
    $stmtMonthly->bind_param("i", $student_id);
    if ($stmtMonthly->execute()) {
        $monthlyResults = $stmtMonthly->get_result();
    }

    // ✅ Subject-wise Attendance
    $querySubjects = "
        SELECT 
            subject,
            SUM(status='P') AS present_days,
            SUM(status='A') AS absent_days,
            SUM(status='S') AS suspended_days,
            SUM(status='R') AS remaining_days,
            ROUND((SUM(status='P') / (SUM(status='P') + SUM(status='A'))) * 100, 2) AS percentage
        FROM attendance
        WHERE student_id = ?
        GROUP BY subject
    ";
    $stmt2 = $conn->prepare($querySubjects);
    $stmt2->bind_param("i", $student_id);
    if ($stmt2->execute()) {
        $subjectsResult = $stmt2->get_result();
        if ($subjectsResult) {
            while ($subjectRow = $subjectsResult->fetch_assoc()) {
                $subjectRows[] = $subjectRow;
            }
        }
    }

    // ✅ Detailed attendance records for monthly view
    $monthlyDetails = [];
    if (isset($_GET['month']) && isset($_GET['year'])) {
        $selectedMonth = $_GET['month'];
        $selectedYear = $_GET['year'];
        
        $queryDetails = "
            SELECT 
                date,
                subject,
                status,
                remarks
            FROM attendance
            WHERE student_id = ? 
                AND MONTH(date) = ? 
                AND YEAR(date) = ?
            ORDER BY date DESC, subject
        ";
        $stmtDetails = $conn->prepare($queryDetails);
        $stmtDetails->bind_param("iii", $student_id, $selectedMonth, $selectedYear);
        if ($stmtDetails->execute()) {
            $monthlyDetails = $stmtDetails->get_result();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance | Ruchi Classes</title>
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

html,
body {
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
  overscroll-behavior-x: none;
}

body {
  background: var(--main-bg);
  color: var(--text-primary);
  font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  line-height: 1.6;
  min-height: 100vh;
  overflow-x: hidden;
  position: relative;
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
  width: 100%;
  max-width: 100%;
  overflow-x: clip;
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
  width: 100%;
  max-width: 100%;
  overflow-x: clip;
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
  width: 100%;
  max-width: 100%;
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
  min-width: 0;
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
  min-width: 0;
  max-width: 100%;
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

.user-name {
  min-width: 0;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ---------------- ATTENDANCE DASHBOARD ---------------- */

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

/* ---------------- TABS ---------------- */
.attendance-tabs {
  display: flex;
  justify-content: center;
  margin-bottom: 2rem;
  flex-wrap: wrap;
  gap: 10px;
  width: 100%;
  max-width: 100%;
}

.tab-button {
  padding: 14px 28px;
  background: var(--bg-card);
  border: 2px solid var(--border);
  border-radius: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  color: var(--text-secondary);
  font-size: 16px;
}

.tab-button:hover {
  background: var(--bg-hover);
  color: var(--text-primary);
  transform: translateY(-2px);
}

.tab-button.active {
  background: var(--gradient-primary);
  color: white;
  border-color: var(--primary);
  box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
}

/* ---------------- TAB CONTENT ---------------- */
.tab-content {
  display: none;
  animation: fadeIn 0.5s ease;
}

.tab-content.active {
  display: block;
}

/* ---------------- DASHBOARD CARDS ---------------- */
.dashboard-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 25px;
  margin-bottom: 3rem;
}

.stat-card {
  background: var(--card-bg);
  border-radius: 20px;
  padding: 2rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  text-align: center;
  position: relative;
  overflow: hidden;
}

.stat-card:hover {
  transform: translateY(-10px);
  box-shadow: 0 25px 50px rgba(0,0,0,0.15);
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: var(--gradient-primary);
  transform: scaleX(0);
  transition: 0.4s ease;
}

.stat-card:hover::before {
  transform: scaleX(1);
}

.present .stat-icon {
  background: var(--success);
}

.absent .stat-icon {
  background: var(--danger);
}

.suspended .stat-icon {
  background: var(--warning);
}

.remaining .stat-icon {
  background: var(--info);
}

.stat-icon {
  width: 70px;
  height: 70px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.5rem;
  color: white;
  font-size: 28px;
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.stat-title {
  font-size: 16px;
  color: var(--text-muted);
  margin-bottom: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.stat-value {
  font-size: 42px;
  font-weight: 800;
  color: var(--text-primary);
  margin-bottom: 10px;
  line-height: 1;
}

.stat-card.present .stat-value { color: var(--success); }
.stat-card.absent .stat-value { color: var(--danger); }
.stat-card.suspended .stat-value { color: var(--warning); }
.stat-card.remaining .stat-value { color: var(--info); }

/* ---------------- ATTENDANCE SECTIONS ---------------- */
.attendance-section {
  background: var(--card-bg);
  border-radius: 20px;
  padding: 2.5rem;
  margin-bottom: 2rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  animation: fadeIn 0.8s ease;
  width: 100%;
  max-width: 100%;
  min-width: 0;
}

.attendance-section:hover {
  transform: translateY(-5px);
  box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.section-title {
  font-size: 24px;
  font-weight: 700;
  margin-bottom: 1.5rem;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 12px;
}

.section-title i {
  color: var(--primary);
}

/* ---------------- SUMMARY BOX ---------------- */
.summary-box {
  display: flex;
  justify-content: space-around;
  flex-wrap: wrap;
  gap: 20px;
  margin-top: 2rem;
  padding: 1.5rem;
  background: var(--bg-secondary);
  border-radius: 16px;
  border: 1px solid var(--border);
  width: 100%;
  max-width: 100%;
}

.summary-item {
  text-align: center;
  flex: 1;
  min-width: 120px;
}

.summary-value {
  font-size: 28px;
  font-weight: 800;
  margin-bottom: 5px;
  color: var(--text-primary);
}

.summary-label {
  font-size: 14px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 600;
}

/* ---------------- PROGRESS BAR ---------------- */
.progress-container {
  width: 100%;
  height: 10px;
  background: var(--bg-secondary);
  border-radius: 5px;
  overflow: hidden;
  margin: 1.5rem 0;
}

.progress-bar {
  height: 100%;
  border-radius: 5px;
  transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
  background: var(--gradient-primary);
}

.good { background: linear-gradient(to right, var(--success), #059669); }
.warning { background: linear-gradient(to right, var(--warning), #ea580c); }
.danger { background: linear-gradient(to right, var(--danger), #b91c1c); }

.percentage-text {
  font-size: 18px;
  font-weight: 600;
  color: var(--text-primary);
  text-align: center;
}

.status-present { color: var(--success); }
.status-absent { color: var(--danger); }
.status-suspended { color: var(--warning); }
.status-remaining { color: var(--info); }

/* ---------------- TABLES ---------------- */
table {
  width: 100%;
  border-collapse: collapse;
  margin: 1.5rem 0;
  background: var(--bg-card);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

th, td {
  padding: 1.2rem 1.5rem;
  text-align: center;
  border-bottom: 1px solid var(--border);
}

th {
  background: var(--gradient-primary);
  color: white;
  font-weight: 600;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 1px;
}

tr:nth-child(even) {
  background-color: var(--bg-secondary);
}

tr:hover {
  background-color: var(--bg-hover);
  transition: background-color 0.2s ease;
}

td {
  color: var(--text-primary);
  font-size: 15px;
  font-weight: 500;
}

.subjects-table {
  display: table;
}

.subject-mobile-list {
  display: none;
}

.subject-attendance-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 18px;
  overflow: hidden;
  box-shadow: 0 12px 28px rgba(37, 99, 235, 0.08);
}

.subject-card-summary {
  list-style: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 1rem 1.1rem;
  background: var(--gradient-primary);
  color: white;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.subject-card-summary::-webkit-details-marker {
  display: none;
}

.subject-card-name {
  font-size: 17px;
  font-weight: 700;
  line-height: 1.35;
}

.subject-card-chevron {
  font-size: 13px;
  transition: transform 0.25s ease;
}

.subject-attendance-card[open] .subject-card-chevron {
  transform: rotate(180deg);
}

.subject-card-body {
  padding: 1rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.subject-card-details {
  display: grid;
  gap: 0.7rem;
}

.subject-detail-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding-bottom: 0.55rem;
  border-bottom: 1px dashed rgba(148, 163, 184, 0.35);
}

.subject-detail-row:last-child {
  padding-bottom: 0;
  border-bottom: none;
}

.subject-detail-label {
  color: var(--text-secondary);
  font-size: 13px;
  font-weight: 600;
}

.subject-detail-value {
  font-size: 16px;
  font-weight: 800;
}

.subject-card-progress-title {
  margin: 1rem 0 0.55rem;
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.subject-card-progress {
  margin: 0;
  height: 8px;
}

.subject-mobile-empty {
  text-align: center;
  padding: 2rem 1rem;
  color: var(--text-muted);
  background: var(--bg-secondary);
  border: 1px dashed var(--border);
  border-radius: 16px;
}

.subject-mobile-empty i {
  display: block;
  font-size: 28px;
  color: var(--primary);
  margin-bottom: 12px;
}

/* ---------------- MONTH CARDS ---------------- */
.month-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 25px;
  margin-bottom: 2rem;
  width: 100%;
  max-width: 100%;
}

.month-card {
  background: var(--card-bg);
  border-radius: 18px;
  padding: 2rem;
  border: 2px solid var(--border);
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
  position: relative;
  overflow: hidden;
}

.month-card:hover {
  transform: translateY(-10px) scale(1.02);
  box-shadow: 0 20px 40px rgba(0,0,0,0.15);
  border-color: var(--primary);
}

.month-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.month-name {
  font-size: 20px;
  font-weight: 700;
  color: var(--primary);
}

.month-stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 15px;
  margin-bottom: 1.5rem;
}

.month-stat {
  text-align: center;
  padding: 15px;
  border-radius: 12px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
}

.month-stat-value {
  font-size: 24px;
  font-weight: 800;
  margin-bottom: 5px;
}

.month-stat-label {
  font-size: 12px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 1px;
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

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ---------------- RESPONSIVE DESIGN ---------------- */

/* Tablet */
@media (max-width: 1024px) {
  html,
  body {
    touch-action: pan-y;
  }

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
  
  .dashboard-cards {
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
  }
  
  .month-cards {
    grid-template-columns: repeat(2, 1fr);
  }
  
  table {
    display: block;
    overflow-x: auto;
    white-space: nowrap;
  }
}

/* Mobile */
@media (max-width: 768px) {
  .main-content {
    padding: 1rem;
  }
  
  .header {
    gap: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 14px;
  }
  
  .page-title {
    font-size: 26px;
  }
  
  .dashboard-cards {
    grid-template-columns: 1fr;
    gap: 20px;
  }
  
  .attendance-tabs {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0;
    background: var(--primary-dark);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.18);
  }
  
  .tab-button {
    width: auto;
    min-height: 56px;
    padding: 0.9rem 0.6rem;
    border: none;
    border-radius: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.88);
    box-shadow: none;
    font-size: 13px;
    line-height: 1.2;
    text-align: center;
    white-space: normal;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .tab-button:hover {
    transform: none;
    background: rgba(255, 255, 255, 0.08);
    color: white;
  }

  .tab-button.active {
    background: var(--gradient-accent);
    color: white;
    box-shadow: none;
  }

  .subjects-table {
    display: none;
  }

  .subject-mobile-list {
    display: grid;
    gap: 16px;
    margin-top: 1.25rem;
  }
  
  .summary-box {
    flex-direction: column;
    gap: 15px;
  }
  
  .summary-item {
    width: 100%;
  }
  
  .month-cards {
    grid-template-columns: 1fr;
  }
  
  .attendance-section {
    padding: 2rem;
  }
  
  .section-title {
    font-size: 20px;
  }
  
  th, td {
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

  .user-profile {
    padding: 8px 12px;
  }

  .user-name {
    max-width: 78px;
    font-size: 14px;
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

  .user-profile {
    padding: 6px 10px;
  }

  .user-name {
    max-width: 58px;
    font-size: 13px;
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
  
  .stat-card {
    padding: 1.5rem;
  }
  
  .stat-icon {
    width: 60px;
    height: 60px;
    font-size: 24px;
  }
  
  .stat-value {
    font-size: 36px;
  }
  
  .attendance-section {
    padding: 1.5rem;
  }

  .attendance-tabs {
    border-radius: 14px;
  }

  .tab-button {
    min-height: 52px;
    padding: 0.8rem 0.45rem;
    font-size: 12px;
  }

  .subject-attendance-card {
    border-radius: 16px;
  }

  .subject-card-summary {
    padding: 0.9rem 1rem;
  }

  .subject-card-name {
    font-size: 15px;
  }

  .subject-card-body {
    padding: 0.9rem;
  }

  .subject-detail-label {
    font-size: 12px;
  }

  .subject-detail-value {
    font-size: 15px;
  }
  
  .month-card {
    padding: 1.5rem;
  }
  
  .month-name {
    font-size: 18px;
  }
  
  .month-stat-value {
    font-size: 20px;
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
  .sidebar-overlay,
  .attendance-tabs {
    display: none !important;
  }
  
  .main-content {
    margin-left: 0 !important;
    padding: 0 !important;
  }
  
  .attendance-section {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    break-inside: avoid;
  }
  
  body {
    background: white !important;
    color: black !important;
  }
  
  table {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
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
      <a href="../student/dashboard.php" class="nav-item">
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
      
      <a href="attendance.php" class="nav-item active">
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
              onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&background=1a56db&color=fff'">
            <div class="user-name"><?php echo h($student['first_name']); ?></div>
          </div>
        </div>
      </div>

      <!-- Page Title -->
      <h1 class="page-title">Attendance Dashboard</h1>

      <!-- Tabs -->
      <div class="attendance-tabs">
        <button class="tab-button active" data-tab="overview">Overview</button>
        <button class="tab-button" data-tab="monthly">Monthly View</button>
        <button class="tab-button" data-tab="subjects">Subject View</button>
      </div>

      <!-- Overview Tab -->
      <div class="tab-content active" id="overview">
        <div class="dashboard-cards">
          <div class="stat-card present">
            <div class="stat-icon">
              <i class="fas fa-check"></i>
            </div>
            <div class="stat-title">Days Present</div>
            <div class="stat-value"><?php echo $overall['present_days'] ?? 0; ?></div>
          </div>
          
          <div class="stat-card absent">
            <div class="stat-icon">
              <i class="fas fa-times"></i>
            </div>
            <div class="stat-title">Days Absent</div>
            <div class="stat-value"><?php echo $overall['absent_days'] ?? 0; ?></div>
          </div>
          
          <div class="stat-card suspended">
            <div class="stat-icon">
              <i class="fas fa-pause-circle"></i>
            </div>
            <div class="stat-title">Days Suspended</div>
            <div class="stat-value"><?php echo $overall['suspended_days'] ?? 0; ?></div>
          </div>
          
          <div class="stat-card remaining">
            <div class="stat-icon">
              <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-title">Days Remaining</div>
            <div class="stat-value"><?php echo $overall['remaining_days'] ?? 0; ?></div>
          </div>
        </div>
        
        <div class="attendance-section">
          <h3 class="section-title"><i class="fas fa-chart-pie"></i> Overall Attendance</h3>
          
          <div class="summary-box">
            <div class="summary-item">
              <div class="summary-value"><?php echo $overall['present_days'] ?? 0; ?></div>
              <div class="summary-label">Present</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $overall['absent_days'] ?? 0; ?></div>
              <div class="summary-label">Absent</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $overall['suspended_days'] ?? 0; ?></div>
              <div class="summary-label">Suspended</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $overall['remaining_days'] ?? 0; ?></div>
              <div class="summary-label">Remaining</div>
            </div>
            <div class="summary-item">
              <div class="summary-value 
                <?php 
                $percentage = $overall['percentage'] ?? 0;
                if ($percentage >= 80) echo 'status-present';
                else if ($percentage >= 60) echo 'status-suspended';
                else echo 'status-absent';
                ?>">
                <?php echo $percentage; ?>%
              </div>
              <div class="summary-label">Attendance Rate</div>
            </div>
          </div>
          
          <div class="progress-container">
            <div class="progress-bar 
              <?php 
              $percentage = $overall['percentage'] ?? 0;
              if ($percentage >= 80) echo 'good';
              else if ($percentage >= 60) echo 'warning';
              else echo 'danger';
              ?>" 
              style="width: <?php echo $percentage; ?>%">
            </div>
          </div>
          <div class="percentage-text">
            Overall Attendance: <?php echo $percentage; ?>%
            <?php if ($percentage >= 80): ?>
              <span class="status-present">(Excellent)</span>
            <?php elseif ($percentage >= 60): ?>
              <span class="status-suspended">(Good)</span>
            <?php else: ?>
              <span class="status-absent">(Needs Improvement)</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <!-- Monthly Tab -->
      <div class="tab-content" id="monthly">
        <div class="attendance-section">
          <h3 class="section-title"><i class="fas fa-calendar-alt"></i> Monthly Attendance</h3>
          
          <?php if (isset($_GET['month']) && isset($_GET['year'])): ?>
            <a href="?">« Back to monthly overview</a>
            
            <h4 style="margin: 1.5rem 0; color: var(--primary);">Details for <?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?></h4>
            
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Subject</th>
                  <th>Status</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
                <?php if (isset($monthlyDetails) && $monthlyDetails->num_rows > 0): ?>
                  <?php while ($detail = $monthlyDetails->fetch_assoc()): ?>
                  <tr>
                    <td><?php echo date('M j, Y', strtotime($detail['date'])); ?></td>
                    <td><?php echo h($detail['subject']); ?></td>
                    <td class="
                      <?php 
                      if ($detail['status'] == 'P') echo 'status-present';
                      else if ($detail['status'] == 'A') echo 'status-absent';
                      else if ($detail['status'] == 'S') echo 'status-suspended';
                      else echo 'status-remaining';
                      ?>
                    "><?php echo $detail['status']; ?></td>
                    <td><?php echo h($detail['remarks']); ?></td>
                  </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                      <i class="fas fa-calendar-times" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                      No attendance records found for this month
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="month-cards">
              <?php if (is_object($monthlyResults) && $monthlyResults->num_rows > 0): ?>
                <?php while ($month = $monthlyResults->fetch_assoc()): ?>
                <div class="month-card" onclick="window.location='?month=<?php echo $month['month']; ?>&year=<?php echo $month['year']; ?>#monthly'">
                  <div class="month-card-header">
                    <div class="month-name"><?php echo date('F Y', mktime(0, 0, 0, $month['month'], 1, $month['year'])); ?></div>
                    <div class="
                      <?php 
                      $monthPercentage = $month['percentage'] ?? 0;
                      if ($monthPercentage >= 80) echo 'status-present';
                      else if ($monthPercentage >= 60) echo 'status-suspended';
                      else echo 'status-absent';
                      ?>
                    "><?php echo $monthPercentage; ?>%</div>
                  </div>
                  <div class="month-stats">
                    <div class="month-stat">
                      <div class="month-stat-value status-present"><?php echo $month['present_days']; ?></div>
                      <div class="month-stat-label">Present</div>
                    </div>
                    <div class="month-stat">
                      <div class="month-stat-value status-absent"><?php echo $month['absent_days']; ?></div>
                      <div class="month-stat-label">Absent</div>
                    </div>
                    <div class="month-stat">
                      <div class="month-stat-value status-suspended"><?php echo $month['suspended_days']; ?></div>
                      <div class="month-stat-label">Suspended</div>
                    </div>
                    <div class="month-stat">
                      <div class="month-stat-value status-remaining"><?php echo $month['remaining_days']; ?></div>
                      <div class="month-stat-label">Remaining</div>
                    </div>
                  </div>
                  <div class="progress-container" style="margin-top: 15px;">
                    <div class="progress-bar 
                      <?php 
                      if ($monthPercentage >= 80) echo 'good';
                      else if ($monthPercentage >= 60) echo 'warning';
                      else echo 'danger';
                      ?>" 
                      style="width: <?php echo $monthPercentage; ?>%">
                    </div>
                  </div>
                </div>
                <?php endwhile; ?>
              <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                  <i class="fas fa-chart-bar" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                  <h3 style="margin-bottom: 10px;">No Monthly Data Available</h3>
                  <p>Attendance records will appear here once available.</p>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Subjects Tab -->
      <div class="tab-content" id="subjects">
        <div class="attendance-section">
          <h3 class="section-title"><i class="fas fa-book"></i> Subject-wise Attendance</h3>
          
          <table class="subjects-table">
            <thead>
              <tr>
                <th>Subject</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Suspended</th>
                <th>Remaining</th>
                <th>Attendance %</th>
                <th>Progress</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($subjectRows)): ?>
                <?php foreach ($subjectRows as $row): ?>
                <?php $subjectPercentage = $row['percentage'] ?? 0; ?>
                <tr>
                  <td><strong><?php echo h($row['subject']); ?></strong></td>
                  <td class="status-present"><?php echo $row['present_days']; ?></td>
                  <td class="status-absent"><?php echo $row['absent_days']; ?></td>
                  <td class="status-suspended"><?php echo $row['suspended_days']; ?></td>
                  <td class="status-remaining"><?php echo $row['remaining_days']; ?></td>
                  <td class="
                    <?php 
                    if ($subjectPercentage >= 80) echo 'status-present';
                    else if ($subjectPercentage >= 60) echo 'status-suspended';
                    else echo 'status-absent';
                    ?>
                  "><?php echo $subjectPercentage; ?>%</td>
                  <td>
                    <div class="progress-container">
                      <div class="progress-bar 
                        <?php 
                        if ($subjectPercentage >= 80) echo 'good';
                        else if ($subjectPercentage >= 60) echo 'warning';
                        else echo 'danger';
                        ?>" 
                        style="width: <?php echo $subjectPercentage; ?>%">
                      </div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                    <i class="fas fa-book-open" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                    No subject-wise attendance data available
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>

          <div class="subject-mobile-list">
            <?php if (!empty($subjectRows)): ?>
              <?php foreach ($subjectRows as $row): ?>
              <?php $subjectPercentage = $row['percentage'] ?? 0; ?>
              <details class="subject-attendance-card" open>
                <summary class="subject-card-summary">
                  <span class="subject-card-name"><?php echo h($row['subject']); ?></span>
                  <i class="fas fa-chevron-down subject-card-chevron"></i>
                </summary>
                <div class="subject-card-body">
                  <div class="subject-card-details">
                    <div class="subject-detail-row">
                      <span class="subject-detail-label">Present</span>
                      <span class="subject-detail-value status-present"><?php echo $row['present_days']; ?></span>
                    </div>
                    <div class="subject-detail-row">
                      <span class="subject-detail-label">Absent</span>
                      <span class="subject-detail-value status-absent"><?php echo $row['absent_days']; ?></span>
                    </div>
                    <div class="subject-detail-row">
                      <span class="subject-detail-label">Suspended</span>
                      <span class="subject-detail-value status-suspended"><?php echo $row['suspended_days']; ?></span>
                    </div>
                    <div class="subject-detail-row">
                      <span class="subject-detail-label">Remaining</span>
                      <span class="subject-detail-value status-remaining"><?php echo $row['remaining_days']; ?></span>
                    </div>
                    <div class="subject-detail-row">
                      <span class="subject-detail-label">Attendance %</span>
                      <span class="subject-detail-value <?php if ($subjectPercentage >= 80) echo 'status-present'; else if ($subjectPercentage >= 60) echo 'status-suspended'; else echo 'status-absent'; ?>">
                        <?php echo $subjectPercentage; ?>%
                      </span>
                    </div>
                  </div>

                  <div class="subject-card-progress-title">Attendance Progress</div>
                  <div class="progress-container subject-card-progress">
                    <div class="progress-bar <?php if ($subjectPercentage >= 80) echo 'good'; else if ($subjectPercentage >= 60) echo 'warning'; else echo 'danger'; ?>" style="width: <?php echo $subjectPercentage; ?>%"></div>
                  </div>
                </div>
              </details>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="subject-mobile-empty">
                <i class="fas fa-book-open"></i>
                No subject-wise attendance data available
              </div>
            <?php endif; ?>
          </div>
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
      
      // Tab functionality
      const tabButtons = document.querySelectorAll('.tab-button');
      const tabContents = document.querySelectorAll('.tab-content');
      
      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          const tabId = button.getAttribute('data-tab');
          
          // Update active button
          tabButtons.forEach(btn => btn.classList.remove('active'));
          button.classList.add('active');
          
          // Show active tab content
          tabContents.forEach(content => content.classList.remove('active'));
          document.getElementById(tabId).classList.add('active');
          
          // Update URL hash
          window.location.hash = tabId;
        });
      });
      
      // Check URL hash on load
      if (window.location.hash) {
        const tabId = window.location.hash.substring(1);
        const tabButton = document.querySelector(`.tab-button[data-tab="${tabId}"]`);
        
        if (tabButton) {
          tabButton.click();
        }
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
      
      // Animate progress bars
      const progressBars = document.querySelectorAll('.progress-bar');
      progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => {
          bar.style.width = width;
        }, 300);
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
      
      document.querySelectorAll('.stat-card, .month-card, .attendance-section').forEach(card => {
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
