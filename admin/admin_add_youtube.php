<?php
session_start();
require '../db.php';
require_once __DIR__ . '/admin_notifications_ui.php';

// Check admin authentication
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

// Get admin info
$admin_id = $_SESSION['admin_id'] ?? $_SESSION['admin_id'] ?? 1;

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
    $admin_profile['phone'] = $_SESSION['admin_phone'] ?? '';
} else {
    // Try database
    $stmt = $conn->prepare("SELECT id, name, email, 'admin' as admin_type, '' as photo, '' as phone FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $admin_data = $result->fetch_assoc();
        $name_parts = explode(' ', $admin_data['name'] ?? 'Admin');
        $admin_profile['first_name'] = $name_parts[0] ?? 'Admin';
        $admin_profile['last_name'] = $name_parts[1] ?? '';
        $admin_profile['email'] = $admin_data['email'] ?? 'admin@ruchiclasses.com';
        $admin_profile['admin_type'] = 'admin';
        $admin_profile['photo'] = '';
        $admin_profile['phone'] = '';
    } else {
        $admin_profile = [
            'first_name' => 'Admin',
            'last_name' => '',
            'email' => 'admin@ruchiclasses.com',
            'admin_type' => 'admin',
            'phone' => '',
            'photo' => ''
        ];
    }
}

