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

// Get class and medium from student data
$studentClass = $student['class'] ?? '';
$studentMedium = $student['medium'] ?? '';
$studentName = $student['first_name'] . ' ' . $student['last_name'];
$student_notifications_data = student_notifications_prepare($conn, $student, 12);

// Subjects based on class
$subjects = [];

if (in_array($studentClass, [8, 9, 10])) {
    $subjects = ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
} elseif (in_array($studentClass, [11, 12])) {
    $subjects = ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];
} else {
    $subjects = ["General Studies", "Language", "Mathematics", "Science"];
}

// Icons for each subject
$subjectIcons = [
    "Maths" => "fas fa-calculator",
    "Science" => "fas fa-flask",
    "Hindi" => "fas fa-language",
    "English" => "fas fa-book",
    "Gujarati" => "fas fa-pen-nib",
    "Social Science" => "fas fa-globe-asia",
    "Accounts" => "fas fa-file-invoice-dollar",
    "Statistics" => "fas fa-chart-bar",
    "Economics" => "fas fa-chart-line",
    "Secretarial Practice" => "fas fa-briefcase",
    "Business Studies" => "fas fa-building",
    "General Studies" => "fas fa-graduation-cap",
    "Language" => "fas fa-comments",
    "Mathematics" => "fas fa-square-root-alt"
];

