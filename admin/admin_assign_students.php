<?php
// ===========================================
// ASSIGN STUDENTS - WITH PROPER AUTHENTICATION
// ===========================================
session_start();
ob_start();

// Include admin authentication
require_once 'admin_auth.php';
require_once __DIR__ . '/admin_notifications_ui.php';

// Require admin authentication
requireAdminAuth();

// Get admin info with updated data
$admin_info = getAdminInfo();
$admin_id = $admin_info['id'];
$admin_email = $admin_info['email'];
$admin_name = $admin_info['name'];
$admin_type = $admin_info['type'];

// Store in array for easy access in template
$admin_profile = [
    'first_name' => $admin_info['first_name'] ?? '',
    'last_name' => $admin_info['last_name'] ?? '',
    'email' => $admin_email,
    'admin_type' => $admin_type,
    'phone' => $admin_info['phone'] ?? '9898624729',
    'photo' => $admin_info['photo'] ?? ''
];
$admin_notifications_data = admin_notifications_prepare($conn, ['id' => (int)$admin_id], 12);

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

// Function to find teacher photo
function getTeacherPhoto($photo_name) {
    if (empty($photo_name)) {
        return null;
    }
    
    // Get just the filename
    $filename = basename($photo_name);
    
    // Your exact path from the error message
    $exact_path = '../teacher/teacher/uploads/' . $filename;
    
    // Check if file exists at the exact path
    if (file_exists($exact_path)) {
        return $exact_path;
    }
    
    // Try other possible paths
    $possible_paths = [
        '../teacher/uploads/' . $filename,
        'teacher/teacher/uploads/' . $filename,
        'teacher/uploads/' . $filename,
        '../uploads/teacher/' . $filename,
        '../' . $photo_name,
        $photo_name
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // Check if file exists in the document root
    $doc_root = $_SERVER['DOCUMENT_ROOT'] . '/ruchi_classes_form/teacher/teacher/uploads/' . $filename;
    if (file_exists($doc_root)) {
        return '/ruchi_classes_form/teacher/teacher/uploads/' . $filename;
    }
    
    return null;
}

// Function to find student photo
function getStudentPhoto($photo_name) {
    if (empty($photo_name)) {
        return null;
    }
    
    // Get just the filename
    $filename = basename($photo_name);
    
    // Correct path for student photos
    $possible_paths = [
        '../student/uploads/' . $filename,
        'student/uploads/' . $filename,
        '../uploads/student/' . $filename,
        '../' . $photo_name,
        $photo_name
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    return null;
}

// Function to check and auto-unassign students after 30 minutes
function checkAndAutoUnassignStudents($conn) {
    // Get all assignments older than 30 minutes
    $query = "SELECT ts.*, t.first_name as teacher_first, t.last_name as teacher_last,
              COALESCE(se.first_name, sh.first_name) as student_first,
              COALESCE(se.last_name, sh.last_name) as student_last
              FROM teacher_students ts
              LEFT JOIN teachers t ON ts.teacher_id = t.id
              LEFT JOIN student_english se ON ts.student_id = se.id AND ts.medium = 'English'
              LEFT JOIN student_hindi sh ON ts.student_id = sh.id AND ts.medium = 'Hindi'
              WHERE ts.assigned_date <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $unassigned_count = 0;
        while ($row = $result->fetch_assoc()) {
            // Delete the old assignment
            $delete_stmt = $conn->prepare("DELETE FROM teacher_students WHERE id = ?");
            if ($delete_stmt) {
                $delete_stmt->bind_param("i", $row['id']);
                if ($delete_stmt->execute()) {
                    $unassigned_count++;
                    // Log the auto-unassignment
                    if (function_exists('logAdminActivity')) {
                        logAdminActivity('AUTO_UNASSIGN', "Auto-unassigned student ID: {$row['student_id']} from teacher ID: {$row['teacher_id']} after 30 minutes");
                    }
                }
                $delete_stmt->close();
            }
        }
        
        if ($unassigned_count > 0) {
            $_SESSION['info_message'] = "$unassigned_count student(s) were automatically unassigned after 30 minutes.";
        }
    }
}

// Run auto-unassign check
checkAndAutoUnassignStudents($conn);

// Generate CSRF token
$csrf_token = generateAdminCSRFToken();

// ===========================================
// DATABASE CONNECTION
// ===========================================
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
}

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log assignment access
if (function_exists('logAdminActivity')) {
    logAdminActivity('STUDENT_ASSIGNMENT', 'Accessed student assignment management');
}

// ===========================================
// CREATE TABLES IF NOT EXISTS
// ===========================================

// Create teacher_classes table for class assignments
$create_teacher_classes_table = "
CREATE TABLE IF NOT EXISTS teacher_classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    class VARCHAR(5) NOT NULL,
    medium ENUM('English', 'Hindi') NOT NULL,
    assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_teacher_class (teacher_id, class, medium)
)";

if ($conn->query($create_teacher_classes_table) === FALSE) {
    error_log("Error creating teacher_classes table: " . $conn->error);
}

// Create teacher_students table if it doesn't exist
$create_table_sql = "
CREATE TABLE IF NOT EXISTS teacher_students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    student_id INT NOT NULL,
    medium ENUM('English', 'Hindi') NOT NULL,
    assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_assignment (teacher_id, student_id, medium)
)";

if ($conn->query($create_table_sql) === FALSE) {
    error_log("Error creating teacher_students table: " . $conn->error);
}

// ===========================================
// HANDLE FORM SUBMISSIONS
// ===========================================

// Track which section is active
$active_section = 'classes'; // Default to classes section
if (isset($_POST['active_section'])) {
    $active_section = $_POST['active_section'];
} elseif (isset($_GET['active_section'])) {
    $active_section = $_GET['active_section'];
}

// Handle teacher class assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_classes'])) {
    // Validate CSRF token
    if (!validateAdminCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = "Security token invalid or expired. Please refresh the page.";
        logAdminActivity('CSRF_FAILURE', 'Failed CSRF validation on class assignment');
    } else {
        $teacher_id = intval($_POST['teacher_id']);
        $selected_classes = $_POST['classes'] ?? [];
        
        // Validate teacher exists
        $stmt = $conn->prepare("SELECT id, first_name, last_name FROM teachers WHERE id = ?");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error_message'] = "Teacher not found!";
            logAdminActivity('TEACHER_NOT_FOUND', "Attempted to assign classes to non-existent teacher ID: $teacher_id");
        } else {
            $teacher_data = $result->fetch_assoc();
            
            // Remove existing class assignments for this teacher
            $delete_stmt = $conn->prepare("DELETE FROM teacher_classes WHERE teacher_id = ?");
            if ($delete_stmt) {
                $delete_stmt->bind_param("i", $teacher_id);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
            
            // Insert new class assignments
            $assigned_class_count = 0;
            foreach ($selected_classes as $class_key) {
                $parts = explode('_', $class_key);
                if (count($parts) == 2) {
                    $class = $parts[0];
                    $medium = $parts[1];
                    
                    $insert_stmt = $conn->prepare("INSERT INTO teacher_classes (teacher_id, class, medium) VALUES (?, ?, ?)");
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("iss", $teacher_id, $class, $medium);
                        if ($insert_stmt->execute()) {
                            $assigned_class_count++;
                            logAdminActivity('CLASS_ASSIGNED', "Assigned Class $class ($medium) to teacher ID: $teacher_id");
                        }
                        $insert_stmt->close();
                    }
                }
            }
            
            $_SESSION['success_message'] = "Successfully assigned $assigned_class_count classes to teacher " . htmlspecialchars($teacher_data['first_name'] . ' ' . $teacher_data['last_name']);
            logAdminActivity('CLASS_ASSIGNMENT_COMPLETE', "Assigned $assigned_class_count classes to teacher ID: $teacher_id");
        }
        $stmt->close();
        header("Location: admin_assign_students.php?teacher_id=" . $teacher_id . "&active_section=" . $active_section);
        exit();
    }
}

