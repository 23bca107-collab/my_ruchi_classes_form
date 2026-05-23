<?php
// ===========================================
// ADMISSION ANALYTICS - WITH PROPER AUTHENTICATION
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

// ===========================================
// DATABASE CONNECTION
// ===========================================
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
}

// Log admission report access
logAdminActivity('ADMISSION_REPORT', 'Accessed admission analytics dashboard');

// ===========================================
// FETCH ADMISSION DATA
// ===========================================

// Fetch English medium counts
$englishData = $conn->query("
    SELECT class, COUNT(*) as total 
    FROM student_english 
    WHERE class IN ('8', '9', '10', '11', '12')
    GROUP BY class
");

// Store English data
$englishCounts = [];
while ($row = $englishData->fetch_assoc()) {
    $englishCounts[$row['class']] = $row['total'];
}

// Fetch Hindi medium counts
$hindiData = $conn->query("
    SELECT class, COUNT(*) as total 
    FROM student_hindi 
    WHERE class IN ('8', '9', '10', '11', '12')
    GROUP BY class
");

// Store Hindi data
$hindiCounts = [];
while ($row = $hindiData->fetch_assoc()) {
    $hindiCounts[$row['class']] = $row['total'];
}

// Calculate totals
$totalEnglish = array_sum($englishCounts);
$totalHindi = array_sum($hindiCounts);
$overallTotal = $totalEnglish + $totalHindi;

// Calculate percentages
$englishPercentage = $overallTotal > 0 ? round(($totalEnglish / $overallTotal) * 100, 1) : 0;
$hindiPercentage = $overallTotal > 0 ? round(($totalHindi / $overallTotal) * 100, 1) : 0;

// Prepare data for all classes 8-12
$classes = ['8', '9', '10', '11', '12'];
$mediumOptions = ['English', 'Hindi'];

// Student detail filters
$selectedClass = trim((string)($_GET['student_class'] ?? ''));
if (!in_array($selectedClass, $classes, true)) {
    $selectedClass = '';
}

$selectedMedium = trim((string)($_GET['student_medium'] ?? ''));
if (!in_array($selectedMedium, $mediumOptions, true)) {
    $selectedMedium = '';
}

$selectedSearch = trim((string)($_GET['student_search'] ?? ''));
if ($selectedSearch !== '') {
    $selectedSearch = function_exists('mb_substr') ? mb_substr($selectedSearch, 0, 100) : substr($selectedSearch, 0, 100);
}

$shouldLoadStudentDetails = $selectedClass !== '' || $selectedMedium !== '';
$studentDetails = [];
$studentQueries = [];
$classFilterSql = $selectedClass !== '' ? " AND class = '" . $conn->real_escape_string($selectedClass) . "'" : '';
$searchFilterSql = '';

if ($selectedSearch !== '') {
    $safeSearch = $conn->real_escape_string($selectedSearch);
    $searchFilterSql = "
        AND (
            CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE '%{$safeSearch}%'
            OR COALESCE(father_name, '') LIKE '%{$safeSearch}%'
            OR COALESCE(school, '') LIKE '%{$safeSearch}%'
            OR COALESCE(email, '') LIKE '%{$safeSearch}%'
            OR COALESCE(parent_mobile, '') LIKE '%{$safeSearch}%'
            OR COALESCE(personal_mobile, '') LIKE '%{$safeSearch}%'
        )
    ";
}

if ($shouldLoadStudentDetails && ($selectedMedium === '' || $selectedMedium === 'English')) {
    $studentQueries[] = "
        SELECT id, first_name, last_name, father_name, school, parent_mobile, personal_mobile, email, class, 'English' AS medium, verified_at
        FROM student_english
        WHERE class IN ('8', '9', '10', '11', '12'){$classFilterSql}{$searchFilterSql}
    ";
}

if ($shouldLoadStudentDetails && ($selectedMedium === '' || $selectedMedium === 'Hindi')) {
    $studentQueries[] = "
        SELECT id, first_name, last_name, father_name, school, parent_mobile, personal_mobile, email, class, 'Hindi' AS medium, verified_at
        FROM student_hindi
        WHERE class IN ('8', '9', '10', '11', '12'){$classFilterSql}{$searchFilterSql}
    ";
}

if ($studentQueries !== []) {
    $studentDetailsQuery = implode(' UNION ALL ', $studentQueries) . "
        ORDER BY CAST(class AS UNSIGNED) ASC, medium ASC, first_name ASC, last_name ASC
    ";
    $studentDetailsResult = $conn->query($studentDetailsQuery);

    if ($studentDetailsResult instanceof mysqli_result) {
        while ($row = $studentDetailsResult->fetch_assoc()) {
            $studentDetails[] = $row;
        }
    }
}

$filteredStudentTotal = count($studentDetails);
$filteredEnglishStudents = 0;
$filteredHindiStudents = 0;

foreach ($studentDetails as $student) {
    if (($student['medium'] ?? '') === 'English') {
        $filteredEnglishStudents++;
    } elseif (($student['medium'] ?? '') === 'Hindi') {
        $filteredHindiStudents++;
    }
}

$hasStudentFilters = $selectedClass !== '' || $selectedMedium !== '' || $selectedSearch !== '';

// ===========================================
// HTML TEMPLATE WITH CONSISTENT DESIGN
// ===========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Admission Analytics | Ruchi Classes</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    
    .stat-percentage {
        font-size: 0.9rem;
        color: var(--primary);
        font-weight: 700;
        margin-top: 8px;
        position: relative;
        z-index: 2;
    }
    
    /* Medium Comparison */
    .medium-comparison {
        display: flex;
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .medium-card {
        flex: 1;
        background: linear-gradient(135deg, white, #fdfdfd);
        border-radius: var(--radius);
        padding: 25px;
        box-shadow: var(--shadow-lg);
        border: 2px solid white;
        text-align: center;
        transition: var(--transition);
        backdrop-filter: blur(10px);
    }
    
    .medium-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        border-color: var(--primary);
    }
    
    .medium-card h3 {
        font-size: 1.1rem;
        margin-bottom: 15px;
        color: var(--dark);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    .medium-card .percentage {
        font-size: 2.5rem;
        font-weight: 900;
        color: var(--primary);
        line-height: 1;
        margin-bottom: 10px;
    }
    
    .medium-card p {
        color: var(--secondary);
        font-size: 0.95rem;
    }
    
    /* Data Section */
    .data-section {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        margin-bottom: 25px;
    }
    
    /* Table Container */
    .table-container, .chart-container {
        background: linear-gradient(135deg, white, #fdfdfd);
        border-radius: var(--radius);
        padding: 25px;
        box-shadow: var(--shadow-lg);
        border: 2px solid white;
        transition: var(--transition);
        backdrop-filter: blur(10px);
    }
    
    .table-container:hover, .chart-container:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
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
    
    .table-container:hover .section-icon,
    .chart-container:hover .section-icon {
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
    
    .table-wrapper {
        overflow-x: auto;
        border-radius: 10px;
        border: 2px solid #e0e6ed;
        background: white;
        -webkit-overflow-scrolling: touch;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 500px;
    }
    
    table th {
        padding: 18px 15px;
        text-align: center;
        font-weight: 700;
        color: var(--dark);
        border-bottom: 3px solid var(--primary);
        white-space: nowrap;
        font-size: 0.9rem;
        letter-spacing: 0.5px;
        background: linear-gradient(135deg, var(--primary-light), #ebf5e6);
    }
    
    table td {
        padding: 15px;
        border-bottom: 2px solid #f1f5f9;
        text-align: center;
        font-size: 0.9rem;
        transition: var(--transition);
    }
    
    table tbody tr:hover {
        background: var(--primary-light);
        transform: scale(1.01);
    }
    
    .total-row {
        font-weight: 700;
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    }

    .detail-table-section {
        margin-bottom: 25px;
    }

    .filters-panel {
        background: linear-gradient(135deg, #f8fcf9, #ffffff);
        border: 2px solid var(--primary-light);
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .filter-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        align-items: end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .filter-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--dark);
    }

    .filter-control {
        width: 100%;
        padding: 12px 14px;
        border: 2px solid #e0e6ed;
        border-radius: 10px;
        background: white;
        color: var(--dark);
        font-size: 0.92rem;
        font-family: 'Inter', sans-serif;
        transition: var(--transition);
    }

    .filter-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.15);
    }

    .filter-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }

    .filter-btn,
    .clear-filter-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 46px;
        padding: 12px 18px;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: var(--transition);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
    }

    .filter-btn {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        box-shadow: 0 8px 18px rgba(39, 174, 96, 0.25);
    }

    .clear-filter-btn {
        background: #f3f4f6;
        color: var(--dark);
        border: 2px solid #e5e7eb;
    }

    .filter-btn:hover,
    .clear-filter-btn:hover {
        transform: translateY(-2px);
    }

    .detail-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 20px;
    }

    .summary-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 999px;
        background: var(--primary-light);
        color: var(--primary-dark);
        font-weight: 700;
        font-size: 0.88rem;
    }

    .summary-chip.secondary {
        background: #eef6ff;
        color: #2563eb;
    }

    .summary-chip.warning {
        background: #fff4df;
        color: #b45309;
    }

    .student-name-cell,
    .student-contact-cell {
        text-align: left;
    }

    .student-name-cell strong {
        display: block;
        color: var(--dark);
    }

    .student-subtext {
        display: block;
        margin-top: 4px;
        font-size: 0.8rem;
        color: var(--secondary);
    }

    .class-badge,
    .medium-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .class-badge {
        background: #edfdf4;
        color: var(--primary-dark);
    }

    .medium-badge.english {
        background: #e8f3ff;
        color: #1d4ed8;
    }

    .medium-badge.hindi {
        background: #fff2e2;
        color: #c2410c;
    }

    .empty-state {
        padding: 40px 20px;
        text-align: center;
        color: var(--secondary);
        background: white;
        border: 2px dashed #d1d5db;
        border-radius: 14px;
    }

    .empty-state i {
        font-size: 2.3rem;
        color: var(--primary);
        margin-bottom: 15px;
        display: block;
    }

    .empty-state h3 {
        font-size: 1.2rem;
        margin-bottom: 8px;
        color: var(--dark);
    }

    .empty-state p {
        font-size: 0.92rem;
    }
    
    /* Chart Wrapper */
    .chart-wrapper {
        position: relative;
        height: 300px;
        width: 100%;
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
        
        .data-section {
            grid-template-columns: 1fr;
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
        
        .medium-comparison {
            flex-direction: column;
        }
        
        .medium-card {
            padding: 20px;
        }
        
        .medium-card .percentage {
            font-size: 2rem;
        }
        
        .data-section {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .table-container,
        .chart-container {
            padding: 20px;
        }
        
        table th,
        table td {
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
        
        .chart-wrapper {
            height: 250px;
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
        
        .medium-card .percentage {
            font-size: 1.8rem;
        }
        
        table th,
        table td {
            padding: 10px 8px;
            font-size: 0.8rem;
        }
        
        .chart-wrapper {
            height: 200px;
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
                    <span class="meta-value"><?php echo $overallTotal; ?></span>
                    <span class="meta-label">Students</span>
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
                <li><a href="admission_report.php" class="active"><i class="fas fa-user-graduate"></i> Students</a></li>
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
                <li><a href="admin_logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <h1><i class="fas fa-chart-line"></i> Admission Analytics</h1>
            </div>
            
            <div class="header-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" name="search" placeholder="Search analytics..." autocomplete="off">
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

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $overallTotal; ?></div>
                <div class="stat-label">Total Admissions</div>
                <div class="stat-percentage">Classes 8-12</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $totalEnglish; ?></div>
                <div class="stat-label">English Medium</div>
                <div class="stat-percentage"><?php echo $englishPercentage; ?>% of total</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-language"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $totalHindi; ?></div>
                <div class="stat-label">Hindi Medium</div>
                <div class="stat-percentage"><?php echo $hindiPercentage; ?>% of total</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                </div>
                <div class="stat-value">8-12</div>
                <div class="stat-label">Classes Covered</div>
                <div class="stat-percentage">5 Grade Levels</div>
            </div>
        </div>

        <!-- Medium Comparison -->
        <div class="medium-comparison">
            <div class="medium-card">
                <h3><i class="fas fa-globe" style="color: #3498db;"></i> English Medium</h3>
                <div class="percentage"><?php echo $englishPercentage; ?>%</div>
                <p><?php echo $totalEnglish; ?> Students</p>
            </div>
            <div class="medium-card">
                <h3><i class="fas fa-language" style="color: #f39c12;"></i> Hindi Medium</h3>
                <div class="percentage"><?php echo $hindiPercentage; ?>%</div>
                <p><?php echo $totalHindi; ?> Students</p>
            </div>
        </div>

        <!-- Data Section -->
        <div class="data-section">
            <!-- Table Container -->
            <div class="table-container">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-table"></i>
                    </div>
                    <div>
                        <h2 class="section-title">Current Admissions</h2>
                        <p class="section-subtitle">Detailed breakdown by class and medium</p>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <table id="admissionsTable">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>English Medium</th>
                                <th>Hindi Medium</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $class): 
                                $englishCount = $englishCounts[$class] ?? 0;
                                $hindiCount = $hindiCounts[$class] ?? 0;
                                $classTotal = $englishCount + $hindiCount;
                            ?>
                            <tr>
                                <td><strong>Class <?php echo $class; ?></strong></td>
                                <td><?php echo $englishCount; ?></td>
                                <td><?php echo $hindiCount; ?></td>
                                <td><strong><?php echo $classTotal; ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total</strong></td>
                                <td><strong><?php echo $totalEnglish; ?></strong></td>
                                <td><strong><?php echo $totalHindi; ?></strong></td>
                                <td><strong><?php echo $overallTotal; ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="chart-container">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div>
                        <h2 class="section-title">Current Enrollment</h2>
                        <p class="section-subtitle">Visual representation by class and medium</p>
                    </div>
                </div>
                
                <div class="chart-wrapper">
                    <canvas id="enrollmentChart"></canvas>
                </div>
            </div>
        </div>

        <div class="table-container detail-table-section" id="student-details">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-address-card"></i>
                </div>
                <div>
                    <h2 class="section-title">Student Details List</h2>
                    <p class="section-subtitle">Class aur medium wise filtered student details</p>
                </div>
            </div>

            <div class="filters-panel">
                <form method="GET" action="admission_report.php#student-details" class="filter-form-grid">
                    <div class="filter-group">
                        <label class="filter-label" for="student_class">Class</label>
                        <select name="student_class" id="student_class" class="filter-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selectedClass === $class ? 'selected' : ''; ?>>
                                    Class <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="student_medium">Medium</label>
                        <select name="student_medium" id="student_medium" class="filter-control">
                            <option value="">All Mediums</option>
                            <?php foreach ($mediumOptions as $medium): ?>
                                <option value="<?php echo htmlspecialchars($medium); ?>" <?php echo $selectedMedium === $medium ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($medium); ?> Medium
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="student_search">Search</label>
                        <input
                            type="text"
                            name="student_search"
                            id="student_search"
                            class="filter-control"
                            placeholder="Name, mobile, email, school"
                            value="<?php echo htmlspecialchars($selectedSearch); ?>"
                        >
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="filter-btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <?php if ($hasStudentFilters): ?>
                            <a href="admission_report.php#student-details" class="clear-filter-btn">
                                <i class="fas fa-rotate-left"></i> Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if ($shouldLoadStudentDetails): ?>
                <div class="detail-summary">
                    <div class="summary-chip">
                        <i class="fas fa-users"></i>
                        <?php echo $filteredStudentTotal; ?> Students
                    </div>
                    <div class="summary-chip secondary">
                        <i class="fas fa-globe"></i>
                        <?php echo $filteredEnglishStudents; ?> English
                    </div>
                    <div class="summary-chip warning">
                        <i class="fas fa-language"></i>
                        <?php echo $filteredHindiStudents; ?> Hindi
                    </div>
                    <?php if ($selectedClass !== ''): ?>
                        <div class="summary-chip">
                            <i class="fas fa-graduation-cap"></i>
                            Class <?php echo htmlspecialchars($selectedClass); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($selectedMedium !== ''): ?>
                        <div class="summary-chip secondary">
                            <i class="fas fa-book-open"></i>
                            <?php echo htmlspecialchars($selectedMedium); ?> Medium
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($shouldLoadStudentDetails && $studentDetails !== []): ?>
                <div class="table-wrapper">
                    <table id="studentDetailsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Class</th>
                                <th>Medium</th>
                                <th>Father Name</th>
                                <th>School</th>
                                <th>Parent Mobile</th>
                                <th>Personal Mobile</th>
                                <th>Email</th>
                                <th>Verified On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentDetails as $index => $student): ?>
                                <?php
                                    $studentName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
                                    if ($studentName === '') {
                                        $studentName = 'Student #' . (int)($student['id'] ?? ($index + 1));
                                    }

                                    $fatherName = trim((string)($student['father_name'] ?? ''));
                                    $schoolName = trim((string)($student['school'] ?? ''));
                                    $parentMobile = trim((string)($student['parent_mobile'] ?? ''));
                                    $personalMobile = trim((string)($student['personal_mobile'] ?? ''));
                                    $emailAddress = trim((string)($student['email'] ?? ''));
                                    $studentClass = trim((string)($student['class'] ?? ''));
                                    $studentMedium = trim((string)($student['medium'] ?? ''));
                                    $verifiedOn = '-';

                                    if (!empty($student['verified_at'])) {
                                        $verifiedTimestamp = strtotime((string)$student['verified_at']);
                                        if ($verifiedTimestamp) {
                                            $verifiedOn = date('d M Y', $verifiedTimestamp);
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td class="student-name-cell">
                                        <strong><?php echo htmlspecialchars($studentName); ?></strong>
                                        <span class="student-subtext">ID: <?php echo (int)($student['id'] ?? 0); ?></span>
                                    </td>
                                    <td>
                                        <span class="class-badge">
                                            <i class="fas fa-graduation-cap"></i>
                                            Class <?php echo htmlspecialchars($studentClass !== '' ? $studentClass : '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="medium-badge <?php echo strtolower($studentMedium) === 'english' ? 'english' : 'hindi'; ?>">
                                            <i class="fas fa-language"></i>
                                            <?php echo htmlspecialchars($studentMedium !== '' ? $studentMedium : '-'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($fatherName !== '' ? $fatherName : '-'); ?></td>
                                    <td><?php echo htmlspecialchars($schoolName !== '' ? $schoolName : '-'); ?></td>
                                    <td><?php echo htmlspecialchars($parentMobile !== '' ? $parentMobile : '-'); ?></td>
                                    <td><?php echo htmlspecialchars($personalMobile !== '' ? $personalMobile : '-'); ?></td>
                                    <td class="student-contact-cell"><?php echo htmlspecialchars($emailAddress !== '' ? $emailAddress : '-'); ?></td>
                                    <td><?php echo htmlspecialchars($verifiedOn); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($shouldLoadStudentDetails): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <h3>No students found</h3>
                    <p>Selected class, medium, ya search ke hisab se koi student record nahi mila.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

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

// Quick profile click effect - FIXED with proper modal
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

// Prepare chart data from PHP
const classes = <?php echo json_encode($classes); ?>;
const englishData = <?php echo json_encode($englishCounts); ?>;
const hindiData = <?php echo json_encode($hindiCounts); ?>;

// Initialize chart
const ctx = document.getElementById('enrollmentChart').getContext('2d');

// Prepare data for the chart
const labels = classes.map(cls => 'Class ' + cls);
const englishValues = classes.map(cls => englishData[cls] || 0);
const hindiValues = classes.map(cls => hindiData[cls] || 0);

// Create chart
const enrollmentChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'English Medium',
                data: englishValues,
                backgroundColor: 'rgba(52, 152, 219, 0.7)',
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 1
            },
            {
                label: 'Hindi Medium',
                data: hindiValues,
                backgroundColor: 'rgba(243, 156, 18, 0.7)',
                borderColor: 'rgba(243, 156, 18, 1)',
                borderWidth: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Number of Students',
                    font: {
                        size: 12,
                        family: "'Inter', sans-serif"
                    }
                },
                ticks: {
                    font: {
                        size: 11,
                        family: "'Inter', sans-serif"
                    }
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Class',
                    font: {
                        size: 12,
                        family: "'Inter', sans-serif"
                    }
                },
                ticks: {
                    font: {
                        size: 11,
                        family: "'Inter', sans-serif"
                    }
                }
            }
        },
        plugins: {
            title: {
                display: true,
                text: 'Enrollment by Class and Medium',
                font: {
                    size: 14,
                    weight: 'bold',
                    family: "'Inter', sans-serif"
                },
                padding: {
                    bottom: 20
                }
            },
            legend: {
                position: 'top',
                labels: {
                    font: {
                        size: 11,
                        family: "'Inter', sans-serif"
                    },
                    padding: 15
                }
            }
        }
    }
});

// Update chart on window resize
window.addEventListener('resize', function() {
    enrollmentChart.resize();
});

// Add hover effects to table rows
const tableRows = document.querySelectorAll('#admissionsTable tbody tr');
tableRows.forEach(row => {
    row.addEventListener('mouseenter', function() {
        if (!this.classList.contains('total-row')) {
            this.style.backgroundColor = 'var(--primary-light)';
        }
    });
    
    row.addEventListener('mouseleave', function() {
        if (!this.classList.contains('total-row')) {
            this.style.backgroundColor = '';
        }
    });
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
// Save scroll position before page unload
window.addEventListener('beforeunload', function() {
    localStorage.setItem('sidebarScrollPosition', sidebar.scrollTop);
});

// Restore scroll position on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedScrollPosition = localStorage.getItem('sidebarScrollPosition');
    if (savedScrollPosition) {
        setTimeout(() => {
            sidebar.scrollTop = parseInt(savedScrollPosition);
            localStorage.removeItem('sidebarScrollPosition'); // Clear after restoring
        }, 100);
    }
    
    // Smooth scroll to top on logo click instead of reload
    const logoLink = document.querySelector('.logo');
    if (logoLink) {
        logoLink.addEventListener('click', function(e) {
            e.preventDefault();
            // Smooth scroll to top of main content
            mainContent.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
    
    // Animate stats cards on load
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
    
    // Animate medium cards
    const mediumCards = document.querySelectorAll('.medium-card');
    mediumCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 300 + (100 * index));
    });
    
    // Add table row animation
    const tableRows = document.querySelectorAll('#admissionsTable tbody tr');
    tableRows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateX(-20px)';
        
        setTimeout(() => {
            row.style.transition = 'all 0.4s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateX(0)';
        }, 500 + (50 * index));
    });
});

// Prevent default behavior on active link click
document.querySelectorAll('.nav-links a.active').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        // If already on the page, just smooth scroll to top
        if (this.getAttribute('href') === '#' || this.getAttribute('href') === '') {
            mainContent.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
    });
});
</script>
</body>
</html>
