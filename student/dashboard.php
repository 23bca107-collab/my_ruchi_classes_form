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
} catch (Throwable $e) {
    error_log("Error fetching student: " . $e->getMessage());
}

if (!$student) {
    // If no student found, logout
    session_unset();
    session_destroy();
    die('
    <script>
        alert("Student profile not found.");
        window.location.href = "admission_form.html";
    </script>
    ');
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

function get_student_subjects(array $student): array {
    $storedSubjects = trim((string)($student['subjects'] ?? ''));
    if ($storedSubjects !== '') {
        $parsedSubjects = preg_split('/[\r\n,|]+/', $storedSubjects) ?: [];
        $subjects = [];

        foreach ($parsedSubjects as $subject) {
            $subject = trim($subject);
            if ($subject !== '' && !in_array($subject, $subjects, true)) {
                $subjects[] = $subject;
            }
        }

        if ($subjects !== []) {
            return $subjects;
        }
    }

    $studentClass = (int)($student['class'] ?? 0);
    if (in_array($studentClass, [8, 9, 10], true)) {
        return ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
    }

    if (in_array($studentClass, [11, 12], true)) {
        return ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];
    }

    return ["General Studies", "Language", "Mathematics", "Science"];
}

function get_attendance_summary(mysqli $conn, int $studentId): array {
    $summary = [
        'present_days' => 0,
        'absent_days' => 0,
        'suspended_days' => 0,
        'remaining_days' => 0,
        'percentage' => 0,
    ];

    if ($studentId <= 0) {
        return $summary;
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'attendance'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return $summary;
    }

    $sql = "
        SELECT
            COALESCE(SUM(status = 'P'), 0) AS present_days,
            COALESCE(SUM(status = 'A'), 0) AS absent_days,
            COALESCE(SUM(status = 'S'), 0) AS suspended_days,
            COALESCE(SUM(status = 'R'), 0) AS remaining_days,
            CASE
                WHEN COALESCE(SUM(status IN ('P', 'A')), 0) > 0
                    THEN ROUND((COALESCE(SUM(status = 'P'), 0) / COALESCE(SUM(status IN ('P', 'A')), 0)) * 100, 2)
                ELSE 0
            END AS percentage
        FROM attendance
        WHERE student_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $summary;
    }

    $stmt->bind_param('i', $studentId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $row = $result->fetch_assoc();
            if (is_array($row)) {
                $summary = array_merge($summary, $row);
            }
        }
    }

    return $summary;
}

function format_percentage($value): string {
    $formatted = number_format((float)$value, 2, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return ($formatted === '' ? '0' : $formatted) . '%';
}

function normalize_schedule_time(?string $timeValue): ?string {
    $timeValue = trim((string)$timeValue);
    if ($timeValue === '') {
        return null;
    }

    $timestamp = strtotime($timeValue);
    if ($timestamp === false) {
        return null;
    }

    return date('H:i:s', $timestamp);
}

function get_student_schedule(mysqli $conn, string $medium, string $class): array {
    $schedule = [];
    $medium = trim($medium);
    $class = trim($class);

    if ($medium === '' || $class === '') {
        return $schedule;
    }

    if (!schedule_table_exists($conn)) {
        return $schedule;
    }

    $scheduleColumns = schedule_table_columns($conn);
    $selectFields = ['day', 'subject', 'teacher', 'start_time', 'end_time'];
    if (isset($scheduleColumns['schedule_type'])) {
        $selectFields[] = 'schedule_type';
    }
    if (isset($scheduleColumns['expires_at'])) {
        $selectFields[] = 'expires_at';
    }

    $sql = "
        SELECT " . implode(', ', $selectFields) . "
        FROM schedule
        WHERE LOWER(medium) = LOWER(?) AND class = ?
        ORDER BY FIELD(day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), start_time
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $schedule;
    }

    $stmt->bind_param('ss', $medium, $class);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            if (schedule_is_visible($row)) {
                $schedule[] = $row;
            }
        }
    }

    return $schedule;
}