// Subject descriptions
$subjectDescriptions = [
    "Maths" => "Master mathematical concepts and problem-solving techniques",
    "Science" => "Explore physics, chemistry, and biology with practical experiments",
    "Hindi" => "Improve reading, writing, and comprehension skills",
    "English" => "Enhance grammar, vocabulary, and communication skills",
    "Gujarati" => "Learn regional language with cultural context",
    "Social Science" => "Understand history, geography, and civics",
    "Accounts" => "Learn accounting principles and financial management",
    "Statistics" => "Master data analysis and statistical methods",
    "Economics" => "Study market trends and economic theories",
    "Secretarial Practice" => "Learn business communication and office management",
    "Business Studies" => "Understand business operations and management",
    "General Studies" => "Broad knowledge across multiple disciplines",
    "Language" => "Develop language proficiency and communication",
    "Mathematics" => "Build strong foundation in mathematical concepts"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Subjects | Ruchi Classes</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
:root {
  --app-vw: 100vw;
  --app-vh: 1vh;
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

html {
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
}

body {
  background: var(--main-bg);
  color: var(--text-primary);
  font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  line-height: 1.6;
  min-height: 100vh;
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
  overscroll-behavior-x: none;
}

img {
  max-width: 100%;
}

.dashboard,
.sidebar,
.main-content,
.header,
.card,
.subjects-header,
.subjects-grid,
.subject-card,
.info-card,
.user-menu,
.user-profile,
.class-badge {
  max-width: 100%;
  min-width: 0;
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
  height: calc(var(--app-vh) * 100);
  max-width: 100vw;
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
  width: calc(100% - 280px);
  margin-left: 280px;
  padding: 2rem;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  background: var(--main-bg);
  position: relative;
  min-height: 100vh;
  overflow-x: hidden;
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
  flex-wrap: wrap;
  justify-content: flex-end;
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

/* ---------------- CARDS ---------------- */
.card {
  background: var(--card-bg);
  border-radius: 20px;
  padding: 2.5rem;
  margin-bottom: 2rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  animation: fadeIn 0.5s ease-out;
}

.card:hover {
  transform: translateY(-8px);
  box-shadow: 0 25px 50px rgba(0,0,0,0.15);
}

/* ---------------- SUBJECTS HEADER ---------------- */
.subjects-header {
  text-align: center;
  margin-bottom: 3rem;
  padding-bottom: 2rem;
  border-bottom: 3px solid var(--border);
}

.subjects-header h1 {
  font-size: 36px;
  font-weight: 800;
  margin-bottom: 15px;
  background: linear-gradient(135deg, var(--primary), var(--primary-dark));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  overflow-wrap: anywhere;
}

.welcome-text {
  font-size: 18px;
  color: var(--text-secondary);
  margin-bottom: 20px;
  overflow-wrap: anywhere;
}

.class-badge {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  background: var(--gradient-primary);
  color: white;
  padding: 12px 24px;
  border-radius: 50px;
  font-weight: 600;
  box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25);
  flex-wrap: wrap;
  justify-content: center;
}

/* ---------------- SUBJECTS GRID ---------------- */
.subjects-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
  gap: 25px;
  margin-top: 2rem;
}

.subject-card {
  background: linear-gradient(135deg, var(--bg-secondary), var(--bg-card));
  border-radius: 18px;
  padding: 2rem;
  border: 2px solid var(--border);
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
  cursor: pointer;
  text-align: center;
}

.subject-card:hover {
  transform: translateY(-10px) scale(1.02);
  box-shadow: 0 20px 40px rgba(0,0,0,0.15);
  border-color: var(--primary);
}

.subject-card::before {
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

.subject-card:hover::before {
  transform: scaleX(1);
}

.subject-icon {
  font-size: 48px;
  color: var(--primary);
  margin-bottom: 20px;
  transition: all 0.3s ease;
  display: block;
}

.subject-card:hover .subject-icon {
  transform: scale(1.2) rotate(5deg);
  color: var(--primary-dark);
}

.subject-name {
  font-size: 22px;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 15px;
  overflow-wrap: anywhere;
}

.subject-description {
  color: var(--text-secondary);
  line-height: 1.6;
  font-size: 15px;
  overflow-wrap: anywhere;
}

/* ---------------- INFO CARD ---------------- */
.info-card {
  background: linear-gradient(135deg, var(--primary-light), var(--primary));
  color: white;
  border-radius: 18px;
  padding: 2rem;
  margin-top: 3rem;
  box-shadow: 0 15px 35px rgba(37, 99, 235, 0.25);
}

.info-card h3 {
  font-size: 22px;
  margin-bottom: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.info-card p {
  line-height: 1.7;
  opacity: 0.9;
  overflow-wrap: anywhere;
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
  .sidebar {
    transform: translateX(-100%);
    z-index: 1000;
    box-shadow: 0 0 50px rgba(0, 0, 0, 0.2);
    width: min(320px, calc(var(--app-vw) - 16px));
    max-width: calc(var(--app-vw) - 16px);
  }
  
  .sidebar.active {
    transform: translateX(0);
    animation: slideInLeft 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .main-content {
    width: 100%;
    margin-left: 0;
    padding: 1.5rem;
  }
  
  .header {
    padding: 1.2rem;
    margin-bottom: 1.5rem;
    gap: 14px;
  }
  
  .subjects-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
  }
  
  .subject-card {
    padding: 1.5rem;
  }
}

/* Mobile */
@media (max-width: 768px) {
  .main-content {
    padding: 1rem;
  }
  
  .header {
    flex-wrap: wrap;
    justify-content: space-between;
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 14px;
  }
  
  .card {
    padding: 2rem;
  }
  
  .subjects-header h1 {
    font-size: 28px;
  }
  
  .welcome-text {
    font-size: 16px;
  }
  
  .subjects-grid {
    grid-template-columns: 1fr;
    gap: 20px;
  }
  
  .subject-name {
    font-size: 20px;
  }
  
  .logo-img {
    width: 75px;
    height: 75px;
  }
  
  .logo-text {
    font-size: 22px;
  }
  
  .user-menu {
    width: 100%;
    gap: 15px;
  }

  .user-profile {
    padding: 8px 12px;
    gap: 10px;
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

  .user-menu {
    gap: 10px;
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
  
  .subjects-header h1 {
    font-size: 24px;
  }
  
  .class-badge {
    padding: 10px 20px;
    font-size: 14px;
  }
  
  .card {
    padding: 1.5rem;
  }
  
  .subject-card {
    padding: 1.25rem;
  }
  
  .subject-icon {
    font-size: 40px;
  }
  
  .subject-name {
    font-size: 18px;
  }
  
  .subject-description {
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

  .sidebar {
    width: calc(var(--app-vw) - 12px);
    max-width: calc(var(--app-vw) - 12px);
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
  
  .card {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    break-inside: avoid;
  }
  
  body {
    background: white !important;
    color: black !important;
  }
  
  .subject-card {
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
      <a href="subject.php" class="nav-item active">
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
            <img src="<?php echo htmlspecialchars($photoWeb); ?>" alt="Profile" class="user-avatar"
              onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&background=1a56db&color=fff'">
            <div class="user-name"><?php echo htmlspecialchars($student['first_name']); ?></div>
          </div>
        </div>
      </div>

      <!-- Subjects Card -->
      <div class="card">
        <div class="subjects-header">
          <h1>Your Courses</h1>
          <p class="welcome-text">Welcome, <strong><?php echo htmlspecialchars($studentName); ?></strong>! Explore your enrolled subjects.</p>
          <div class="class-badge">
            <i class="fas fa-graduation-cap"></i>
            Class <?php echo htmlspecialchars($studentClass); ?> | <?php echo ucfirst(htmlspecialchars($studentMedium)); ?> Medium
          </div>
        </div>

        <!-- Subjects Grid -->
        <div class="subjects-grid">
          <?php foreach ($subjects as $subject): 
            $icon = isset($subjectIcons[$subject]) ? $subjectIcons[$subject] : 'fas fa-book';
            $description = isset($subjectDescriptions[$subject]) ? $subjectDescriptions[$subject] : "Course content and materials for $subject";
          ?>
            <div class="subject-card" style="animation: fadeInUp 0.6s ease-out <?php echo (array_search($subject, $subjects) * 0.1) + 0.2; ?>s forwards;">
              <div class="subject-icon">
                <i class="<?php echo $icon; ?>"></i>
              </div>
              <h3 class="subject-name"><?php echo $subject; ?></h3>
              <p class="subject-description"><?php echo $description; ?></p>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Information Card -->
        <div class="info-card">
          <h3><i class="fas fa-info-circle"></i> Course Information</h3>
          <p>
            All your enrolled subjects are displayed above. These are the courses available for your class and medium. 
            Detailed course materials, schedules, and resources will be accessible through your dashboard as they become available.
          </p>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const root = document.documentElement;
      const toggleBtn = document.getElementById('toggleSidebar');
      const toggleIcon = document.getElementById('toggleIcon');
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      const sidebarOverlay = document.getElementById('sidebarOverlay');
      const internetError = document.getElementById('internetError');
      const reconnectBtn = document.getElementById('reconnectBtn');
      const logoImg = document.getElementById('logoImg');
      const logoText = document.getElementById('logoText');

      function syncViewportSize() {
        const viewport = window.visualViewport;
        const viewportWidth = viewport ? viewport.width : window.innerWidth;
        const viewportHeight = viewport ? viewport.height : window.innerHeight;

        root.style.setProperty('--app-vw', `${viewportWidth}px`);
        root.style.setProperty('--app-vh', `${viewportHeight / 100}px`);
      }

      function setPageScrollLock(shouldLock) {
        root.style.overflowX = 'hidden';
        document.body.style.overflowX = 'hidden';
        root.style.overflow = shouldLock ? 'hidden' : '';
        document.body.style.overflow = shouldLock ? 'hidden' : '';
      }
      
      // Check internet connection
      function checkInternetConnection() {
        if (!navigator.onLine) {
          internetError.classList.add('show');
        } else {
          internetError.classList.remove('show');
        }
      }
      
      // Initial check
      syncViewportSize();
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
          setPageScrollLock(sidebar.classList.contains('active'));
          
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
        setPageScrollLock(false);
      });
      
      // Close sidebar when clicking on mobile links (but NOT on dropdown trigger)
      if (window.innerWidth < 1025) {
        document.querySelectorAll('.nav-item:not(.dropdown), .dropdown-item').forEach(link => {
          link.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            setPageScrollLock(false);
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
      
      // Add click effect to subject cards
      document.querySelectorAll('.subject-card').forEach(card => {
        card.addEventListener('click', function() {
          this.style.transform = 'scale(0.98)';
          setTimeout(() => {
            this.style.transform = 'translateY(-10px) scale(1.02)';
          }, 150);
        });
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
          syncViewportSize();

          if (window.innerWidth >= 1025) {
            // Desktop - ensure sidebar is not in mobile active state
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            setPageScrollLock(false);
            
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

          if (window.innerWidth < 1025 && !sidebar.classList.contains('active')) {
            setPageScrollLock(false);
          }
        }, 250);
      });
      
      function setInitialState() {
        syncViewportSize();

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

        setPageScrollLock(window.innerWidth < 1025 && sidebar.classList.contains('active'));
      }

      setInitialState();

      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncViewportSize);
      }

      window.addEventListener('orientationchange', syncViewportSize);
      
      // Subject card click
      document.querySelectorAll('.subject-card').forEach(card => {
        card.addEventListener('click', function() {
          const subjectName = this.querySelector('.subject-name').textContent;
          showToast(`${subjectName} course details will be available soon`, 'info');
        });
      });
    });
  </script>
  <?php student_notifications_render_script($student_notifications_data); ?>
</body>
</html>
