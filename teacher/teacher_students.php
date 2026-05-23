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

// Log page access
logTeacherActivity('VIEW_STUDENTS_PAGE', 'Viewed students list page');

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

// ========== FIX: Get ACTUALLY ASSIGNED students ==========
$assigned_students = [];
$student_counts = [
    'english' => 0,
    'hindi' => 0,
    'total' => 0
];

// Get students that are actually assigned to this teacher from teacher_students table
$assigned_query = "
    SELECT ts.*, 
           COALESCE(se.first_name, sh.first_name) as first_name,
           COALESCE(se.last_name, sh.last_name) as last_name,
           COALESCE(se.photo, sh.photo) as photo,
           COALESCE(se.class, sh.class) as class,
           COALESCE(se.father_name, sh.father_name) as father_name,
           COALESCE(se.mother_name, sh.mother_name) as mother_name,
           COALESCE(se.parent_mobile, sh.parent_mobile) as parent_mobile,
           COALESCE(se.personal_mobile, sh.personal_mobile) as personal_mobile,
           COALESCE(se.whatsapp, sh.whatsapp) as whatsapp,
           ts.medium
    FROM teacher_students ts
    LEFT JOIN student_english se ON ts.student_id = se.id AND ts.medium = 'English'
    LEFT JOIN student_hindi sh ON ts.student_id = sh.id AND ts.medium = 'Hindi'
    WHERE ts.teacher_id = ?
    ORDER BY class ASC, first_name ASC
";

$stmt = $conn->prepare($assigned_query);
if ($stmt) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $assigned_students[] = $row;
        if ($row['medium'] === 'English') {
            $student_counts['english']++;
        } else {
            $student_counts['hindi']++;
        }
        $student_counts['total']++;
    }
    $stmt->close();
}

// Get teacher's assigned classes for display (optional)
$teacher_classes = [];
$assigned_classes_stmt = $conn->prepare("SELECT class, medium FROM teacher_classes WHERE teacher_id = ?");
if ($assigned_classes_stmt) {
    $assigned_classes_stmt->bind_param("i", $teacher_id);
    $assigned_classes_stmt->execute();
    $result = $assigned_classes_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $teacher_classes[] = $row;
    }
    $assigned_classes_stmt->close();
}

// Filter students by class if requested (optional)
$filter_class = $_GET['class'] ?? '';
$filter_medium = $_GET['medium'] ?? '';
$filtered_students = $assigned_students;

if ($filter_class) {
    $filtered_students = array_filter($filtered_students, function($student) use ($filter_class) {
        return $student['class'] == $filter_class;
    });
}

if ($filter_medium) {
    $filtered_students = array_filter($filtered_students, function($student) use ($filter_medium) {
        return $student['medium'] == $filter_medium;
    });
}