function get_upcoming_classes(array $schedule, int $limit = 4): array {
    $dayMap = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
        'Sunday' => 7,
    ];

    $timezone = new DateTimeZone('Asia/Kolkata');
    $now = new DateTimeImmutable('now', $timezone);
    $todayIndex = (int)$now->format('N');
    $upcomingClasses = [];

    foreach ($schedule as $classRow) {
        $dayName = ucfirst(strtolower(trim((string)($classRow['day'] ?? ''))));
        if (!isset($dayMap[$dayName])) {
            continue;
        }

        $startTime = normalize_schedule_time($classRow['start_time'] ?? null);
        $endTime = normalize_schedule_time($classRow['end_time'] ?? null) ?? $startTime;

        if ($startTime === null || $endTime === null) {
            continue;
        }

        [$startHour, $startMinute, $startSecond] = array_map('intval', explode(':', $startTime));
        [$endHour, $endMinute, $endSecond] = array_map('intval', explode(':', $endTime));

        $daysAhead = ($dayMap[$dayName] - $todayIndex + 7) % 7;
        $startDateTime = $now->modify(sprintf('+%d days', $daysAhead))->setTime($startHour, $startMinute, $startSecond);
        $endDateTime = $now->modify(sprintf('+%d days', $daysAhead))->setTime($endHour, $endMinute, $endSecond);
        $isLive = $daysAhead === 0 && $now >= $startDateTime && $now <= $endDateTime;

        if ($daysAhead === 0 && !$isLive && $startDateTime < $now) {
            $startDateTime = $startDateTime->modify('+7 days');
            $endDateTime = $endDateTime->modify('+7 days');
            $daysAhead = 7;
        }

        $upcomingClasses[] = [
            'sort_timestamp' => $isLive ? $now->getTimestamp() - 1 : $startDateTime->getTimestamp(),
            'day_label' => $isLive ? 'Live Now' : ($daysAhead === 0 ? 'Today' : ($daysAhead === 1 ? 'Tomorrow' : $dayName)),
            'date_label' => $startDateTime->format('d M'),
            'subject' => trim((string)($classRow['subject'] ?? '')) ?: 'Class',
            'teacher' => trim((string)($classRow['teacher'] ?? '')) ?: 'Teacher will be updated',
            'time_range' => date('h:i A', strtotime($startTime)) . ' - ' . date('h:i A', strtotime($endTime)),
            'is_live' => $isLive,
        ];
    }

    usort($upcomingClasses, static function (array $left, array $right): int {
        return $left['sort_timestamp'] <=> $right['sort_timestamp'];
    });

    return array_slice($upcomingClasses, 0, $limit);
}

$studentSubjects = get_student_subjects($student);
$activeCoursesCount = count($studentSubjects);
$attendanceSummary = get_attendance_summary($conn, (int)($student['id'] ?? 0));
$overallAttendanceDisplay = format_percentage($attendanceSummary['percentage'] ?? 0);
$scheduleRows = get_student_schedule($conn, (string)($student['medium'] ?? ''), (string)($student['class'] ?? ''));
$upcomingClasses = get_upcoming_classes($scheduleRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Dashboard | Ruchi Classes</title>
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
  overflow-x: clip;
  overscroll-behavior-x: none;
}

body {
  background: var(--main-bg);
  color: var(--text-primary);
  font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  line-height: 1.6;
  min-height: 100vh;
  width: 100%;
  max-width: 100%;
  overflow-x: clip;
  overscroll-behavior-x: none;
  position: relative;
  touch-action: pan-y;
}

img {
  max-width: 100%;
}

