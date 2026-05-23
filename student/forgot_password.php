<?php
session_start();

// Get email from URL if exists
$email = isset($_GET['email']) ? htmlspecialchars($_GET['email']) : '';

// Agar email nahi hai toh login page pe redirect
if (empty($email)) {
    header("Location: login.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Forgot Password - Ruchi Classes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Animated background */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.8) 0%, rgba(118, 75, 162, 0.8) 100%);
            z-index: -1;
        }
        
        .circles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        
        .circles li {
            position: absolute;
            display: block;
            list-style: none;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.2);
            animation: animate 25s linear infinite;
            bottom: -150px;
            border-radius: 50%;
        }
        
        .circles li:nth-child(1) {
            left: 25%;
            width: 80px;
            height: 80px;
            animation-delay: 0s;
        }
        
        .circles li:nth-child(2) {
            left: 10%;
            width: 20px;
            height: 20px;
            animation-delay: 2s;
            animation-duration: 12s;
        }
        
        .circles li:nth-child(3) {
            left: 70%;
            width: 20px;
            height: 20px;
            animation-delay: 4s;
        }
        
        .circles li:nth-child(4) {
            left: 40%;
            width: 60px;
            height: 60px;
            animation-delay: 0s;
            animation-duration: 18s;
        }
        
        .circles li:nth-child(5) {
            left: 65%;
            width: 20px;
            height: 20px;
            animation-delay: 0s;
        }
        
        .circles li:nth-child(6) {
            left: 75%;
            width: 110px;
            height: 110px;
            animation-delay: 3s;
        }
        
        .circles li:nth-child(7) {
            left: 35%;
            width: 150px;
            height: 150px;
            animation-delay: 7s;
        }
        
        .circles li:nth-child(8) {
            left: 50%;
            width: 25px;
            height: 25px;
            animation-delay: 15s;
            animation-duration: 45s;
        }
        
        .circles li:nth-child(9) {
            left: 20%;
            width: 15px;
            height: 15px;
            animation-delay: 2s;
            animation-duration: 35s;
        }
        
        .circles li:nth-child(10) {
            left: 85%;
            width: 150px;
            height: 150px;
            animation-delay: 0s;
            animation-duration: 11s;
        }
        
        @keyframes animate {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
                border-radius: 50%;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 40px 35px;
            border-radius: 30px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 480px;
            animation: slideUp 0.6s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 35px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7);
            }
            70% {
                box-shadow: 0 0 0 15px rgba(102, 126, 234, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
            }
        }
        
        h2 {
            text-align: center;
            margin-bottom: 10px;
            color: #333;
            font-weight: 700;
            font-size: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
        }
        
        .info-box {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-left: 4px solid #667eea;
            padding: 18px 20px;
            margin-bottom: 25px;
            border-radius: 12px;
            font-size: 14px;
            color: #444;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.5s ease-out;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }
        
        .info-box i {
            color: #667eea;
            font-size: 24px;
            animation: shake 2s infinite;
        }
        
        @keyframes shake {
            0%, 100% { transform: rotate(0); }
            10%, 30%, 50%, 70%, 90% { transform: rotate(-10deg); }
            20%, 40%, 60%, 80% { transform: rotate(10deg); }
        }
        
        .info-box strong {
            color: #667eea;
            font-weight: 600;
            word-break: break-all;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 25px;
        }
        
        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 18px;
            z-index: 2;
            transition: all 0.3s;
        }
        
        input {
            width: 100%;
            padding: 18px 18px 18px 50px;
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
            color: #333;
        }
        
        input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            outline: none;
            background: #fff;
        }
        
        /* Style for readonly input */
        input[readonly] {
            background: linear-gradient(135deg, #f0f3fa, #e9ecf5);
            cursor: not-allowed;
            border-color: #cbd5e0;
            color: #4a5568;
            font-weight: 500;
            border: 2px dashed #667eea;
        }
        
        input[readonly]:focus {
            border-color: #667eea;
            box-shadow: none;
        }
        
        .email-label {
            position: absolute;
            top: -10px;
            left: 20px;
            background: white;
            padding: 0 10px;
            font-size: 12px;
            color: #667eea;
            font-weight: 600;
            border-radius: 20px;
            z-index: 3;
        }
        
        .info-text {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            color: #666;
            font-size: 14px;
            border: 2px dashed #e0e0e0;
            animation: pulse 2s infinite;
        }
        
        .info-text i {
            color: #667eea;
            margin-right: 8px;
            animation: bounce 1s infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        button {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 15px;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        button:hover::before {
            left: 100%;
        }
        
        button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .spinner {
            display: none;
            width: 22px;
            height: 22px;
            border: 3px solid rgba(255, 255, 255, 0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        button.loading .spinner {
            display: inline-block;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .back-link {
            margin-top: 25px;
            text-align: center;
        }
        
        .back-link a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            padding: 10px 20px;
            border-radius: 10px;
            background: #f8f9fa;
        }
        
        .back-link a:hover {
            color: #667eea;
            gap: 12px;
            background: #fff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .back-link a i {
            font-size: 14px;
            transition: transform 0.3s;
        }
        
        .back-link a:hover i {
            transform: translateX(-5px);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 30px 25px;
            }
            
            h2 {
                font-size: 24px;
            }
            
            .logo-icon {
                width: 70px;
                height: 70px;
                font-size: 30px;
            }
            
            input {
                padding: 16px 16px 16px 45px;
                font-size: 14px;
            }
            
            button {
                padding: 16px;
                font-size: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 25px 20px;
            }
            
            h2 {
                font-size: 22px;
            }
            
            .logo-icon {
                width: 60px;
                height: 60px;
                font-size: 26px;
            }
            
            .info-box {
                padding: 15px;
                gap: 12px;
            }
            
            .info-box i {
                font-size: 20px;
            }
            
            .info-box span {
                font-size: 13px;
            }
        }
        
        /* Password strength indicator (for reset page) */
        .password-strength {
            margin-top: 10px;
            height: 5px;
            border-radius: 5px;
            background: #e0e0e0;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
            border-radius: 5px;
        }
        
        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- Animated background circles -->
    <ul class="circles">
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
    </ul>

    <div class="container">
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-key"></i>
            </div>
            <h2>Forgot Password?</h2>
            <div class="subtitle">Don't worry, we'll help you reset it</div>
        </div>
        
        <!-- Info box showing which email is being used -->
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <span>Password reset for: <strong><?php echo htmlspecialchars($email); ?></strong></span>
        </div>
        
        <form id="forgotForm">
            <div class="input-group">
                <i class="input-icon fas fa-envelope"></i>
                <span class="email-label">Email Address</span>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    value="<?php echo htmlspecialchars($email); ?>" 
                    readonly
                    required
                >
            </div>
            
            <div class="info-text">
                <i class="fas fa-envelope-open-text"></i>
                An OTP will be sent to this email address
            </div>
            
            <button type="submit" id="submitBtn">
                <i class="fas fa-paper-plane"></i>
                <span class="btn-text">Send OTP</span>
                <span class="spinner"></span>
            </button>
        </form>
        
        <div class="back-link">
            <a href="login.html">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.getElementById('forgotForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value.trim();
            const submitBtn = document.getElementById('submitBtn');
            
            // Email validation (though it's readonly, still check)
            if (!email || !email.includes('@') || !email.includes('.')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Email',
                    text: 'The email address is not valid. Please go back and try again.',
                    confirmButtonColor: '#667eea',
                    background: '#fff',
                    backdrop: true,
                    allowOutsideClick: false,
                    confirmButtonText: 'Go to Login',
                    showClass: {
                        popup: 'animate__animated animate__fadeInDown'
                    },
                    hideClass: {
                        popup: 'animate__animated animate__fadeOutUp'
                    }
                }).then(() => {
                    window.location.href = 'login.html';
                });
                return;
            }
            
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            
            fetch('process_forgot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'send_otp', email: email })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'OTP Sent!',
                        text: 'Check your email for OTP',
                        timer: 2000,
                        showConfirmButton: false,
                        background: '#fff',
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                        },
                        willClose: () => {
                            window.location.href = `reset_password.php?email=${encodeURIComponent(email)}`;
                        }
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message,
                        confirmButtonColor: '#667eea',
                        background: '#fff'
                    });
                    submitBtn.classList.remove('loading');
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please try again',
                    confirmButtonColor: '#667eea',
                    background: '#fff'
                });
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            });
        });
        
        // Disable any attempts to edit the email field
        document.getElementById('email').addEventListener('keydown', function(e) {
            e.preventDefault();
            return false;
        });
        
        document.getElementById('email').addEventListener('paste', function(e) {
            e.preventDefault();
            return false;
        });
        
        // Add floating effect to container
        const container = document.querySelector('.container');
        let mouseX = 0, mouseY = 0;
        let containerX = 0, containerY = 0;
        
        document.addEventListener('mousemove', (e) => {
            mouseX = (e.clientX / window.innerWidth - 0.5) * 10;
            mouseY = (e.clientY / window.innerHeight - 0.5) * 10;
        });
        
        function animate() {
            containerX += (mouseX - containerX) * 0.05;
            containerY += (mouseY - containerY) * 0.05;
            
            container.style.transform = `perspective(1000px) rotateY(${containerX}deg) rotateX(${-containerY}deg) translateY(-5px)`;
            
            requestAnimationFrame(animate);
        }
        
        animate();
        
        // Add particle effect on button click
        document.getElementById('submitBtn').addEventListener('click', function(e) {
            if (!this.classList.contains('loading')) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const particle = document.createElement('span');
                particle.style.position = 'absolute';
                particle.style.width = '10px';
                particle.style.height = '10px';
                particle.style.background = 'white';
                particle.style.borderRadius = '50%';
                particle.style.left = x + 'px';
                particle.style.top = y + 'px';
                particle.style.pointerEvents = 'none';
                particle.style.animation = 'ripple 0.6s ease-out';
                
                this.appendChild(particle);
                
                setTimeout(() => {
                    particle.remove();
                }, 600);
            }
        });
        
        // Add ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                0% {
                    transform: scale(1);
                    opacity: 1;
                }
                100% {
                    transform: scale(30);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>