<?php
session_start();
require __DIR__ . '/../db.php';

// ==================== STUDENT AUTHENTICATION FUNCTION ====================
function isStudentAuthenticated() {
    // Check all required session variables
    if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
        return false;
    }
    
    // Check for required session variables
    $required = ['student_id', 'student_email', 'student_name', 'student_medium', 'login_time'];
    foreach ($required as $field) {
        if (!isset($_SESSION[$field]) || empty($_SESSION[$field])) {
            return false;
        }
    }
    
    // Check session age (max 2 hours)
    if (time() - $_SESSION['login_time'] > 7200) {
        return false;
    }
    
    return true;
}

// ==================== CHECK AUTHENTICATION ====================
if (!isStudentAuthenticated()) {
    // Log the security attempt
    error_log("SECURITY: Unauthorized access attempt to edit_profile.php from IP: " . $_SERVER['REMOTE_ADDR']);
    
    // Destroy session
    session_unset();
    session_destroy();
    
    // Redirect with JavaScript (more reliable)
    echo '<script>
        localStorage.clear();
        sessionStorage.clear();
        window.location.href = "../login.php?error=session_expired";
    </script>';
    exit();
}

// ==================== UPDATE LAST ACTIVITY ====================
$_SESSION['last_activity'] = time();

// ==================== GET STUDENT DETAILS ====================
$email = $_SESSION['student_email'];
$medium = $_SESSION['student_medium'];
$student_id = $_SESSION['student_id'];

// Determine which table to use based on medium
$table = ($medium === 'English') ? 'student_english' : 'student_hindi';

// Get student details
$stmt = $conn->prepare("SELECT * FROM $table WHERE id = ? AND email = ?");
$stmt->bind_param("is", $student_id, $email);
$stmt->execute();
$result = $stmt->get_result();
$student_details = $result->fetch_assoc();

if (!$student_details) {
    // Student not found - session might be corrupted
    session_unset();
    session_destroy();
    header("Location: ../login.php?error=student_not_found");
    exit;
}

// ==================== CHECK EDIT PERMISSION ====================
function canEditProfile($conn, $student_id, $medium, $class) {
    // Check if student_edit_permissions table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'student_edit_permissions'");
    if ($table_check->num_rows == 0) {
        // If table doesn't exist, allow editing by default
        return true;
    }
    
    // Check individual student permission first
    $stmt = $conn->prepare("SELECT can_edit FROM student_edit_permissions 
                            WHERE student_id = ? AND medium = ?");
    $stmt->bind_param("is", $student_id, $medium);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $perm = $result->fetch_assoc();
        return (bool)$perm['can_edit'];
    }
    
    // Check class-level permission
    $stmt = $conn->prepare("SELECT can_edit FROM student_edit_permissions 
                            WHERE class = ? AND medium = ? AND student_id IS NULL");
    $stmt->bind_param("ss", $class, $medium);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $perm = $result->fetch_assoc();
        return (bool)$perm['can_edit'];
    }
    
    // Default to true if no permission found
    return true;
}

$can_edit = canEditProfile($conn, $student_id, $medium, $student_details['class']);

// If cannot edit, show message and redirect
if (!$can_edit) {
    $_SESSION['error_message'] = "Profile editing is currently locked. Please contact your teacher.";
    header("Location: profile.php");
    exit();
}

// ==================== DEFAULT IMAGE PATH ====================
$defaultImage = "http://localhost/ruchi_classes_form/student/uploads/default.png";
foreach (['png','jpg','jpeg'] as $ext) {
    $path = __DIR__ . "/uploads/default.$ext";
    if (file_exists($path)) {
        $defaultImage = "http://localhost/ruchi_classes_form/student/uploads/default.$ext";
        break;
    }
}

// ==================== PHOTO PATH FUNCTION ====================
function getPhotoPath($photo, $defaultImage) {
    if (!empty($photo)) {
        if (strpos($photo, 'uploads/') === 0) {
            return "http://localhost/ruchi_classes_form/student/" . $photo;
        }
        if (strpos($photo, 'student/uploads/') === 0) {
            return "http://localhost/ruchi_classes_form/" . $photo;
        }
        if (strpos($photo, 'http') === 0) {
            return $photo;
        }
    }
    return $defaultImage;
}
$photoPath = getPhotoPath($student_details['photo'] ?? '', $defaultImage);

