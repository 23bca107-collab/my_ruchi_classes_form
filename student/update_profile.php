<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['student_email'])) {
    echo "<script>alert('Please login first.'); window.location.href='ruchi.html';</script>";
    exit();
}

$conn = new mysqli("localhost", "root", "", "ruchi_classes");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Sanitize inputs
function sanitizeInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Get which table to update
$student_table = $_POST['student_table'] ?? '';
if (!in_array($student_table, ['student_english', 'student_hindi'])) {
    echo "<script>alert('Invalid student data.'); window.history.back();</script>";
    exit();
}

$email = $_SESSION['student_email'];

// Handle photo upload
$photoSql = "";
if (!empty($_FILES['photo']['name'])) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    $photoName = basename($_FILES['photo']['name']);
    $tmpName = $_FILES['photo']['tmp_name'];
    $fileType = $_FILES['photo']['type'];
    $fileSize = $_FILES['photo']['size'];
    $uploadDir = "uploads/";
    
    // Validate file
    if (!in_array($fileType, $allowedTypes)) {
        echo "<script>alert('Only JPG, PNG & GIF files are allowed.'); window.history.back();</script>";
        exit();
    }
    
    if ($fileSize > $maxSize) {
        echo "<script>alert('File size must be less than 2MB.'); window.history.back();</script>";
        exit();
    }
    
    // Generate unique filename
    $extension = pathinfo($photoName, PATHINFO_EXTENSION);
    $newFilename = uniqid() . '.' . $extension;
    $destination = $uploadDir . $newFilename;
    
    if (move_uploaded_file($tmpName, $destination)) {
        $photoSql = ", photo='" . $conn->real_escape_string($destination) . "'";
        
        // Delete old photo if exists
        $result = $conn->query("SELECT photo FROM $student_table WHERE email = '$email'");
        if ($result->num_rows > 0) {
            $oldPhoto = $result->fetch_assoc()['photo'];
            if (!empty($oldPhoto) && file_exists($oldPhoto) && strpos($oldPhoto, 'uploads/') !== false) {
                unlink($oldPhoto);
            }
        }
    } else {
        echo "<script>alert('Error uploading photo.'); window.history.back();</script>";
        exit();
    }
}

// Prepare update query
$sql = "UPDATE $student_table SET 
    first_name = ?,
    last_name = ?,
    father_name = ?,
    mother_name = ?,
    dob = ?,
    gender = ?,
    class = ?,
    medium = ?,
    school = ?,
    board = ?,
    previous_marks = ?,
    parent_mobile = ?,
    whatsapp = ?,
    address = ?,
    reference = ?"
    . $photoSql . 
    " WHERE email = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}

// Bind parameters
$params = [
    sanitizeInput($_POST['first_name']),
    sanitizeInput($_POST['last_name']),
    sanitizeInput($_POST['father_name']),
    sanitizeInput($_POST['mother_name']),
    sanitizeInput($_POST['dob']),
    sanitizeInput($_POST['gender']),
    sanitizeInput($_POST['class']),
    sanitizeInput($_POST['medium']),
    sanitizeInput($_POST['school']),
    sanitizeInput($_POST['board']),
    floatval($_POST['previous_marks']),
    sanitizeInput($_POST['parent_mobile']),
    sanitizeInput($_POST['whatsapp']),
    sanitizeInput($_POST['address']),
    sanitizeInput($_POST['reference']),
    $email
];

$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo "<script>alert('Profile updated successfully.'); window.location.href='profile.php';</script>";
} else {
    echo "<script>alert('Error updating profile: " . addslashes($stmt->error) . "'); window.history.back();</script>";
}

$stmt->close();
$conn->close();
?>