<?php
// ===========================================
// ADMIN PROFILE EDIT - WITH PROPER AUTHENTICATION
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

// Store in array for easy access
$admin_profile = [
    'first_name' => $admin_info['first_name'] ?? '',
    'last_name' => $admin_info['last_name'] ?? '',
    'email' => $admin_email,
    'admin_type' => $admin_type,
    'phone' => $admin_info['phone'] ?? '',
    'photo' => $admin_info['photo'] ?? ''
];
$admin_notifications_data = admin_notifications_prepare($conn, ['id' => (int)$admin_id], 12);

// ===========================================
// PHOTO PATH FUNCTION - DIRECTLY IN THIS FILE
// ===========================================

/**
 * Get admin photo URL for display
 * @param string $photo Photo path from database
 * @return string Full URL to photo or empty string
 */
function getAdminPhotoUrl($photo) {
    if (empty($photo)) {
        return '';
    }
    
    // Define base URL
    $base_url = '/ruchi_classes_form/';
    
    // Clean the photo path
    $photo = ltrim($photo, '/');
    
    // Array of possible paths to check
    $possible_paths = [
        // Relative to current file
        '../' . $photo,
        'uploads/admin_photos/' . basename($photo),
        '../admin/uploads/admin_photos/' . basename($photo),
        
        // Absolute paths
        $_SERVER['DOCUMENT_ROOT'] . $base_url . $photo,
        $_SERVER['DOCUMENT_ROOT'] . $base_url . 'admin/' . $photo,
        $_SERVER['DOCUMENT_ROOT'] . $base_url . 'uploads/admin_photos/' . basename($photo),
        
        // Direct path
        $photo
    ];
    
    // Check each path
    foreach ($possible_paths as $path) {
        // Remove any double slashes
        $path = str_replace('//', '/', $path);
        
        if (file_exists($path)) {
            // Return appropriate URL based on path
            if (strpos($path, $_SERVER['DOCUMENT_ROOT']) === 0) {
                // Convert absolute path to URL
                return str_replace($_SERVER['DOCUMENT_ROOT'], '', $path);
            } else {
                // Return as is for relative paths
                return $path;
            }
        }
    }
    
    return '';
}

// Generate CSRF token
$csrf_token = generateAdminCSRFToken();

// ===========================================
// DATABASE CONNECTION
// ===========================================
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
}

// Log profile access
try {
    logAdminActivity('PROFILE_ACCESS', 'Accessed profile edit page');
} catch (Exception $e) {
    // Silently handle logging errors
}

// Initialize messages
$error = '';
$success = '';

