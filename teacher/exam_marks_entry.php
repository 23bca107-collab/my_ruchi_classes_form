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

// ==================== MARKS SAVE FUNCTIONALITY ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateTeacherCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'Security token invalid. Please try again.';
        header("Location: exam_marks_entry.php");
        exit();
    }
    
    // Get form data
    $exam_id = intval($_POST['exam_id'] ?? 0);
    $medium = $_POST['medium'] ?? '';
    $class = $_POST['class'] ?? '';
    $marks_data = $_POST['marks'] ?? [];
    $mobile_marks_data = $_POST['mobile_marks'] ?? [];
    
    // Merge both desktop and mobile marks
    if (!empty($mobile_marks_data)) {
        foreach ($mobile_marks_data as $student_id => $marks) {
            $marks_data[$student_id] = $marks;
        }
    }
    
    // Validate required fields
    if (empty($exam_id)) {
        $_SESSION['error_message'] = 'Exam ID is missing. Please select an exam.';
        header("Location: exam_marks_entry.php?class=$class&medium=$medium");
        exit();
    }
    
    if (empty($marks_data)) {
        $_SESSION['error_message'] = 'No marks data received. Please enter marks for students.';
        header("Location: exam_marks_entry.php?class=$class&medium=$medium&exam_id=$exam_id");
        exit();
    }
    
    // Check if ANY marks are entered
    $has_marks = false;
    $filled_count = 0;
    
    foreach ($marks_data as $student_id => $marks) {
        $marks = trim((string)$marks);
        if ($marks !== '') {
            $has_marks = true;
            $filled_count++;
        }
    }
    
    if (!$has_marks) {
        $_SESSION['error_message'] = 'Please enter marks for at least one student. All fields are empty.';
        header("Location: exam_marks_entry.php?class=$class&medium=$medium&exam_id=$exam_id");
        exit();
    }
    
    // Get total marks for this exam
    $total_marks = 100;
    $total_marks_query = $conn->prepare("SELECT total_marks FROM exams WHERE id = ?");
    $total_marks_query->bind_param("i", $exam_id);
    $total_marks_query->execute();
    $total_marks_result = $total_marks_query->get_result();
    if ($total_marks_result->num_rows > 0) {
        $total_marks_row = $total_marks_result->fetch_assoc();
        $total_marks = $total_marks_row['total_marks'] ?? 100;
    }
    
    // Prepare insert statement
    $insert_sql = "INSERT INTO exam_marks (exam_id, student_id, obtained_marks) 
                   VALUES (?, ?, ?) 
                   ON DUPLICATE KEY UPDATE obtained_marks = VALUES(obtained_marks)";

    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        $_SESSION['error_message'] = 'DB prepare failed: ' . $conn->error;
        header("Location: exam_marks_entry.php?class=$class&medium=$medium&exam_id=$exam_id");
        exit();
    }

    $success_count = 0;
    $errors = [];

    foreach ($marks_data as $student_id => $marks) {
        $student_id = intval($student_id);
        $marks = trim((string)$marks);
        
        if ($marks === '') continue;

        $marks = intval($marks);
        
        if ($marks < 0 || $marks > $total_marks) {
            $errors[] = "Student ID $student_id: Marks $marks out of range (0-$total_marks)";
            continue;
        }

        $stmt->bind_param("iii", $exam_id, $student_id, $marks);
        
        if (!$stmt->execute()) {
            $errors[] = "Student $student_id: " . $stmt->error;
            continue;
        }

        $success_count++;
    }

    $stmt->close();

    if ($success_count > 0) {
        $_SESSION['success_message'] = "✅ Marks saved for $success_count student(s).";
        if (!empty($errors)) {
            $_SESSION['success_message'] .= " Some errors: " . implode(", ", $errors);
        }
        // ✅ Set a flag that marks have been saved for this exam
        $_SESSION['marks_saved_for_exam_' . $exam_id] = true;
    } else {
        $error_text = !empty($errors) ? implode("; ", $errors) : 'No valid marks to save.';
        $_SESSION['error_message'] = '❌ No marks were saved. ' . $error_text;
    }
    
    // Redirect to history page instead of staying on entry page
    header("Location: exam_marks_history.php?class=$class&medium=$medium&exam_id=$exam_id");
    exit();
}