.dashboard,
.sidebar,
.main-content,
.header,
.card,
.profile,
.stats-grid,
.details-grid,
.detail-item,
.upcoming-classes-list,
.upcoming-class-item,
.user-menu,
.user-profile,
.section-header {
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
  width: 100%;
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

/* ---------------- PROFILE ---------------- */

.profile {
  display: flex;
  gap: 35px;
  align-items: center;
  margin-bottom: 2.5rem;
  padding-bottom: 2.5rem;
  border-bottom: 3px solid var(--border);
  min-width: 0;
}

.avatar {
  width: 160px;
  height: 160px;
  border-radius: 25px;
  overflow: hidden;
  border: 5px solid var(--primary);
  position: relative;
  flex-shrink: 0;
  box-shadow: 0 10px 30px rgba(37, 99, 235, 0.2);
}

.avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: all 0.4s ease;
}

.avatar:hover img {
  transform: scale(1.1);
}

.name {
  font-size: 36px;
  font-weight: 800;
  margin-bottom: 12px;
  color: var(--text-primary);
  line-height: 1.2;
  background: linear-gradient(135deg, var(--primary), var(--primary-dark));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  overflow-wrap: anywhere;
}

.sub {
  font-size: 18px;
  color: var(--text-secondary);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  overflow-wrap: anywhere;
}

.sub i {
  color: var(--primary);
  font-size: 16px;
}

/* ---------------- STATS GRID ---------------- */

.stats-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(280px, 1fr));
  gap: 25px;
  margin: 2.5rem auto;
  max-width: 980px;
}

.stat-card {
  background: var(--gradient-primary);
  padding: 30px;
  border-radius: 20px;
  text-align: center;
  color: white;
  box-shadow: 0 10px 30px rgba(37, 99, 235, 0.25);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
  min-height: 180px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
}

.stat-card:hover {
  transform: translateY(-8px) scale(1.03);
  box-shadow: 0 20px 40px rgba(37, 99, 235, 0.35);
}

.stat-card::before {
  content: '';
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: linear-gradient(45deg, transparent, rgba(255,255,255,0.15), transparent);
  transform: rotate(45deg);
  transition: 0.8s ease;
}

.stat-card:hover::before {
  transform: rotate(45deg) translate(50%, 50%);
}

.stat-card.accent {
  background: var(--gradient-accent);
}

.stat-card.success {
  background: linear-gradient(135deg, var(--success), #059669);
}

.stat-icon {
  font-size: 3.5rem;
  margin-bottom: 20px;
  opacity: 0.9;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
}

.stat-number {
  font-size: 3rem;
  font-weight: 800;
  margin-bottom: 10px;
  text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-label {
  font-size: 17px;
  opacity: 0.95;
  font-weight: 600;
  letter-spacing: 0.5px;
}

/* ---------------- DETAILS GRID ---------------- */

.details-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 25px;
  margin-top: 2.5rem;
}

.detail-item {
  padding: 25px;
  background: linear-gradient(135deg, var(--bg-secondary), var(--bg-card));
  border-radius: 16px;
  border-left: 5px solid var(--primary);
  border: 1px solid var(--border);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
}

.detail-item::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--gradient-primary);
  transform: scaleX(0);
  transition: 0.4s ease;
}

.detail-item:hover {
  transform: translateY(-5px);
  box-shadow: 0 12px 30px rgba(0,0,0,0.1);
  background: var(--bg-card);
}

.detail-item:hover::before {
  transform: scaleX(1);
}

.detail-label {
  font-size: 14px;
  color: var(--text-muted);
  margin-bottom: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.detail-value {
  font-size: 18px;
  font-weight: 600;
  color: var(--text-primary);
  line-height: 1.6;
  overflow-wrap: anywhere;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
}

.section-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-radius: 999px;
  text-decoration: none;
  color: white;
  background: var(--gradient-primary);
  font-weight: 600;
  font-size: 14px;
  box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
  transition: all 0.3s ease;
}

.section-link:hover {
  transform: translateY(-2px);
  box-shadow: 0 14px 24px rgba(37, 99, 235, 0.24);
}

.upcoming-classes-list {
  display: grid;
  gap: 16px;
}

