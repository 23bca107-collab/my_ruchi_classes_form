<?php
// ===========================================
// MANAGE TEACHERS - WITH PROPER AUTHENTICATION
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

// Generate CSRF token
$csrf_token = generateAdminCSRFToken();

// ===========================================
// DATABASE CONNECTION
// ===========================================
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
}

// Check if connection was successful
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log teacher management access
if (function_exists('logAdminActivity')) {
    logAdminActivity('TEACHER_MANAGEMENT', 'Accessed teacher management');
}

// Check admin permissions for teacher actions
$can_manage_teachers = checkAdminPermission('manage_users');
$can_delete_teachers = ($admin_type === 'first_admin');

// Initialize messages
$error = '';
$success = '';

function normalizeTeacherEmail($email) {
    $email = mb_strtolower(trim((string) $email));
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);

    if ($email === '') {
        return [
            'valid' => false,
            'email' => '',
            'auto_corrected' => false,
            'correction_message' => '',
            'message' => 'Email address is required.'
        ];
    }

    if (substr_count($email, '@') !== 1) {
        return [
            'valid' => false,
            'email' => $email,
            'auto_corrected' => false,
            'correction_message' => '',
            'message' => 'Please enter one valid email address.'
        ];
    }

    list($localPart, $domainPart) = explode('@', $email, 2);
    $localPart = trim($localPart, ". \t\n\r\0\x0B");
    $domainPart = trim($domainPart);

    $domainCorrections = [
        'gmail' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.cm' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'gmailcom' => 'gmail.com',
        'gmail,com' => 'gmail.com',
        'gmail.om' => 'gmail.com',
        'gmailcom.' => 'gmail.com',
        'yaho.com' => 'yahoo.com',
        'yahho.com' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'hotmail.con' => 'hotmail.com',
        'hotmal.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'outlok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com'
    ];

    $originalDomain = $domainPart;
    $compactDomain = str_replace([' ', ','], ['', '.'], $domainPart);
    $compactDomain = preg_replace('/\.{2,}/', '.', $compactDomain);
    $domainLower = mb_strtolower($compactDomain);

    if (isset($domainCorrections[$domainLower])) {
        $domainPart = $domainCorrections[$domainLower];
    } elseif (preg_match('/^gmail\.(co|cim|comm|con)$/', $domainLower)) {
        $domainPart = 'gmail.com';
    } else {
        $domainPart = $domainLower;
    }

    $normalizedEmail = $localPart . '@' . $domainPart;
    $autoCorrected = $normalizedEmail !== $email;
    $correctionMessage = $autoCorrected
        ? "Email corrected from <strong>" . htmlspecialchars($email) . "</strong> to <strong>" . htmlspecialchars($normalizedEmail) . "</strong>."
        : '';

    if ($originalDomain !== $domainPart && !$autoCorrected) {
        $correctionMessage = "Domain corrected to <strong>" . htmlspecialchars($domainPart) . "</strong>.";
    }

    if (
        $localPart === '' ||
        $domainPart === '' ||
        !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)
    ) {
        return [
            'valid' => false,
            'email' => $normalizedEmail,
            'auto_corrected' => $autoCorrected,
            'correction_message' => $correctionMessage,
            'message' => 'Please enter a correct email address like teacher@gmail.com.'
        ];
    }

    return [
        'valid' => true,
        'email' => $normalizedEmail,
        'auto_corrected' => $autoCorrected,
        'correction_message' => $correctionMessage,
        'message' => 'Email looks good.'
    ];
}

function findTeacherByEmail($conn, $email) {
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM teachers WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?: null;
}

function redirectToManageTeacher() {
    header('Location: manage_teacher.php');
    exit;
}

// ===========================================
// HANDLE TEACHER MANAGEMENT ACTIONS
// ===========================================

