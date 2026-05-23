<?php
session_start();

// Database connection
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
}

// Function to show alerts
function showAlert($icon, $title, $text, $redirect = null) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Signup - Ruchi Classes</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { 
                background: linear-gradient(135deg, #1a2980 0%, #26d0ce 100%);
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
                color: #28a745;
                margin-bottom: 20px;
                animation: bounce 1s ease infinite alternate;
            }
            @keyframes bounce {
                from { transform: translateY(0); }
                to { transform: translateY(-10px); }
            }
        </style>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: "'.$icon.'",
                title: "'.$title.'",
                html: `<div style="text-align: center;">
                    <i class="fas fa-user-plus success-icon"></i>
                    <h3 style="margin: 10px 0; color: #333;">'.$text.'</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-top: 20px;">
                        <i class="fas fa-shield-alt" style="color: #28a745;"></i>
                        <span style="color: #666; font-size: 14px;">Admin Account Created Successfully</span>
                    </div>
                </div>`,
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                allowOutsideClick: false,
                allowEscapeKey: false,
                willClose: () => {
                    window.location.href = "'.$redirect.'";
                }
            });
        </script>
    </body>
    </html>';
    exit;
}

function showErrorAlert($icon, $title, $text) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Signup Error</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body style="background: linear-gradient(135deg, #1a2980 0%, #26d0ce 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0;">
        <script>
            Swal.fire({
                icon: "'.$icon.'",
                title: "'.$title.'",
                text: "'.$text.'",
                confirmButtonText: "Try Again",
                confirmButtonColor: "#667eea",
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data - ONLY email and password
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    // Validate inputs
    if (empty($email) || empty($password) || empty($confirm_password)) {
        showErrorAlert('error', 'Missing Information', 'Please fill in all required fields.');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        showErrorAlert('error', 'Invalid Email', 'Please enter a valid email address.');
    }
    
    if (strlen($password) < 6) {
        showErrorAlert('error', 'Weak Password', 'Password must be at least 6 characters long.');
    }
    
    if ($password !== $confirm_password) {
        showErrorAlert('error', 'Password Mismatch', 'Passwords do not match.');
    }
    
    // Check if email already exists
    $check_stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        showErrorAlert('error', 'Email Exists', 'This email is already registered.');
    }
    
    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Generate a temporary name from email
    $temp_name = explode('@', $email)[0];
    $temp_name = ucfirst(str_replace(['.', '_', '-'], ' ', $temp_name));
    
    // Insert into database - minimal data
    $stmt = $conn->prepare("INSERT INTO admins (name, email, password, admin_type, profile_completed) VALUES (?, ?, ?, 'second_admin', 0)");
    $stmt->bind_param("sss", $temp_name, $email, $hashed_password);
    
    if ($stmt->execute()) {
        // Get the new admin ID
        $admin_id = $stmt->insert_id;
        
        // Show success message
        showAlert('success', 'Registration Successful!', 'Admin account has been created. Please login to continue.', 'admin_login.php');
    } else {
        showErrorAlert('error', 'Registration Failed', 'Could not create admin account. Please try again.');
    }
}

