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
} catch (Throwable $e) {
    error_log("Error fetching student: " . $e->getMessage());
}

if (!$student) {
    echo "<script>alert('Profile not found. Please complete admission form.'); window.location='../profile_setup.php';</script>";
    exit;
}

// ---------------- PHOTO PATH ----------------
function resolve_photo_path(string $rawPath): string {
    if (!$rawPath) return '../assets/img/avatar-placeholder.png';

    if (strpos($rawPath, 'student/uploads/') === 0) {
        return '../' . $rawPath;
    }

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

function is_ajax_request(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function cleanup_video_watch_unlocks(): void {
    $ttl = 6 * 60 * 60;
    $currentTime = time();
    $storedUnlocks = $_SESSION['video_watch_unlocks'] ?? [];

    if (!is_array($storedUnlocks) || $storedUnlocks === []) {
        $_SESSION['video_watch_unlocks'] = [];
        return;
    }

    foreach ($storedUnlocks as $videoId => $unlockedAt) {
        if (!is_numeric($unlockedAt) || ((int)$unlockedAt + $ttl) < $currentTime) {
            unset($storedUnlocks[$videoId]);
        }
    }

    $_SESSION['video_watch_unlocks'] = $storedUnlocks;
}

function is_video_watch_unlocked(int $videoId): bool {
    cleanup_video_watch_unlocks();
    return isset($_SESSION['video_watch_unlocks'][(string)$videoId]);
}

function unlock_video_watch(int $videoId): void {
    cleanup_video_watch_unlocks();
    $_SESSION['video_watch_unlocks'][(string)$videoId] = time();
}

function clear_video_watch_unlock(int $videoId): void {
    cleanup_video_watch_unlocks();
    unset($_SESSION['video_watch_unlocks'][(string)$videoId]);
}

function get_video_progress_stats(
    mysqli $conn,
    int $studentId,
    string $classFilter,
    string $mediumFilter,
    string $subjectFilter
): array {
    $stats = [
        'total_videos' => 0,
        'watched_videos' => 0,
        'progress_percentage' => 0,
    ];

    $tableCheck = $conn->query("SHOW TABLES LIKE 'youtube_videos'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return $stats;
    }

    $sql = "SELECT
                COUNT(*) AS total_videos,
                COALESCE(SUM(CASE WHEN h.video_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS watched_videos
            FROM youtube_videos v
            LEFT JOIN (
                SELECT DISTINCT video_id
                FROM youtube_history
                WHERE student_id = ?
            ) h ON h.video_id = v.id
            WHERE v.is_active = 1";

    $params = [$studentId];
    $types = 'i';

    if ($classFilter !== '') {
        $sql .= " AND v.class_name = ?";
        $params[] = $classFilter;
        $types .= 's';
    }

    if ($mediumFilter !== '' && $mediumFilter !== 'both') {
        $sql .= " AND (v.medium = ? OR v.medium = 'both')";
        $params[] = $mediumFilter;
        $types .= 's';
    }

    if ($subjectFilter !== '') {
        $sql .= " AND v.subject = ?";
        $params[] = $subjectFilter;
        $types .= 's';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $stats;
    }

    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $row = $result->fetch_assoc();
            if (is_array($row)) {
                $stats['total_videos'] = (int)($row['total_videos'] ?? 0);
                $stats['watched_videos'] = (int)($row['watched_videos'] ?? 0);
            }
        }
    }

    $stmt->close();

    if ($stats['total_videos'] > 0) {
        $stats['progress_percentage'] = (int)round(($stats['watched_videos'] / $stats['total_videos']) * 100);
    }

    return $stats;
}

$student_id = $student['id'] ?? '';
$student_name = $student['first_name'] . ' ' . $student['last_name'];
$student_medium = strtolower($student['medium'] ?? '');
$class = $student['class'] ?? '';

// ---------------- FETCH VIDEOS ----------------
$videos = [];
$total_watched = 0;
$total_videos = 0;

// Get filter parameters
$class_filter = $_GET['class'] ?? $class;
$medium_filter = $_GET['medium'] ?? $student_medium;
$subject_filter = $_GET['subject'] ?? '';

if (isset($_GET['unlock_watch']) && is_numeric($_GET['unlock_watch'])) {
    $video_id = (int)$_GET['unlock_watch'];
    unlock_video_watch($video_id);

    if (is_ajax_request()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => true,
            'message' => 'Video opened. Mark as Watched is now unlocked.',
            'video_id' => $video_id,
        ]);
        exit;
    }
}