// 1. Handle adding new teacher (No password generation - just email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher'])) {
    // Validate CSRF token
    if (!validateAdminCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Security token invalid or expired. Please refresh the page.";
        logAdminActivity('CSRF_FAILURE', 'Failed CSRF validation on teacher add');
    } elseif (!$can_manage_teachers) {
        $error = "You don't have permission to add teachers!";
        logAdminActivity('PERMISSION_DENIED', 'Attempted to add teacher without permission');
    } else {
        $raw_email = trim($_POST['email'] ?? '');
        $normalized = normalizeTeacherEmail($raw_email);
        $email = $normalized['email'];

        if (!$normalized['valid']) {
            $error = $normalized['message'];
        } else {
            $existingTeacher = findTeacherByEmail($conn, $email);

            if ($existingTeacher) {
                $teacherName = trim(($existingTeacher['first_name'] ?? '') . ' ' . ($existingTeacher['last_name'] ?? ''));
                $error = "This email is already registered" . ($teacherName !== '' ? " for <strong>" . htmlspecialchars($teacherName) . "</strong>" : '') . ".";
                logAdminActivity('TEACHER_ADD_DUPLICATE', "Attempted to add duplicate teacher: $email");
            } else {
                // Insert teacher with no password so they can set it on first login
                // Also generate a setup token for the email invitation
                $setup_token = bin2hex(random_bytes(32));
                $token_expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
                $insert_sql = "INSERT INTO teachers (email, password, status, created_at, setup_token, token_expiry) VALUES (?, NULL, 'active', NOW(), ?, ?)";
                $stmt2 = $conn->prepare($insert_sql);
                $stmt2->bind_param("sss", $email, $setup_token, $token_expiry);
                
                if ($stmt2->execute()) {
                    $teacher_id = $conn->insert_id;
                    
                    // Send invitation email
                    $invitation_sent = sendTeacherInvitationEmail($email, $teacher_id, $setup_token);
                    
                    if ($invitation_sent) {
                        $success = "Teacher added successfully! An invitation email has been sent to <strong>" . htmlspecialchars($email) . "</strong> with instructions to set up their account.";
                    } else {
                        $success = "Teacher added successfully! However, the invitation email could not be sent. Please check the email configuration.";
                        logAdminActivity('TEACHER_EMAIL_FAILED', "Failed to send invitation email to: $email");
                    }
                    
                    if ($normalized['auto_corrected']) {
                        $success .= ' ' . $normalized['correction_message'];
                    }

                    logAdminActivity('TEACHER_ADDED', "New teacher: $email (ID: $teacher_id)");
                    
                    // Store in session for SweetAlert
                    $_SESSION['teacher_added'] = true;
                    $_SESSION['teacher_email'] = $email;
                    $_SESSION['teacher_id'] = $teacher_id;
                    $_SESSION['success_message'] = $success;
                    redirectToManageTeacher();
                    
                } else {
                    $error = "Error adding teacher: " . $conn->error;
                    logAdminActivity('TEACHER_ADD_ERROR', "Failed to add teacher: $email - " . $conn->error);
                }
                $stmt2->close();
            }
        }
    }
}

// 2. Handle delete teacher
if (isset($_GET['delete_id'])) {
    $teacher_id = intval($_GET['delete_id']);
    
    // Validate permissions
    if (!$can_delete_teachers) {
        $error = "Only Super Admin can delete teachers!";
        logAdminActivity('PERMISSION_DENIED', "Attempted to delete teacher ID: $teacher_id without permission");
    } elseif ($teacher_id <= 0) {
        $error = "Invalid teacher ID!";
    } else {
        // Get teacher email before deletion for logging
        $stmt = $conn->prepare("SELECT email FROM teachers WHERE id = ?");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $teacher_data = $result->fetch_assoc();
        
        if (!$teacher_data) {
            $error = "Teacher not found!";
        } else {
            $teacher_email = $teacher_data['email'];
            
            $delete_stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
            $delete_stmt->bind_param("i", $teacher_id);
            
            if ($delete_stmt->execute()) {
                $success = "Teacher <strong>" . htmlspecialchars($teacher_email) . "</strong> deleted successfully!";
                logAdminActivity('TEACHER_DELETED', "Deleted teacher: $teacher_email");
                
                // Store in session for SweetAlert
                $_SESSION['teacher_deleted'] = true;
                $_SESSION['deleted_email'] = $teacher_email;
                $_SESSION['success_message'] = $success;
                redirectToManageTeacher();
            } else {
                $error = "Error deleting teacher: " . $conn->error;
                logAdminActivity('TEACHER_DELETE_ERROR', "Failed to delete teacher: $teacher_email");
            }
            $delete_stmt->close();
        }
        $stmt->close();
    }
}