// If accessed directly, show the form
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Admin Signup - Ruchi Classes</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { 
        margin:0; 
        padding:0; 
        box-sizing:border-box; 
        -webkit-tap-highlight-color: transparent;
    }
    html {
        height: 100%;
        overflow-x: hidden;
    }
    body { 
        font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        background: linear-gradient(135deg, #1a2980 0%, #26d0ce 100%); 
        display:flex; 
        justify-content:center; 
        align-items:center; 
        min-height:100vh;
        padding: 20px;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    .signup-container { 
        background:#fff; 
        padding: 40px 35px; 
        border-radius:20px; 
        box-shadow:0 20px 60px rgba(0,0,0,0.3); 
        width:100%; 
        max-width:500px;
        min-width: 320px;
        position: relative;
        overflow: hidden;
        animation: fadeIn 0.5s ease-out;
        margin: auto;
        border: 2px solid rgba(102, 126, 234, 0.1);
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .signup-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 8px;
        background: linear-gradient(to right, #667eea, #764ba2);
    }
    .logo-container {
        text-align: center;
        margin-bottom: 30px;
    }
    .logo {
        max-width: 180px;
        height: auto;
        margin-bottom: 20px;
        border-radius: 12px;
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
    }
    h2 { 
        text-align:center; 
        margin-bottom:30px; 
        color:#333; 
        font-weight: 700;
        font-size: 28px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    h2::after {
        content: '';
        position: absolute;
        bottom: -12px;
        left: 50%;
        transform: translateX(-50%);
        width: 60px;
        height: 4px;
        background: linear-gradient(to right, #667eea, #764ba2);
        border-radius: 4px;
    }
    .input-group { 
        position:relative; 
        margin-bottom:25px; 
    }
    input { 
        width:100%; 
        padding: 16px 16px 16px 50px; 
        border: 2px solid #e0e0e0; 
        border-radius:12px; 
        font-size:16px; 
        transition: all 0.3s ease; 
        background: #f8f9fa;
        -webkit-appearance: none;
        appearance: none;
        color: #333;
    }
    input:focus { 
        border-color:#667eea; 
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.2); 
        outline: none;
        background: #fff;
    }
    @media screen and (max-width: 768px) {
        input, select, textarea {
            font-size: 16px !important;
        }
    }
    .input-group label { 
        position:absolute; 
        top:50%; 
        left:50px; 
        transform:translateY(-50%); 
        background: transparent; 
        padding:0 5px; 
        color:#999; 
        pointer-events:none; 
        transition:0.3s; 
        font-size: 16px;
    }
    input:focus + label, 
    input:not(:placeholder-shown) + label { 
        top: -10px; 
        left: 20px;
        font-size:13px; 
        color:#667eea; 
        font-weight:600; 
        background: white;
        padding: 0 8px;
    }
    .input-icon {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        font-size: 20px;
        transition: 0.3s;
        z-index: 2;
    }
    input:focus ~ .input-icon {
        color: #667eea;
    }
    .password-toggle { 
        position:absolute; 
        right:18px; 
        top:50%; 
        transform:translateY(-50%); 
        cursor:pointer; 
        color:#999; 
        font-size: 18px;
        transition: 0.3s;
        z-index: 2;
        padding: 10px;
        -webkit-touch-callout: none;
        -webkit-user-select: none;
        user-select: none;
    }
    .password-toggle:hover, .password-toggle:active {
        color: #667eea;
    }
    button { 
        width:100%; 
        padding:18px; 
        background:linear-gradient(to right, #667eea, #764ba2); 
        color:white; 
        font-size:18px; 
        font-weight:600; 
        border:none; 
        border-radius:12px; 
        cursor:pointer; 
        transition:0.3s; 
        margin-top: 20px;
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        position: relative;
        overflow: hidden;
        -webkit-touch-callout: none;
        -webkit-user-select: none;
        user-select: none;
        touch-action: manipulation;
        min-height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        letter-spacing: 0.5px;
    }
    button:hover { 
        transform:translateY(-3px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }
    button:active {
        transform: translateY(0);
    }
    button:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none !important;
    }
    
    /* Password strength indicator */
    .password-strength {
        height: 5px;
        border-radius: 3px;
        margin-top: 8px;
        transition: all 0.3s;
        display: none;
    }
    
    .strength-weak { background: #dc3545; width: 25%; }
    .strength-fair { background: #ffc107; width: 50%; }
    .strength-good { background: #28a745; width: 75%; }
    .strength-strong { background: #20c997; width: 100%; }
    
    .strength-text {
        font-size: 12px;
        margin-top: 5px;
        text-align: right;
        display: none;
    }
    
    .strength-text.weak { color: #dc3545; }
    .strength-text.fair { color: #ffc107; }
    .strength-text.good { color: #28a745; }
    .strength-text.strong { color: #20c997; }
    
    /* Loading button */
    button.loading {
        pointer-events: none;
        opacity: 0.9;
    }
    
    button.loading .btn-text {
        opacity: 0;
        transition: opacity 0.2s;
    }
    
    button .spinner {
        display: none;
        width: 24px;
        height: 24px;
        border: 3px solid rgba(255,255,255,0.4);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
    
    button.loading .spinner {
        display: block;
    }
    
    @keyframes spin {
        0% {
            transform: translate(-50%, -50%) rotate(0deg);
        }
        100% {
            transform: translate(-50%, -50%) rotate(360deg);
        }
    }
    
    .message { 
        text-align:center; 
        margin-top:25px; 
        font-size:15px; 
        color:#555; 
    }
    .message a { 
        color:#667eea; 
        text-decoration:none; 
        font-weight:600; 
        transition: 0.2s;
        position: relative;
    }
    .message a::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 0;
        height: 2px;
        background: #667eea;
        transition: width 0.3s ease;
    }
    .message a:hover::after {
        width: 100%;
    }
    
    .requirements {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        margin-top: 10px;
        font-size: 13px;
        color: #666;
        border-left: 4px solid #667eea;
    }
    
    .requirements ul {
        margin: 8px 0 0 20px;
        padding: 0;
    }
    
    .requirements li {
        margin-bottom: 5px;
    }
    
    .requirements .valid {
        color: #28a745;
    }
    
    .requirements .invalid {
        color: #dc3545;
    }
    
    .form-note {
        background: #e8f4fd;
        padding: 12px;
        border-radius: 8px;
        margin: 20px 0;
        border-left: 4px solid #2196F3;
        font-size: 14px;
        color: #1976D2;
    }
    
    .form-note i {
        margin-right: 8px;
    }
    
    /* Mobile-specific optimizations */
    @media (max-width: 768px) {
        body {
            padding: 15px;
        }
        
        .signup-container {
            padding: 30px 25px;
            border-radius: 18px;
        }
        
        h2 {
            font-size: 24px;
        }
        
        .logo {
            max-width: 140px;
        }
        
        input {
            padding: 16px 16px 16px 50px;
        }
        
        button {
            padding: 17px;
            font-size: 17px;
        }
        
        .requirements {
            padding: 12px;
            font-size: 12px;
        }
        
        .form-note {
            padding: 10px;
            font-size: 13px;
        }
    }
    
    @media (max-width: 480px) {
        .signup-container {
            padding: 25px 20px;
        }
        
        h2 {
            font-size: 22px;
        }
        
        .logo {
            max-width: 120px;
        }
        
        input {
            padding: 15px 15px 15px 45px;
        }
        
        .input-icon {
            left: 15px;
            font-size: 18px;
        }
        
        .password-toggle {
            right: 15px;
        }
    }
    
    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        .signup-container {
            background: #1e1e1e;
            color: #f0f0f0;
        }
        
        h2 {
            color: #f0f0f0;
        }
        
        input {
            background: #2d2d2d;
            border-color: #444;
            color: #f0f0f0;
        }
        
        input:focus {
            background: #333;
        }
        
        input:focus + label {
            background: #1e1e1e;
        }
        
        .requirements {
            background: #2d2d2d;
            border-color: #444;
            color: #aaa;
        }
        
        .form-note {
            background: #1e3a5f;
            border-color: #2196F3;
            color: #90caf9;
        }
    }
</style>
</head>
<body>

<div class="signup-container">
    <div class="logo-container">
        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo" onerror="this.onerror=null; this.src='https://via.placeholder.com/180/667eea/FFFFFF?text=Ruchi+Classes';">
        <h2><i class="fas fa-user-plus"></i> Admin Registration</h2>
    </div>
    
    <div class="form-note">
        <i class="fas fa-info-circle"></i>
        Only email and password are required for registration. You can complete your profile after login.
    </div>
    
    <form action="admin_signup.php" method="POST" id="signupForm">
        <div class="input-group">
            <i class="input-icon fas fa-envelope"></i>
            <input type="email" name="email" placeholder=" " required autocomplete="email">
            <label>Email Address</label>
        </div>
        
        <div class="input-group">
            <i class="input-icon fas fa-lock"></i>
            <input type="password" name="password" placeholder=" " required id="passwordInput" autocomplete="new-password">
            <label>Password</label>
            <span class="password-toggle" id="togglePass" role="button" aria-label="Show password">
                <i class="fas fa-eye"></i>
            </span>
            <div class="password-strength" id="passwordStrength"></div>
            <div class="strength-text" id="strengthText"></div>
        </div>
        
        <div class="input-group">
            <i class="input-icon fas fa-lock"></i>
            <input type="password" name="confirm_password" placeholder=" " required id="confirmPasswordInput" autocomplete="new-password">
            <label>Confirm Password</label>
            <span class="password-toggle" id="toggleConfirmPass" role="button" aria-label="Show password">
                <i class="fas fa-eye"></i>
            </span>
            <div class="strength-text" id="passwordMatch"></div>
        </div>
        
        <div class="requirements">
            <strong><i class="fas fa-shield-alt"></i> Password Requirements:</strong>
            <ul>
                <li id="reqLength" class="invalid">Minimum 6 characters</li>
                <li id="reqUppercase" class="invalid">At least one uppercase letter</li>
                <li id="reqLowercase" class="invalid">At least one lowercase letter</li>
                <li id="reqNumber" class="invalid">At least one number</li>
            </ul>
        </div>
        
        <button type="submit" id="signupButton">
            <span class="btn-text">Create Admin Account</span>
            <div class="spinner"></div>
        </button>
        
        <p class="message">
            Already have an account? <a href="admin_login.php">Login here</a>
        </p>
    </form>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
    // Password toggle functionality
    const passwordInput = document.getElementById('passwordInput');
    const confirmPasswordInput = document.getElementById('confirmPasswordInput');
    const togglePass = document.getElementById('togglePass');
    const toggleConfirmPass = document.getElementById('toggleConfirmPass');
    const eyeIcon = togglePass.querySelector('i');
    const eyeConfirmIcon = toggleConfirmPass.querySelector('i');
    
    togglePass.addEventListener('click', () => {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
            togglePass.setAttribute('aria-label', 'Hide password');
        } else {
            passwordInput.type = 'password';
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
            togglePass.setAttribute('aria-label', 'Show password');
        }
    });
    
    toggleConfirmPass.addEventListener('click', () => {
        if (confirmPasswordInput.type === 'password') {
            confirmPasswordInput.type = 'text';
            eyeConfirmIcon.classList.remove('fa-eye');
            eyeConfirmIcon.classList.add('fa-eye-slash');
            toggleConfirmPass.setAttribute('aria-label', 'Hide password');
        } else {
            confirmPasswordInput.type = 'password';
            eyeConfirmIcon.classList.remove('fa-eye-slash');
            eyeConfirmIcon.classList.add('fa-eye');
            toggleConfirmPass.setAttribute('aria-label', 'Show password');
        }
    });
    
    // Password strength checker
    function checkPasswordStrength(password) {
        let strength = 0;
        const strengthBar = document.getElementById('passwordStrength');
        const strengthText = document.getElementById('strengthText');
        
        // Requirements
        const reqLength = document.getElementById('reqLength');
        const reqUppercase = document.getElementById('reqUppercase');
        const reqLowercase = document.getElementById('reqLowercase');
        const reqNumber = document.getElementById('reqNumber');
        
        // Reset
        strengthBar.style.display = 'none';
        strengthText.style.display = 'none';
        reqLength.className = 'invalid';
        reqUppercase.className = 'invalid';
        reqLowercase.className = 'invalid';
        reqNumber.className = 'invalid';
        
        if (password.length === 0) return;
        
        // Check requirements
        if (password.length >= 6) {
            strength += 1;
            reqLength.className = 'valid';
        }
        
        if (/[A-Z]/.test(password)) {
            strength += 1;
            reqUppercase.className = 'valid';
        }
        
        if (/[a-z]/.test(password)) {
            strength += 1;
            reqLowercase.className = 'valid';
        }
        
        if (/[0-9]/.test(password)) {
            strength += 1;
            reqNumber.className = 'valid';
        }
        
        // Show strength indicator
        strengthBar.style.display = 'block';
        strengthText.style.display = 'block';
        
        if (strength === 1) {
            strengthBar.className = 'password-strength strength-weak';
            strengthText.className = 'strength-text weak';
            strengthText.textContent = 'Weak password';
        } else if (strength === 2) {
            strengthBar.className = 'password-strength strength-fair';
            strengthText.className = 'strength-text fair';
            strengthText.textContent = 'Fair password';
        } else if (strength === 3) {
            strengthBar.className = 'password-strength strength-good';
            strengthText.className = 'strength-text good';
            strengthText.textContent = 'Good password';
        } else if (strength === 4) {
            strengthBar.className = 'password-strength strength-strong';
            strengthText.className = 'strength-text strong';
            strengthText.textContent = 'Strong password';
        }
    }
    
    // Password match checker
    function checkPasswordMatch() {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        const matchText = document.getElementById('passwordMatch');
        
        if (confirmPassword.length === 0) {
            matchText.style.display = 'none';
            return;
        }
        
        matchText.style.display = 'block';
        
        if (password === confirmPassword && password.length >= 6) {
            matchText.className = 'strength-text strong';
            matchText.textContent = '✓ Passwords match';
            matchText.style.color = '#28a745';
        } else if (password === confirmPassword) {
            matchText.className = 'strength-text weak';
            matchText.textContent = '⚠ Passwords match but too short';
            matchText.style.color = '#ffc107';
        } else {
            matchText.className = 'strength-text weak';
            matchText.textContent = '✗ Passwords do not match';
            matchText.style.color = '#dc3545';
        }
    }
    
    // Event listeners for password checking
    passwordInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
    });
    
    confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    
    // Loading animation
    function showLoading() {
        const signupButton = document.getElementById('signupButton');
        signupButton.classList.add('loading');
        signupButton.disabled = true;
    }
    
    function hideLoading() {
        const signupButton = document.getElementById('signupButton');
        signupButton.classList.remove('loading');
        signupButton.disabled = false;
    }
    
    // Form validation
    const signupForm = document.getElementById('signupForm');
    
    signupForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Get form values
        const email = signupForm.email.value.trim();
        const password = signupForm.password.value.trim();
        const confirmPassword = signupForm.confirm_password.value.trim();
        
        // Validation
        let isValid = true;
        let errorMessage = '';
        
        if (!email) {
            isValid = false;
            errorMessage = 'Please enter your email address.';
        } else if (!/\S+@\S+\.\S+/.test(email)) {
            isValid = false;
            errorMessage = 'Please enter a valid email address.';
        } else if (password.length < 6) {
            isValid = false;
            errorMessage = 'Password must be at least 6 characters long.';
        } else if (password !== confirmPassword) {
            isValid = false;
            errorMessage = 'Passwords do not match.';
        }
        
        if (!isValid) {
            alert(errorMessage);
            return;
        }
        
        // Additional password strength check (optional)
        const hasUppercase = /[A-Z]/.test(password);
        const hasLowercase = /[a-z]/.test(password);
        const hasNumber = /[0-9]/.test(password);
        
        if (!hasUppercase || !hasLowercase || !hasNumber) {
            if (!confirm('Your password is weak. For better security, we recommend including uppercase letters, lowercase letters, and numbers.\n\nContinue with this password?')) {
                return;
            }
        }
        
        // Show loading and submit
        showLoading();
        setTimeout(() => {
            signupForm.submit();
        }, 500);
    });
    
    // Reset button when page loads
    window.addEventListener('load', function() {
        hideLoading();
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            document.getElementById('signupButton').click();
        }
    });
</script>

</body>
</html>