// Handle video watch tracking
if (isset($_GET['watch']) && is_numeric($_GET['watch'])) {
    $video_id = (int)$_GET['watch'];

    try {
        $alreadyWatched = false;

        $check_sql = "SELECT id FROM youtube_history WHERE video_id = ? AND student_id = ?";
        $check_stmt = $conn->prepare($check_sql);

        if ($check_stmt) {
            $check_stmt->bind_param('ii', $video_id, $student_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $alreadyWatched = $check_result && $check_result->num_rows > 0;
            $check_stmt->close();
        }

        if (!$alreadyWatched && !is_video_watch_unlocked($video_id)) {
            if (is_ajax_request()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Pehle video open karo, phir Mark as Watched use karo.',
                ]);
                exit;
            }

            header('Location: student_videos.php');
            exit;
        }

        if (!$alreadyWatched) {
            $insert_sql = "INSERT INTO youtube_history (video_id, student_id, viewed_at) VALUES (?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);

            if ($insert_stmt) {
                $insert_stmt->bind_param('ii', $video_id, $student_id);
                $insert_stmt->execute();
                $insert_stmt->close();
            }

            clear_video_watch_unlock($video_id);
        }

        $stats = get_video_progress_stats(
            $conn,
            (int)$student_id,
            (string)$class_filter,
            (string)$medium_filter,
            (string)$subject_filter
        );

        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'already_watched' => $alreadyWatched,
                'message' => $alreadyWatched
                    ? 'Video already counted in your progress.'
                    : 'Video marked as watched successfully.',
                'watched_at_label' => date('M d, Y'),
                'stats' => $stats,
            ]);
            exit;
        }

        $redirectParams = [];
        if ($class_filter !== '') {
            $redirectParams['class'] = $class_filter;
        }
        if ($medium_filter !== '') {
            $redirectParams['medium'] = $medium_filter;
        }
        if ($subject_filter !== '') {
            $redirectParams['subject'] = $subject_filter;
        }

        $redirectUrl = 'student_videos.php';
        if ($redirectParams !== []) {
            $redirectUrl .= '?' . http_build_query($redirectParams);
        }

        header('Location: ' . $redirectUrl);
        exit;
    } catch (Exception $e) {
        error_log("Error tracking video view: " . $e->getMessage());

        if (is_ajax_request()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update watch progress.',
            ]);
            exit;
        }
    }
}

// Get all subjects for filter
$subjects_sql = "SELECT DISTINCT subject FROM youtube_videos WHERE subject IS NOT NULL AND subject != '' ORDER BY subject";
$subjects_result = $conn->query($subjects_sql);

try {
    // Check if youtube_videos table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'youtube_videos'");
    
    if ($table_check && $table_check->num_rows > 0) {
        // Table exists, fetch videos
        $sql = "SELECT v.*, 
                CASE WHEN h.id IS NOT NULL THEN 1 ELSE 0 END as watched,
                h.viewed_at as last_watched
                FROM youtube_videos v
                LEFT JOIN youtube_history h ON v.id = h.video_id AND h.student_id = ?
                WHERE v.is_active = 1";
        
        $params = [$student_id];
        $types = 'i';
        
        if (!empty($class_filter)) {
            $sql .= " AND v.class_name = ?";
            $params[] = $class_filter;
            $types .= 's';
        }
        
        if (!empty($medium_filter) && $medium_filter !== 'both') {
            $sql .= " AND (v.medium = ? OR v.medium = 'both')";
            $params[] = $medium_filter;
            $types .= 's';
        }
        
        if (!empty($subject_filter)) {
            $sql .= " AND v.subject = ?";
            $params[] = $subject_filter;
            $types .= 's';
        }
        
        $sql .= " ORDER BY v.class_name, v.subject, v.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $row['watch_unlocked'] = !empty($row['watched']) || is_video_watch_unlocked((int)$row['id']);
                    $videos[] = $row;
                }
            }
            $stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error fetching videos: " . $e->getMessage());
}

$video_stats = get_video_progress_stats(
    $conn,
    (int)$student_id,
    (string)$class_filter,
    (string)$medium_filter,
    (string)$subject_filter
);
$total_videos = $video_stats['total_videos'];
$total_watched = $video_stats['watched_videos'];
$progress_percentage = $video_stats['progress_percentage'];
$student_notifications_data = student_notifications_prepare($conn, $student, 12);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Video Lectures | Ruchi Classes</title>
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
  --gradient-success: linear-gradient(135deg, var(--success) 0%, #059669 100%);

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

.notification-badge.hidden {
  display: none;
}

.notification-panel {
  position: absolute;
  top: calc(100% + 14px);
  right: 0;
  width: 380px;
  max-width: min(380px, calc(100vw - 32px));
  background: rgba(255, 255, 255, 0.98);
  border: 1px solid var(--border);
  border-radius: 20px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(16px);
  overflow: hidden;
  opacity: 0;
  pointer-events: none;
  transform: translateY(12px);
  transition: all 0.25s ease;
  z-index: 1200;
}

.notifications.open .notification-panel {
  opacity: 1;
  pointer-events: auto;
  transform: translateY(0);
}

.notification-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 16px 18px 14px;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(245, 158, 11, 0.08));
}

