<?php
session_start();
require '../db.php';
require_once 'admin_auth.php';
require_once __DIR__ . '/admin_notifications_ui.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit();
}

// Get admin info
$admin_id = $_SESSION['admin_id'] ?? 1;

// Get admin details
$admin_profile = [];

// First try to get from session
if (isset($_SESSION['admin_name'])) {
    $name_parts = explode(' ', $_SESSION['admin_name']);
    $admin_profile['first_name'] = $name_parts[0] ?? 'Admin';
    $admin_profile['last_name'] = $name_parts[1] ?? '';
    $admin_profile['email'] = $_SESSION['admin_email'] ?? 'admin@ruchiclasses.com';
    $admin_profile['admin_type'] = $_SESSION['admin_type'] ?? 'admin';
    $admin_profile['photo'] = $_SESSION['admin_photo'] ?? '';
    $admin_profile['phone'] = $_SESSION['admin_phone'] ?? '9898624729';
} else {
    // Try database
    $stmt = $conn->prepare("SELECT id, name, email, first_name, last_name, phone, photo, admin_type FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $admin_data = $result->fetch_assoc();
        $admin_profile['first_name'] = $admin_data['first_name'] ?? explode(' ', $admin_data['name'] ?? 'Admin')[0] ?? 'Admin';
        $admin_profile['last_name'] = $admin_data['last_name'] ?? (isset(explode(' ', $admin_data['name'] ?? '')[1]) ? explode(' ', $admin_data['name'])[1] : '');
        $admin_profile['email'] = $admin_data['email'] ?? 'admin@ruchiclasses.com';
        $admin_profile['admin_type'] = $admin_data['admin_type'] ?? 'admin';
        $admin_profile['photo'] = $admin_data['photo'] ?? '';
        $admin_profile['phone'] = $admin_data['phone'] ?? '9898624729';
    } else {
        $admin_profile = [
            'first_name' => 'Admin',
            'last_name' => '',
            'email' => 'admin@ruchiclasses.com',
            'admin_type' => 'admin',
            'phone' => '9898624729',
            'photo' => ''
        ];
    }
}

// ==================== PHOTO PATH FUNCTIONS (DEFINED ONCE) ====================

// Function to get admin photo path
function getAdminPhotoPath($photo) {
    if (empty($photo)) {
        return '';
    }
    
    // First try: direct path from database
    $photo_path = '../' . $photo;
    if (file_exists($photo_path)) {
        return $photo_path;
    }
    
    // Second try: if photo is stored with admin_photos/
    $filename = basename($photo);
    $alt_path = 'uploads/admin_photos/' . $filename;
    if (file_exists($alt_path)) {
        return $alt_path;
    }
    
    return '';
}

$admin_notifications_data = admin_notifications_prepare($conn, ['id' => (int)$admin_id], 12);

function getTeacherPhotoPath($photo) {
    if (empty($photo)) {
        return '';
    }

    $photo = ltrim((string) $photo, '/');
    $possible_paths = [
        '../' . $photo,
        '../teacher/' . basename($photo),
        '../teacher/uploads/' . basename($photo),
        '../teacher/uploads/teacher_photos/' . basename($photo),
        'uploads/teacher_photos/' . basename($photo),
        'teacher/uploads/' . basename($photo),
        'teacher/uploads/teacher_photos/' . basename($photo),
    ];

    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return '';
}

function getAttendanceTaskSnapshot(mysqli $conn, int $taskId): array
{
    $stmt = $conn->prepare("
        SELECT teacher_id, class, medium, subject, task_date
        FROM attendance_tasks
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $taskId);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result ? ($result->fetch_assoc() ?? []) : [];
    $stmt->close();

    return $task;
}

function logAttendanceTaskAction(string $action, array $task): void
{
    if ($task === [] || !function_exists('logAdminActivity')) {
        return;
    }

    $details = "Class {$task['class']} ({$task['medium']}) - {$task['subject']} on {$task['task_date']} for teacher ID: {$task['teacher_id']}";
    logAdminActivity($action, $details);
}

// Check if attendance_tasks table exists, create if not with all required fields
$conn->query("
    CREATE TABLE IF NOT EXISTS attendance_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        class VARCHAR(10) NOT NULL,
        medium ENUM('English','Hindi') NOT NULL,
        subject VARCHAR(100) NOT NULL,
        task_date DATE NOT NULL,
        status ENUM('pending','completed','expired') DEFAULT 'pending',
        is_locked TINYINT DEFAULT 0,
        lock_reason TEXT,
        assigned_by INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        INDEX idx_teacher (teacher_id),
        INDEX idx_date (task_date),
        INDEX idx_status (status),
        UNIQUE KEY unique_task (teacher_id, class, medium, subject, task_date)
    )
");

// Check if attendance_update_requests table exists, create if not
$conn->query("
    CREATE TABLE IF NOT EXISTS attendance_update_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        class VARCHAR(10) NOT NULL,
        medium VARCHAR(20) NOT NULL,
        subject VARCHAR(50) NOT NULL,
        date DATE NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME DEFAULT NULL,
        reviewed_by INT DEFAULT NULL,
        admin_notes TEXT DEFAULT NULL,
        INDEX idx_teacher (teacher_id),
        INDEX idx_status (status),
        INDEX idx_date (date)
    )
");

// Check if attendance table exists with all fields
$conn->query("
    CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class VARCHAR(10) NOT NULL,
        medium VARCHAR(20) NOT NULL,
        subject VARCHAR(50) NOT NULL,
        date DATE NOT NULL,
        status ENUM('P','A','S','R') NOT NULL DEFAULT 'A',
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        teacher_id INT NOT NULL,
        is_locked TINYINT DEFAULT 0,
        INDEX idx_student (student_id),
        INDEX idx_teacher (teacher_id),
        INDEX idx_date (date),
        UNIQUE KEY unique_attendance (student_id, class, medium, subject, date)
    )
");

