<?php
session_start();
require '../db.php';
require_once __DIR__ . '/../includes/schedule_helper.php';
require_once __DIR__ . '/admin_notifications_ui.php';

// Only admin can access
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit();
}

// Get admin info
$admin_email = $_SESSION['admin_email'] ?? 'admin@ruchiclasses.com';
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_type = $_SESSION['admin_type'] ?? 'admin';
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

// Store in array for easy access in template
$admin_profile = [
    'first_name' => explode(' ', $admin_name)[0] ?? 'Admin',
    'last_name' => explode(' ', $admin_name)[1] ?? '',
    'email' => $admin_email,
    'admin_type' => $admin_type,
    'phone' => $_SESSION['admin_phone'] ?? '9898624729',
    'photo' => $_SESSION['admin_photo'] ?? ''
];

schedule_ensure_management_columns($conn);
$scheduleColumns = schedule_table_columns($conn);
$scheduleSupportsLifecycle = isset($scheduleColumns['schedule_type'], $scheduleColumns['expires_at']);
$admin_notifications_data = admin_notifications_prepare($conn, ['id' => $admin_id], 12);

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

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $class = trim((string)($_POST['class'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $teacher = trim((string)($_POST['teacher'] ?? ''));
    $day = trim((string)($_POST['day'] ?? ''));
    $start_time = trim((string)($_POST['start_time'] ?? ''));
    $end_time = trim((string)($_POST['end_time'] ?? ''));
    $medium = trim((string)($_POST['medium'] ?? ''));
    $schedule_type = schedule_normalize_type($_POST['schedule_type'] ?? 'permanent');
    $expires_at = $schedule_type === 'temporary' ? schedule_compute_expiry($day, $end_time) : null;

    if ($class === '' || $subject === '' || $day === '' || $start_time === '' || $end_time === '' || $medium === '') {
        $error_message = "Please fill in all required schedule fields.";
    } elseif ($start_time >= $end_time) {
        $error_message = "End time must be after start time.";
    } elseif ($schedule_type === 'temporary' && $expires_at === null) {
        $error_message = "Temporary schedule ke liye valid day aur end time required hai.";
    } else {
        if ($scheduleSupportsLifecycle) {
            $stmt = $conn->prepare("
                INSERT INTO schedule (class, subject, teacher, day, start_time, end_time, medium, schedule_type, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param('sssssssss', $class, $subject, $teacher, $day, $start_time, $end_time, $medium, $schedule_type, $expires_at);
                if ($stmt->execute()) {
                    $success_message = $schedule_type === 'temporary'
                        ? "Temporary schedule added successfully! It will auto-hide after " . schedule_expiry_label($expires_at) . '.'
                        : "Permanent schedule added successfully!";
                } else {
                    $error_message = "Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Error: " . $conn->error;
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO schedule (class, subject, teacher, day, start_time, end_time, medium)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param('sssssss', $class, $subject, $teacher, $day, $start_time, $end_time, $medium);
                if ($stmt->execute()) {
                    $success_message = "Schedule added successfully!";
                } else {
                    $error_message = "Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Error: " . $conn->error;
            }
        }
    }
}

// Fetch existing schedules for editing
$schedules = [];
if (schedule_table_exists($conn)) {
    $sql = "SELECT * FROM schedule ORDER BY FIELD(day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), start_time";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            if (schedule_is_visible($row)) {
                $schedules[] = $row;
            }
        }
    }
}

// Handle schedule deletion
if (isset($_GET['delete_id'])) {
    $delete_id = (int)($_GET['delete_id'] ?? 0);
    if ($delete_id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM schedule WHERE id = ?");
        if ($delete_stmt) {
            $delete_stmt->bind_param('i', $delete_id);
            if ($delete_stmt->execute()) {
                $success_message = "Schedule deleted successfully!";
                header("Location: add_schedule.php?success=1");
                exit();
            } else {
                $error_message = "Error deleting schedule: " . $delete_stmt->error;
            }
            $delete_stmt->close();
        } else {
            $error_message = "Error deleting schedule: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Class Schedule Management | Ruchi Classes</title>
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
        
        /* Mobile Menu Toggle - FIXED POSITIONING */
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
        
        /* Sidebar - FIXED Z-INDEX AND WIDTH */
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
        
        /* Main Content - FIXED MARGIN AND PADDING */
        .main-content {
            flex: 1;
            margin-left: 300px;
            padding: 20px 30px;
            min-height: 100vh;
            overflow-y: auto;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: calc(100% - 300px);
        }
        
        /* Header - FIXED POSITIONING */
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
        
        /* Tabs */
        .tabs {
            display: flex;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            border: 2px solid #e0e6ed;
            margin-bottom: 25px;
            width: 100%;
        }
        
        .tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 3px solid transparent;
            font-size: 0.95rem;
        }
        
        .tab:hover {
            background: #f8fafc;
        }
        
        .tab.active {
            background: var(--primary-light);
            color: var(--primary-dark);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
            width: 100%;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
            font-family: 'Inter', sans-serif;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
            background: white;
        }

        .form-help {
            display: block;
            margin-top: 8px;
            color: var(--secondary);
            font-size: 0.82rem;
            line-height: 1.5;
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
        
        /* Schedule List */
        .schedule-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .schedule-card {
            background: linear-gradient(135deg, white, #fdfdfd);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            border: 2px solid #e0e6ed;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .schedule-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }
        
        .schedule-card:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
        }
        
        .schedule-day {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .schedule-time {
            font-size: 0.9rem;
            color: var(--secondary);
            font-weight: 500;
            background: #f8fafc;
            padding: 4px 10px;
            border-radius: 15px;
        }
        
        .schedule-class {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
        }
        
        .schedule-subject {
            font-size: 1rem;
            color: var(--secondary);
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .schedule-teacher {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 12px;
        }
        
        .schedule-teacher i {
            color: var(--primary);
        }
        
        .schedule-medium {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 5px;
        }

        .schedule-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 10px 0 2px;
        }

        .schedule-type {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .schedule-type-permanent {
            background: rgba(39, 174, 96, 0.12);
            color: #1e8449;
            border: 2px solid rgba(39, 174, 96, 0.18);
        }

        .schedule-type-temporary {
            background: rgba(243, 156, 18, 0.12);
            color: #af6e08;
            border: 2px solid rgba(243, 156, 18, 0.18);
        }

        .schedule-expiry {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: var(--secondary);
            font-size: 0.82rem;
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
        
        .schedule-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .schedule-actions .btn {
            flex: 1;
            justify-content: center;
        }
        
        /* Timetable Styles */
        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            border: 2px solid #e0e6ed;
        }
        
        .timetable {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .timetable th {
            background: linear-gradient(135deg, var(--primary-light), #ebf5e6);
            color: var(--dark);
            padding: 15px;
            text-align: center;
            font-weight: 600;
            border-bottom: 3px solid var(--primary);
            font-size: 0.95rem;
        }
        
        .timetable td {
            padding: 12px;
            text-align: center;
            border: 1px solid #e0e6ed;
            vertical-align: middle;
        }
        
        .timetable tr:hover td {
            background-color: #f8fafc;
        }
        
        .time-slot {
            font-weight: 600;
            color: var(--primary);
            background: #f8fafc;
            font-size: 0.9rem;
        }
        
        .class-slot {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.05), rgba(39, 174, 96, 0.1));
            border: 2px solid rgba(39, 174, 96, 0.2);
            border-radius: 8px;
            padding: 10px;
            font-size: 0.85rem;
        }
        
        .class-slot .class-name {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 0.9rem;
            margin-bottom: 3px;
        }
        
        .class-slot .subject-name {
            color: var(--dark);
            font-size: 0.85rem;
            margin-bottom: 3px;
        }
        
        .class-slot .teacher-name {
            color: var(--secondary);
            font-size: 0.8rem;
            margin-bottom: 3px;
        }
        
        .class-slot .medium-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            display: inline-block;
            background: white;
            border: 1px solid rgba(39, 174, 96, 0.3);
            color: var(--primary-dark);
        }
        
        .free-slot {
            color: var(--secondary);
            font-style: italic;
            font-size: 0.85rem;
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
        
        /* Footer */
        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: var(--secondary);
            font-size: 0.85rem;
            border-top: 1px solid #e0e6ed;
        }
        
        /* Responsive Design - FIXED MOBILE ISSUES */
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
            
            /* Adjust header for mobile */
            .header {
                margin-top: 60px; /* Space for mobile menu button */
                padding: 15px 20px;
            }
            
            .header-left h1 {
                font-size: 1.4rem;
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
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                padding: 12px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .schedule-list {
                grid-template-columns: 1fr;
            }
            
            .schedule-actions {
                flex-direction: column;
            }
            
            .timetable {
                font-size: 0.8rem;
            }
            
            .timetable th, .timetable td {
                padding: 8px;
            }
            
            .class-slot {
                padding: 8px 5px;
                font-size: 0.75rem;
            }
            
            /* Adjust modal for mobile */
            .profile-modal-content {
                padding: 20px;
                width: 95%;
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
            
            .profile-card {
                margin: 10px;
                padding: 15px;
            }
            
            .profile-avatar {
                width: 70px;
                height: 70px;
            }
            
            .profile-name {
                font-size: 1rem;
            }
            
            .empty-state i {
                font-size: 3rem;
            }
            
            .empty-state h3 {
                font-size: 1.1rem;
            }
        }
        
        /* Fix for very small screens */
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
    <link rel="stylesheet" href="admin_nav_cards.css">
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
                    <span class="meta-value"><?php echo count($schedules); ?></span>
                    <span class="meta-label">Schedules</span>
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
                <li><a href="add_schedule.php" class="active"><i class="fas fa-calendar-alt"></i> Schedule</a></li>
                <li><a href="admin_assign_attendance.php"><i class="fa-solid fa-clipboard-check"></i> Assign Attendance</a></li>
                <li><a href="admin_videos.php"><i class="fas fa-video"></i> Videos</a></li>
            </ul>
        </nav>
        
        <nav class="nav-section">
            <h3>System Controls</h3>
            <ul class="nav-links">
                <li><a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="admin_faculty.php"><i class="fas fa-user-tie"></i> Faculty</a></li>
                <li><a href="admin_logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <h1><i class="fas fa-calendar-alt"></i> Class Schedule Management</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search schedules..." id="searchInput" autocomplete="off">
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
                                <?php echo strtoupper(substr($admin_profile['first_name'], 0, 1)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="quick-profile-info">
                        <div class="quick-profile-name">
                            <?php echo htmlspecialchars(trim((string)($admin_profile['first_name'] ?? '') . ' ' . (string)($admin_profile['last_name'] ?? '')) ?: 'Administrator', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="quick-profile-role">
                            <?php echo (($admin_profile['admin_type'] ?? '') === 'first_admin') ? 'Super Admin' : 'Administrator'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php elseif (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" data-tab="add">Add Schedule</div>
            <div class="tab" data-tab="manage">Manage Schedules</div>
            <div class="tab" data-tab="timetable">Timetable View</div>
        </div>

        <!-- Add Schedule Tab -->
        <div class="tab-content active" id="add-tab">
            <div class="content-card">
                <h2><i class="fas fa-plus-circle"></i> Add New Schedule</h2>
                <form method="POST" action="" id="scheduleForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="class">Class</label>
                            <input type="text" id="class" name="class" class="form-control" placeholder="e.g., 10, 11, 12" required autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="subject">Subject</label>
                            <input type="text" id="subject" name="subject" class="form-control" placeholder="e.g., Mathematics, Physics" required autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="teacher">Teacher</label>
                            <input type="text" id="teacher" name="teacher" class="form-control" placeholder="Teacher's name" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="day">Day</label>
                            <select id="day" name="day" class="form-control" required>
                                <option value="">Select Day</option>
                                <option>Monday</option>
                                <option>Tuesday</option>
                                <option>Wednesday</option>
                                <option>Thursday</option>
                                <option>Friday</option>
                                <option>Saturday</option>
                                <option>Sunday</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" class="form-control" required autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" class="form-control" required autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="medium">Medium</label>
                        <select id="medium" name="medium" class="form-control" required>
                            <option value="English">English Medium</option>
                            <option value="Hindi">Hindi Medium</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="schedule_type">Schedule Type</label>
                        <select id="schedule_type" name="schedule_type" class="form-control" required>
                            <option value="permanent">Permanent</option>
                            <option value="temporary">Temporary</option>
                        </select>
                        <small class="form-help" id="scheduleTypeHelp">
                            Permanent schedule har week visible rahega. Temporary schedule selected day ki next occurrence ke end time ke baad automatically hide ho jayega.
                        </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-save"></i> Add Schedule
                    </button>
                </form>
            </div>
        </div>

        <!-- Manage Schedules Tab -->
        <div class="tab-content" id="manage-tab">
            <div class="content-card">
                <h2><i class="fas fa-list"></i> Manage Schedules</h2>
                <?php if (empty($schedules)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Schedules Found</h3>
                        <p>Add some schedules to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="schedule-list">
                        <?php foreach ($schedules as $schedule): ?>
                            <?php
                                $scheduleType = schedule_normalize_type($schedule['schedule_type'] ?? 'permanent');
                                $expiryLabel = schedule_expiry_label($schedule['expires_at'] ?? '');
                            ?>
                            <div class="schedule-card">
                                <div class="schedule-header">
                                    <div class="schedule-day"><?php echo $schedule['day']; ?></div>
                                    <div class="schedule-time">
                                        <i class="far fa-clock"></i>
                                        <?php echo date('h:i A', strtotime($schedule['start_time'])); ?> - <?php echo date('h:i A', strtotime($schedule['end_time'])); ?>
                                    </div>
                                </div>
                                <div class="schedule-class">
                                    <i class="fas fa-graduation-cap"></i> Class <?php echo $schedule['class']; ?>
                                </div>
                                <div class="schedule-subject">
                                    <i class="fas fa-book"></i> <?php echo $schedule['subject']; ?>
                                </div>
                                <div class="schedule-teacher">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <?php echo $schedule['teacher'] ? $schedule['teacher'] : 'Not assigned'; ?>
                                </div>
                                <div class="schedule-meta-row">
                                    <div class="schedule-medium <?php echo strtolower($schedule['medium']) === 'english' ? 'medium-english' : 'medium-hindi'; ?>">
                                        <i class="fas fa-language"></i> <?php echo $schedule['medium']; ?> Medium
                                    </div>
                                    <div class="schedule-type schedule-type-<?php echo $scheduleType; ?>">
                                        <i class="fas fa-<?php echo $scheduleType === 'temporary' ? 'hourglass-half' : 'infinity'; ?>"></i>
                                        <?php echo ucfirst($scheduleType); ?>
                                    </div>
                                </div>
                                <?php if ($scheduleType === 'temporary' && $expiryLabel !== ''): ?>
                                    <div class="schedule-expiry">
                                        <i class="fas fa-clock"></i>
                                        Auto-hide after <?php echo $expiryLabel; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="schedule-actions">
                                    <button class="btn btn-warning btn-sm edit-btn" data-id="<?php echo $schedule['id']; ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="?delete_id=<?php echo $schedule['id']; ?>" class="btn btn-danger btn-sm delete-btn">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Timetable Tab -->
        <div class="tab-content" id="timetable-tab">
            <div class="content-card">
                <h2><i class="fas fa-table"></i> Timetable View</h2>
                <?php if (empty($schedules)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Schedules Found</h3>
                        <p>Add some schedules to view the timetable.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="timetable">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Monday</th>
                                    <th>Tuesday</th>
                                    <th>Wednesday</th>
                                    <th>Thursday</th>
                                    <th>Friday</th>
                                    <th>Saturday</th>
                                    <th>Sunday</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Generate time slots from 8 AM to 5 PM
                                $time_slots = [];
                                for ($hour = 8; $hour <= 17; $hour++) {
                                    $time_slots[] = sprintf("%02d:00", $hour);
                                    if ($hour < 17) {
                                        $time_slots[] = sprintf("%02d:30", $hour);
                                    }
                                }
                                
                                foreach ($time_slots as $time_slot):
                                    $time_display = date('h:i A', strtotime($time_slot));
                                ?>
                                <tr>
                                    <td class="time-slot"><?php echo $time_display; ?></td>
                                    <?php
                                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                                    foreach ($days as $day):
                                        $class_info = "";
                                        foreach ($schedules as $schedule) {
                                            if ($schedule['day'] == $day) {
                                                $start_time = date('H:i', strtotime($schedule['start_time']));
                                                $end_time = date('H:i', strtotime($schedule['end_time']));
                                                
                                                if ($time_slot >= $start_time && $time_slot < $end_time) {
                                                    $class_info = "<div class='class-slot'>
                                                        <div class='class-name'>Class {$schedule['class']}</div>
                                                        <div class='subject-name'>{$schedule['subject']}</div>
                                                        <div class='teacher-name'><i class='fas fa-user'></i> {$schedule['teacher']}</div>
                                                        <div class='medium-badge'>{$schedule['medium']}</div>
                                                    </div>";
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <td><?php echo $class_info ? $class_info : '<span class="free-slot">— Free —</span>'; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
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
    // Mobile menu toggle - FIXED
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

    // Quick profile click effect - FIXED with proper close button
    const quickProfile = document.getElementById('quickProfile');
    if (quickProfile) {
        quickProfile.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const quickProfileName = document.querySelector('.quick-profile-name').textContent;
            const quickProfileRole = document.querySelector('.quick-profile-role').textContent;
            const adminEmail = '<?php echo htmlspecialchars($admin_profile['email'] ?? 'admin@ruchiclasses.com'); ?>';
            const adminPhone = '<?php echo htmlspecialchars($admin_profile['phone'] ?? 'Not set'); ?>';
            
            // Create modal with proper structure
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

    // Tab switching
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            // Remove active class from all tabs and contents
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab + '-tab').classList.add('active');
        });
    });

    // Form validation
    document.getElementById('scheduleForm')?.addEventListener('submit', function(e) {
        const startTime = document.getElementById('start_time').value;
        const endTime = document.getElementById('end_time').value;
        
        if (startTime >= endTime) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Time',
                text: 'End time must be after start time.',
                timer: 3000,
                showConfirmButton: true,
                confirmButtonColor: '#27ae60',
                background: '#f9fafb',
                backdrop: true,
                allowOutsideClick: false
            });
        }
    });

    const scheduleTypeSelect = document.getElementById('schedule_type');
    const scheduleTypeHelp = document.getElementById('scheduleTypeHelp');

    function updateScheduleTypeHelp() {
        if (!scheduleTypeSelect || !scheduleTypeHelp) {
            return;
        }

        scheduleTypeHelp.textContent = scheduleTypeSelect.value === 'temporary'
            ? 'Temporary schedule selected day ki next occurrence ke end time ke baad automatically hide ho jayega.'
            : 'Permanent schedule har week same day aur time par visible rahega.';
    }

    if (scheduleTypeSelect) {
        scheduleTypeSelect.addEventListener('change', updateScheduleTypeHelp);
        updateScheduleTypeHelp();
    }

    // Delete confirmation
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            
            Swal.fire({
                title: 'Are you sure?',
                text: "This schedule will be permanently deleted!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#27ae60',
                cancelButtonColor: '#e74c3c',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                background: '#f9fafb',
                backdrop: true,
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        });
    });

    // Edit button functionality
    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function() {
            Swal.fire({
                title: 'Edit Schedule',
                text: 'This feature is under development. You can delete and recreate the schedule for now.',
                icon: 'info',
                confirmButtonColor: '#27ae60',
                confirmButtonText: 'OK',
                background: '#f9fafb',
                backdrop: true,
                allowOutsideClick: false
            });
        });
    });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const scheduleCards = document.querySelectorAll('.schedule-card');
            
            scheduleCards.forEach(card => {
                const text = card.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    // Auto-hide success message after 5 seconds
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                successAlert.style.display = 'none';
            }, 500);
        }, 5000);
    }

    // Error message auto-hide
    const errorAlert = document.querySelector('.alert-error');
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.opacity = '0';
            errorAlert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                errorAlert.style.display = 'none';
            }, 500);
        }, 5000);
    }

    // Add smooth scrolling to main content
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

    // Save and restore sidebar scroll position
    window.addEventListener('beforeunload', function() {
        localStorage.setItem('sidebarScrollPosition', sidebar.scrollTop);
    });

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
        
        // Animate cards on load
        const scheduleCards = document.querySelectorAll('.schedule-card');
        scheduleCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100 * index);
        });
        
        // Animate stat cards if any
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
    });

    // Prevent default behavior on active link click
    document.querySelectorAll('.nav-links a.active').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            mainContent.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    });
</script>
</body>
</html>
