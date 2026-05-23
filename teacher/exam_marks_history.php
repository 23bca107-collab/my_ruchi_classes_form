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

// Check if teacher is logged in
if (!isTeacherAuthenticated()) {
    header("Location: teacher_login.php");
    exit();
}

// Get teacher info
$teacher = getTeacherInfo();
updateTeacherLastActivity();
$csrf_token = generateTeacherCSRFToken();
$teacher_id = $_SESSION['teacher_id'];

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
if ($table_check && $table_check->num_rows > 0) {
    $messages_stmt = $conn->prepare("SELECT COUNT(*) as msg_count FROM messages WHERE receiver_id=? AND receiver_type='teacher' AND status='unread'");
    $messages_stmt->bind_param("i", $teacher_id);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    $messages_count = $messages_result->fetch_assoc()['msg_count'];
}

// ==================== HANDLE DELETE ====================
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    $delete_stmt = $conn->prepare("DELETE FROM exam_marks WHERE id = ?");
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success_message'] = "✅ Marks entry deleted successfully!";
    } else {
        $_SESSION['error_message'] = "❌ Failed to delete marks entry.";
    }
    $delete_stmt->close();
    
    header("Location: exam_marks_history.php");
    exit();
}

// ==================== HANDLE UPDATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_marks'])) {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateTeacherCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'Security token invalid. Please try again.';
        header("Location: exam_marks_history.php");
        exit();
    }
    
    $entry_id = intval($_POST['entry_id'] ?? 0);
    $obtained_marks = intval($_POST['obtained_marks'] ?? 0);
    $exam_id = intval($_POST['exam_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    
    // Get total marks for this exam
    $total_marks = 100;
    $total_query = $conn->prepare("SELECT total_marks FROM exams WHERE id = ?");
    $total_query->bind_param("i", $exam_id);
    $total_query->execute();
    $total_result = $total_query->get_result();
    if ($total_result->num_rows > 0) {
        $total_marks = $total_result->fetch_assoc()['total_marks'] ?? 100;
    }
    
    // Validate marks range
    if ($obtained_marks < 0 || $obtained_marks > $total_marks) {
        $_SESSION['error_message'] = "❌ Marks must be between 0 and $total_marks.";
        header("Location: exam_marks_history.php");
        exit();
    }
    
    $update_stmt = $conn->prepare("UPDATE exam_marks SET obtained_marks = ? WHERE id = ?");
    $update_stmt->bind_param("ii", $obtained_marks, $entry_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['success_message'] = "✅ Marks updated successfully!";
    } else {
        $_SESSION['error_message'] = "❌ Failed to update marks.";
    }
    $update_stmt->close();
    
    header("Location: exam_marks_history.php");
    exit();
}

// ==================== FILTERS ====================
$class_filter = $_GET['class'] ?? '';
$medium_filter = $_GET['medium'] ?? '';
$exam_filter = $_GET['exam_id'] ?? '';

// Build query with filters
$query = "
    SELECT 
        em.id,
        em.exam_id,
        em.student_id,
        em.obtained_marks,
        e.subject,
        e.topic,
        e.exam_date,
        e.exam_time,
        e.total_marks,
        e.class,
        e.medium,
        CASE 
            WHEN e.medium = 'English' THEN se.first_name
            ELSE sh.first_name
        END as student_first_name,
        CASE 
            WHEN e.medium = 'English' THEN se.last_name
            ELSE sh.last_name
        END as student_last_name,
        CASE 
            WHEN e.medium = 'English' THEN se.father_name
            ELSE sh.father_name
        END as student_father_name
    FROM exam_marks em
    JOIN exams e ON em.exam_id = e.id
    LEFT JOIN student_english se ON (e.medium = 'English' AND em.student_id = se.id)
    LEFT JOIN student_hindi sh ON (e.medium = 'Hindi' AND em.student_id = sh.id)
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($class_filter)) {
    $query .= " AND e.class = ?";
    $params[] = $class_filter;
    $types .= "s";
}

if (!empty($medium_filter)) {
    $query .= " AND e.medium = ?";
    $params[] = $medium_filter;
    $types .= "s";
}

if (!empty($exam_filter)) {
    $query .= " AND e.id = ?";
    $params[] = $exam_filter;
    $types .= "i";
}

$query .= " ORDER BY e.exam_date DESC, e.exam_time DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$marks_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all exams for filter dropdown
$exams = $conn->query("SELECT id, subject, class, medium FROM exams ORDER BY exam_date DESC")->fetch_all(MYSQLI_ASSOC);