// Handle student assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_students'])) {
    // Validate CSRF token
    if (!validateAdminCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = "Security token invalid or expired. Please refresh the page.";
        logAdminActivity('CSRF_FAILURE', 'Failed CSRF validation on student assignment');
    } else {
        $teacher_id = intval($_POST['teacher_id']);
        $selected_students = $_POST['students'] ?? [];
        
        // Validate teacher exists
        $stmt = $conn->prepare("SELECT id, first_name, last_name FROM teachers WHERE id = ?");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $_SESSION['error_message'] = "Teacher not found!";
            logAdminActivity('TEACHER_NOT_FOUND', "Attempted to assign students to non-existent teacher ID: $teacher_id");
        } else {
            $teacher_data = $result->fetch_assoc();
            
            // First, remove all existing student assignments for this teacher
            $delete_stmt = $conn->prepare("DELETE FROM teacher_students WHERE teacher_id = ?");
            if ($delete_stmt) {
                $delete_stmt->bind_param("i", $teacher_id);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
            
            // Then insert new assignments
            $assigned_count = 0;
            foreach ($selected_students as $student_key) {
                $parts = explode('_', $student_key);
                if (count($parts) == 2) {
                    $student_id = intval($parts[0]);
                    $medium = $parts[1];
                    
                    // Validate student exists
                    if ($medium === 'English') {
                        $table = 'student_english';
                    } else {
                        $table = 'student_hindi';
                    }
                    
                    $check_stmt = $conn->prepare("SELECT id FROM $table WHERE id = ?");
                    if ($check_stmt) {
                        $check_stmt->bind_param("i", $student_id);
                        $check_stmt->execute();
                        $check_stmt->store_result();
                        
                        if ($check_stmt->num_rows > 0) {
                            $insert_stmt = $conn->prepare("INSERT INTO teacher_students (teacher_id, student_id, medium) VALUES (?, ?, ?)");
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("iis", $teacher_id, $student_id, $medium);
                                if ($insert_stmt->execute()) {
                                    $assigned_count++;
                                    logAdminActivity('STUDENT_ASSIGNED', "Assigned student ID: $student_id ($medium) to teacher ID: $teacher_id");
                                }
                                $insert_stmt->close();
                            }
                        }
                        $check_stmt->close();
                    }
                }
            }
            
            $_SESSION['success_message'] = "Successfully assigned $assigned_count students to teacher " . htmlspecialchars($teacher_data['first_name'] . ' ' . $teacher_data['last_name']);
            logAdminActivity('STUDENT_ASSIGNMENT_COMPLETE', "Assigned $assigned_count students to teacher ID: $teacher_id");
        }
        $stmt->close();
        header("Location: admin_assign_students.php?teacher_id=" . $teacher_id . "&active_section=" . $active_section);
        exit();
    }
}

// Handle remove assignment - FIXED delete button
if (isset($_GET['remove_assignment'])) {
    $assignment_id = intval($_GET['remove_assignment']);
    $teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
    $active_section = isset($_GET['active_section']) ? $_GET['active_section'] : 'students';
    
    $stmt = $conn->prepare("DELETE FROM teacher_students WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $assignment_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Assignment removed successfully!";
            logAdminActivity('ASSIGNMENT_REMOVED', "Removed student assignment ID: $assignment_id from teacher ID: $teacher_id");
        } else {
            $_SESSION['error_message'] = "Failed to remove assignment: " . $conn->error;
        }
        $stmt->close();
    }
    header("Location: admin_assign_students.php?teacher_id=" . $teacher_id . "&active_section=" . $active_section);
    exit();
}

// Handle remove class assignment - FIXED delete button
if (isset($_GET['remove_class'])) {
    $class_id = intval($_GET['remove_class']);
    $teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
    $active_section = isset($_GET['active_section']) ? $_GET['active_section'] : 'classes';
    
    $stmt = $conn->prepare("DELETE FROM teacher_classes WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $class_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Class assignment removed successfully!";
            logAdminActivity('CLASS_ASSIGNMENT_REMOVED', "Removed class assignment ID: $class_id from teacher ID: $teacher_id");
        } else {
            $_SESSION['error_message'] = "Failed to remove class assignment: " . $conn->error;
        }
        $stmt->close();
    }
    header("Location: admin_assign_students.php?teacher_id=" . $teacher_id . "&active_section=" . $active_section);
    exit();
}

// ===========================================
// FIXED: FETCH ALL TEACHERS (No status filter)
// ===========================================

// Get all teachers (without status filter)
$teachers = [];
// Simple query - get all teachers
$teacher_query = "SELECT id, first_name, last_name, email, subject, photo 
                  FROM teachers 
                  ORDER BY first_name ASC";
                  
$teacher_result = $conn->query($teacher_query);

if ($teacher_result) {
    while ($row = $teacher_result->fetch_assoc()) {
        $teachers[] = $row;
    }
} else {
    // If error, show error message
    $_SESSION['error_message'] = "Database error: " . $conn->error;
}

// Get teacher class assignments
$teacher_classes = [];
$class_query = "SELECT * FROM teacher_classes ORDER BY teacher_id, class";
$class_result = $conn->query($class_query);

if ($class_result && $class_result->num_rows > 0) {
    while ($row = $class_result->fetch_assoc()) {
        if (!isset($teacher_classes[$row['teacher_id']])) {
            $teacher_classes[$row['teacher_id']] = [];
        }
        $teacher_classes[$row['teacher_id']][] = $row;
    }
}

// Get all students from both tables
$all_students = [];

// Get English medium students
$english_query = "SELECT id, first_name, last_name, photo, class, 'English' as medium 
                  FROM student_english 
                  ORDER BY class ASC, first_name ASC";
$english_result = $conn->query($english_query);

if ($english_result && $english_result->num_rows > 0) {
    while ($row = $english_result->fetch_assoc()) {
        $all_students[] = $row;
    }
}

// Get Hindi medium students
$hindi_query = "SELECT id, first_name, last_name, photo, class, 'Hindi' as medium 
                FROM student_hindi 
                ORDER BY class ASC, first_name ASC";
$hindi_result = $conn->query($hindi_query);

if ($hindi_result && $hindi_result->num_rows > 0) {
    while ($row = $hindi_result->fetch_assoc()) {
        $all_students[] = $row;
    }
}

// Get assigned students
$assigned_students = [];
$assigned_query = "
    SELECT ts.*, 
           COALESCE(se.first_name, sh.first_name) as first_name,
           COALESCE(se.last_name, sh.last_name) as last_name,
           COALESCE(se.class, sh.class) as class,
           ts.assigned_date
    FROM teacher_students ts
    LEFT JOIN student_english se ON ts.student_id = se.id AND ts.medium = 'English'
    LEFT JOIN student_hindi sh ON ts.student_id = sh.id AND ts.medium = 'Hindi'
";
$assigned_result = $conn->query($assigned_query);

if ($assigned_result && $assigned_result->num_rows > 0) {
    while ($row = $assigned_result->fetch_assoc()) {
        $assigned_students[$row['student_id'] . '_' . $row['medium']] = [
            'teacher_id' => $row['teacher_id'],
            'assigned_date' => $row['assigned_date']
        ];
    }
}

// Get teacher's current class and student assignments for display
$teacher_class_assignments = [];
$teacher_assignments = [];

