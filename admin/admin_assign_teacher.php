<?php
session_start();
require '../db.php';
require_once __DIR__ . '/admin_notifications_ui.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? 1;

// Get admin info
$admin = [];
$stmt = $conn->prepare("SELECT name, email FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc() ?? ['name' => 'Admin', 'email' => ''];
$admin_notifications_data = admin_notifications_prepare($conn, ['id' => (int)$admin_id], 12);

// Handle form submissions
if (isset($_POST['assign'])) {
    $teacher_id = $_POST['teacher_id'];
    $class = $_POST['class'];
    $medium = $_POST['medium'];
    $subject = trim($_POST['subject']);

    // Validate subject based on class
    if ($class >= 8 && $class <= 10) {
        $allowed_subjects = ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
        if (!in_array($subject, $allowed_subjects)) {
            $_SESSION['error_message'] = 'Invalid subject for this class!';
            header("Location: admin_assign_teacher.php");
            exit();
        }
    } elseif ($class >= 11 && $class <= 12) {
        $allowed_subjects = ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];
        if (!in_array($subject, $allowed_subjects)) {
            $_SESSION['error_message'] = 'Invalid subject for this class!';
            header("Location: admin_assign_teacher.php");
            exit();
        }
    }

    // Check if assignment already exists
    $checkStmt = $conn->prepare("SELECT id FROM teacher_assignments WHERE teacher_id=? AND class=? AND medium=? AND subject=?");
    $checkStmt->bind_param("isss", $teacher_id, $class, $medium, $subject);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->fetch_assoc();

    if (!$exists) {
        $stmt = $conn->prepare("
            INSERT INTO teacher_assignments (teacher_id, class, medium, subject, status, created_at)
            VALUES (?, ?, ?, ?, 'assigned', NOW())
        ");
        $stmt->bind_param("isss", $teacher_id, $class, $medium, $subject);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Teacher assigned successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to assign teacher. Please try again.';
        }
    } else {
        $_SESSION['error_message'] = 'This teacher is already assigned to this class/subject!';
    }
    
    header("Location: admin_assign_teacher.php");
    exit();
}

// Unassign teacher
if (isset($_GET['unassign'])) {
    $id = $_GET['unassign'];
    $stmt = $conn->prepare("UPDATE teacher_assignments SET status='unassigned', updated_at=NOW() WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Teacher unassigned successfully!';
    } else {
        $_SESSION['error_message'] = 'Failed to unassign teacher. Please try again.';
    }
    
    header("Location: admin_assign_teacher.php");
    exit();
}

// Reactivate assignment
if (isset($_GET['reactivate'])) {
    $id = $_GET['reactivate'];
    $stmt = $conn->prepare("UPDATE teacher_assignments SET status='assigned', updated_at=NOW() WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Assignment reactivated successfully!';
    } else {
        $_SESSION['error_message'] = 'Failed to reactivate assignment. Please try again.';
    }
    
    header("Location: admin_assign_teacher.php");
    exit();
}

// Delete assignment permanently
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Assignment deleted permanently!';
    } else {
        $_SESSION['error_message'] = 'Failed to delete assignment. Please try again.';
    }
    
    header("Location: admin_assign_teacher.php");
    exit();
}

// Fetch teachers with photo
$teachers = $conn->query("SELECT id, first_name, last_name, email, mobile, photo FROM teachers ORDER BY first_name");

// Fetch assignments with teacher details
$assignments = $conn->query("
    SELECT ta.*, 
           t.first_name, 
           t.last_name,
           t.email,
           t.mobile,
           t.photo,
           (SELECT COUNT(*) FROM student_english se WHERE se.class = ta.class AND se.medium = ta.medium) + 
           (SELECT COUNT(*) FROM student_hindi sh WHERE sh.class = ta.class AND sh.medium = ta.medium) as student_count
    FROM teacher_assignments ta
    JOIN teachers t ON ta.teacher_id = t.id
    ORDER BY ta.status DESC, ta.class ASC, ta.medium ASC, ta.subject ASC
");

// Get statistics
$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM teacher_assignments WHERE status='assigned') as total_assigned,
        (SELECT COUNT(*) FROM teacher_assignments WHERE status='unassigned') as total_unassigned,
        (SELECT COUNT(*) FROM teachers) as total_teachers,
        (SELECT COUNT(DISTINCT class) FROM teacher_assignments WHERE status='assigned') as classes_covered
