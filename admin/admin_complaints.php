<?php
session_start();
require '../db.php';
require_once 'admin_auth.php';
require_once __DIR__ . '/admin_notifications_ui.php';

// ===== AJAX STATUS UPDATE (SAME FILE) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['admin_logged_in'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $new_status = ucfirst(strtolower(trim($_POST['status'] ?? '')));

    if ($complaint_id <= 0 || !in_array($new_status, ['Pending', 'Resolved'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }

    // Get current status
    $stmt = $conn->prepare("SELECT status, user_type, user_id FROM complaints WHERE id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Complaint not found']);
        exit;
    }

    $current_data = $res->fetch_assoc();
    $current_status = $current_data['status'];

    if (strtolower($current_status) === strtolower($new_status)) {
        echo json_encode(['success' => false, 'message' => 'Status already same']);
        exit;
    }

    // Update
    $up = $conn->prepare("UPDATE complaints SET status = ? WHERE id = ?");
    $up->bind_param("si", $new_status, $complaint_id);

    if ($up->execute()) {
        if (($current_data['user_type'] ?? '') === 'teacher' && function_exists('logAdminActivity')) {
            logAdminActivity('COMPLAINT_STATUS_UPDATED', "Updated complaint #{$complaint_id} to {$new_status} for teacher ID: {$current_data['user_id']}");
        }
        echo json_encode([
            'success' => true,
            'old' => $current_status,
            'new' => $new_status
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error']);
    }
    exit;
}
// ===== END AJAX HANDLER =====

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

// Handle status update with affected_rows check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $new_status = ucfirst(strtolower(trim($_POST['status'])));
    
    // Validate status
    if (!in_array($new_status, ['Pending', 'Resolved'])) {
        $_SESSION['error_message'] = "Invalid status value!";
        header("Location: admin_complaints.php");
        exit();
    }
    
    // First check if complaint exists
    $check_stmt = $conn->prepare("SELECT id, status, user_type, user_id FROM complaints WHERE id = ?");
    $check_stmt->bind_param("i", $complaint_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $_SESSION['error_message'] = "Complaint #$complaint_id not found!";
    } else {
        $current_data = $check_result->fetch_assoc();
        $current_status = $current_data['status'];
        
        // Check if status is actually changing
        if (strtolower(trim($current_status)) == strtolower(trim($new_status))) {
            $_SESSION['error_message'] = "No change made - status is already $current_status.";
        } else {
            // Update the status
            $stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $complaint_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Status updated from $current_status to $new_status!";
                if (($current_data['user_type'] ?? '') === 'teacher' && function_exists('logAdminActivity')) {
                    logAdminActivity('COMPLAINT_STATUS_UPDATED', "Updated complaint #{$complaint_id} to {$new_status} for teacher ID: {$current_data['user_id']}");
                }
            } else {
                $_SESSION['error_message'] = "Error updating status: " . $conn->error;
            }
            $stmt->close();
        }
    }
    $check_stmt->close();
    
    // Redirect to prevent form resubmission
    header("Location: admin_complaints.php");
    exit();
}

// Fetch complaints - Using correct column name 'complaint'
$result = $conn->query("
    SELECT c.id, c.user_type, c.user_id, c.complaint, c.status, c.created_at,
           CASE 
               WHEN c.user_type = 'teacher' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM teachers WHERE id = c.user_id)
               WHEN c.user_type = 'student' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM student_english WHERE id = c.user_id)
               ELSE 'Unknown'
           END as user_name
    FROM complaints c
    ORDER BY c.created_at DESC
");

// Check if query failed
if (!$result) {
    die("Query failed: " . $conn->error);
}

// Get counts for statistics
$total_complaints = 0;
$pending_count = 0;
$resolved_count = 0;