$admin_notifications_data = admin_notifications_prepare($conn, ['id' => (int)$admin_id], 12);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $link = trim($_POST['link']);
    $class = $_POST['class'];
    $medium = $_POST['medium'];
    $subject = trim($_POST['subject']);
    $chapter = trim($_POST['chapter']);
    
    // Validate
    if (empty($title) || empty($link) || empty($class) || empty($medium)) {
        $error = "Please fill all required fields!";
    } else {
        // Extract video ID
        $video_id = '';
        if (preg_match('/youtu\.be\/([^\?]+)/', $link, $matches)) {
            $video_id = $matches[1];
        } elseif (preg_match('/youtube\.com.*[?&]v=([^&]+)/', $link, $matches)) {
            $video_id = $matches[1];
        } else {
            $video_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $link);
        }
        
        // Insert video
        $sql = "INSERT INTO youtube_videos (title, description, video_id, file_path, class_name, medium, subject, chapter, uploaded_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssssssi', $title, $description, $video_id, $link, $class, $medium, $subject, $chapter, $admin_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Video added successfully!";
            header("Location: admin_videos.php");
            exit();
        } else {
            $error = "Error adding video: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Function to get admin photo
function getAdminPhotoPath($photo) {
    if (empty($photo)) {
        return '';
    }
    
    $photo_path = '../' . $photo;
    if (file_exists($photo_path)) {
        return $photo_path;
    }
    
    return '';
}

// Get current page for active navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Add Video | Ruchi Classes</title>
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
            z-index: 900;
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
            padding: 25px;
            max-height: 100vh;
            overflow-y: auto;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
            gap: 20px;
            position: sticky;
            top: -25px;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .header-left h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
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
            padding: 10px 18px;
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
            line-height: 1.4;
        }
        
        .quick-profile-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 0.95rem;
        }
        
        .quick-profile-role {
            font-size: 0.8rem;
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
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .btn-lg {
            padding: 16px 30px;
            font-size: 1.1rem;
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
            z-index: 800;
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
                width: 320px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
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
                gap: 15px;
                padding: 20px;
            }
            
            .header-left h1 {
                font-size: 1.4rem;
                justify-content: center;
                text-align: center;
                width: 100%;
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
                padding: 12px;
            }
            
            .content-card {
                padding: 20px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .form-row .form-group {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            body {
                font-size: 12px;
            }
            
            .main-content {
                padding: 15px;
            }
            
            .header {
                padding: 15px;
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
                padding: 14px 25px;
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
                    <span class="meta-value"><?php echo date('d/m'); ?></span>
                    <span class="meta-label">Date</span>
                </div>
            </div>
        </div>
        
        <nav class="nav-section">
            <h3>Navigation Menu</h3>
            <ul class="nav-links">
                <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage_teacher.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="admission_report.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="admin_assign_students.php"><i class="fas fa-users"></i> Assign Students</a></li>
                <li><a href="admin_complaints.php"><i class="fas fa-comment-dots"></i> Complaints</a></li>
                <li><a href="add_schedule.php"><i class="fas fa-calendar-alt"></i> Schedule</a></li>
                <li><a href="admin_assign_attendance.php"><i class="fa-solid fa-clipboard-check"></i> Assign Attendance</a></li>
                <li><a href="admin_videos.php" class="active"><i class="fas fa-video"></i> Videos</a></li>
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
                <h1><i class="fas fa-plus-circle"></i> Add New Video</h1>
            </div>
            
            <div class="header-right">
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

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Success Message (if not redirected) -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <p style="margin-top: 10px;">Redirecting to videos page...</p>
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = 'admin_videos.php';
                }, 2000);
            </script>
        <?php endif; ?>

        <!-- Add Video Form -->
        <div class="content-card">
            <h2><i class="fab fa-youtube"></i> Video Details</h2>
            <p style="margin-bottom: 20px; color: var(--secondary);">Fill in the details below to add a new educational video</p>
            
            <form method="POST" action="" id="videoForm">
                <div class="form-group">
                    <label class="form-label">Video Title *</label>
                    <input type="text" name="title" class="form-control" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">YouTube URL or Video ID *</label>
                    <input type="text" name="link" class="form-control" required placeholder="https://youtube.com/watch?v=... or video ID" value="<?php echo htmlspecialchars($_POST['link'] ?? ''); ?>">
                    <small style="color: var(--secondary); display: block; margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> Enter full YouTube URL or just the video ID
                    </small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Class *</label>
                        <select name="class" class="form-control" required>
                            <option value="">Select Class</option>
                            <option value="8" <?php echo ($_POST['class'] ?? '') == '8' ? 'selected' : ''; ?>>Class 8</option>
                            <option value="9" <?php echo ($_POST['class'] ?? '') == '9' ? 'selected' : ''; ?>>Class 9</option>
                            <option value="10" <?php echo ($_POST['class'] ?? '') == '10' ? 'selected' : ''; ?>>Class 10</option>
                            <option value="11" <?php echo ($_POST['class'] ?? '') == '11' ? 'selected' : ''; ?>>Class 11</option>
                            <option value="12" <?php echo ($_POST['class'] ?? '') == '12' ? 'selected' : ''; ?>>Class 12</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Medium *</label>
                        <select name="medium" class="form-control" required>
                            <option value="">Select Medium</option>
                            <option value="hindi" <?php echo ($_POST['medium'] ?? '') == 'hindi' ? 'selected' : ''; ?>>Hindi</option>
                            <option value="english" <?php echo ($_POST['medium'] ?? '') == 'english' ? 'selected' : ''; ?>>English</option>
                            <option value="both" <?php echo ($_POST['medium'] ?? '') == 'both' ? 'selected' : ''; ?>>Both</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Chapter/Topic</label>
                        <input type="text" name="chapter" class="form-control" value="<?php echo htmlspecialchars($_POST['chapter'] ?? ''); ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 30px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
                        <i class="fas fa-upload"></i> Add Video
                    </button>
                    <a href="admin_videos.php" class="btn btn-secondary btn-lg" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Preview Section -->
        <?php if (!empty($_POST['link']) && empty($error)): 
            // Extract video ID for preview
            $preview_link = $_POST['link'];
            $preview_id = '';
            if (preg_match('/youtu\.be\/([^\?]+)/', $preview_link, $matches)) {
                $preview_id = $matches[1];
            } elseif (preg_match('/youtube\.com.*[?&]v=([^&]+)/', $preview_link, $matches)) {
                $preview_id = $matches[1];
            } else {
                $preview_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $preview_link);
            }
            
            if (!empty($preview_id) && strlen($preview_id) > 5):
        ?>
        <div class="content-card">
            <h2><i class="fas fa-eye"></i> Video Preview</h2>
            <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <img src="https://img.youtube.com/vi/<?php echo $preview_id; ?>/maxresdefault.jpg" 
                         alt="Video Thumbnail"
                         style="width: 100%; border-radius: var(--radius); border: 2px solid var(--primary);"
                         onerror="this.src='https://img.youtube.com/vi/<?php echo $preview_id; ?>/hqdefault.jpg'">
                </div>
                <div style="flex: 1; min-width: 250px;">
                    <h3 style="color: var(--dark); margin-bottom: 10px;">Video Information</h3>
                    <p><strong>Title:</strong> <?php echo htmlspecialchars($_POST['title'] ?? ''); ?></p>
                    <p><strong>Class:</strong> <?php echo htmlspecialchars($_POST['class'] ?? ''); ?></p>
                    <p><strong>Medium:</strong> <?php echo htmlspecialchars($_POST['medium'] ?? ''); ?></p>
                    <?php if (!empty($_POST['subject'])): ?>
                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($_POST['subject']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($_POST['chapter'])): ?>
                    <p><strong>Chapter:</strong> <?php echo htmlspecialchars($_POST['chapter']); ?></p>
                    <?php endif; ?>
                    <a href="https://youtube.com/watch?v=<?php echo $preview_id; ?>" target="_blank" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fab fa-youtube"></i> Watch on YouTube
                    </a>
                </div>
            </div>
        </div>
        <?php endif; endif; ?>

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
    
    // Mobile menu toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const mainContent = document.getElementById('mainContent');

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
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
            const quickProfileName = document.querySelector('.quick-profile-name')?.textContent || 'Admin';
            const quickProfileRole = document.querySelector('.quick-profile-role')?.textContent || 'Admin';
            
            // Create a custom modal
            const modal = document.createElement('div');
            modal.style.cssText = `
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
            `;
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    border-radius: var(--radius);
                    padding: 30px;
                    max-width: 400px;
                    width: 90%;
                    box-shadow: var(--shadow-lg);
                    border: 2px solid var(--primary);
                ">
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
                            <span style="color: var(--secondary); margin-left: 8px;"><?php echo htmlspecialchars($admin_profile['email'] ?? 'admin@ruchiclasses.com'); ?></span>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong style="color: var(--dark);">Phone:</strong>
                            <span style="color: var(--secondary); margin-left: 8px;"><?php echo !empty($admin_profile['phone']) ? htmlspecialchars($admin_profile['phone']) : 'Not set'; ?></span>
                        </div>
                        <div>
                            <strong style="color: var(--dark);">Account Type:</strong>
                            <span style="color: var(--secondary); margin-left: 8px;">${quickProfileRole}</span>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 25px;">
                        <button onclick="this.closest('div[style*=\'position: fixed\']').remove()" style="
                            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                            color: white;
                            border: none;
                            padding: 12px 30px;
                            border-radius: 8px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.3s ease;
                            font-family: 'Inter', sans-serif;
                        " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 15px rgba(39, 174, 96, 0.4)'"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <i class="fas fa-times" style="margin-right: 8px;"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
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
    
    // Animate form on load
    const formGroups = document.querySelectorAll('.form-group');
    formGroups.forEach((group, index) => {
        group.style.opacity = '0';
        group.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            group.style.transition = 'all 0.5s ease';
            group.style.opacity = '1';
            group.style.transform = 'translateY(0)';
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

    // Form validation
    const videoForm = document.getElementById('videoForm');
    if (videoForm) {
        videoForm.addEventListener('submit', function(e) {
            const title = document.querySelector('input[name="title"]').value;
            const link = document.querySelector('input[name="link"]').value;
            const classVal = document.querySelector('select[name="class"]').value;
            const medium = document.querySelector('select[name="medium"]').value;
            
            if (!title || !link || !classVal || !medium) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Form',
                    text: 'Please fill in all required fields!',
                    confirmButtonColor: '#27ae60',
                    background: '#f9fafb',
                    backdrop: true,
                    allowOutsideClick: false
                });
                return false;
            }
            
            // Validate YouTube URL
            const youtubeRegex = /^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/;
            const videoIdRegex = /^[a-zA-Z0-9_-]{11}$/;
            
            if (!youtubeRegex.test(link) && !videoIdRegex.test(link)) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid YouTube Link',
                    text: 'Please enter a valid YouTube URL or 11-character video ID',
                    confirmButtonColor: '#27ae60',
                    background: '#f9fafb',
                    backdrop: true,
                    allowOutsideClick: false
                });
                return false;
            }
            
            return true;
        });
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
</script>
</body>
</html>
