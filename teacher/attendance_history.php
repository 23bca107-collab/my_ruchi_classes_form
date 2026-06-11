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

// Include teacher authentication functions
require_once 'teacher_auth.php';
require_once __DIR__ . '/teacher_notifications_ui.php';

// Check if teacher is logged in using the auth function
if (!isTeacherAuthenticated()) {
    header("Location: teacher_login.php");
    exit();
}

// Get teacher info using the auth function
$teacher = getTeacherInfo();

// Update last activity
updateTeacherLastActivity();

// Generate CSRF token for forms
$csrf_token = generateTeacherCSRFToken();

$teacher_id = $_SESSION['teacher_id'];

// Log page access
logTeacherActivity('VIEW_ATTENDANCE_HISTORY', 'Viewed attendance history page');

// Get teacher info for sidebar
$teacher = [];
$stmt = $conn->prepare("SELECT first_name, last_name, email, mobile, subject, photo FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$teacher = $result->fetch_assoc() ?? [];
$teacher['id'] = $teacher_id;
$teacher_notifications_data = teacher_notifications_prepare($conn, $teacher, 12);

// Get messages count
$messages_count = 0;
$table_check = $conn->query("SHOW TABLES LIKE 'messages'");
if ($table_check->num_rows > 0) {
    $messages_stmt = $conn->prepare("SELECT COUNT(*) as msg_count FROM messages WHERE receiver_id=? AND receiver_type='teacher' AND status='unread'");
    $messages_stmt->bind_param("i", $teacher_id);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    $messages_count = $messages_result->fetch_assoc()['msg_count'];
}

// Date range for filtering (default: last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$class_filter = $_GET['class'] ?? '';
$medium_filter = $_GET['medium'] ?? '';
$subject_filter = $_GET['subject'] ?? '';

// First, check if attendance table has the required columns
$check_columns = $conn->query("SHOW COLUMNS FROM attendance LIKE 'teacher_id'");
if ($check_columns && $check_columns->num_rows == 0) {
    // Add missing columns
    $conn->query("ALTER TABLE attendance ADD COLUMN teacher_id INT NOT NULL DEFAULT 0 AFTER status");
    $conn->query("ALTER TABLE attendance ADD COLUMN submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER teacher_id");
    
    // Update existing records with current teacher's ID
    $conn->query("UPDATE attendance SET teacher_id = $teacher_id WHERE teacher_id = 0");
}

// Build query to fetch attendance history
$query = "SELECT 
            a.class,
            a.medium,
            a.subject,
            a.date,
            COUNT(DISTINCT a.student_id) as total_students,
            SUM(CASE WHEN a.status = 'P' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'A' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'S' THEN 1 ELSE 0 END) as suspended_count,
            SUM(CASE WHEN a.status = 'R' THEN 1 ELSE 0 END) as remaining_count,
            MAX(a.submitted_at) as submitted_at
          FROM attendance a
          WHERE a.teacher_id = ?";

$params = [$teacher_id];
$types = "i";

if ($start_date) {
    $query .= " AND a.date >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if ($end_date) {
    $query .= " AND a.date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

if ($class_filter) {
    $query .= " AND a.class = ?";
    $params[] = $class_filter;
    $types .= "s";
}

if ($medium_filter) {
    $query .= " AND a.medium = ?";
    $params[] = $medium_filter;
    $types .= "s";
}

if ($subject_filter) {
    $query .= " AND a.subject = ?";
    $params[] = $subject_filter;
    $types .= "s";
}

$query .= " GROUP BY a.class, a.medium, a.subject, a.date
           ORDER BY a.date DESC, a.class, a.medium, a.subject";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$attendance_history = $result->fetch_all(MYSQLI_ASSOC);

// Get unique classes, mediums, subjects for filters
$classes_result = $conn->query("SELECT DISTINCT class FROM attendance WHERE teacher_id = $teacher_id ORDER BY class");
$classes = $classes_result ? $classes_result->fetch_all(MYSQLI_ASSOC) : [];

$mediums_result = $conn->query("SELECT DISTINCT medium FROM attendance WHERE teacher_id = $teacher_id ORDER BY medium");
$mediums = $mediums_result ? $mediums_result->fetch_all(MYSQLI_ASSOC) : [];

$subjects_result = $conn->query("SELECT DISTINCT subject FROM attendance WHERE teacher_id = $teacher_id ORDER BY subject");
$subjects = $subjects_result ? $subjects_result->fetch_all(MYSQLI_ASSOC) : [];

// Debug: Check if we have data
error_log("Teacher ID: $teacher_id");
error_log("Attendance records found: " . count($attendance_history));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance History - Ruchi Classes</title>
    <!-- SweetAlert CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Font Awesome -->
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
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);

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
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }
        
        /* ----------------- SIDEBAR ------------------ */
        .sidebar {
            width: 300px;
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

        /* Active state for dropdown parent */
        .dropdown.open {
            background: rgba(37, 99, 235, 0.1) !important;
            border-left: 4px solid var(--primary) !important;
        }

        .dropdown.open .nav-icon {
            color: var(--primary) !important;
        }

        .dropdown.open .nav-text {
            color: var(--primary) !important;
            font-weight: 600 !important;
        }

        /* ---------------- MAIN CONTENT ----------------- */
        .main-content {
            flex: 1;
            margin-left: 300px;
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

        .user-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* ---------------- PROFILE MENU ---------------- */
        .profile-menu {
            display: none;
            position: absolute;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            padding: 10px 0;
            min-width: 200px;
            z-index: 1000;
        }

        .profile-menu a {
            display: block;
            padding: 10px 20px;
            text-decoration: none;
            color: #333;
            transition: 0.3s;
        }

        .profile-menu a:hover {
            background: var(--bg-hover);
        }

        .profile-menu hr {
            margin: 5px 0;
            border: 1px solid #eee;
        }

        /* ---------------- CONTENT STYLES ----------------- */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        
        .page-title {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .page-title h1 {
            color: var(--text-primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .page-title p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }
        
        .card {
            background: var(--gradient-primary);
            color: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            animation: fadeIn 0.8s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .card h2 {
            color: white;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
        }

        .card h2 i {
            font-size: 1.8rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            background: rgba(255,255,255,0.1);
            padding: 1.5rem;
            border-radius: 12px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #000000;
            font-size: 0.95rem;
        }

        .form-group label i {
            margin-right: 5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.9);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: white;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
            background: white;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
            z-index: -1;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: white;
            color: var(--primary);
            box-shadow: 0 6px 15px rgba(255,255,255,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(255,255,255,0.4);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-3px);
        }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--gradient-primary);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.25);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            animation: fadeIn 0.6s ease forwards;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0));
            z-index: 1;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }

        .stat-card:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 12px 30px rgba(37, 99, 235, 0.35);
        }

        .stat-card .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 15px;
            position: relative;
            z-index: 2;
        }

        .stat-card .stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 5px;
            position: relative;
            z-index: 2;
        }

        .stat-card .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }

        /* ---------------- TABLE STYLES - DESKTOP ----------------- */
        .table-container {
            overflow-x: auto;
            border-radius: 16px;
            box-shadow: var(--shadow);
            margin-top: 2rem;
            background: white;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1000px;
        }

        .history-table th {
            background: var(--gradient-primary);
            color: white;
            font-weight: 600;
            padding: 16px 12px;
            text-align: left;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }

        .history-table td {
            padding: 16px 12px;
            border-bottom: 1px solid var(--border);
            background: white;
        }

        .history-table tr:hover td {
            background: var(--bg-hover);
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 80px;
            margin: 2px;
        }

        .status-present { background: #d1fae5; color: #065f46; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-remaining { background: #e0e7ff; color: #3730a3; }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .action-btn.view {
            background: #dbeafe;
            color: #1e40af;
        }

        .action-btn.edit {
            background: #fef3c7;
            color: #92400e;
        }

        .action-btn.request {
            background: #fef3c7;
            color: #92400e;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .date-badge {
            background: #f1f5f9;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
            white-space: nowrap;
        }

        .attendance-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .no-data {
            text-align: center;
            padding: 3rem 2rem;
            color: #000000;
            font-size: 1.1rem;
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: #000;
            opacity: 0.5;
        }

        .teacher-info-badge {
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

        .footer {
            text-align: center;
            margin-top: 3rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
            padding: 1.5rem;
        }

        /* ---------------- ANIMATIONS ---------------- */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }

        .fade-in {
            animation: fadeIn 0.6s ease;
        }

        /* ---------------- MOBILE TABLE (CARD LAYOUT) ---------------- */
        .mobile-history-cards {
            display: none;
            flex-direction: column;
            gap: 20px;
            margin-top: 2rem;
        }

        .history-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .history-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .card-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .card-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .card-label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
            flex: 0 0 120px;
        }

        .card-value {
            flex: 1;
            text-align: right;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .card-value .attendance-summary {
            justify-content: flex-end;
        }

        .card-value .action-buttons {
            justify-content: flex-end;
        }

        /* ---------------- RESPONSIVE BREAKPOINTS ---------------- */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
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

            .page-title h1 {
                font-size: 2rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .dropdown-menu {
                position: static !important;
                margin-left: 0 !important;
                margin-top: 10px !important;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
                border: 1px solid var(--border) !important;
                background: white !important;
                display: none !important;
            }

            .dropdown.open + .dropdown-menu {
                display: flex !important;
                animation: slideDown 0.3s ease !important;
            }

            .dropdown-item {
                padding: 18px 25px !important;
                border-bottom: 1px solid #eee !important;
                font-size: 16px !important;
                min-height: 60px;
            }

            .dropdown-item:hover {
                transform: none !important;
                background: var(--primary-light) !important;
                color: white !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .header {
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .page-title h1 {
                font-size: 1.8rem;
            }

            .page-title p {
                font-size: 1rem;
            }

            .user-name {
                display: none;
            }

            .user-profile {
                padding: 8px;
            }

            .toggle-sidebar {
                width: 45px;
                height: 45px;
            }

            /* Hide desktop table on mobile */
            .table-container {
                display: none;
            }

            /* Show mobile cards */
            .mobile-history-cards {
                display: flex;
            }

            /* Adjust stats for mobile */
            .stats-summary {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .stat-card {
                padding: 1.2rem;
            }

            .stat-card .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 24px;
            }

            .stat-card .stat-value {
                font-size: 1.8rem;
            }

            /* Adjust filter form for mobile */
            .filter-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .header {
                padding: 0.75rem;
            }

            .page-title h1 {
                font-size: 1.5rem;
            }

            .stats-summary {
                grid-template-columns: 1fr;
            }

            .history-card {
                padding: 1rem;
            }

            .card-row {
                flex-direction: column;
                gap: 5px;
            }

            .card-label {
                flex: none;
                width: 100%;
            }

            .card-value {
                text-align: left;
                width: 100%;
            }

            .card-value .attendance-summary {
                justify-content: flex-start;
            }

            .card-value .action-buttons {
                justify-content: flex-start;
            }

            .status-badge {
                min-width: 60px;
                font-size: 11px;
                padding: 4px 8px;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (min-width: 1025px) {
            .sidebar {
                width: 300px;
            }

            .main-content {
                margin-left: 300px;
            }

            .sidebar.collapsed {
                width: 85px;
            }

            .main-content.expanded {
                margin-left: 85px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        .sidebar {
            scroll-behavior: smooth;
        }

        .main-content {
            scroll-behavior: smooth;
        }

        .dashboard-container {
            opacity: 0;
            animation: fadeInDashboard 0.5s ease forwards;
        }

        @keyframes fadeInDashboard {
            from { opacity: 0; }
            to { opacity: 1; }
        }
</style>
<?php teacher_notifications_render_styles(); ?>
</head>
<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Profile Menu Dropdown -->
    <div class="profile-menu" id="profileMenu">
        <a href="teacher_profile.php"><i class="fas fa-user"></i> My Profile</a>
        <hr>
        <a href="#" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="logo-container">
            <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo-img" id="logoImg">
            <div class="logo-text" id="logoText">
                Ruchi <br>Classes
                <span>Education for Excellence</span>
            </div>
        </div>
        <a href="teacher_dashboard.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <div class="nav-text">Dashboard</div>
        </a>

        <!-- Attendance Dropdown -->
        <div class="nav-item dropdown <?php echo basename($_SERVER['PHP_SELF']) == 'teacher_attendance.php' || basename($_SERVER['PHP_SELF']) == 'attendance_history.php' ? 'active' : ''; ?>" id="attendanceDropdown">
            <div class="nav-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="nav-text">Attendance</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="attendanceMenu">
            <a href="teacher_attendance.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'teacher_attendance.php' ? 'active' : ''; ?>">
                <i class="fas fa-pencil-alt"></i> Mark Attendance
            </a>
            <a href="attendance_history.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'attendance_history.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Attendance History
            </a>
        </div>

        <!-- Exams Dropdown -->
        <div class="nav-item dropdown <?php echo basename($_SERVER['PHP_SELF']) == 'teacher_add_exam.php' || basename($_SERVER['PHP_SELF']) == 'exam_marks_entry.php' || basename($_SERVER['PHP_SELF']) == 'exam_results.php' ? 'active' : ''; ?>" id="examsDropdown">
            <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
            <div class="nav-text">Exams</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="examsMenu">
            <a href="teacher_add_exam.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'teacher_add_exam.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> Add Exam
            </a>
            <a href="all_exam.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'all_exam.php' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Exams
            </a>
            <a href="exam_marks_entry.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'exam_marks_entry.php' ? 'active' : ''; ?>">
                <i class="fas fa-pencil-alt"></i> Marks Entry
            </a>
            <a href="exam_marks_history.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'exam_marks_history.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Marks History
            </a>
        </div>
        <a href="teacher_complain.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-comment-dots"></i></div>
            <div class="nav-text">Complaint</div>
        </a>

        <a href="teacher_students.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-users"></i></div>
            <div class="nav-text">Students</div>
        </a>

        <a href="#" class="nav-item" onclick="confirmLogout(event)">
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
                <?php teacher_notifications_render_button($teacher_notifications_data); ?>
                <div class="user-profile" onclick="toggleProfileMenu(event)">
                    <?php if (!empty($teacher['photo'])): ?>
                        <img src="<?php echo htmlspecialchars($teacher['photo']); ?>" alt="Profile" class="user-avatar"
                            onerror="this.src='../<?php echo htmlspecialchars($teacher['photo']); ?>'">
                    <?php else: ?>
                        <div class="user-avatar" style="background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                            <?php 
                            $initials = '';
                            if (!empty($teacher['first_name'])) {
                                $initials = substr($teacher['first_name'], 0, 1) . substr($teacher['last_name'] ?? '', 0, 1);
                            } else {
                                $initials = 'TC';
                            }
                            echo $initials;
                            ?>
                        </div>
                    <?php endif; ?>
                    <div class="user-name">
                        <?php 
                        if (!empty($teacher['first_name'])) {
                            echo htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['last_name'] ?? ''));
                        } else {
                            echo 'Teacher';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Teacher Info Badge -->
            <?php if (!empty($teacher['subject'])): ?>
            <div style="text-align: center; margin-bottom: 1rem;">
                <div class="teacher-info-badge">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <?php echo htmlspecialchars($teacher['subject']); ?> Teacher
                </div>
            </div>
            <?php endif; ?>

            <div class="page-title">
                <h1><i class="fas fa-history"></i> Attendance History</h1>
                <p>View and manage your attendance records</p>
            </div>

            <!-- Filter Card -->
            <div class="card">
                <h2><i class="fas fa-filter"></i> Filter Attendance Records</h2>
                <form method="get" class="filter-form">
                    <div class="form-group">
                        <label for="start_date"><i class="fas fa-calendar-day"></i> From Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                    </div>

                    <div class="form-group">
                        <label for="end_date"><i class="fas fa-calendar-day"></i> To Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                    </div>

                    <div class="form-group">
                        <label for="class"><i class="fas fa-graduation-cap"></i> Class</label>
                        <select id="class" name="class" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class'] ?>" <?= ($class_filter == $c['class']) ? 'selected' : '' ?>>
                                    Class <?= $c['class'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="medium"><i class="fas fa-language"></i> Medium</label>
                        <select id="medium" name="medium" class="form-control">
                            <option value="">All Mediums</option>
                            <?php foreach ($mediums as $m): ?>
                                <option value="<?= $m['medium'] ?>" <?= ($medium_filter == $m['medium']) ? 'selected' : '' ?>>
                                    <?= $m['medium'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subject"><i class="fas fa-book"></i> Subject</label>
                        <select id="subject" name="subject" class="form-control">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['subject'] ?>" <?= ($subject_filter == $s['subject']) ? 'selected' : '' ?>>
                                    <?= $s['subject'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="attendance_history.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <?php if (!empty($attendance_history)): ?>
                <?php
                // Calculate summary statistics
                $total_records = count($attendance_history);
                $total_present = array_sum(array_column($attendance_history, 'present_count'));
                $total_absent = array_sum(array_column($attendance_history, 'absent_count'));
                $total_suspended = array_sum(array_column($attendance_history, 'suspended_count'));
                $total_remaining = array_sum(array_column($attendance_history, 'remaining_count'));
                $total_students = $total_present + $total_absent + $total_suspended + $total_remaining;
                ?>

                <!-- Statistics Cards -->
                <div class="stats-summary">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?= $total_records ?></div>
                        <div class="stat-label">Total Records</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-value"><?= $total_present ?></div>
                        <div class="stat-label">Total Present</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-times"></i>
                        </div>
                        <div class="stat-value"><?= $total_absent ?></div>
                        <div class="stat-label">Total Absent</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="stat-value"><?= $total_suspended + $total_remaining ?></div>
                        <div class="stat-label">Other Status</div>
                    </div>
                </div>

                <!-- History Table (Desktop) -->
                <div class="table-container">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Class</th>
                                <th>Medium</th>
                                <th>Subject</th>
                                <th>Attendance Summary</th>
                                <th>Submitted At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_history as $record): ?>
                                <tr class="fade-in">
                                    <td>
                                        <span class="date-badge">
                                            <?= date('d M Y', strtotime($record['date'])) ?>
                                        </span>
                                        <?php if ($record['date'] == date('Y-m-d')): ?>
                                            <span style="color: var(--success); font-size: 12px; margin-left: 5px;">
                                                <i class="fas fa-star"></i> Today
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>Class <?= htmlspecialchars($record['class']) ?></td>
                                    <td><?= htmlspecialchars($record['medium']) ?></td>
                                    <td><?= htmlspecialchars($record['subject']) ?></td>
                                    <td>
                                        <div class="attendance-summary">
                                            <span class="status-badge status-present">
                                                <i class="fas fa-check"></i> P: <?= $record['present_count'] ?>
                                            </span>
                                            <span class="status-badge status-absent">
                                                <i class="fas fa-times"></i> A: <?= $record['absent_count'] ?>
                                            </span>
                                            <span class="status-badge status-suspended">
                                                <i class="fas fa-clock"></i> S: <?= $record['suspended_count'] ?>
                                            </span>
                                            <span class="status-badge status-remaining">
                                                <i class="fas fa-user-clock"></i> R: <?= $record['remaining_count'] ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($record['submitted_at']): ?>
                                            <?= date('d M Y, h:i A', strtotime($record['submitted_at'])) ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 12px;">Not recorded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- View Button -->
                                            <button class="action-btn view" onclick="viewAttendanceDetails(
                                                '<?= $record['class'] ?>',
                                                '<?= $record['medium'] ?>',
                                                '<?= $record['subject'] ?>',
                                                '<?= $record['date'] ?>'
                                            )">
                                                <i class="fas fa-eye"></i> View
                                            </button>

                                            <?php
                                            // Check date restrictions - Allow edit only for today and yesterday
                                            $attendance_date = $record['date'];
                                            $today = date('Y-m-d');
                                            $yesterday = date('Y-m-d', strtotime('-1 day'));
                                            $is_editable_date = ($attendance_date == $today || $attendance_date == $yesterday);

                                            // Check if request already exists
                                            $check_req = $conn->prepare("
                                                SELECT id, status FROM attendance_update_requests 
                                                WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=?
                                            ");
                                            $check_req->bind_param("iisss", $teacher_id, $record['class'], $record['medium'], $record['subject'], $attendance_date);
                                            $check_req->execute();
                                            $req_result = $check_req->get_result();
                                            $existing_request = $req_result->fetch_assoc();

                                            // Check if teacher can edit directly (for today only)
                                            $can_edit_directly = ($attendance_date == $today);
                                            ?>

                                            <?php if ($is_editable_date): ?>
                                                <?php if ($existing_request): ?>
                                                    <?php
                                                    $request_status = $existing_request['status'];
                                                    $status_class = '';
                                                    $status_text = ucfirst($request_status);

                                                    switch($request_status) {
                                                        case 'pending':
                                                            $status_class = 'status-badge status-suspended';
                                                            break;
                                                        case 'approved':
                                                            $status_class = 'status-badge status-present';
                                                            // Create edit link for approved requests
                                                            $edit_link = "teacher_attendance.php?update_request=" . $existing_request['id'];
                                                            break;
                                                        case 'rejected':
                                                            $status_class = 'status-badge status-absent';
                                                            break;
                                                        case 'completed':
                                                            $status_class = 'status-badge status-remaining';
                                                            break;
                                                    }
                                                    ?>

                                                    <?php if ($request_status == 'approved' && isset($edit_link)): ?>
                                                        <a href="<?= $edit_link ?>" class="action-btn edit">
                                                            <i class="fas fa-edit"></i> Edit Now
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="<?= $status_class ?>" style="cursor: default; padding: 6px 12px; font-size: 12px;">
                                                            <?= $status_text ?>
                                                        </span>
                                                    <?php endif; ?>

                                                <?php elseif ($can_edit_directly): ?>
                                                    <!-- Direct edit for today's attendance -->
                                                    <a href="teacher_attendance.php?class=<?= $record['class'] ?>&medium=<?= $record['medium'] ?>&subject=<?= $record['subject'] ?>&date=<?= $record['date'] ?>" class="action-btn edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                <?php else: ?>
                                                    <!-- Request edit for yesterday's attendance -->
                                                    <button class="action-btn request" onclick="requestUpdate(
                                                        '<?= $record['class'] ?>',
                                                        '<?= $record['medium'] ?>',
                                                        '<?= $record['subject'] ?>',
                                                        '<?= $record['date'] ?>',
                                                        this
                                                    )">
                                                        <i class="fas fa-edit"></i> Request Edit
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 11px; padding: 6px 0;">
                                                    Edit not allowed
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="mobile-history-cards">
                    <?php foreach ($attendance_history as $record): ?>
                        <div class="history-card fade-in">
                            <div class="card-row">
                                <div class="card-label">Date</div>
                                <div class="card-value">
                                    <span class="date-badge">
                                        <?= date('d M Y', strtotime($record['date'])) ?>
                                    </span>
                                    <?php if ($record['date'] == date('Y-m-d')): ?>
                                        <span style="color: var(--success); font-size: 11px; margin-left: 5px;">
                                            <i class="fas fa-star"></i> Today
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-row">
                                <div class="card-label">Class</div>
                                <div class="card-value">Class <?= htmlspecialchars($record['class']) ?></div>
                            </div>

                            <div class="card-row">
                                <div class="card-label">Medium</div>
                                <div class="card-value"><?= htmlspecialchars($record['medium']) ?></div>
                            </div>

                            <div class="card-row">
                                <div class="card-label">Subject</div>
                                <div class="card-value"><?= htmlspecialchars($record['subject']) ?></div>
                            </div>

                            <div class="card-row">
                                <div class="card-label">Attendance</div>
                                <div class="card-value">
                                    <div class="attendance-summary">
                                        <span class="status-badge status-present">
                                            <i class="fas fa-check"></i> P: <?= $record['present_count'] ?>
                                        </span>
                                        <span class="status-badge status-absent">
                                            <i class="fas fa-times"></i> A: <?= $record['absent_count'] ?>
                                        </span>
                                        <span class="status-badge status-suspended">
                                            <i class="fas fa-clock"></i> S: <?= $record['suspended_count'] ?>
                                        </span>
                                        <span class="status-badge status-remaining">
                                            <i class="fas fa-user-clock"></i> R: <?= $record['remaining_count'] ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="card-row">
                                <div class="card-label">Submitted</div>
                                <div class="card-value">
                                    <?php if ($record['submitted_at']): ?>
                                        <?= date('d M Y, h:i A', strtotime($record['submitted_at'])) ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">Not recorded</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-row">
                                <div class="card-label">Actions</div>
                                <div class="card-value">
                                    <div class="action-buttons">
                                        <!-- View Button -->
                                        <button class="action-btn view" onclick="viewAttendanceDetails(
                                            '<?= $record['class'] ?>',
                                            '<?= $record['medium'] ?>',
                                            '<?= $record['subject'] ?>',
                                            '<?= $record['date'] ?>'
                                        )">
                                            <i class="fas fa-eye"></i> View
                                        </button>

                                        <?php
                                        // Check date restrictions - Allow edit only for today and yesterday
                                        $attendance_date = $record['date'];
                                        $today = date('Y-m-d');
                                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                                        $is_editable_date = ($attendance_date == $today || $attendance_date == $yesterday);

                                        // Check if request already exists
                                        $check_req = $conn->prepare("
                                            SELECT id, status FROM attendance_update_requests 
                                            WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=?
                                        ");
                                        $check_req->bind_param("iisss", $teacher_id, $record['class'], $record['medium'], $record['subject'], $attendance_date);
                                        $check_req->execute();
                                        $req_result = $check_req->get_result();
                                        $existing_request = $req_result->fetch_assoc();

                                        // Check if teacher can edit directly (for today only)
                                        $can_edit_directly = ($attendance_date == $today);
                                        ?>

                                        <?php if ($is_editable_date): ?>
                                            <?php if ($existing_request): ?>
                                                <?php
                                                $request_status = $existing_request['status'];
                                                $status_class = '';
                                                $status_text = ucfirst($request_status);

                                                switch($request_status) {
                                                    case 'pending':
                                                        $status_class = 'status-badge status-suspended';
                                                        break;
                                                    case 'approved':
                                                        $status_class = 'status-badge status-present';
                                                        // Create edit link for approved requests
                                                        $edit_link = "teacher_attendance.php?update_request=" . $existing_request['id'];
                                                        break;
                                                    case 'rejected':
                                                        $status_class = 'status-badge status-absent';
                                                        break;
                                                    case 'completed':
                                                        $status_class = 'status-badge status-remaining';
                                                        break;
                                                }
                                                ?>

                                                <?php if ($request_status == 'approved' && isset($edit_link)): ?>
                                                    <a href="<?= $edit_link ?>" class="action-btn edit">
                                                        <i class="fas fa-edit"></i> Edit Now
                                                    </a>
                                                <?php else: ?>
                                                    <span class="<?= $status_class ?>" style="cursor: default; padding: 6px 12px; font-size: 12px; display: inline-block; margin-top: 5px;">
                                                        <?= $status_text ?>
                                                    </span>
                                                <?php endif; ?>

                                            <?php elseif ($can_edit_directly): ?>
                                                <!-- Direct edit for today's attendance -->
                                                <a href="teacher_attendance.php?class=<?= $record['class'] ?>&medium=<?= $record['medium'] ?>&subject=<?= $record['subject'] ?>&date=<?= $record['date'] ?>" class="action-btn edit">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                            <?php else: ?>
                                                <!-- Request edit for yesterday's attendance -->
                                                <button class="action-btn request" onclick="requestUpdate(
                                                    '<?= $record['class'] ?>',
                                                    '<?= $record['medium'] ?>',
                                                    '<?= $record['subject'] ?>',
                                                    '<?= $record['date'] ?>',
                                                    this
                                                )">
                                                    <i class="fas fa-edit"></i> Request Edit
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 11px; padding: 6px 0; display: inline-block;">
                                                Edit not allowed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- Empty State -->
                <div class="card">
                    <div class="no-data">
                        <i class="fas fa-calendar-times"></i>
                        <h3 style="margin-bottom: 10px;">No Attendance Records Found</h3>
                        <p>You haven't submitted any attendance yet, or no records match your filters.</p>
                        <p style="margin-top: 10px; color: rgba(3, 3, 3, 0.7);">
                            Start by marking attendance for today's classes.
                        </p>
                        <a href="teacher_attendance.php" class="btn btn-primary" style="margin-top: 20px; max-width: 200px; margin-left: auto; margin-right: auto;">
                            <i class="fas fa-plus"></i> Mark New Attendance
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?= date('Y') ?> Ruchi Classes. All rights reserved.</p>
                <p style="margin-top: 5px; font-size: 0.8rem; opacity: 0.7;">
                    <i class="fas fa-shield-alt"></i> Secure Attendance System
                </p>
            </div>
        </div>
    </div>

    <!-- SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ==================== SIDEBAR TOGGLE FUNCTION ====================
            const toggleBtn = document.getElementById('toggleSidebar');
            const toggleIcon = document.getElementById('toggleIcon');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('sidebarOverlay');
            const profileMenu = document.getElementById('profileMenu');
            const logoImg = document.getElementById('logoImg');
            const logoText = document.getElementById('logoText');

            function handleSidebarToggle() {
                if (window.innerWidth < 1025) {
                    // Mobile toggle
                    sidebar.classList.toggle('active');
                    overlay.classList.toggle('active');
                    document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';

                    logoImg.style.width = '85px';
                    logoImg.style.height = '85px';
                    if (logoText) logoText.style.display = 'block';
                } else {
                    // Desktop toggle
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');

                    if (sidebar.classList.contains('collapsed')) {
                        if (toggleIcon) toggleIcon.classList.replace('fa-bars', 'fa-ellipsis-h');
                        logoImg.style.width = '70px';
                        logoImg.style.height = '70px';
                        if (logoText) logoText.style.display = 'none';
                    } else {
                        if (toggleIcon) toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
                        logoImg.style.width = '85px';
                        logoImg.style.height = '85px';
                        if (logoText) logoText.style.display = 'block';
                    }
                }
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', handleSidebarToggle);
            }

            // Close sidebar on mobile overlay click
            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }

            // ==================== PROFILE MENU TOGGLE ====================
            window.toggleProfileMenu = function(event) {
                event.stopPropagation();
                const rect = event.currentTarget.getBoundingClientRect();

                profileMenu.style.display = 'block';
                profileMenu.style.top = (rect.bottom + window.scrollY + 10) + 'px';
                profileMenu.style.right = (window.innerWidth - rect.right + 10) + 'px';

                // Close menu when clicking outside
                setTimeout(() => {
                    document.addEventListener('click', function closeMenu(e) {
                        if (!profileMenu.contains(e.target) && !e.target.closest('.user-profile')) {
                            profileMenu.style.display = 'none';
                            document.removeEventListener('click', closeMenu);
                        }
                    });
                }, 100);
            };

            // ==================== DROPDOWN FUNCTIONALITY ====================
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

            // ==================== MOBILE SIDEBAR BEHAVIOR ====================
            if (window.innerWidth < 1025) {
                document.querySelectorAll('.nav-item:not(.dropdown), .dropdown-item').forEach(link => {
                    link.addEventListener('click', function() {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';

                        document.querySelectorAll('.dropdown').forEach(drop => {
                            drop.classList.remove('open');
                        });
                    });
                });
            }

            // ==================== FORM VALIDATIONS ====================
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');

            if (startDateInput && endDateInput) {
                endDateInput.min = startDateInput.value;

                startDateInput.addEventListener('change', function() {
                    endDateInput.min = this.value;
                    if (endDateInput.value < this.value) {
                        endDateInput.value = this.value;
                    }
                });
            }

            // ==================== WINDOW RESIZE HANDLER ====================
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 1025) {
                        // Desktop view
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';

                        if (sidebar.classList.contains('collapsed')) {
                            if (toggleIcon) toggleIcon.classList.replace('fa-bars', 'fa-ellipsis-h');
                            logoImg.style.width = '70px';
                            logoImg.style.height = '70px';
                            if (logoText) logoText.style.display = 'none';
                        } else {
                            if (toggleIcon) toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
                            logoImg.style.width = '85px';
                            logoImg.style.height = '85px';
                            if (logoText) logoText.style.display = 'block';
                        }
                    } else {
                        // Mobile view
                        sidebar.classList.remove('collapsed');
                        mainContent.classList.remove('expanded');
                        if (toggleIcon) toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');

                        logoImg.style.width = '85px';
                        logoImg.style.height = '85px';
                        if (logoText) logoText.style.display = 'block';
                    }

                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                }, 250);
            });

            // ==================== KEYBOARD SHORTCUTS ====================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (window.innerWidth < 1025 && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }

                    profileMenu.style.display = 'none';

                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                }
            });

            // ==================== LOGOUT CONFIRMATION ====================
            window.confirmLogout = function(event) {
                if (event) event.preventDefault();

                Swal.fire({
                    title: 'Are you sure?',
                    text: "You will be logged out of your account.",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, logout',
                    cancelButtonText: 'Cancel',
                    background: '#ffffff',
                    backdrop: 'rgba(0,0,0,0.3)'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Logging out...',
                            html: 'Please wait while we securely log you out.',
                            timer: 1500,
                            timerProgressBar: true,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        }).then(() => {
                            window.location.href = 'teacher_logout.php';
                        });
                    }
                });
            };

            // ==================== SESSION TIMEOUT WARNING ====================
            let sessionTime = 1800; // 30 minutes in seconds
            let warningShown = false;

            function checkSessionTimeout() {
                if (sessionTime <= 300 && !warningShown) { // 5 minutes warning
                    warningShown = true;
                    Swal.fire({
                        icon: 'warning',
                        title: 'Session Expiring Soon',
                        text: 'Your session will expire in 5 minutes. Please save your work.',
                        timer: 10000,
                        timerProgressBar: true,
                        showConfirmButton: false,
                        allowOutsideClick: false
                    });
                }

                if (sessionTime <= 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Session Expired',
                        text: 'Your session has expired. Please login again.',
                        timer: 3000,
                        timerProgressBar: true,
                        showConfirmButton: false,
                        allowOutsideClick: false
                    }).then(() => {
                        window.location.href = 'teacher_logout.php?timeout=1';
                    });
                }

                sessionTime--;
            }

            setInterval(checkSessionTimeout, 60000);

            let activityTimer;
            document.addEventListener('mousemove', () => {
                clearTimeout(activityTimer);
                activityTimer = setTimeout(() => {
                    sessionTime = 1800;
                    warningShown = false;
                }, 10000);
            });
        });

        // Functions for attendance actions
        function viewAttendanceDetails(classVal, medium, subject, date) {
            window.location.href = `view_attendance_details.php?class=${classVal}&medium=${medium}&subject=${subject}&date=${date}`;
        }

        function markPendingRequest(triggerButton) {
            if (!(triggerButton instanceof HTMLElement)) {
                return;
            }

            const actionContainer = triggerButton.closest('.action-buttons');
            if (!actionContainer) {
                return;
            }

            actionContainer.querySelectorAll('.action-btn.request').forEach((button) => {
                button.remove();
            });

            if (!actionContainer.querySelector('.status-badge')) {
                const pendingBadge = document.createElement('span');
                pendingBadge.className = 'status-badge status-suspended';
                pendingBadge.style.cursor = 'default';
                pendingBadge.style.padding = '6px 12px';
                pendingBadge.style.fontSize = '12px';
                pendingBadge.style.display = 'inline-block';
                pendingBadge.style.marginTop = '5px';
                pendingBadge.textContent = 'Pending';
                actionContainer.appendChild(pendingBadge);
            }
        }

        function requestUpdate(classVal, medium, subject, date, triggerButton) {
            Swal.fire({
                title: 'Request Attendance Update',
                html: `
                    <div style="text-align: left; margin: 15px 0;">
                        <div style="margin-bottom: 15px;">
                            <strong>Details:</strong>
                            <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-top: 5px; font-size: 14px;">
                                <div><i class="fas fa-calendar"></i> Date: ${date}</div>
                                <div><i class="fas fa-graduation-cap"></i> Class: ${classVal}</div>
                                <div><i class="fas fa-language"></i> Medium: ${medium}</div>
                                <div><i class="fas fa-book"></i> Subject: ${subject}</div>
                            </div>
                        </div>
                        <div>
                            <label for="reason" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px;">
                                <i class="fas fa-comment"></i> Reason for Update:
                            </label>
                            <textarea id="reason" style="width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px; resize: vertical; font-size: 14px;" 
                                      placeholder="Please explain why you need to update this attendance..." rows="3"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Submit Request',
                cancelButtonText: 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const reason = document.getElementById('reason').value.trim();
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason for the update request');
                        return false;
                    }

                    return fetch('submit_update_request.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            class: classVal,
                            medium: medium,
                            subject: subject,
                            date: date,
                            reason: reason,
                            csrf_token: '<?= $csrf_token ?>'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            return data;
                        } else {
                            throw new Error(data.message || 'Failed to submit request');
                        }
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Request Submitted!',
                        text: 'Your update request has been sent to the administrator for approval.',
                        timer: 3000,
                        showConfirmButton: false
                    }).then(() => {
                        markPendingRequest(triggerButton);
                    });
                }
            });
        }
    </script>
<?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>