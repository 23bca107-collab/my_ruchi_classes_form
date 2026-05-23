<?php
$host = "localhost";
$user = "root";  // XAMPP default
$pass = "";      // XAMPP default (blank password)
$db   = "ruchi_classes"; // apna database name yahan likho

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
?>