.notification-panel-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 10px;
}

.notification-panel-subtitle {
  font-size: 12px;
  color: var(--text-muted);
}

.notification-list {
  max-height: 420px;
  overflow-y: auto;
}

.notification-item {
  display: flex;
  gap: 14px;
  padding: 16px 18px;
  text-decoration: none;
  color: inherit;
  border-bottom: 1px solid rgba(226, 232, 240, 0.8);
  transition: all 0.25s ease;
  background: transparent;
}

.notification-item:last-child {
  border-bottom: none;
}

.notification-item:hover {
  background: rgba(37, 99, 235, 0.05);
}

.notification-item.unread {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.03));
}

.notification-item.unread:hover {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.05));
}

.notification-icon-wrap {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 17px;
}

.notification-icon-wrap.theme-primary {
  background: rgba(37, 99, 235, 0.12);
  color: var(--primary);
}

.notification-icon-wrap.theme-success {
  background: rgba(16, 185, 129, 0.12);
  color: var(--success);
}

.notification-icon-wrap.theme-warning {
  background: rgba(245, 158, 11, 0.14);
  color: #b45309;
}

.notification-icon-wrap.theme-danger {
  background: rgba(239, 68, 68, 0.12);
  color: var(--danger);
}

.notification-icon-wrap.theme-info {
  background: rgba(6, 182, 212, 0.12);
  color: var(--info);
}

.notification-content {
  min-width: 0;
  flex: 1;
}

.notification-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 6px;
}

.notification-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.4;
}

.notification-time {
  font-size: 12px;
  color: var(--text-muted);
  white-space: nowrap;
}

.notification-message {
  font-size: 13px;
  color: var(--text-secondary);
  line-height: 1.55;
  word-break: break-word;
}

.notification-empty {
  padding: 30px 22px;
  text-align: center;
  color: var(--text-muted);
}

.notification-empty i {
  font-size: 34px;
  margin-bottom: 12px;
  opacity: 0.65;
}

body.notifications-open {
  overflow: hidden;
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
  text-decoration: none;
  color: inherit;
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

/* ---------------- STATS GRID ---------------- */

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 25px;
  margin: 2.5rem 0;
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
  background: var(--gradient-success);
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

/* ---------------- FILTERS ---------------- */
.filters-container {
  background: var(--bg-secondary);
  border-radius: 16px;
  padding: 25px;
  margin-bottom: 25px;
  border: 1px solid var(--border);
}

.filter-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-label {
  font-size: 14px;
  color: var(--text-secondary);
  font-weight: 600;
}

.form-select {
  padding: 12px 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  font-size: 14px;
  color: var(--text-primary);
  background: var(--bg-primary);
  transition: all 0.3s;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23475569' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 16px center;
  background-size: 12px;
  padding-right: 40px;
}

.form-select:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.filter-actions {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.btn {
  padding: 12px 24px;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  display: flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
}

.btn-primary {
  background: var(--gradient-primary);
  color: white;
}

.btn-primary:hover {
  background: var(--primary-dark);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(37, 99, 235, 0.2);
}

.btn-secondary {
  background: var(--bg-secondary);
  color: var(--text-primary);
  border: 1px solid var(--border);
}

.btn-secondary:hover {
  background: var(--border);
}

/* ---------------- PROGRESS BAR ---------------- */
.progress-container {
  background: var(--bg-secondary);
  border-radius: 16px;
  padding: 25px;
  margin-bottom: 25px;
  border: 1px solid var(--border);
}

.progress-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}

.progress-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--text-primary);
}

.progress-percentage {
  font-size: 20px;
  font-weight: 800;
  color: var(--success);
}

.progress-bar {
  height: 12px;
  background: var(--border);
  border-radius: 6px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: var(--gradient-success);
  border-radius: 6px;
  transition: width 0.5s ease;
}

/* ---------------- VIDEOS GRID ---------------- */
.videos-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 25px;
  margin-top: 25px;
}

.video-card {
  background: var(--card-bg);
  border-radius: 16px;
  overflow: hidden;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
}

