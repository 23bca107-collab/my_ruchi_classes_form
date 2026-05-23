<?php
session_start();
require '../db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.html");
    exit;
}

$first = trim($_POST['first_name']);
$last  = trim($_POST['last_name']);
$phone = trim($_POST['phone']);

/* XSS Protection */
$first = htmlspecialchars($first);
$last  = htmlspecialchars($last);
$phone = htmlspecialchars($phone);

/* Update profile */
$stmt = $conn->prepare("
    UPDATE admins 
    SET first_name = ?, last_name = ?, phone = ?, profile_completed = 1 
    WHERE id = ?
");
$stmt->bind_param("sssi", $first, $last, $phone, $_SESSION['admin_id']);
$stmt->execute();

/* Redirect to dashboard */
header("Location: admin_dashboard.php");
exit;