if (isset($_GET['teacher_id']) && !empty($_GET['teacher_id'])) {
    $teacher_id = intval($_GET['teacher_id']);
    
    // Get class assignments
    $class_assign_query = "SELECT * FROM teacher_classes WHERE teacher_id = ? ORDER BY class, medium";
    $stmt = $conn->prepare($class_assign_query);
    if ($stmt) {
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $teacher_class_assignments[] = $row;
        }
        $stmt->close();
    }
    
    // Get student assignments with time remaining
    $student_assign_query = "
        SELECT ts.*, 
               COALESCE(se.first_name, sh.first_name) as first_name,
               COALESCE(se.last_name, sh.last_name) as last_name,
               COALESCE(se.photo, sh.photo) as photo,
               COALESCE(se.class, sh.class) as class,
               ts.medium,
               ts.assigned_date,
               TIMESTAMPDIFF(MINUTE, ts.assigned_date, NOW()) as minutes_passed
        FROM teacher_students ts
        LEFT JOIN student_english se ON ts.student_id = se.id AND ts.medium = 'English'
        LEFT JOIN student_hindi sh ON ts.student_id = sh.id AND ts.medium = 'Hindi'
        WHERE ts.teacher_id = ?
        ORDER BY class ASC, first_name ASC
    ";
    $stmt = $conn->prepare($student_assign_query);
    if ($stmt) {
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Calculate time remaining (30 minutes total)
            $row['time_remaining'] = max(0, 30 - $row['minutes_passed']);
            $teacher_assignments[] = $row;
        }
        $stmt->close();
    }
    
    // Filter students based on teacher's assigned classes
    $filtered_students = [];
    if (!empty($teacher_class_assignments)) {
        foreach ($all_students as $student) {
            foreach ($teacher_class_assignments as $class_assignment) {
                if ($student['class'] == $class_assignment['class'] && 
                    $student['medium'] == $class_assignment['medium']) {
                    $filtered_students[] = $student;
                    break;
                }
            }
        }
    }
}

// Get active section from URL
$active_section = isset($_GET['active_section']) ? $_GET['active_section'] : 'classes';

// Get available classes for assignment (8-12)
$available_classes = ['8', '9', '10', '11', '12'];
$available_mediums = ['English', 'Hindi'];