// ===========================================
// HANDLE PROFILE UPDATE
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Validate CSRF token
    if (!validateAdminCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Security token invalid or expired. Please refresh the page.";
        logAdminActivity('CSRF_FAILURE', 'Failed CSRF validation on profile update');
    } else {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $phone = trim($_POST['phone']);
        $name = $first_name . ' ' . $last_name;
        
        // Validate inputs
        if (empty($first_name) || empty($last_name) || empty($phone)) {
            $error = "All fields are required!";
        } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
            $error = "Please enter a valid 10-digit phone number!";
        } else {
            // Handle photo upload
            $photo = $admin_profile['photo']; // Keep existing photo by default
            
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                $file_type = $_FILES['photo']['type'];
                $file_size = $_FILES['photo']['size'];
                $max_size = 2 * 1024 * 1024; // 2MB
                
                if ($file_size > $max_size) {
                    $error = "File size must be less than 2MB. Your file is " . round($file_size / (1024 * 1024), 2) . "MB";
                } elseif (in_array($file_type, $allowed_types)) {
                    $upload_dir = 'uploads/admin_photos/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Delete old photo if exists
                    if (!empty($admin_profile['photo'])) {
                        // Check multiple possible locations for old photo
                        $old_photo_paths = [
                            '../' . $admin_profile['photo'],
                            'uploads/admin_photos/' . basename($admin_profile['photo']),
                            $_SERVER['DOCUMENT_ROOT'] . '/ruchi_classes_form/' . $admin_profile['photo'],
                            $_SERVER['DOCUMENT_ROOT'] . '/ruchi_classes_form/admin/' . $admin_profile['photo']
                        ];
                        
                        foreach ($old_photo_paths as $old_path) {
                            if (file_exists($old_path)) {
                                unlink($old_path);
                                break;
                            }
                        }
                    }
                    
                    $file_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                    $file_name = 'admin_' . $admin_id . '_' . time() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $file_path)) {
                        // Store the path in database
                        $photo = 'admin/uploads/admin_photos/' . $file_name;  // ✅ Correct - includes admin_photos/
                    } else {
                        $error = "Error uploading file. Please try again.";
                    }
                } else {
                    $error = "Invalid file type. Only JPG, PNG, GIF are allowed.";
                }
            }
            
            // If no error, update profile
            if (empty($error)) {
                $stmt = $conn->prepare("UPDATE admins SET name = ?, first_name = ?, last_name = ?, phone = ?, photo = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $name, $first_name, $last_name, $phone, $photo, $admin_id);
                
                if ($stmt->execute()) {
                    // Update session variables
                    $_SESSION['admin_name'] = $name;
                    $_SESSION['admin_first_name'] = $first_name;
                    $_SESSION['admin_last_name'] = $last_name;
                    $_SESSION['admin_phone'] = $phone;
                    $_SESSION['admin_photo'] = $photo;
                    
                    // Update admin_profile array
                    $admin_profile['first_name'] = $first_name;
                    $admin_profile['last_name'] = $last_name;
                    $admin_profile['phone'] = $phone;
                    $admin_profile['photo'] = $photo;
                    
                    $success = "Profile updated successfully!";
                    logAdminActivity('PROFILE_UPDATED', "Admin profile updated");
                } else {
                    $error = "Error updating profile: " . $conn->error;
                    logAdminActivity('PROFILE_UPDATE_ERROR', "Failed to update profile");
                }
            }
        }
    }
}

// ===========================================
// FETCH STATISTICS FOR SIDEBAR
// ===========================================

// Teacher stats for sidebar
$total_teachers_count = 0;
$active_teachers = 0;

$teachers_count_result = $conn->query("SELECT COUNT(*) as count FROM teachers");
if ($teachers_count_result) {
    $total_teachers_count = $teachers_count_result->fetch_assoc()['count'];
}

$active_teachers_result = $conn->query("SELECT COUNT(*) as count FROM teachers WHERE profile_completed = 1");
if ($active_teachers_result) {
    $active_teachers = $active_teachers_result->fetch_assoc()['count'];
}

