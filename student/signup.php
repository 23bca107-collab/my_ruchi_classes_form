<?php
session_start();
require '../db.php'; // <-- DB connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    // Function to display SweetAlert2 and redirect
    function showAlert($icon, $title, $text, $redirect) {
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Signup Alert</title>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: "'.$icon.'",
                    title: "'.$title.'",
                    text: "'.$text.'",
                    timer: 2500,
                    timerProgressBar: true,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = "'.$redirect.'";
                });
            </script>
        </body>
        </html>';
        exit;
    }

    // --- Validation ---
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        showAlert('error', 'Invalid Email', 'Please enter a valid email address.', 'signup.html');
    }

    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        showAlert('error', 'Weak Password', 'Password must be at least 8 characters, with uppercase, lowercase & number.', 'signup.html');
    }

    if ($password !== $confirm_password) {
        showAlert('error', 'Passwords do not match!', 'Please check your password confirmation.', 'signup.html');
    }

    // --- Check which table the email exists in ---
    $medium = '';
    $stmt = $conn->prepare("SELECT id FROM student_english WHERE BINARY email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0){
        $medium = 'English';
    }
    $stmt->close();

    if($medium === ''){
        $stmt = $conn->prepare("SELECT id FROM student_hindi WHERE BINARY email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if($result->num_rows > 0){
            $medium = 'Hindi';
        }
        $stmt->close();
    }

    if($medium === ''){
        showAlert('error', 'Email Not Found', 'This email is not registered in either English or Hindi table.', 'signup.html');
    }

    // --- Check if email already exists in signup table ---
    $stmt = $conn->prepare("SELECT id FROM signup WHERE BINARY email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        showAlert('warning', 'Email exists', 'Use a different email.', 'signup.html');
    }
    $stmt->close();

    // --- Insert into signup table ---
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO signup (email, password, medium, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("sss", $email, $hashed_password, $medium);

    if ($stmt->execute()) {
        showAlert('success', 'Signup Successful!', 'You can now login.', 'login.html');
    } else {
        showAlert('error', 'Error', 'Something went wrong! Try again.', 'signup.html');
    }

    $stmt->close();
    $conn->close();

} else {
    http_response_code(405);
    exit('Method Not Allowed');
}
?>