if ($result->num_rows > 0) {
    $total_complaints = $result->num_rows;
    
    // Reset pointer to count
    $result->data_seek(0);
    while($row = $result->fetch_assoc()) {
        $status_lower = strtolower(trim($row['status']));
        if ($status_lower == 'pending') $pending_count++;
        if ($status_lower == 'resolved') $resolved_count++;
    }
    
    // Reset pointer for main display
    $result->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Complaints Management | Ruchi Classes</title>
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
        
        .stat-card .count.total {
            color: var(--primary);
        }
        
        .stat-card .count.pending {
            color: var(--warning);
        }
        
        .stat-card .count.resolved {
            color: var(--success);
        }
        
        .stat-card .subtext {
            font-size: 0.85rem;
            color: var(--secondary);
            margin-top: 10px;
        }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            overflow-x: auto;
            border: 2px solid #e0e6ed;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th {
            background: linear-gradient(to right, var(--primary-light), #ebf5e6);
            color: var(--dark);
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 3px solid var(--primary);
            font-size: 0.9rem;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e0e6ed;
            vertical-align: middle;
        }
        
        tr {
            transition: var(--transition);
        }
        
        tr:hover {
            background-color: #f8fafc;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fef5e7, #fdebd0);
            color: #b9770e;
            border: 2px solid #f8c471;
        }
        
        .status-resolved {
            background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
            color: #1e8449;
            border: 2px solid #a3e4b9;
        }
        
        .user-type {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .user-student {
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.1), rgba(52, 152, 219, 0.2));
            color: #2980b9;
            border: 2px solid rgba(52, 152, 219, 0.3);
        }
        
        .user-teacher {
            background: linear-gradient(135deg, rgba(243, 156, 18, 0.1), rgba(243, 156, 18, 0.2));
            color: #d68910;
            border: 2px solid rgba(243, 156, 18, 0.3);
        }
        
        /* Action Form with disabled state */
        .action-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .status-select {
            padding: 10px 15px;
            border-radius: 8px;
            border: 2px solid #e0e6ed;
            font-size: 0.9rem;
            background: #f8fafc;
            transition: var(--transition);
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            min-width: 120px;
        }
        
        .status-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
            background: white;
        }
        
        .update-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
        }
        
        .update-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.3);
        }
        
        .update-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
        
        .update-btn i {
            font-size: 0.9rem;
        }
        
        .complaint-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .complaint-text.expanded {
            white-space: normal;
            max-width: none;
            word-wrap: break-word;
        }
        
        .toggle-text {
            color: var(--primary);
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 5px;
            display: inline-block;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .toggle-text:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .user-id {
            font-family: monospace;
            background: #f8fafc;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            border: 1px solid #e0e6ed;
            display: inline-block;
            margin-bottom: 4px;
        }
        
        .user-id-small {
            font-size: 0.75rem;
            color: var(--secondary);
        }
        
        /* Hint text */
        .hint-text {
            color: #7f8c8d;
            font-size: 0.7rem;
            margin-top: 5px;
            display: block;
            font-style: italic;
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
            
            .header-left h1 {
                font-size: 1.4rem;
            }
            
            .stats-cards {
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
            
            .stats-cards {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-card .count {
                font-size: 2.2rem;
            }
            
            table {
                min-width: 600px;
            }
            
            th, td {
                padding: 12px;
                font-size: 0.85rem;
            }
            
            .action-form {
                flex-direction: column;
                min-width: 120px;
            }
            
            .status-select {
                width: 100%;
            }
            
            .update-btn {
                width: 100%;
                justify-content: center;
            }
            
            .complaint-text {
                max-width: 150px;
            }
            
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
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-card .count {
                font-size: 2rem;
            }
            
            th, td {
                padding: 10px;
                font-size: 0.8rem;
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
                    <span class="meta-value"><?php echo $total_complaints; ?></span>
                    <span class="meta-label">Complaints</span>
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
                <li><a href="admin_manage_student_edit.php"><i class="fas fa-edit"></i> Manage Students</a></li>
                <li><a href="admin_complaints.php" class="active"><i class="fas fa-comment-dots"></i> Complaints</a></li>
                <li><a href="add_schedule.php"><i class="fas fa-calendar-alt"></i> Schedule</a></li>
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
                <h1><i class="fas fa-comment-dots"></i> Complaints Management</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search complaints, users..." autocomplete="off">
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

        <!-- Success/Error Messages - Using Session -->
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
                <h3>Total Complaints</h3>
                <div class="count total"><?php echo $total_complaints; ?></div>
                <div class="subtext">All registered complaints</div>
            </div>
            <div class="stat-card">
                <h3>Pending</h3>
                <div class="count pending"><?php echo $pending_count; ?></div>
                <div class="subtext">Awaiting resolution</div>
            </div>
            <div class="stat-card">
                <h3>Resolved</h3>
                <div class="count resolved"><?php echo $resolved_count; ?></div>
                <div class="subtext">Successfully resolved</div>
            </div>
        </div>

        <!-- Complaints Table -->
        <div class="content-card">
            <h2><i class="fas fa-list"></i> Complaints List</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Type</th>
                            <th>Complaint</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): 
                                $status_lower = strtolower(trim($row['status']));
                                $status_class = 'status-' . $status_lower;
                                $user_type_class = 'user-' . $row['user_type'];
                                
                                // Set dropdown selected values based on current status
                                $pending_selected = ($status_lower == 'pending') ? 'selected' : '';
                                $resolved_selected = ($status_lower == 'resolved') ? 'selected' : '';
                            ?>
                            <tr>
                                <td><strong>#<?php echo $row['id']; ?></strong></td>
                                <td>
                                    <span class="user-id"><?php echo htmlspecialchars($row['user_name'] ?? 'User #'.$row['user_id']); ?></span>
                                    <div class="user-id-small">(ID: <?php echo $row['user_id']; ?>)</div>
                                </td>
                                <td><span class="user-type <?php echo $user_type_class; ?>"><?php echo ucfirst($row['user_type']); ?></span></td>
                                <td>
                                    <div class="complaint-text" id="complaint-<?php echo $row['id']; ?>">
                                        <?php echo htmlspecialchars($row['complaint']); ?>
                                    </div>
                                    <?php if (strlen($row['complaint']) > 100): ?>
                                        <span class="toggle-text" onclick="toggleText(<?php echo $row['id']; ?>)">Show more</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <form class="action-form">
                                        <input type="hidden" name="complaint_id" value="<?php echo $row['id']; ?>">
                                        <select name="status" class="status-select" required>
                                            <option value="Pending" <?php echo $pending_selected; ?>>Pending</option>
                                            <option value="Resolved" <?php echo $resolved_selected; ?>>Resolved</option>
                                        </select>
                                        <button type="button" class="update-btn" data-id="<?php echo $row['id']; ?>">
                                            <i class="fas fa-sync-alt"></i> Update
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h3>No Complaints Found</h3>
                                        <p>There are no complaints to display at the moment.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
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
                    <p><strong>Total Complaints:</strong> <?php echo $total_complaints; ?></p>
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

// Toggle complaint text expansion
function toggleText(id) {
    const textElement = document.getElementById(`complaint-${id}`);
    const toggleButton = textElement.nextElementSibling;
    
    if (textElement.classList.contains('expanded')) {
        textElement.classList.remove('expanded');
        toggleButton.textContent = 'Show more';
    } else {
        textElement.classList.add('expanded');
        toggleButton.textContent = 'Show less';
    }
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

// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                // Skip empty state row
                if (row.querySelector('td[colspan]')) return;
                
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
    const rows = document.querySelectorAll('tbody tr:not(:has(td[colspan]))');
    rows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            row.style.transition = 'all 0.5s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, 100 * index);
    });
    
    // Animate stats cards
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
            mainContent.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
    
    // Save and restore sidebar scroll position
    const savedScrollPosition = localStorage.getItem('sidebarScrollPosition');
    if (savedScrollPosition) {
        setTimeout(() => {
            sidebar.scrollTop = parseInt(savedScrollPosition);
            localStorage.removeItem('sidebarScrollPosition');
        }, 100);
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    if (window.innerWidth >= 1200) {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
});

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

// Save sidebar scroll position before page unload
window.addEventListener('beforeunload', function() {
    localStorage.setItem('sidebarScrollPosition', sidebar.scrollTop);
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

// Optional: Enable/disable button based on select change
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        const form = this.closest('form');
        const button = form.querySelector('.update-btn');
        const currentStatus = form.closest('tr').querySelector('.status-badge').textContent.trim().toLowerCase();
        const selectedValue = this.value.toLowerCase();
        
        if (selectedValue === currentStatus) {
            button.disabled = true;
        } else {
            button.disabled = false;
        }
    });
});

// NEW: AJAX Status Update (No Page Reload)
document.querySelectorAll('.update-btn').forEach(button => {
    button.addEventListener('click', function () {
        const form = this.closest('form');
        const select = form.querySelector('.status-select');
        const complaintId = this.dataset.id;
        const newStatus = select.value;

        const row = form.closest('tr');
        const badge = row.querySelector('.status-badge');
        const currentStatus = badge.textContent.trim();

        if (currentStatus.toLowerCase() === newStatus.toLowerCase()) {
            Swal.fire({
                title: 'No Change',
                text: 'Status is already ' + currentStatus,
                icon: 'info',
                timer: 1500,
                showConfirmButton: false
            });
            return;
        }

        Swal.fire({
            title: 'Update Status?',
            text: `Change from "${currentStatus}" to "${newStatus}"?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#27ae60',
            cancelButtonColor: '#e74c3c',
            confirmButtonText: 'Yes, update!',
            background: '#f9fafb'
        }).then(result => {
            if (!result.isConfirmed) return;

            // Show loading
            Swal.fire({
                title: 'Updating...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            // AJAX request
            fetch('admin_complaints.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `ajax_update=1&complaint_id=${complaintId}&status=${newStatus}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update badge
                    badge.textContent = data.new;
                    badge.className = 'status-badge status-' + data.new.toLowerCase();

                    // Update counts in stats cards
                    updateStats();

                    // Show success
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: `Status changed to ${data.new}`,
                        timer: 1200,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Server error', 'error'));
        });
    });
});

// Function to update statistics dynamically
function updateStats() {
    // Count current statuses
    const pending = document.querySelectorAll('.status-pending').length;
    const resolved = document.querySelectorAll('.status-resolved').length;
    const total = pending + resolved;
    
    // Update stats cards
    document.querySelector('.stat-card .count.total').textContent = total;
    document.querySelector('.stat-card .count.pending').textContent = pending;
    document.querySelector('.stat-card .count.resolved').textContent = resolved;
}
</script>
</body>
</html>
