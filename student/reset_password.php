<?php
session_start();

require_once __DIR__ . '/../includes/site_settings.php';
site_settings_start_favicon_buffer(null);

// Get email from URL
$email = isset($_GET['email']) ? htmlspecialchars($_GET['email']) : '';

// Agar email nahi hai toh redirect
if (empty($email)) {
    header("Location: forgot_password.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reset Password - Ruchi Classes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { 
            font-family: 'Segoe UI', sans-serif; 
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh;
            padding: 20px;
        }
        .container { 
            background: #fff; 
            padding: 30px 25px; 
            border-radius: 20px; 
            box-shadow: 0 15px 50px rgba(0,0,0,0.2); 
            width: 100%; 
            max-width: 450px;
            animation: fadeIn 0.5s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        h2 { 
            text-align: center; 
            margin-bottom: 25px; 
            color: #333; 
            font-weight: 600;
        }
        h2 i { margin-right: 10px; color: #4CAF50; }
        
        .info-box {
            background: #e8f4fd;
            border-left: 4px solid #4CAF50;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 14px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .otp-inputs {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin: 25px 0;
            flex-wrap: wrap;
        }
        
        .otp-input {
            width: 55px;
            height: 65px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 32px;
            font-weight: bold;
            text-align: center;
            color: #333;
            background: #f9f9f9;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            line-height: 65px;
            padding: 0;
            margin: 0;
            outline: none;
        }
        
        .otp-input:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
            background: #fff;
        }
        
        @media (max-width: 768px) {
            .otp-input {
                width: 50px;
                height: 60px;
                font-size: 28px;
                line-height: 60px;
            }
        }
        
        .otp-input::-webkit-outer-spin-button,
        .otp-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        .timer {
            text-align: center;
            font-size: 18px;
            margin: 15px 0;
            color: #666;
        }
        .timer span {
            font-weight: bold;
            color: #4CAF50;
        }
        
        .input-group { 
            position: relative; 
            margin-bottom: 20px; 
        }
        input { 
            width: 100%; 
            padding: 14px 14px 14px 45px; 
            border: 2px solid #e0e0e0; 
            border-radius: 10px; 
            font-size: 16px; 
            transition: all 0.3s; 
            background: #f9f9f9;
        }
        input:focus { 
            border-color: #4CAF50; 
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2); 
            outline: none;
            background: #fff;
        }
        /* Style for readonly email */
        .email-display {
            background: #f0f0f0;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            color: #333;
            border: 1px solid #ddd;
        }
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 18px;
            z-index: 2;
        }
        .password-toggle { 
            position:absolute; 
            right:15px; 
            top:50%; 
            transform:translateY(-50%); 
            cursor:pointer; 
            color:#999; 
            z-index: 2;
        }
        button { 
            width: 100%; 
            padding: 16px; 
            background: linear-gradient(to right, #4CAF50, #2E8B57); 
            color: white; 
            font-size: 17px; 
            font-weight: 600; 
            border: none; 
            border-radius: 10px; 
            cursor: pointer; 
            transition: 0.3s; 
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
            min-height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        button:hover { 
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }
        .spinner {
            display: none;
            width: 22px;
            height: 22px;
            border: 3px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 10px;
        }
        button.loading .spinner {
            display: inline-block;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .back-link {
            margin-top: 15px;
            text-align: center;
        }
        .back-link a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }
        .resend-link {
            text-align: center;
            margin: 15px 0;
        }
        .resend-link a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-lock"></i> Reset Password</h2>
        
        <form id="resetForm">
            <input type="hidden" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>">
            
            <div class="info-box">
                <i class="fas fa-envelope" style="color: #4CAF50;"></i>
                <span>Resetting password for: <strong><?php echo htmlspecialchars($email); ?></strong></span>
            </div>
            
            <p style="text-align: center; margin-bottom: 10px;">Enter 6-digit OTP</p>
            <div class="otp-inputs">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="0">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="1">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="2">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="3">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="4">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="5">
            </div>
            
            <div class="timer" id="otpTimer">
                <i class="fas fa-hourglass-half"></i> <span id="minutes">05</span>:<span id="seconds">00</span> remaining
            </div>
            
            <div class="resend-link">
                <a href="#" id="resendOtp">Didn't receive OTP? Resend</a>
            </div>
            
            <div class="input-group">
                <i class="input-icon fas fa-lock"></i>
                <input type="password" id="newPassword" name="newPassword" required placeholder="Enter new password">
                <span class="password-toggle" id="toggleNewPass">
                    <i class="fas fa-eye"></i>
                </span>
            </div>
            
            <div class="input-group">
                <i class="input-icon fas fa-lock"></i>
                <input type="password" id="confirmPassword" name="confirmPassword" required placeholder="Confirm new password">
                <span class="password-toggle" id="toggleConfirmPass">
                    <i class="fas fa-eye"></i>
                </span>
            </div>
            
            <button type="submit" id="submitBtn">
                <span class="btn-text">Reset Password</span>
                <span class="spinner"></span>
            </button>
        </form>
        
        <div class="back-link">
            <a href="login.html"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const email = document.getElementById('email').value;
        
        if (!email) {
            window.location.href = 'forgot_password.php';
        }
        
        let timerInterval;
        
        // OTP Input handling
        document.querySelectorAll('.otp-input').forEach((input, index) => {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 1);
                
                if (this.value.length === 1) {
                    const next = document.querySelector(`.otp-input[data-index="${index + 1}"]`);
                    if (next) next.focus();
                }
            });
            
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.value.length === 0) {
                    const prev = document.querySelector(`.otp-input[data-index="${index - 1}"]`);
                    if (prev) {
                        prev.focus();
                        prev.value = '';
                    }
                }
            });
        });
        
        // Password toggles
        function setupPasswordToggle(inputId, toggleId) {
            const input = document.getElementById(inputId);
            const toggle = document.getElementById(toggleId);
            
            toggle.addEventListener('click', () => {
                if (input.type === 'password') {
                    input.type = 'text';
                    toggle.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    input.type = 'password';
                    toggle.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
        }
        
        setupPasswordToggle('newPassword', 'toggleNewPass');
        setupPasswordToggle('confirmPassword', 'toggleConfirmPass');
        
        // Timer function
        function startTimer(duration) {
            let timer = duration;
            clearInterval(timerInterval);
            
            timerInterval = setInterval(() => {
                const minutes = Math.floor(timer / 60);
                const seconds = timer % 60;
                
                document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
                document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
                
                if (--timer < 0) {
                    clearInterval(timerInterval);
                    document.getElementById('otpTimer').innerHTML = '<span style="color: #e74c3c;">OTP expired. Please resend.</span>';
                }
            }, 1000);
        }
        
        startTimer(300); // 5 minutes
        
        // Resend OTP
        document.getElementById('resendOtp').addEventListener('click', function(e) {
            e.preventDefault();
            
            const link = this;
            link.style.pointerEvents = 'none';
            link.style.opacity = '0.5';
            link.textContent = 'Sending...';
            
            fetch('process_forgot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resend_otp', email: email })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'OTP Resent',
                        text: 'Check your email for new OTP',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    
                    clearInterval(timerInterval);
                    startTimer(300);
                    
                    // Clear OTP inputs
                    document.querySelectorAll('.otp-input').forEach(input => input.value = '');
                    document.querySelector('.otp-input')?.focus();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'))
            .finally(() => {
                setTimeout(() => {
                    link.style.pointerEvents = 'auto';
                    link.style.opacity = '1';
                    link.textContent = "Didn't receive OTP? Resend";
                }, 30000);
            });
        });
        
        // Form submission
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const otpInputs = document.querySelectorAll('.otp-input');
            let otp = '';
            otpInputs.forEach(input => otp += input.value);
            
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (otp.length !== 6) {
                Swal.fire('Error', 'Please enter complete 6-digit OTP', 'error');
                return;
            }
            
            if (!newPassword || !confirmPassword) {
                Swal.fire('Error', 'Please enter both password fields', 'error');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                Swal.fire('Error', 'Passwords do not match', 'error');
                return;
            }
            
            if (newPassword.length < 6) {
                Swal.fire('Error', 'Password must be at least 6 characters', 'error');
                return;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            
            fetch('process_forgot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reset_password',
                    email: email,
                    otp: otp,
                    password: newPassword
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Password Reset Successfully!',
                        text: 'You can now login with your new password',
                        confirmButtonColor: '#4CAF50'
                    }).then(() => {
                        window.location.href = 'login.html';
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'))
            .finally(() => {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            });
        });
    </script>
</body>
</html>
