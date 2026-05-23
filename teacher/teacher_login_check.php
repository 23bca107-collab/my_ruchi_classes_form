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

// Only process POST requests
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Check if teacher exists
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $teacher = $result->fetch_assoc();

        // First-time login: password is empty
        if (empty($teacher['password'])) {
            $stmt2 = $conn->prepare("UPDATE teachers SET password=? WHERE id=?");
            $stmt2->bind_param("si", $password, $teacher['id']);
            $stmt2->execute();

            $_SESSION['teacher_logged_in'] = true;
            $_SESSION['teacher_id'] = $teacher['id'];
            $_SESSION['teacher_email'] = $teacher['email'];

            // Redirect to profile form (first time)
            header("Location: teacher_profile.php");
            exit;
        }

        // Normal login: check password
        if ($password === $teacher['password']) {
            $_SESSION['teacher_logged_in'] = true;
            $_SESSION['teacher_id'] = $teacher['id'];
            $_SESSION['teacher_email'] = $teacher['email'];

            // Redirect based on profile completion
            if ($teacher['profile_completed'] == 0) {
                header("Location: teacher_profile.php");
            } else {
                header("Location: teacher_dashboard.php");
            }
            exit;
        } else {
            $_SESSION['error_message'] = "❌ Wrong password!";
            header("Location: teacher_login.php");
            exit;
        }
    } else {
        $_SESSION['error_message'] = "❌ Teacher not found!";
        header("Location: teacher_login.php");
        exit;
    }
} else {
    header("Location: teacher_login.php");
    exit;
}
?>
