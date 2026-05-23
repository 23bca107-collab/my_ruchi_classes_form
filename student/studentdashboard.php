<?php
session_start();
require 'db.php'; // your database connection

// Check if student is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: studentloginform.php");
    exit();
}

// Get student email from session
$student_email = $_SESSION['student_email'];

// Fetch student data from admissions table
$stmt = $conn->prepare("SELECT * FROM admissions WHERE gmail = ?");
$stmt->bind_param("s", $student_email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Student data not found. Please contact admin.";
    exit();
}

$student = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard</title>
<style>
body { font-family: Arial, sans-serif; background-color: #f0f2f5; padding: 20px; }
.container { max-width: 800px; margin: auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 10px 20px rgba(0,0,0,0.1);}
h2 { color: #4a6fc7; margin-bottom: 20px; }
p { margin: 8px 0; font-size: 16px; }
.logout { margin-top: 20px; display: inline-block; padding: 10px 15px; background: #4a6fc7; color: #fff; text-decoration: none; border-radius: 5px;}
.logout:hover { background: #3b5aa6; }
</style>
</head>
<body>
<div class="container">
    <h2>Welcome, <?php echo htmlspecialchars($student['name']); ?>!</h2>
    
    <p><strong>Email:</strong> <?php echo htmlspecialchars($student['gmail']); ?></p>
    <p><strong>Medium:</strong> <?php echo htmlspecialchars($student['medium']); ?></p>
    <p><strong>Admission ID:</strong> <?php echo htmlspecialchars($student['id']); ?></p>
    
    <!-- Add more fields from admissions table if needed -->
    
    <a href="logout.php" class="logout">Logout</a>
</div>
</body>
</html>