// ===========================================
// HTML TEMPLATE WITH COMPLETE DESIGN
// ===========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Assign Students | Ruchi Classes</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
    /* ============================================
       ROOT VARIABLES
    ============================================ */
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
    
    /* ============================================
       RESET & BASE STYLES
    ============================================ */
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
    
    /* ============================================
       MAIN LAYOUT
    ============================================ */
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
    
    /* ============================================
       SIDEBAR
    ============================================ */
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
    
    .logo-container::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
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
    
    .logo-img:hover {
        transform: scale(1.05) rotate(3deg);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3);
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
        background: transparent;
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
    
    .nav-links a.active::before {
        content: '';
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 20px;
        background: var(--primary);
        border-radius: 3px 0 0 3px;
        box-shadow: -2px 0 8px rgba(39, 174, 96, 0.3);
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
    
    /* ============================================
       MAIN CONTENT
    ============================================ */
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
        font-family: 'Inter', sans-serif;
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
    
    .alert-info {
        background: linear-gradient(135deg, rgba(52, 152, 219, 0.1), rgba(52, 152, 219, 0.2));
        color: #2980b9;
        border-color: #85c1e9;
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
    
    /* Section Toggle */
    .section-toggle {
        display: flex;
        gap: 20px;
        margin-bottom: 30px;
        justify-content: center;
    }
    
    .section-toggle-btn {
        padding: 15px 40px;
        border: 2px solid #e0e6ed;
        border-radius: 50px;
        background: white;
        color: var(--secondary);
        font-weight: 700;
        font-size: 1.1rem;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: var(--card-shadow);
    }
    
    .section-toggle-btn:hover {
        border-color: var(--primary);
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }
    
    .section-toggle-btn.active {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border-color: var(--primary);
        box-shadow: 0 6px 15px rgba(39, 174, 96, 0.3);
    }
    
    .section-toggle-btn i {
        font-size: 1.2rem;
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
        scroll-margin-top: 20px;
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
    
    /* Teacher Selection Form */
    .teacher-selection-form {
        max-width: 600px;
        margin: 0 auto;
    }
    
    .form-group {
        margin-bottom: 25px;
    }
    
    .form-label {
        display: block;
        margin-bottom: 12px;
        font-weight: 700;
        color: var(--dark);
        font-size: 1rem;
    }
    
    .form-select {
        width: 100%;
        padding: 14px 18px;
        border: 2px solid #e0e6ed;
        border-radius: 10px;
        font-size: 1rem;
        transition: var(--transition);
        background: #f8fafc;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
    }
    
    .form-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
        background: white;
    }
    
    /* Teacher Profile */
    .teacher-profile-card {
        display: flex;
        align-items: center;
        gap: 25px;
        padding: 25px;
        background: linear-gradient(135deg, #f8fafc, #e5e7eb);
        border-radius: var(--radius);
        margin-bottom: 30px;
        border: 2px solid var(--primary-light);
    }
    
    .teacher-avatar {
        width: 100px;
        height: 100px;
        border-radius: 15px;
        overflow: hidden;
        border: 4px solid white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        flex-shrink: 0;
    }
    
    .teacher-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .avatar-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 2.5rem;
        font-weight: bold;
    }
    
    .teacher-info h3 {
        font-size: 1.5rem;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .teacher-info p {
        color: var(--secondary);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .teacher-info p strong {
        color: var(--dark);
        display: inline-block;
        min-width: 120px;
    }
    
    /* Time Remaining Badge */
    .time-remaining {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        margin-left: 10px;
    }
    
    .time-remaining.high {
        background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
        color: #1e8449;
        border: 2px solid #a3e4b9;
    }
    
    .time-remaining.medium {
        background: linear-gradient(135deg, #fef5e7, #fdebd0);
        color: #b9770e;
        border: 2px solid #f8c471;
    }
    
    .time-remaining.low {
        background: linear-gradient(135deg, #fadbd8, #f5b7b1);
        color: #c0392b;
        border: 2px solid #e6b0aa;
    }
    
    /* Class Selection Grid */
    .selection-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding: 20px;
        background: linear-gradient(135deg, #f8fafc, #e5e7eb);
        border-radius: 10px;
        border: 2px solid #e0e6ed;
    }
    
    .selection-info {
        font-size: 1.1rem;
        font-weight: 700;
    }
    
    .selection-count {
        font-size: 1.3rem;
        color: var(--primary);
    }
    
    .selection-buttons {
        display: flex;
        gap: 12px;
    }
    
    /* Buttons */
    .btn {
        padding: 12px 20px;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-family: 'Inter', sans-serif;
        position: relative;
        overflow: hidden;
        z-index: 1;
        text-decoration: none;
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
    
    .btn-secondary {
        background: linear-gradient(135deg, #f8fafc, #e5e7eb);
        color: var(--secondary);
        border: 2px solid #e0e6ed;
    }
    
    .btn-secondary:hover {
        background: #e5e7eb;
        transform: translateY(-3px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
    
    .btn-lg {
        padding: 16px 30px;
        font-size: 1.1rem;
    }
    
    .btn-block {
        display: block;
        width: 100%;
    }
    
    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    
    .btn:disabled:hover {
        transform: none;
    }
    
    .btn:disabled::before {
        display: none;
    }
    
    /* Class Grid */
    .class-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }
    
    .class-card {
        background: linear-gradient(135deg, white, #fdfdfd);
        border: 2px solid #e0e6ed;
        border-radius: var(--radius);
        padding: 25px;
        transition: var(--transition);
        cursor: pointer;
        position: relative;
        text-align: center;
    }
    
    .class-card:hover {
        border-color: var(--primary);
        transform: translateY(-5px) scale(1.02);
        box-shadow: var(--shadow-lg);
    }
    
    .class-card.selected {
        border-color: var(--success);
        background: linear-gradient(135deg, rgba(39, 174, 96, 0.05), rgba(39, 174, 96, 0.1));
    }
    
    .class-card.disabled {
        opacity: 0.6;
        cursor: not-allowed;
        background: #f8fafc;
    }
    
    .class-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary-light), #ebf5e6);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        color: var(--primary);
        font-size: 1.8rem;
    }
    
    .class-card h4 {
        font-size: 1.3rem;
        color: var(--dark);
        margin-bottom: 12px;
    }
    
    .class-medium {
        text-align: center;
        padding: 8px 20px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 700;
        display: inline-block;
        margin: 0 auto;
    }
    
    .medium-english {
        background: linear-gradient(135deg, rgba(52, 152, 219, 0.1), rgba(52, 152, 219, 0.2));
        color: #2980b9;
        border: 2px solid rgba(52, 152, 219, 0.3);
    }
    
    .medium-hindi {
        background: linear-gradient(135deg, rgba(243, 156, 18, 0.1), rgba(243, 156, 18, 0.2));
        color: #d68910;
        border: 2px solid rgba(243, 156, 18, 0.3);
    }
    
    .class-checkbox {
        position: absolute;
        top: 20px;
        right: 20px;
        width: 22px;
        height: 22px;
        cursor: pointer;
        accent-color: var(--primary);
    }
    
    /* Student Grid */
    .student-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }
    
    .student-card {
        background: linear-gradient(135deg, white, #fdfdfd);
        border: 2px solid #e0e6ed;
        border-radius: var(--radius);
        padding: 20px;
        transition: var(--transition);
        position: relative;
    }
    
    .student-card:hover {
        border-color: var(--primary);
        transform: translateY(-5px) scale(1.02);
        box-shadow: var(--shadow-lg);
    }
    
    .student-card.selected {
        border-color: var(--success);
        background: linear-gradient(135deg, rgba(39, 174, 96, 0.05), rgba(39, 174, 96, 0.1));
    }
    
    .student-card.disabled {
        opacity: 0.6;
        background: #f8fafc;
        cursor: not-allowed;
    }
    
    .student-info {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .student-avatar {
        width: 70px;
        height: 70px;
        border-radius: 12px;
        overflow: hidden;
        flex-shrink: 0;
        border: 3px solid white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .student-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .student-details h4 {
        font-size: 1.1rem;
        color: var(--dark);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .student-details p {
        color: var(--secondary);
        font-size: 0.9rem;
        margin-bottom: 8px;
    }
    
    .student-checkbox {
        position: absolute;
        top: 20px;
        right: 20px;
        width: 22px;
        height: 22px;
        cursor: pointer;
        accent-color: var(--primary);
    }
    
    /* Filters */
    .filters-container {
        display: flex;
        gap: 20px;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }
    
    .filter-group {
        flex: 1;
        min-width: 220px;
    }
    
    /* Assigned Items */
    .assigned-section {
        margin-top: 35px;
        padding-top: 25px;
        border-top: 3px solid #e0e6ed;
    }
    
    .assigned-section h3 {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--dark);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .assigned-list {
        margin-top: 20px;
    }
    
    .assigned-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        background: white;
        border: 2px solid #e0e6ed;
        border-radius: var(--radius);
        margin-bottom: 15px;
        transition: var(--transition);
    }
    
    .assigned-item:hover {
        border-color: var(--primary);
        transform: translateX(5px);
    }
    
    .item-info {
        display: flex;
        align-items: center;
        gap: 20px;
        flex: 1;
    }
    
    .item-badge {
        padding: 8px 20px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .badge-success {
        background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
        color: #1e8449;
        border: 2px solid #a3e4b9;
    }
    
    .badge-warning {
        background: linear-gradient(135deg, #fef5e7, #fdebd0);
        color: #b9770e;
        border: 2px solid #f8c471;
    }
    
    .badge-info {
        background: linear-gradient(135deg, #d4e6f1, #a9cce3);
        color: #2874a6;
        border: 2px solid #7fb3d5;
    }
    
    .item-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 50px 20px;
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
    
    /* ============================================
       RESPONSIVE DESIGN
    ============================================ */
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
        
        .header-left h1 {
            font-size: 1.4rem;
        }
        
        .class-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .student-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        body {
            font-size: 13px;
        }
        
        .main-content {
            padding: 15px;
        }
        
        .header {
            flex-direction: column;
            gap: 15px;
            margin-top: 60px;
            padding: 15px;
        }
        
        .header-left h1 {
            font-size: 1.3rem;
            justify-content: center;
            text-align: center;
            width: 100%;
            margin: 0;
        }
        
        .header-left h1 i {
            width: 40px;
            height: 40px;
            font-size: 1rem;
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
            padding: 10px;
        }
        
        .content-card {
            padding: 20px;
        }
        
        .teacher-profile-card {
            flex-direction: column;
            text-align: center;
            padding: 20px;
        }
        
        .teacher-info h3 {
            font-size: 1.3rem;
        }

        .teacher-info p {
            justify-content: center;
        }
        
        .class-grid {
            grid-template-columns: 1fr;
        }
        
        .student-grid {
            grid-template-columns: 1fr;
        }
        
        .selection-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .selection-buttons {
            width: 100%;
            justify-content: center;
        }
        
        .filters-container {
            flex-direction: column;
        }
        
        .filter-group {
            min-width: 100%;
        }
        
        .assigned-item {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .item-info {
            flex-direction: column;
            text-align: center;
        }
        
        .section-toggle {
            flex-direction: column;
            gap: 10px;
        }
        
        .section-toggle-btn {
            width: 100%;
            justify-content: center;
        }
    }
    
    @media (max-width: 480px) {
        body {
            font-size: 12px;
        }
        
        .main-content {
            padding: 10px;
        }
        
        .header {
            margin-top: 55px;
            padding: 12px;
        }
        
        .header-left h1 {
            font-size: 1.2rem;
        }
        
        .header-left h1 i {
            width: 35px;
            height: 35px;
            font-size: 0.9rem;
        }
        
        .btn {
            padding: 10px 15px;
            font-size: 0.9rem;
        }
        
        .btn-lg {
            padding: 12px 20px;
        }
        
        .empty-state i {
            font-size: 3rem;
        }
        
        .empty-state h3 {
            font-size: 1.1rem;
        }
        
        .profile-card {
            margin: 15px;
            padding: 15px;
        }
        
        .profile-avatar {
            width: 70px;
            height: 70px;
        }
        
        .profile-name {
            font-size: 1rem;
        }
    }
    
    @media (max-width: 360px) {
        .header-left h1 {
            font-size: 1.1rem;
        }
        
        .header-left h1 i {
            width: 30px;
            height: 30px;
            font-size: 0.8rem;
        }
        
        .user-quick-profile {
            padding: 8px;
        }
        
        .quick-profile-name {
            font-size: 0.85rem;
        }
        
        .quick-profile-role {
            font-size: 0.7rem;
        }
    }
    
    @media (min-width: 1200px) {
        .sidebar {
            width: 300px;
        }
        
        .main-content {
            margin-left: 300px;
        }
    }
    
    @media (min-width: 768px) and (max-width: 1199px) {
        .sidebar {
            width: 280px;
        }
        
        .main-content {
            margin-left: 280px;
        }
        
        .class-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .student-grid {
            grid-template-columns: repeat(2, 1fr);
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
    
    /* Smooth scrolling behavior */
    .sidebar {
        scroll-behavior: smooth;
    }
    
    html {
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
                    if (!empty($admin_profile['first_name']) && !empty($admin_profile['last_name'])) {
                        echo htmlspecialchars($admin_profile['first_name'] . ' ' . $admin_profile['last_name']);
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
                    <span class="meta-value"><?php echo count($teachers); ?></span>
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
                <li><a href="admin_assign_students.php" class="active"><i class="fas fa-users"></i> Assign Students</a></li>
                <li><a href="admin_complaints.php"><i class="fas fa-comment-dots"></i> Complaints</a></li>
                <li><a href="add_schedule.php"><i class="fas fa-calendar-alt"></i> Schedule</a></li>
                <li><a href="admin_assign_attendance.php"><i class="fa-solid fa-clipboard-check"></i> Assign Attendance</a></li>
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
                <h1><i class="fas fa-users"></i> Assign Students</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search teachers, students..." autocomplete="off">
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
                            <?php 
                                $initial = !empty($admin_profile['first_name']) 
                                    ? strtoupper(substr($admin_profile['first_name'], 0, 1))
                                    : 'A';
                            ?>
                            <span style="font-weight: 700; font-size: 1.2rem;"><?php echo $initial; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="quick-profile-info">
                        <div class="quick-profile-name">
                            <?php 
                                if (!empty($admin_profile['first_name'])) {
                                    echo htmlspecialchars($admin_profile['first_name']);
                                } else {
                                    echo 'Administrator';
                                }
                            ?>
                        </div>
                        <div class="quick-profile-role">
                            <?php 
                                if (isset($admin_profile['admin_type']) && $admin_profile['admin_type'] == 'first_admin') {
                                    echo 'Super Admin';
                                } else {
                                    echo 'Admin';
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Success/Error/Info Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php 
                echo $_SESSION['success_message']; 
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php 
                echo $_SESSION['error_message']; 
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <?php 
                echo $_SESSION['info_message']; 
                unset($_SESSION['info_message']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Teacher Selection -->
        <div class="content-card">
            <h2><i class="fas fa-chalkboard-teacher"></i> Step 1: Select Teacher</h2>
            
            <form method="get" class="teacher-selection-form" id="teacherSelectForm">
                <div class="form-group">
                    <label for="teacher_id" class="form-label">Choose Teacher to Manage</label>
                    <select name="teacher_id" id="teacher_id" class="form-select" required onchange="document.getElementById('teacherSelectForm').submit()">
                        <option value="">-- Select a Teacher --</option>
                        <?php if (!empty($teachers)): ?>
                            <?php foreach ($teachers as $teacher): 
                                $has_classes = isset($teacher_classes[$teacher['id']]) && !empty($teacher_classes[$teacher['id']]);
                                $teacher_name = trim($teacher['first_name'] . ' ' . ($teacher['last_name'] ?? ''));
                                if (empty($teacher_name)) {
                                    $teacher_name = $teacher['email'];
                                }
                            ?>
                                <option value="<?php echo $teacher['id']; ?>" 
                                    <?php if(isset($_GET['teacher_id']) && $_GET['teacher_id'] == $teacher['id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($teacher_name); ?> 
                                    - <?php echo htmlspecialchars($teacher['subject'] ?? 'No Subject'); ?>
                                    <?php if($has_classes): ?> (<?php echo count($teacher_classes[$teacher['id']]); ?> classes assigned)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No teachers found</option>
                        <?php endif; ?>
                    </select>
                </div>
                <input type="hidden" name="active_section" value="<?php echo $active_section; ?>">
            </form>
        </div>

        <?php if (isset($_GET['teacher_id']) && !empty($_GET['teacher_id'])): 
            $selected_teacher_id = $_GET['teacher_id'];
            $selected_teacher = null;
            foreach ($teachers as $teacher) {
                if ($teacher['id'] == $selected_teacher_id) {
                    $selected_teacher = $teacher;
                    break;
                }
            }
            
            if ($selected_teacher):
        ?>
        
        <!-- Teacher Profile with Fixed Photo Path -->
        <div class="teacher-profile-card">
            <div class="teacher-avatar">
                <?php 
                $teacher_photo_path = getTeacherPhoto($selected_teacher['photo'] ?? '');
                
                if ($teacher_photo_path): 
                ?>
                    <img src="<?php echo htmlspecialchars($teacher_photo_path); ?>" alt="Teacher Photo" 
                         onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<div class=\'avatar-placeholder\'>' + getTeacherInitials() + '</div>';">
                <?php else: ?>
                    <div class="avatar-placeholder" id="teacher-placeholder">
                        <?php 
                        $first_initial = !empty($selected_teacher['first_name']) ? strtoupper(substr($selected_teacher['first_name'], 0, 1)) : '';
                        $last_initial = !empty($selected_teacher['last_name']) ? strtoupper(substr($selected_teacher['last_name'], 0, 1)) : '';
                        $initials = $first_initial . $last_initial;
                        echo $initials ?: 'T';
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="teacher-info">
                <h3><?php echo htmlspecialchars($selected_teacher['first_name'] . ' ' . ($selected_teacher['last_name'] ?? '')); ?></h3>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($selected_teacher['email'] ?? 'N/A'); ?></p>
                <p><strong>Subject:</strong> <?php echo htmlspecialchars($selected_teacher['subject'] ?? 'Not Assigned'); ?></p>
                <p><strong>Assigned Classes:</strong> <?php echo count($teacher_class_assignments); ?></p>
                <p><strong>Assigned Students:</strong> <?php echo count($teacher_assignments ?? []); ?></p>
            </div>
        </div>

        <script>
        function getTeacherInitials() {
            <?php 
            $first_initial = !empty($selected_teacher['first_name']) ? strtoupper(substr($selected_teacher['first_name'], 0, 1)) : '';
            $last_initial = !empty($selected_teacher['last_name']) ? strtoupper(substr($selected_teacher['last_name'], 0, 1)) : '';
            $initials = $first_initial . $last_initial;
            ?>
            return '<?php echo $initials ?: 'T'; ?>';
        }
        </script>

        <!-- Section Toggle - Radio Buttons -->
        <div class="section-toggle">
            <button type="button" class="section-toggle-btn <?php echo $active_section == 'classes' ? 'active' : ''; ?>" onclick="toggleSection('classes')" id="classesToggle">
                <i class="fas fa-graduation-cap"></i> Assign Classes
            </button>
            <button type="button" class="section-toggle-btn <?php echo $active_section == 'students' ? 'active' : ''; ?>" onclick="toggleSection('students')" id="studentsToggle">
                <i class="fas fa-user-graduate"></i> Assign Students
            </button>
        </div>

        <!-- Class Assignment Section -->
        <div id="classesSection" class="content-card" style="display: <?php echo $active_section == 'classes' ? 'block' : 'none'; ?>;">
            <h2><i class="fas fa-graduation-cap"></i> Step 2: Assign Classes</h2>
            <p>Select classes (8-12) and medium for this teacher:</p>
            
            <form method="post" id="classAssignmentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="teacher_id" value="<?php echo $selected_teacher_id; ?>">
                <input type="hidden" name="active_section" value="classes">
                
                <div class="selection-header">
                    <div class="selection-info">
                        <i class="fas fa-layer-group"></i> Select Classes:
                        <span class="selection-count" id="selectedClassCount">0</span> selected
                    </div>
                    <div class="selection-buttons">
                        <button type="button" class="btn btn-primary" onclick="selectAllClasses()">
                            <i class="fas fa-check-double"></i> Select All
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="deselectAllClasses()">
                            <i class="fas fa-times"></i> Deselect
                        </button>
                    </div>
                </div>
                
                <div class="class-grid">
                    <?php foreach ($available_classes as $class): 
                        foreach ($available_mediums as $medium):
                            $class_key = $class . '_' . $medium;
                            $is_assigned = false;
                            foreach ($teacher_class_assignments as $assignment) {
                                if ($assignment['class'] == $class && $assignment['medium'] == $medium) {
                                    $is_assigned = true;
                                    break;
               
                                }
                            }
                    ?>
                    <div class="class-card <?php echo $is_assigned ? 'selected' : ''; ?>" 
                         data-class-key="<?php echo $class_key; ?>">
                        <div class="class-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <h4>Class <?php echo $class; ?></h4>
                        <div class="class-medium <?php echo $medium === 'English' ? 'medium-english' : 'medium-hindi'; ?>">
                            <?php echo $medium; ?> Medium
                        </div>
                        <input type="checkbox" 
                               name="classes[]" 
                               value="<?php echo $class_key; ?>" 
                               class="class-checkbox"
                               <?php echo $is_assigned ? 'checked' : ''; ?>>
                    </div>
                    <?php endforeach; endforeach; ?>
                </div>
                
                <button type="submit" name="assign_classes" class="btn btn-success btn-lg btn-block" style="margin-top: 20px;">
                    <i class="fas fa-save"></i> Save Class Assignments
                </button>
            </form>
            
            <!-- Current Class Assignments - FIXED delete buttons -->
            <?php if (!empty($teacher_class_assignments)): ?>
            <div class="assigned-section">
                <h3><i class="fas fa-list-check"></i> Your Assigned Classes (8-12)</h3>
                <div class="assigned-list">
                    <?php foreach ($teacher_class_assignments as $assignment): ?>
                    <div class="assigned-item">
                        <div class="item-info">
                            <div style="width: 50px; height: 50px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.5rem;">
                                <?php echo $assignment['class']; ?>
                            </div>
                            <div>
                                <strong>Class <?php echo htmlspecialchars($assignment['class']); ?></strong>
                                <div style="font-size: 0.9rem; color: var(--secondary); margin-top: 5px;">
                                    <?php echo htmlspecialchars($assignment['medium']); ?> Medium
                                </div>
                            </div>
                        </div>
                        <div class="item-actions">
                            <span class="item-badge badge-success">
                                <i class="fas fa-check-circle"></i> Assigned
                            </span>
                            <button type="button" class="btn btn-danger" onclick="confirmDelete('class', <?php echo $assignment['id']; ?>, <?php echo $selected_teacher_id; ?>, 'classes')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Student Assignment Section (Only if teacher has classes assigned) -->
        <div id="studentsSection" class="content-card" style="display: <?php echo $active_section == 'students' ? 'block' : 'none'; ?>;">
            <?php if (!empty($teacher_class_assignments)): ?>
                <?php if (!empty($filtered_students)): ?>
                <h2><i class="fas fa-user-graduate"></i> Step 3: Assign Students</h2>
                <p>Select students from the assigned classes:</p>
                
                <!-- Filters -->
                <div class="filters-container">
                    <div class="filter-group">
                        <label for="filterClass" class="form-label">Filter by Class</label>
                        <select id="filterClass" class="form-select" onchange="filterStudents()">
                            <option value="">All Classes</option>
                            <?php 
                            $unique_classes = array_unique(array_column($filtered_students, 'class'));
                            sort($unique_classes);
                            foreach ($unique_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>">Class <?php echo htmlspecialchars($class); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="filterMedium" class="form-label">Filter by Medium</label>
                        <select id="filterMedium" class="form-select" onchange="filterStudents()">
                            <option value="">All Mediums</option>
                            <option value="English">English Medium</option>
                            <option value="Hindi">Hindi Medium</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="searchName" class="form-label">Search by Name</label>
                        <input type="text" id="searchName" class="form-select" placeholder="Enter student name..." onkeyup="filterStudents()" autocomplete="off">
                    </div>
                </div>
                
                <form method="post" id="studentAssignmentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="teacher_id" value="<?php echo $selected_teacher_id; ?>">
                    <input type="hidden" name="active_section" value="students">
                    
                    <div class="selection-header">
                        <div class="selection-info">
                            <i class="fas fa-users"></i> Select Students:
                            <span class="selection-count" id="selectedStudentCount">0</span> selected
                        </div>
                        <div class="selection-buttons">
                            <button type="button" class="btn btn-primary" onclick="selectAllStudents()">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="deselectAllStudents()">
                                <i class="fas fa-times"></i> Deselect
                            </button>
                        </div>
                    </div>
                    
                    <div class="student-grid">
                        <?php foreach ($filtered_students as $student): 
                            $student_key = $student['id'] . '_' . $student['medium'];
                            $is_assigned = isset($assigned_students[$student_key]) && $assigned_students[$student_key]['teacher_id'] == $selected_teacher_id;
                            $is_assigned_to_other = isset($assigned_students[$student_key]) && $assigned_students[$student_key]['teacher_id'] != $selected_teacher_id;
                            
                            $student_name = strtolower(htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? '')));
                            
                            // Calculate time remaining if assigned
                            $time_remaining = 0;
                            $time_class = '';
                            if ($is_assigned && isset($assigned_students[$student_key]['assigned_date'])) {
                                $assigned_time = strtotime($assigned_students[$student_key]['assigned_date']);
                                $current_time = time();
                                $minutes_passed = floor(($current_time - $assigned_time) / 60);
                                $time_remaining = max(0, 30 - $minutes_passed);
                                
                                if ($time_remaining > 20) {
                                    $time_class = 'high';
                                } elseif ($time_remaining > 10) {
                                    $time_class = 'medium';
                                } else {
                                    $time_class = 'low';
                                }
                            }
                        ?>
                        <div class="student-card <?php echo $is_assigned ? 'selected' : ''; ?> <?php echo $is_assigned_to_other ? 'disabled' : ''; ?>" 
                             data-class="<?php echo htmlspecialchars($student['class']); ?>"
                             data-medium="<?php echo htmlspecialchars($student['medium']); ?>"
                             data-name="<?php echo $student_name; ?>"
                             data-student-key="<?php echo $student_key; ?>">
                            <div class="student-info">
                                <div class="student-avatar">
                                    <?php 
                                    $student_photo_path = getStudentPhoto($student['photo'] ?? '');
                                    
                                    if ($student_photo_path): 
                                    ?>
                                        <img src="<?php echo htmlspecialchars($student_photo_path); ?>" alt="Student"
                                             onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<div style=\'width:100%; height:100%; background: linear-gradient(135deg, <?php echo $student['medium'] === 'English' ? '#3498db' : '#f39c12'; ?>, #2c3e50); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.2rem;\'><?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'] ?? '', 0, 1)); ?></div>';">
                                    <?php else: ?>
                                        <div style="width:100%; height:100%; background: linear-gradient(135deg, <?php echo $student['medium'] === 'English' ? '#3498db' : '#f39c12'; ?>, #2c3e50); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.2rem;">
                                            <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'] ?? '', 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="student-details">
                                    <h4>
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? '')); ?>
                                        <?php if ($is_assigned && $time_remaining > 0): ?>
                                            <span class="time-remaining <?php echo $time_class; ?>">
                                                <i class="fas fa-clock"></i> <?php echo $time_remaining; ?>m left
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                    <p>Class <?php echo htmlspecialchars($student['class']); ?></p>
                                    <span class="class-medium <?php echo $student['medium'] === 'English' ? 'medium-english' : 'medium-hindi'; ?>">
                                        <?php echo htmlspecialchars($student['medium']); ?> Medium
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (!$is_assigned_to_other): ?>
                            <input type="checkbox" 
                                   name="students[]" 
                                   value="<?php echo $student_key; ?>" 
                                   class="student-checkbox"
                                   <?php echo $is_assigned ? 'checked' : ''; ?>>
                            <?php endif; ?>
                            
                            <?php if ($is_assigned_to_other): ?>
                            <div style="position: absolute; top: 10px; right: 10px; background: var(--warning); color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.7rem;">
                                Assigned to another teacher
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="submit" name="assign_students" class="btn btn-success btn-lg btn-block" style="margin-top: 20px;">
                        <i class="fas fa-save"></i> Save Student Assignments
                    </button>
                </form>
                
                <!-- Current Student Assignments - FIXED delete buttons -->
                <?php if (!empty($teacher_assignments)): ?>
                <div class="assigned-section">
                    <h3><i class="fas fa-list-check"></i> Current Student Assignments</h3>
                    <div class="assigned-list">
                        <?php foreach ($teacher_assignments as $assignment): 
                            $time_remaining = $assignment['time_remaining'];
                            $time_class = '';
                            if ($time_remaining > 20) {
                                $time_class = 'high';
                            } elseif ($time_remaining > 10) {
                                $time_class = 'medium';
                            } else {
                                $time_class = 'low';
                            }
                        ?>
                        <div class="assigned-item">
                            <div class="item-info">
                                <div class="student-avatar" style="width: 50px; height: 50px;">
                                    <?php 
                                    $assignment_photo_path = getStudentPhoto($assignment['photo'] ?? '');
                                    
                                    if ($assignment_photo_path): 
                                    ?>
                                        <img src="<?php echo htmlspecialchars($assignment_photo_path); ?>" alt="Student" style="width:100%; height:100%; object-fit:cover;"
                                             onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<div style=\'width:100%; height:100%; background: linear-gradient(135deg, <?php echo $assignment['medium'] === 'English' ? '#3498db' : '#f39c12'; ?>, #2c3e50); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;\'><?php echo strtoupper(substr($assignment['first_name'], 0, 1) . substr($assignment['last_name'] ?? '', 0, 1)); ?></div>';">
                                    <?php else: ?>
                                        <div style="width:100%; height:100%; background: linear-gradient(135deg, <?php echo $assignment['medium'] === 'English' ? '#3498db' : '#f39c12'; ?>, #2c3e50); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                            <?php echo strtoupper(substr($assignment['first_name'], 0, 1) . substr($assignment['last_name'] ?? '', 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($assignment['first_name'] . ' ' . ($assignment['last_name'] ?? '')); ?></strong>
                                    <div style="font-size: 0.9rem; color: var(--secondary); margin-top: 5px;">
                                        Class <?php echo htmlspecialchars($assignment['class']); ?> • 
                                        <?php echo htmlspecialchars($assignment['medium']); ?> Medium
                                    </div>
                                    <?php if ($time_remaining > 0): ?>
                                        <div class="time-remaining <?php echo $time_class; ?>" style="margin-top: 5px;">
                                            <i class="fas fa-hourglass-half"></i> Auto-unassign in <?php echo $time_remaining; ?> minutes
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="item-actions">
                                <span class="item-badge badge-success">
                                    <i class="fas fa-check-circle"></i> Assigned
                                </span>
                                <button type="button" class="btn btn-danger" onclick="confirmDelete('student', <?php echo $assignment['id']; ?>, <?php echo $selected_teacher_id; ?>, 'students')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>No Students Available</h3>
                    <p>No students found for the assigned classes. Please check if students are registered in these classes.</p>
                </div>
                <?php endif; ?>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>No Classes Assigned</h3>
                <p>This teacher doesn't have any class assignments. Please assign classes in Step 2 first.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <div class="content-card">
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Teacher Not Found</h3>
                <p>The selected teacher could not be found. Please select a valid teacher from the list.</p>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ===========================================
// FIXED: Scroll Position Preservation
// ===========================================
document.addEventListener('DOMContentLoaded', function() {
    
    // Save scroll position before form submission
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Save current scroll position to sessionStorage
            const scrollPosition = window.scrollY;
            sessionStorage.setItem('admin_scroll_position', scrollPosition);
            
            // Also save which section is active
            const activeSection = document.querySelector('.section-toggle-btn.active')?.getAttribute('onclick')?.match(/'([^']+)'/)?.[1] || 'classes';
            sessionStorage.setItem('admin_active_section', activeSection);
            
            // Save selected teacher if any
            const teacherSelect = document.getElementById('teacher_id');
            if (teacherSelect && teacherSelect.value) {
                sessionStorage.setItem('admin_selected_teacher', teacherSelect.value);
            }
        });
    });
    
    // Restore scroll position after page load
    const savedScrollPosition = sessionStorage.getItem('admin_scroll_position');
    const savedActiveSection = sessionStorage.getItem('admin_active_section');
    const savedTeacher = sessionStorage.getItem('admin_selected_teacher');
    
    if (savedScrollPosition) {
        // Use setTimeout to ensure DOM is fully rendered
        setTimeout(() => {
            window.scrollTo({
                top: parseInt(savedScrollPosition),
                behavior: 'smooth' // Optional: change to 'auto' for instant scroll
            });
            
            // Clear saved position after restoring
            sessionStorage.removeItem('admin_scroll_position');
        }, 100);
    }
    
    // Restore active section if needed
    if (savedActiveSection && savedActiveSection !== '<?php echo $active_section; ?>') {
        toggleSection(savedActiveSection);
    }
    
    // Restore teacher selection if needed
    if (savedTeacher && savedTeacher !== '<?php echo $_GET['teacher_id'] ?? ''; ?>') {
        const teacherSelect = document.getElementById('teacher_id');
        if (teacherSelect) {
            teacherSelect.value = savedTeacher;
            // Auto-submit if needed
            // teacherSelect.form.submit();
        }
        sessionStorage.removeItem('admin_selected_teacher');
    }
    
    // FIX: Handle class card clicks with scroll preservation
    document.querySelectorAll('.class-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.classList.contains('class-checkbox')) {
                return;
            }
            
            const checkbox = this.querySelector('.class-checkbox');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
                updateClassCount();
                
                // Save current scroll position
                sessionStorage.setItem('admin_scroll_position', window.scrollY);
            }
        });
    });
    
    // FIX: Handle student card clicks with scroll preservation
    document.querySelectorAll('.student-card:not(.disabled)').forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.classList.contains('student-checkbox')) {
                return;
            }
            
            const checkbox = this.querySelector('.student-checkbox');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('selected', checkbox.checked);
                updateStudentCount();
                
                // Save current scroll position
                sessionStorage.setItem('admin_scroll_position', window.scrollY);
            }
        });
    });
    
    // FIX: Handle checkbox changes with scroll preservation
    document.querySelectorAll('.class-checkbox, .student-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            sessionStorage.setItem('admin_scroll_position', window.scrollY);
        });
    });
    
    // FIX: Handle filter changes with scroll preservation
    const filterClass = document.getElementById('filterClass');
    const filterMedium = document.getElementById('filterMedium');
    const searchName = document.getElementById('searchName');
    
    if (filterClass) {
        filterClass.addEventListener('change', function() {
            sessionStorage.setItem('admin_scroll_position', window.scrollY);
        });
    }
    
    if (filterMedium) {
        filterMedium.addEventListener('change', function() {
            sessionStorage.setItem('admin_scroll_position', window.scrollY);
        });
    }
    
    if (searchName) {
        searchName.addEventListener('keyup', function() {
            sessionStorage.setItem('admin_scroll_position', window.scrollY);
        });
    }
    
    // FIX: Make class cards clickable
    document.querySelectorAll('.class-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't trigger if clicking on checkbox itself
            if (e.target.classList.contains('class-checkbox')) {
                return;
            }
            
            // Find the checkbox inside this card
            const checkbox = this.querySelector('.class-checkbox');
            if (checkbox) {
                // Toggle checkbox state
                checkbox.checked = !checkbox.checked;
                
                // Toggle selected class
                this.classList.toggle('selected', checkbox.checked);
                
                // Update count
                updateClassCount();
            }
        });
    });
    
    // FIX: Make student cards clickable
    document.querySelectorAll('.student-card:not(.disabled)').forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't trigger if clicking on checkbox itself
            if (e.target.classList.contains('student-checkbox')) {
                return;
            }
            
            // Find the checkbox inside this card
            const checkbox = this.querySelector('.student-checkbox');
            if (checkbox) {
                // Toggle checkbox state
                checkbox.checked = !checkbox.checked;
                
                // Toggle selected class
                this.classList.toggle('selected', checkbox.checked);
                
                // Update count
                updateStudentCount();
            }
        });
    });
    
    // FIX: Handle checkboxes separately
    document.querySelectorAll('.class-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const card = this.closest('.class-card');
            if (card) {
                card.classList.toggle('selected', this.checked);
            }
            updateClassCount();
        });
    });
    
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const card = this.closest('.student-card');
            if (card) {
                card.classList.toggle('selected', this.checked);
            }
            updateStudentCount();
        });
    });
    
    // Initialize counts
    updateClassCount();
    updateStudentCount();
    
    // Prevent page jump on form submission
    const forms2 = document.querySelectorAll('form');
    forms2.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Don't prevent default, but ensure we save scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
        });
    });
    
    // Restore scroll position after page load
    const scrollPos = sessionStorage.getItem('scrollPosition');
    if (scrollPos) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('scrollPosition');
    }
});

