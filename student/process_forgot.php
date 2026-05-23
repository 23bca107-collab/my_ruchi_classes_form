<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'ruchi_classes';
$username = 'root';
$password = '';

// PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../PHPMailer/src/PHPMailer.php';
require_once '../PHPMailer/src/SMTP.php';
require_once '../PHPMailer/src/Exception.php';

// Database connection
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// Function to send OTP email
function sendOTPEmail($email, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ruchiclassnavayard@gmail.com';
        $mail->Password = 'kyzuhtviekrmporz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('ruchiclassnavayard@gmail.com', 'Ruchi Classes');
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset OTP - Ruchi Classes';
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f4f4; padding: 20px; }
                .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #4CAF50, #2E8B57); color: white; padding: 30px; text-align: center; }
                .content { padding: 40px 30px; text-align: center; }
                .otp-box { background: #f8f9fa; border: 3px dashed #4CAF50; border-radius: 15px; padding: 25px; margin: 20px 0; }
                .otp-code { font-size: 48px; font-weight: bold; letter-spacing: 10px; color: #4CAF50; }
                .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 8px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 Password Reset</h1>
                    <p>Ruchi Classes</p>
                </div>
                <div class='content'>
                    <h2>Hello!</h2>
                    <p>We received a request to reset your password.</p>
                    
                    <div class='otp-box'>
                        <p>Your OTP is:</p>
                        <div class='otp-code'>$otp</div>
                    </div>
                    
                    <p>This OTP is valid for <strong>10 minutes</strong>.</p>
                    
                    <div class='warning'>
                        ⚠️ Never share this OTP with anyone.
                    </div>
                    
                    <p style='margin-top: 30px; color: #666;'>
                        If you didn't request this, please ignore this email.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Your password reset OTP is: $otp. Valid for 10 minutes.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Generate OTP
function generateOTP() {
    return rand(100000, 999999);
}

// Handle requests
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ============================================
    // SEND OTP
    // ============================================
    if ($action === 'send_otp') {
        $email = $input['email'] ?? '';
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Valid email required']);
            exit;
        }
        
        // Check both student tables
        $tables = ['student_hindi', 'student_english'];
        $email_exists = false;
        $user_table = '';
        
        foreach ($tables as $table) {
            $stmt = $conn->prepare("SELECT id FROM $table WHERE email = :email");
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $email_exists = true;
                $user_table = $table;
                break;
            }
        }
        
        if (!$email_exists) {
            echo json_encode(['success' => false, 'message' => 'Email not registered']);
            exit;
        }
        
        // Store in session
        $otp = generateOTP();
        $_SESSION['password_reset'] = [
            'email' => $email,
            'otp' => $otp,
            'table' => $user_table,
            'expiry' => time() + 600,
            'attempts' => 0
        ];
        
        if (sendOTPEmail($email, $otp)) {
            echo json_encode(['success' => true, 'message' => 'OTP sent']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP']);
        }
        exit;
    }
    
    // ============================================
    // RESEND OTP
    // ============================================
    if ($action === 'resend_otp') {
        $email = $input['email'] ?? '';
        
        if (empty($email) || !isset($_SESSION['password_reset']) || $_SESSION['password_reset']['email'] !== $email) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        $new_otp = generateOTP();
        $_SESSION['password_reset']['otp'] = $new_otp;
        $_SESSION['password_reset']['expiry'] = time() + 600;
        $_SESSION['password_reset']['attempts'] = 0;
        
        if (sendOTPEmail($email, $new_otp)) {
            echo json_encode(['success' => true, 'message' => 'New OTP sent']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP']);
        }
        exit;
    }
    
    // ============================================
    // ✅ FIXED: RESET PASSWORD
    // ============================================
    if ($action === 'reset_password') {
        $email = $input['email'] ?? '';
        $otp = $input['otp'] ?? '';
        $new_password = $input['password'] ?? '';
        
        if (empty($email) || empty($otp) || empty($new_password)) {
            echo json_encode(['success' => false, 'message' => 'All fields required']);
            exit;
        }
        
        // Check session
        if (!isset($_SESSION['password_reset']) || $_SESSION['password_reset']['email'] !== $email) {
            echo json_encode(['success' => false, 'message' => 'Invalid session']);
            exit;
        }
        
        $reset = $_SESSION['password_reset'];
        
        // Check expiry
        if (time() > $reset['expiry']) {
            unset($_SESSION['password_reset']);
            echo json_encode(['success' => false, 'message' => 'OTP expired']);
            exit;
        }
        
        // Check attempts
        if ($reset['attempts'] >= 3) {
            unset($_SESSION['password_reset']);
            echo json_encode(['success' => false, 'message' => 'Too many attempts']);
            exit;
        }
        
        // Verify OTP
        if ($otp != $reset['otp']) {
            $_SESSION['password_reset']['attempts']++;
            echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
            exit;
        }
        
        // ✅ FIXED: Update password in the correct table
        $table = $reset['table']; // This will be 'student_hindi' or 'student_english'
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        try {
            // First check if table exists and has password column
            $check_column = $conn->query("SHOW COLUMNS FROM $table LIKE 'password'");
            if ($check_column->rowCount() == 0) {
                echo json_encode(['success' => false, 'message' => "Password column not found in $table"]);
                exit;
            }
            
            // Update password
            $stmt = $conn->prepare("UPDATE $table SET password = :password WHERE email = :email");
            $stmt->execute([
                ':password' => $hashed_password,
                ':email' => $email
            ]);
            
            // Check if update was successful
            if ($stmt->rowCount() > 0) {
                // Also update signup table if exists
                try {
                    $stmt2 = $conn->prepare("UPDATE signup SET password = :password WHERE email = :email");
                    $stmt2->execute([
                        ':password' => $hashed_password,
                        ':email' => $email
                    ]);
                } catch (Exception $e) {
                    // Signup table might not exist, ignore
                }
                
                // Clear session
                unset($_SESSION['password_reset']);
                
                echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Email not found in ' . $table]);
            }
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>