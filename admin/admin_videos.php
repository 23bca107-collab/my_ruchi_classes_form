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
    $admin_profile['phone'] = $_SESSION['admin_phone'] ?? '9898624729';
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
        $admin_profile['phone'] = '9898624729';
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

// Handle video deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $video_id = intval($_GET['delete']);
    $delete_sql = "DELETE FROM youtube_videos WHERE id = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param('i', $video_id);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Video deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Error deleting video!";
    }
    $stmt->close();
    header("Location: admin_videos.php");
    exit();
}

// Get filter parameters
$class_filter = $_GET['class'] ?? '';
$medium_filter = $_GET['medium'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query with filters
$sql = "SELECT * FROM youtube_videos WHERE 1=1";
$params = [];
$types = '';

if (!empty($class_filter)) {
    $sql .= " AND class_name = ?";
    $params[] = $class_filter;
    $types .= 's';
}

if (!empty($medium_filter)) {
    $sql .= " AND medium = ?";
    $params[] = $medium_filter;
    $types .= 's';
}

if (!empty($search_query)) {
    $sql .= " AND (title LIKE ? OR description LIKE ? OR subject LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$sql .= " ORDER BY created_at DESC";

// Prepare and execute query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_videos,
    COUNT(DISTINCT class_name) as classes_count,
    COUNT(DISTINCT medium) as mediums_count,
    (SELECT COUNT(*) FROM youtube_history) as total_views
    FROM youtube_videos";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get current page for active navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Video Management | Ruchi Classes</title>
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
        
        /* Profile Card in Sidebar - FIXED PHOTO DISPLAY */
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
        
        /* Search Bar - FIXED with autocomplete */
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
            color: #ff0000;
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
        
        .stat-card .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.6rem;
            margin: 0 auto 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
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
        
        .stat-card .count.completed {
            color: var(--success);
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
        
        /* Filter Section */
        .filters-container {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 25px;
            border: 2px solid #e0e6ed;
        }
        
        .filter-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        /* Videos Grid */
        .videos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .video-card {
            background: linear-gradient(135deg, white, #fdfdfd);
            border-radius: var(--radius);
            overflow: hidden;
            border: 2px solid #e0e6ed;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
        }
        
        .video-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }
        
        .video-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }
        
        .video-thumbnail {
            position: relative;
            height: 180px;
            overflow: hidden;
            background: linear-gradient(45deg, var(--dark), #34495e);
        }
        
        .video-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }
        
        .video-card:hover .video-thumbnail img {
            transform: scale(1.05);
        }
        
        .video-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--danger);
            font-size: 1.2rem;
            opacity: 0;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }
        
        .video-card:hover .video-play-btn {
            opacity: 1;
        }
        
        .video-info {
            padding: 20px;
        }
        
        .video-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .video-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-class {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
            border: 2px solid rgba(52, 152, 219, 0.2);
        }
        
        .badge-hindi {
            background: rgba(243, 156, 18, 0.1);
            color: #d68910;
            border: 2px solid rgba(243, 156, 18, 0.2);
        }
        
        .badge-english {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
            border: 2px solid rgba(52, 152, 219, 0.2);
        }
        
        .badge-both {
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.1), rgba(243, 156, 18, 0.1));
            color: var(--dark);
            border: 2px solid rgba(39, 174, 96, 0.2);
        }
        
        .video-desc {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 12px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .video-subject {
            font-size: 0.85rem;
            color: var(--dark);
            margin-bottom: 15px;
            background: var(--primary-light);
            padding: 5px 12px;
            border-radius: 15px;
            display: inline-block;
        }
        
        .video-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .action-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
        }
        
        .action-btn.view {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);
        }
        
        .action-btn.edit {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            color: #374151;
            border: 2px solid #d1d5db;
        }
        
        .action-btn.delete {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
            border: 2px solid #fca5a5;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }
        
        /* No Videos Message */
        .no-videos {
            text-align: center;
            padding: 60px 20px;
            color: var(--secondary);
            grid-column: 1 / -1;
        }
        
        .no-videos i {
            font-size: 4rem;
            color: #bdc3c7;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-videos h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .no-videos p {
            font-size: 1rem;
            margin-bottom: 20px;
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .filter-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .videos-grid {
                grid-template-columns: 1fr;
            }
            
            .video-thumbnail {
                height: 200px;
            }
            
            .video-actions {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
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
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-card .count {
                font-size: 2rem;
            }
            
            .btn {
                padding: 8px 12px;
                font-size: 0.85rem;
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
        
        <!-- Profile Card in Sidebar - FIXED PHOTO DISPLAY -->
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
                    <span class="meta-value"><?php echo $stats['total_videos'] ?? 0; ?></span>
                    <span class="meta-label">Videos</span>
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
                <h1><i class="fas fa-video"></i> Video Management</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search videos..." value="<?php echo htmlspecialchars($search_query); ?>" autocomplete="off">
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
                <div class="stat-icon">
                    <i class="fas fa-video"></i>
                </div>
                <div class="count total"><?php echo $stats['total_videos'] ?? 0; ?></div>
                <div class="subtext">Total Videos</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="count pending"><?php echo $stats['classes_count'] ?? 0; ?></div>
                <div class="subtext">Classes Covered</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-language"></i>
                </div>
                <div class="count completed"><?php echo $stats['mediums_count'] ?? 0; ?></div>
                <div class="subtext">Mediums</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="count total"><?php echo $stats['total_views'] ?? 0; ?></div>
                <div class="subtext">Total Views</div>
            </div>
        </div>

        <!-- Main Content Card -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                    <i class="fab fa-youtube"></i> Manage YouTube Videos
                </h2>
                <div style="display: flex; gap: 10px;">
                    <a href="admin_add_youtube.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Add Video
                    </a>
                    <a href="admin_history.php" class="btn btn-info">
                        <i class="fas fa-history"></i> View History
                    </a>
                </div>
            </div>
            
            <!-- Filters - FIXED with proper labels -->
            <div class="filters-container">
                <h3 class="filter-title">
                    <i class="fas fa-filter"></i>
                    Filter Videos
                </h3>
                <form method="GET" class="filter-form" id="filterForm">
                    <div class="form-group">
                        <label for="class_filter" class="form-label">Class</label>
                        <select name="class" id="class_filter" class="form-control">
                            <option value="">All Classes</option>
                            <option value="8" <?php echo $class_filter == '8' ? 'selected' : ''; ?>>Class 8</option>
                            <option value="9" <?php echo $class_filter == '9' ? 'selected' : ''; ?>>Class 9</option>
                            <option value="10" <?php echo $class_filter == '10' ? 'selected' : ''; ?>>Class 10</option>
                            <option value="11" <?php echo $class_filter == '11' ? 'selected' : ''; ?>>Class 11</option>
                            <option value="12" <?php echo $class_filter == '12' ? 'selected' : ''; ?>>Class 12</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="medium_filter" class="form-label">Medium</label>
                        <select name="medium" id="medium_filter" class="form-control">
                            <option value="">All Mediums</option>
                            <option value="hindi" <?php echo $medium_filter == 'hindi' ? 'selected' : ''; ?>>Hindi</option>
                            <option value="english" <?php echo $medium_filter == 'english' ? 'selected' : ''; ?>>English</option>
                            <option value="both" <?php echo $medium_filter == 'both' ? 'selected' : ''; ?>>Both</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="search_query" class="form-label">Search</label>
                        <input type="text" name="search" id="search_query" class="form-control" placeholder="Search by title, subject..." value="<?php echo htmlspecialchars($search_query); ?>" autocomplete="off">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="admin_videos.php" class="btn btn-warning">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Videos Grid -->
            <?php if ($result && $result->num_rows > 0): ?>
            <div class="videos-grid">
                <?php while($video = $result->fetch_assoc()): 
                    $medium_class = 'badge-' . $video['medium'];
                ?>
                <div class="video-card">
                    <div class="video-thumbnail">
                        <img src="https://img.youtube.com/vi/<?php echo $video['video_id']; ?>/hqdefault.jpg" 
                             alt="<?php echo htmlspecialchars($video['title']); ?>"
                             onerror="this.src='https://via.placeholder.com/320x180/2c3e50/ffffff?text=Video+Thumbnail'">
                        <div class="video-play-btn">
                            <i class="fas fa-play"></i>
                        </div>
                    </div>
                    
                    <div class="video-info">
                        <h3 class="video-title"><?php echo htmlspecialchars($video['title']); ?></h3>
                        
                        <div class="video-meta">
                            <span class="meta-badge badge-class">
                                <i class="fas fa-graduation-cap"></i>
                                Class <?php echo $video['class_name']; ?>
                            </span>
                            <span class="meta-badge <?php echo $medium_class; ?>">
                                <i class="fas fa-language"></i>
                                <?php echo ucfirst($video['medium']); ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($video['description'])): ?>
                        <p class="video-desc"><?php echo htmlspecialchars(substr($video['description'], 0, 100)); ?>...</p>
                        <?php endif; ?>
                        
                        <?php if (!empty($video['subject'])): ?>
                        <div class="video-subject">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($video['subject']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="video-actions">
                            <a href="https://youtube.com/watch?v=<?php echo $video['video_id']; ?>" 
                               target="_blank" 
                               class="action-btn view">
                                <i class="fas fa-play-circle"></i> View
                            </a>
                            <a href="admin_edit_video.php?id=<?php echo $video['id']; ?>" 
                               class="action-btn edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="?delete=<?php echo $video['id']; ?>" 
                               class="action-btn delete"
                               onclick="return confirmDelete(event, this.href)">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="no-videos">
                <i class="fas fa-video-slash"></i>
                <h3>No Videos Found</h3>
                <p>No videos match your search criteria. Try changing filters or add new videos.</p>
                <a href="admin_add_youtube.php" class="btn btn-primary" style="margin-top: 15px;">
                    <i class="fas fa-plus-circle"></i> Add Your First Video
                </a>
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

    // Quick profile click effect - FIXED with proper modal
    const quickProfile = document.getElementById('quickProfile');
    if (quickProfile) {
        quickProfile.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const quickProfileName = document.querySelector('.quick-profile-name')?.textContent || 'Admin';
            const quickProfileRole = document.querySelector('.quick-profile-role')?.textContent || 'Admin';
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
    
    // Search functionality for the search bar
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value;
                const url = new URL(window.location.href);
                url.searchParams.set('search', searchTerm);
                window.location.href = url.toString();
            }
        });
    }
    
    // Animate cards on load
    const videoCards = document.querySelectorAll('.video-card');
    videoCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
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

// Delete confirmation with SweetAlert
function confirmDelete(event, url) {
    if (event) event.preventDefault();
    
    Swal.fire({
        title: 'Are you sure?',
        text: "This video will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#27ae60',
        confirmButtonText: 'Yes, delete it!',
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
</script>
</body>
</html>