")->fetch_assoc();

// Define subject arrays
$subjects_8_10 = ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
$subjects_11_12 = ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];

// Function to get correct photo path
function getTeacherPhotoPath($photo) {
    if (empty($photo)) {
        return '../assets/default_teacher.png';
    }

    // DB me already: teacher/uploads/filename
    if (strpos($photo, 'teacher/uploads/') === 0) {
        return '../' . $photo;
    }

    return '../assets/default_teacher.png';
}

// Get current page for active navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Assign Teachers | Ruchi Classes</title>
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
            --radius: 10px;
            --transition: all 0.3s ease;
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
        }
        
        /* Main Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1000;
            background: var(--primary);
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 8px;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
            border: none;
            cursor: pointer;
        }
        
        /* Sidebar - White Theme */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            color: var(--dark);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.08);
            z-index: 900;
            border-right: 4px solid var(--primary);
            transition: transform 0.3s ease;
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .logo-container {
            padding: 20px 15px;
            background: white;
            text-align: center;
            border-bottom: 1px solid #e0e6ed;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: var(--dark);
            text-decoration: none;
        }
        
        .logo-img {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            overflow: hidden;
            border: 3px solid var(--primary);
            background: white;
            padding: 6px;
            margin-bottom: 10px;
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.2);
            transition: var(--transition);
        }
        
        .logo-img:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
            border-color: var(--primary-dark);
        }
        
        .logo-img img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .logo-text h2 {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.2;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        
        .logo-text span {
            font-size: 0.8rem;
            color: var(--secondary);
            font-weight: 500;
            background: #f8fafc;
            padding: 4px 12px;
            border-radius: 15px;
            display: inline-block;
            border: 1px solid #e0e6ed;
        }
        
        /* Navigation */
        .nav-section {
            padding: 15px 0;
        }
        
        .nav-section h3 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--secondary);
            margin-bottom: 10px;
            padding: 0 20px;
            font-weight: 600;
        }
        
        .nav-links {
            list-style: none;
        }
        
        .nav-links li {
            margin-bottom: 2px;
        }
        
        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            border-left: 4px solid transparent;
            position: relative;
            font-size: 0.9rem;
        }
        
        .nav-links a:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
            border-left-color: var(--primary);
            padding-left: 25px;
        }
        
        .nav-links a.active {
            background: linear-gradient(90deg, rgba(39, 174, 96, 0.1), rgba(39, 174, 96, 0.05));
            color: var(--primary-dark);
            border-left-color: var(--primary);
            font-weight: 600;
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
            height: 18px;
            background: var(--primary);
            border-radius: 3px 0 0 3px;
        }
        
        .nav-links a i {
            font-size: 1.1rem;
            width: 22px;
            text-align: center;
            color: var(--secondary);
        }
        
        .nav-links a:hover i {
            color: var(--primary);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            max-height: 100vh;
            overflow-y: auto;
            transition: margin-left 0.3s ease;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            border: 1px solid #e0e6ed;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-left h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-left h1 i {
            color: white;
            background: var(--primary);
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 1;
        }
        
        /* Search Bar */
        .search-bar {
            position: relative;
            flex: 1;
            min-width: 200px;
        }
        
        .search-bar input {
            padding: 10px 15px 10px 40px;
            border: 2px solid #e0e6ed;
            border-radius: 8px;
            width: 100%;
            font-size: 0.9rem;
            transition: var(--transition);
            background: #f8fafc;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
            background: white;
        }
        
        .search-bar i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            font-size: 0.9rem;
        }
        
        /* User Profile */
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            background: white;
            border-radius: 8px;
            border: 2px solid #e0e6ed;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            min-width: fit-content;
        }
        
        .user-profile:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .profile-img {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 3px 8px rgba(39, 174, 96, 0.3);
        }
        
        .profile-info {
            line-height: 1.3;
        }
        
        .profile-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }
        
        .profile-role {
            font-size: 0.75rem;
            color: var(--secondary);
        }
        
        /* Alert Styles */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            border: 2px solid transparent;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
            color: #1e8449;
            border-color: #a3e4b9;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fadbd8, #f5b7b1);
            color: #c0392b;
            border-color: #e6b0aa;
        }
        
        .alert i {
            font-size: 1.2rem;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            border: 2px solid #e0e6ed;
        }
        
        .content-card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .content-card h2 i {
            color: var(--primary);
        }
        
        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
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
            background: var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .stat-card h3 {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .count {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .stat-card .subtext {
            font-size: 0.85rem;
            color: var(--secondary);
            margin-top: 10px;
        }
        
        .count.total {
            color: var(--primary);
        }
        
        .count.pending {
            color: var(--warning);
        }
        
        .count.resolved {
            color: var(--success);
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
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e6ed;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f8fafc;
            font-family: 'Inter', sans-serif;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
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
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.3);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(231, 76, 60, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d68910);
            color: white;
            box-shadow: 0 4px 8px rgba(243, 156, 18, 0.3);
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(243, 156, 18, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #229954);
            color: white;
            box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 2px solid #e0e6ed;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: linear-gradient(to right, var(--primary-light), #ebf5e6);
            color: var(--dark);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--primary);
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e0e6ed;
        }
        
        tr {
            transition: var(--transition);
        }
        
        tr:hover {
            background-color: #f8fafc;
        }
        
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-assigned {
            background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
            color: #1e8449;
            border: 2px solid #a3e4b9;
        }
        
        .status-unassigned {
            background: linear-gradient(135deg, #fef5e7, #fdebd0);
            color: #b9770e;
            border: 2px solid #f8c471;
        }
        
        /* Teacher Info */
        .teacher-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .teacher-avatar {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            overflow: hidden;
            flex-shrink: 0;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .teacher-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .teacher-details h4 {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 3px;
        }
        
        .teacher-details p {
            color: var(--secondary);
            font-size: 0.8rem;
        }
        
        /* Medium Badge */
        .medium-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .medium-english {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }
        
        .medium-hindi {
            background: rgba(243, 156, 18, 0.1);
            color: #d68910;
            border: 1px solid rgba(243, 156, 18, 0.3);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        /* Mobile Overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 800;
        }
        
        .mobile-overlay.active {
            display: block;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #e0e6ed;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--dark);
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
                padding: 15px;
            }
            
            .mobile-menu-toggle {
                display: flex;
            }
            
            .mobile-overlay {
                display: none;
            }
            
            .mobile-overlay.active {
                display: block;
            }
        }
        
        @media (max-width: 768px) {
            body {
                font-size: 13px;
            }
            
            .header {
                flex-direction: column;
                gap: 12px;
                padding: 15px;
            }
            
            .header-left h1 {
                font-size: 1.2rem;
                justify-content: center;
                text-align: center;
                width: 100%;
            }
            
            .header-right {
                flex-direction: column;
                width: 100%;
                gap: 10px;
            }
            
            .search-bar {
                min-width: 100%;
            }
            
            .user-profile {
                width: 100%;
                justify-content: center;
            }
            
            .content-card {
                padding: 15px;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
            
            th, td {
                padding: 10px 12px;
                font-size: 0.85rem;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }
            
            .teacher-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .teacher-avatar {
                width: 40px;
                height: 40px;
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
                padding: 12px;
            }
            
            .header-left h1 {
                font-size: 1.1rem;
            }
            
            .header-left h1 i {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-card .count {
                font-size: 2rem;
            }
            
            .btn {
                padding: 10px 15px;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
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
        
        <nav class="nav-section">
            <h3>Navigation Menu</h3>
            <ul class="nav-links">
                <li><a href="#" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage_teacher.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="admission_report.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="admin_assign_students.php"><i class="fas fa-users"></i> Assign Students</a></li>
                <li><a href="admin_complaints.php"><i class="fas fa-comment-dots"></i> Complaints</a></li>
                <li><a href="add_schedule.php"><i class="fas fa-calendar-alt"></i> Schedule</a></li>
                <li><a href="admin_assign_attendance.php"><i class="fa-solid fa-clipboard-check"></i> Assign Attendance</a></li>
            </ul>
        </nav>
        
        <nav class="nav-section">
            <h3>System Controls</h3>
            <ul class="nav-links">
                <li><a href="admin_settings.php" class="<?php echo $current_page == 'admin_settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="admin_logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <h1><i class="fas fa-tasks"></i> Assign Teachers to Classes</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search teachers, classes..." id="searchInput">
                </div>

                <?php admin_notifications_render_widget($admin_notifications_data); ?>
                
                <div class="user-profile">
                    <div class="profile-img">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($admin['name']); ?></div>
                        <div class="profile-role">Teacher Management</div>
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
                <h3>Total Teachers</h3>
                <div class="count total"><?php echo $stats['total_teachers'] ?? 0; ?></div>
                <div class="subtext">Registered in system</div>
            </div>
            
            <div class="stat-card">
                <h3>Active Assignments</h3>
                <div class="count resolved"><?php echo $stats['total_assigned'] ?? 0; ?></div>
                <div class="subtext">Currently assigned</div>
            </div>
            
            <div class="stat-card">
                <h3>Inactive Assignments</h3>
                <div class="count pending"><?php echo $stats['total_unassigned'] ?? 0; ?></div>
                <div class="subtext">Awaiting assignment</div>
            </div>
            
            <div class="stat-card">
                <h3>Classes Covered</h3>
                <div class="count total"><?php echo $stats['classes_covered'] ?? 0; ?></div>
                <div class="subtext">Different classes</div>
            </div>
        </div>

        <!-- Assignment Form -->
        <div class="content-card">
            <h2><i class="fas fa-user-plus"></i> Assign New Teacher</h2>
            <form method="post" id="assignForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="teacher_id"><i class="fas fa-user-tie"></i> Select Teacher</label>
                        <select name="teacher_id" id="teacher_id" class="form-control" required>
                            <option value="">-- Choose Teacher --</option>
                            <?php 
                            $teachers->data_seek(0); // Reset pointer
                            while($t = $teachers->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $t['id']; ?>">
                                    <?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?> 
                                    (<?php echo htmlspecialchars($t['email']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="class"><i class="fas fa-graduation-cap"></i> Class</label>
                        <select name="class" id="class" class="form-control" required onchange="updateSubjects()">
                            <option value="">-- Select Class --</option>
                            <?php for($i = 8; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>">Class <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="medium"><i class="fas fa-language"></i> Medium</label>
                        <select name="medium" id="medium" class="form-control" required onchange="updateSubjects()">
                            <option value="">-- Select Medium --</option>
                            <option value="English">English Medium</option>
                            <option value="Hindi">Hindi Medium</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="subject"><i class="fas fa-book"></i> Subject</label>
                        <select name="subject" id="subject" class="form-control" required>
                            <option value="">-- Select Subject --</option>
                            <!-- Subjects will be populated by JavaScript -->
                        </select>
                    </div>
                </div>
                
                <button type="submit" name="assign" class="btn btn-primary btn-block">
                    <i class="fas fa-check"></i> Assign Teacher
                </button>
            </form>
        </div>

        <!-- Assignments Table -->
        <div class="content-card">
            <h2><i class="fas fa-list"></i> All Assignments</h2>
            
            <?php if ($assignments->num_rows > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Class & Medium</th>
                            <th>Subject</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $assignments->data_seek(0); // Reset pointer
                        while($assignment = $assignments->fetch_assoc()): 
                        ?>
                        <tr>
                            <td>
                                <div class="teacher-info">
                                    <?php 
                                    $photoPath = getTeacherPhotoPath($assignment['photo']);
                                    ?>
                                    <img src="<?php echo htmlspecialchars($photoPath); ?>" 
                                         alt="Teacher Photo"
                                         class="teacher-avatar"
                                         onerror="this.onerror=null; this.src='../assets/default_teacher.png';">
                                    <div class="teacher-details">
                                        <h4><?php echo htmlspecialchars($assignment['first_name'] . ' ' . $assignment['last_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($assignment['email']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 1rem;">
                                    Class <?php echo htmlspecialchars($assignment['class']); ?>
                                </div>
                                <div class="medium-badge <?php echo strtolower($assignment['medium']) === 'english' ? 'medium-english' : 'medium-hindi'; ?>">
                                    <?php echo htmlspecialchars($assignment['medium']); ?> Medium
                                </div>
                            </td>
                            <td style="font-weight: 500; color: var(--dark);">
                                <?php echo htmlspecialchars($assignment['subject']); ?>
                            </td>
                            <td style="text-align: center; font-weight: 600;">
                                <div style="background: var(--primary-light); padding: 8px 12px; border-radius: 8px; display: inline-block;">
                                    <?php echo $assignment['student_count'] ?? 0; ?>
                                    <div style="font-size: 0.75rem; color: var(--secondary); font-weight: normal;">Students</div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $assignment['status']; ?>">
                                    <?php echo ucfirst($assignment['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if($assignment['status'] == 'assigned'): ?>
                                        <a href="?unassign=<?php echo $assignment['id']; ?>" 
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirmUnassign()">
                                            <i class="fas fa-times"></i> Unassign
                                        </a>
                                    <?php else: ?>
                                        <a href="?reactivate=<?php echo $assignment['id']; ?>" 
                                           class="btn btn-success btn-sm"
                                           onclick="return confirmReactivate()">
                                            <i class="fas fa-redo"></i> Reactivate
                                        </a>
                                    <?php endif; ?>
                                    <a href="?delete=<?php echo $assignment['id']; ?>" 
                                       class="btn btn-warning btn-sm"
                                       onclick="return confirmDelete()">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No Assignments Found</h3>
                <p>Start by assigning a teacher to a class and subject.</p>
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
// Subject arrays
const subjects_8_10 = ["Maths", "Science", "Hindi", "English", "Gujarati", "Social Science"];
const subjects_11_12 = ["Accounts", "Statistics", "Economics", "Secretarial Practice", "English", "Business Studies"];

// Update subjects based on class selection
function updateSubjects() {
    const classSelect = document.getElementById('class');
    const subjectSelect = document.getElementById('subject');
    const classValue = parseInt(classSelect.value);
    
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
}

// Initialize subjects on page load if class is selected
document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.getElementById('class');
    if (classSelect.value) {
        updateSubjects();
    }
    
    // Mobile menu toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');

    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        mobileOverlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : 'auto';
    });

    mobileOverlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
        document.body.style.overflow = 'auto';
    });

    // Close sidebar when clicking on a link
    document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 1200) {
                sidebar.classList.remove('active');
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
    });

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
    
    // Set active navigation based on current page
    const currentPage = window.location.pathname.split('/').pop();
    const navLinks = document.querySelectorAll('.nav-links a');
    
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        // Check if current page matches href or if we're on admin_assign_teacher.php
        if (href === currentPage || (currentPage === 'admin_assign_teacher.php' && href === 'admin_assign_teacher.php')) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
});

// Confirmation dialogs
function confirmUnassign() {
    return Swal.fire({
        title: 'Unassign Teacher?',
        text: "This will mark the assignment as inactive. The teacher won't be able to take attendance for this class.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, unassign!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        return result.isConfirmed;
    });
}

function confirmReactivate() {
    return Swal.fire({
        title: 'Reactivate Assignment?',
        text: "This will mark the assignment as active. The teacher will be able to take attendance again.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, reactivate!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        return result.isConfirmed;
    });
}

function confirmDelete() {
    return Swal.fire({
        title: 'Delete Assignment Permanently?',
        text: "This action cannot be undone. The assignment will be permanently removed from the system.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f39c12',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, delete!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        return result.isConfirmed;
    });
}

// Form validation
document.getElementById('assignForm').addEventListener('submit', function(e) {
    const teacherId = document.getElementById('teacher_id').value;
    const classVal = document.getElementById('class').value;
    const medium = document.getElementById('medium').value;
    const subject = document.getElementById('subject').value;
    
    if (!teacherId || !classVal || !medium || !subject) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Incomplete Form',
            text: 'Please fill in all fields before submitting.',
            confirmButtonColor: '#27ae60'
        });
        return false;
    }
    return true;
});
</script>
</body>
</html>
