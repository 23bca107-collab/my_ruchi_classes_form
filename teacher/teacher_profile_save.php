<?php
session_start();

require_once __DIR__ . '/teacher_auth.php';

requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: teacher_profile.php');
    exit;
}

$teacher = getTeacherInfo();
$teacherId = (int)($teacher['id'] ?? ($_SESSION['teacher_id'] ?? 0));

if ($teacherId <= 0) {
    $_SESSION['error_message'] = 'Unable to identify teacher profile.';
    header('Location: teacher_profile.php');
    exit;
}

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$address = trim($_POST['address'] ?? '');
$subject = trim($_POST['subject'] ?? '');

if ($firstName === '' || $lastName === '' || $mobile === '' || $address === '' || $subject === '') {
    $_SESSION['error_message'] = 'Please fill all required profile fields.';
    header('Location: teacher_profile.php');
    exit;
}

$photoPath = trim((string)($teacher['photo'] ?? ''));

if (!empty($_FILES['photo']['name'])) {
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        $_SESSION['error_message'] = 'Unable to create upload folder.';
        header('Location: teacher_profile.php');
        exit;
    }

    $extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        $_SESSION['error_message'] = 'Please upload a valid image file (JPG, PNG, GIF, WEBP).';
        header('Location: teacher_profile.php');
        exit;
    }

    $fileName = 'teacher_' . $teacherId . '_' . time() . '.' . $extension;
    $targetFile = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
        $_SESSION['error_message'] = 'Profile photo upload failed. Please try again.';
        header('Location: teacher_profile.php');
        exit;
    }

    $photoPath = 'teacher/uploads/' . $fileName;
}

$stmt = $conn->prepare('UPDATE teachers SET first_name = ?, last_name = ?, mobile = ?, address = ?, subject = ?, photo = ?, profile_completed = 1 WHERE id = ?');

if (!$stmt) {
    $_SESSION['error_message'] = 'Unable to prepare profile update.';
    header('Location: teacher_profile.php');
    exit;
}

$stmt->bind_param('ssssssi', $firstName, $lastName, $mobile, $address, $subject, $photoPath, $teacherId);

if ($stmt->execute()) {
    $_SESSION['teacher_first_name'] = $firstName;
    $_SESSION['teacher_last_name'] = $lastName;
    $_SESSION['teacher_name'] = trim($firstName . ' ' . $lastName);
    $_SESSION['teacher_mobile'] = $mobile;
    $_SESSION['teacher_subject'] = $subject;
    $_SESSION['teacher_photo'] = $photoPath;
    $_SESSION['profile_completed'] = 1;
    $_SESSION['success_message'] = 'Profile updated successfully.';

    header('Location: teacher_dashboard.php');
    exit;
}

$_SESSION['error_message'] = 'Unable to save profile right now. Please try again.';
header('Location: teacher_profile.php');
exit;
?>
