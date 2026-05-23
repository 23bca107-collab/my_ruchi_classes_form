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
$teacher['id'] = $teacher_id;

// Log page access
logTeacherActivity('VIEW_ATTENDANCE', 'Viewed attendance page');

// Get assigned classes for teacher from attendance_tasks
$assigned = [];
$stmt = $conn->prepare("
    SELECT class, medium, subject, task_date as date
    FROM attendance_tasks
    WHERE teacher_id=? AND status='pending' AND is_locked=0
    AND task_date = CURDATE()
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $assigned[] = $row;
}

// Also get from teacher_assignments (for permanent assignments)
$permanent_assigned = [];
$stmt2 = $conn->prepare("
    SELECT class, medium, subject
    FROM teacher_assignments
    WHERE teacher_id=? AND status='assigned'
");
$stmt2->bind_param("i", $teacher_id);
$stmt2->execute();
$res2 = $stmt2->get_result();

while ($row = $res2->fetch_assoc()) {
    $permanent_assigned[] = $row;
}

// Merge both assignments
$all_assigned = array_merge($assigned, $permanent_assigned);

// Selected filters
$class = $_GET['class'] ?? '';
$medium = $_GET['medium'] ?? '';
$subject = $_GET['subject'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
$is_locked = false;
$lock_message = '';
$submitted_at = null;
$attendance_status = 'not_submitted';

// Check if this is an update request (if admin approved)
$update_request_id = $_GET['update_request'] ?? 0;
$is_update_mode = false;
$approved_request = null;

if ($update_request_id > 0) {
    $stmt = $conn->prepare("
        SELECT * FROM attendance_update_requests 
        WHERE id=? AND teacher_id=? AND status='approved'
    ");
    $stmt->bind_param("ii", $update_request_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $approved_request = $result->fetch_assoc();
    
    if ($approved_request) {
        $is_update_mode = true;
        $class = $approved_request['class'];
        $medium = $approved_request['medium'];
        $subject = $approved_request['subject'];
        $date = $approved_request['date'];
        $is_locked = false; // Allow updates for approved requests
    }
}

// Check if attendance is locked via attendance_tasks table
if (!$is_update_mode && $class && $medium && $subject && $date) {
    $lockStmt = $conn->prepare("
        SELECT is_locked, status, submitted_at, completed_at 
        FROM attendance_tasks 
        WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND task_date=?
    ");
    $lockStmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();
    $lockData = $lockResult->fetch_assoc();
    
    if ($lockData) {
        if ($lockData['is_locked'] == 1) {
            $is_locked = true;
            $lock_message = "Attendance for $date is locked and cannot be modified.";
            $submitted_at = $lockData['submitted_at'];
            $attendance_status = 'locked';
        } elseif ($lockData['status'] == 'completed' && $lockData['is_locked'] == 0) {
            // Automatically lock if completed but not locked
            $conn->query("UPDATE attendance_tasks SET is_locked=1 WHERE teacher_id=$teacher_id AND class='$class' AND medium='$medium' AND subject='$subject' AND task_date='$date'");
            $is_locked = true;
            $lock_message = "Attendance for $date has been completed and is now locked.";
            $attendance_status = 'completed';
        } elseif ($lockData['status'] == 'pending') {
            $attendance_status = 'pending';
        }
    }
    
    // Also check if date is in the past (only if not in update mode)
    if (!$is_update_mode && $date && $date < date('Y-m-d')) {
        // Check if attendance exists for past date
        $pastCheck = $conn->prepare("
            SELECT COUNT(*) as count FROM attendance 
            WHERE class=? AND medium=? AND subject=? AND date=?
        ");
        $pastCheck->bind_param("isss", $class, $medium, $subject, $date);
        $pastCheck->execute();
        $pastResult = $pastCheck->get_result();
        $pastData = $pastResult->fetch_assoc();
        
        if ($pastData['count'] > 0 && !$lockData) {
            // If attendance exists but no task record, lock it
            $is_locked = true;
            $lock_message = "Attendance for past date $date is locked.";
        }
    }
}

// Check if update request exists for this date
$existing_request = null;
if ($class && $medium && $subject && $date) {
    $stmt = $conn->prepare("
        SELECT * FROM attendance_update_requests 
        WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=?
    ");
    $stmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_request = $result->fetch_assoc();
}

// Get teacher info for sidebar (already have from getTeacherInfo)
// But ensure we have all needed fields
if (!isset($teacher['photo'])) $teacher['photo'] = '';
if (!isset($teacher['first_name'])) $teacher['first_name'] = '';
if (!isset($teacher['last_name'])) $teacher['last_name'] = '';
if (!isset($teacher['subject'])) $teacher['subject'] = '';

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

// Validate assignment
$allowed = false;

if ($is_update_mode && $approved_request) {
    // If it's an approved update request, always allow
    $allowed = true;
} else {
    foreach ($all_assigned as $a) {
        if (isset($a['date'])) {
            // For daily tasks, check date too
            if ($a['class']==$class && $a['medium']==$medium && $a['subject']==$subject && $a['date']==$date) {
                $allowed = true;
                break;
            }
        } else {
            // For permanent assignments, only check class/medium/subject
            if ($a['class']==$class && $a['medium']==$medium && $a['subject']==$subject) {
                $allowed = true;
                break;
            }
        }
    }
}

if ($class && $medium && $subject && !$allowed && !$is_update_mode) {
    $_SESSION['error_message'] = 'You are not assigned to this class/subject or date.';
    header("Location: teacher_attendance.php");
    exit();
}

// Fetch students
$students = [];
$attendance_data = [];

if ($allowed) {
    $table = ($medium=='English') ? 'student_english' : 'student_hindi';
    $stmt = $conn->prepare("SELECT id, first_name, last_name, photo FROM $table WHERE class=? ORDER BY first_name");
    $stmt->bind_param("s", $class);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    // Get existing attendance for the date
    if ($students) {
        $stmt = $conn->prepare("
            SELECT student_id, status 
            FROM attendance 
            WHERE class=? AND medium=? AND subject=? AND date=?
        ");
        $stmt->bind_param("isss", $class, $medium, $subject, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $attendance_data[$row['student_id']] = $row['status'];
        }
    }
}

// Handle attendance submission - UPDATED SECTION WITH ATTENDANCE HISTORY
if ($_SERVER['REQUEST_METHOD']=='POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateTeacherCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'Security token invalid. Please try again.';
        header("Location: teacher_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
        exit();
    }
    
    if (isset($_POST['attendance'])) {
        // Regular attendance submission
        if ($is_locked && !$is_update_mode) {
            $_SESSION['error_message'] = 'Cannot modify locked attendance!';
            header("Location: teacher_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // First, delete existing attendance for this class/date/subject (for fresh submission)
            // This ensures we don't have duplicate records
            $delete_stmt = $conn->prepare("
                DELETE FROM attendance 
                WHERE class=? AND medium=? AND subject=? AND date=?
            ");
            $delete_stmt->bind_param("isss", $class, $medium, $subject, $date);
            $delete_stmt->execute();
            
            // Now insert all attendance records
            foreach ($_POST['attendance'] as $sid=>$status) {
                $stmt = $conn->prepare("
                    INSERT INTO attendance 
                    (student_id, class, medium, subject, date, status, teacher_id, submitted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param("isssssi", $sid, $class, $medium, $subject, $date, $status, $teacher_id);
                $stmt->execute();
                
                // TRIGGER will automatically insert into attendance_history
                // No need for manual insertion here
            }
            
            // Mark attendance task as completed and lock it
            $updateTaskStmt = $conn->prepare("
                UPDATE attendance_tasks 
                SET status='completed', submitted_at=NOW(), is_locked=1, completed_at=NOW()
                WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND task_date=?
            ");
            $updateTaskStmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
            $updateTaskStmt->execute();
            
            // If no task exists, create one
            if ($updateTaskStmt->affected_rows == 0) {
                $createTaskStmt = $conn->prepare("
                    INSERT INTO attendance_tasks 
                    (teacher_id, class, medium, subject, task_date, status, is_locked, submitted_at, completed_at, assigned_at)
                    VALUES (?, ?, ?, ?, ?, 'completed', 1, NOW(), NOW(), NOW())
                ");
                $createTaskStmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
                $createTaskStmt->execute();
            }
            
            // If this was an update from an approved request, mark it as completed
            if ($is_update_mode && $update_request_id > 0) {
                $stmt = $conn->prepare("
                    UPDATE attendance_update_requests 
                    SET status='completed', reviewed_at=NOW() 
                    WHERE id=?
                ");
                $stmt->bind_param("i", $update_request_id);
                $stmt->execute();
                
                // Lock again after update
                $lockAfterUpdate = $conn->prepare("
                    UPDATE attendance_tasks 
                    SET is_locked=1, completed_at=NOW() 
                    WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND task_date=?
                ");
                $lockAfterUpdate->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
                $lockAfterUpdate->execute();
                
                $_SESSION['success_message'] = 'Attendance updated and locked successfully!';
            } else {
                $_SESSION['success_message'] = 'Attendance saved and locked successfully!';
            }
            
            // Log the activity
            logTeacherActivity('ATTENDANCE_SUBMITTED', "Submitted attendance for Class $class, $subject on $date");
            
            $conn->commit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = 'Failed to save attendance: ' . $e->getMessage();
            header("Location: teacher_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
            exit();
        }
        
        // Redirect directly to dashboard to avoid reloading this page first
        header("Location: teacher_dashboard.php");
        exit();
    }
    
    // Handle update request submission
    if (isset($_POST['request_update'])) {
        $reason = trim($_POST['reason']);
        
        if (empty($reason)) {
            $_SESSION['error_message'] = 'Please provide a reason for the update request.';
            header("Location: teacher_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
            exit();
        }
        
        // Check if request already exists
        $stmt = $conn->prepare("
            SELECT id FROM attendance_update_requests 
            WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=? AND status IN ('pending', 'approved')
        ");
        $stmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['error_message'] = 'An update request for this date already exists.';
            header("Location: teacher_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
            exit();
        }
        
        // Create new update request
        $stmt = $conn->prepare("
            INSERT INTO attendance_update_requests (teacher_id, class, medium, subject, date, reason, status, requested_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iissss", $teacher_id, $class, $medium, $subject, $date, $reason);
        
        if ($stmt->execute()) {
            logTeacherActivity('UPDATE_REQUEST', "Requested update for Class $class, $subject on $date");
            $_SESSION['success_message'] = 'Update request submitted successfully! Waiting for admin approval.';
        } else {
            $_SESSION['error_message'] = 'Failed to submit update request. Please try again.';
        }
        
        header("Location: teacher_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
        exit();
    }
}

// Generate date options (last 7 days + today + tomorrow)
$date_options = [];
for ($i = 0; $i < 8; $i++) { // 7 past days + today
    $d = date('Y-m-d', strtotime("-$i days"));
    $date_options[$d] = date('M j, Y', strtotime($d));
}
// Add tomorrow if needed
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$date_options[$tomorrow] = date('M j, Y', strtotime($tomorrow)) . ' (Tomorrow)';

$assignment_filter_options = array_values(array_map(function ($assignment) {
    return [
        'class' => (string) ($assignment['class'] ?? ''),
        'medium' => (string) ($assignment['medium'] ?? ''),
        'subject' => (string) ($assignment['subject'] ?? ''),
    ];
}, $all_assigned));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Attendance - Ruchi Classes</title>
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

        /* ---------------- CONTENT STYLES ----------------- */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInDown 0.8s ease;
        }
        
        .page-header h1 {
            color: var(--text-primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .page-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 25px;
            margin-bottom: 30px;
            animation: fadeInUp 0.8s ease;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-actions {
            display: flex;
            align-items: flex-end;
        }

        .filter-actions .btn {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: var(--bg-secondary);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            background: white;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .btn:hover:not(:disabled) {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
        }
        
        .btn:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .btn-success:hover:not(:disabled) {
            background: #0da271;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }
        
        .btn-warning {
            background: var(--warning);
        }
        
        .btn-warning:hover:not(:disabled) {
            background: #d97706;
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.3);
        }
        
        .btn-info {
            background: var(--info);
        }
        
        .btn-info:hover:not(:disabled) {
            background: #0891b2;
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.3);
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 25px 0;
            font-size: 16px;
            animation: fadeIn 1s ease;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .attendance-table th {
            background: var(--gradient-primary);
            color: white;
            font-weight: 600;
            padding: 20px 15px;
            text-align: left;
            position: sticky;
            top: 0;
        }
        
        .attendance-table td {
            padding: 20px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            background: white;
        }
        
        .attendance-table tr:last-child td {
            border-bottom: none;
        }
        
        .attendance-table tr {
            transition: all 0.3s ease;
        }
        
        .attendance-table tr:hover {
            background: rgba(37, 99, 235, 0.05);
        }
        
        .attendance-table tr:hover td {
            transform: translateX(5px);
        }
        
        .student-photo {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        .student-photo:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .attendance-select {
            padding: 10px 15px;
            border-radius: 8px;
            border: 2px solid var(--border);
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            min-width: 150px;
            font-weight: 500;
        }
        
        .attendance-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .attendance-select:disabled {
            background-color: var(--bg-secondary);
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
            font-size: 18px;
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            color: var(--border);
            opacity: 0.5;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            color: var(--text-secondary);
            font-size: 14px;
            padding: 20px;
        }
        
        .lock-banner {
            background: linear-gradient(135deg, #ff6b6b 0%, #ff8e8e 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: white;
            font-weight: 600;
            animation: pulse 2s infinite;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.2);
        }
        
        .lock-icon {
            margin-right: 10px;
            font-size: 1.3em;
        }
        
        .submitted-banner {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
        }
        
        .update-banner {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: white;
            font-weight: 600;
            animation: pulse 2s infinite;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);
        }
        
        .approval-banner {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
        }
        
        .date-info {
            text-align: center;
            margin-bottom: 25px;
            padding: 15px;
            background: var(--gradient-primary);
            color: white;
            border-radius: 12px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }
        
        .today-badge {
            background: var(--accent);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            margin-left: 10px;
            font-weight: 600;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            min-width: 90px;
        }
        
        .status-present { background: #d1fae5; color: #065f46; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-remaining { background: #e0e7ff; color: #3730a3; }
        
        /* Attendance Status Badges */
        .attendance-status-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 90px;
        }
        
        .attendance-pending { background: #fef3c7; color: #92400e; }
        .attendance-submitted { background: #d1fae5; color: #065f46; }
        .attendance-locked { background: #fee2e2; color: #991b1b; }
        
        /* Request Status Badges */
        .request-status {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 90px;
        }
        
        .request-pending { background: #fef3c7; color: #92400e; }
        .request-approved { background: #d1fae5; color: #065f46; }
        .request-rejected { background: #fee2e2; color: #991b1b; }
        .request-completed { background: #dbeafe; color: #1e40af; }
        
        /* Teacher Info Badge */
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
        
        /* Action Buttons Container */
        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
            justify-content: center;
        }
        
        /* Submitted Info */
        .submitted-info {
            background: #f0f9ff;
            border-left: 4px solid #0ea5e9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        /* ---------------- ANIMATIONS ---------------- */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease;
        }
        
        /* Request Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }
        
        .modal {
            background: white;
            border-radius: 16px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }
        
        .modal-title {
            font-size: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                width: 320px;
                z-index: 1000;
                box-shadow: 0 0 50px rgba(0, 0, 0, 0.2);
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
            
            .page-header h1 {
                font-size: 28px;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .attendance-table {
                display: block;
                overflow-x: auto;
            }
            
            .attendance-table th,
            .attendance-table td {
                padding: 15px 10px;
                font-size: 14px;
            }
            
            .student-photo {
                width: 50px;
                height: 50px;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
                margin-bottom: 1rem;
                border-radius: 14px;
            }
            
            .user-menu {
                gap: 15px;
            }
            
            .toggle-sidebar {
                width: 45px;
                height: 45px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }

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
        
        /* Mobile dropdown improvements */
        @media (max-width: 1024px) {
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
            }
            
            .dropdown-item:hover {
                transform: none !important;
                background: var(--primary-light) !important;
                color: white !important;
            }
            
            .dropdown-item i {
                font-size: 18px !important;
                width: 25px !important;
                text-align: center !important;
            }
            
            .dropdown-item {
                min-height: 60px;
                display: flex !important;
                align-items: center !important;
                gap: 15px !important;
            }
        }

        .dropdown-menu {
            z-index: 1001;
            position: relative;
        }

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

        .dropdown-item:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary)) !important;
            color: white !important;
            padding-left: 30px !important;
            transition: all 0.3s ease !important;
        }
</style>
<?php teacher_notifications_render_styles(); ?>
</head>
<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Request Update Modal -->
    <div class="modal-overlay" id="requestModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-edit"></i> Request Attendance Update</h2>
                <button class="modal-close" id="closeModal">&times;</button>
            </div>
            <form method="post" id="requestUpdateForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="class" value="<?= htmlspecialchars($class) ?>">
                <input type="hidden" name="medium" value="<?= htmlspecialchars($medium) ?>">
                <input type="hidden" name="subject" value="<?= htmlspecialchars($subject) ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
                
                <div class="form-group">
                    <label for="reason"><i class="fas fa-comment"></i> Reason for Update</label>
                    <textarea name="reason" id="reason" class="form-control" placeholder="Please explain why you need to update the attendance..." required></textarea>
                    <small style="color: var(--text-muted); font-size: 12px; margin-top: 5px; display: block;">
                        Your request will be sent to the administrator for approval.
                    </small>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 25px;">
                    <button type="button" class="btn" id="cancelRequest" style="background: var(--text-muted); flex: 1;">Cancel</button>
                    <button type="submit" name="request_update" class="btn btn-warning" style="flex: 1;">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
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
                <?php
                $teacher_notifications_data = teacher_notifications_prepare($conn, $teacher, 12);
                teacher_notifications_render_button($teacher_notifications_data);
                ?>
                <div class="user-profile" onclick="showProfileMenu(event)">
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

        <!-- Profile Menu Dropdown -->
        <div id="profileMenu" style="display: none; position: absolute; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); padding: 10px 0; min-width: 200px; z-index: 1000;">
            <a href="teacher_profile.php" style="display: block; padding: 10px 20px; text-decoration: none; color: #333; transition: 0.3s;">
                <i class="fas fa-user"></i> My Profile
            </a>
            <hr style="margin: 5px 0; border: 1px solid #eee;">
            <a href="#" onclick="confirmLogout(event)" style="display: block; padding: 10px 20px; text-decoration: none; color: #ef4444; transition: 0.3s;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
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

            <div class="page-header">
                <h1><i class="fas fa-chalkboard-teacher"></i> Teacher Attendance System</h1>
                <p>Mark and manage student attendance efficiently</p>
            </div>
            
            <!-- Show banners based on status -->
            <?php if ($is_update_mode): ?>
            <div class="approval-banner fade-in">
                <i class="fas fa-check-circle"></i> 
                ADMIN APPROVED: You can now update attendance for <?= date('F j, Y', strtotime($date)) ?>
            </div>
            <?php elseif ($existing_request): ?>
                <?php if ($existing_request['status'] == 'pending'): ?>
                <div class="update-banner fade-in">
                    <i class="fas fa-clock"></i> 
                    Update Request Pending: Waiting for admin approval
                    <div style="margin-top: 10px; font-size: 14px;">
                        <strong>Reason:</strong> <?= htmlspecialchars($existing_request['reason']) ?>
                    </div>
                </div>
                <?php elseif ($existing_request['status'] == 'approved'): ?>
                <div class="approval-banner fade-in">
                    <i class="fas fa-check-circle"></i> 
                    Update Request Approved! 
                    <a href="teacher_attendance.php?update_request=<?= $existing_request['id'] ?>" class="btn btn-success" style="margin-left: 15px; padding: 8px 20px;">
                        <i class="fas fa-edit"></i> Update Attendance
                    </a>
                </div>
                <?php elseif ($existing_request['status'] == 'rejected'): ?>
                <div class="lock-banner fade-in">
                    <i class="fas fa-times-circle"></i> 
                    Update Request Rejected
                    <?php if (!empty($existing_request['admin_notes'])): ?>
                    <div style="margin-top: 10px; font-size: 14px;">
                        <strong>Admin Note:</strong> <?= htmlspecialchars($existing_request['admin_notes']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php elseif ($is_locked && $submitted_at): ?>
            <div class="submitted-banner fade-in">
                <i class="fas fa-check-circle"></i> 
                ATTENDANCE SUBMITTED & LOCKED
                <?php if ($submitted_at): ?>
                <div style="margin-top: 10px; font-size: 14px;">
                    Submitted on: <?= date('d M Y, h:i A', strtotime($submitted_at)) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php elseif ($is_locked): ?>
            <div class="lock-banner fade-in">
                <i class="fas fa-lock lock-icon"></i>
                <?= htmlspecialchars($lock_message) ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($all_assigned)): ?>
            <div class="card">
                <h2 style="margin-bottom: 20px; color: var(--primary);"><i class="fas fa-filter"></i> Select Class & Subject</h2>
                <form method="get" class="filter-form" id="attendanceFilterForm" action="teacher_attendance.php#attendanceSection">
                    <div class="form-group">
                        <label for="class">Class</label>
                        <select name="class" id="class" class="form-control" required>
                            <option value="">-- Select Class --</option>
                            <?php 
                            $unique_classes = array_unique(array_column($all_assigned, 'class'));
                            foreach ($unique_classes as $c): ?>
                                <option value="<?= $c ?>" <?= ($class==$c) ? 'selected' : '' ?>>
                                    Class <?= $c ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="medium">Medium</label>
                        <select name="medium" id="medium" class="form-control" data-initial-value="<?= htmlspecialchars($medium) ?>" required>
                            <option value="">-- Select Medium --</option>
                            <?php 
                            $unique_mediums = array_unique(array_column($all_assigned, 'medium'));
                            foreach ($unique_mediums as $m): ?>
                                <option value="<?= $m ?>" <?= ($medium==$m) ? 'selected' : '' ?>>
                                    <?= $m ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <select name="subject" id="subject" class="form-control" data-initial-value="<?= htmlspecialchars($subject) ?>" <?= ($class && $medium) ? '' : 'disabled' ?> required>
                            <option value="">-- Select Subject --</option>
                            <?php 
                            $filtered_subjects = [];
                            if ($class && $medium) {
                                $matching_assignments = array_filter($all_assigned, function($a) use ($class, $medium) {
                                    return $a['class'] == $class && $a['medium'] == $medium;
                                });
                                $filtered_subjects = array_unique(array_column($matching_assignments, 'subject'));
                            }
                            foreach ($filtered_subjects as $subject_option): ?>
                                <option value="<?= $subject_option ?>" <?= ($subject == $subject_option) ? 'selected' : '' ?>>
                                    <?= $subject_option ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date">Date</label>
                        <select name="date" id="date" class="form-control" required>
                            <?php foreach ($date_options as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($date==$value) ? 'selected' : '' ?>>
                                    <?= $label ?>
                                    <?= ($value == date('Y-m-d')) ? '(Today)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group filter-actions">
                        <button type="submit" class="btn btn-info" id="viewAttendanceBtn" <?= ($class && $medium && $subject) ? '' : 'disabled' ?>>
                            <i class="fas fa-search"></i> View Attendance
                        </button>
                    </div>
                </form>
                
                <?php if ($class && $medium && $subject): ?>
                <div class="submitted-info">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <strong>Attendance Status:</strong>
                            <span class="attendance-status-badge attendance-<?= $attendance_status ?>" style="margin-left: 10px;">
                                <?= ucfirst(str_replace('_', ' ', $attendance_status)) ?>
                            </span>
                        </div>
                        <?php if ($submitted_at): ?>
                        <div style="font-size: 13px; color: var(--text-secondary);">
                            <i class="fas fa-clock"></i> Submitted: <?= date('d M Y, h:i A', strtotime($submitted_at)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($allowed && $students): ?>
            <div class="card fade-in" id="attendanceSection">
                <div class="date-info">
                    <i class="fas fa-calendar-alt"></i> 
                    <?= $is_update_mode ? 'Updating' : 'Viewing' ?> attendance for: <strong><?= date('F j, Y', strtotime($date)) ?></strong>
                    <?php if ($date == date('Y-m-d')): ?>
                        <span class="today-badge">Today</span>
                    <?php endif; ?>
                    <?php if ($existing_request): ?>
                        <span class="request-status request-<?= $existing_request['status'] ?>" style="margin-left: 10px;">
                            <?= ucfirst($existing_request['status']) ?> Request
                        </span>
                    <?php endif; ?>
                </div>
                
                <h2 style="margin-bottom: 20px; color: var(--primary);">
                    <i class="fas fa-users"></i> Student List - Class <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($medium) ?>)
                </h2>
                <p style="margin-bottom: 25px; color: var(--text-secondary);">
                    <i class="fas fa-book"></i> Subject: <strong><?= htmlspecialchars($subject) ?></strong>
                </p>
                
                <form method="post" id="attendanceForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Photo</th>
                                <th>Student Name</th>
                                <th>Attendance Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $index => $s): 
                                $prev_status = $attendance_data[$s['id']] ?? 'P';
                                $status_class = '';
                                switch($prev_status) {
                                    case 'P': $status_class = 'status-present'; break;
                                    case 'A': $status_class = 'status-absent'; break;
                                    case 'S': $status_class = 'status-suspended'; break;
                                    case 'R': $status_class = 'status-remaining'; break;
                                }
                            ?>
                            <tr style="animation-delay: <?= $index * 0.05 ?>s;">
                                <td style="font-weight: 600;"><?= $index + 1 ?></td>
                                <td>
                                <?php
                                $photoFile = $s['photo']; 
                                $serverPath = $_SERVER['DOCUMENT_ROOT'] . "/ruchi_classes_form/student/uploads/" . basename($photoFile);
                                $webPath = "/ruchi_classes_form/student/uploads/" . basename($photoFile);
                                if (!empty($photoFile) && file_exists($serverPath)) {
                                    echo '<img src="'.$webPath.'" alt="'.htmlspecialchars($s['first_name']).'" class="student-photo">';
                                } else {
                                    $avatarName = urlencode($s['first_name'].' '.$s['last_name']);
                                    echo '<img src="https://ui-avatars.com/api/?name='.$avatarName.'&size=65" class="student-photo">';
                                }
                                ?>
                                </td>
                                <td style="font-weight: 500;"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></td>
                                <td>
                                    <select name="attendance[<?= $s['id'] ?>]" class="attendance-select" <?= $is_locked && !$is_update_mode ? 'disabled' : '' ?> required>
                                        <option value="P" <?= ($prev_status == 'P') ? 'selected' : '' ?>>Present</option>
                                        <option value="A" <?= ($prev_status == 'A') ? 'selected' : '' ?>>Absent</option>
                                        <option value="S" <?= ($prev_status == 'S') ? 'selected' : '' ?>>Suspended</option>
                                        <option value="R" <?= ($prev_status == 'R') ? 'selected' : '' ?>>Remaining</option>
                                    </select>
                                    <div class="status-badge <?= $status_class ?>" style="margin-top: 8px; display: inline-block;">
                                        <?= $prev_status ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="action-buttons">
                        <?php if ($is_update_mode): ?>
                            <button type="submit" class="btn btn-success" style="min-width: 200px; padding: 16px 30px;">
                                <i class="fas fa-save"></i> Update & Lock Attendance
                            </button>
                            <a href="teacher_attendance.php" class="btn" style="min-width: 200px; padding: 16px 30px;">
                                <i class="fas fa-times"></i> Cancel Update
                            </a>
                        <?php elseif (!$is_locked): ?>
                            <button type="submit" class="btn btn-success" style="min-width: 200px; padding: 16px 30px;">
                                <i class="fas fa-save"></i> Save & Lock Attendance
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($is_locked && !$existing_request): ?>
                            <button type="button" class="btn btn-warning" id="requestUpdateBtn" style="min-width: 200px; padding: 16px 30px;">
                                <i class="fas fa-edit"></i> Request Update
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <p style="color: var(--text-muted); font-size: 14px;">
                            <i class="fas fa-info-circle"></i> 
                            <?php if ($is_update_mode): ?>
                                You are updating attendance with admin approval. After updating, attendance will be locked again.
                            <?php elseif ($is_locked): ?>
                                Attendance is locked. Request admin approval to make changes.
                            <?php else: ?>
                                After saving, attendance will be automatically locked and cannot be edited without admin approval.
                            <?php endif; ?>
                        </p>
                    </div>
                </form>
            </div>
            <?php elseif ($allowed && empty($students)): ?>
            <div class="card fade-in" id="attendanceSection">
                <div class="no-data">
                    <i class="fas fa-user-slash"></i>
                    <h3 style="margin-bottom: 10px;">No Students Found</h3>
                    <p>No students found for Class <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($medium) ?>)</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="card">
                <div class="no-data">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3 style="margin-bottom: 10px;">No Classes Assigned</h3>
                    <p>You have not been assigned to any classes yet.</p>
                    <p style="margin-top: 10px; font-size: 14px; color: var(--text-muted);">
                        Contact the administrator to get assigned to classes.
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="footer">
                <p>&copy; <?= date('Y') ?> Ruchi Classes. All rights reserved.</p>
                <p style="margin-top: 5px; font-size: 12px; opacity: 0.7;">
                    <i class="fas fa-shield-alt"></i> Secure Attendance System
                </p>
            </div>
        </div>
    </div>

<!-- SweetAlert JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const toggleBtn = document.getElementById('toggleSidebar');
    const toggleIcon = document.getElementById('toggleIcon');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const logoImg = document.getElementById('logoImg');
    const logoText = document.getElementById('logoText');
    const requestModal = document.getElementById('requestModal');
    const requestUpdateBtn = document.getElementById('requestUpdateBtn');
    const closeModal = document.getElementById('closeModal');
    const cancelRequest = document.getElementById('cancelRequest');
    const requestUpdateForm = document.getElementById('requestUpdateForm');
    const profileMenu = document.getElementById('profileMenu');
    const filterForm = document.getElementById('attendanceFilterForm');
    const classFilter = document.getElementById('class');
    const mediumFilter = document.getElementById('medium');
    const subjectFilter = document.getElementById('subject');
    const dateFilter = document.getElementById('date');
    const viewAttendanceBtn = document.getElementById('viewAttendanceBtn');
    const attendanceAssignments = <?= json_encode($assignment_filter_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function getUniqueAssignmentValues(items, key) {
        return [...new Set(items.map(item => (item[key] || '').toString()).filter(Boolean))];
    }

    function rebuildSelectOptions(select, values, placeholder, selectedValue = '') {
        if (!select) return '';

        const normalizedSelectedValue = (selectedValue || '').toString();
        select.innerHTML = '';

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = placeholder;
        select.appendChild(defaultOption);

        values.forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            if (value === normalizedSelectedValue) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        if (normalizedSelectedValue && !values.includes(normalizedSelectedValue)) {
            select.value = '';
        }

        return select.value;
    }

    function updateFilterSubmitState() {
        if (!viewAttendanceBtn) return;

        viewAttendanceBtn.disabled = !(
            classFilter &&
            mediumFilter &&
            subjectFilter &&
            dateFilter &&
            classFilter.value &&
            mediumFilter.value &&
            subjectFilter.value &&
            dateFilter.value
        );
    }

    function refreshFilterOptions(preserveInitialSelections = false) {
        if (!classFilter || !mediumFilter || !subjectFilter) return;

        const selectedClass = classFilter.value;
        const preferredMedium = preserveInitialSelections
            ? (mediumFilter.dataset.initialValue || mediumFilter.value)
            : mediumFilter.value;

        const availableMediums = selectedClass
            ? getUniqueAssignmentValues(
                attendanceAssignments.filter(item => item.class === selectedClass),
                'medium'
            )
            : getUniqueAssignmentValues(attendanceAssignments, 'medium');

        rebuildSelectOptions(mediumFilter, availableMediums, '-- Select Medium --', preferredMedium);
        mediumFilter.disabled = availableMediums.length === 0;

        const resolvedMedium = mediumFilter.value;
        const preferredSubject = preserveInitialSelections
            ? (subjectFilter.dataset.initialValue || subjectFilter.value)
            : subjectFilter.value;

        const availableSubjects = (selectedClass && resolvedMedium)
            ? getUniqueAssignmentValues(
                attendanceAssignments.filter(item => item.class === selectedClass && item.medium === resolvedMedium),
                'subject'
            )
            : [];

        rebuildSelectOptions(subjectFilter, availableSubjects, '-- Select Subject --', preferredSubject);
        subjectFilter.disabled = !(selectedClass && resolvedMedium) || availableSubjects.length === 0;

        updateFilterSubmitState();
    }
    
    // ==================== SIDEBAR TOGGLE FUNCTION ====================
    function handleSidebarToggle() {
        if (window.innerWidth < 1025) {
            // Mobile toggle
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            
            // Ensure logo is visible on mobile
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
    
    // ==================== SIDEBAR INITIALIZATION ====================
    if (toggleBtn) {
        toggleBtn.addEventListener('click', handleSidebarToggle);
    }

    if (filterForm && classFilter && mediumFilter && subjectFilter) {
        refreshFilterOptions(true);

        classFilter.addEventListener('change', function() {
            subjectFilter.dataset.initialValue = '';
            refreshFilterOptions(false);
        });

        mediumFilter.addEventListener('change', function() {
            subjectFilter.dataset.initialValue = '';
            refreshFilterOptions(false);
        });

        subjectFilter.addEventListener('change', updateFilterSubmitState);

        if (dateFilter) {
            dateFilter.addEventListener('change', updateFilterSubmitState);
        }
    }
    
    // Close sidebar on mobile overlay click
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
    
    // ==================== PROFILE MENU FUNCTIONALITY ====================
    window.showProfileMenu = function(event) {
        event.stopPropagation();
        const rect = event.currentTarget.getBoundingClientRect();
        
        profileMenu.style.display = 'block';
        profileMenu.style.top = (rect.bottom + window.scrollY + 10) + 'px';
        profileMenu.style.left = (rect.left + window.scrollX - 150) + 'px';
        
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
                if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
                
                // Close dropdowns when sidebar closes
                document.querySelectorAll('.dropdown').forEach(drop => {
                    drop.classList.remove('open');
                });
            });
        });
    }
    
    // ==================== MODAL FUNCTIONALITY ====================
    // Request Update Modal
    if (requestUpdateBtn) {
        requestUpdateBtn.addEventListener('click', function() {
            requestModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    }
    
    if (closeModal) {
        closeModal.addEventListener('click', function() {
            requestModal.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
    
    if (cancelRequest) {
        cancelRequest.addEventListener('click', function() {
            requestModal.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
    
    // Close modal when clicking outside
    if (requestModal) {
        requestModal.addEventListener('click', function(e) {
            if (e.target === requestModal) {
                requestModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }
    
    // ==================== FORM VALIDATIONS ====================
    // Attendance form confirmation with SweetAlert
    const attendanceForm = document.getElementById('attendanceForm');
    if (attendanceForm) {
        attendanceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const formTitle = submitBtn && submitBtn.textContent.includes('Update') 
                ? 'Update Attendance' 
                : 'Save Attendance';
            
            const message = formTitle.includes('Update') 
                ? 'Are you sure you want to update attendance for <?= date('F j, Y', strtotime($date)) ?>? After updating, attendance will be locked again.'
                : 'Are you sure you want to save attendance for <?= date('F j, Y', strtotime($date)) ?>? After saving, attendance will be locked and cannot be edited without admin approval.';
            
            Swal.fire({
                title: 'Confirm ' + formTitle,
                text: message,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, save it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading state
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                        submitBtn.disabled = true;
                    }
                    
                    this.submit();
                }
            });
        });
    }
    
    // Request Update Form validation with SweetAlert
    if (requestUpdateForm) {
        requestUpdateForm.addEventListener('submit', function(e) {
            const reason = document.getElementById('reason').value.trim();
            if (!reason) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Reason Required',
                    text: 'Please provide a reason for the update request.',
                    confirmButtonColor: '#2563eb'
                });
                return false;
            }
            
            e.preventDefault();
            Swal.fire({
                title: 'Submit Update Request?',
                text: 'This request will be sent to the administrator for approval.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, submit request!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });
    }
    
    // ==================== ATTENDANCE STATUS BADGE UPDATES ====================
    document.querySelectorAll('.attendance-select').forEach(select => {
        select.addEventListener('change', function() {
            const badge = this.nextElementSibling;
            if (!badge || !badge.classList.contains('status-badge')) return;
            
            const value = this.value;
            badge.className = 'status-badge';
            badge.textContent = value;
            
            switch(value) {
                case 'P': badge.classList.add('status-present'); break;
                case 'A': badge.classList.add('status-absent'); break;
                case 'S': badge.classList.add('status-suspended'); break;
                case 'R': badge.classList.add('status-remaining'); break;
            }
        });
    });
    
    // ==================== KEYBOARD SHORTCUTS ====================
    document.addEventListener('keydown', function(e) {
        // Ctrl + S to save attendance form
        if (e.ctrlKey && e.key === 's' && attendanceForm && !e.target.matches('select, input, textarea')) {
            e.preventDefault();
            const submitBtn = attendanceForm.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) submitBtn.click();
        }
        
        // Escape key to close modals and sidebar
        if (e.key === 'Escape') {
            // Close mobile sidebar
            if (window.innerWidth < 1025 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
            
            // Close request modal
            if (requestModal && requestModal.classList.contains('active')) {
                requestModal.classList.remove('active');
                document.body.style.overflow = '';
            }
            
            // Close profile menu
            if (profileMenu) {
                profileMenu.style.display = 'none';
            }
            
            // Close dropdowns
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
            // Session expired
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

    // Check session every minute
    setInterval(checkSessionTimeout, 60000);

    // Reset timer on user activity
    let activityTimer;
    document.addEventListener('mousemove', () => {
        clearTimeout(activityTimer);
        activityTimer = setTimeout(() => {
            // Reset session timer on activity
            sessionTime = 1800;
            warningShown = false;
        }, 10000);
    });
    
    // ==================== WINDOW RESIZE HANDLER ====================
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth >= 1025) {
                // Desktop view
                sidebar.classList.remove('active');
                if (sidebarOverlay) sidebarOverlay.classList.remove('active');
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
                // Mobile view - ensure sidebar is not collapsed
                sidebar.classList.remove('collapsed');
                if (mainContent) mainContent.classList.remove('expanded');
                if (toggleIcon) toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
                
                logoImg.style.width = '85px';
                logoImg.style.height = '85px';
                if (logoText) logoText.style.display = 'block';
            }
            
            // Close dropdowns on resize
            document.querySelectorAll('.dropdown').forEach(drop => {
                drop.classList.remove('open');
            });
        }, 250);
    });
    
    // ==================== INITIAL LOAD STATE ====================
    function setInitialState() {
        if (window.innerWidth < 1025) {
            sidebar.classList.remove('collapsed');
            if (mainContent) mainContent.classList.remove('expanded');
            if (toggleIcon) toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
        } else {
            if (sidebar.classList.contains('collapsed')) {
                logoImg.style.width = '70px';
                logoImg.style.height = '70px';
                if (logoText) logoText.style.display = 'none';
            } else {
                logoImg.style.width = '85px';
                logoImg.style.height = '85px';
                if (logoText) logoText.style.display = 'block';
            }
        }
    }
    setInitialState();
    
    // ==================== SWEETALERT MESSAGES ====================
    <?php if (isset($_SESSION['success_message'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?= addslashes($_SESSION['success_message']) ?>',
        timer: 3000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
    });
    <?php unset($_SESSION['success_message']); endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: '<?= addslashes($_SESSION['error_message']) ?>',
        timer: 3000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
    });
    <?php unset($_SESSION['error_message']); endif; ?>
    
});
</script>
<?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>