// ===========================================
// HTML PROFILE EDIT PAGE
// ===========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Edit Profile | Ruchi Classes</title>
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
    
    /* Profile Edit Card */
    .profile-edit-card {
        background: linear-gradient(135deg, white, #fdfdfd);
        border-radius: var(--radius);
        padding: 30px;
        box-shadow: var(--shadow-lg);
        border: 2px solid white;
        transition: var(--transition);
        backdrop-filter: blur(10px);
        max-width: 800px;
        margin: 0 auto;
    }
    
    .profile-edit-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        border-color: var(--primary);
    }
    
    .profile-edit-header {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 3px solid var(--primary-light);
        position: relative;
    }
    
    .profile-edit-header::after {
        content: '';
        position: absolute;
        bottom: -3px;
        left: 0;
        width: 100px;
        height: 3px;
        background: linear-gradient(to right, var(--primary), var(--primary-dark));
        border-radius: 3px;
    }
    
    .profile-edit-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        box-shadow: 0 6px 15px rgba(39, 174, 96, 0.3);
        transition: var(--transition);
    }
    
    .profile-edit-card:hover .profile-edit-icon {
        transform: scale(1.05) rotate(5deg);
    }
    
    .profile-edit-title {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--dark);
        flex: 1;
    }
    
    .profile-edit-subtitle {
        color: var(--secondary);
        font-size: 0.95rem;
        margin-top: 5px;
        font-weight: 500;
    }
    
    /* Photo Upload Section */
    .photo-upload-section {
        text-align: center;
        margin-bottom: 30px;
        padding: 25px;
        background: #f8fafc;
        border-radius: var(--radius);
        border: 2px dashed var(--primary-light);
        transition: var(--transition);
    }
    
    .photo-upload-section:hover {
        border-color: var(--primary);
        background: white;
    }
    
    .preview-image {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid var(--primary);
        margin: 0 auto 20px;
        box-shadow: 0 10px 25px rgba(39, 174, 96, 0.3);
        transition: var(--transition);
        display: block;
    }
    
    .preview-image:hover {
        transform: scale(1.05);
        box-shadow: 0 15px 35px rgba(39, 174, 96, 0.4);
    }
    
    .upload-btn-wrapper {
        position: relative;
        display: inline-block;
    }
    
    .upload-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 12px 30px;
        background: white;
        border: 2px solid var(--primary);
        border-radius: 12px;
        color: var(--primary);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        box-shadow: 0 4px 10px rgba(39, 174, 96, 0.1);
    }
    
    .upload-btn:hover {
        background: var(--primary);
        color: white;
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(39, 174, 96, 0.3);
    }
    
    .upload-btn i {
        font-size: 1.1rem;
    }
    
    #photoInput {
        display: none;
    }
    
    .photo-hint {
        font-size: 0.85rem;
        color: var(--secondary);
        margin-top: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .photo-hint i {
        color: var(--primary);
    }
    
    /* Form Styles */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group.full-width {
        grid-column: span 2;
    }
    
    .form-label {
        display: block;
        margin-bottom: 10px;
        font-weight: 700;
        color: var(--dark);
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-label i {
        color: var(--primary);
        font-size: 0.9rem;
    }
    
    .form-input {
        width: 100%;
        padding: 14px 18px;
        border: 2px solid #e0e6ed;
        border-radius: 10px;
        font-size: 0.95rem;
        transition: var(--transition);
        background: #f8fafc;
        font-family: 'Inter', sans-serif;
    }
    
    .form-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
        background: white;
    }
    
    .form-input[readonly] {
        background: #f1f5f9;
        cursor: not-allowed;
        color: var(--secondary);
    }
    
    .input-hint {
        font-size: 0.85rem;
        color: var(--secondary);
        margin-top: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .input-hint i {
        color: var(--primary);
        font-size: 0.8rem;
    }
    
    /* Button Styles */
    .btn {
        padding: 14px 28px;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 12px;
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
        background: white;
        color: var(--primary);
        border: 2px solid var(--primary);
        box-shadow: 0 4px 10px rgba(39, 174, 96, 0.1);
    }
    
    .btn-secondary:hover {
        background: var(--primary-light);
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(39, 174, 96, 0.2);
    }
    
    .button-group {
        display: flex;
        gap: 15px;
        margin-top: 30px;
        flex-wrap: wrap;
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
        
        .profile-edit-card {
            padding: 20px;
        }
        
        .profile-edit-header {
            flex-direction: column;
            text-align: center;
            gap: 15px;
        }
        
        .profile-edit-icon {
            width: 50px;
            height: 50px;
            font-size: 1.2rem;
        }
        
        .profile-edit-title {
            font-size: 1.3rem;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
            gap: 0;
        }
        
        .form-group.full-width {
            grid-column: span 1;
        }
        
        .preview-image {
            width: 120px;
            height: 120px;
        }
        
        .upload-btn {
            padding: 10px 25px;
            font-size: 0.9rem;
        }
        
        .button-group {
            flex-direction: column;
        }
        
        .btn {
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
        
        .profile-edit-card {
            padding: 15px;
        }
        
        .profile-edit-title {
            font-size: 1.2rem;
        }
        
        .form-input {
            padding: 12px 15px;
            font-size: 0.9rem;
        }
        
        .preview-image {
            width: 100px;
            height: 100px;
        }
        
        .photo-hint {
            font-size: 0.8rem;
            flex-wrap: wrap;
        }
        
        .alert {
            padding: 15px;
            font-size: 0.9rem;
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
    
    /* Smooth scrolling */
    .sidebar, .main-content {
        scroll-behavior: smooth;
    }
    
    /* Fade in animation */
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
                $photo_url = getAdminPhotoUrl($admin_profile['photo'] ?? '');
                if (!empty($photo_url)): 
                ?>
                    <img src="<?php echo htmlspecialchars($photo_url); ?>" alt="Profile Photo">
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
                    <span class="meta-value"><?php echo $total_teachers_count; ?></span>
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
                <li><a href="#" class="active"><i class="fas fa-user-edit"></i> Edit Profile</a></li>
                <li><a href="manage_teacher.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="admission_report.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="admin_manage_student_edit.php"><i class="fas fa-edit"></i> Manage Students</a></li>
                <li><a href="admin_assign_students.php"><i class="fas fa-users"></i> Assign Students</a></li>
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
                <h1><i class="fas fa-user-edit"></i> Edit Profile</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" name="search" placeholder="Search..." autocomplete="off">
                </div>

                <?php admin_notifications_render_widget($admin_notifications_data); ?>
                
                <div class="user-quick-profile" id="quickProfile">
                    <div class="quick-profile-img">
                        <?php 
                        $photo_url = getAdminPhotoUrl($admin_profile['photo'] ?? '');
                        if (!empty($photo_url)): 
                        ?>
                            <img src="<?php echo htmlspecialchars($photo_url); ?>" alt="Profile">
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
                            <?php echo htmlspecialchars(trim((string)($admin_profile['first_name'] ?? '') . ' ' . (string)($admin_profile['last_name'] ?? '')) ?: 'Administrator', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="quick-profile-role">
                            <?php echo (($admin_profile['admin_type'] ?? '') === 'first_admin') ? 'Super Admin' : 'Administrator'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Alerts -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Profile Edit Form -->
        <div class="profile-edit-card">
            <div class="profile-edit-header">
                <div class="profile-edit-icon">
                    <i class="fas fa-user-cog"></i>
                </div>
                <div>
                    <h2 class="profile-edit-title">Edit Your Profile</h2>
                    <p class="profile-edit-subtitle">Update your personal information and profile photo</p>
                </div>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="profileForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Photo Upload Section - FIXED PHOTO DISPLAY -->
                <div class="photo-upload-section">
                    <img id="profilePreview" 
                         src="<?php 
                            $photo_url = getAdminPhotoUrl($admin_profile['photo'] ?? '');
                            if (!empty($photo_url)): 
                                echo htmlspecialchars($photo_url);
                            else: 
                                echo 'https://ui-avatars.com/api/?name=' . urlencode($admin_profile['first_name'] . ' ' . $admin_profile['last_name']) . '&size=150&background=27ae60&color=fff&rounded=true';
                            endif; 
                         ?>" 
                         class="preview-image" alt="Profile Preview">
                    
                    <div class="upload-btn-wrapper">
                        <label for="photoInput" class="upload-btn">
                            <i class="fas fa-camera"></i> Change Profile Photo
                        </label>
                        <input type="file" id="photoInput" name="photo" accept="image/*">
                    </div>
                    
                    <div class="photo-hint">
                        <i class="fas fa-info-circle"></i>
                        <span>Max size: 2MB | Allowed: JPG, PNG, GIF</span>
                    </div>
                </div>
                
                <!-- Form Fields -->
                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name" class="form-label">
                            <i class="fas fa-user"></i> First Name *
                        </label>
                        <input type="text" id="first_name" name="first_name" class="form-input" 
                               value="<?php echo htmlspecialchars($admin_profile['first_name']); ?>" 
                               required maxlength="100" placeholder="Enter your first name">
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name" class="form-label">
                            <i class="fas fa-user"></i> Last Name *
                        </label>
                        <input type="text" id="last_name" name="last_name" class="form-input" 
                               value="<?php echo htmlspecialchars($admin_profile['last_name']); ?>" 
                               required maxlength="100" placeholder="Enter your last name">
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <input type="email" id="email" class="form-input" 
                               value="<?php echo htmlspecialchars($admin_profile['email']); ?>" 
                               readonly disabled>
                        <div class="input-hint">
                            <i class="fas fa-lock"></i>
                            <span>Email address cannot be changed</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone" class="form-label">
                            <i class="fas fa-phone"></i> Phone Number *
                        </label>
                        <input type="tel" id="phone" name="phone" class="form-input" 
                               value="<?php echo htmlspecialchars($admin_profile['phone']); ?>" 
                               required pattern="[0-9]{10}" maxlength="10" 
                               placeholder="Enter 10-digit mobile number">
                        <div class="input-hint">
                            <i class="fas fa-info-circle"></i>
                            <span>Enter 10-digit number without country code</span>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="admin_type" class="form-label">
                            <i class="fas fa-user-tag"></i> Admin Type
                        </label>
                        <input type="text" id="admin_type" class="form-input" 
                               value="<?php 
                                   if (isset($admin_profile['admin_type']) && $admin_profile['admin_type'] == 'first_admin') {
                                       echo 'Super Admin';
                                   } else {
                                       echo 'Administrator';
                                   }
                               ?>" 
                               readonly disabled>
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                    <a href="admin_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </form>
        </div>
    </main>
</div>

<!-- SweetAlert2 -->
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

// Image preview
document.getElementById('photoInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        // Validate file size
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire({
                icon: 'error',
                title: 'File Too Large',
                text: 'File size must be less than 2MB. Your file is ' + (file.size / (1024 * 1024)).toFixed(2) + 'MB',
                confirmButtonColor: '#27ae60',
                background: '#f9fafb'
            });
            this.value = '';
            return;
        }
        
        // Validate file type
        const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!validTypes.includes(file.type)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid File Type',
                text: 'Please select a valid image file (JPG, PNG, GIF)',
                confirmButtonColor: '#27ae60',
                background: '#f9fafb'
            });
            this.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profilePreview').src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
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
        
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.remove();
            }
        });
    });
}