// 3. Handle toggle teacher status
if (isset($_GET['toggle_id'])) {
    $teacher_id = intval($_GET['toggle_id']);
    
    // Validate permissions
    if (!$can_manage_teachers) {
        $error = "You don't have permission to modify teacher status!";
        logAdminActivity('PERMISSION_DENIED', "Attempted to toggle teacher ID: $teacher_id status without permission");
    } elseif ($teacher_id <= 0) {
        $error = "Invalid teacher ID!";
    } else {
        // Always read the current status from the database so the toggle stays reliable.
        $stmt = $conn->prepare("SELECT email, status FROM teachers WHERE id = ?");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $teacher_data = $result->fetch_assoc();
        
        if (!$teacher_data) {
            $error = "Teacher not found!";
        } else {
            $teacher_email = $teacher_data['email'];
            $current_status = $teacher_data['status'] ?? 'inactive';
            $new_status = ($current_status === 'active') ? 'inactive' : 'active';
            
            $update_stmt = $conn->prepare("UPDATE teachers SET status = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_status, $teacher_id);
            
            if ($update_stmt->execute()) {
                $status_text = $new_status === 'active' ? 'activated' : 'deactivated';
                $success = "Teacher <strong>" . htmlspecialchars($teacher_email) . "</strong> " . $status_text . " successfully!";
                logAdminActivity('TEACHER_STATUS_CHANGED', "Teacher $teacher_email status changed to $new_status");
                
                // Store in session for SweetAlert
                $_SESSION['status_changed'] = true;
                $_SESSION['status_email'] = $teacher_email;
                $_SESSION['new_status'] = $new_status;
                $_SESSION['success_message'] = $success;
                redirectToManageTeacher();
            } else {
                $error = "Error updating teacher status: " . $conn->error;
                logAdminActivity('TEACHER_STATUS_ERROR', "Failed to update teacher $teacher_email status");
            }
            $update_stmt->close();
        }
        $stmt->close();
    }
}

/**
 * Send invitation email to teacher with setup link
 */
function sendTeacherInvitationEmail($email, $teacher_id, $setup_token) {
    $subject = "Welcome to Ruchi Classes - Complete Your Registration";
    
    // Setup link
    $setup_link = "http://" . $_SERVER['HTTP_HOST'] . "/teacher_setup.php?token=" . $setup_token . "&email=" . urlencode($email);
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
            .header { background: #27ae60; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { padding: 20px; }
            .button { display: inline-block; padding: 12px 24px; background: #27ae60; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { font-size: 12px; color: #777; text-align: center; padding: 20px; border-top: 1px solid #eee; }
        </style>
        <link rel="stylesheet" href="admin_nav_cards.css">
</head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Welcome to Ruchi Classes</h2>
            </div>
            <div class='content'>
                <p>Dear Teacher,</p>
                <p>You have been added as a teacher in the Ruchi Classes management system.</p>
                <p>Please click the button below to complete your registration and set up your password:</p>
                <p style='text-align: center;'>
                    <a href='{$setup_link}' class='button' style='background: #27ae60; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px;'>Complete Registration</a>
                </p>
                <p>Or copy and paste this link in your browser:</p>
                <p><a href='{$setup_link}'>{$setup_link}</a></p>
                <p>This link will expire in 7 days. If you did not request this invitation, please ignore this email.</p>
                <p>Best regards,<br>Ruchi Classes Administration</p>
            </div>
            <div class='footer'>
                <p>This is an automated message, please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Ruchi Classes <noreply@ruchiclasses.com>" . "\r\n";
    
    return mail($email, $subject, $message, $headers);
}

// ===========================================
// FETCH TEACHER DATA
// ===========================================

// Fetch all teachers with all fields
$result = $conn->query("SELECT id, email, first_name, last_name, mobile, subject, password, status, created_at, profile_completed, setup_token, token_expiry FROM teachers ORDER BY id DESC");

if (!$result) {
    error_log("Error fetching teachers: " . $conn->error);
    $result = false;
}

// Get stats for sidebar and cards
$total_teachers = 0;
$active_teachers = 0;
$inactive_teachers = 0;
$pending_setup = 0;

$stats_query = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN password IS NULL OR password = '' THEN 1 ELSE 0 END) as pending_setup
    FROM teachers");

if ($stats_query) {
    $stats = $stats_query->fetch_assoc();
    $total_teachers = $stats['total'] ?? 0;
    $active_teachers = $stats['active'] ?? 0;
    $inactive_teachers = $stats['inactive'] ?? 0;
    $pending_setup = $stats['pending_setup'] ?? 0;
}

// Function to safely format date
function safeDateFormat($date, $format = 'M d, Y') {
    if (empty($date) || $date === null) {
        return 'Not set';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false || $timestamp === -1) {
        return 'Invalid date';
    }
    return date($format, $timestamp);
}

$flash_success_message = $success !== '' ? $success : ($_SESSION['success_message'] ?? '');
$flash_error_message = $error !== '' ? $error : ($_SESSION['error_message'] ?? '');

// ===========================================
// HTML TEMPLATE WITH TAILWIND CSS
// ===========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Manage Teachers | Ruchi Classes</title>
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
    
    /* Alert Styles - Hidden by default, using SweetAlert instead */
    .alert {
        display: none;
    }
    
    /* Stats Grid - Modern Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: linear-gradient(135deg, white, #fdfdfd);
        border-radius: var(--radius);
        padding: 25px;
        box-shadow: var(--shadow-lg);
        transition: var(--transition);
        border: 2px solid white;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(10px);
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(39, 174, 96, 0.05), rgba(39, 174, 96, 0.02));
        z-index: 1;
    }
    
    .stat-card:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        border-color: var(--primary);
    }
    
    .stat-card::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        background: linear-gradient(to bottom, var(--primary), var(--primary-dark));
    }
    
    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        position: relative;
        z-index: 2;
    }
    
    .stat-icon {
        width: 55px;
        height: 55px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        color: white;
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        transition: var(--transition);
    }
    
    .stat-card:hover .stat-icon {
        transform: scale(1.1) rotate(5deg);
    }
    
    .stat-card:nth-child(1) .stat-icon {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }
    
    .stat-card:nth-child(2) .stat-icon {
        background: linear-gradient(135deg, #2ecc71, #27ae60);
    }
    
    .stat-card:nth-child(3) .stat-icon {
        background: linear-gradient(135deg, #f39c12, #e67e22);
    }
    
    .stat-card:nth-child(4) .stat-icon {
        background: linear-gradient(135deg, #3498db, #2980b9);
    }
    
    .stat-value {
        font-size: 2.2rem;
        font-weight: 900;
        color: var(--dark);
        margin-bottom: 8px;
        line-height: 1;
        position: relative;
        z-index: 2;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    
    .stat-label {
        color: var(--secondary);
        font-size: 0.95rem;
        font-weight: 600;
        position: relative;
        z-index: 2;
    }
    
    /* Form Section */
    .form-section {
        background: linear-gradient(135deg, white, #fdfdfd);
        border-radius: var(--radius);
        padding: 25px;
        box-shadow: var(--shadow-lg);
        border: 2px solid white;
        transition: var(--transition);
        backdrop-filter: blur(10px);
        margin-bottom: 25px;
    }
    
    .form-section:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
        border-color: var(--primary);
    }
    
    .section-header {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 3px solid var(--primary-light);
        position: relative;
    }
    
    .section-header::after {
        content: '';
        position: absolute;
        bottom: -3px;
        left: 0;
        width: 100px;
        height: 3px;
        background: linear-gradient(to right, var(--primary), var(--primary-dark));
        border-radius: 3px;
    }
    
    .section-icon {
        width: 55px;
        height: 55px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.4rem;
        box-shadow: 0 6px 15px rgba(39, 174, 96, 0.3);
        transition: var(--transition);
    }
    
    .form-section:hover .section-icon {
        transform: scale(1.05) rotate(5deg);
    }
    
    .section-title {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--dark);
        flex: 1;
    }
    
    .section-subtitle {
        color: var(--secondary);
        font-size: 0.9rem;
        margin-top: 5px;
        font-weight: 500;
    }
    
    .form-row {
        display: flex;
        gap: 20px;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    
    .form-group {
        flex: 1;
        min-width: 250px;
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
    
    /* Buttons */
    .btn {
        padding: 14px 24px;
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
        white-space: nowrap;
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
    
    /* Table Section */
    .table-section {
        background: linear-gradient(135deg, white, #fdfdfd);
        border-radius: var(--radius);
        padding: 25px;
        box-shadow: var(--shadow-lg);
        border: 2px solid white;
        margin-top: 25px;
        backdrop-filter: blur(10px);
        transition: var(--transition);
    }
    
    .table-section:hover {
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        border-color: var(--primary);
    }
    
    .table-container {
        overflow-x: auto;
        border-radius: 10px;
        border: 2px solid #e0e6ed;
        margin-top: 20px;
        background: white;
        -webkit-overflow-scrolling: touch;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }
    
    .table thead {
        background: linear-gradient(135deg, var(--primary-light), #ebf5e6);
    }
    
    .table th {
        padding: 18px 15px;
        text-align: left;
        font-weight: 700;
        color: var(--dark);
        border-bottom: 3px solid var(--primary);
        white-space: nowrap;
        font-size: 0.9rem;
        letter-spacing: 0.5px;
    }
    
    .table td {
        padding: 15px;
        border-bottom: 2px solid #f1f5f9;
        transition: var(--transition);
        font-size: 0.9rem;
    }
    
    .table tbody tr:hover {
        background: var(--primary-light);
        transform: scale(1.01);
    }
    
    /* Status Badges */
    .status-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        white-space: nowrap;
        transition: var(--transition);
    }
    
    .status-active {
        background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
        color: #1e8449;
        border: 2px solid #a3e4b9;
    }
    
    .status-inactive {
        background: linear-gradient(135deg, #fef5e7, #fdebd0);
        color: #b9770e;
        border: 2px solid #f8c471;
    }
    
    .status-pending-setup {
        background: linear-gradient(135deg, #fff3cd, #ffeaa7);
        color: #856404;
        border: 2px solid #ffeeba;
    }
    
    .password-status {
        padding: 6px 12px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        transition: var(--transition);
    }
    
    .password-set {
        background: linear-gradient(135deg, #d5f4e6, #c8f7d9);
        color: #1e8449;
        border: 2px solid #a3e4b9;
    }
    
    .password-not-set {
        background: linear-gradient(135deg, #fef5e7, #fdebd0);
        color: #b9770e;
        border: 2px solid #f8c471;
    }
    
    .status-badge:hover, .password-status:hover {
        transform: scale(1.05);
        box-shadow: 0 5px 12px rgba(0, 0, 0, 0.15);
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .btn-action {
        padding: 8px 14px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8rem;
        white-space: nowrap;
        border: 2px solid transparent;
    }
    
    .btn-activate {
        background: linear-gradient(135deg, var(--success), #229954);
        color: white;
        box-shadow: 0 4px 8px rgba(39, 174, 96, 0.2);
    }
    
    .btn-activate:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(39, 174, 96, 0.3);
        border-color: white;
    }

    .btn-deactivate {
        background: linear-gradient(135deg, var(--danger), #c0392b);
        color: white;
        box-shadow: 0 4px 8px rgba(231, 76, 60, 0.2);
    }
    
    .btn-deactivate:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(231, 76, 60, 0.3);
        border-color: white;
    }
    
    .btn-delete {
        background: linear-gradient(135deg, var(--danger), #c0392b);
        color: white;
        box-shadow: 0 4px 8px rgba(231, 76, 60, 0.2);
    }
    
    .btn-delete:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(231, 76, 60, 0.3);
        border-color: white;
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
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
    }
    
    .empty-state i {
        font-size: 3rem;
        color: #bdc3c7;
        margin-bottom: 15px;
        opacity: 0.5;
    }
    
    .empty-state p {
        color: var(--secondary);
        font-size: 1rem;
        font-weight: 600;
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
        
        .stats-grid {
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
        
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .stat-card {
            padding: 20px;
        }
        
        .stat-value {
            font-size: 2rem;
        }
        
        .form-row {
            flex-direction: column;
        }
        
        .form-group {
            min-width: 100%;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
            padding: 15px;
        }
        
        .table-section {
            padding: 20px;
        }
        
        .table th,
        .table td {
            padding: 12px 10px;
            font-size: 0.85rem;
        }
        
        .logo-container {
            padding: 20px 15px;
        }
        
        .logo-img {
            width: 70px;
            height: 70px;
        }
        
        .logo-text h2 {
            font-size: 1.4rem;
        }
        
        .section-header {
            flex-direction: column;
            text-align: center;
            gap: 15px;
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
        }
        
        .section-title {
            font-size: 1.2rem;
        }
        
        .action-buttons {
            flex-direction: column;
        }
        
        .btn-action {
            width: 100%;
            justify-content: center;
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
        
        .stat-icon {
            width: 50px;
            height: 50px;
            font-size: 1.4rem;
        }
        
        .stat-value {
            font-size: 1.8rem;
        }
        
        .table th,
        .table td {
            padding: 10px 8px;
            font-size: 0.8rem;
        }
        
        .status-badge,
        .password-status {
            font-size: 0.75rem;
            padding: 6px 10px;
        }
        
        .btn-action {
            font-size: 0.75rem;
            padding: 6px 10px;
        }
        
        .empty-state i {
            font-size: 2.5rem;
        }
        
        .empty-state p {
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
        
        .stats-grid {
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
    
    /* Prevent flash on reload */
    html {
        scroll-behavior: smooth;
    }
    
    /* Add smooth transitions for main content */
    .main-content {
        scroll-behavior: smooth;
    }
    
    /* Add this to prevent content flash */
    .dashboard-container {
        opacity: 0;
        animation: fadeInDashboard 0.5s ease forwards;
    }
    
    @keyframes fadeInDashboard {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    /* Invitation info styling */
    .invitation-info {
        font-size: 0.75rem;
        color: var(--secondary);
        margin-top: 4px;
        display: block;
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
                    <span class="meta-value"><?php echo $total_teachers; ?></span>
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
                <li><a href="manage_teacher.php" class="active"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="admission_report.php"><i class="fas fa-user-graduate"></i> Students</a></li>
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
                <h1><i class="fas fa-chalkboard-teacher"></i> Manage Teachers</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search teachers..." autocomplete="off">
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
                                // Show first letter of name or initial
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

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total_teachers; ?></div>
                <div class="stat-label">Total Teachers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $active_teachers; ?></div>
                <div class="stat-label">Active Teachers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $inactive_teachers; ?></div>
                <div class="stat-label">Inactive Teachers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $pending_setup; ?></div>
                <div class="stat-label">Pending Setup</div>
            </div>
        </div>

        <!-- Add Teacher Form -->
        <section class="form-section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div>
                    <h2 class="section-title">Invite New Teacher</h2>
                    <p class="section-subtitle">Teacher will receive an email to set up their password</p>
                </div>
            </div>
            
            <form method="POST" id="addTeacherForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="teacher_email" class="form-label"><i class="fas fa-envelope"></i> Teacher's Email Address</label>
                        <input
                            type="email"
                            id="teacher_email"
                            name="email"
                            class="form-input"
                            placeholder="teacher@example.com"
                            required
                            autocomplete="off"
                            maxlength="254"
                            pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$"
                            title="Please enter a valid email address (e.g., teacher@example.com)"
                        >
                        <div id="emailValidationMessage" style="display:none; margin-top:8px; font-size:0.85rem;"></div>
                        <small style="color: var(--secondary); font-size: 0.8rem; display: block; margin-top: 8px;">
                            <i class="fas fa-info-circle"></i> An invitation email will be sent with a link to set up password
                        </small>
                    </div>
                    <button type="submit" name="add_teacher" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Invitation
                    </button>
                </div>
            </form>
        </section>

        <!-- Teachers Table -->
        <section class="table-section">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <h2 class="section-title">Teacher List</h2>
                    <p class="section-subtitle">Manage and monitor all registered teachers</p>
                </div>
            </div>
            
            <div class="table-container">
                <table class="table" id="teachersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Invitation</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td data-label="ID"><strong>#<?= $row['id'] ?></strong></td>
                                <td data-label="Email">
                                    <i class="fas fa-envelope" style="color: var(--primary); margin-right: 10px;"></i>
                                    <strong><?= htmlspecialchars($row['email']) ?></strong>
                                </td>
                                <td data-label="Name">
                                    <?php 
                                        $name = trim($row['first_name'] . ' ' . $row['last_name']);
                                        if ($name): ?>
                                            <span style="font-weight: 700; color: var(--dark);"><?php echo htmlspecialchars($name); ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--secondary); font-style: italic;">Not set</span>
                                        <?php endif; ?>
                                </td>
                                <td data-label="Mobile">
                                    <?= $row['mobile'] ? htmlspecialchars($row['mobile']) : '<span style="color: #999;">-</span>' ?>
                                </td>
                                <td data-label="Subject">
                                    <?= $row['subject'] ? htmlspecialchars($row['subject']) : '<span style="color: #999;">-</span>' ?>
                                </td>
                                <td data-label="Status">
                                    <span class="status-badge status-<?= $row['status'] ?? 'inactive' ?>">
                                        <i class="fas fa-<?= ($row['status'] == 'active') ? 'check-circle' : 'clock' ?>"></i>
                                        <?= ucfirst($row['status'] ?? 'inactive') ?>
                                    </span>
                                </td>
                                <td data-label="Invitation">
                                    <?php if (empty($row['password'])): ?>
                                        <?php 
                                            $token_expired = false;
                                            if (!empty($row['token_expiry'])) {
                                                $token_expired = strtotime($row['token_expiry']) < time();
                                            }
                                            if ($token_expired): 
                                        ?>
                                            <div>
                                                <span class="status-badge status-pending-setup">
                                                    <i class="fas fa-hourglass-end"></i> Expired
                                                </span>
                                                <span class="invitation-info">
                                                    <i class="fas fa-info-circle"></i> Link expired
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <div>
                                                <span class="status-badge status-pending-setup">
                                                    <i class="fas fa-envelope"></i> Pending Setup
                                                </span>
                                                <?php if (!empty($row['token_expiry'])): ?>
                                                <span class="invitation-info">
                                                    <i class="fas fa-hourglass-half"></i> Valid until: <?php echo safeDateFormat($row['token_expiry']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="status-badge status-active">
                                            <i class="fas fa-check-double"></i> Completed
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions">
                                    <div class="action-buttons">
                                        <button class="btn-action <?= ($row['status'] == 'active') ? 'btn-deactivate' : 'btn-activate' ?>" onclick="toggleStatus(<?= $row['id'] ?>, '<?= $row['status'] ?? 'inactive' ?>', '<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-power-off"></i>
                                            <?= ($row['status'] == 'active') ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                        
                                        <?php if ($can_delete_teachers): ?>
                                        <button class="btn-action btn-delete" onclick="confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars($row['email']) ?>')">
                                            <i class="fas fa-trash"></i>
                                            Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-users-slash"></i>
                                        <p>No teachers found. Invite your first teacher using the form above.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

// Quick profile click
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

        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.remove();
            }
        });
    });
}

// Search functionality
const searchInput = document.getElementById('searchInput');
const teachersTable = document.getElementById('teachersTable');

if (searchInput && teachersTable) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = teachersTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        for (let row of rows) {
            const cells = row.getElementsByTagName('td');
            let match = false;
            
            for (let cell of cells) {
                if (cell.textContent.toLowerCase().includes(searchTerm)) {
                    match = true;
                    break;
                }
            }
            
            row.style.display = match ? '' : 'none';
        }
    });
}

if (mainContent) {
    mainContent.addEventListener('scroll', function() {
        const scrollTop = this.scrollTop;
        const header = document.querySelector('.header');

        if (!header) {
            return;
        }

        if (scrollTop > 10) {
            header.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.1)';
            header.style.background = 'rgba(255, 255, 255, 0.98)';
        } else {
            header.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1)';
            header.style.background = 'rgba(255, 255, 255, 0.95)';
        }
    });
}

window.addEventListener('resize', function() {
    if (window.innerWidth >= 1200) {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
});

window.addEventListener('beforeunload', function() {
    if (sidebar) {
        localStorage.setItem('sidebarScrollPosition', String(sidebar.scrollTop));
    }
});

// SweetAlert for delete confirmation
function confirmDelete(teacherId, teacherEmail) {
    Swal.fire({
        title: 'Are you sure?',
        html: `You are about to delete teacher <strong>${teacherEmail}</strong>. This action cannot be undone!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#27ae60',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `?delete_id=${teacherId}`;
        }
    });
}

// Function to toggle active/inactive status
function toggleStatus(teacherId, currentStatus, teacherEmail) {
    const isActive = currentStatus === 'active';
    const action = isActive ? 'Deactivate' : 'Activate';
    Swal.fire({
        title: `${action} Teacher`,
        html: `Are you sure you want to ${action.toLowerCase()} <strong>${teacherEmail}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: isActive ? '#e74c3c' : '#27ae60',
        cancelButtonColor: '#27ae60',
        confirmButtonText: `Yes, ${action}`,
        cancelButtonText: 'Cancel',
        backdrop: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            sessionStorage.setItem('manage_teacher_scroll_position', String(window.scrollY || window.pageYOffset || 0));
            window.location.href = `?toggle_id=${teacherId}`;
        }
    });
}

// Show SweetAlert for success/error messages from PHP
document.addEventListener('DOMContentLoaded', function() {
    const addTeacherForm = document.getElementById('addTeacherForm');
    const emailInput = document.getElementById('teacher_email');
    const emailValidationMessage = document.getElementById('emailValidationMessage');
    const flashSuccessMessage = <?php echo json_encode($flash_success_message); ?>;
    const flashErrorMessage = <?php echo json_encode($flash_error_message); ?>;
    const teacherScrollStorageKey = 'manage_teacher_scroll_position';

    function restoreTeacherScrollPosition() {
        const savedScrollPosition = sessionStorage.getItem(teacherScrollStorageKey);

        if (savedScrollPosition === null) {
            return;
        }

        const parsedPosition = parseInt(savedScrollPosition, 10);
        if (Number.isNaN(parsedPosition)) {
            sessionStorage.removeItem(teacherScrollStorageKey);
            return;
        }

        const previousScrollBehavior = document.documentElement.style.scrollBehavior;
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(0, parsedPosition);

        setTimeout(() => {
            window.scrollTo(0, parsedPosition);
            document.documentElement.style.scrollBehavior = previousScrollBehavior;
            sessionStorage.removeItem(teacherScrollStorageKey);
        }, 120);
    }

    restoreTeacherScrollPosition();
    window.addEventListener('load', restoreTeacherScrollPosition, { once: true });

    const savedSidebarScrollPosition = localStorage.getItem('sidebarScrollPosition');
    if (savedSidebarScrollPosition && sidebar) {
        setTimeout(() => {
            sidebar.scrollTop = parseInt(savedSidebarScrollPosition, 10);
            localStorage.removeItem('sidebarScrollPosition');
        }, 100);
    }

    if (emailInput && emailValidationMessage) {
        let validationTimeout;

        emailInput.addEventListener('input', function() {
            const email = this.value.trim();
            clearTimeout(validationTimeout);

            emailValidationMessage.style.display = 'none';

            if (!email) {
                return;
            }

            validationTimeout = setTimeout(() => {
                fetch('check_email.php?email=' + encodeURIComponent(email))
                    .then(response => response.json())
                    .then(data => {
                        emailValidationMessage.style.display = 'block';

                        if (data.exists) {
                            emailValidationMessage.style.color = '#e74c3c';
                            emailValidationMessage.innerHTML = '<i class="fas fa-ban"></i> ' + data.message;
                            return;
                        }

                        if (!data.valid) {
                            emailValidationMessage.style.color = '#e74c3c';
                            emailValidationMessage.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                            return;
                        }

                        if (data.auto_corrected && data.corrected) {
                            emailInput.value = data.corrected;
                            emailValidationMessage.style.color = '#f39c12';
                            emailValidationMessage.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> ' + data.correction_message;
                            return;
                        }

                        emailValidationMessage.style.color = '#27ae60';
                        emailValidationMessage.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
                    })
                    .catch(() => {
                        emailValidationMessage.style.display = 'none';
                    });
            }, 350);
        });
    }

    if (addTeacherForm && emailInput) {
        addTeacherForm.addEventListener('submit', function(e) {
            const email = emailInput.value.trim();

            if (!email) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Email Required',
                    text: "Please enter teacher's email address",
                    confirmButtonColor: '#27ae60',
                    allowOutsideClick: false
                });
                emailInput.focus();
                return;
            }

            if (emailValidationMessage && emailValidationMessage.textContent.toLowerCase().includes('already registered')) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Duplicate Email',
                    html: emailValidationMessage.innerHTML,
                    confirmButtonColor: '#27ae60',
                    allowOutsideClick: false
                });
                emailInput.focus();
                return;
            }

            if (!emailInput.checkValidity()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address (e.g., teacher@example.com)',
                    confirmButtonColor: '#27ae60',
                    allowOutsideClick: false
                });
                emailInput.focus();
                return;
            }
        });
    }

    if (flashSuccessMessage) {
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            html: flashSuccessMessage,
            confirmButtonColor: '#27ae60',
            confirmButtonText: 'OK'
        });
    }

    if (flashErrorMessage) {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            html: flashErrorMessage,
            confirmButtonColor: '#e74c3c',
            confirmButtonText: 'OK'
        });
    }

    // Clear session messages after displaying
    <?php
    unset($_SESSION['success_message']);
    unset($_SESSION['error_message']);
    unset($_SESSION['teacher_added']);
    unset($_SESSION['teacher_email']);
    unset($_SESSION['teacher_id']);
    unset($_SESSION['teacher_deleted']);
    unset($_SESSION['deleted_email']);
    unset($_SESSION['status_changed']);
    unset($_SESSION['status_email']);
    unset($_SESSION['new_status']);
    ?>
});
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
