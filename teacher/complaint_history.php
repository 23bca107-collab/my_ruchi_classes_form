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
$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $teacher['first_name'] . ' ' . ($teacher['last_name'] ?? '');

// Get messages count for notification badge
$messages_count = 0;
$table_check = $conn->query("SHOW TABLES LIKE 'messages'");
if ($table_check && $table_check->num_rows > 0) {
    $messages_stmt = $conn->prepare("SELECT COUNT(*) as msg_count FROM messages WHERE receiver_id=? AND receiver_type='teacher' AND status='unread'");
    $messages_stmt->bind_param("i", $teacher_id);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    $messages_count = $messages_result->fetch_assoc()['msg_count'] ?? 0;
}

$teacher['id'] = $teacher_id;
$teacher_notifications_data = teacher_notifications_prepare($conn, $teacher, 12);

// Get complaint count for badge
$complaint_count = 0;
$table_check = $conn->query("SHOW TABLES LIKE 'complaints'");
if ($table_check && $table_check->num_rows > 0) {
    $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM complaints WHERE user_id = ? AND user_type = 'teacher' AND status = 'Pending'");
    $count_stmt->bind_param("i", $teacher_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $complaint_count = $count_result->fetch_assoc()['count'] ?? 0;
}

// ✅ Fetch teacher's complaints from database
$stmt = $conn->prepare("SELECT * FROM complaints WHERE user_type = 'teacher' AND user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

// Helper function for HTML escaping
function h($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
  <title>My Complaint History - Teacher</title>
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
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;
            
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

        /* ========== SIDEBAR STYLES ========== */
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

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--gradient-primary);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s ease;
            color: white;
            box-shadow: var(--shadow);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-card i {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-card .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        /* Table Container */
        .table-container {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            overflow-x: auto;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th {
            background: var(--gradient-primary);
            color: white;
            padding: 15px;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
        }

        tr {
            transition: all 0.3s ease;
        }

        tr:hover {
            background: var(--bg-hover);
        }

        tr:last-child td {
            border-bottom: none;
        }

        .complaint-cell {
            max-width: 300px;
        }

        .complaint-text {
            max-height: 60px;
            overflow-y: auto;
            padding: 5px;
            background: var(--bg-secondary);
            border-radius: var(--radius-sm);
            font-size: 14px;
            line-height: 1.5;
        }

        .complaint-text::-webkit-scrollbar {
            width: 4px;
        }

        .complaint-text::-webkit-scrollbar-track {
            background: var(--border-light);
            border-radius: 4px;
        }

        .complaint-text::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }

        .status {
            display: inline-block;
            padding: 8px 15px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }

        .status-pending {
            background: var(--warning);
            color: white;
        }

        .status-resolved {
            background: var(--success);
            color: white;
        }

        .status-rejected {
            background: var(--danger);
            color: white;
        }

        .date-cell {
            font-size: 13px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .date-cell i {
            margin-right: 5px;
            color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }

        .empty-state i {
            font-size: 80px;
            color: var(--border);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 10px;
            font-size: 24px;
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: var(--gradient-primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-secondary {
            background: var(--gradient-info);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
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

        /* Animations */
        @keyframes slideInLeft {
            from {
                transform: translateX(-100%);
            }
            to {
                transform: translateX(0);
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

        .fade-in {
            animation: fadeIn 0.6s ease;
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
                min-height: 60px;
            }
            
            .dropdown-item:hover {
                transform: none !important;
                background: var(--primary-light) !important;
                color: white !important;
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
            
            .stats-cards {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                text-align: center;
            }
            
            td {
                padding: 10px;
                font-size: 13px;
            }
            
            .status {
                padding: 5px 10px;
                min-width: 80px;
                font-size: 12px;
            }
            
            .page-title h1 {
                font-size: 1.5rem;
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
                padding: var(--space-3) var(--space-4);
                font-size: 0.9rem;
            }
            
            .page-title h1 {
                font-size: 1.3rem;
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
            
            .table-container {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ccc;
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
        <a href="teacher_settings.php"><i class="fas fa-cog"></i> Settings</a>
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
        
        <a href="teacher_dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'teacher_dashboard.php' ? 'active' : ''; ?>">
            <div class="nav-icon"><i class="fas fa-home"></i></div>
            <div class="nav-text">Dashboard</div>
        </a>

        <!-- Attendance Dropdown -->
        <div class="nav-item dropdown <?php echo (basename($_SERVER['PHP_SELF']) == 'teacher_attendance.php' || basename($_SERVER['PHP_SELF']) == 'attendance_history.php') ? 'active' : ''; ?>" id="attendanceDropdown">
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
        <div class="nav-item dropdown <?php echo (basename($_SERVER['PHP_SELF']) == 'teacher_add_exam.php' || basename($_SERVER['PHP_SELF']) == 'exam_marks_entry.php' || basename($_SERVER['PHP_SELF']) == 'exam_marks_history.php' || basename($_SERVER['PHP_SELF']) == 'all_exam.php') ? 'active' : ''; ?>" id="examsDropdown">
            <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
            <div class="nav-text">Exams</div>
            <i class="fas fa-caret-down dropdown-icon"></i>
        </div>
        <div class="dropdown-menu" id="examsMenu">
            <a href="teacher_add_exam.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'teacher_add_exam.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> Add Exam
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
            <?php if ($complaint_count > 0): ?>
            <span style="background: var(--danger); color: white; padding: 2px 8px; border-radius: var(--radius-full); font-size: 12px; margin-left: auto;">
                <?= $complaint_count ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="teacher_students.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-users"></i></div>
            <div class="nav-text">Students</div>
        </a>
        
        <a href="teacher_grades.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-chart-bar"></i></div>
            <div class="nav-text">Grades</div>
        </a>
        
        <a href="teacher_schedule.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="nav-text">Schedule</div>
        </a>
        
        <a href="teacher_settings.php" class="nav-item">
            <div class="nav-icon"><i class="fas fa-cog"></i></div>
            <div class="nav-text">Settings</div>
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

            <!-- Page Title -->
            <div class="page-title">
                <h1><i class="fas fa-history"></i> My Complaint History</h1>
                <p>Track the status of all your complaints</p>
            </div>

            <!-- Main Content -->
            <div class="content">
              <?php 
              // Calculate statistics
              $total_complaints = 0;
              $pending_count = 0;
              $resolved_count = 0;
              $rejected_count = 0;

              if ($result && $result->num_rows > 0) {
                $total_complaints = $result->num_rows;
                
                // Reset pointer to calculate stats
                $result->data_seek(0);
                while($row = $result->fetch_assoc()) {
                  $status = $row['status'] ?? 'Pending';
                  if ($status === 'Pending') $pending_count++;
                  elseif ($status === 'Resolved') $resolved_count++;
                  elseif ($status === 'Rejected') $rejected_count++;
                }
                
                // Reset pointer for main display
                $result->data_seek(0);
              }
              ?>

              <!-- Statistics Cards -->
              <div class="stats-cards">
                <div class="stat-card">
                  <i class="fas fa-file-alt"></i>
                  <div class="stat-value"><?php echo $total_complaints; ?></div>
                  <div class="stat-label">Total Complaints</div>
                </div>
                <div class="stat-card">
                  <i class="fas fa-clock"></i>
                  <div class="stat-value"><?php echo $pending_count; ?></div>
                  <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                  <i class="fas fa-check-circle"></i>
                  <div class="stat-value"><?php echo $resolved_count; ?></div>
                  <div class="stat-label">Resolved</div>
                </div>
              </div>

              <!-- Complaints Table -->
              <div class="table-container">
                <?php if ($result && $result->num_rows > 0): ?>
                  <table>
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Complaint Details</th>
                        <th>Submitted On</th>
                        <th>Current Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php while($row = $result->fetch_assoc()): ?>
                        <?php 
                          $status = $row['status'] ?? 'Pending';
                          $class = 'status-pending';
                          $icon = 'fa-clock';
                          
                          if ($status === 'Resolved') {
                            $class = 'status-resolved';
                            $icon = 'fa-check-circle';
                          } elseif ($status === 'Rejected') {
                            $class = 'status-rejected';
                            $icon = 'fa-times-circle';
                          }
                        ?>
                        <tr>
                          <td><strong>#<?php echo $row['id']; ?></strong></td>
                          <td class="complaint-cell">
                            <div class="complaint-text">
                              <?php echo nl2br(htmlspecialchars($row['complaint'])); ?>
                            </div>
                          </td>
                          <td class="date-cell">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo date("d M Y", strtotime($row['created_at'])); ?>
                            <br>
                            <small style="color: var(--text-muted);">
                              <i class="fas fa-clock"></i>
                              <?php echo date("h:i A", strtotime($row['created_at'])); ?>
                            </small>
                          </td>
                          <td>
                            <span class="status <?php echo $class; ?>">
                              <i class="fas <?php echo $icon; ?>" style="margin-right: 5px;"></i>
                              <?php echo $status; ?>
                            </span>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <!-- Empty State -->
                  <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Complaints Found</h3>
                    <p>You haven't submitted any complaints yet. If you have an issue, feel free to submit a complaint and we'll help you resolve it.</p>
                    <a href="teacher_complain.php" class="btn">
                      <i class="fas fa-plus-circle"></i>
                      Submit Your First Complaint
                    </a>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Action Buttons -->
              <div class="action-buttons">
                <a href="teacher_complain.php" class="btn">
                  <i class="fas fa-plus-circle"></i>
                  Submit New Complaint
                </a>
                <a href="teacher_dashboard.php" class="btn btn-secondary">
                  <i class="fas fa-arrow-left"></i>
                  Back to Dashboard
                </a>
              </div>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <i class="fas fa-shield-alt"></i>
                Secure Teacher Portal | Ruchi Classes © <?php echo date('Y'); ?>
            </div>
        </div>
    </div>

    <!-- SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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

            // ==================== INITIAL STATE ====================
            if (window.innerWidth < 1024) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
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
    </script>
<?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>