// Update class selection count
function updateClassCount() {
    const count = document.querySelectorAll('.class-checkbox:checked').length;
    const countElement = document.getElementById('selectedClassCount');
    if (countElement) countElement.textContent = count;
}

// Update student selection count
function updateStudentCount() {
    const count = document.querySelectorAll('.student-checkbox:checked').length;
    const countElement = document.getElementById('selectedStudentCount');
    if (countElement) countElement.textContent = count;
}

// Filter students by class, medium, and name
function filterStudents() {
    const classFilter = document.getElementById('filterClass')?.value || '';
    const mediumFilter = document.getElementById('filterMedium')?.value || '';
    const nameFilter = document.getElementById('searchName')?.value.toLowerCase() || '';
    
    document.querySelectorAll('.student-card').forEach(card => {
        const studentClass = card.getAttribute('data-class');
        const studentMedium = card.getAttribute('data-medium');
        const studentName = card.getAttribute('data-name') || '';
        
        let show = true;
        if (classFilter && studentClass !== classFilter) show = false;
        if (mediumFilter && studentMedium !== mediumFilter) show = false;
        if (nameFilter && !studentName.includes(nameFilter)) show = false;
        
        card.style.display = show ? 'block' : 'none';
    });
    
    // Save scroll position after filtering
    sessionStorage.setItem('admin_scroll_position', window.scrollY);
}

