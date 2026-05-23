<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/otp_functions.php';

$otpHandler = new OTPHandler();
$response = ['success' => false, 'message' => ''];

$action = $_POST['action'] ?? '';

switch($action) {
    case 'verify':
        $email = $_POST['email'] ?? '';
        $otp = $_POST['otp'] ?? '';
        
        if (empty($email) || empty($otp)) {
            $response['message'] = 'Email and OTP are required';
        } else {
            $response = $otpHandler->verifyOTP($email, $otp);
        }
        break;
        
    case 'resend':
        $email = $_POST['email'] ?? '';
        
        if (empty($email)) {
            $response['message'] = 'Email is required';
        } else {
            $pendingData = $otpHandler->getPendingStudentData($email);
            
            if ($pendingData) {
                $otp = $otpHandler->generateOTP();
                $studentName = $pendingData['first_name'] . ' ' . $pendingData['last_name'];
                
                $saved = $otpHandler->saveOTP($email, $otp, $pendingData);
                
                if ($saved) {
                    $emailSent = $otpHandler->sendEmailOTP($email, $otp, $studentName);
                    
                    if ($emailSent['success']) {
                        $response['success'] = true;
                        $response['message'] = 'OTP resent successfully';
                        
                        if (TEST_MODE) {
                            $response['test_otp'] = $otp;
                        }
                    } else {
                        $response['message'] = 'Failed to send email';
                    }
                } else {
                    $response['message'] = 'Failed to resend OTP';
                }
            } else {
                $response['message'] = 'No pending admission found';
            }
        }
        break;
        
    default:
        $response['message'] = 'Invalid action';
}

echo json_encode($response);
?>