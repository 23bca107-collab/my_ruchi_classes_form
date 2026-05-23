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
    exit;
}

// Get teacher info using the auth function
$teacher = getTeacherInfo();

// Update last activity
updateTeacherLastActivity();

// Generate CSRF token for forms
$csrf_token = generateTeacherCSRFToken();

// Initialize variables with default values
$class_count = 0;
$student_count = 0;
$assignments_count = 0;
$messages_count = 0;

// Check if classes table exists and get count
$table_check = $conn->query("SHOW TABLES LIKE 'classes'");
if ($table_check->num_rows > 0) {
    $classes_stmt = $conn->prepare("SELECT COUNT(*) as class_count FROM classes WHERE teacher_id=?");
    $classes_stmt->bind_param("i", $teacher['id']);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    $class_count = $classes_result->fetch_assoc()['class_count'];
}

// Check if class_enrollments table exists and get student count
$table_check = $conn->query("SHOW TABLES LIKE 'class_enrollments'");
if ($table_check->num_rows > 0) {
    $students_stmt = $conn->prepare("SELECT COUNT(DISTINCT student_id) as student_count FROM class_enrollments WHERE class_id IN (SELECT id FROM classes WHERE teacher_id=?)");
    $students_stmt->bind_param("i", $teacher['id']);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    $student_count = $students_result->fetch_assoc()['student_count'];
}

// Check if assignments table exists and get assignments
$assignments_result = false;
$table_check = $conn->query("SHOW TABLES LIKE 'assignments'");
if ($table_check->num_rows > 0) {
    $assignments_stmt = $conn->prepare("SELECT * FROM assignments WHERE teacher_id=? ORDER BY due_date DESC LIMIT 5");
    $assignments_stmt->bind_param("i", $teacher['id']);
    $assignments_stmt->execute();
    $assignments_result = $assignments_stmt->get_result();
    $assignments_count = $assignments_result->num_rows;
}

// Check if classes table exists and get upcoming classes
$upcoming_result = false;
$table_check = $conn->query("SHOW TABLES LIKE 'classes'");
if ($table_check->num_rows > 0) {
    $upcoming_stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? AND class_date >= CURDATE() ORDER BY class_date ASC, start_time ASC LIMIT 5");
    $upcoming_stmt->bind_param("i", $teacher['id']);
    $upcoming_stmt->execute();
    $upcoming_result = $upcoming_stmt->get_result();
}

// Get messages count
$table_check = $conn->query("SHOW TABLES LIKE 'messages'");
if ($table_check->num_rows > 0) {
    $messages_stmt = $conn->prepare("SELECT COUNT(*) as msg_count FROM messages WHERE receiver_id=? AND receiver_type='teacher' AND status='unread'");
    $messages_stmt->bind_param("i", $teacher['id']);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    $messages_count = $messages_result->fetch_assoc()['msg_count'];
}

$teacher['id'] = $teacher['id'] ?? ($_SESSION['teacher_id'] ?? 0);
$teacher_notifications_data = teacher_notifications_prepare($conn, $teacher, 12);
$messages_count = max((int)$messages_count, (int)($teacher_notifications_data['unread_count'] ?? 0));

// Log dashboard access
logTeacherActivity('DASHBOARD_VIEW', 'Viewed teacher dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | Ruchi Classes</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

.sidebar.collapsed .logo-text span {
    display: none;
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

/* ---------------- DROPDOWN STYLES ---------------- */
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
    z-index: 1001;
    position: relative;
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

/* Active state for dropdown parent */
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

.main-content::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 20% 80%, rgba(37, 99, 235, 0.05) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(245, 158, 11, 0.05) 0%, transparent 50%),
        radial-gradient(circle at 40% 40%, rgba(16, 185, 129, 0.03) 0%, transparent 50%);
    pointer-events: none;
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

/* ---------------- PAGE TITLE ---------------- */
.page-title {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 1.5rem;
    color: var(--text-primary);
    text-align: center;
    position: relative;
    padding-bottom: 15px;
}

.page-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 100px;
    height: 4px;
    background: var(--gradient-primary);
    border-radius: 2px;
}

/* ---------------- TEACHER INFO BADGE ---------------- */
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