.upcoming-class-item {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 18px;
  padding: 18px;
  border-radius: 18px;
  border: 1px solid var(--border);
  background: linear-gradient(135deg, var(--bg-secondary), var(--bg-card));
  transition: all 0.3s ease;
}

.upcoming-class-item:hover {
  transform: translateY(-4px);
  box-shadow: 0 14px 28px rgba(0,0,0,0.08);
}

.upcoming-class-badge {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 4px;
  min-height: 90px;
  border-radius: 16px;
  color: white;
  background: var(--gradient-primary);
  text-align: center;
  padding: 14px 12px;
  box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
}

.upcoming-class-badge.live {
  background: linear-gradient(135deg, var(--success), #059669);
}

.upcoming-class-day {
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.3px;
}

.upcoming-class-date {
  font-size: 13px;
  opacity: 0.95;
}

.upcoming-class-subject {
  font-size: 22px;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 10px;
  overflow-wrap: anywhere;
}

.upcoming-class-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 14px 20px;
  color: var(--text-secondary);
  font-size: 15px;
  min-width: 0;
}

.upcoming-class-meta span {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  overflow-wrap: anywhere;
}

.upcoming-class-meta i {
  color: var(--primary);
}

.upcoming-classes-note {
  margin-top: 16px;
  color: var(--text-muted);
  font-size: 14px;
}