// Form validation
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const firstName = document.getElementById('first_name').value.trim();
    const lastName = document.getElementById('last_name').value.trim();
    const phone = document.getElementById('phone').value.trim();
    
    if (!firstName) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'First Name Required',
            text: 'Please enter your first name',
            confirmButtonColor: '#27ae60',
            background: '#f9fafb'
        });
        return;
    }
    
    if (!lastName) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Last Name Required',
            text: 'Please enter your last name',
            confirmButtonColor: '#27ae60',
            background: '#f9fafb'
        });
        return;
    }
    
    if (!phone) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Phone Number Required',
            text: 'Please enter your phone number',
            confirmButtonColor: '#27ae60',
            background: '#f9fafb'
        });
        return;
    }
    
    if (!/^\d{10}$/.test(phone)) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Invalid Phone Number',
            text: 'Please enter a valid 10-digit phone number',
            confirmButtonColor: '#27ae60',
            background: '#f9fafb'
        });
        return;
    }
});

// Animate elements on load
document.addEventListener('DOMContentLoaded', function() {
    const profileCard = document.querySelector('.profile-edit-card');
    if (profileCard) {
        profileCard.style.opacity = '0';
        profileCard.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            profileCard.style.transition = 'all 0.5s ease';
            profileCard.style.opacity = '1';
            profileCard.style.transform = 'translateY(0)';
        }, 100);
    }
    
    // Animate sidebar profile card
    const sidebarProfile = document.querySelector('.profile-card');
    if (sidebarProfile) {
        sidebarProfile.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        sidebarProfile.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
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
        header.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1)';
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
});

// Search functionality (can be expanded)
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        // You can implement search functionality here if needed
        console.log('Searching for:', this.value);
    });
}
</script>

</body>
</html>
<?php
// Clean output buffer
ob_end_flush();

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>