/* ---------------- WELCOME BANNER ---------------- */
.welcome-banner {
    background: var(--gradient-primary);
    color: white;
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow);
    animation: fadeIn 0.8s ease;
}

.welcome-banner:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.welcome-text h2 {
    font-size: 24px;
    margin-bottom: 10px;
}

.view-schedule-btn {
    background: white;
    color: var(--primary);
    border: none;
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
}

.view-schedule-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(255, 255, 255, 0.3);
}

/* ---------------- STATS CARDS ---------------- */
.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--gradient-primary);
    color: white;
    border-radius: 16px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 8px 25px rgba(37, 99, 235, 0.25);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0;
    animation: fadeIn 0.6s ease forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }

.stat-card:hover {
    transform: translateY(-5px) scale(1.05);
    box-shadow: 0 12px 30px rgba(37, 99, 235, 0.35);
}

.stat-icon {
    font-size: 32px;
    margin-bottom: 12px;
}

.stat-value {
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 8px;
    line-height: 1;
}

.stat-label {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.9;
}

/* ---------------- DASHBOARD SECTIONS ---------------- */
.dashboard-section {
    background: var(--card-bg);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    animation: fadeIn 0.8s ease;
}

.dashboard-section:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border);
}

.section-header h2 {
    font-size: 20px;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.view-all {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s ease;
}

.view-all:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

/* ---------------- UPCOMING LIST ---------------- */
.upcoming-list {
    list-style: none;
}

.upcoming-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border);
    transition: all 0.3s ease;
}

.upcoming-item:last-child {
    border-bottom: none;
}

.upcoming-item:hover {
    background: var(--bg-hover);
    border-radius: 10px;
}

.upcoming-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.upcoming-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
}

.class-icon {
    background: var(--primary);
}

.assignment-icon {
    background: var(--success);
}

.upcoming-details h4 {
    font-size: 15px;
    margin-bottom: 5px;
    color: var(--text-primary);
}

.upcoming-details p {
    font-size: 13px;
    color: var(--text-muted);
}

.upcoming-time {
    text-align: right;
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 500;
}

/* ---------------- TEACHER PROFILE CARD ---------------- */
.teacher-profile-card {
    background: var(--card-bg);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    animation: fadeIn 0.8s ease;
}

.teacher-profile-content {
    display: flex;
    align-items: center;
    gap: 2rem;
}

.teacher-photo {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--primary);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25);
}

.teacher-details h3 {
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--text-primary);
}

.teacher-details p {
    margin-bottom: 8px;
    color: var(--text-secondary);
}

/* ---------------- LOGOUT BUTTON ---------------- */
.logout-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.logout-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

/* ---------------- ANIMATIONS ---------------- */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-15px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideInLeft {
    from { transform: translateX(-100%); }
    to { transform: translateX(0); }
}

/* ---------------- RESPONSIVE DESIGN ---------------- */