.video-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 15px 35px rgba(0,0,0,0.15);
  border-color: var(--primary-light);
}

.video-card.watched {
  border-color: var(--success);
}

.watched-badge {
  position: absolute;
  top: 15px;
  right: 15px;
  background: var(--gradient-success);
  color: white;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 5px;
  box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
}

.video-thumbnail {
  position: relative;
  width: 100%;
  height: 180px;
  overflow: hidden;
  background: linear-gradient(45deg, #1e293b, #334155);
}

.video-thumbnail img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.5s;
}

.video-card:hover .video-thumbnail img {
  transform: scale(1.05);
}

.video-play-btn {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 60px;
  height: 60px;
  background: rgba(255, 255, 255, 0.95);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--danger);
  font-size: 24px;
  cursor: pointer;
  transition: all 0.3s ease;
  opacity: 0;
  border: none;
}

.video-card:hover .video-play-btn {
  opacity: 1;
  transform: translate(-50%, -50%) scale(1.1);
}

.video-info {
  padding: 20px;
}

.video-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 12px;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.video-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 15px;
}

.meta-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.badge-class {
  background: #dbeafe;
  color: #1d4ed8;
}

.badge-hindi {
  background: #dcfce7;
  color: #166534;
}

.badge-english {
  background: #f3e8ff;
  color: #6b21a8;
}

.badge-both {
  background: #fce7f3;
  color: #9d174d;
}

.video-desc {
  font-size: 14px;
  color: var(--text-secondary);
  margin-bottom: 20px;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.video-actions {
  display: flex;
  gap: 10px;
}

.action-btn {
  flex: 1;
  padding: 12px;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  text-decoration: none;
}

.action-btn.watch {
  background: var(--gradient-primary);
  color: white;
}

.action-btn.watch.is-loading,
.action-btn.watch.is-watched {
  pointer-events: none;
}

.action-btn.watch.is-watched {
  background: var(--gradient-success);
  color: white;
  box-shadow: 0 8px 18px rgba(16, 185, 129, 0.2);
}

.action-btn.watch.is-locked {
  background: #cbd5e1;
  color: #475569;
  box-shadow: none;
  cursor: not-allowed;
}

.action-btn.youtube {
  background: var(--bg-secondary);
  color: var(--text-primary);
  border: 1px solid var(--border);
}

.action-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

/* ---------------- NO VIDEOS MESSAGE ---------------- */
.no-videos {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-muted);
  grid-column: 1 / -1;
}

.no-videos i {
  font-size: 60px;
  margin-bottom: 20px;
  color: var(--border);
  opacity: 0.5;
}

.no-videos h3 {
  font-size: 22px;
  margin-bottom: 10px;
  color: var(--text-secondary);
}