.empty-card-text {
  color: var(--text-secondary);
  line-height: 1.7;
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
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes bounceIn {
  0% {
    opacity: 0;
    transform: scale(0.3);
  }
  50% {
    opacity: 0.9;
    transform: scale(1.05);
  }
  80% {
    opacity: 1;
    transform: scale(0.95);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}

@keyframes pulse {
  0% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.1);
    opacity: 0.8;
  }
  100% {
    transform: scale(1);
    opacity: 1;
  }
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-15px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes slideInLeft {
  from {
    transform: translateX(-100%);
  }
  to {
    transform: translateX(0);
  }
}

@keyframes logoResize {
  from {
    transform: scale(0.8);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
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
    width: min(320px, calc(var(--app-vw) - 16px));
    max-width: calc(var(--app-vw) - 16px);
  }
  
  .sidebar.active {
    transform: translateX(0);
    animation: slideInLeft 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .sidebar.active .logo-img {
    animation: logoResize 0.5s ease;
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
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .details-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .upcoming-class-item {
    grid-template-columns: 1fr;
  }

  .upcoming-class-badge {
    min-height: auto;
  }
  
  /* Mobile dropdown improvements */
  .dropdown-menu {
    position: static !important;
    margin-left: 15px !important;
    margin-top: 10px !important;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
    border: 1px solid var(--border) !important;
    background: white !important;
    width: calc(100% - 30px);
  }
  
  .dropdown-item {
    padding: 18px 20px !important;
    border-bottom: 1px solid #eee !important;
    font-size: 15px !important;
    min-height: 50px;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
  }
  
  .dropdown-item:last-child {
    border-bottom: none !important;
  }
  
  .dropdown-item:hover {
    transform: none !important;
    background: var(--primary-light) !important;
    color: white !important;
  }
  
  .dropdown-item i {
    font-size: 16px !important;
    width: 24px !important;
    text-align: center !important;
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
  
  .profile {
    flex-direction: column;
    text-align: center;
    gap: 25px;
    padding-bottom: 2rem;
    margin-bottom: 2rem;
  }
  
  .avatar {
    width: 140px;
    height: 140px;
    border-radius: 20px;
  }
  
  .name {
    font-size: 28px;
  }
  
  .sub {
    font-size: 16px;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
    gap: 20px;
    margin: 2rem 0;
  }
  
  .stat-card {
    padding: 25px;
    min-height: 160px;
  }
  
  .stat-icon {
    font-size: 3rem;
  }
  
  .stat-number {
    font-size: 2.5rem;
  }
  
  .details-grid {
    grid-template-columns: 1fr;
    gap: 20px;
    margin-top: 2rem;
  }
  
  .detail-item {
    padding: 20px;
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
  
  .user-name {
    display: none;
  }
  
  .user-profile {
    padding: 8px 12px;
    gap: 10px;
  }

  .user-name {
    max-width: 80px;
  }

  .card {
    padding: 2rem;
  }

  .upcoming-class-subject {
    font-size: 20px;
  }

  .upcoming-class-meta {
    font-size: 14px;
    gap: 12px;
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

  .user-profile {
    padding: 6px 10px;
  }

  .user-name {
    max-width: 60px;
    font-size: 13px;
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

  .section-link {
    width: 100%;
    justify-content: center;
  }

  .upcoming-class-item {
    padding: 16px;
  }

  .upcoming-class-subject {
    font-size: 18px;
  }
  
  .name {
    font-size: 24px;
  }
  
  .sub {
    font-size: 14px;
  }
  
  .stat-card {
    padding: 20px;
    min-height: 140px;
  }
  
  .stat-icon {
    font-size: 2.5rem;
  }
  
  .stat-number {
    font-size: 2.2rem;
  }
  
  .stat-label {
    font-size: 15px;
  }
  
  .detail-item {
    padding: 18px;
  }
  
  .detail-label {
    font-size: 13px;
  }
  
  .detail-value {
    font-size: 16px;
  }
  
  .card {
    padding: 1.5rem;
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
  .internet-error {
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
      <a href="../student/dashboard.php" class="nav-item active">
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
        <a href="student_exams.php" class="dropdown-item">
          <i class="fas fa-list-alt"></i> Exam List
        </a>
        <a href="student_marks.php" class="dropdown-item">
          <i class="fas fa-chart-bar"></i> Marks
        </a>
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

      <a href="logout.php" class="nav-item">
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

      <!-- Profile Card -->
      <div class="card">
        <div class="profile">
          <div class="avatar">
            <img src="<?php echo h($photoWeb); ?>" alt="Profile"
              onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&background=1a56db&color=fff'">
          </div>
          <div>
            <div class="name">
              <?php echo h($student['first_name'] . ' ' . $student['last_name']); ?>
            </div>
            <div class="sub">
              <i class="fas fa-graduation-cap"></i>
              Class <?php echo h($student['class']); ?> - <?php echo h($student['medium']); ?> Medium
            </div>
            <div class="sub">
              <i class="fas fa-id-card"></i>
              Student ID: STU<?php echo h($student['id'] ?? '12345'); ?>
            </div>
          </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-book-open"></i></div>
            <div class="stat-number"><?php echo h((string)$activeCoursesCount); ?></div>
            <div class="stat-label">Active Courses</div>
          </div>
          <div class="stat-card accent">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-number"><?php echo h($overallAttendanceDisplay); ?></div>
            <div class="stat-label">Attendance</div>
          </div>
        </div>

        <!-- Additional Information -->
        <div class="details-grid">
          <div class="detail-item">
            <div class="detail-label">FATHER'S NAME</div>
            <div class="detail-value"><?php echo h($student['father_name']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">MOTHER'S NAME</div>
            <div class="detail-value"><?php echo h($student['mother_name']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">DATE OF BIRTH</div>
            <div class="detail-value"><?php echo h($student['dob']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">GENDER</div>
            <div class="detail-value"><?php echo h($student['gender']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">SCHOOL</div>
            <div class="detail-value"><?php echo h($student['school']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">EDUCATION BOARD</div>
            <div class="detail-value"><?php echo h($student['board']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">PARENT MOBILE</div>
            <div class="detail-value"><?php echo h($student['parent_mobile']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">PERSONAL MOBILE</div>
            <div class="detail-value"><?php echo h($student['personal_mobile']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">WHATSAPP NUMBER</div>
            <div class="detail-value"><?php echo h($student['whatsapp']); ?></div>
          </div>
          <div class="detail-item">
            <div class="detail-label">ADDRESS</div>
            <div class="detail-value"><?php echo h($student['address']); ?></div>
          </div>
        </div>
      </div>

      <!-- Additional Cards -->
      <div class="card">
        <h2 style="display: flex; align-items: center; gap: 12px; margin-bottom: 1rem;">
          <i class="fas fa-chart-line" style="color: var(--primary-light);"></i>
          Academic Performance
        </h2>
        <p style="color: var(--text-secondary);">Your progress and performance metrics will be displayed here with detailed analytics and insights.</p>
      </div>
      
      <div class="card">
        <div class="section-header">
          <h2 class="section-title">
            <i class="fas fa-calendar-alt" style="color: var(--accent);"></i>
            Upcoming Classes
          </h2>
          <a href="view_schedule.php" class="section-link">
            <i class="fas fa-table"></i>
            View Full Time Table
          </a>
        </div>

        <?php if ($upcomingClasses !== []): ?>
          <div class="upcoming-classes-list">
            <?php foreach ($upcomingClasses as $classItem): ?>
              <div class="upcoming-class-item">
                <div class="upcoming-class-badge<?php echo !empty($classItem['is_live']) ? ' live' : ''; ?>">
                  <div class="upcoming-class-day"><?php echo h($classItem['day_label']); ?></div>
                  <div class="upcoming-class-date"><?php echo h($classItem['date_label']); ?></div>
                </div>
                <div>
                  <div class="upcoming-class-subject"><?php echo h($classItem['subject']); ?></div>
                  <div class="upcoming-class-meta">
                    <span>
                      <i class="fas fa-clock"></i>
                      <?php echo h($classItem['time_range']); ?>
                    </span>
                    <span>
                      <i class="fas fa-user"></i>
                      <?php echo h($classItem['teacher']); ?>
                    </span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="upcoming-classes-note">Showing your next upcoming classes from the live timetable.</p>
        <?php else: ?>
          <p class="empty-card-text">No classes are scheduled yet for your class and medium. As soon as the timetable is added in <a href="view_schedule.php" style="color: var(--primary); font-weight: 600; text-decoration: none;">View Schedule</a>, it will appear here automatically.</p>
        <?php endif; ?>
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
      
      // ==================== SIDEBAR TOGGLE FUNCTION ====================
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
      
      // ==================== DROPDOWN FUNCTIONALITY ====================
      // Simple dropdown toggle - just toggle the 'open' class
      document.querySelectorAll('.dropdown').forEach(drop => {
        drop.addEventListener('click', function(e) {
          e.stopPropagation();
          this.classList.toggle('open');
        });
      });
      
      // Close dropdowns when clicking outside
      document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
          document.querySelectorAll('.dropdown').forEach(drop => {
            drop.classList.remove('open');
          });
        }
      });
      
      // Close dropdowns when pressing Escape key
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.dropdown').forEach(drop => {
            drop.classList.remove('open');
          });
        }
      });
      
      // ==================== MOBILE SIDEBAR BEHAVIOR ====================
      if (window.innerWidth < 1025) {
        // Close sidebar when clicking any link (mobile only)
        document.querySelectorAll('.nav-item:not(.dropdown), .dropdown-item').forEach(link => {
          link.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            setPageScrollLock(false);
            
            // Close dropdowns when sidebar closes (optional)
            document.querySelectorAll('.dropdown').forEach(drop => {
              drop.classList.remove('open');
            });
          });
        });
      }
      
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
      
      document.querySelectorAll('.card').forEach(card => {
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
      
      // ==================== WINDOW RESIZE HANDLER ====================
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
          
          // Close dropdowns on resize
          document.querySelectorAll('.dropdown').forEach(drop => {
            drop.classList.remove('open');
          });
        }, 250);
      });
      
      // ==================== INITIAL LOAD STATE ====================
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
    });
  </script>
  <?php student_notifications_render_script($student_notifications_data); ?>
</body>
</html>