// Handle attendance assignment
if (isset($_POST['assign_attendance'])) {
    $teacher_id = intval($_POST['teacher_id']);
    $class = $_POST['class'];
    $medium = $_POST['medium'];
    $subject = trim($_POST['subject']);
    $date = $_POST['attendance_date'];

    // Validate inputs
    if (empty($teacher_id) || empty($class) || empty($medium) || empty($subject) || empty($date)) {
        $_SESSION['error_message'] = 'All fields are required!';
        header("Location: admin_assign_attendance.php");
        exit();
    }

    // Check if attendance already assigned for this date/class/subject
    $checkStmt = $conn->prepare("
        SELECT id FROM attendance_tasks 
        WHERE teacher_id = ? AND class = ? AND medium = ? AND subject = ? AND task_date = ?
    ");
    $checkStmt->bind_param("issss", $teacher_id, $class, $medium, $subject, $date);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->fetch_assoc();

    if (!$exists) {
        $stmt = $conn->prepare("
            INSERT INTO attendance_tasks (teacher_id, class, medium, subject, task_date, status, assigned_by)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->bind_param("issssi", $teacher_id, $class, $medium, $subject, $date, $admin_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Attendance task assigned successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to assign attendance task. Error: ' . $conn->error;
        }
    } else {
        $_SESSION['error_message'] = 'Attendance task already assigned for this date!';
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// Mark attendance task as completed
if (isset($_GET['complete'])) {
    $id = intval($_GET['complete']);
    $stmt = $conn->prepare("UPDATE attendance_tasks SET status='completed', completed_at=NOW() WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Attendance task marked as completed!';
    } else {
        $_SESSION['error_message'] = 'Failed to update attendance task. Please try again.';
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// Delete attendance task
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $taskSnapshot = getAttendanceTaskSnapshot($conn, $id);
    $stmt = $conn->prepare("DELETE FROM attendance_tasks WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Attendance task deleted!';
        logAttendanceTaskAction('ATTENDANCE_TASK_REMOVED', $taskSnapshot);
    } else {
        $_SESSION['error_message'] = 'Failed to delete task. Please try again.';
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// Lock attendance
if (isset($_GET['lock'])) {
    $id = intval($_GET['lock']);
    $taskSnapshot = getAttendanceTaskSnapshot($conn, $id);
    $stmt = $conn->prepare("UPDATE attendance_tasks SET is_locked=1, lock_reason='Locked by admin' WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Attendance locked! Teacher cannot edit now.';
        logAttendanceTaskAction('ATTENDANCE_TASK_LOCKED', $taskSnapshot);
    } else {
        $_SESSION['error_message'] = 'Failed to lock attendance. Please try again.';
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// Unlock attendance
if (isset($_GET['unlock'])) {
    $id = intval($_GET['unlock']);
    $taskSnapshot = getAttendanceTaskSnapshot($conn, $id);
    $stmt = $conn->prepare("UPDATE attendance_tasks SET is_locked=0, lock_reason=NULL WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Attendance unlocked! Teacher can edit now.';
        logAttendanceTaskAction('ATTENDANCE_TASK_UNLOCKED', $taskSnapshot);
    } else {
        $_SESSION['error_message'] = 'Failed to unlock attendance. Please try again.';
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// Approve update request
if (isset($_GET['approve_request'])) {
    $request_id = intval($_GET['approve_request']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get request details
        $stmt = $conn->prepare("SELECT * FROM attendance_update_requests WHERE id=?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        
        if ($request) {
            // Check if attendance_tasks record exists
            $checkTask = $conn->prepare("
                SELECT id FROM attendance_tasks 
                WHERE teacher_id = ? AND class = ? AND medium = ? AND subject = ? AND task_date = ?
            ");
            $checkTask->bind_param("issss", 
                $request['teacher_id'], 
                $request['class'], 
                $request['medium'], 
                $request['subject'], 
                $request['date']
            );
            $checkTask->execute();
            $taskExists = $checkTask->get_result()->fetch_assoc();
            
            if ($taskExists) {
                // Unlock the attendance task
                $unlockStmt = $conn->prepare("
                    UPDATE attendance_tasks 
                    SET is_locked = 0, 
                        lock_reason = 'Unlocked for approved update request', 
                        status = 'pending',
                        completed_at = NULL
                    WHERE teacher_id = ? AND class = ? AND medium = ? AND subject = ? AND task_date = ?
                ");
                $unlockStmt->bind_param("issss", 
                    $request['teacher_id'], 
                    $request['class'], 
                    $request['medium'], 
                    $request['subject'], 
                    $request['date']
                );
                $unlockStmt->execute();
            } else {
                // Create a new attendance task if it doesn't exist
                $createTask = $conn->prepare("
                    INSERT INTO attendance_tasks 
                    (teacher_id, class, medium, subject, task_date, status, is_locked, assigned_by)
                    VALUES (?, ?, ?, ?, ?, 'pending', 0, ?)
                ");
                $createTask->bind_param("issssi",
                    $request['teacher_id'],
                    $request['class'],
                    $request['medium'],
                    $request['subject'],
                    $request['date'],
                    $admin_id
                );
                $createTask->execute();
            }
            
            // Update request status
            $updateStmt = $conn->prepare("
                UPDATE attendance_update_requests 
                SET status = 'approved', 
                    reviewed_at = NOW(),
                    reviewed_by = ? 
                WHERE id = ?
            ");
            $updateStmt->bind_param("ii", $admin_id, $request_id);
            $updateStmt->execute();
            
            $conn->commit();
            $_SESSION['success_message'] = 'Update request approved! Teacher can now update attendance.';
        } else {
            $conn->rollback();
            $_SESSION['error_message'] = 'Update request not found.';
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = 'Failed to approve request: ' . $e->getMessage();
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// Reject update request
if (isset($_GET['reject_request'])) {
    $request_id = intval($_GET['reject_request']);
    $reason = $_GET['reason'] ?? 'Request rejected by admin';
    
    $stmt = $conn->prepare("
        UPDATE attendance_update_requests 
        SET status = 'rejected', 
            admin_notes = ?, 
            reviewed_at = NOW(),
            reviewed_by = ? 
        WHERE id = ?
    ");
    $stmt->bind_param("sii", $reason, $admin_id, $request_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Update request rejected!';
    } else {
        $_SESSION['error_message'] = 'Failed to reject request. Please try again.';
    }
    
    header("Location: admin_assign_attendance.php");
    exit();
}

// ==================== FIXED TEACHER FETCH ====================
$teacher_list = [];

// Check if teachers table exists
$table_check = $conn->query("SHOW TABLES LIKE 'teachers'");
if ($table_check && $table_check->num_rows > 0) {
    // Get all teachers with proper columns
    $teacher_query = "SELECT id, first_name, last_name, email, mobile, photo, subject, status FROM teachers";
    $teacher_result = $conn->query($teacher_query);
    
    if ($teacher_result && $teacher_result->num_rows > 0) {
        while ($row = $teacher_result->fetch_assoc()) {
            $teacher_list[] = [
                'id' => $row['id'],
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'email' => $row['email'] ?? '',
                'mobile' => $row['mobile'] ?? '',
                'photo' => $row['photo'] ?? '',
                'subject' => $row['subject'] ?? '',
                'status' => $row['status'] ?? 'inactive'
            ];
        }
    }
}

// If no teachers found with first query, try alternate column names
if (empty($teacher_list)) {
    $teacher_query = "SELECT id, 
                      COALESCE(first_name, name, '') as first_name, 
                      COALESCE(last_name, '') as last_name, 
                      email, 
                      COALESCE(mobile, '') as mobile, 
                      photo, 
                      subject, 
                      status 
                      FROM teachers";
    $teacher_result = $conn->query($teacher_query);
    
    if ($teacher_result && $teacher_result->num_rows > 0) {
        while ($row = $teacher_result->fetch_assoc()) {
            $teacher_list[] = [
                'id' => $row['id'],
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'email' => $row['email'] ?? '',
                'mobile' => $row['mobile'] ?? '',
                'photo' => $row['photo'] ?? '',
                'subject' => $row['subject'] ?? '',
                'status' => $row['status'] ?? 'inactive'
            ];
        }
    }
}

// ==================== FETCH ATTENDANCE TASKS ====================
$tasks = $conn->query("
    SELECT at.*, 
           t.first_name, 
           t.last_name,
           t.email,
           t.mobile,
           t.photo,
           t.subject as teacher_subject,
           (SELECT COUNT(*) FROM student_english se WHERE se.class = at.class) + 
           (SELECT COUNT(*) FROM student_hindi sh WHERE sh.class = at.class AND sh.medium = at.medium) as student_count,
           (SELECT COUNT(*) FROM attendance a WHERE a.class = at.class AND a.medium = at.medium AND a.subject = at.subject AND a.date = at.task_date) as attendance_count,
           (SELECT status FROM attendance_update_requests aur 
            WHERE aur.teacher_id = at.teacher_id 
            AND aur.class = at.class 
            AND aur.medium = at.medium 
            AND aur.subject = at.subject 
            AND aur.date = at.task_date 
            AND aur.status = 'pending' 
            ORDER BY aur.requested_at DESC LIMIT 1) as update_request_status
    FROM attendance_tasks at
    LEFT JOIN teachers t ON at.teacher_id = t.id
    ORDER BY at.task_date DESC, 
             CASE at.status 
                 WHEN 'pending' THEN 1 
                 WHEN 'completed' THEN 2 
                 WHEN 'expired' THEN 3 
             END,
             at.class ASC
");

// ==================== FETCH PENDING UPDATE REQUESTS ====================
$update_requests = $conn->query("
    SELECT aur.*, 
           t.first_name, 
           t.last_name,
           t.email,
           t.photo
    FROM attendance_update_requests aur
    LEFT JOIN teachers t ON aur.teacher_id = t.id
    WHERE aur.status = 'pending'
    ORDER BY aur.requested_at DESC
");

// ==================== GET STATISTICS ====================
$stats_query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM attendance_tasks WHERE status='pending') as total_pending,
        (SELECT COUNT(*) FROM attendance_tasks WHERE status='completed') as total_completed,
        (SELECT COUNT(*) FROM attendance_tasks WHERE is_locked=1) as total_locked,
        (SELECT COUNT(DISTINCT teacher_id) FROM attendance_tasks WHERE status='pending') as teachers_assigned,
        (SELECT COUNT(*) FROM attendance_update_requests WHERE status='pending') as pending_requests
");

if ($stats_query && $stats_query->num_rows > 0) {
    $stats = $stats_query->fetch_assoc();
} else {
    $stats = [
        'total_pending' => 0,
        'total_completed' => 0,
        'total_locked' => 0,
        'teachers_assigned' => 0,
        'pending_requests' => 0
    ];
}

// ==================== GET STUDENT COUNTS BY CLASS AND MEDIUM ====================
$student_counts = [];
for ($class = 8; $class <= 12; $class++) {
    $eng_count = $conn->query("SELECT COUNT(*) as count FROM student_english WHERE class = '$class'")->fetch_assoc()['count'] ?? 0;
    $hin_count = $conn->query("SELECT COUNT(*) as count FROM student_hindi WHERE class = '$class'")->fetch_assoc()['count'] ?? 0;
    
    // Store counts for this class
    $student_counts[$class] = [
        'total' => $eng_count + $hin_count,
        'english' => $eng_count,
        'hindi' => $hin_count
    ];
}

// Define subject arrays
$subjects_8_10 = ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
$subjects_11_12 = ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];

// Get current page for active navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Assign Attendance | Ruchi Classes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #27ae60;
            --primary-dark: #229954;
            --primary-light: #d5f4e6;
            --secondary: #7f8c8d;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --light: #f9fafb;
            --dark: #2c3e50;
            --sidebar-bg: #ffffff;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --transition: all 0.3s ease;
            --header-height: 80px;
            --mobile-header-height: 120px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e5e7eb 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            font-size: 14px;
            overflow-x: hidden;
        }
        
        /* Main Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
            position: relative;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1002;
            background: var(--primary);
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .mobile-menu-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
        }
        
        /* Sidebar */
        .sidebar {
            width: 300px;
            background: var(--sidebar-bg);
            color: var(--dark);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.08);
            z-index: 1001;
            border-right: 4px solid var(--primary);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .logo-container {
            padding: 25px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: white;
            text-decoration: none;
            position: relative;
            z-index: 1;
        }
        
        .logo-img {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            overflow: hidden;
            border: 3px solid white;
            background: white;
            padding: 8px;
            margin-bottom: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            transition: var(--transition);
        }
        
        .logo-img img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .logo-text h2 {
            font-size: 1.6rem;
            font-weight: 800;
            color: white;
            line-height: 1.2;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .logo-text span {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
            background: rgba(255, 255, 255, 0.1);
            padding: 6px 16px;
            border-radius: 20px;
            display: inline-block;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Profile Card in Sidebar */
        .profile-card {
            padding: 20px;
            background: white;
            margin: 20px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            border: 2px solid var(--primary-light);
            text-align: center;
            transition: var(--transition);
        }
        
        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }
        
        .profile-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 15px;
            border: 3px solid var(--primary);
            position: relative;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-avatar i {
            font-size: 2.5rem;
            color: var(--primary);
        }
        
        .profile-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .profile-email {
            font-size: 0.85rem;
            color: var(--secondary);
            margin-bottom: 8px;
            word-break: break-all;
        }
        
        .profile-role {
            display: inline-block;
            padding: 6px 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 12px;
            box-shadow: 0 3px 8px rgba(39, 174, 96, 0.3);
        }
        
        .profile-meta {
            display: flex;
            justify-content: space-around;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid var(--primary-light);
        }
        
        .meta-item {
            text-align: center;
        }
        
        .meta-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-dark);
            display: block;
        }
        
        .meta-label {
            font-size: 0.75rem;
            color: var(--secondary);
        }
        
        /* Navigation */
        .nav-section {
            padding: 20px 0;
        }
        
        .nav-section h3 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--secondary);
            margin-bottom: 15px;
            padding: 0 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-section h3::before {
            content: '';
            flex: 1;
            height: 2px;
            background: linear-gradient(to right, transparent, var(--primary-light));
        }
        
        .nav-section h3::after {
            content: '';
            flex: 1;
            height: 2px;
            background: linear-gradient(to left, transparent, var(--primary-light));
        }
        
        .nav-links {
            list-style: none;
        }
        
        .nav-links li {
            margin-bottom: 3px;
            position: relative;
        }
        
        .nav-links a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 25px;
            color: var(--dark);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            border-left: 4px solid transparent;
            position: relative;
            font-size: 0.95rem;
        }
        
        .nav-links a:hover {
            background: linear-gradient(90deg, rgba(39, 174, 96, 0.08), rgba(39, 174, 96, 0.04));
            color: var(--primary-dark);
            border-left-color: var(--primary);
            padding-left: 30px;
            transform: translateX(5px);
        }
        
        .nav-links a.active {
            background: linear-gradient(90deg, rgba(39, 174, 96, 0.15), rgba(39, 174, 96, 0.08));
            color: var(--primary-dark);
            border-left-color: var(--primary);
            font-weight: 600;
            box-shadow: inset 0 0 20px rgba(39, 174, 96, 0.05);
        }
        
        .nav-links a.active i {
            color: var(--primary);
        }
        
        .nav-links a i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
            color: var(--secondary);
            transition: var(--transition);
        }
        
        .nav-links a:hover i {
            color: var(--primary);
            transform: scale(1.1);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 300px;
            padding: 20px 30px;
            min-height: 100vh;
            overflow-y: auto;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: calc(100% - 300px);
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 25px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            border: 2px solid white;
            flex-wrap: wrap;
            gap: 15px;
            position: relative;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            width: 100%;
        }
        
        .header-left h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        
        .header-left h1 i {
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 1;
        }
        
        /* Search Bar */
        .search-bar {
            position: relative;
            flex: 1;
            min-width: 250px;
        }
        
        .search-bar input {
            padding: 12px 15px 12px 45px;
            border: 2px solid #e0e6ed;
            border-radius: 12px;
            width: 100%;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f8fafc;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
            background: white;
        }
        
        .search-bar i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            font-size: 1rem;
        }
        
        /* User Quick Profile */
        .user-quick-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 18px;
            background: white;
            border-radius: 12px;
            border: 2px solid #e0e6ed;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: var(--transition);
            min-width: fit-content;
            cursor: pointer;
        }
        
        .user-quick-profile:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }
        
        .quick-profile-img {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .quick-profile-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .quick-profile-info {
            line-height: 1.3;
        }
        
        .quick-profile-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 0.95rem;
        }
        
        .quick-profile-role {
            font-size: 0.75rem;
            color: var(--primary-dark);
            font-weight: 600;
            background: var(--primary-light);
            padding: 3px 10px;
            border-radius: 15px;
            display: inline-block;
        }
        
        /* Alert Styles */
        .alert {
            padding: 18px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(213, 244, 230, 0.95), rgba(200, 247, 217, 0.95));
            color: #1e8449;
            border-color: #a3e4b9;
        }
        
        .alert-error {
            background: linear-gradient(135deg, rgba(250, 219, 216, 0.95), rgba(245, 183, 177, 0.95));
            color: #c0392b;
            border-color: #e6b0aa;
        }
        
        .alert i {
            font-size: 1.4rem;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Content Card */
        .content-card {
            background: linear-gradient(135deg, white, #fdfdfd);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow-lg);
            border: 2px solid white;
            margin-bottom: 25px;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            width: 100%;
        }
        
        .content-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }
        
        .content-card h2 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 3px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .content-card h2 i {
            color: var(--primary);
        }
        
        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, white, #fdfdfd);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            border: 2px solid #e0e6ed;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .stat-card h3 {
            font-size: 1rem;
            color: var(--secondary);
            margin-bottom: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .count {
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1;
            margin-bottom: 10px;
        }
        
        .stat-card .subtext {
            font-size: 0.85rem;
            color: var(--secondary);
            margin-top: 10px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e6ed;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f8fafc;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
            background: white;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        /* Medium-specific student count styles */
        .medium-count-info {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e0e6ed;
        }
        
        .count-box {
            flex: 1;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }
        
        .count-box.english {
            background: rgba(52, 152, 219, 0.1);
            border-left: 4px solid #3498db;
        }
        
        .count-box.hindi {
            background: rgba(243, 156, 18, 0.1);
            border-left: 4px solid #f39c12;
        }
        
        .count-label {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .count-number {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .count-number.english {
            color: #2980b9;
        }
        
        .count-number.hindi {
            color: #d68910;
        }
        
        /* Button Styles */
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
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
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
            z-index: -1;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(39, 174, 96, 0.4);
            border-color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            box-shadow: 0 6px 15px rgba(231, 76, 60, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(231, 76, 60, 0.4);
            border-color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d68910);
            color: white;
            box-shadow: 0 6px 15px rgba(243, 156, 18, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(243, 156, 18, 0.4);
            border-color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #229954);
            color: white;
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(39, 174, 96, 0.4);
            border-color: white;
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--info), #2980b9);
            color: white;
            box-shadow: 0 6px 15px rgba(52, 152, 219, 0.3);
            border: 2px solid transparent;
        }
        
        .btn-info:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(52, 152, 219, 0.4);
            border-color: white;
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
        }
        
        .btn-lg {
            padding: 16px 30px;
            font-size: 1.1rem;
        }
        
        /* Table Styles - OPTIMIZED */
.table-container {
    background: white;
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    overflow-x: auto;
    border: 2px solid #e0e6ed;
    margin-top: 15px;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px; /* Reduced from 1200px */
    font-size: 13px; /* Slightly smaller font */
}

th {
    background: linear-gradient(to right, var(--primary-light), #ebf5e6);
    color: var(--dark);
    padding: 12px 10px; /* Reduced padding */
    text-align: left;
    font-weight: 600;
    border-bottom: 3px solid var(--primary);
    font-size: 0.85rem; /* Smaller font */
    white-space: nowrap;
}

td {
    padding: 12px 10px; /* Reduced padding */
    border-bottom: 1px solid #e0e6ed;
    vertical-align: middle;
}

/* Teacher Info - COMPACT */
.teacher-info {
    display: flex;
    align-items: center;
    gap: 10px; /* Reduced gap */
}

.teacher-avatar {
    width: 40px; /* Smaller avatar */
    height: 40px;
    border-radius: 8px; /* Smaller border radius */
    overflow: hidden;
    flex-shrink: 0;
    border: 2px solid white;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.teacher-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.teacher-details h4 {
    font-size: 0.9rem; /* Smaller font */
    color: var(--dark);
    margin-bottom: 2px;
    font-weight: 600;
}

.teacher-details p {
    color: var(--secondary);
    font-size: 0.7rem; /* Smaller font */
    line-height: 1.2;
}

/* Class & Medium - COMPACT */
.class-medium {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.class-number {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--dark);
}

.medium-badge {
    padding: 3px 8px; /* Smaller padding */
    border-radius: 15px;
    font-size: 0.7rem; /* Smaller font */
    font-weight: 600;
    display: inline-block;
    width: fit-content;
}

/* Subject & Date - COMPACT */
.subject-date {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.subject-name {
    font-weight: 500;
    color: var(--dark);
    font-size: 0.85rem;
}

.date-badge {
    background: linear-gradient(135deg, rgba(52, 152, 219, 0.1), rgba(155, 89, 182, 0.1));
    color: #2980b9;
    padding: 4px 8px; /* Smaller padding */
    border-radius: 6px; /* Smaller radius */
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px solid rgba(52, 152, 219, 0.2);
    font-size: 0.7rem;
    width: fit-content;
}

.date-badge i {
    font-size: 0.7rem;
}

/* Status Badges - COMPACT */
.status-badge {
    padding: 4px 10px; /* Smaller padding */
    border-radius: 15px;
    font-size: 0.7rem; /* Smaller font */
    font-weight: 600;
    text-transform: uppercase;
    display: inline-block;
    white-space: nowrap;
}

/* Attendance Count - COMPACT */
.attendance-count {
    background: var(--primary-light);
    padding: 5px 8px; /* Smaller padding */
    border-radius: 6px; /* Smaller radius */
    display: inline-block;
    text-align: center;
    font-size: 0.85rem;
    font-weight: 600;
    min-width: 60px;
}

.attendance-count .count-label {
    font-size: 0.6rem; /* Smaller font */
    color: var(--secondary);
    font-weight: normal;
    margin-top: 2px;
}

/* Action Buttons - COMPACT */
.action-buttons {
    display: flex;
    gap: 5px; /* Reduced gap */
    flex-wrap: wrap;
}

.btn-sm {
    padding: 5px 10px; /* Smaller padding */
    font-size: 0.7rem; /* Smaller font */
    border-radius: 6px; /* Smaller radius */
}

.btn-sm i {
    font-size: 0.7rem;
    margin-right: 3px;
}

/* Column Widths - OPTIMIZED */
th:nth-child(1) { width: 18%; } /* Teacher */
th:nth-child(2) { width: 12%; } /* Class & Medium */
th:nth-child(3) { width: 15%; } /* Subject & Date */
th:nth-child(4) { width: 10%; } /* Status */
th:nth-child(5) { width: 10%; } /* Lock Status */
th:nth-child(6) { width: 12%; } /* Update Request */
th:nth-child(7) { width: 10%; } /* Attendance Count */
th:nth-child(8) { width: 13%; } /* Actions */

/* Mobile Responsive */
@media (max-width: 1199px) {
    table {
        min-width: 800px; /* Smaller min-width for tablets */
    }
    
    th {
        padding: 10px 8px;
        font-size: 0.8rem;
    }
    
    td {
        padding: 10px 8px;
    }
}

@media (max-width: 768px) {
    .table-container {
        margin: 0 -10px; /* Negative margin for full width on mobile */
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    
    table {
        min-width: 700px; /* Even smaller for mobile */
    }
    
    .teacher-avatar {
        width: 35px;
        height: 35px;
    }
    
    .teacher-details h4 {
        font-size: 0.8rem;
    }
    
    .teacher-details p {
        font-size: 0.65rem;
    }
}
        
        /* Medium Badge */
        .medium-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .medium-english {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
            border: 2px solid rgba(52, 152, 219, 0.2);
        }
        
        .medium-hindi {
            background: rgba(243, 156, 18, 0.1);
            color: #d68910;
            border: 2px solid rgba(243, 156, 18, 0.2);
        }
        
        /* Date Badge */
        .date-badge {
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.1), rgba(155, 89, 182, 0.1));
            color: #2980b9;
            padding: 8px 15px;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 2px solid rgba(52, 152, 219, 0.2);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .lock-btn {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            box-shadow: 0 4px 8px rgba(220, 38, 38, 0.3);
        }
        
        .lock-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(220, 38, 38, 0.4);
        }
        
        .unlock-btn {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            box-shadow: 0 4px 8px rgba(5, 150, 105, 0.3);
        }
        
        .unlock-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(5, 150, 105, 0.4);
        }
        
        /* Request Badge */
        .request-badge {
            position: relative;
        }
        
        .request-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #f59e0b;
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
        
        .request-item {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 8px;
        }
        
        /* Custom Modal Styles */
        .profile-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }
        
        .profile-modal-content {
            background: white;
            border-radius: var(--radius);
            padding: 30px;
            max-width: 400px;
            width: 90%;
            box-shadow: var(--shadow-lg);
            border: 2px solid var(--primary);
            position: relative;
        }
        
        .profile-modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary);
            transition: var(--transition);
        }
        
        .profile-modal-close:hover {
            color: var(--danger);
            transform: scale(1.1);
        }
        
        /* Mobile Overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 1000;
            transition: opacity 0.3s ease;
        }
        
        .mobile-overlay.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #bdc3c7;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            color: var(--secondary);
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .empty-state p {
            color: var(--secondary);
            font-size: 1rem;
            max-width: 500px;
            margin: 0 auto;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: var(--secondary);
            font-size: 0.85rem;
            border-top: 1px solid #e0e6ed;
        }
        
        /* Responsive Design */
        @media (max-width: 1199px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px 15px;
            }
            
            .mobile-menu-toggle {
                display: flex;
            }
            
            .header {
                margin-top: 60px;
                padding: 15px 20px;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                margin-top: 60px;
                padding: 15px;
            }
            
            .header-right {
                flex-direction: column;
                width: 100%;
                gap: 15px;
            }
            
            .search-bar {
                min-width: 100%;
            }
            
            .user-quick-profile {
                width: 100%;
                justify-content: center;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .medium-count-info {
                flex-direction: column;
                gap: 10px;
            }
        }
        /* Teacher Avatar Placeholder */
.teacher-avatar-placeholder {
    background: linear-gradient(135deg, var(--primary-light), #d5f4e6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    font-size: 1.2rem;
}

.teacher-avatar-placeholder i {
    font-size: 1.5rem;
    color: var(--primary);
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
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="logo-container">
            <a href="admin_dashboard.php" class="logo">
                <div class="logo-img">
                    <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes">
                </div>
                <div class="logo-text">
                    <h2>RUCHI CLASSES</h2>
                    <span>Administration Portal</span>
                </div>
            </a>
        </div>
        
        <!-- Profile Card in Sidebar -->
        <div class="profile-card">
            <div class="profile-avatar">
                <?php 
                $admin_photo_path = getAdminPhotoPath($admin_profile['photo'] ?? '');
                if (!empty($admin_photo_path) && file_exists($admin_photo_path)): 
                ?>
                    <img src="<?php echo htmlspecialchars($admin_photo_path); ?>" alt="Profile Photo">
                <?php else: ?>
                    <i class="fas fa-user-shield"></i>
                <?php endif; ?>
            </div>
            <div class="profile-name">
                <?php 
                    if (!empty($admin_profile['first_name'])) {
                        echo htmlspecialchars($admin_profile['first_name'] . ' ' . ($admin_profile['last_name'] ?? ''));
                    } else {
                        echo 'Administrator';
                    }
                ?>
            </div>
            <div class="profile-email"><?php echo htmlspecialchars($admin_profile['email'] ?? 'admin@ruchiclasses.com'); ?></div>
            <div class="profile-role">
                <?php 
                    if (isset($admin_profile['admin_type']) && $admin_profile['admin_type'] == 'first_admin') {
                        echo 'Super Admin';
                    } else {
                        echo 'Administrator';
                    }
                ?>
            </div>
            <div class="profile-meta">
                <div class="meta-item">
                    <span class="meta-value"><?php echo $stats['teachers_assigned'] ?? 0; ?></span>
                    <span class="meta-label">Teachers</span>
                </div>
                <div class="meta-item">
                    <span class="meta-value"><?php echo date('d/m'); ?></span>
                    <span class="meta-label">Date</span>
                </div>
            </div>
        </div>
        
        <nav class="nav-section">
            <h3>Navigation Menu</h3>
            <ul class="nav-links">
                <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="admin_profile_edit.php"><i class="fas fa-user-edit"></i> Edit Profile</a></li>
                <li><a href="manage_teacher.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="admission_report.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="admin_assign_students.php"><i class="fas fa-users"></i> Assign Students</a></li>
                <li><a href="admin_complaints.php"><i class="fas fa-comment-dots"></i> Complaints</a></li>
                <li><a href="add_schedule.php"><i class="fas fa-calendar-alt"></i> Schedule</a></li>
                <li><a href="#" class="active"><i class="fa-solid fa-clipboard-check"></i> Assign Attendance</a></li>
                <li><a href="admin_videos.php"><i class="fas fa-video"></i> Videos</a></li>
            </ul>
        </nav>
        
        <nav class="nav-section">
            <h3>System Controls</h3>
            <ul class="nav-links">
                <li><a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="admin_logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <h1><i class="fa-solid fa-clipboard-check"></i> Assign & Manage Attendance</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search teachers, dates..." autocomplete="off">
                </div>

                <?php admin_notifications_render_widget($admin_notifications_data); ?>
                
                <div class="user-quick-profile" id="quickProfile">
                    <div class="quick-profile-img">
                        <?php 
                        $admin_photo_path = getAdminPhotoPath($admin_profile['photo'] ?? '');
                        if (!empty($admin_photo_path) && file_exists($admin_photo_path)): 
                        ?>
                            <img src="<?php echo htmlspecialchars($admin_photo_path); ?>" alt="Profile">
                        <?php else: ?>
                            <span style="font-weight: 700; font-size: 1.2rem;">
                                <?php echo strtoupper(substr($admin_profile['first_name'] ?? 'A', 0, 1)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="quick-profile-info">
                        <div class="quick-profile-name">
                            <?php echo htmlspecialchars($admin_profile['first_name'] ?? 'Admin'); ?>
                        </div>
                        <div class="quick-profile-role">
                            <?php echo ($admin_profile['admin_type'] == 'first_admin') ? 'Super Admin' : 'Admin'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> 
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card">
                <h3>Pending Tasks</h3>
                <div class="count pending"><?php echo $stats['total_pending'] ?? 0; ?></div>
                <div class="subtext">Attendance tasks pending</div>
            </div>
            
            <div class="stat-card">
                <h3>Completed Tasks</h3>
                <div class="count completed"><?php echo $stats['total_completed'] ?? 0; ?></div>
                <div class="subtext">Marked as completed</div>
            </div>
            
            <div class="stat-card">
                <h3>Locked Attendance</h3>
                <div class="count total"><?php echo $stats['total_locked'] ?? 0; ?></div>
                <div class="subtext">Cannot be edited</div>
            </div>
            
            <div class="stat-card">
                <h3>Update Requests</h3>
                <div class="count pending"><?php echo $stats['pending_requests'] ?? 0; ?></div>
                <div class="subtext">Pending approval</div>
            </div>
        </div>

        <!-- Pending Update Requests -->
        <?php if ($update_requests && $update_requests->num_rows > 0): ?>
        <div class="content-card">
            <h2><i class="fas fa-clock"></i> Pending Update Requests 
                <?php if (($stats['pending_requests'] ?? 0) > 0): ?>
                <span class="request-count"><?php echo $stats['pending_requests'] ?? 0; ?></span>
                <?php endif; ?>
            </h2>
            
            <div style="margin-bottom: 20px;">
                <?php 
                if ($update_requests) {
                    $update_requests->data_seek(0);
                    while($request = $update_requests->fetch_assoc()): 
                ?>
                <div class="request-item">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <div style="font-weight: 600; margin-bottom: 5px;">
                                <i class="fas fa-user"></i> 
                                <?php echo htmlspecialchars(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')); ?>
                            </div>
                            <div style="color: var(--secondary); font-size: 0.85rem; margin-bottom: 5px;">
                                Class <?php echo htmlspecialchars($request['class']); ?> | 
                                <?php echo htmlspecialchars($request['medium']); ?> | 
                                <?php echo htmlspecialchars($request['subject']); ?> | 
                                <?php echo date('d M Y', strtotime($request['date'])); ?>
                            </div>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 8px;">
                                <strong>Reason:</strong> <?php echo htmlspecialchars($request['reason']); ?>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <a href="?approve_request=<?php echo $request['id']; ?>" 
                               class="btn btn-success btn-sm"
                               onclick="return confirmApprove(event)">
                                <i class="fas fa-check"></i> Approve
                            </a>
                            <button type="button" 
                                    class="btn btn-danger btn-sm"
                                    onclick="rejectRequest(<?php echo $request['id']; ?>)">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                </div>
                <?php 
                    endwhile;
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assignment Form -->
        <div class="content-card">
            <h2><i class="fas fa-calendar-plus"></i> Create New Attendance Task</h2>
            
            <!-- Student Count Summary -->
            <div class="medium-count-info" id="countInfo" style="display: none;">
                <div class="count-box english">
                    <div class="count-label"><i class="fas fa-language"></i> English Medium</div>
                    <div class="count-number english" id="englishCount">0</div>
                    <div style="font-size: 0.8rem; color: #666;">students</div>
                </div>
                <div class="count-box hindi">
                    <div class="count-label"><i class="fas fa-language"></i> Hindi Medium</div>
                    <div class="count-number hindi" id="hindiCount">0</div>
                    <div style="font-size: 0.8rem; color: #666;">students</div>
                </div>
            </div>
            
            <form method="post" id="assignForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="teacher_id" class="form-label"><i class="fas fa-user-tie"></i> Select Teacher</label>
                        <select name="teacher_id" id="teacher_id" class="form-control" required>
                            <option value="">-- Choose Teacher --</option>
                            <?php
                            if (!empty($teacher_list)) {
                                foreach ($teacher_list as $t) {
                                    $display_name = htmlspecialchars(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')));
                                    $display_email = htmlspecialchars($t['email'] ?: $t['mobile'] ?: 'No email');
                                    echo '<option value="' . intval($t['id']) . '">' . $display_name . ' (' . $display_email . ')</option>';
                                }
                            } else {
                                echo '<option value="" disabled>No teachers found in the system</option>';
                            }
                            ?>
                        </select>
                        <?php if (empty($teacher_list)): ?>
                            <div style="color: #e74c3c; margin-top: 5px; font-size: 0.85rem;">
                                <i class="fas fa-exclamation-circle"></i> No teachers found. Please add teachers first.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="class" class="form-label"><i class="fas fa-graduation-cap"></i> Class</label>
                        <select name="class" id="class" class="form-control" required onchange="updateSubjectsAndCounts()">
                            <option value="">-- Select Class --</option>
                            <?php for($i = 8; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                        data-english="<?php echo $student_counts[$i]['english'] ?? 0; ?>"
                                        data-hindi="<?php echo $student_counts[$i]['hindi'] ?? 0; ?>">
                                    Class <?php echo $i; ?> 
                                    (E: <?php echo $student_counts[$i]['english'] ?? 0; ?> | H: <?php echo $student_counts[$i]['hindi'] ?? 0; ?>)
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="medium" class="form-label"><i class="fas fa-language"></i> Medium</label>
                        <select name="medium" id="medium" class="form-control" required onchange="updateSubjects()">
                            <option value="">-- Select Medium --</option>
                            <option value="English">English Medium</option>
                            <option value="Hindi">Hindi Medium</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="subject" class="form-label"><i class="fas fa-book"></i> Subject</label>
                        <select name="subject" id="subject" class="form-control" required>
                            <option value="">-- Select Subject --</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="attendance_date" class="form-label"><i class="fas fa-calendar-day"></i> Attendance Date</label>
                        <input type="date" name="attendance_date" id="attendance_date" class="form-control" required
                               value="<?php echo date('Y-m-d'); ?>"
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <button type="submit" name="assign_attendance" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-check"></i> Create Attendance Task
                </button>
            </form>
        </div>

        <!-- Attendance Tasks Table - OPTIMIZED HTML -->
<div class="content-card">
    <h2><i class="fas fa-list-check"></i> Attendance Tasks</h2>
    
    <?php if ($tasks && $tasks->num_rows > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Class & Medium</th>
                    <th>Subject & Date</th>
                    <th>Status</th>
                    <th>Lock</th>
                    <th>Request</th>
                    <th>Count</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ($tasks) {
                    $tasks->data_seek(0);
                    while($task = $tasks->fetch_assoc()): 
                ?>
                <tr>
                    <td>
    <div class="teacher-info">
        <?php 
        $photoPath = getTeacherPhotoPath($task['photo'] ?? '');
        ?>
        <?php if (!empty($photoPath) && file_exists($photoPath)): ?>
            <img src="<?php echo htmlspecialchars($photoPath); ?>" 
                 alt="Teacher"
                 class="teacher-avatar">
        <?php else: ?>
            <div class="teacher-avatar teacher-avatar-placeholder">
                <i class="fas fa-user-tie"></i>
            </div>
        <?php endif; ?>
        <div class="teacher-details">
            <h4><?php echo htmlspecialchars(($task['first_name'] ?? '') . ' ' . ($task['last_name'] ?? '')); ?></h4>
            <p><?php echo htmlspecialchars($task['email'] ?? 'No email'); ?></p>
        </div>
    </div>
</td>
                    <td>
                        <div class="class-medium">
                            <span class="class-number">Class <?php echo htmlspecialchars($task['class'] ?? ''); ?></span>
                            <span class="medium-badge <?php echo strtolower($task['medium'] ?? '') === 'english' ? 'medium-english' : 'medium-hindi'; ?>">
                                <?php echo htmlspecialchars($task['medium'] ?? ''); ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="subject-date">
                            <span class="subject-name"><?php echo htmlspecialchars($task['subject'] ?? ''); ?></span>
                            <span class="date-badge">
                                <i class="fas fa-calendar-day"></i>
                                <?php echo date('d M', strtotime($task['task_date'] ?? '')); ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo $task['status'] ?? 'pending'; ?>">
                            <?php echo ucfirst($task['status'] ?? 'pending'); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (($task['is_locked'] ?? 0) == 1): ?>
                            <span class="status-badge status-locked">
                                <i class="fas fa-lock"></i>
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-unlocked">
                                <i class="fas fa-unlock"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        if (!empty($task['update_request_status'])): 
                            $status_class = '';
                            $status_text = '';
                            
                            switch($task['update_request_status']) {
                                case 'pending':
                                    $status_class = 'status-pending';
                                    $status_text = 'Pending';
                                    break;
                                case 'approved':
                                    $status_class = 'status-completed';
                                    $status_text = 'Approved';
                                    break;
                                case 'rejected':
                                    $status_class = 'status-expired';
                                    $status_text = 'Rejected';
                                    break;
                            }
                        ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: var(--secondary); font-size: 0.7rem;">
                                No Request
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="attendance-count">
                            <?php echo $task['attendance_count'] ?? 0; ?>/<?php echo $task['student_count'] ?? 0; ?>
                            <div class="count-label">Students</div>
                        </div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <?php if(($task['status'] ?? '') == 'pending'): ?>
                                <a href="?complete=<?php echo $task['id']; ?>" 
                                   class="btn btn-success btn-sm"
                                   onclick="return confirmComplete(event)"
                                   title="Mark Complete">
                                    <i class="fas fa-check"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if(($task['is_locked'] ?? 0) == 0): ?>
                                <a href="?lock=<?php echo $task['id']; ?>" 
                                   class="btn lock-btn btn-sm"
                                   onclick="return confirmLock(event)"
                                   title="Lock">
                                    <i class="fas fa-lock"></i>
                                </a>
                            <?php else: ?>
                                <a href="?unlock=<?php echo $task['id']; ?>" 
                                   class="btn unlock-btn btn-sm"
                                   onclick="return confirmUnlock(event)"
                                   title="Unlock">
                                    <i class="fas fa-unlock"></i>
                                </a>
                            <?php endif; ?>
                            
                            <a href="?delete=<?php echo $task['id']; ?>" 
                               class="btn btn-warning btn-sm"
                               onclick="return confirmDelete(event)"
                               title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php 
                    endwhile;
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-clipboard-list"></i>
        <h3>No Attendance Tasks</h3>
        <p>Start by creating a new attendance task for teachers.</p>
    </div>
    <?php endif; ?>
</div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Ruchi Classes. All rights reserved.</p>
            <p style="margin-top: 5px; font-size: 0.75rem; color: var(--secondary);">
                <i class="fas fa-shield-alt"></i> Secure Admin Panel
            </p>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// Make sure DOM is fully loaded before running scripts
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Subject arrays
    const subjects_8_10 = ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
    const subjects_11_12 = ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];

    // Update subjects based on class selection
    window.updateSubjects = function() {
        const classSelect = document.getElementById('class');
        const mediumSelect = document.getElementById('medium');
        const subjectSelect = document.getElementById('subject');
        
        if (!classSelect || !subjectSelect) return;
        
        const classValue = parseInt(classSelect.value);
        const mediumValue = mediumSelect ? mediumSelect.value : '';
        
        if (!classValue) {
            subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
            return;
        }
        
        let subjects = [];
        if (classValue >= 8 && classValue <= 10) {
            subjects = subjects_8_10;
        } else if (classValue >= 11 && classValue <= 12) {
            subjects = subjects_11_12;
        }
        
        let options = '<option value="">-- Select Subject --</option>';
        subjects.forEach(subject => {
            options += `<option value="${subject}">${subject}</option>`;
        });
        
        subjectSelect.innerHTML = options;
    };

    // Update subjects and show student counts by medium
    window.updateSubjectsAndCounts = function() {
        const classSelect = document.getElementById('class');
        const selectedOption = classSelect.options[classSelect.selectedIndex];
        const countInfo = document.getElementById('countInfo');
        
        if (classSelect.value) {
            const englishCount = selectedOption.getAttribute('data-english') || '0';
            const hindiCount = selectedOption.getAttribute('data-hindi') || '0';
            
            document.getElementById('englishCount').textContent = englishCount;
            document.getElementById('hindiCount').textContent = hindiCount;
            
            countInfo.style.display = 'flex';
        } else {
            countInfo.style.display = 'none';
        }
        
        // Also update subjects
        updateSubjects();
    };

    // Mobile menu toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const mainContent = document.getElementById('mainContent');

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
            mobileOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : 'auto';
        });
    }

    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = 'auto';
        });
    }

    // Close sidebar when clicking on a link
    document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 1200) {
                sidebar.classList.remove('active');
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
    });

    // Quick profile click effect
    const quickProfile = document.getElementById('quickProfile');
    if (quickProfile) {
        quickProfile.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const quickProfileName = document.querySelector('.quick-profile-name')?.textContent || 'Admin';
            const quickProfileRole = document.querySelector('.quick-profile-role')?.textContent || 'Admin';
            const adminEmail = '<?php echo htmlspecialchars($admin_profile['email'] ?? 'admin@ruchiclasses.com'); ?>';
            const adminPhone = '<?php echo htmlspecialchars($admin_profile['phone'] ?? 'Not set'); ?>';
            
            // Create modal
            const modal = document.createElement('div');
            modal.className = 'profile-modal';
            modal.id = 'profileModal';
            
            modal.innerHTML = `
                <div class="profile-modal-content">
                    <button class="profile-modal-close" onclick="document.getElementById('profileModal').remove()">
                        <i class="fas fa-times"></i>
                    </button>
                    <div style="text-align: center; margin-bottom: 25px;">
                        <div style="
                            width: 80px;
                            height: 80px;
                            border-radius: 50%;
                            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                            margin: 0 auto 15px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            color: white;
                            font-size: 2rem;
                            border: 3px solid white;
                            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
                        ">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3 style="
                            font-size: 1.3rem;
                            font-weight: 700;
                            color: var(--dark);
                            margin-bottom: 8px;
                        ">${quickProfileName}</h3>
                        <div style="
                            display: inline-block;
                            padding: 6px 14px;
                            background: var(--primary-light);
                            color: var(--primary-dark);
                            border-radius: 20px;
                            font-size: 0.85rem;
                            font-weight: 600;
                            margin-bottom: 20px;
                        ">${quickProfileRole}</div>
                    </div>
                    <div style="
                        background: #f8fafc;
                        padding: 20px;
                        border-radius: 10px;
                        text-align: left;
                        border: 2px solid #e0e6ed;
                    ">
                        <div style="margin-bottom: 12px;">
                            <strong style="color: var(--dark);">Email:</strong>
                            <span style="color: var(--secondary); margin-left: 8px;">${adminEmail}</span>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong style="color: var(--dark);">Phone:</strong>
                            <span style="color: var(--secondary); margin-left: 8px;">${adminPhone}</span>
                        </div>
                        <div>
                            <strong style="color: var(--dark);">Account Type:</strong>
                            <span style="color: var(--secondary); margin-left: 8px;">${quickProfileRole}</span>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 25px;">
                        <button onclick="document.getElementById('profileModal').remove()" style="
                            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                            color: white;
                            border: none;
                            padding: 12px 30px;
                            border-radius: 8px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.3s ease;
                            font-family: 'Inter', sans-serif;
                            width: 100%;
                        " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 15px rgba(39, 174, 96, 0.4)'"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <i class="fas fa-times" style="margin-right: 8px;"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        });
    }

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1200) {
            sidebar.classList.remove('active');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    });

    // Initialize subjects on page load if class is selected
    const classSelect = document.getElementById('class');
    if (classSelect && classSelect.value) {
        updateSubjectsAndCounts();
    }
    
    // Set default date to today
    const dateInput = document.getElementById('attendance_date');
    if (dateInput) {
        dateInput.valueAsDate = new Date();
    }
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const tableRows = document.querySelectorAll('tbody tr');
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
    
    // Animate table rows on load
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            row.style.transition = 'all 0.5s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, 100 * index);
    });
    
    // Animate stat cards
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 100 * index);
    });
    
    // Smooth scroll to top on logo click
    const logoLink = document.querySelector('.logo');
    if (logoLink) {
        logoLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (mainContent) {
                mainContent.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            } else {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        });
    }
    
    // Save and restore sidebar scroll position
    window.addEventListener('beforeunload', function() {
        localStorage.setItem('sidebarScrollPosition', sidebar.scrollTop);
    });

    const savedScrollPosition = localStorage.getItem('sidebarScrollPosition');
    if (savedScrollPosition) {
        setTimeout(() => {
            sidebar.scrollTop = parseInt(savedScrollPosition);
            localStorage.removeItem('sidebarScrollPosition');
        }, 100);
    }
});

// Add smooth scrolling to main content
const mainContent = document.getElementById('mainContent');
if (mainContent) {
    mainContent.addEventListener('scroll', function() {
        const scrollTop = this.scrollTop;
        const header = document.querySelector('.header');
        
        if (scrollTop > 10) {
            header.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.1)';
            header.style.background = 'rgba(255, 255, 255, 0.98)';
        } else {
            header.style.boxShadow = 'var(--shadow-lg)';
            header.style.background = 'rgba(255, 255, 255, 0.95)';
        }
    });
}

// SweetAlert Confirmation dialogs
function confirmComplete(event) {
    if (event) event.preventDefault();
    const url = event.currentTarget.href;
    
    Swal.fire({
        title: 'Mark as Completed?',
        text: "This will mark the attendance task as completed.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, mark complete!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: '#f9fafb',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
    return false;
}

function confirmLock(event) {
    if (event) event.preventDefault();
    const url = event.currentTarget.href;
    
    Swal.fire({
        title: 'Lock Attendance?',
        text: "This will prevent the teacher from editing the attendance.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, lock it!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: '#f9fafb',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
    return false;
}

function confirmUnlock(event) {
    if (event) event.preventDefault();
    const url = event.currentTarget.href;
    
    Swal.fire({
        title: 'Unlock Attendance?',
        text: "This will allow the teacher to edit the attendance again.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, unlock it!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: '#f9fafb',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
    return false;
}

function confirmApprove(event) {
    if (event) event.preventDefault();
    const url = event.currentTarget.href;
    
    Swal.fire({
        title: 'Approve Update Request?',
        text: "This will unlock the attendance and allow teacher to update.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, approve!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: '#f9fafb',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
    return false;
}

function rejectRequest(requestId) {
    Swal.fire({
        title: 'Reject Update Request',
        input: 'text',
        inputLabel: 'Reason for rejection (optional)',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#27ae60',
        confirmButtonText: 'Reject',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: '#f9fafb',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            const reason = result.value || 'Request rejected by admin';
            window.location.href = `?reject_request=${requestId}&reason=${encodeURIComponent(reason)}`;
        }
    });
}

function confirmDelete(event) {
    if (event) event.preventDefault();
    const url = event.currentTarget.href;
    
    Swal.fire({
        title: 'Delete Attendance Task?',
        text: "This action cannot be undone. The attendance task will be permanently removed.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f39c12',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, delete!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: '#f9fafb',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
    return false;
}

// Form validation
document.getElementById('assignForm')?.addEventListener('submit', function(e) {
    const teacherId = document.getElementById('teacher_id').value;
    const classVal = document.getElementById('class').value;
    const medium = document.getElementById('medium').value;
    const subject = document.getElementById('subject').value;
    const date = document.getElementById('attendance_date').value;
    
    if (!teacherId || !classVal || !medium || !subject || !date) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Incomplete Form',
            text: 'Please fill in all fields before submitting.',
            confirmButtonColor: '#27ae60',
            background: '#f9fafb',
            backdrop: true,
            allowOutsideClick: false
        });
        return false;
    }
    
    // Check if date is in the future
    const selectedDate = new Date(date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (selectedDate < today) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Invalid Date',
            text: 'Attendance date cannot be in the past. Please select today or a future date.',
            confirmButtonColor: '#27ae60',
            background: '#f9fafb',
            backdrop: true,
            allowOutsideClick: false
        });
        return false;
    }
    
    return true;
});
</script>
</body>
</html>
