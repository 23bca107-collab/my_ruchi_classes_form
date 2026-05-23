<?php
require_once 'db_connection.php';
require_once 'config.php';

class OTPHandler {
    private $db;
    private $conn;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
        $this->createOTPTable();
    }
    
    /**
     * Create OTP table if not exists
     */
    private function createOTPTable() {
        $sql = "CREATE TABLE IF NOT EXISTS otp_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(150) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            student_data TEXT,
            purpose VARCHAR(50) DEFAULT 'admission',
            expires_at DATETIME NOT NULL,
            attempts INT DEFAULT 0,
            verified_at DATETIME NULL,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->conn->exec($sql);
    }
    
    /**
     * Generate OTP
     */
    public function generateOTP() {
        if (TEST_MODE) {
            return TEST_OTP; // Return fixed OTP in test mode
        }
        
        $otp = '';
        for ($i = 0; $i < OTP_LENGTH; $i++) {
            $otp .= random_int(0, 9);
        }
        return $otp;
    }
    
    /**
     * Send OTP via Email using PHPMailer
     */
    public function sendEmailOTP($to, $otp, $studentName) {
        require_once '../PHPMailer/PHPMailer.php';
        require_once '../PHPMailer/SMTP.php';
        require_once '../PHPMailer/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            
            // Recipients
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Email Verification - Ruchi Classes Admission';
            $mail->Body    = $this->getEmailTemplate($otp, $studentName);
            $mail->AltBody = "Your OTP for admission is: $otp. Valid for " . OTP_EXPIRY_MINUTES . " minutes.";
            
            $mail->send();
            return ['success' => true, 'message' => 'OTP sent successfully'];
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo);
            return ['success' => false, 'message' => 'Failed to send email'];
        }
    }
    
    /**
     * Email HTML Template
     */
    private function getEmailTemplate($otp, $studentName) {
        $expiryMinutes = OTP_EXPIRY_MINUTES;
        $year = date('Y');
        $siteName = SMTP_FROM_NAME;
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 40px 30px; }
                .otp-box { background: #f8f9fa; border: 3px dashed #667eea; border-radius: 15px; padding: 25px; margin: 25px 0; text-align: center; }
                .otp-code { font-size: 48px; font-weight: bold; letter-spacing: 10px; color: #667eea; font-family: monospace; }
                .timer { color: #e74c3c; font-weight: 600; margin: 20px 0; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 14px; }
                .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-top: 20px; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🏫 $siteName</h1>
                    <p>Student Admission Verification</p>
                </div>
                
                <div class="content">
                    <h2>Hello $studentName,</h2>
                    <p>Thank you for applying to <strong>$siteName</strong>. Please verify your email address to complete the admission process.</p>
                    
                    <div class="otp-box">
                        <div style="font-size: 18px; color: #666; margin-bottom: 15px;">Your OTP Code:</div>
                        <div class="otp-code">$otp</div>
                    </div>
                    
                    <div class="timer">
                        ⏰ This OTP is valid for <strong>$expiryMinutes minutes</strong>
                    </div>
                    
                    <div class="warning">
                        <strong>⚠️ Important:</strong>
                        <ul style="margin-top: 10px;">
                            <li>Never share this OTP with anyone</li>
                            <li>Our staff will never ask for your OTP</li>
                            <li>Maximum 3 verification attempts allowed</li>
                        </ul>
                    </div>
                </div>
                
                <div class="footer">
                    <p>&copy; $year $siteName. All rights reserved.</p>
                    <p>For any assistance, contact: support@ruchiclasses.com</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
    
    /**
     * Save OTP with student data
     */
    public function saveOTP($email, $otp, $studentData) {
        try {
            // Delete old unverified OTPs
            $deleteSql = "DELETE FROM otp_verifications WHERE email = :email AND verified_at IS NULL";
            $deleteStmt = $this->conn->prepare($deleteSql);
            $deleteStmt->execute([':email' => $email]);
            
            // Insert new OTP
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $studentDataJson = json_encode($studentData);
            
            $sql = "INSERT INTO otp_verifications (email, otp_code, student_data, expires_at, ip_address) 
                    VALUES (:email, :otp, :data, :expires, :ip)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':email' => $email,
                ':otp' => $otp,
                ':data' => $studentDataJson,
                ':expires' => $expiresAt,
                ':ip' => $ipAddress
            ]);
            
            return $this->conn->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Save OTP error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify OTP and get student data
     */
    public function verifyOTP($email, $otp) {
        try {
            // Get latest valid OTP
            $sql = "SELECT * FROM otp_verifications 
                    WHERE email = :email 
                    AND verified_at IS NULL 
                    AND expires_at > NOW()
                    ORDER BY id DESC LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':email' => $email]);
            $record = $stmt->fetch();
            
            if (!$record) {
                return ['success' => false, 'message' => 'No valid OTP found. Request new OTP.'];
            }
            
            // Check attempts
            if ($record['attempts'] >= MAX_OTP_ATTEMPTS) {
                return ['success' => false, 'message' => 'Maximum attempts exceeded. Request new OTP.'];
            }
            
            // Verify OTP
            if ($record['otp_code'] == $otp) {
                // Mark as verified
                $updateSql = "UPDATE otp_verifications SET verified_at = NOW() WHERE id = :id";
                $updateStmt = $this->conn->prepare($updateSql);
                $updateStmt->execute([':id' => $record['id']]);
                
                return [
                    'success' => true,
                    'message' => 'OTP verified successfully',
                    'student_data' => json_decode($record['student_data'], true)
                ];
                
            } else {
                // Increment attempts
                $updateSql = "UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = :id";
                $updateStmt = $this->conn->prepare($updateSql);
                $updateStmt->execute([':id' => $record['id']]);
                
                $remaining = MAX_OTP_ATTEMPTS - ($record['attempts'] + 1);
                return [
                    'success' => false,
                    'message' => "Invalid OTP. $remaining attempts remaining."
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Verify OTP error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Verification failed. Try again.'];
        }
    }
}
?>