// ==================== FETCH STUDENTS AND EXAMS ====================
$students = [];
$exams = [];
$existing_marks = [];
$class = $_GET['class'] ?? '';
$medium = $_GET['medium'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

// Flag to check if marks already exist for this exam
$marks_already_exist = false;

if (!empty($class) && !empty($medium)) {
    if ($medium === "English") {
        $student_table = "student_english";
    } elseif ($medium === "Hindi") {
        $student_table = "student_hindi";
    } else {
        $student_table = "";
    }
    
    if (!empty($student_table)) {
        $table_check = $conn->query("SHOW TABLES LIKE '$student_table'");
        if ($table_check && $table_check->num_rows > 0) {
            $s = $conn->prepare("SELECT id, first_name, last_name, father_name, photo FROM $student_table WHERE class = ? ORDER BY first_name");
            $s->bind_param("s", $class);
            $s->execute();
            $students = $s->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $ex = $conn->prepare("SELECT id, subject, topic, exam_date, exam_time, total_marks FROM exams WHERE class = ? AND medium = ? ORDER BY exam_date DESC");
            $ex->bind_param("ss", $class, $medium);
            $ex->execute();
            $exams = $ex->get_result()->fetch_all(MYSQLI_ASSOC);
            
            if (!empty($exam_id)) {
                $marks_stmt = $conn->prepare("SELECT student_id, obtained_marks FROM exam_marks WHERE exam_id = ?");
                $marks_stmt->bind_param("i", $exam_id);
                $marks_stmt->execute();
                $marks_result = $marks_stmt->get_result();
                
                // Check if any marks exist for this exam
                if ($marks_result->num_rows > 0) {
                    $marks_already_exist = true;
                }
                
                while ($row = $marks_result->fetch_assoc()) {
                    $existing_marks[$row['student_id']] = $row['obtained_marks'];
                }
            }
        }
    }
}

$selected_exam = null;
if (!empty($exam_id)) {
    foreach ($exams as $exam) {
        if ($exam['id'] == $exam_id) {
            $selected_exam = $exam;
            break;
        }
    }
}

// Check if we should show entry form or redirect to history
if ($marks_already_exist && !isset($_GET['force_entry'])) {
    // Redirect to history page with a message
    $_SESSION['info_message'] = "Marks have already been entered for this exam. You can view or edit them in the history page.";
    header("Location: exam_marks_history.php?class=$class&medium=$medium&exam_id=$exam_id");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Exam Marks Entry - Ruchi Classes</title>
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

        /* Cards */
        .card {
            background: var(--gradient-primary);
            color: var(--text-white);
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
            color: var(--text-white);
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
            background: rgba(255, 255, 255, 0.1);
            padding: var(--space-6);
            border-radius: var(--radius-md);
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 600;
            color: var(--text-black);
            font-size: 0.95rem;
        }

        .form-group label i {
            margin-right: var(--space-1);
        }

        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-md);
            font-size: 1rem;
            transition: all var(--transition-fast);
            background: rgba(255, 255, 255, 0.9);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--text-white);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
            background: var(--text-white);
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
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
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
            background: var(--text-black);
            color: var(--primary);
        }

        .btn-success {
            background: var(--gradient-success);
            color: var(--text-white);
        }

        .btn-info {
            background: var(--gradient-info);
            color: var(--text-white);
        }

        .btn-danger {
            background: var(--gradient-danger);
            color: var(--text-white);
        }

        .btn-filter {
            background: var(--primary);
            color: var(--text-white);
            width: 100%;
        }

        /* Exam Info */
        .exam-info {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: var(--text-white);
            padding: var(--space-5);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-6);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--space-4);
        }

        .exam-info-item {
            text-align: center;
        }

        .exam-info-item i {
            font-size: 24px;
            margin-bottom: var(--space-2);
            opacity: 0.9;
        }

        .exam-info-item .label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .exam-info-item .value {
            font-size: 18px;
            font-weight: 700;
        }

        .total-marks-badge {
            background: var(--accent);
            color: var(--text-white);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-full);
            font-weight: 700;
            font-size: 18px;
            display: inline-block;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-top: var(--space-4);
            background: var(--bg-card);
        }

        .marks-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 800px;
        }

        .marks-table th {
            background: var(--gradient-primary);
            color: var(--text-white);
            font-weight: 600;
            padding: var(--space-4) var(--space-3);
            text-align: left;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }

        .marks-table td {
            padding: var(--space-4) var(--space-3);
            border-bottom: 1px solid var(--border);
            background: var(--text-white);
            color: var(--text-black);
        }

        .marks-table tr:hover td {
            background: var(--bg-hover);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .student-img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }

        .marks-input {
            width: 120px;
            padding: var(--space-2) var(--space-3);
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 16px;
            text-align: center;
        }

        .marks-input:focus {
            border-color: var(--primary);
            outline: none;
        }

        .marks-input.over-limit {
            border-color: var(--danger);
            background: #fee2e2;
        }

        /* Mobile Cards */
        .mobile-marks-container {
            display: none;
            flex-direction: column;
            gap: var(--space-4);
            margin-top: var(--space-4);
        }

        @media (max-width: 768px) {
            .table-responsive {
                display: none;
            }
            
            .mobile-marks-container {
                display: flex;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                padding: var(--space-4);
            }
            
            .exam-info {
                grid-template-columns: 1fr;
            }
        }

        .marks-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all var(--transition-base);
        }

        .marks-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary);
        }

        .marks-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 2px solid var(--border);
        }

        .student-id-badge {
            background: var(--gradient-primary);
            color: var(--text-white);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-full);
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .marks-card-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-3);
            padding-bottom: var(--space-3);
            border-bottom: 1px solid var(--border);
        }

        .marks-card-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .marks-card-label {
            font-weight: 600;
            color: var(--text-black);
        }

        .marks-card-value {
            color: var(--primary);
            font-weight: 600;
        }

        .mobile-marks-input {
            width: 100%;
            padding: var(--space-3);
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 16px;
            margin-top: var(--space-2);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: var(--space-4);
            margin-top: var(--space-4);
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            flex: 1;
            min-width: 150px;
        }

        /* Info Message */
        .info-message {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: var(--text-white);
            padding: var(--space-4) var(--space-6);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-4);
            display: flex;
            align-items: center;
            gap: var(--space-4);
            box-shadow: var(--shadow);
            animation: fadeIn 0.5s ease;
        }

        .info-message i {
            font-size: 24px;
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

        /* No Data */
        .no-data {
            text-align: center;
            padding: var(--space-10) var(--space-4);
            color: var(--text-black);
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: var(--space-4);
            color: var(--border);
            opacity: 0.5;
        }

        /* Messages */
        .success-message,
        .error-message,
        .info-message-popup {
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

        .info-message-popup {
            background: var(--gradient-info);
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
            
            .marks-card-row {
                flex-direction: column;
                gap: 5px;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
            
            .info-message {
                flex-direction: column;
                text-align: center;
                padding: var(--space-3);
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
        }

        /* Custom Scrollbar */
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
            <a href="exam_marks_entry.php" class="dropdown-item active">
                <i class="fas fa-pencil-alt"></i> Marks Entry
            </a>
            <a href="exam_marks_history.php" class="dropdown-item">
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

            <!-- Success/Error/Info Messages -->
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

            <?php if (isset($_SESSION['info_message'])): ?>
            <div class="info-message-popup" id="infoMessage">
                <i class="fas fa-info-circle"></i> <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
            </div>
            <?php endif; ?>

            <div class="page-title">
                <h1><i class="fas fa-pencil-alt"></i> Exam Marks Entry</h1>
                <p>Enter marks for students - Maximum marks from selected exam</p>
            </div>

            <!-- Filter Card -->
            <div class="card">
                <h2><i class="fas fa-filter"></i> Select Class & Medium</h2>
                <form method="get" id="filterForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class"><i class="fas fa-graduation-cap"></i> Class</label>
                            <select name="class" id="class" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php for($i=8; $i<=12; $i++): ?>
                                <option value="<?= $i ?>" <?= ($class == $i) ? 'selected' : '' ?>>
                                    Class <?= $i ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="medium"><i class="fas fa-language"></i> Medium</label>
                            <select name="medium" id="medium" class="form-control" required>
                                <option value="">Select Medium</option>
                                <option value="English" <?= ($medium == 'English') ? 'selected' : '' ?>>English Medium</option>
                                <option value="Hindi" <?= ($medium == 'Hindi') ? 'selected' : '' ?>>Hindi Medium</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="exam_id"><i class="fas fa-file-alt"></i> Select Exam</label>
                            <select name="exam_id" id="exam_id" class="form-control" required>
                                <option value="">First select class & medium</option>
                                <?php foreach($exams as $exam): ?>
                                <option value="<?= $exam['id'] ?>" <?= ($exam_id == $exam['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($exam['subject']) ?> - <?= htmlspecialchars($exam['topic']) ?> (Total: <?= $exam['total_marks'] ?? 100 ?> marks)
                                    <?php 
                                    // Check if marks already exist for this exam
                                    $check_marks = $conn->prepare("SELECT COUNT(*) as count FROM exam_marks WHERE exam_id = ?");
                                    $check_marks->bind_param("i", $exam['id']);
                                    $check_marks->execute();
                                    $marks_count = $check_marks->get_result()->fetch_assoc()['count'];
                                    if ($marks_count > 0) {
                                        echo " ✓ (Marks entered)";
                                    }
                                    ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-filter">
                                <i class="fas fa-search"></i> Load Students
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if($marks_already_exist && $selected_exam): ?>
            <!-- Info Message - Marks Already Exist -->
            <div class="info-message">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Marks already entered for this exam!</strong>
                    <p style="margin-top: 5px;">Marks have been saved for this exam. You can view or edit them in the history page.</p>
                </div>
                <a href="exam_marks_history.php?class=<?= urlencode($class) ?>&medium=<?= urlencode($medium) ?>&exam_id=<?= $exam_id ?>" class="btn btn-info" style="margin-left: auto;">
                    <i class="fas fa-history"></i> View History
                </a>
            </div>
            <?php endif; ?>

            <?php if($selected_exam && !empty($students) && !$marks_already_exist): ?>
            
            <!-- Exam Info Card -->
            <div class="exam-info">
                <div class="exam-info-item">
                    <i class="fas fa-book"></i>
                    <div class="label">Subject</div>
                    <div class="value"><?= htmlspecialchars($selected_exam['subject']) ?></div>
                </div>
                
                <div class="exam-info-item">
                    <i class="fas fa-tag"></i>
                    <div class="label">Topic</div>
                    <div class="value"><?= htmlspecialchars($selected_exam['topic']) ?></div>
                </div>
                
                <div class="exam-info-item">
                    <i class="fas fa-calendar"></i>
                    <div class="label">Exam Date</div>
                    <div class="value"><?= date('d M Y', strtotime($selected_exam['exam_date'])) ?></div>
                </div>
                
                <div class="exam-info-item">
                    <i class="fas fa-clock"></i>
                    <div class="label">Exam Time</div>
                    <div class="value"><?= date('h:i A', strtotime($selected_exam['exam_time'])) ?></div>
                </div>
                
                <div class="exam-info-item">
                    <i class="fas fa-star"></i>
                    <div class="label">Total Marks</div>
                    <div class="total-marks-badge">
                        <?= $selected_exam['total_marks'] ?? 100 ?>
                    </div>
                </div>
            </div>

            <!-- Marks Entry Form -->
            <div class="card">
                <h2><i class="fas fa-pencil-alt"></i> Enter Students Marks (Max: <?= $selected_exam['total_marks'] ?? 100 ?>)</h2>
                
                <form method="post" id="marksForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="exam_id" value="<?= $selected_exam['id'] ?>">
                    <input type="hidden" name="class" value="<?= $class ?>">
                    <input type="hidden" name="medium" value="<?= $medium ?>">

                    <!-- Desktop Table View -->
                    <div class="table-responsive">
                        <table class="marks-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Father Name</th>
                                    <th><span id="marksHeaderLabel">Marks (Max: <?= $selected_exam['total_marks'] ?? 100 ?>)</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): 
                                    $obtained = $existing_marks[$student['id']] ?? '';
                                    $photo_path = '../assets/user.png';
                                    if (!empty($student['photo'])) {
                                        if (strpos($student['photo'], 'uploads/') !== false) {
                                            $photo_path = '../student/' . $student['photo'];
                                        } else {
                                            $photo_path = $student['photo'];
                                        }
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <img src="<?= $photo_path ?>" alt="Student" class="student-img" 
                                                 onerror="this.src='../assets/user.png'">
                                            <div>
                                                <strong><?= htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? '')) ?></strong>
                                                <br>
                                                <small>ID: <?= $student['id'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($student['father_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <input type="number" 
                                               name="marks[<?= $student['id'] ?>]" 
                                               class="marks-input" 
                                               value="<?= htmlspecialchars($obtained) ?>"
                                               min="0" 
                                               max="<?= $selected_exam['total_marks'] ?? 100 ?>"
                                               placeholder="0-<?= $selected_exam['total_marks'] ?? 100 ?>"
                                               onchange="validateMark(this, <?= $selected_exam['total_marks'] ?? 100 ?>)"
                                               onkeyup="validateMark(this, <?= $selected_exam['total_marks'] ?? 100 ?>)">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards View -->
                    <div class="mobile-marks-container">
                        <?php foreach($students as $student): 
                            $obtained = $existing_marks[$student['id']] ?? '';
                            $photo_path = '../assets/user.png';
                            if (!empty($student['photo'])) {
                                if (strpos($student['photo'], 'uploads/') !== false) {
                                    $photo_path = '../student/' . $student['photo'];
                                } else {
                                    $photo_path = $student['photo'];
                                }
                            }
                        ?>
                        <div class="marks-card fade-in">
                            <div class="marks-card-header">
                                <div class="student-id-badge">
                                    <i class="fas fa-id-card"></i> ID: <?= $student['id'] ?>
                                </div>
                                <img src="<?= $photo_path ?>" alt="Student" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary);" onerror="this.src='../assets/user.png'">
                            </div>
                            
                            <div class="marks-card-row">
                                <span class="marks-card-label"><i class="fas fa-user"></i> Name:</span>
                                <span class="marks-card-value"><?= htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? '')) ?></span>
                            </div>
                            
                            <div class="marks-card-row">
                                <span class="marks-card-label"><i class="fas fa-user-tie"></i> Father:</span>
                                <span class="marks-card-value"><?= htmlspecialchars($student['father_name'] ?? 'N/A') ?></span>
                            </div>
                            
                            <div class="marks-card-row">
                                <span class="marks-card-label"><i class="fas fa-star"></i> Max Marks:</span>
                                <span class="marks-card-value"><?= $selected_exam['total_marks'] ?? 100 ?></span>
                            </div>
                            
                            <label class="marks-card-label" style="margin-bottom: var(--space-2); display: block;">Enter Marks:</label>
                            <input type="number" 
                                name="mobile_marks[<?= $student['id'] ?>]" 
                                class="mobile-marks-input" 
                                value="<?= htmlspecialchars($obtained) ?>"
                                min="0" 
                                max="<?= $selected_exam['total_marks'] ?? 100 ?>"
                                placeholder="0-<?= $selected_exam['total_marks'] ?? 100 ?>"
                                onchange="validateMark(this, <?= $selected_exam['total_marks'] ?? 100 ?>)"
                                onkeyup="validateMark(this, <?= $selected_exam['total_marks'] ?? 100 ?>)">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" name="save_marks" class="btn btn-success" onclick="return confirmSave()">
                            <i class="fas fa-save"></i> Save All Marks
                        </button>
                        
                        <a href="exam_marks_history.php?class=<?= urlencode($class) ?>&medium=<?= urlencode($medium) ?>" class="btn btn-info">
                            <i class="fas fa-history"></i> View History
                        </a>
                        
                        <a href="exam_marks_entry.php?class=<?= urlencode($class) ?>&medium=<?= urlencode($medium) ?>" class="btn btn-danger">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                    
                    <div style="margin-top: var(--space-4); text-align: center; color: var(--text-black); font-size: 14px;">
                        <i class="fas fa-info-circle"></i>
                        Make sure all marks are between 0 and <?= $selected_exam['total_marks'] ?? 100 ?>
                    </div>
                </form>
            </div>

            <?php elseif($class && $medium && empty($students) && !empty($student_table)): ?>
            <!-- No Students Found -->
            <div class="card">
                <div class="no-data">
                    <i class="fas fa-users-slash"></i>
                    <h3>No Students Found</h3>
                    <p>No students in Class <?= $class ?> (<?= $medium ?> Medium)</p>
                </div>
            </div>
            
            <?php elseif($class && $medium && empty($exams)): ?>
            <!-- No Exams Found -->
            <div class="card">
                <div class="no-data">
                    <i class="fas fa-file-excel"></i>
                    <h3>No Exams Found</h3>
                    <p>Please add an exam first in <a href="teacher_add_exam.php" style="color: var(--text-white); text-decoration: underline;">Add Exam</a> page</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="footer">
                <p>&copy; <?= date('Y') ?> Ruchi Classes. All rights reserved.</p>
                <p style="margin-top: 5px; font-size: 0.8rem; opacity: 0.7;">
                    <i class="fas fa-shield-alt"></i> Secure Exam Management System
                </p>
            </div>
        </div>
    </div>

    <!-- SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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

            // ==================== AUTO LOAD EXAMS ====================
            const classSelect = document.getElementById('class');
            const mediumSelect = document.getElementById('medium');
            const examSelect = document.getElementById('exam_id');

            if (classSelect && mediumSelect) {
                function loadExams() {
                    if (classSelect.value && mediumSelect.value) {
                        window.location.href = `exam_marks_entry.php?class=${classSelect.value}&medium=${mediumSelect.value}`;
                    }
                }
                classSelect.addEventListener('change', loadExams);
                mediumSelect.addEventListener('change', loadExams);
            }

            if (examSelect) {
                examSelect.addEventListener('change', function() {
                    if (this.value && classSelect.value && mediumSelect.value) {
                        window.location.href = `exam_marks_entry.php?class=${classSelect.value}&medium=${mediumSelect.value}&exam_id=${this.value}`;
                    }
                });
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

            // ==================== AUTO-HIDE MESSAGES ====================
            setTimeout(() => {
                document.querySelectorAll('.success-message, .error-message, .info-message-popup').forEach(msg => {
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

        // ==================== VALIDATE MARK ====================
        function validateMark(input, maxMarks) {
            let value = parseInt(input.value);
            if (isNaN(value) || input.value === '') {
                input.classList.remove('over-limit');
                return true;
            }
            if (value < 0) {
                input.value = 0;
                input.classList.remove('over-limit');
            } else if (value > maxMarks) {
                input.classList.add('over-limit');
                input.value = maxMarks;
            } else {
                input.classList.remove('over-limit');
            }
        }

        // ==================== CONFIRM SAVE ====================
        function confirmSave() {
            const marksInputs = document.querySelectorAll('.marks-input, .mobile-marks-input');
            let hasMarks = false;
            
            marksInputs.forEach(input => {
                if (input.value !== '' && input.value !== null) {
                    hasMarks = true;
                }
            });

            if (!hasMarks) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Marks Entered',
                    text: 'Please enter marks for at least one student.',
                    confirmButtonColor: '#2563eb',
                    background: '#ffffff',
                    backdrop: 'rgba(0,0,0,0.3)'
                });
                return false;
            }

            Swal.fire({
                title: 'Save Marks?',
                text: "Are you sure you want to save these marks?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#2563eb',
                confirmButtonText: 'Yes, save them!',
                cancelButtonText: 'Cancel',
                background: '#ffffff',
                backdrop: 'rgba(0,0,0,0.3)'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Saving...',
                        html: 'Please wait while we save the marks.',
                        timer: 1500,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    }).then(() => {
                        document.getElementById('marksForm').submit();
                    });
                }
            });
            return false;
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

        // ==================== DISABLE DUPLICATE INPUTS ====================
        document.addEventListener('DOMContentLoaded', function() {
            function handleInputVisibility() {
                const isMobile = window.innerWidth <= 768;

                document.querySelectorAll('.marks-input').forEach(input => {
                    input.disabled = isMobile;
                });

                document.querySelectorAll('.mobile-marks-input').forEach(input => {
                    input.disabled = !isMobile;
                });
            }

            handleInputVisibility();
            window.addEventListener('resize', handleInputVisibility);
        });
    </script>
<?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>
