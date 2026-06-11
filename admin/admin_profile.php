<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// Check if profile is completed
if (!isset($_SESSION['profile_completed']) || $_SESSION['profile_completed'] !== true) {
    header("Location: admin_profile_complete.php");
    exit;
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/../includes/site_settings.php';
site_settings_start_favicon_buffer($conn);

// Get admin details
$admin_id = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone, photo, admin_type FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    
    // Handle photo upload
    $photo = $admin['photo']; // Keep existing photo by default
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $file_type = $_FILES['photo']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            $upload_dir = '../uploads/admin_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Delete old photo if exists
            if (!empty($admin['photo']) && file_exists($upload_dir . $admin['photo'])) {
                unlink($upload_dir . $admin['photo']);
            }
            
            $file_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $file_name = 'admin_' . $admin_id . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $file_path)) {
                $photo = $file_name;
            }
        }
    }
    
    // Update admin profile
    $stmt = $conn->prepare("UPDATE admins SET first_name = ?, last_name = ?, phone = ?, photo = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $first_name, $last_name, $phone, $photo, $admin_id);
    
    if ($stmt->execute()) {
        // Update session variables
        $_SESSION['admin_name'] = $first_name . ' ' . $last_name;
        $admin = array_merge($admin, [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'photo' => $photo
        ]);
        
        $success = "Profile updated successfully!";
    } else {
        $error = "Error updating profile: " . $conn->error;
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Verify current password (plain text comparison - update to hash in production)
    if ($current_password !== $admin['password']) {
        $password_error = "Current password is incorrect";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New passwords do not match";
    } elseif (strlen($new_password) < 6) {
        $password_error = "Password must be at least 6 characters";
    } else {
        // Update password
        $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $new_password, $admin_id);
        
        if ($stmt->execute()) {
            $password_success = "Password changed successfully!";
        } else {
            $password_error = "Error changing password: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Ruchi Classes</title>
    <!-- Add your CSS links here -->
</head>
<body>
    <!-- Add your profile management form here -->
</body>
</html>