// Toggle between sections
function toggleSection(section) {
    // Update URL without page reload
    const url = new URL(window.location.href);
    url.searchParams.set('active_section', section);
    window.history.pushState({}, '', url);
    
    // Update button styles
    document.getElementById('classesToggle').classList.toggle('active', section === 'classes');
    document.getElementById('studentsToggle').classList.toggle('active', section === 'students');
    
    // Show/hide sections
    document.getElementById('classesSection').style.display = section === 'classes' ? 'block' : 'none';
    document.getElementById('studentsSection').style.display = section === 'students' ? 'block' : 'none';
    
    // Update active section in any forms
    document.querySelectorAll('input[name="active_section"]').forEach(input => {
        input.value = section;
    });
    
    // Save scroll position after toggling section
    sessionStorage.setItem('admin_scroll_position', window.scrollY);
}

// Class Selection Functions
function selectAllClasses() {
    document.querySelectorAll('.class-card').forEach(card => {
        const checkbox = card.querySelector('.class-checkbox');
        if (checkbox) {
            checkbox.checked = true;
            card.classList.add('selected');
        }
    });
    updateClassCount();
    sessionStorage.setItem('admin_scroll_position', window.scrollY);
}

function deselectAllClasses() {
    document.querySelectorAll('.class-card').forEach(card => {
        const checkbox = card.querySelector('.class-checkbox');
        if (checkbox) {
            checkbox.checked = false;
            card.classList.remove('selected');
        }
    });
    updateClassCount();
    sessionStorage.setItem('admin_scroll_position', window.scrollY);
}

