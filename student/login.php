<?php
session_start();

// Try to include database connection
@require '../db.php';

// Check if database connection exists
if (!isset($conn) || !($conn instanceof mysqli)) {
    // If no database connection, create a simple one for XAMPP
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
    
    if ($conn->connect_error) {
        // If still fails, show error
        showAlert('error', 'Database Error', 'Please make sure MySQL is running in XAMPP');
    }
}

// Function to show SweetAlert with REAL-TIME clock
function showAlert($icon, $title, $text, $redirect = null, $student_name = ''){
    $server_time = time(); // Capture server time at function call
    $formatted_time = date("h:i A", $server_time);
    $date = date("F j, Y", $server_time);
    
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Alert</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { 
                background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;
            }
            .success-icon {
                font-size: 80px;
                color: #4CAF50;
                margin-bottom: 20px;
                animation: bounce 1s ease infinite alternate;
            }
            @keyframes bounce {
                from { transform: translateY(0); }
                to { transform: translateY(-10px); }
            }
            .real-time-clock {
                background: #f8f9fa;
                padding: 12px;
                border-radius: 8px;
                margin-top: 15px;
                text-align: center;
                border-left: 3px solid #4CAF50;
                font-family: monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 10px;
            }
            .countdown {
                display: inline-block;
                background: #4CAF50;
                color: white;
                padding: 4px 10px;
                border-radius: 15px;
                font-size: 12px;
                font-weight: bold;
                margin-left: 10px;
                min-width: 70px;
                text-align: center;
            }
            .server-time {
                color: #4CAF50;
                font-weight: 600;
                font-size: 13px;
            }
            .clock-separator {
                animation: blink 1s infinite;
            }
            @keyframes blink {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
        </style>
    </head>
    <body>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
        <script>
            // Server time from PHP (in milliseconds)
            const serverTimestamp = ' . ($server_time * 1000) . ';
            let redirectSeconds = 2; // Initial redirect time
            
            // Function to format time
            function formatTime(date) {
                const hours = date.getHours();
                const minutes = date.getMinutes();
                const seconds = date.getSeconds();
                const ampm = hours >= 12 ? "PM" : "AM";
                const formattedHours = hours % 12 || 12;
                
                return `${formattedHours.toString().padStart(2, "0")}<span class="clock-separator">:</span>${minutes.toString().padStart(2, "0")}<span class="clock-separator">:</span>${seconds.toString().padStart(2, "0")} ${ampm}`;
            }
            
            // Function to format date
            function formatDate(date) {
                const options = { 
                    weekday: "long", 
                    year: "numeric", 
                    month: "long", 
                    day: "numeric" 
                };
                return date.toLocaleDateString("en-US", options);
            }
            
            // Function to update the real-time display
            function updateRealTimeDisplay() {
                const now = new Date();
                const timeElement = document.getElementById("realTimeDisplay");
                const dateElement = document.getElementById("currentDate");
                const countdownElement = document.getElementById("countdownTimer");
                
                if (timeElement) {
                    timeElement.innerHTML = formatTime(now);
                }
                if (dateElement) {
                    dateElement.innerHTML = formatDate(now);
                }
                if (countdownElement && redirectSeconds > 0) {
                    countdownElement.textContent = redirectSeconds + "s";
                    redirectSeconds--;
                }
            }
            
            // Start the real-time clock
            Swal.fire({
                icon: "'.$icon.'",
                title: "'.$title.'",
                html: `<div style="text-align: center;">
                    <i class="fas fa-check-circle success-icon"></i>
                    <h3 style="margin: 10px 0; color: #333;">'.$text.'</h3>
                    <p style="color: #666; margin: 15px 0 10px;">
                        <i class="fas fa-spinner fa-spin"></i> 
                        Redirecting in <span id="countdownTimer" class="countdown">2s</span>
                    </p>
                    
                    <div class="real-time-clock">
                        <i class="fas fa-clock" style="color: #4CAF50;"></i>
                        <div>
                            <div class="server-time" id="realTimeDisplay">'.date("h:i:s A", $server_time).'</div>
                            <div style="font-size: 11px; color: #666; margin-top: 3px;" id="currentDate">'.$date.'</div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 10px; font-size: 11px; color: #999;">
                        <i class="fas fa-shield-alt"></i> Secure Login • 
                        <i class="fas fa-server"></i> Server Time
                    </div>
                </div>`,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                willClose: () => {
                    window.location.href = "'.$redirect.'";
                },
                didOpen: () => {
                    // Start real-time updates immediately
                    updateRealTimeDisplay();
                    
                    // Update every second
                    const timerInterval = setInterval(updateRealTimeDisplay, 1000);
                    
                    // Clear interval when alert closes
                    Swal.getPopup().addEventListener("close", () => {
                        clearInterval(timerInterval);
                    });
                },
                didDestroy: () => {
                    // Force redirect if still on page
                    setTimeout(() => {
                        if (window.location.href.indexOf("'.$redirect.'") === -1) {
                            window.location.href = "'.$redirect.'";
                        }
                    }, 100);
                }
            });
            
            // Start countdown timer
            const countdownInterval = setInterval(() => {
                const timer = Swal.getTimerLeft();
                if (timer) {
                    const seconds = Math.ceil(timer / 1000);
                    redirectSeconds = seconds;
                    const countdownElement = document.getElementById("countdownTimer");
                    if (countdownElement) {
                        countdownElement.textContent = seconds + "s";
                    }
                    
                    // Update progress bar text
                    const progressBar = Swal.getPopup().querySelector(".swal2-timer-progress-bar");
                    if (progressBar) {
                        progressBar.style.transition = `width ${timer}ms linear`;
                    }
                }
            }, 100);
            
            // Clear interval when alert closes
            Swal.getPopup().addEventListener("close", () => {
                clearInterval(countdownInterval);
            });
        </script>
    </body>
    </html>';
    exit;
}

// Function for error alerts
function showErrorAlert($icon, $title, $text){
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Error</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { 
                background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
        </style>
    </head>
    <body>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
        <script>
            Swal.fire({
                icon: "'.$icon.'",
                title: "'.$title.'",
                text: "'.$text.'",
                confirmButtonText: "Try Again",
                confirmButtonColor: "#4CAF50",
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.history.back();
            });
        </script>
    </body>
    </html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Simple validation
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Validate inputs
    if (empty($email) || empty($password)) {
        showErrorAlert('error', 'Missing Information', 'Please enter both email and password.');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        showErrorAlert('error', 'Invalid Email', 'Please enter a valid email address.');
    }
    
    // ✅ FIXED: First check student tables directly
    $student = null;
    $medium = "";
    $user_table = "";

    // Check Hindi table first (since your user is in Hindi table)
    $stmtHin = $conn->prepare("SELECT * FROM student_hindi WHERE email = ?");
    $stmtHin->bind_param("s", $email);
    $stmtHin->execute();
    $resHin = $stmtHin->get_result();
    if($resHin->num_rows > 0){
        $student = $resHin->fetch_assoc();
        $medium = "Hindi";
        $user_table = "student_hindi";
    }

    // Check English table if not found in Hindi
    if(!$student){
        $stmtEng = $conn->prepare("SELECT * FROM student_english WHERE email = ?");
        $stmtEng->bind_param("s", $email);
        $stmtEng->execute();
        $resEng = $stmtEng->get_result();
        if($resEng->num_rows > 0){
            $student = $resEng->fetch_assoc();
            $medium = "English";
            $user_table = "student_english";
        }
    }

    if(!$student){
        showErrorAlert('error', 'Email Not Found', 'This email is not registered. Please sign up first.');
    }

    // ✅ FIXED: Password verification - check both password column and signup table
    $password_valid = false;
    
    // Check if student table has password column
    if (isset($student['password']) && !empty($student['password'])) {
        // Check if password is hashed
        if (strlen($student['password']) == 60 && password_get_info($student['password'])['algo'] !== 0) {
            $password_valid = password_verify($password, $student['password']);
        } else {
            // Plain text password (for backward compatibility)
            $password_valid = ($password === $student['password']);
            
            // If plain text matches, hash it for future
            if ($password_valid) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE $user_table SET password = ? WHERE email = ?");
                $update->bind_param("ss", $hashed, $email);
                $update->execute();
            }
        }
    }
    
    // If not valid yet, check signup table
    if (!$password_valid) {
        $stmtSignup = $conn->prepare("SELECT * FROM signup WHERE email = ?");
        $stmtSignup->bind_param("s", $email);
        $stmtSignup->execute();
        $resSignup = $stmtSignup->get_result();
        
        if ($resSignup->num_rows > 0) {
            $signup_user = $resSignup->fetch_assoc();
            
            if (isset($signup_user['password']) && !empty($signup_user['password'])) {
                if (strlen($signup_user['password']) == 60 && password_get_info($signup_user['password'])['algo'] !== 0) {
                    $password_valid = password_verify($password, $signup_user['password']);
                } else {
                    $password_valid = ($password === $signup_user['password']);
                }
                
                // If valid, copy password to student table for future use
                if ($password_valid) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE $user_table SET password = ? WHERE email = ?");
                    $update->bind_param("ss", $hashed, $email);
                    $update->execute();
                }
            }
        }
    }

    if(!$password_valid){
        showErrorAlert('error', 'Incorrect Password', 'The password you entered is incorrect.');
    }

    // Get student name
    $student_name = "";
    if(isset($student['name'])){
        $student_name = htmlspecialchars($student['name']);
    } elseif(isset($student['first_name']) && isset($student['last_name'])){
        $student_name = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
    } else {
        $student_name = "Student";
    }

    // Set session variables
    session_regenerate_id(true); // Prevent session fixation

    $_SESSION['student_logged_in'] = true;
    $_SESSION['student_id'] = $student['id'];
    $_SESSION['student_email'] = $email;
    $_SESSION['student_name'] = $student_name;
    $_SESSION['student_medium'] = $medium;
    $_SESSION['student_class'] = $student['class'] ?? '';
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    $_SESSION['user_table'] = $user_table;

    // Record login in database
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $status = 'success';
    
    // Check if login table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'login'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO login (email, status, ip_address, login_time) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $email, $status, $ip_address);
        $stmt->execute();
    }

    // Show success alert with welcome message
    showAlert('success', 'Login Successful!', 'Welcome back ' . $student_name . '!', 'dashboard.php', $student_name);
}

// If someone accesses directly without POST, redirect to login page
header("Location: login.php");
exit;
?>