// Get unique classes from assigned students for filter dropdown
$classes = array_unique(array_column($assigned_students, 'class'));
sort($classes);

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Students | Ruchi Classes</title>
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
            --text-black: #000000;
            
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

        .dropdown-item.active {
            background: var(--gradient-primary);
            color: var(--text-white);
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

        /* Teacher Info Card */
        .teacher-info-card {
            background: var(--gradient-primary);
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            color: var(--text-white);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: var(--space-6);
            animation: fadeIn 0.8s ease;
        }

        .teacher-info-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--text-white);
            box-shadow: var(--shadow);
        }

        .teacher-info-details h2 {
            margin-bottom: var(--space-2);
            font-size: clamp(1.2rem, 3vw, 1.8rem);
        }

        .teacher-info-details p {
            margin: var(--space-1) 0;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            .teacher-info-card {
                flex-direction: column;
                text-align: center;
                padding: var(--space-4);
            }
            
            .teacher-info-avatar {
                width: 80px;
                height: 80px;
            }
        }

        /* Assigned Classes Section (Optional - for display only) */
        .assigned-classes {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .assigned-classes h3 {
            color: var(--text-primary);
            margin-bottom: var(--space-4);
            font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .class-chips {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
        }

        .class-chip {
            padding: var(--space-2) var(--space-4);
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            border: 2px solid var(--border);
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .class-chip:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .class-chip.english {
            border-left: 4px solid var(--primary);
        }

        .class-chip.hindi {
            border-left: 4px solid var(--warning);
        }

        /* Stats Grid */
        .stats-grid {
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
            animation: fadeIn 0.6s ease forwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }

        .stat-card:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: var(--shadow-hover);
        }

        .stat-icon {
            font-size: 32px;
            margin-bottom: var(--space-2);
        }

        .stat-value {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 800;
            margin-bottom: var(--space-1);
            line-height: 1;
        }

        .stat-label {
            font-size: clamp(0.8rem, 2vw, 0.9rem);
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all var(--transition-base);
            animation: fadeIn 0.8s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .card h2 {
            color: var(--primary);
            margin-bottom: var(--space-4);
            display: flex;
            align-items: center;
            gap: var(--space-2);
            font-size: clamp(1.2rem, 3vw, 1.5rem);
        }

        /* Filter Form */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 600;
            color: var(--text-primary);
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

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            padding: var(--space-3) var(--space-6);
            background: var(--primary);
            color: var(--text-white);
            border: none;
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-base);
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
        }

        .btn-success {
            background: var(--gradient-success);
        }

        .btn-warning {
            background: var(--gradient-warning);
        }

        .btn-info {
            background: var(--gradient-info);
        }

        .btn-danger {
            background: var(--gradient-danger);
        }

        .btn-block {
            width: 100%;
        }

        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: 0.85rem;
        }

        .btn-icon {
            padding: var(--space-2);
            width: 36px;
            height: 36px;
        }

        /* Table Styles */
        .students-table-container {
            overflow-x: auto;
            margin: var(--space-4) 0;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 800px;
            background: var(--bg-card);
        }

        .students-table th {
            background: var(--gradient-primary);
            color: var(--text-white);
            font-weight: 600;
            padding: var(--space-4) var(--space-3);
            text-align: left;
            position: sticky;
            top: 0;
        }

        .students-table td {
            padding: var(--space-4) var(--space-3);
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
        }

        .students-table tr:hover td {
            background: var(--bg-hover);
        }

        .students-table tr:last-child td {
            border-bottom: none;
        }

        /* Student Photo */
        .student-photo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }

        /* Medium Badges */
        .medium-badge {
            padding: var(--space-1) var(--space-3);
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .medium-english {
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid rgba(37, 99, 235, 0.3);
        }

        .medium-hindi {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: var(--space-2);
            flex-wrap: wrap;
        }

        /* Mobile Filter Section */
        .mobile-filter-section {
            display: none;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-4);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .mobile-filter-toggle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            padding: var(--space-3) var(--space-4);
            background: var(--gradient-primary);
            color: var(--text-white);
            border-radius: var(--radius-md);
            font-weight: 600;
        }

        .mobile-filter-content {
            display: none;
            padding-top: var(--space-4);
            margin-top: var(--space-4);
            border-top: 1px solid var(--border);
        }

        /* Mobile Student Cards */
        .mobile-student-card {
            display: none;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-4);
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
            border: 1px solid var(--border);
            transition: all var(--transition-base);
        }

        .mobile-student-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .mobile-student-header {
            display: flex;
            align-items: center;
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }

        .mobile-student-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }

        .mobile-student-info h4 {
            color: var(--text-primary);
            margin-bottom: var(--space-1);
            font-size: 1.1rem;
        }

        .mobile-student-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-3);
            margin-bottom: var(--space-4);
            padding: var(--space-4);
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
        }

        .mobile-detail-item {
            font-size: 0.9rem;
        }

        .mobile-detail-item span:first-child {
            font-weight: 600;
            color: var(--text-secondary);
            display: block;
            margin-bottom: var(--space-1);
            font-size: 0.8rem;
        }

        .mobile-detail-item span:last-child {
            color: var(--text-primary);
        }

        .mobile-action-buttons {
            display: flex;
            gap: var(--space-2);
            flex-wrap: wrap;
        }

        .mobile-action-buttons .btn {
            flex: 1;
            min-width: 100px;
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

        .no-data a {
            color: var(--primary);
            text-decoration: none;
        }

        .no-data a:hover {
            text-decoration: underline;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: var(--z-modal);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            animation: slideIn var(--transition-base);
            box-shadow: var(--shadow-lg);
        }

        .modal-close {
            position: absolute;
            top: var(--space-4);
            right: var(--space-4);
            background: var(--danger);
            color: var(--text-white);
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }

        .modal-close:hover {
            transform: scale(1.1);
            background: var(--danger-dark);
        }

        /* Animations */
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

        .fade-in {
            animation: fadeIn 0.6s ease;
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
            
            /* Show desktop views, hide mobile */
            .filter-form,
            .students-table-container {
                display: block !important;
            }
            
            .mobile-filter-section,
            .mobile-student-card {
                display: none !important;
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
                min-height: 60px;
            }
            
            .dropdown-item:hover {
                transform: none !important;
                background: var(--primary-light) !important;
                color: white !important;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            /* Show both views on tablet */
            .filter-form {
                display: grid;
            }
            
            .mobile-filter-section {
                display: block;
            }
            
            .students-table-container {
                display: block;
            }
            
            .mobile-student-card {
                display: none;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: var(--space-3);
            }
            
            .stat-card {
                padding: var(--space-3);
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .page-title h1 {
                font-size: 1.5rem;
            }
            
            /* Hide desktop views, show mobile */
            .filter-form,
            .students-table-container {
                display: none !important;
            }
            
            .mobile-filter-section,
            .mobile-student-card {
                display: block !important;
            }
            
            .mobile-student-details {
                grid-template-columns: 1fr;
            }
            
            .mobile-action-buttons {
                flex-direction: column;
            }
            
            .mobile-action-buttons .btn {
                width: 100%;
            }
            
            .class-chips {
                gap: var(--space-1);
            }
            
            .class-chip {
                padding: var(--space-1) var(--space-2);
                font-size: 12px;
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
            
            .teacher-info-avatar {
                width: 70px;
                height: 70px;
            }
            
            .mobile-student-photo {
                width: 50px;
                height: 50px;
            }
            
            .mobile-student-info h4 {
                font-size: 1rem;
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
            
            .modal-content {
                max-height: 80vh;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar,
            .header,
            .btn,
            .footer,
            .mobile-filter-section,
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
            
            .students-table {
                min-width: auto;
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
        
        <a href="teacher_dashboard.php" class="nav-item <?php echo $current_page == 'teacher_dashboard.php' ? 'active' : ''; ?>">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <div class="nav-text">Dashboard</div>
        </a>

        <!-- Attendance Dropdown -->
        <div class="nav-item dropdown <?php echo in_array($current_page, ['teacher_attendance.php', 'attendance_history.php']) ? 'active' : ''; ?>" id="attendanceDropdown">
            <div class="nav-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="nav-text">Attendance</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="attendanceMenu">
            <a href="teacher_attendance.php" class="dropdown-item <?php echo $current_page == 'teacher_attendance.php' ? 'active' : ''; ?>">
                <i class="fas fa-pencil-alt"></i> Mark Attendance
            </a>
            <a href="attendance_history.php" class="dropdown-item <?php echo $current_page == 'attendance_history.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Attendance History
            </a>
        </div>

        <!-- Exams Dropdown -->
        <div class="nav-item dropdown <?php echo in_array($current_page, ['teacher_add_exam.php', 'exam_marks_entry.php', 'exam_marks_history.php', 'all_exam.php']) ? 'active' : ''; ?>" id="examsDropdown">
            <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
            <div class="nav-text">Exams</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="examsMenu">
            <a href="teacher_add_exam.php" class="dropdown-item <?php echo $current_page == 'teacher_add_exam.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> Add Exam
            </a>
            <a href="all_exam.php" class="dropdown-item <?php echo $current_page == 'all_exam.php' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Exams
            </a>
            <a href="exam_marks_entry.php" class="dropdown-item <?php echo $current_page == 'exam_marks_entry.php' ? 'active' : ''; ?>">
                <i class="fas fa-pencil-alt"></i> Marks Entry
            </a>
            <a href="exam_marks_history.php" class="dropdown-item <?php echo $current_page == 'exam_marks_history.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Marks History
            </a>
        </div>
        
        <a href="teacher_complain.php" class="nav-item <?php echo $current_page == 'teacher_complain.php' ? 'active' : ''; ?>">
            <div class="nav-icon"><i class="fas fa-comment-dots"></i></div>
            <div class="nav-text">Complaint</div>
        </a>

        <a href="teacher_students.php" class="nav-item active">
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

        <!-- Main Container -->
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

            <!-- Teacher Info Card -->
            <?php if (!empty($teacher)): ?>
            <div class="teacher-info-card">
                <?php
                $photoPath = $teacher['photo'] ?? '';
                if (!empty($photoPath) && file_exists("../" . $photoPath)) {
                ?>
                    <img src="../<?php echo htmlspecialchars($photoPath); ?>"
                         alt="Teacher Photo"
                         class="teacher-info-avatar">
                <?php
                } elseif (!empty($photoPath)) {
                ?>
                    <img src="<?php echo htmlspecialchars($photoPath); ?>"
                         alt="Teacher Photo"
                         class="teacher-info-avatar">
                <?php
                } else {
                ?>
                    <div class="teacher-info-avatar"
                         style="background: white; color: var(--primary);
                                display: flex; align-items: center;
                                justify-content: center;
                                font-size: 2rem; font-weight: bold;">
                        <?php
                        echo substr($teacher['first_name'] ?? 'T', 0, 1) .
                             substr($teacher['last_name'] ?? 'C', 0, 1);
                        ?>
                    </div>
                <?php
                }
                ?>
                <div class="teacher-info-details">
                    <h2><?php echo htmlspecialchars(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')); ?></h2>
                    <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($teacher['subject'] ?? 'Not specified'); ?></p>
                    <?php if (!empty($assigned_students)): ?>
                        <p><i class="fas fa-user-graduate"></i> Assigned Students: <?php echo $student_counts['total']; ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Assigned Classes Section (Optional - just for information) -->
            <?php if (!empty($teacher_classes)): ?>
            <div class="assigned-classes">
                <h3><i class="fas fa-graduation-cap"></i> Your Assigned Classes (8-12)</h3>
                <div class="class-chips">
                    <?php foreach ($teacher_classes as $class): ?>
                    <div class="class-chip <?php echo strtolower($class['medium']); ?>">
                        <i class="fas fa-chalkboard-teacher"></i>
                        Class <?php echo htmlspecialchars($class['class']); ?>
                        <span class="medium-badge <?php echo $class['medium'] === 'English' ? 'medium-english' : 'medium-hindi'; ?>" style="font-size: 11px;">
                            <?php echo htmlspecialchars($class['medium']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Page Title -->
            <div class="page-title">
                <h1><i class="fas fa-users"></i> My Assigned Students</h1>
                <p>Students that have been assigned to you by the admin</p>
            </div>
            
            <!-- Stats Cards - Only show if students are assigned -->
            <?php if (!empty($assigned_students)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-value"><?php echo $student_counts['total']; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-language"></i>
                    </div>
                    <div class="stat-value"><?php echo $student_counts['english']; ?></div>
                    <div class="stat-label">English Medium</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-value"><?php echo $student_counts['hindi']; ?></div>
                    <div class="stat-label">Hindi Medium</div>
                </div>
            </div>
            
            <!-- DESKTOP: Filter Section (only if students exist) -->
            <?php if (!empty($assigned_students)): ?>
            <div class="card">
                <h2 style="margin-bottom: var(--space-4); color: var(--primary);">
                    <i class="fas fa-filter"></i> Filter Students
                </h2>
                <form method="get" class="filter-form" id="filterForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="form-group">
                        <label for="class">Class</label>
                        <select name="class" id="class" class="form-control" onchange="saveScrollAndSubmit()">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class; ?>" <?php if($filter_class == $class) echo 'selected'; ?>>
                                    Class <?php echo $class; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="medium">Medium</label>
                        <select name="medium" id="medium" class="form-control" onchange="saveScrollAndSubmit()">
                            <option value="">All Mediums</option>
                            <option value="English" <?php if($filter_medium == "English") echo 'selected'; ?>>English</option>
                            <option value="Hindi" <?php if($filter_medium == "Hindi") echo 'selected'; ?>>Hindi</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="teacher_students.php" class="btn" style="width: 100%;" onclick="saveScrollBeforeNavigate()">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- MOBILE: Filter Section (only if students exist) -->
            <?php if (!empty($assigned_students)): ?>
            <div class="mobile-filter-section">
                <div class="mobile-filter-toggle" onclick="toggleMobileFilters()">
                    <span><i class="fas fa-filter"></i> Filter Students</span>
                    <i class="fas fa-chevron-down" id="filterToggleIcon"></i>
                </div>
                <div class="mobile-filter-content" id="mobileFilterContent">
                    <form method="get" id="mobileFilterForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="form-group">
                            <label for="mobileClass">Class</label>
                            <select name="class" id="mobileClass" class="form-control" onchange="saveScrollAndSubmitMobile()">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class; ?>" <?php if($filter_class == $class) echo 'selected'; ?>>
                                        Class <?php echo $class; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="mobileMedium">Medium</label>
                            <select name="medium" id="mobileMedium" class="form-control" onchange="saveScrollAndSubmitMobile()">
                                <option value="">All Mediums</option>
                                <option value="English" <?php if($filter_medium == "English") echo 'selected'; ?>>English</option>
                                <option value="Hindi" <?php if($filter_medium == "Hindi") echo 'selected'; ?>>Hindi</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-block" style="margin-bottom: var(--space-2);">
                            <i class="fas fa-check"></i> Apply Filters
                        </button>
                        
                        <a href="teacher_students.php" class="btn btn-block" style="background: var(--text-muted);" onclick="saveScrollBeforeNavigate()">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- DESKTOP: Students Table - Only show assigned students -->
            <div class="card fade-in">
                <h2 style="margin-bottom: var(--space-4); color: var(--primary);">
                    <i class="fas fa-list"></i> Assigned Student List 
                    <?php if ($filter_class || $filter_medium): ?>
                        <span style="font-size: 0.9rem; color: var(--text-secondary); margin-left: var(--space-2);">
                            (Filtered: 
                            <?php echo $filter_class ? "Class $filter_class " : ""; ?>
                            <?php echo $filter_medium ? "$filter_medium Medium" : ""; ?>
                            )
                        </span>
                    <?php endif; ?>
                </h2>
                
                <?php if (!empty($filtered_students)): ?>
                <div class="students-table-container">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Student Name</th>
                                <th>Class</th>
                                <th>Medium</th>
                                <th>Parents</th>
                                <th>Contact</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filtered_students as $student): 
                                // Determine which phone number to display (priority: whatsapp > parent_mobile > personal_mobile)
                                $phone = !empty($student['whatsapp']) ? $student['whatsapp'] : 
                                        (!empty($student['parent_mobile']) ? $student['parent_mobile'] : 
                                        ($student['personal_mobile'] ?? ''));
                                
                                $photoPath = "../student/" . ($student['photo'] ?? '');
                                $photoExists = !empty($student['photo']) && file_exists($photoPath);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($photoExists): ?>
                                        <img src="<?php echo $photoPath; ?>" alt="Student Photo" class="student-photo"
                                             onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&size=50&background=random'">
                                    <?php else: ?>
                                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&size=50&background=random" 
                                             alt="Student Photo" class="student-photo">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? '')); ?></strong>
                                </td>
                                <td>Class <?php echo htmlspecialchars($student['class']); ?></td>
                                <td>
                                    <span class="medium-badge <?php echo $student['medium'] === 'English' ? 'medium-english' : 'medium-hindi'; ?>">
                                        <?php echo htmlspecialchars($student['medium']); ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <div><i class="fas fa-male"></i> <?php echo htmlspecialchars($student['father_name'] ?? 'N/A'); ?></div>
                                        <div><i class="fas fa-female"></i> <?php echo htmlspecialchars($student['mother_name'] ?? 'N/A'); ?></div>
                                    </small>
                                </td>
                                <td>
                                    <?php if (!empty($phone)): ?>
                                        <div style="display: flex; flex-direction: column; gap: var(--space-1);">
                                            <a href="tel:<?php echo htmlspecialchars($phone); ?>" class="btn btn-sm btn-success" style="padding: 4px 8px;">
                                                <i class="fas fa-phone"></i> Call
                                            </a>
                                            <?php if (!empty($student['whatsapp'])): ?>
                                                <a href="https://wa.me/<?php echo htmlspecialchars($student['whatsapp']); ?>" target="_blank" class="btn btn-sm" style="background: #25D366; color: white; padding: 4px 8px;">
                                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-icon btn-info" onclick="viewStudentDetails(<?php echo htmlspecialchars(json_encode($student)); ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-icon btn-warning" onclick="viewAttendance(<?php echo $student['id']; ?>, '<?php echo $student['medium']; ?>')">
                                            <i class="fas fa-clipboard-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-icon btn-success" onclick="viewPerformance(<?php echo $student['id']; ?>)">
                                            <i class="fas fa-chart-line"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-filter"></i>
                    <h3>No Students Match Filters</h3>
                    <p>No students found with the selected filters. Try different filters or <a href="teacher_students.php">clear all filters</a>.</p>
                </div>
                <?php endif; ?>
                
                <!-- MOBILE: Student Cards - Only show assigned students -->
                <?php foreach ($filtered_students as $student): 
                    $phone = !empty($student['whatsapp']) ? $student['whatsapp'] : 
                            (!empty($student['parent_mobile']) ? $student['parent_mobile'] : 
                            ($student['personal_mobile'] ?? ''));
                    
                    $photoPath = "../student/" . ($student['photo'] ?? '');
                    $photoExists = !empty($student['photo']) && file_exists($photoPath);
                ?>
                <div class="mobile-student-card">
                    <div class="mobile-student-header">
                        <?php if ($photoExists): ?>
                            <img src="<?php echo $photoPath; ?>" alt="Student Photo" class="mobile-student-photo"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&size=60&background=random'">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'].' '.$student['last_name']); ?>&size=60&background=random" 
                                 alt="Student Photo" class="mobile-student-photo">
                        <?php endif; ?>
                        <div class="mobile-student-info">
                            <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? '')); ?></h4>
                            <div style="display: flex; gap: var(--space-1); flex-wrap: wrap;">
                                <span class="medium-badge <?php echo $student['medium'] === 'English' ? 'medium-english' : 'medium-hindi'; ?>" style="font-size: 11px;">
                                    <?php echo htmlspecialchars($student['medium']); ?>
                                </span>
                                <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: var(--radius-full); font-size: 11px;">
                                    Class <?php echo htmlspecialchars($student['class']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mobile-student-details">
                        <div class="mobile-detail-item">
                            <span>Father</span>
                            <span><?php echo htmlspecialchars($student['father_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="mobile-detail-item">
                            <span>Mother</span>
                            <span><?php echo htmlspecialchars($student['mother_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="mobile-detail-item">
                            <span>Parent Mobile</span>
                            <span><?php echo htmlspecialchars($student['parent_mobile'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="mobile-detail-item">
                            <span>Student Mobile</span>
                            <span><?php echo htmlspecialchars($student['personal_mobile'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <div class="mobile-action-buttons">
                        <button class="btn btn-sm btn-info" onclick="viewStudentDetails(<?php echo htmlspecialchars(json_encode($student)); ?>)">
                            <i class="fas fa-eye"></i> Details
                        </button>
                        <?php if (!empty($phone)): ?>
                            <a href="tel:<?php echo htmlspecialchars($phone); ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-phone"></i> Call
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($student['whatsapp'])): ?>
                            <a href="https://wa.me/<?php echo htmlspecialchars($student['whatsapp']); ?>" target="_blank" class="btn btn-sm" style="background: #25D366; color: white;">
                                <i class="fab fa-whatsapp"></i> WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php else: ?>
            <!-- No Students Assigned Message - Jab koi student assign nahi hua ho -->
            <div class="card fade-in">
                <div class="no-data">
                    <i class="fas fa-user-slash"></i>
                    <h3>No Students Assigned Yet</h3>
                    <p>
                        You don't have any students assigned to you yet. 
                        <br><br>
                        <strong>Note:</strong> Students are assigned by the admin. Please wait for the admin to assign students to you.
                        <br><br>
                        <i class="fas fa-info-circle" style="font-size: 1rem;"></i> 
                        Your assigned classes: 
                        <?php if (!empty($teacher_classes)): ?>
                            <?php foreach ($teacher_classes as $class): ?>
                                <span class="class-chip" style="display: inline-block; margin: 2px;">
                                    Class <?php echo $class['class']; ?> (<?php echo $class['medium']; ?>)
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            No classes assigned yet.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?php echo date('Y'); ?> Ruchi Classes. All rights reserved.</p>
                <p style="margin-top: 5px; font-size: 0.8rem; opacity: 0.7;">
                    <i class="fas fa-shield-alt"></i> Secure Student Management System
                </p>
            </div>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div class="modal" id="studentModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeStudentModal()">×</button>
            <div id="studentModalContent"></div>
        </div>
    </div>

    <!-- SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // ==================== SCROLL POSITION PRESERVATION ====================
        
        // Save scroll position before form submission
        function saveScrollAndSubmit() {
            const scrollPosition = window.scrollY;
            sessionStorage.setItem('teacher_scroll_position', scrollPosition);
            document.getElementById('filterForm').submit();
        }
        
        function saveScrollAndSubmitMobile() {
            const scrollPosition = window.scrollY;
            sessionStorage.setItem('teacher_scroll_position', scrollPosition);
            document.getElementById('mobileFilterForm').submit();
        }
        
        function saveScrollBeforeNavigate() {
            const scrollPosition = window.scrollY;
            sessionStorage.setItem('teacher_scroll_position', scrollPosition);
        }
        
        // Restore scroll position after page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedScrollPosition = sessionStorage.getItem('teacher_scroll_position');
            
            if (savedScrollPosition) {
                setTimeout(() => {
                    window.scrollTo({
                        top: parseInt(savedScrollPosition),
                        behavior: 'smooth'
                    });
                    sessionStorage.removeItem('teacher_scroll_position');
                }, 100);
            }
            
            // Save scroll position before any form submission
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const scrollPosition = window.scrollY;
                    sessionStorage.setItem('teacher_scroll_position', scrollPosition);
                });
            });
            
            // Save scroll position before any link click
            document.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.getAttribute('href') && !this.getAttribute('href').startsWith('#')) {
                        const scrollPosition = window.scrollY;
                        sessionStorage.setItem('teacher_scroll_position', scrollPosition);
                    }
                });
            });
        });

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

            // ==================== MOBILE FILTER TOGGLE ====================
            window.toggleMobileFilters = function() {
                const filterContent = document.getElementById('mobileFilterContent');
                const toggleIcon = document.getElementById('filterToggleIcon');
                
                if (filterContent.style.display === 'block') {
                    filterContent.style.display = 'none';
                    toggleIcon.classList.remove('fa-chevron-up');
                    toggleIcon.classList.add('fa-chevron-down');
                } else {
                    filterContent.style.display = 'block';
                    toggleIcon.classList.remove('fa-chevron-down');
                    toggleIcon.classList.add('fa-chevron-up');
                }
            };

            // Initialize mobile filter content as hidden
            const mobileFilterContent = document.getElementById('mobileFilterContent');
            if (mobileFilterContent) {
                mobileFilterContent.style.display = 'none';
            }

            // ==================== WINDOW RESIZE HANDLER ====================
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth >= 1024) {
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
                        
                        // Reset mobile filter state on resize
                        if (mobileFilterContent) {
                            mobileFilterContent.style.display = 'none';
                        }
                    }
                    
                    // Close all dropdowns on resize
                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                    
                    // Hide profile menu on resize
                    if (profileMenu) profileMenu.style.display = 'none';
                }, 250);
            });

            // ==================== KEYBOARD SHORTCUTS ====================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (window.innerWidth < 1024 && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }

                    profileMenu.style.display = 'none';
                    closeStudentModal();

                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
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

            document.addEventListener('keypress', () => {
                clearTimeout(activityTimer);
                activityTimer = setTimeout(() => {
                    sessionTime = 1800;
                    warningShown = false;
                }, 10000);
            });

            // ==================== INITIAL STATE ====================
            if (window.innerWidth < 1024) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        });

        // ==================== VIEW STUDENT DETAILS ====================
        function viewStudentDetails(student) {
            const modal = document.getElementById('studentModal');
            const content = document.getElementById('studentModalContent');
            
            // Determine which phone number to display
            const phone = student.whatsapp || student.parent_mobile || student.personal_mobile || '';
            const whatsapp = student.whatsapp || '';
            
            let photoHtml = '';
            if (student.photo) {
                photoHtml = `<img src="../student/${student.photo}" alt="Student Photo" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary); margin-bottom: var(--space-4);">`;
            } else {
                photoHtml = `<div style="width: 120px; height: 120px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: bold; margin-bottom: var(--space-4);">${student.first_name ? student.first_name.charAt(0) : ''}${student.last_name ? student.last_name.charAt(0) : ''}</div>`;
            }
            
            const mediumBadgeClass = student.medium === 'English' ? 'medium-english' : 'medium-hindi';
            
            content.innerHTML = `
                <div style="text-align: center;">
                    ${photoHtml}
                    <h2 style="color: var(--text-primary); margin-bottom: var(--space-2);">${student.first_name || ''} ${student.last_name || ''}</h2>
                    <div style="display: flex; justify-content: center; gap: var(--space-2); margin-bottom: var(--space-4); flex-wrap: wrap;">
                        <span class="medium-badge ${mediumBadgeClass}" style="font-size: 14px;">${student.medium || 'N/A'} Medium</span>
                        <span style="background: var(--primary); color: white; padding: 4px 12px; border-radius: var(--radius-full); font-size: 14px;">Class ${student.class || 'N/A'}</span>
                    </div>
                </div>
                
                <div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: var(--space-4); margin-top: var(--space-4);">
                    <h3 style="color: var(--text-primary); margin-bottom: var(--space-3); border-bottom: 2px solid var(--border); padding-bottom: var(--space-2);">Student Information</h3>
                    
                    <div style="display: grid; gap: var(--space-3);">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">Father's Name:</span>
                            <span>${student.father_name || 'N/A'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">Mother's Name:</span>
                            <span>${student.mother_name || 'N/A'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">Parent Mobile:</span>
                            <span>${student.parent_mobile || 'N/A'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">Student Mobile:</span>
                            <span>${student.personal_mobile || 'N/A'}</span>
                        </div>
                        ${student.whatsapp ? `
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">WhatsApp:</span>
                            <span>${student.whatsapp}</span>
                        </div>
                        ` : ''}
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">Medium:</span>
                            <span>${student.medium || 'N/A'}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary); font-weight: 600;">Class:</span>
                            <span>${student.class || 'N/A'}</span>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: var(--space-6); display: flex; justify-content: center; gap: var(--space-2); flex-wrap: wrap;">
                    ${phone ? `<a href="tel:${phone}" class="btn btn-success" style="padding: 10px 20px;">
                        <i class="fas fa-phone"></i> Call Parents
                    </a>` : ''}
                    ${whatsapp ? `<a href="https://wa.me/${whatsapp}" target="_blank" class="btn" style="background: #25D366; color: white; padding: 10px 20px;">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>` : ''}
                    <button class="btn btn-info" style="padding: 10px 20px;" onclick="viewAttendance(${student.id}, '${student.medium}')">
                        <i class="fas fa-clipboard-check"></i> View Attendance
                    </button>
                </div>
            `;
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        // ==================== CLOSE MODAL ====================
        function closeStudentModal() {
            document.getElementById('studentModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // ==================== VIEW ATTENDANCE ====================
        function viewAttendance(studentId, medium) {
            Swal.fire({
                title: 'Coming Soon',
                text: `Attendance feature for Student ID: ${studentId} (${medium} Medium) will be available soon.`,
                icon: 'info',
                confirmButtonColor: '#2563eb'
            });
            // window.location.href = `student_attendance.php?id=${studentId}&medium=${medium}`;
        }

        // ==================== VIEW PERFORMANCE ====================
        function viewPerformance(studentId) {
            Swal.fire({
                title: 'Coming Soon',
                text: `Performance tracking for Student ID: ${studentId} will be available soon.`,
                icon: 'info',
                confirmButtonColor: '#2563eb'
            });
            // window.location.href = `student_performance.php?id=${studentId}`;
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

        // Close modal when clicking outside
        document.getElementById('studentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStudentModal();
            }
        });
    </script>
    <?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>
