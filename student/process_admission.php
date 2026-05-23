<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'ruchi_classes';
$username = 'root';
$password = '';

// PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer (path check karein)
require_once '../PHPMailer/src/PHPMailer.php';
require_once '../PHPMailer/src/SMTP.php';
require_once '../PHPMailer/src/Exception.php';

// Create connection
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// ============================================
// FUNCTION: Send OTP via Email
// ============================================
function sendOTPEmail($email, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ruchiclassnavayard@gmail.com';  // Your Gmail
        $mail->Password   = 'kyzuhtviekrmporz';  // App password without spaces
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('ruchiclassnavayard@gmail.com', 'Ruchi Classes');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Ruchi Classes - Email Verification OTP';
        $mail->Body    = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; }
                .content { padding: 40px 30px; text-align: center; }
                .otp-box { background: #f8f9fa; border: 3px dashed #667eea; border-radius: 15px; padding: 25px; margin: 20px 0; }
                .otp-code { font-size: 48px; font-weight: bold; letter-spacing: 10px; color: #667eea; font-family: monospace; }
                .timer { color: #e74c3c; font-weight: 600; margin: 15px 0; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 14px; border-top: 1px solid #eee; }
                .warning { background: #fff3cd; color: #856404; padding: 12px; border-radius: 8px; margin-top: 20px; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 Ruchi Classes</h1>
                    <p>Email Verification</p>
                </div>
                <div class='content'>
                    <h2>Hello!</h2>
                    <p>Thank you for registering with Ruchi Classes. Please verify your email address using the OTP below:</p>
                    
                    <div class='otp-box'>
                        <div style='color: #666; margin-bottom: 10px;'>Your OTP is:</div>
                        <div class='otp-code'>$otp</div>
                    </div>
                    
                    <div class='timer'>⏰ This OTP is valid for 10 minutes only</div>
                    
                    <div class='warning'>
                        <strong>⚠️ Important:</strong> Never share this OTP with anyone. Our staff will never ask for your OTP.
                    </div>
                    
                    <p style='margin-top: 30px; color: #666;'>
                        If you didn't request this, please ignore this email.<br>
                        Your account will remain secure.
                    </p>
                </div>
                <div class='footer'>
                    <p>© 2026 Ruchi Classes. All rights reserved.</p>
                    <p>📞 Contact: +91 9898624729 | 📧 ruchiclassnavayard@gmail.com</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Your Ruchi Classes verification OTP is: $otp. Valid for 10 minutes.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================
// FUNCTION: Generate OTP
// ============================================
function generateOTP() {
    return rand(100000, 999999);
}

// ============================================
// HANDLE AJAX REQUESTS
// ============================================
$response = ['success' => false, 'message' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? '';
    
    // ============================================
    // ACTION: Test endpoint
    // ============================================
    if ($action === 'test') {
        echo json_encode(['status' => 'working', 'time' => date('Y-m-d H:i:s')]);
        exit;
    }
    
    // ============================================
    // ACTION: Submit admission form (with OTP)
    // ============================================
    if ($action === 'submit') {
        
        // Collect form data
        $first_name       = trim($_POST['first_name'] ?? '');
        $last_name        = trim($_POST['last_name'] ?? '');
        $father_name      = trim($_POST['father_name'] ?? '');
        $mother_name      = trim($_POST['mother_name'] ?? '');
        $dob              = trim($_POST['dob'] ?? '');
        $gender           = trim($_POST['gender'] ?? '');
        $class            = trim($_POST['class'] ?? '');
        $medium           = trim($_POST['medium'] ?? '');
        $board            = trim($_POST['board'] ?? '');
        $school           = trim($_POST['school'] ?? '');
        $previous_marks   = trim($_POST['previous_marks'] ?? '');
        $parent_mobile    = trim($_POST['parent_mobile'] ?? '');
        $personal_mobile  = trim($_POST['personal_mobile'] ?? '');
        $whatsapp         = trim($_POST['whatsapp'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $city             = trim($_POST['city'] ?? '');
        $state            = trim($_POST['state'] ?? '');
        $pincode          = trim($_POST['pincode'] ?? '');
        $address          = trim($_POST['address'] ?? '');
        $reference        = trim($_POST['reference'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($first_name)) $errors[] = "First name is required";
        if (empty($last_name)) $errors[] = "Last name is required";
        if (empty($email)) $errors[] = "Email is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
        if (empty($medium)) $errors[] = "Medium is required";
        if (!preg_match('/^[0-9]{10}$/', $parent_mobile)) $errors[] = "Parent mobile must be 10 digits";
        if (!preg_match('/^[0-9]{6}$/', $pincode)) $errors[] = "Pincode must be 6 digits";
        
        // Photo upload handling
        $photo_path = '';
        if (!empty($_FILES['photo']['name'])) {
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($ext, $allowed)) {
                $errors[] = "Only JPG, JPEG, PNG images allowed";
            } else {
                $file_name = time() . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $first_name) . '.' . $ext;
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                    $photo_path = $target_file;
                }
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
        
        // Check if email already exists in any table
        $tables = ['student_english', 'student_hindi'];
        $email_exists = false;
        
        foreach ($tables as $table) {
            $stmt = $conn->prepare("SELECT id FROM $table WHERE email = :email");
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $email_exists = true;
                break;
            }
        }
        
        if ($email_exists) {
            echo json_encode([
                'success' => false,
                'duplicate' => true,
                'message' => 'This email is already registered. Please use a different email or login.'
            ]);
            exit;
        }
        
        // Generate OTP and store in session
        $otp = generateOTP();
        $_SESSION['pending_admission'] = [
            'data' => $_POST,
            'photo' => $photo_path,
            'otp' => $otp,
            'expiry' => time() + 600, // 10 minutes
            'attempts' => 0
        ];
        
        // Send OTP email
        if (sendOTPEmail($email, $otp)) {
            echo json_encode([
                'success' => true,
                'requires_otp' => true,
                'email' => $email,
                'message' => 'OTP sent to your email'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ]);
        }
        exit;
    }
    
    // ============================================
    // ACTION: Verify OTP
    // ============================================
    if ($action === 'verify_otp') {
        
        $email = $_POST['email'] ?? '';
        $otp = $_POST['otp'] ?? '';
        
        if (empty($email) || empty($otp)) {
            echo json_encode(['success' => false, 'message' => 'Email and OTP required']);
            exit;
        }
        
        // Check session
        if (!isset($_SESSION['pending_admission'])) {
            echo json_encode(['success' => false, 'message' => 'No pending admission found. Please submit form again.']);
            exit;
        }
        
        $pending = $_SESSION['pending_admission'];
        
        // Check expiry
        if (time() > $pending['expiry']) {
            unset($_SESSION['pending_admission']);
            echo json_encode(['success' => false, 'message' => 'OTP expired. Please request new OTP.']);
            exit;
        }
        
        // Check attempts
        if ($pending['attempts'] >= 3) {
            unset($_SESSION['pending_admission']);
            echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please submit form again.']);
            exit;
        }
        
        // Verify OTP
        if ($otp == $pending['otp']) {
            
            // OTP verified - Save to database
            $data = $pending['data'];
            $photo_path = $pending['photo'];
            $medium = $data['medium'];
            $table = ($medium === 'English') ? 'student_english' : 'student_hindi';
            
            try {
                $stmt = $conn->prepare("INSERT INTO $table 
                    (first_name, last_name, father_name, mother_name, dob, gender, class, medium, board, school,
                     previous_marks, parent_mobile, personal_mobile, whatsapp, email, city, state, pincode, address,
                     reference, photo, verified_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                
                $stmt->execute([
                    $data['first_name'], $data['last_name'], $data['father_name'], $data['mother_name'],
                    $data['dob'], $data['gender'], $data['class'], $data['medium'], $data['board'],
                    $data['school'], $data['previous_marks'], $data['parent_mobile'], $data['personal_mobile'],
                    $data['whatsapp'], $data['email'], $data['city'], $data['state'], $data['pincode'],
                    $data['address'], $data['reference'] ?? '', $photo_path
                ]);
                
                $admission_id = $conn->lastInsertId();
                
                // Clear session
                unset($_SESSION['pending_admission']);
                
                // Send confirmation email
                sendOTPEmail($email, "Welcome to Ruchi Classes! Your admission ID is: $admission_id");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Admission successful! Welcome to Ruchi Classes.',
                    'admission_id' => $admission_id
                ]);
                
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            
        } else {
            // Failed attempt
            $_SESSION['pending_admission']['attempts']++;
            echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
        }
        exit;
    }
    
    // ============================================
    // ACTION: Resend OTP
    // ============================================
    if ($action === 'resend_otp') {
        
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        
        if (empty($email) || !isset($_SESSION['pending_admission'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        // Generate new OTP
        $new_otp = generateOTP();
        $_SESSION['pending_admission']['otp'] = $new_otp;
        $_SESSION['pending_admission']['expiry'] = time() + 600; // Reset expiry
        $_SESSION['pending_admission']['attempts'] = 0; // Reset attempts
        
        // Send new OTP
        if (sendOTPEmail($email, $new_otp)) {
            echo json_encode([
                'success' => true,
                'message' => 'New OTP sent'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP']);
        }
        exit;
    }
    
} else {
    // GET request - show test message
    echo json_encode(['success' => false, 'message' => 'Please use POST method']);
}

echo json_encode($response);
?>