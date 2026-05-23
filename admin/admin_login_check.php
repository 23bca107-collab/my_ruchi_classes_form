<?php
session_start();

// Set JSON header
header('Content-Type: application/json');

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit;
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

// Get POST data
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$email = filter_var($email, FILTER_SANITIZE_EMAIL);
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

// Validate inputs
if (empty($email) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Email and password are required'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address'
    ]);
    exit;
}

function isAdminPasswordValid($enteredPassword, $storedPassword) {
    if (!is_string($storedPassword) || $storedPassword === '') {
        return false;
    }

    return password_verify($enteredPassword, $storedPassword) || hash_equals($storedPassword, $enteredPassword);
}

// Query database
$stmt = $conn->prepare("SELECT id, name, email, password FROM admins WHERE LOWER(email) = LOWER(?) LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    echo json_encode([
        'success' => false,
        'message' => 'This admin email is not registered'
    ]);
    exit;
}

$admin = $result->fetch_assoc();

// Verify password
if (isAdminPasswordValid($password, $admin['password'])) {
    // Password matches (plain text comparison - not recommended for production)
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_email'] = $admin['email'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'redirect' => 'admin_dashboard.php'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'The password you entered is incorrect'
    ]);
}

$stmt->close();
$conn->close();
?>