// Get edit data if edit_id is set
$edit_entry = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    foreach ($marks_history as $entry) {
        if ($entry['id'] == $edit_id) {
            $edit_entry = $entry;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Exam Marks History - Ruchi Classes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ========== CSS VARIABLES - UNIFIED DESIGN SYSTEM ========== */
        :root {
            /* Colors - Professional Blue Theme */
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --primary-soft: rgba(37, 99, 235, 0.1);
            --secondary: #f8fafc;
            --secondary-light: #f1f5f9;
            --accent: #f59e0b;
            --accent-light: #fbbf24;
            --success: #10b981;
            --success-dark: #059669;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --info: #06b6d4;
            --info-dark: #0284c7;
            
            /* Text Colors */
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            --text-white: #ffffff;
            
            /* Background Colors */
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-card: #ffffff;
            --bg-hover: #f1f5f9;
            --bg-sidebar: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            --bg-main: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --bg-header: rgba(255, 255, 255, 0.9);
            
            /* Borders & Shadows */
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
            --shadow-hover: 0 20px 40px -10px rgba(37, 99, 235, 0.2);
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-warning: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-danger: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --gradient-info: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%);
            
            /* Spacing - Consistent Scale */
            --space-1: 0.25rem;  /* 4px */
            --space-2: 0.5rem;   /* 8px */
            --space-3: 0.75rem;  /* 12px */
            --space-4: 1rem;     /* 16px */
            --space-5: 1.25rem;  /* 20px */
            --space-6: 1.5rem;   /* 24px */
            --space-8: 2rem;     /* 32px */
            --space-10: 2.5rem;  /* 40px */
            --space-12: 3rem;    /* 48px */
            
            /* Border Radius */
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-full: 9999px;
            
            /* Transitions */
            --transition-fast: 0.2s ease;
            --transition-base: 0.3s ease;
            --transition-slow: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Layout */
            --sidebar-width-desktop: 280px;
            --sidebar-width-mobile: 320px;
            --sidebar-collapsed-width: 85px;
            --header-height: 80px;
            --header-height-mobile: 70px;
            
            /* Z-index layers */
            --z-sidebar: 1000;
            --z-overlay: 999;
            --z-modal: 9999;
            --z-popup: 10000;
            --z-notification: 9999;
        }

        /* ========== RESET & BASE STYLES ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background: var(--bg-main);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ========== SIDEBAR STYLES - UNIFIED ========== */
        .sidebar {
            width: var(--sidebar-width-desktop);
            background: var(--bg-sidebar);
            padding: var(--space-6) var(--space-4);
            transition: all var(--transition-slow);
            box-shadow: var(--shadow);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            border-right: 1px solid var(--border);
            z-index: var(--z-sidebar);
            transform: translateX(-100%);
            scrollbar-width: thin;
            scrollbar-color: var(--primary) var(--border);
        }

        @media (min-width: 1024px) {
            .sidebar {
                transform: translateX(0);
            }
        }

        .sidebar.active {
            transform: translateX(0);
            animation: slideInLeft var(--transition-slow);
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
            padding: var(--space-6) var(--space-2);
        }

        /* Logo Container */
        .logo-container {
            display: flex;
            align-items: center;
            gap: var(--space-4);
            margin-bottom: var(--space-8);
            padding: 0 var(--space-3);
            transition: all var(--transition-base);
            height: 90px;
            overflow: hidden;
        }

        .sidebar.collapsed .logo-container {
            padding: 0 var(--space-2);
            justify-content: center;
            gap: 0;
            height: 85px;
            margin-bottom: var(--space-6);
        }

        .logo-img {
            width: 85px;
            height: 85px;
            border-radius: var(--radius-lg);
            object-fit: contain;
            background: white;
            padding: var(--space-2);
            border: 4px solid var(--primary);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25);
            transition: all var(--transition-slow);
            display: block;
            flex-shrink: 0;
        }

        .sidebar.collapsed .logo-img {
            width: 70px;
            height: 70px;
            border-radius: var(--radius-md);
            border-width: 3px;
            padding: 6px;
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
            transition: all var(--transition-base);
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

        /* Navigation Items */
        .nav-item {
            display: flex;
            align-items: center;
            padding: var(--space-4) var(--space-5);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-2);
            cursor: pointer;
            transition: all var(--transition-base);
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
            transition: var(--transition-fast);
            border-radius: 0 4px 4px 0;
        }

        .nav-item:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
            transform: translateX(5px);
            box-shadow: var(--shadow-sm);
        }

        .nav-item:hover::before {
            transform: scaleY(1);
        }

        .nav-item.active {
            background: var(--gradient-primary);
            color: var(--text-white);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }

        .nav-item.active::before {
            transform: scaleY(1);
            background: var(--accent-light);
        }

        .nav-icon {
            margin-right: var(--space-4);
            font-size: 20px;
            width: 28px;
            text-align: center;
            transition: all var(--transition-base);
            flex-shrink: 0;
        }

        .nav-item:hover .nav-icon {
            transform: scale(1.1);
        }

        .nav-text {
            font-size: 15px;
            font-weight: 500;
            transition: all var(--transition-base);
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
            padding: var(--space-5) 0;
            margin: 0 5px var(--space-2);
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

        /* Overlay for Mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: var(--z-overlay);
            backdrop-filter: blur(5px);
        }

        .sidebar-overlay.active {
            display: block;
            animation: fadeIn var(--transition-fast);
        }

        /* Dropdown Styles */
        .dropdown {
            position: relative;
            cursor: pointer;
        }

        .dropdown-icon {
            margin-left: auto;
            transition: all var(--transition-slow);
            font-size: 16px;
            opacity: 0.7;
        }

        .dropdown-menu {
            display: none;
            flex-direction: column;
            margin-left: 50px;
            margin-top: var(--space-2);
            background: var(--bg-card);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .dropdown.open + .dropdown-menu {
            display: flex;
            animation: slideDown var(--transition-slow);
        }

        .dropdown.open .dropdown-icon {
            transform: rotate(180deg);
            opacity: 1;
        }

        .dropdown.open {
            background: var(--primary-soft) !important;
            border-left: 4px solid var(--primary) !important;
        }

        .dropdown.open .nav-icon,
        .dropdown.open .nav-text {
            color: var(--primary) !important;
            font-weight: 600 !important;
        }

        .dropdown-item {
            padding: var(--space-4) var(--space-5);
            text-decoration: none;
            font-size: 15px;
            margin: 0;
            color: var(--text-secondary);
            transition: all var(--transition-base);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item:hover {
            background: var(--gradient-primary);
            color: var(--text-white);
            transform: translateX(8px);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }

        /* Profile Menu */
        .profile-menu {
            display: none;
            position: fixed;
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            padding: var(--space-2) 0;
            min-width: 200px;
            z-index: var(--z-popup);
            border: 1px solid var(--border);
        }

        .profile-menu a {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-5);
            text-decoration: none;
            color: var(--text-primary);
            transition: all var(--transition-fast);
        }

        .profile-menu a:hover {
            background: var(--bg-hover);
        }

        .profile-menu hr {
            margin: var(--space-2) 0;
            border: 1px solid var(--border-light);
        }

        /* ========== MAIN CONTENT STYLES ========== */
        .main-content {
            flex: 1;
            margin-left: 0;
            padding: var(--space-6);
            transition: all var(--transition-slow);
            background: var(--bg-main);
            position: relative;
            min-height: 100vh;
            width: 100%;
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: var(--sidebar-width-desktop);
            }
            
            .main-content.expanded {
                margin-left: var(--sidebar-collapsed-width);
            }
        }

        .main-content::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(37, 99, 235, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(245, 158, 11, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
            padding: var(--space-4) var(--space-6);
            background: var(--bg-header);
            border-radius: var(--radius-lg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            position: relative;
            z-index: 1;
            box-shadow: var(--shadow-sm);
            height: var(--header-height);
        }

        @media (max-width: 768px) {
            .header {
                padding: var(--space-3) var(--space-4);
                height: var(--header-height-mobile);
            }
        }

        .toggle-sidebar {
            background: var(--gradient-primary);
            border: none;
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-base);
            color: var(--text-white);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
            flex-shrink: 0;
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
            gap: var(--space-4);
        }

        .notifications {
            position: relative;
            padding: var(--space-3);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: var(--transition-base);
            color: var(--text-secondary);
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .notifications:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: var(--text-white);
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
            gap: var(--space-4);
            cursor: pointer;
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-md);
            transition: var(--transition-base);
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .user-profile:hover {
            background: var(--bg-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
            flex-shrink: 0;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        @media (max-width: 768px) {
            .user-name {
                display: none;
            }
            
            .user-profile {
                padding: var(--space-2);
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
            }
            
            .notifications {
                padding: var(--space-2);
            }
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            padding: 0 var(--space-4);
            position: relative;
            z-index: 1;
        }

        /* Page Title */
        .page-title {
            text-align: center;
            margin-bottom: var(--space-8);
        }

        .page-title h1 {
            color: var(--text-primary);
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            margin-bottom: var(--space-2);
            font-weight: 800;
        }

        .page-title p {
            color: var(--text-secondary);
            font-size: clamp(1rem, 2vw, 1.1rem);
        }

        /* Teacher Info Badge */
        .teacher-info-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            background: var(--gradient-primary);
            color: var(--text-white);
            padding: var(--space-2) var(--space-5);
            border-radius: var(--radius-full);
            font-weight: 600;
            margin: var(--space-4) auto var(--space-8);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
            width: fit-content;
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: all var(--transition-base);
            animation: fadeIn 0.8s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .card h2 {
            color: var(--primary);
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            gap: var(--space-2);
            font-size: clamp(1.2rem, 3vw, 1.5rem);
        }

        .card h2 i {
            font-size: 1.8rem;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .form-group label i {
            margin-right: var(--space-1);
            color: var(--primary);
        }

        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            font-size: 1rem;
            transition: all var(--transition-fast);
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right var(--space-3) center;
            padding-right: var(--space-8);
        }

        /* Buttons */
        .btn {
            padding: var(--space-3) var(--space-6);
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all var(--transition-base);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: var(--text-white);
        }

        .btn-success {
            background: var(--gradient-success);
            color: var(--text-white);
        }

        .btn-warning {
            background: var(--gradient-warning);
            color: var(--text-white);
        }

        .btn-danger {
            background: var(--gradient-danger);
            color: var(--text-white);
        }

        .btn-info {
            background: var(--gradient-info);
            color: var(--text-white);
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            width: 100%;
        }

        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: 0.85rem;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Summary Stats */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .stat-card {
            background: var(--gradient-primary);
            color: var(--text-white);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            text-align: center;
            box-shadow: var(--shadow);
            transition: all var(--transition-base);
        }

        .stat-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--shadow-hover);
        }

        .stat-value {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 800;
            margin-bottom: var(--space-1);
            line-height: 1;
        }

        .stat-label {
            font-size: clamp(0.8rem, 2vw, 0.9rem);
            opacity: 0.9;
        }

        /* Table Styles - UNIFIED for both mobile & laptop */
        .table-wrapper {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-top: var(--space-4);
            box-shadow: var(--shadow);
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: var(--primary) var(--border);
            max-width: 100%;
            border-radius: var(--radius-lg);
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: var(--border);
            border-radius: var(--radius-full);
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: var(--radius-full);
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1000px;
            background: var(--bg-card);
        }

        .history-table th {
            background: var(--gradient-primary);
            color: var(--text-white);
            font-weight: 600;
            padding: var(--space-4) var(--space-3);
            text-align: left;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 14px;
        }

        .history-table td {
            padding: var(--space-4) var(--space-3);
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
            vertical-align: middle;
        }

        .history-table tr:hover td {
            background: var(--bg-hover);
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        /* Student Info in Table */
        .student-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            min-width: 200px;
        }

        .student-details {
            min-width: 0;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .student-id {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Badges */
        .badge-success {
            background: var(--success);
            color: var(--text-white);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .badge-warning {
            background: var(--warning);
            color: var(--text-white);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .badge-danger {
            background: var(--danger);
            color: var(--text-white);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: var(--space-2);
            flex-wrap: wrap;
            min-width: 120px;
        }

        .topic-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Edit Modal */
        .edit-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: var(--z-modal);
            backdrop-filter: blur(5px);
            padding: var(--space-4);
        }

        .edit-modal-content {
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn var(--transition-base);
            box-shadow: var(--shadow-lg);
        }

        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 2px solid var(--border);
        }

        .edit-modal-header h3 {
            color: var(--primary);
            font-size: clamp(1.2rem, 3vw, 1.5rem);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            transition: var(--transition-fast);
        }

        .close-modal:hover {
            color: var(--danger);
            transform: rotate(90deg);
        }

        .edit-form-group {
            margin-bottom: var(--space-4);
        }

        .edit-form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 600;
            color: var(--text-primary);
        }

        .edit-form-group input[readonly] {
            background: var(--bg-secondary);
            cursor: not-allowed;
        }

        .edit-actions {
            display: flex;
            gap: var(--space-4);
            margin-top: var(--space-6);
            flex-wrap: wrap;
        }

        .edit-actions button,
        .edit-actions a {
            flex: 1;
            min-width: 140px;
        }

        /* Messages */
        .success-message,
        .error-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: var(--z-notification);
            padding: var(--space-4) var(--space-6);
            border-radius: var(--radius-md);
            color: var(--text-white);
            animation: slideIn var(--transition-base);
            box-shadow: var(--shadow-lg);
            max-width: 400px;
            width: calc(100% - 40px);
        }

        .success-message {
            background: var(--gradient-success);
        }

        .error-message {
            background: var(--gradient-danger);
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: var(--space-10) var(--space-4);
            color: var(--text-muted);
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: var(--space-4);
            color: var(--border);
            opacity: 0.5;
        }

        .no-data h3 {
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }

        .no-data p {
            margin-bottom: var(--space-4);
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: var(--space-8);
            color: var(--text-secondary);
            font-size: 0.9rem;
            padding: var(--space-4);
            border-top: 1px solid var(--border);
        }

        /* ========== ANIMATIONS ========== */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
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

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* ========== RESPONSIVE BREAKPOINTS ========== */
        
        /* Desktop (1024px and above) */
        @media (min-width: 1024px) {
            .sidebar {
                transform: translateX(0);
            }
            
            .sidebar-overlay {
                display: none !important;
            }
            
            .dropdown-menu {
                position: relative;
                margin-left: 50px;
            }
            
            .main-content {
                margin-left: var(--sidebar-width-desktop);
            }
        }

        /* Tablet (768px to 1023px) */
        @media (max-width: 1023px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width-mobile);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: var(--space-4);
            }
            
            .dropdown-menu {
                position: static !important;
                margin-left: 0 !important;
                margin-top: var(--space-2) !important;
                width: 100%;
            }
            
            .dropdown-item {
                padding: var(--space-4) var(--space-5) !important;
                font-size: 16px !important;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-sm {
                width: 100%;
            }
        }

        /* Mobile (767px and below) */
        @media (max-width: 767px) {
            :root {
                --space-6: 1.25rem;
                --space-8: 1.5rem;
            }
            
            .main-content {
                padding: var(--space-3);
            }
            
            .container {
                padding: 0 var(--space-2);
            }
            
            .card {
                padding: var(--space-4);
            }
            
            .page-title h1 {
                font-size: 1.5rem;
            }
            
            .summary-stats {
                grid-template-columns: 1fr;
                gap: var(--space-3);
            }
            
            .stat-card {
                padding: var(--space-3);
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .history-table th,
            .history-table td {
                padding: var(--space-2) var(--space-2);
                font-size: 13px;
            }
            
            .student-info {
                min-width: 150px;
            }
            
            .student-name {
                font-size: 13px;
            }
            
            .badge-success,
            .badge-warning,
            .badge-danger {
                padding: 2px 6px;
                font-size: 11px;
            }
            
            .edit-modal-content {
                padding: var(--space-4);
            }
            
            .edit-actions {
                flex-direction: column;
            }
            
            .edit-actions button,
            .edit-actions a {
                width: 100%;
            }
            
            .success-message,
            .error-message {
                top: 10px;
                right: 10px;
                padding: var(--space-3) var(--space-4);
                font-size: 14px;
            }
            
            .toggle-sidebar {
                width: 45px;
                height: 45px;
            }
        }

        /* Small Mobile (480px and below) */
        @media (max-width: 480px) {
            .user-avatar {
                width: 36px;
                height: 36px;
            }
            
            .notifications {
                padding: var(--space-2);
            }
            
            .notification-badge {
                width: 18px;
                height: 18px;
                font-size: 10px;
            }
            
            .teacher-info-badge {
                padding: var(--space-1) var(--space-3);
                font-size: 13px;
            }
            
            .btn {
                padding: var(--space-2) var(--space-4);
                font-size: 0.9rem;
            }
            
            .form-control {
                padding: var(--space-2) var(--space-3);
                font-size: 14px;
            }
            
            .history-table th,
            .history-table td {
                padding: var(--space-2);
                font-size: 12px;
            }
            
            .student-info {
                min-width: 120px;
                gap: var(--space-2);
            }
            
            .student-id {
                font-size: 10px;
            }
            
            .action-buttons {
                min-width: 80px;
            }
            
            .btn-sm {
                padding: var(--space-1) var(--space-2);
                font-size: 11px;
            }
        }

        /* Landscape Mode */
        @media (max-height: 600px) and (orientation: landscape) {
            .sidebar {
                overflow-y: auto;
            }
            
            .logo-container {
                margin-bottom: var(--space-4);
            }
            
            .edit-modal-content {
                max-height: 80vh;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar,
            .header,
            .btn,
            .footer,
            .action-buttons {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 20px;
            }
            
            .card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ccc;
            }
        }

        /* Custom Scrollbar for WebKit */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-primary);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-primary { color: var(--primary); }
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        
        .mt-2 { margin-top: var(--space-2); }
        .mt-4 { margin-top: var(--space-4); }
        .mb-2 { margin-bottom: var(--space-2); }
        .mb-4 { margin-bottom: var(--space-4); }
        
        .d-flex { display: flex; }
        .align-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: var(--space-2); }
        .gap-4 { gap: var(--space-4); }
        
        .w-100 { width: 100%; }
        
        .hide-mobile {
            display: none;
        }
        
        @media (min-width: 768px) {
            .hide-mobile {
                display: block;
            }
        }
        
        .show-mobile {
            display: block;
        }
        
        @media (min-width: 768px) {
            .show-mobile {
                display: none;
            }
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
            <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo-img" id="logoImg"
                 onerror="this.src='https://via.placeholder.com/85x85?text=Ruchi'">
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
        <div class="nav-item dropdown" id="attendanceDropdown">
            <div class="nav-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="nav-text">Attendance</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="attendanceMenu">
            <a href="teacher_attendance.php" class="dropdown-item">
                <i class="fas fa-pencil-alt"></i> Mark Attendance
            </a>
            <a href="attendance_history.php" class="dropdown-item">
                <i class="fas fa-history"></i> Attendance History
            </a>
        </div>

        <!-- Exams Dropdown -->
        <div class="nav-item dropdown active" id="examsDropdown">
            <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
            <div class="nav-text">Exams</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="examsMenu">
            <a href="teacher_add_exam.php" class="dropdown-item">
                <i class="fas fa-plus-circle"></i> Add Exam
            </a>
            <a href="all_exam.php" class="dropdown-item">
                <i class="fas fa-list-alt"></i> All Exams
            </a>
            <a href="exam_marks_entry.php" class="dropdown-item">
                <i class="fas fa-pencil-alt"></i> Marks Entry
            </a>
            <a href="exam_marks_history.php" class="dropdown-item active">
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
            <button class="toggle-sidebar" id="toggleSidebar" aria-label="Toggle Sidebar">
                <i class="fas fa-bars" id="toggleIcon"></i>
            </button>
            <div class="user-menu">
                <?php teacher_notifications_render_button($teacher_notifications_data); ?>
                <div class="user-profile" onclick="toggleProfileMenu(event)" title="Profile">
                    <?php if (!empty($teacher['photo'])): ?>
                        <img src="<?php echo htmlspecialchars($teacher['photo']); ?>" 
                             alt="Profile" 
                             class="user-avatar"
                             onerror="this.src='https://via.placeholder.com/48x48?text=<?php echo urlencode(substr($teacher['first_name']??'T',0,1)); ?>'">
                    <?php else: ?>
                        <div class="user-avatar" style="background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px;">
                            <?php 
                            $initials = '';
                            if (!empty($teacher['first_name'])) {
                                $initials = substr($teacher['first_name'], 0, 1);
                                if (!empty($teacher['last_name'])) {
                                    $initials .= substr($teacher['last_name'], 0, 1);
                                }
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
            <div style="text-align: center;">
                <div class="teacher-info-badge">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <?php echo htmlspecialchars($teacher['subject']); ?> Teacher
                </div>
            </div>
            <?php endif; ?>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message" id="successMessage">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message" id="errorMessage">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
            <?php endif; ?>

            <div class="page-title">
                <h1><i class="fas fa-history"></i> Exam Marks History</h1>
                <p>View, edit, and manage all exam marks</p>
            </div>

            <!-- Summary Stats -->
            <?php if (!empty($marks_history)): 
                $total_entries = count($marks_history);
                $total_marks_sum = 0;
                $total_possible = 0;
                foreach ($marks_history as $entry) {
                    $total_marks_sum += $entry['obtained_marks'];
                    $total_possible += $entry['total_marks'] ?? 100;
                }
                $avg_percentage = $total_possible > 0 ? round(($total_marks_sum / $total_possible) * 100, 2) : 0;
            ?>
            <div class="summary-stats">
                <div class="stat-card">
                    <div class="stat-value"><?= $total_entries ?></div>
                    <div class="stat-label">Total Entries</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $total_entries > 0 ? round($total_marks_sum / $total_entries, 2) : 0 ?></div>
                    <div class="stat-label">Avg Marks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $avg_percentage ?>%</div>
                    <div class="stat-label">Avg Percentage</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Card -->
            <div class="card">
                <h2><i class="fas fa-filter"></i> Filter Marks</h2>
                <form method="get" id="filterForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class"><i class="fas fa-graduation-cap"></i> Class</label>
                            <select name="class" id="class" class="form-control">
                                <option value="">All Classes</option>
                                <?php for($i=8; $i<=12; $i++): ?>
                                <option value="<?= $i ?>" <?= ($class_filter == $i) ? 'selected' : '' ?>>
                                    Class <?= $i ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="medium"><i class="fas fa-language"></i> Medium</label>
                            <select name="medium" id="medium" class="form-control">
                                <option value="">All Mediums</option>
                                <option value="English" <?= ($medium_filter == 'English') ? 'selected' : '' ?>>English Medium</option>
                                <option value="Hindi" <?= ($medium_filter == 'Hindi') ? 'selected' : '' ?>>Hindi Medium</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="exam_id"><i class="fas fa-file-alt"></i> Exam</label>
                            <select name="exam_id" id="exam_id" class="form-control">
                                <option value="">All Exams</option>
                                <?php foreach($exams as $exam): ?>
                                <option value="<?= $exam['id'] ?>" <?= ($exam_filter == $exam['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($exam['subject']) ?> (Class <?= $exam['class'] ?> - <?= $exam['medium'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-filter w-100">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Edit Modal -->
            <?php if ($edit_entry): ?>
            <div class="edit-modal" id="editModal">
                <div class="edit-modal-content">
                    <div class="edit-modal-header">
                        <h3><i class="fas fa-edit"></i> Edit Marks</h3>
                        <button class="close-modal" onclick="closeEditModal()" aria-label="Close">&times;</button>
                    </div>
                    
                    <form method="post" id="editForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="entry_id" value="<?= $edit_entry['id'] ?>">
                        <input type="hidden" name="exam_id" value="<?= $edit_entry['exam_id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $edit_entry['student_id'] ?>">
                        
                        <div class="edit-form-group">
                            <label>Student Name</label>
                            <input type="text" value="<?= htmlspecialchars($edit_entry['student_first_name'] . ' ' . ($edit_entry['student_last_name'] ?? '')) ?>" readonly class="form-control">
                        </div>
                        
                        <div class="edit-form-group">
                            <label>Father's Name</label>
                            <input type="text" value="<?= htmlspecialchars($edit_entry['student_father_name'] ?? 'N/A') ?>" readonly class="form-control">
                        </div>
                        
                        <div class="edit-form-group">
                            <label>Exam Details</label>
                            <input type="text" value="<?= htmlspecialchars($edit_entry['subject'] . ' - ' . $edit_entry['topic']) ?>" readonly class="form-control">
                        </div>
                        
                        <div class="edit-form-group">
                            <label>Exam Date</label>
                            <input type="text" value="<?= date('d M Y', strtotime($edit_entry['exam_date'])) ?>" readonly class="form-control">
                        </div>
                        
                        <div class="edit-form-group">
                            <label for="obtained_marks">Obtained Marks (Max: <?= $edit_entry['total_marks'] ?? 100 ?>)</label>
                            <input type="number" 
                                   name="obtained_marks" 
                                   id="obtained_marks" 
                                   value="<?= $edit_entry['obtained_marks'] ?>"
                                   min="0" 
                                   max="<?= $edit_entry['total_marks'] ?? 100 ?>"
                                   required
                                   class="form-control"
                                   onchange="validateEditMark(this, <?= $edit_entry['total_marks'] ?? 100 ?>)">
                        </div>
                        
                        <div class="edit-actions">
                            <button type="submit" name="update_marks" class="btn btn-success" onclick="return confirmUpdate()">
                                <i class="fas fa-save"></i> Update Marks
                            </button>
                            <a href="exam_marks_history.php?<?= http_build_query($_GET) ?>" class="btn btn-danger">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Marks History Table -->
            <div class="card">
                <h2><i class="fas fa-list-alt"></i> Marks History</h2>
                
                <?php if (!empty($marks_history)): ?>
                <div class="table-wrapper">
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Medium</th>
                                    <th>Subject</th>
                                    <th>Topic</th>
                                    <th>Exam Date</th>
                                    <th>Marks</th>
                                    <th>Percentage</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marks_history as $entry): 
                                    $percentage = $entry['total_marks'] > 0 ? round(($entry['obtained_marks'] / $entry['total_marks']) * 100, 2) : 0;
                                    $status_class = '';
                                    $status_text = '';
                                    
                                    if ($percentage >= 80) {
                                        $status_class = 'badge-success';
                                        $status_text = 'Excellent';
                                    } elseif ($percentage >= 60) {
                                        $status_class = 'badge-success';
                                        $status_text = 'Good';
                                    } elseif ($percentage >= 40) {
                                        $status_class = 'badge-warning';
                                        $status_text = 'Average';
                                    } else {
                                        $status_class = 'badge-danger';
                                        $status_text = 'Poor';
                                    }
                                ?>
                                <tr>
                                    <td><strong>#<?= $entry['id'] ?></strong></td>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-details">
                                                <div class="student-name"><?= htmlspecialchars($entry['student_first_name'] . ' ' . ($entry['student_last_name'] ?? '')) ?></div>
                                                <div class="student-id">ID: <?= $entry['student_id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class <?= $entry['class'] ?></td>
                                    <td><?= $entry['medium'] ?></td>
                                    <td class="subject-cell"><?= htmlspecialchars($entry['subject']) ?></td>
                                    <td class="topic-cell" title="<?= htmlspecialchars($entry['topic']) ?>">
                                        <?= htmlspecialchars(substr($entry['topic'], 0, 30)) . (strlen($entry['topic']) > 30 ? '...' : '') ?>
                                    </td>
                                    <td><?= date('d M Y', strtotime($entry['exam_date'])) ?></td>
                                    <td><strong><?= $entry['obtained_marks'] ?></strong> / <?= $entry['total_marks'] ?? 100 ?></td>
                                    <td><?= $percentage ?>%</td>
                                    <td>
                                        <span class="<?= $status_class ?>">
                                            <?= $status_text ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?<?= http_build_query(array_merge($_GET, ['edit_id' => $entry['id']])) ?>" class="btn btn-warning btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="#" onclick="confirmDelete(<?= $entry['id'] ?>)" class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-history"></i>
                    <h3>No Marks Found</h3>
                    <p>No exam marks have been entered yet.</p>
                    <a href="exam_marks_entry.php" class="btn btn-success" style="margin-top: var(--space-4);">
                        <i class="fas fa-plus-circle"></i> Enter Marks
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?= date('Y') ?> Ruchi Classes. All rights reserved.</p>
                <p style="margin-top: var(--space-1); font-size: 0.8rem; opacity: 0.7;">
                    <i class="fas fa-shield-alt"></i> Secure Exam Management System
                </p>
            </div>
        </div>
    </div>

    <!-- SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // ==================== GLOBAL VARIABLES ====================
        let resizeTimer;

        // ==================== DOM CONTENT LOADED ====================
        document.addEventListener('DOMContentLoaded', function() {
            // Get DOM elements
            const toggleBtn = document.getElementById('toggleSidebar');
            const toggleIcon = document.getElementById('toggleIcon');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('sidebarOverlay');
            const profileMenu = document.getElementById('profileMenu');
            const logoImg = document.getElementById('logoImg');
            const logoText = document.getElementById('logoText');

            // ==================== SIDEBAR TOGGLE FUNCTION ====================
            function handleSidebarToggle() {
                if (window.innerWidth < 1024) { // Mobile/Tablet
                    sidebar.classList.toggle('active');
                    overlay.classList.toggle('active');
                    document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                    
                    // Ensure logo is visible in mobile
                    logoImg.style.width = '85px';
                    logoImg.style.height = '85px';
                    if (logoText) logoText.style.display = 'block';
                } else { // Desktop
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

            // Add click event to toggle button
            if (toggleBtn) {
                toggleBtn.addEventListener('click', handleSidebarToggle);
            }

            // ==================== OVERLAY CLICK ====================
            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                    
                    // Close all dropdowns
                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                });
            }

            // ==================== PROFILE MENU TOGGLE ====================
            window.toggleProfileMenu = function(event) {
                event.stopPropagation();
                const rect = event.currentTarget.getBoundingClientRect();
                
                profileMenu.style.display = 'block';
                
                // Position menu based on screen size
                if (window.innerWidth < 768) {
                    profileMenu.style.top = (rect.bottom + window.scrollY + 5) + 'px';
                    profileMenu.style.right = '10px';
                    profileMenu.style.left = 'auto';
                } else {
                    profileMenu.style.top = (rect.bottom + window.scrollY + 10) + 'px';
                    profileMenu.style.right = (window.innerWidth - rect.right + 10) + 'px';
                    profileMenu.style.left = 'auto';
                }
                
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

            // ==================== DROPDOWNS ====================
            document.querySelectorAll('.dropdown').forEach(drop => {
                drop.addEventListener('click', function(e) {
                    e.stopPropagation();
                    
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown').forEach(d => {
                        if (d !== this) d.classList.remove('open');
                    });
                    
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

            // ==================== ESC KEY HANDLER ====================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    // Close mobile sidebar
                    if (window.innerWidth < 1024 && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                    
                    // Close profile menu
                    profileMenu.style.display = 'none';
                    
                    // Close all dropdowns
                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                    
                    // Close edit modal if open
                    const editModal = document.getElementById('editModal');
                    if (editModal) {
                        window.location.href = 'exam_marks_history.php?<?= http_build_query(array_merge($_GET, ['edit_id' => null])) ?>';
                    }
                }
            });

            // ==================== MOBILE SIDEBAR AUTO-CLOSE ON NAVIGATION ====================
            if (window.innerWidth < 1024) {
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

            // ==================== WINDOW RESIZE HANDLER ====================
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 1024) {
                        // Desktop view
                        sidebar.classList.remove('active');
                        if (overlay) overlay.classList.remove('active');
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
                    
                    // Close all dropdowns on resize
                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                    
                    // Hide profile menu on resize
                    if (profileMenu) profileMenu.style.display = 'none';
                }, 250);
            });

            // ==================== INITIAL STATE ====================
            if (window.innerWidth < 1024) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        });

        // ==================== CLOSE EDIT MODAL ====================
        function closeEditModal() {
            window.location.href = 'exam_marks_history.php?<?= http_build_query(array_merge($_GET, ['edit_id' => null])) ?>';
        }

        // ==================== VALIDATE EDIT MARK ====================
        function validateEditMark(input, maxMarks) {
            let value = parseInt(input.value);
            if (isNaN(value) || input.value === '') {
                return true;
            }
            if (value < 0) {
                input.value = 0;
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Marks',
                    text: 'Marks cannot be negative!',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else if (value > maxMarks) {
                input.value = maxMarks;
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Marks',
                    text: `Marks cannot exceed ${maxMarks}!`,
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        }

        // ==================== CONFIRM UPDATE ====================
        function confirmUpdate() {
            Swal.fire({
                title: 'Update Marks?',
                text: "Are you sure you want to update these marks?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#2563eb',
                confirmButtonText: 'Yes, update them!',
                cancelButtonText: 'Cancel',
                background: '#ffffff',
                backdrop: 'rgba(0,0,0,0.3)'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Updating...',
                        html: 'Please wait while we update the marks.',
                        timer: 1500,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    }).then(() => {
                        document.getElementById('editForm').submit();
                    });
                }
            });
            return false;
        }

        // ==================== CONFIRM DELETE ====================
        function confirmDelete(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#2563eb',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                background: '#ffffff',
                backdrop: 'rgba(0,0,0,0.3)'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Deleting...',
                        html: 'Please wait while we delete the entry.',
                        timer: 1500,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    }).then(() => {
                        window.location.href = `exam_marks_history.php?delete_id=${id}&<?= http_build_query($_GET) ?>`;
                    });
                }
            });
        }

        // ==================== LOGOUT CONFIRMATION ====================
        window.confirmLogout = function(event) {
            if (event) event.preventDefault();
            
            Swal.fire({
                title: 'Are you sure?',
                text: "You will be logged out of your account.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#2563eb',
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Cancel',
                background: '#ffffff',
                backdrop: 'rgba(0,0,0,0.3)'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
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

        // ==================== SHOW NOTIFICATIONS ====================
        function showNotifications() {
            Swal.fire({
                title: 'Notifications',
                text: 'No new notifications',
                icon: 'info',
                confirmButtonColor: '#2563eb',
                background: '#ffffff',
                backdrop: 'rgba(0,0,0,0.3)'
            });
        }

        // ==================== AUTO-HIDE MESSAGES ====================
        setTimeout(() => {
            document.querySelectorAll('.success-message, .error-message').forEach(msg => {
                msg.style.transition = 'all 0.5s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateX(100px)';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);

        // ==================== TOUCH GESTURES FOR MOBILE ====================
        let touchStartX = 0;
        let touchEndX = 0;
        
        document.addEventListener('touchstart', e => {
            touchStartX = e.changedTouches[0].screenX;
        }, false);
        
        document.addEventListener('touchend', e => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, false);
        
        function handleSwipe() {
            const swipeThreshold = 100;
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            // Swipe right to open sidebar (from left edge)
            if (touchEndX - touchStartX > swipeThreshold && touchStartX < 30) {
                if (window.innerWidth < 1024 && !sidebar.classList.contains('active')) {
                    sidebar.classList.add('active');
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            }
            
            // Swipe left to close sidebar
            if (touchStartX - touchEndX > swipeThreshold) {
                if (window.innerWidth < 1024 && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        }

        // ==================== PREVENT BODY SCROLL WHEN MODAL OPEN ====================
        const editModal = document.getElementById('editModal');
        if (editModal) {
            document.body.style.overflow = 'hidden';
        }

        // ==================== FORM VALIDATION ====================
        document.getElementById('filterForm')?.addEventListener('submit', function(e) {
            // Optional: Add validation before submit
            return true;
        });
    </script>
<?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>