// Student Selection Functions
function selectAllStudents() {
    document.querySelectorAll('.student-card:not(.disabled)').forEach(card => {
        const checkbox = card.querySelector('.student-checkbox');
        if (checkbox) {
            checkbox.checked = true;
            card.classList.add('selected');
        }
    });
    updateStudentCount();
    sessionStorage.setItem('admin_scroll_position', window.scrollY);
}

function deselectAllStudents() {
    document.querySelectorAll('.student-card:not(.disabled)').forEach(card => {
        const checkbox = card.querySelector('.student-checkbox');
        if (checkbox) {
            checkbox.checked = false;
            card.classList.remove('selected');
        }
    });
    updateStudentCount();
    sessionStorage.setItem('admin_scroll_position', window.scrollY);
}

// Confirm delete with SweetAlert
function confirmDelete(type, id, teacherId, section) {
    const message = type === 'class' 
        ? 'Are you sure you want to remove this class assignment?' 
        : 'Are you sure you want to remove this student assignment?';
    
    Swal.fire({
        title: 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#27ae60',
        confirmButtonText: 'Yes, remove it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Save current scroll position before redirect
            sessionStorage.setItem('admin_scroll_position', window.scrollY);
            
            // Redirect to delete URL
            const url = new URL(window.location.href);
            if (type === 'class') {
                url.searchParams.set('remove_class', id);
            } else {
                url.searchParams.set('remove_assignment', id);
            }
            url.searchParams.set('teacher_id', teacherId);
            url.searchParams.set('active_section', section);
            window.location.href = url.toString();
        }
    });
    
    return false;
}