/* Tablet */
@media (max-width: 1024px) {
    .sidebar {
        transform: translateX(-100%);
        z-index: 1000;
        box-shadow: 0 0 50px rgba(0, 0, 0, 0.2);
        width: 320px;
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
    
    .page-title {
        font-size: 28px;
    }
    
    .stats-container {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .teacher-profile-content {
        flex-direction: column;
        text-align: center;
    }
    
    .dropdown-menu {
        position: static !important;
        margin-left: 15px !important;
        margin-top: 10px !important;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
        border: 1px solid var(--border) !important;
        background: white !important;
        width: calc(100% - 30px);
    }
    
    .dropdown.open + .dropdown-menu {
        display: flex !important;
        animation: slideDown 0.3s ease !important;
    }
    
    .dropdown-item {
        padding: 18px 20px !important;
        border-bottom: 1px solid #eee !important;
        font-size: 15px !important;
        min-height: 50px;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }
    
    .dropdown-item:last-child {
        border-bottom: none !important;
    }
    
    .dropdown-item:hover {
        transform: none !important;
        background: var(--primary-light) !important;
        color: white !important;
    }
}

/* Mobile */
@media (max-width: 768px) {
    .main-content {
        padding: 1rem;
    }
    
    .header {
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 14px;
    }
    
    .page-title {
        font-size: 26px;
    }
    
    .welcome-banner {
        padding: 1.5rem;
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .view-schedule-btn {
        width: 100%;
    }
    
    .stats-container {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        padding: 1.2rem;
    }
    
    .stat-value {
        font-size: 28px;
    }
    
    .dashboard-section {
        padding: 1.5rem;
    }
    
    .logo-img {
        width: 75px;
        height: 75px;
    }
    
    .logo-text {
        font-size: 22px;
    }
    
    .user-menu {
        gap: 15px;
    }
    
    .toggle-sidebar {
        width: 45px;
        height: 45px;
    }
}

/* Small Mobile */
@media (max-width: 480px) {
    .main-content {
        padding: 0.75rem;
    }
    
    .header {
        padding: 0.75rem;
    }
    
    .toggle-sidebar {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
    }
    
    .notifications {
        padding: 10px;
    }
    
    .notification-badge {
        width: 18px;
        height: 18px;
        font-size: 10px;
    }
    
    .page-title {
        font-size: 22px;
    }
    
    .teacher-info-badge {
        padding: 8px 16px;
        font-size: 14px;
    }
    
    .welcome-banner {
        padding: 1rem;
    }
    
    .welcome-text h2 {
        font-size: 20px;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .stat-value {
        font-size: 24px;
    }
    
    .stat-label {
        font-size: 12px;
    }
    
    .upcoming-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .upcoming-time {
        text-align: left;
    }
    
    .teacher-photo {
        width: 120px;
        height: 120px;
    }
}

/* Desktop */
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
</style>
<?php teacher_notifications_render_styles(); ?>
</head>
<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar Navigation -->
<div class="sidebar" id="sidebar">
    <div class="logo-container">
        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo-img" id="logoImg">
        <div class="logo-text" id="logoText">
            Ruchi <br>Classes
            <span>Education for Excellence</span>
        </div>
    </div>
    <a href="teacher_dashboard.php" class="nav-item active">
        <div class="nav-icon"><i class="fas fa-home"></i></div>
        <div class="nav-text">Dashboard</div>
    </a>
    
    <!-- Attendance Dropdown -->
    <div class="nav-item dropdown" id="attendanceDropdown">
        <div class="nav-icon"><i class="fas fa-clipboard-check"></i></div>
        <div class="nav-text">Attendance</div>
        <i class="fas fa-caret-down dropdown-icon"></i>
    </div>
    <div class="dropdown-menu" id="attendanceMenu">
        <a href="teacher_attendance.php" class="dropdown-item">
            <i class="fas fa-pencil-alt"></i> Mark Attendance
        </a>
        <a href="attendance_history.php" class="dropdown-item">
            <i class="fas fa-history"></i> Attendance History
        </a>
    </div>
    
    <!-- Exams Dropdown -->
    <div class="nav-item dropdown" id="examsDropdown">
        <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
        <div class="nav-text">Exams</div>
        <i class="fas fa-caret-down dropdown-icon"></i>
    </div>
    <div class="dropdown-menu" id="examsMenu">
        <a href="teacher_add_exam.php" class="dropdown-item">
            <i class="fas fa-plus-circle"></i> Add Exam
        </a>
        <a href="all_exam.php" class="dropdown-item">
            <i class="fas fa-list"></i> All Exams
        </a>
        <a href="exam_marks_entry.php" class="dropdown-item">
            <i class="fas fa-pencil-alt"></i> Marks Entry
        </a>
        <a href="exam_marks_history.php" class="dropdown-item">
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
                <?php teacher_notifications_render_button($teacher_notifications_data); ?>
                <div class="user-profile" onclick="showProfileMenu()">
                    <?php if (!empty($teacher['photo'])): ?>
                        <img src="<?php echo htmlspecialchars($teacher['photo']); ?>" alt="Profile" class="user-avatar"
                            onerror="this.src='../<?php echo htmlspecialchars($teacher['photo']); ?>'">
                    <?php else: ?>
                        <div class="user-avatar" style="background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                            <?php echo substr($teacher['first_name'], 0, 1) . substr($teacher['last_name'], 0, 1); ?>
                        </div>
                    <?php endif; ?>
                    <div class="user-name"><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></div>
                </div>
            </div>
        </div>

        <!-- Page Title -->
        <h1 class="page-title">Teacher Dashboard</h1>

        <!-- Teacher Info Badge -->
        <div style="text-align: center; margin-bottom: 1rem;">
            <div class="teacher-info-badge">
                <i class="fas fa-chalkboard-teacher"></i>
                <?php 
                    // Fixed: Proper null handling for subject
                    $subject_display = !empty($teacher['subject']) ? htmlspecialchars($teacher['subject']) : 'Teacher';
                    echo $subject_display . ' Teacher';
                ?>
            </div>
        </div>

        <!-- Teacher Profile Card -->
        <div class="teacher-profile-card">
            <div class="teacher-profile-content">
                <?php if (!empty($teacher['photo'])): ?>
                    <!-- Using both photo paths -->
                    <img src="<?php echo htmlspecialchars($teacher['photo']); ?>" alt="Teacher Photo" class="teacher-photo"
                        onerror="this.src='../<?php echo htmlspecialchars($teacher['photo']); ?>'">
                <?php else: ?>
                    <div class="teacher-photo" style="background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 2.5rem;">
                        <?php echo substr($teacher['first_name'], 0, 1) . substr($teacher['last_name'], 0, 1); ?>
                    </div>
                <?php endif; ?>
                <div class="teacher-details">
                    <h3><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></h3>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($teacher['email']); ?></p>
                    <p><strong>Mobile:</strong> <?php echo htmlspecialchars($teacher['mobile'] ?? 'Not set'); ?></p>
                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($teacher['subject'] ?? 'Not assigned'); ?></p>
                    <p><strong>Last Login:</strong> <?php echo isset($teacher['last_login']) ? date('d M Y h:i A', strtotime($teacher['last_login'])) : 'First Login'; ?></p>
                </div>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h2 id="greetingText">Welcome back, <?php echo htmlspecialchars($teacher['first_name']); ?>!</h2>
                <p>You have <?php echo ($upcoming_result ? $upcoming_result->num_rows : 0); ?> upcoming classes to manage.</p>
            </div>
            <button class="view-schedule-btn" onclick="window.location.href='teacher_schedule.php'">
                <i class="fas fa-calendar-alt"></i>
                View Schedule
            </button>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-value"><?php echo $class_count; ?></div>
                <div class="stat-label">Active Classes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $student_count; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?php echo $assignments_count; ?></div>
                <div class="stat-label">Assignments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-value"><?php echo $messages_count; ?></div>
                <div class="stat-label">Notifications</div>
            </div>
        </div>

        <!-- Upcoming Classes -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2><i class="fas fa-calendar-alt"></i> Upcoming Classes</h2>
                <a href="teacher_schedule.php" class="view-all">View All</a>
            </div>
            <ul class="upcoming-list">
                <?php if ($upcoming_result && $upcoming_result->num_rows > 0): ?>
                    <?php while($class = $upcoming_result->fetch_assoc()): 
                        $class_date = new DateTime($class['class_date']);
                        $today = new DateTime();
                        $interval = $today->diff($class_date);
                        $days_until = $interval->format('%a');
                        $start_time = date('g:i A', strtotime($class['start_time']));
                        $end_time = date('g:i A', strtotime($class['end_time']));
                    ?>
                        <li class="upcoming-item">
                            <div class="upcoming-info">
                                <div class="upcoming-icon class-icon">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                <div class="upcoming-details">
                                    <h4><?php echo htmlspecialchars($class['class_name']); ?></h4>
                                    <p><?php echo date('M j, Y', strtotime($class['class_date'])); ?> • <?php echo $start_time; ?> - <?php echo $end_time; ?></p>
                                </div>
                            </div>
                            <div class="upcoming-time">
                                <div><?php echo $days_until; ?> days</div>
                            </div>
                        </li>
                    <?php endwhile; ?>
                <?php else: ?>
                    <li class="upcoming-item">
                        <div class="upcoming-info">
                            <div class="upcoming-details">
                                <h4>No upcoming classes</h4>
                                <p>You don't have any scheduled classes.</p>
                            </div>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Recent Assignments -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2><i class="fas fa-clipboard-list"></i> Recent Assignments</h2>
                <a href="#" class="view-all">View All</a>
            </div>
            <ul class="upcoming-list">
                <?php if ($assignments_result && $assignments_result->num_rows > 0): ?>
                    <?php while($assignment = $assignments_result->fetch_assoc()): 
                        $due_date = new DateTime($assignment['due_date']);
                        $today = new DateTime();
                        $interval = $today->diff($due_date);
                        $days_until = $interval->format('%a');
                    ?>
                        <li class="upcoming-item">
                            <div class="upcoming-info">
                                <div class="upcoming-icon assignment-icon">
                                    <i class="fas fa-clipboard-list"></i>
                                </div>
                                <div class="upcoming-details">
                                    <h4><?php echo htmlspecialchars($assignment['title']); ?></h4>
                                    <p>Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></p>
                                </div>
                            </div>
                            <div class="upcoming-time">
                                <div><?php echo $days_until; ?> days</div>
                            </div>
                        </li>
                    <?php endwhile; ?>
                <?php else: ?>
                    <li class="upcoming-item">
                        <div class="upcoming-info">
                            <div class="upcoming-details">
                                <h4>No assignments</h4>
                                <p>You haven't created any assignments yet.</p>
                            </div>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Profile Menu Dropdown (Hidden by default) -->
    <div id="profileMenu" style="display: none; position: absolute; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); padding: 10px 0; min-width: 200px; z-index: 1000;">
        <a href="teacher_profile.php" style="display: block; padding: 10px 20px; text-decoration: none; color: #333; transition: 0.3s;">
            <i class="fas fa-user"></i> My Profile
        </a>
        <hr style="margin: 5px 0; border: 1px solid #eee;">
        <a href="#" onclick="confirmLogout(event)" style="display: block; padding: 10px 20px; text-decoration: none; color: #ef4444; transition: 0.3s;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggleSidebar');
        const toggleIcon = document.getElementById('toggleIcon');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const logoImg = document.getElementById('logoImg');
        const logoText = document.getElementById('logoText');
        const greetingText = document.getElementById('greetingText');
        
        // Set greeting based on time of day
        const hour = new Date().getHours();
        let greeting;
        if (hour < 12) {
            greeting = "Good morning";
        } else if (hour < 18) {
            greeting = "Good afternoon";
        } else {
            greeting = "Good evening";
        }
        greetingText.textContent = `${greeting}, <?php echo htmlspecialchars($teacher['first_name']); ?>!`;
        
        // ==================== SIDEBAR TOGGLE FUNCTION ====================
        function handleSidebarToggle() {
            if (window.innerWidth < 1025) {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';

                logoImg.style.width = '85px';
                logoImg.style.height = '85px';
                if (logoText) logoText.style.display = 'block';

            } else {
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

        // Close sidebar on mobile overlay click
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

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
            document.querySelectorAll('.nav-item:not(.dropdown), .dropdown-item').forEach(link => {
                link.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                    
                    document.querySelectorAll('.dropdown').forEach(drop => {
                        drop.classList.remove('open');
                    });
                });
            });
        }

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
                    // Mobile view
                    sidebar.classList.remove('collapsed');
                    if (mainContent) mainContent.classList.remove('expanded');
                    if (toggleIcon) toggleIcon.classList.replace('fa-ellipsis-h', 'fa-bars');
                    
                    logoImg.style.width = '85px';
                    logoImg.style.height = '85px';
                    if (logoText) logoText.style.display = 'block';
                }
                
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

    // ==================== PROFILE MENU ====================
    function showProfileMenu() {
        const menu = document.getElementById('profileMenu');
        const rect = document.querySelector('.user-profile').getBoundingClientRect();
        
        menu.style.display = 'block';
        menu.style.top = (rect.bottom + window.scrollY + 10) + 'px';
        menu.style.left = (rect.left + window.scrollX - 150) + 'px';
        
        // Close menu when clicking outside
        setTimeout(() => {
            document.addEventListener('click', function closeMenu(e) {
                if (!menu.contains(e.target) && !e.target.closest('.user-profile')) {
                    menu.style.display = 'none';
                    document.removeEventListener('click', closeMenu);
                }
            });
        }, 100);
    }

    // ==================== LOGOUT CONFIRMATION ====================
    function confirmLogout(event) {
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
    }

    // Handle logout from sidebar link
    document.querySelectorAll('.nav-item a[href*="logout"], .nav-item[onclick*="logout"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            confirmLogout(e);
        });
    });
</script>
<?php teacher_notifications_render_script($teacher_notifications_data); ?>
</body>
</html>