.no-videos p {
  max-width: 400px;
  margin: 0 auto;
  line-height: 1.6;
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
  
  .sidebar.active .logo-img {
    animation: logoResize 0.5s ease;
  }
  
  .main-content {
    margin-left: 0;
    padding: 1.5rem;
  }
  
  .header {
    padding: 1.2rem;
    margin-bottom: 1.5rem;
  }
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .videos-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .filter-form {
    grid-template-columns: repeat(2, 1fr);
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
    gap: 14px;
    align-items: center;
    flex-wrap: wrap;
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
  
  .videos-grid {
    grid-template-columns: 1fr;
    gap: 20px;
  }
  
  .video-thumbnail {
    height: 200px;
  }
  
  .logo-img {
    width: 75px;
    height: 75px;
  }
  
  .logo-text {
    font-size: 22px;
  }
  
  .user-menu {
    gap: 12px;
    margin-left: auto;
    max-width: 100%;
  }
  
  .user-name {
    display: none;
  }
  
  .card {
    padding: 2rem;
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
  
  .filter-form {
    grid-template-columns: 1fr;
  }
  
  .filter-actions {
    grid-column: 1;
  }

  .user-profile {
    padding: 9px 12px;
  }

  .notifications {
    position: static;
  }

  .notification-panel {
    position: fixed;
    top: var(--student-notification-top, 92px);
    left: 12px;
    right: 12px;
    width: auto;
    max-width: none;
    max-height: calc(100dvh - var(--student-notification-top, 92px) - 12px);
    border-radius: 18px;
    transform: translateY(16px);
  }

  .notification-list {
    max-height: calc(100dvh - var(--student-notification-top, 92px) - 84px);
  }

  .notification-row {
    flex-direction: column;
    gap: 4px;
  }

  .notification-time {
    white-space: normal;
  }
}

/* Small Mobile */
@media (max-width: 480px) {
  .main-content {
    padding: 0.75rem;
  }
  
  .header {
    padding: 0.75rem;
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }
  
  .toggle-sidebar {
    width: 40px;
    height: 40px;
    font-size: 16px;
  }
  
  .user-menu {
    width: auto;
    margin-left: auto;
    justify-content: flex-end;
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

  .notification-panel {
    top: var(--student-notification-top, 84px);
    left: 10px;
    right: 10px;
    max-height: calc(100dvh - var(--student-notification-top, 84px) - 10px);
  }

  .notification-list {
    max-height: calc(100dvh - var(--student-notification-top, 84px) - 82px);
  }

  .notification-item {
    padding: 14px 15px;
  }

  .notification-icon-wrap {
    width: 38px;
    height: 38px;
    border-radius: 12px;
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
  
  .card {
    padding: 1.5rem;
  }
  
  .video-actions {
    flex-direction: column;
  }
  
  .action-btn {
    width: 100%;
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
  
  .filters-container {
    padding: 20px;
  }
  
  .video-title {
    font-size: 16px;
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
  .video-actions,
  .filters-container {
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

      <a href="view_schedule.php" class="nav-item">
        <div class="nav-icon"><i class="fas fa-calendar-alt"></i></div>
        <div class="nav-text">Time Table</div>
      </a>
      
      <a href="student_videos.php" class="nav-item active">
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
          <a href="profile.php" class="user-profile">
            <img src="<?php echo h($photoWeb); ?>" alt="Profile" class="user-avatar"
              onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student_name); ?>&background=1a56db&color=fff'">
            <div class="user-name"><?php echo h($student_name); ?></div>
          </a>
        </div>
      </div>

      <!-- Main Card -->
      <div class="card">
        <h1 style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
          <i class="fab fa-youtube" style="color: #ff0000;"></i>
          Video Lectures
        </h1>
        <p style="color: var(--text-secondary); margin-bottom: 25px;">
          Watch educational videos for Class <?php echo h($class); ?> (<?php echo ucfirst(h($student_medium)); ?> Medium)
        </p>

        <!-- Stats Grid -->
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-video"></i></div>
            <div class="stat-number" id="totalVideosCount"><?php echo $total_videos; ?></div>
            <div class="stat-label">Total Videos</div>
          </div>
          <div class="stat-card success">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-number" id="watchedVideosCount"><?php echo $total_watched; ?></div>
            <div class="stat-label">Watched Videos</div>
          </div>
          <div class="stat-card accent">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-number" id="completionPercentage"><?php echo $progress_percentage; ?>%</div>
            <div class="stat-label">Completion</div>
          </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-container">
          <div class="progress-header">
            <div class="progress-title">Your Learning Progress</div>
            <div class="progress-percentage" id="learningProgressPercentage"><?php echo $progress_percentage; ?>%</div>
          </div>
          <div class="progress-bar">
            <div
              class="progress-fill"
              id="learningProgressFill"
              style="width: <?php echo $progress_percentage; ?>%;"
              aria-valuenow="<?php echo $progress_percentage; ?>"
            ></div>
          </div>
        </div>

        <!-- Filters -->
        <div class="filters-container">
          <h3 class="filter-title">
            <i class="fas fa-filter"></i>
            Filter Videos
          </h3>
          <form method="GET" class="filter-form">
            <div class="form-group">
              <label class="form-label">Class</label>
              <select name="class" class="form-select" onchange="this.form.submit()">
                <option value="">All Classes</option>
                <option value="8" <?php echo $class_filter == '8' ? 'selected' : ''; ?>>Class 8</option>
                <option value="9" <?php echo $class_filter == '9' ? 'selected' : ''; ?>>Class 9</option>
                <option value="10" <?php echo $class_filter == '10' ? 'selected' : ''; ?>>Class 10</option>
                <option value="11" <?php echo $class_filter == '11' ? 'selected' : ''; ?>>Class 11</option>
                <option value="12" <?php echo $class_filter == '12' ? 'selected' : ''; ?>>Class 12</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Medium</label>
              <select name="medium" class="form-select" onchange="this.form.submit()">
                <option value="">All Mediums</option>
                <option value="hindi" <?php echo $medium_filter == 'hindi' ? 'selected' : ''; ?>>Hindi</option>
                <option value="english" <?php echo $medium_filter == 'english' ? 'selected' : ''; ?>>English</option>
                <option value="both" <?php echo $medium_filter == 'both' ? 'selected' : ''; ?>>Both</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Subject</label>
              <select name="subject" class="form-select" onchange="this.form.submit()">
                <option value="">All Subjects</option>
                <?php if ($subjects_result): while($subject_row = $subjects_result->fetch_assoc()): ?>
                <option value="<?php echo h($subject_row['subject']); ?>" 
                    <?php echo $subject_filter == $subject_row['subject'] ? 'selected' : ''; ?>>
                    <?php echo h($subject_row['subject']); ?>
                </option>
                <?php endwhile; endif; ?>
              </select>
            </div>
            
            <div class="filter-actions">
              <a href="student_videos.php" class="btn btn-secondary">
                <i class="fas fa-times"></i>
                Clear Filters
              </a>
            </div>
          </form>
        </div>

        <!-- Videos Grid -->
        <?php if (count($videos) > 0): ?>
        <div class="videos-grid">
          <?php foreach ($videos as $video): 
            $medium_class = 'badge-' . $video['medium'];
            $watched_class = $video['watched'] ? 'watched' : '';
            $last_watched = $video['last_watched'] ? date('M d, Y', strtotime($video['last_watched'])) : '';
            $video_url = 'https://youtube.com/watch?v=' . h($video['video_id']);
            $unlock_url = '?unlock_watch=' . (int)$video['id'];
            $watch_button_state = $video['watched'] ? 'watched' : (!empty($video['watch_unlocked']) ? 'ready' : 'locked');
            $watch_button_classes = 'action-btn watch';
            $watch_button_icon = 'lock';
            $watch_button_text = 'Open Video First';

            if ($watch_button_state === 'watched') {
                $watch_button_classes .= ' is-watched';
                $watch_button_icon = 'check';
                $watch_button_text = 'Watched';
            } elseif ($watch_button_state === 'locked') {
                $watch_button_classes .= ' is-locked';
            } else {
                $watch_button_icon = 'eye';
                $watch_button_text = 'Mark as Watched';
            }
          ?>
          <div class="video-card <?php echo $watched_class; ?>" data-video-card data-video-id="<?php echo (int)$video['id']; ?>">
            <?php if ($video['watched']): ?>
            <div class="watched-badge" data-watched-badge>
              <i class="fas fa-check"></i>
              Watched <?php echo $last_watched ? 'on ' . $last_watched : ''; ?>
            </div>
            <?php endif; ?>
            
            <div class="video-thumbnail">
              <img src="https://img.youtube.com/vi/<?php echo h($video['video_id']); ?>/hqdefault.jpg" 
                   alt="<?php echo h($video['title']); ?>"
                   onerror="this.src='https://via.placeholder.com/320x180/1e293b/ffffff?text=Video+Thumbnail'">
              <button
                type="button"
                class="video-play-btn"
                data-video-open
                data-video-url="<?php echo $video_url; ?>"
                data-unlock-url="<?php echo $unlock_url; ?>"
                aria-label="Open video"
              >
                <i class="fas fa-play"></i>
              </button>
            </div>
            
            <div class="video-info">
              <h3 class="video-title"><?php echo h($video['title']); ?></h3>
              
              <div class="video-meta">
                <span class="meta-badge badge-class">
                  <i class="fas fa-graduation-cap"></i>
                  Class <?php echo h($video['class_name']); ?>
                </span>
                <span class="meta-badge <?php echo $medium_class; ?>">
                  <i class="fas fa-language"></i>
                  <?php echo ucfirst(h($video['medium'])); ?>
                </span>
              </div>
              
              <?php if (!empty($video['description'])): ?>
              <p class="video-desc"><?php echo h(substr($video['description'], 0, 100)); ?>...</p>
              <?php endif; ?>
              
              <?php if (!empty($video['subject'])): ?>
              <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">
                <i class="fas fa-book"></i> <?php echo h($video['subject']); ?>
                <?php if (!empty($video['chapter'])): ?>
                <span style="color: var(--text-muted);"> • <?php echo h($video['chapter']); ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              
              <div class="video-actions">
                <a href="?watch=<?php echo $video['id']; ?>&class=<?php echo urlencode($class_filter); ?>&medium=<?php echo urlencode($medium_filter); ?>&subject=<?php echo urlencode($subject_filter); ?>" 
                   class="<?php echo $watch_button_classes; ?>"
                   data-watch-button
                   data-watch-status="<?php echo $watch_button_state; ?>"
                   <?php echo $watch_button_state !== 'ready' ? 'aria-disabled="true"' : ''; ?>>
                  <i class="fas fa-<?php echo $watch_button_icon; ?>"></i>
                  <?php echo $watch_button_text; ?>
                </a>
                <a href="https://youtube.com/watch?v=<?php echo h($video['video_id']); ?>" 
                   target="_blank" 
                   class="action-btn youtube"
                   data-video-open
                   data-video-url="<?php echo $video_url; ?>"
                   data-unlock-url="<?php echo $unlock_url; ?>">
                  <i class="fab fa-youtube"></i>
                  Watch on YouTube
                </a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-videos">
          <i class="fas fa-video-slash"></i>
          <h3>No Videos Available</h3>
          <p>No video lectures found for your selected filters. Try changing filters or check back later.</p>
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Information Card -->
      <div class="card">
        <h2 style="display: flex; align-items: center; gap: 12px; margin-bottom: 1rem;">
          <i class="fas fa-info-circle" style="color: var(--primary);"></i>
          Video Instructions
        </h2>
        <p style="color: var(--text-secondary); margin-bottom: 15px;">
          All videos are carefully selected by your teachers to match your curriculum for Class <?php echo h($class); ?> (<?php echo ucfirst(h($student_medium)); ?> Medium).
        </p>
        <p style="color: var(--text-secondary);">
          Pehle video open karo, uske baad hi "Mark as Watched" unlock hoga aur progress me count hoga.
          For the best viewing experience, make sure you have a stable internet connection.
        </p>
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
      const totalVideosCount = document.getElementById('totalVideosCount');
      const watchedVideosCount = document.getElementById('watchedVideosCount');
      const completionPercentage = document.getElementById('completionPercentage');
      const learningProgressPercentage = document.getElementById('learningProgressPercentage');
      const learningProgressFill = document.getElementById('learningProgressFill');
      
      // Check internet connection
      function checkInternetConnection() {
        if (!navigator.onLine) {
          internetError.classList.add('show');
          showToast('You are offline. Videos may not play properly.', 'error');
        } else {
          internetError.classList.remove('show');
        }
      }
      
      // Initial check
      checkInternetConnection();
      
      // Listen for connection changes
      window.addEventListener('online', function() {
        internetError.classList.remove('show');
        showToast('Internet connection restored! Videos will now play.', 'success');
      });
      
      window.addEventListener('offline', function() {
        internetError.classList.add('show');
        showToast('You are offline. Videos may not play properly.', 'error');
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

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.dropdown').forEach(drop => {
            drop.classList.remove('open');
          });
        }
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
      
      document.querySelectorAll('.card, .video-card').forEach(card => {
        observer.observe(card);
      });
      
      function updateProgressStats(stats) {
        if (!stats) {
          return;
        }

        const totalVideos = Number(stats.total_videos || 0);
        const watchedVideos = Number(stats.watched_videos || 0);
        const progress = Number(stats.progress_percentage || 0);

        if (totalVideosCount) {
          totalVideosCount.textContent = totalVideos;
        }

        if (watchedVideosCount) {
          watchedVideosCount.textContent = watchedVideos;
        }

        if (completionPercentage) {
          completionPercentage.textContent = `${progress}%`;
        }

        if (learningProgressPercentage) {
          learningProgressPercentage.textContent = `${progress}%`;
        }

        if (learningProgressFill) {
          learningProgressFill.style.width = `${progress}%`;
          learningProgressFill.setAttribute('aria-valuenow', String(progress));
        }
      }

      function buildWatchedBadge(label) {
        const badge = document.createElement('div');
        badge.className = 'watched-badge';
        badge.setAttribute('data-watched-badge', '');
        badge.innerHTML = `<i class="fas fa-check"></i> Watched${label ? ` on ${label}` : ''}`;
        return badge;
      }

      function setWatchButtonReady(button) {
        button.classList.remove('is-loading', 'is-locked');
        button.dataset.watchStatus = 'ready';
        button.removeAttribute('data-loading');
        button.removeAttribute('aria-disabled');
        button.style.background = '';
        button.innerHTML = '<i class="fas fa-eye"></i> Mark as Watched';
      }

      function setWatchButtonLocked(button) {
        button.classList.remove('is-loading', 'is-watched');
        button.classList.add('is-locked');
        button.dataset.watchStatus = 'locked';
        button.removeAttribute('data-loading');
        button.setAttribute('aria-disabled', 'true');
        button.style.background = '';
        button.innerHTML = '<i class="fas fa-lock"></i> Open Video First';
      }

      function markVideoAsWatched(button, watchedAtLabel) {
        const card = button.closest('[data-video-card]');
        if (card) {
          card.classList.add('watched');
          let badge = card.querySelector('[data-watched-badge]');
          if (!badge) {
            badge = buildWatchedBadge(watchedAtLabel);
            card.prepend(badge);
          } else {
            badge.innerHTML = `<i class="fas fa-check"></i> Watched${watchedAtLabel ? ` on ${watchedAtLabel}` : ''}`;
          }
        }

        button.classList.remove('is-loading');
        button.classList.add('is-watched');
        button.dataset.watchStatus = 'watched';
        button.removeAttribute('data-loading');
        button.setAttribute('aria-disabled', 'true');
        button.style.background = '';
        button.innerHTML = '<i class="fas fa-check"></i> Watched';
      }

      function unlockVideoWatch(card, options = {}) {
        if (!card) {
          return Promise.resolve(false);
        }

        const watchButton = card.querySelector('[data-watch-button]');
        if (!watchButton || watchButton.dataset.watchStatus === 'watched' || watchButton.dataset.watchStatus === 'ready') {
          return Promise.resolve(true);
        }

        const videoTrigger = card.querySelector('[data-video-open]');
        const unlockUrl = videoTrigger ? videoTrigger.dataset.unlockUrl : '';

        if (!unlockUrl || watchButton.dataset.loading === 'true') {
          return Promise.resolve(false);
        }

        watchButton.dataset.loading = 'true';
        watchButton.classList.add('is-loading');
        watchButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Unlocking...';

        return fetch(unlockUrl, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        })
          .then(async response => {
            let data = null;

            try {
              data = await response.json();
            } catch (error) {
              data = null;
            }

            if (!response.ok || !data || data.success !== true) {
              throw new Error(data && data.message ? data.message : 'Video unlock failed.');
            }

            setWatchButtonReady(watchButton);

            if (!options.silent) {
              showToast(data.message || 'Video opened. Mark as Watched unlocked.', 'success');
            }

            return true;
          })
          .catch(error => {
            setWatchButtonLocked(watchButton);
            showToast(error.message || 'Video open hua, lekin watch unlock nahi ho paya.', 'error');
            return false;
          });
      }

      document.querySelectorAll('[data-video-open]').forEach(trigger => {
        trigger.addEventListener('click', function(e) {
          e.preventDefault();

          const card = this.closest('[data-video-card]');
          const videoUrl = this.dataset.videoUrl || this.getAttribute('href');

          if (!videoUrl) {
            return;
          }

          const openedWindow = window.open(videoUrl, '_blank', 'noopener');
          if (!openedWindow) {
            window.location.href = videoUrl;
          }

          unlockVideoWatch(card, { silent: true });
        });
      });

      // Handle "Mark as Watched" button clicks
      document.querySelectorAll('[data-watch-button]').forEach(btn => {
        btn.addEventListener('click', function(e) {
          if (this.classList.contains('is-watched') || this.dataset.loading === 'true') {
            e.preventDefault();
            return;
          }

          if (this.dataset.watchStatus === 'locked') {
            e.preventDefault();
            showToast('Pehle video open karo, uske baad hi Mark as Watched chalega.', 'info');
            return;
          }

          e.preventDefault();

          const originalText = this.innerHTML;
          const originalBg = this.style.background;

          this.dataset.loading = 'true';
          this.classList.add('is-loading');
          this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';
          this.style.background = 'var(--accent)';

          fetch(this.href, {
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            }
          })
            .then(async response => {
              let data = null;

              try {
                data = await response.json();
              } catch (error) {
                data = null;
              }

              if (!response.ok || !data || data.success !== true) {
                throw new Error(data && data.message ? data.message : 'Failed to update watch progress.');
              }

              return data;
            })
            .then(data => {
              markVideoAsWatched(this, data.watched_at_label || '');
              updateProgressStats(data.stats || {});
              showToast(data.message || 'Video marked as watched successfully.', data.already_watched ? 'info' : 'success');
            })
            .catch(error => {
              this.classList.remove('is-loading');
              this.removeAttribute('data-loading');
              this.innerHTML = '<i class="fas fa-times"></i> Error';
              this.style.background = 'var(--danger)';

              setTimeout(() => {
                this.innerHTML = originalText;
                this.style.background = originalBg;
              }, 2000);

              showToast(error.message || 'Failed to mark video as watched. Please try again.', 'error');
            });
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
      
      // Handle video thumbnail loading errors
      document.querySelectorAll('.video-thumbnail img').forEach(img => {
        img.addEventListener('error', function() {
          this.src = 'https://via.placeholder.com/320x180/1e293b/ffffff?text=Video+Thumbnail';
        });
      });
      
      // Auto-refresh progress bar on page load
      if (learningProgressFill) {
        const width = learningProgressFill.style.width;
        learningProgressFill.style.width = '0';
        setTimeout(() => {
          learningProgressFill.style.width = width;
        }, 100);
      }
    });
  </script>
  <?php student_notifications_render_script($student_notifications_data); ?>
</body>
</html>