// ==================== HTML ESCAPE FUNCTION ====================
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ==================== HANDLE PROFILE UPDATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $update_data = [];
    $allowed_fields = [
        'first_name','last_name','father_name','mother_name','dob','gender',
        'class','board','school','previous_marks','parent_mobile','personal_mobile',
        'whatsapp','city','state','pincode','address','reference'
    ];

    foreach ($allowed_fields as $field) {
        if (isset($_POST[$field])) {
            $update_data[$field] = trim($_POST[$field]);
        }
    }

    // Handle photo upload
    if (!empty($_FILES['photo']['name'])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . basename($_FILES['photo']['name']);
        $targetFilePath = $uploadDir . $fileName;
        $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

        $allowTypes = ['jpg','jpeg','png','gif','webp'];
        if (in_array($fileType, $allowTypes)) {
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetFilePath)) {
                $update_data['photo'] = 'uploads/' . $fileName;

                // Delete old photo
                $oldPhoto = $student_details['photo'] ?? '';
                if (!empty($oldPhoto) && $oldPhoto !== 'uploads/default.png' &&
                    file_exists(__DIR__ . '/' . $oldPhoto)) {
                    unlink(__DIR__ . '/' . $oldPhoto);
                }
            }
        }
    }

    // Update database
    if (!empty($update_data)) {
        $setClause = [];
        $types = '';
        $values = [];

        foreach ($update_data as $field => $value) {
            $setClause[] = "$field = ?";
            $types .= 's';
            $values[] = $value;
        }

        // Add WHERE clause conditions
        $values[] = $student_id;
        $values[] = $email;
        $types .= 'is';

        $sql = "UPDATE $table SET " . implode(', ', $setClause) . " WHERE id = ? AND email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);

        if ($stmt->execute()) {
            // Update session name if first_name or last_name changed
            if (isset($update_data['first_name']) || isset($update_data['last_name'])) {
                $new_first = $update_data['first_name'] ?? $student_details['first_name'];
                $new_last = $update_data['last_name'] ?? $student_details['last_name'];
                $_SESSION['student_name'] = $new_first . ' ' . $new_last;
            }
            
            $_SESSION['success_message'] = "Profile updated successfully!";
            header("Location: profile.php");
            exit;
        } else {
            $_SESSION['error_message'] = "Error updating profile: " . $conn->error;
        }
    } else {
        $_SESSION['info_message'] = "No changes were made.";
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Ruchi Classes</title>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #6f42c1;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fc;
            --dark: #5a5c69;
            --bg-gradient: linear-gradient(180deg, #4e73df 10%, #224abe 100%);
            --card-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fc;
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Navigation Bar */
        .navbar {
            background: var(--bg-gradient);
            padding: 15px 0;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .navbar-content {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-brand:hover {
            color: rgba(255,255,255,0.9);
        }
        
        .navbar-menu {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .navbar-menu a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .navbar-menu a:hover {
            background: rgba(255,255,255,0.1);
        }
        

        
        .user-info {
            color: white;
            margin-right: 15px;
            padding-right: 15px;
            border-right: 2px solid rgba(255,255,255,0.3);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInDown 0.8s ease;
        }
        
        .header h1 {
            color: var(--primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .header p {
            color: var(--dark);
            font-size: 1.1rem;
        }
        
        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 30px;
            animation: slideUp 0.5s ease;
        }
        
        .card-header {
            background: var(--bg-gradient);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .profile-img-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .profile-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #f0f0f0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .profile-img:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .img-upload {
            margin-top: 15px;
        }
        
        .img-upload-label {
            display: inline-block;
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .img-upload-label:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #d1d3e2;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
            outline: none;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .btn:hover {
            background: var(--secondary);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .btn-danger {
            background: var(--danger);
        }
        
        .btn-info {
            background: var(--info);
        }
        
        /* Permission Warning */
        .permission-warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: #856404;
        }
        
        .permission-warning i {
            font-size: 24px;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animated {
            animation-duration: 0.5s;
            animation-fill-mode: both;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .navbar {
                border-radius: 12px;
                margin-bottom: 20px;
            }

            .navbar-content {
                flex-direction: column;
                gap: 15px;
                padding: 0 16px;
            }
            
            .navbar-menu {
                width: 100%;
                flex-wrap: wrap;
                justify-content: center;
                gap: 12px;
            }
            
            .user-info {
                border-right: none;
                border-bottom: 2px solid rgba(255,255,255,0.3);
                padding-bottom: 10px;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }

            .card-body {
                padding: 24px 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }

            .session-timer {
                right: 12px;
                left: 12px;
                bottom: 12px;
                justify-content: center;
                border-radius: 16px;
            }

            .toast-notification {
                top: 12px;
                left: 12px;
                right: 12px;
                width: auto;
                padding: 14px 16px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 12px;
            }

            .navbar-content {
                padding: 0 12px;
            }

            .navbar-brand {
                font-size: 1.2rem;
                text-align: center;
            }

            .navbar-menu {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .navbar-menu a {
                width: 100%;
                justify-content: center;
                padding: 12px 14px;
            }

            .user-info {
                width: 100%;
                margin-right: 0;
                padding-right: 0;
                margin-bottom: 0;
            }

            .header {
                margin-bottom: 20px;
            }

            .header h1 {
                font-size: 1.6rem;
                line-height: 1.3;
            }

            .header p {
                font-size: 0.95rem;
            }

            .profile-img {
                width: 110px;
                height: 110px;
            }

            .img-upload-label {
                width: 100%;
                text-align: center;
            }

            .card-header,
            .card-body {
                padding: 18px 16px;
            }

            .form-control {
                padding: 11px 13px;
                font-size: 0.95rem;
            }

            .btn {
                width: 100%;
                padding: 13px 18px;
            }

            .session-timer {
                padding: 10px 14px;
            }

            .timer-text {
                font-size: 13px;
            }
        }
        
        /* Custom file input */
        input[type="file"] {
            display: none;
        }
        
        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            animation: toastIn 0.5s ease, toastOut 0.5s ease 2.5s forwards;
        }
        
        .toast-success {
            background: var(--success);
        }
        
        .toast-error {
            background: var(--danger);
        }
        
        .toast-info {
            background: var(--info);
        }
        
        @keyframes toastIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes toastOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        /* Session timer warning */
        .session-timer {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 10px 20px;
            border-radius: 50px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 100;
        }
        
        .timer-text {
            font-size: 14px;
            font-weight: 500;
        }
        
        .timer-warning {
            color: var(--warning);
        }
        
        .timer-danger {
            color: var(--danger);
        }
    </style>
</head>
<body>
    <!-- Session Timer -->
    <div class="session-timer" id="sessionTimer">
        <i class="fas fa-clock"></i>
        <span class="timer-text" id="timerDisplay">Session: 2h remaining</span>
    </div>

    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-graduation-cap"></i>
                Ruchi Classes
            </a>
            <div class="navbar-menu">
                <span class="user-info">
                    <i class="fas fa-user"></i> <?=h($_SESSION['student_name'])?>
                </span>
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Edit Your Profile</h1>
            <p>Update your information to keep your profile current</p>
        </div>
        
        <!-- Permission Warning (if any) -->
        <?php if (isset($permission_warning)): ?>
            <div class="permission-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?=h($permission_warning)?></span>
            </div>
        <?php endif; ?>
        
        <div class="profile-card">
            <div class="card-header">
                <h2><i class="fas fa-user-graduate"></i> Student Information</h2>
            </div>
            
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="profileForm">
                    <div class="profile-img-section">
                        <img src="<?=$photoPath?>" alt="Profile Photo" class="profile-img" id="profileImage">
                        <div class="img-upload">
                            <label for="photoUpload" class="img-upload-label">
                                <i class="fas fa-camera"></i> Change Photo
                            </label>
                            <input type="file" id="photoUpload" name="photo" accept="image/*">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name"><i class="fas fa-signature"></i> First Name</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" value="<?=h($student_details['first_name'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name"><i class="fas fa-signature"></i> Last Name</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" value="<?=h($student_details['last_name'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="father_name"><i class="fas fa-user-friends"></i> Father's Name</label>
                            <input type="text" id="father_name" name="father_name" class="form-control" value="<?=h($student_details['father_name'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="mother_name"><i class="fas fa-user-friends"></i> Mother's Name</label>
                            <input type="text" id="mother_name" name="mother_name" class="form-control" value="<?=h($student_details['mother_name'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="dob"><i class="fas fa-birthday-cake"></i> Date of Birth</label>
                            <input type="date" id="dob" name="dob" class="form-control" value="<?=h($student_details['dob'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="gender"><i class="fas fa-venus-mars"></i> Gender</label>
                            <select id="gender" name="gender" class="form-control" required>
                                <option value="Male" <?=($student_details['gender']=="Male"?"selected":"")?>>Male</option>
                                <option value="Female" <?=($student_details['gender']=="Female"?"selected":"")?>>Female</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="class"><i class="fas fa-graduation-cap"></i> Class</label>
                            <input type="text" id="class" name="class" class="form-control" value="<?=h($student_details['class'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="board"><i class="fas fa-school"></i> Board</label>
                            <input type="text" id="board" name="board" class="form-control" value="<?=h($student_details['board'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="school"><i class="fas fa-university"></i> School</label>
                            <input type="text" id="school" name="school" class="form-control" value="<?=h($student_details['school'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="previous_marks"><i class="fas fa-chart-line"></i> Previous Marks</label>
                            <input type="text" id="previous_marks" name="previous_marks" class="form-control" value="<?=h($student_details['previous_marks'])?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="parent_mobile"><i class="fas fa-phone"></i> Parent Mobile</label>
                            <input type="text" id="parent_mobile" name="parent_mobile" class="form-control" value="<?=h($student_details['parent_mobile'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="personal_mobile"><i class="fas fa-mobile-alt"></i> Personal Mobile</label>
                            <input type="text" id="personal_mobile" name="personal_mobile" class="form-control" value="<?=h($student_details['personal_mobile'])?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</label>
                            <input type="text" id="whatsapp" name="whatsapp" class="form-control" value="<?=h($student_details['whatsapp'])?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="city"><i class="fas fa-city"></i> City</label>
                            <input type="text" id="city" name="city" class="form-control" value="<?=h($student_details['city'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="state"><i class="fas fa-map-marked"></i> State</label>
                            <input type="text" id="state" name="state" class="form-control" value="<?=h($student_details['state'])?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="pincode"><i class="fas fa-map-pin"></i> Pincode</label>
                            <input type="text" id="pincode" name="pincode" class="form-control" value="<?=h($student_details['pincode'])?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="address"><i class="fas fa-address-card"></i> Address</label>
                        <textarea id="address" name="address" class="form-control" required><?=h($student_details['address'])?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="reference"><i class="fas fa-handshake"></i> Reference</label>
                        <input type="text" id="reference" name="reference" class="form-control" value="<?=h($student_details['reference'])?>">
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Profile</button>
                        <button type="button" class="btn btn-info" id="resetBtn"><i class="fas fa-undo"></i> Reset Changes</button>
                        <a href="profile.php" class="btn btn-danger"><i class="fas fa-arrow-left"></i> Back to Profile</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // ==================== SESSION TIMER ====================
        const loginTime = <?= $_SESSION['login_time'] ?? time() ?>;
        const sessionDuration = 2 * 60 * 60; // 2 hours in seconds
        
        function updateSessionTimer() {
            const now = Math.floor(Date.now() / 1000);
            const elapsed = now - loginTime;
            const remaining = sessionDuration - elapsed;
            
            if (remaining <= 0) {
                // Session expired
                Swal.fire({
                    icon: 'warning',
                    title: 'Session Expired',
                    text: 'Your session has expired. Please login again.',
                    timer: 3000,
                    timerProgressBar: true,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = '?logout=1';
                });
                return;
            }
            
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            
            const timerDisplay = document.getElementById('timerDisplay');
            const timerDiv = document.getElementById('sessionTimer');
            
            if (hours > 0) {
                timerDisplay.textContent = `Session: ${hours}h ${minutes}m remaining`;
            } else {
                timerDisplay.textContent = `Session: ${minutes}m remaining`;
            }
            
            // Add warning classes
            if (remaining < 300) { // Less than 5 minutes
                timerDiv.classList.add('timer-danger');
                timerDiv.classList.remove('timer-warning');
            } else if (remaining < 600) { // Less than 10 minutes
                timerDiv.classList.add('timer-warning');
                timerDiv.classList.remove('timer-danger');
            }
        }
        
        // Update timer every second
        setInterval(updateSessionTimer, 1000);
        updateSessionTimer();
        

        
        // ==================== IMAGE PREVIEW ====================
        document.getElementById('photoUpload').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const file = e.target.files[0];
                
                // Check file size (max 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'Please select an image under 5MB.'
                    });
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profileImage').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
        
        // ==================== FORM RESET ====================
        document.getElementById('resetBtn').addEventListener('click', function() {
            Swal.fire({
                title: 'Reset Form?',
                text: 'Are you sure you want to reset all changes?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#4e73df',
                cancelButtonColor: '#e74a3b',
                confirmButtonText: 'Yes, reset it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('profileForm').reset();
                    document.getElementById('profileImage').src = '<?=$photoPath?>';
                    
                    Swal.fire(
                        'Reset!',
                        'Your form has been reset.',
                        'success'
                    );
                }
            });
        });
        
        // ==================== FORM SUBMISSION ====================
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Check if any field has been changed
            const formData = new FormData(this);
            let hasChanges = false;
            
            // Compare with original values
            <?php foreach ($student_details as $key => $value): ?>
                <?php if (!in_array($key, ['id', 'photo', 'created_at', 'updated_at'])): ?>
                    if (formData.get('<?=$key?>') !== '<?=h($value)?>') {
                        hasChanges = true;
                    }
                <?php endif; ?>
            <?php endforeach; ?>
            
            if (!hasChanges && !formData.get('photo').name) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Changes',
                    text: 'You haven\'t made any changes to your profile.'
                });
                return;
            }
            
            Swal.fire({
                title: 'Update Profile?',
                text: 'Are you sure you want to update your profile information?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1cc88a',
                cancelButtonColor: '#e74a3b',
                confirmButtonText: 'Yes, update it!',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return new Promise((resolve) => {
                        // Submit the form
                        document.getElementById('profileForm').submit();
                        resolve();
                    });
                },
                allowOutsideClick: false,
                allowEscapeKey: false
            });
        });
        
        // ==================== SHOW SESSION MESSAGES ====================
        <?php if(isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?=h($_SESSION['success_message'])?>',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error_message'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?=h($_SESSION['error_message'])?>',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true
            });
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['info_message'])): ?>
            Swal.fire({
                icon: 'info',
                title: 'Info',
                text: '<?=h($_SESSION['info_message'])?>',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            <?php unset($_SESSION['info_message']); ?>
        <?php endif; ?>
        
        // ==================== ANIMATIONS ====================
        document.addEventListener('DOMContentLoaded', function() {
            const formGroups = document.querySelectorAll('.form-group');
            
            formGroups.forEach((group, index) => {
                group.style.opacity = '0';
                group.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    group.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    group.style.opacity = '1';
                    group.style.transform = 'translateY(0)';
                }, 100 + (index * 50));
            });
        });
        
        // ==================== ACTIVITY TRACKING ====================
        let inactivityTimer;
        const INACTIVITY_LIMIT = 15 * 60 * 1000; // 15 minutes
        
        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                Swal.fire({
                    icon: 'warning',
                    title: 'Inactivity Detected',
                    text: 'You have been inactive. Your session will expire soon.',
                    timer: 30000,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
            }, INACTIVITY_LIMIT - 30000); // Warn 30 seconds before expiry
        }
        
        // Track user activity
        ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetInactivityTimer);
        });
        
        resetInactivityTimer();
    </script>
</body>
</html>