// Form submission confirmation
const classForm = document.getElementById('classAssignmentForm');
if (classForm) {
    classForm.addEventListener('submit', function(e) {
        const checkboxes = document.querySelectorAll('input[name="classes[]"]:checked');
        if (checkboxes.length === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'No Classes Selected',
                text: 'Please select at least one class to assign.',
                confirmButtonColor: '#27ae60'
            });
        }
    });
}

const studentForm = document.getElementById('studentAssignmentForm');
if (studentForm) {
    studentForm.addEventListener('submit', function(e) {
        const checkboxes = document.querySelectorAll('#studentAssignmentForm input[name="students[]"]:checked');
        if (checkboxes.length === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'No Students Selected',
                text: 'Please select at least one student to assign.',
                confirmButtonColor: '#27ae60'
            });
        }
    });
}

// Mobile menu toggle
const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const sidebar = document.getElementById('sidebar');
const mobileOverlay = document.getElementById('mobileOverlay');

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
        
        const quickProfileName = document.querySelector('.quick-profile-name').textContent;
        const quickProfileRole = document.querySelector('.quick-profile-role').textContent;
        const adminEmail = '<?php echo htmlspecialchars($admin_profile['email'] ?? 'admin@ruchiclasses.com'); ?>';
        const adminPhone = '<?php echo htmlspecialchars($admin_profile['phone'] ?? 'Not set'); ?>';
        
        Swal.fire({
            title: 'Admin Profile',
            html: `
                <div style="text-align: left;">
                    <p><strong>Name:</strong> ${quickProfileName}</p>
                    <p><strong>Role:</strong> ${quickProfileRole}</p>
                    <p><strong>Email:</strong> ${adminEmail}</p>
                    <p><strong>Phone:</strong> ${adminPhone}</p>
                </div>
            `,
            icon: 'info',
            confirmButtonColor: '#27ae60',
            confirmButtonText: 'Close',
            background: '#ffffff',
            backdrop: true
        });
    });
}

// Search functionality for teacher dropdown
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        
        // Search in teacher dropdown
        const teacherSelect = document.getElementById('teacher_id');
        if (teacherSelect) {
            const options = teacherSelect.options;
            for (let i = 1; i < options.length; i++) {
                const optionText = options[i].text.toLowerCase();
                if (optionText.includes(searchTerm)) {
                    options[i].style.display = '';
                } else {
                    options[i].style.display = 'none';
                }
            }
        }
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

// Save and restore sidebar scroll position
window.addEventListener('beforeunload', function() {
    localStorage.setItem('sidebarScrollPosition', sidebar.scrollTop);
});

// Restore scroll position on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedScrollPosition = localStorage.getItem('sidebarScrollPosition');
    if (savedScrollPosition) {
        setTimeout(() => {
            sidebar.scrollTop = parseInt(savedScrollPosition);
            localStorage.removeItem('sidebarScrollPosition');
        }, 100);
    }
    
    // Smooth scroll to top on logo click
    const logoLink = document.querySelector('.logo');
    if (logoLink) {
        logoLink.addEventListener('click', function(e) {
            e.preventDefault();
            mainContent.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
});

// Auto-refresh time remaining every minute
setInterval(function() {
    // Reload the page to update time remaining
    if (document.getElementById('studentsSection').style.display === 'block') {
        location.reload();
    }
}, 60000); // Refresh every minute
</script>
</body>
</html>
<?php
// Close database connection
if (isset($conn)) {
    $conn->close();
}
ob_end_flush();
?>
