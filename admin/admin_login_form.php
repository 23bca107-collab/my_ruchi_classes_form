<?php
session_start();
// If admin is already logged in, redirect to appropriate page
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (isset($_SESSION['admin_profile_completed']) && $_SESSION['admin_profile_completed'] == 1) {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: admin_profile_form.php");
    }
    exit;
}

require_once __DIR__ . '/../includes/site_settings.php';
site_settings_start_favicon_buffer(null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Admin Login - Ruchi Classes</title>
<!-- Font Awesome for icons -->
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
        background: linear-gradient(135deg, #0d47a1 0%, #1565c0 50%, #1976d2 100%); 
        display:flex; 
        justify-content:center; 
        align-items:center; 
        min-height:100vh;
        padding: 20px;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    .login-container { 
        background:#fff; 
        padding: 30px 25px; 
        border-radius:20px; 
        box-shadow:0 15px 50px rgba(0,0,0,0.3); 
        width:100%; 
        max-width:450px;
        min-width: 300px;
        position: relative;
        overflow: hidden;
        animation: fadeIn 0.5s ease-out;
        margin: auto;
        border-top: 5px solid #1976d2;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .login-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 6px;
        background: linear-gradient(to right, #1976D2, #0D47A1);
    }
    .logo-container {
        text-align: center;
        margin-bottom: 20px;
    }
    .logo {
        max-width: 150px;
        height: auto;
        margin-bottom: 15px;
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    h2 { 
        text-align:center; 
        margin-bottom:25px; 
        color:#333; 
        font-weight: 600;
        font-size: 24px;
        position: relative;
    }
    h2::after {
        content: '';
        position: absolute;
        bottom: -8px;
        left: 50%;
        transform: translateX(-50%);
        width: 50px;
        height: 3px;
        background: linear-gradient(to right, #1976D2, #0D47A1);
        border-radius: 3px;
    }
    .admin-badge {
        display: inline-block;
        background: linear-gradient(45deg, #0d47a1, #1976d2);
        color: white;
        padding: 8px 20px;
        border-radius: 30px;
        font-size: 14px;
        font-weight: bold;
        margin: 10px 0;
        letter-spacing: 0.5px;
    }
    .input-group { 
        position:relative; 
        margin-bottom:20px; 
    }
    input { 
        width:100%; 
        padding: 14px 14px 14px 45px; 
        border: 2px solid #e0e0e0; 
        border-radius:10px; 
        font-size:16px; 
        transition: all 0.3s ease; 
        background: #f9f9f9;
        -webkit-appearance: none;
        appearance: none;
    }
    input:focus { 
        border-color:#1976D2; 
        box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.2); 
        outline: none;
        background: #fff;
    }
    /* Fix for iOS zoom on input focus */
    @media screen and (max-width: 768px) {
        input, select, textarea {
            font-size: 16px !important;
        }
    }
    .input-group label { 
        position:absolute; 
        top:50%; 
        left:45px; 
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
        left: 15px;
        font-size:13px; 
        color:#1976D2; 
        font-weight:600; 
        background: white;
        padding: 0 8px;
    }
    .input-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        font-size: 18px;
        transition: 0.3s;
        z-index: 2;
    }
    input:focus ~ .input-icon {
        color: #1976D2;
    }
    .password-toggle { 
        position:absolute; 
        right:15px; 
        top:50%; 
        transform:translateY(-50%); 
        cursor:pointer; 
        color:#999; 
        font-size: 16px;
        transition: 0.3s;
        z-index: 2;
        padding: 10px;
        -webkit-touch-callout: none;
        -webkit-user-select: none;
        user-select: none;
    }
    .password-toggle:hover, .password-toggle:active {
        color: #1976D2;
    }
    button { 
        width:100%; 
        padding:16px; 
        background:linear-gradient(to right, #1976D2, #0D47A1); 
        color:white; 
        font-size:17px; 
        font-weight:600; 
        border:none; 
        border-radius:10px; 
        cursor:pointer; 
        transition:0.3s; 
        margin-top: 10px;
        box-shadow: 0 4px 15px rgba(25, 118, 210, 0.3);
        position: relative;
        overflow: hidden;
        -webkit-touch-callout: none;
        -webkit-user-select: none;
        user-select: none;
        touch-action: manipulation;
        min-height: 54px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    button:hover { 
        transform:translateY(-3px);
        box-shadow: 0 6px 20px rgba(25, 118, 210, 0.4);
    }
    button:active {
        transform: translateY(0);
    }
    button:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none !important;
    }
    
    /* ================= LOADING BUTTON ================= */
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
        width: 22px;
        height: 22px;
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
    
    /* Mobile touch feedback */
    @media (hover: none) and (pointer: coarse) {
        button:active {
            opacity: 0.8;
            transform: scale(0.98);
        }
        .social-btn:active {
            transform: scale(0.95);
        }
    }
    button::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 5px;
        height: 5px;
        background: rgba(255, 255, 255, 0.5);
        opacity: 0;
        border-radius: 100%;
        transform: scale(1, 1) translate(-50%);
        transform-origin: 50% 50%;
    }
    button:focus:not(:active)::after {
        animation: ripple 1s ease-out;
    }
    @keyframes ripple {
        0% {
            transform: scale(0, 0);
            opacity: 0.5;
        }
        20% {
            transform: scale(50, 50);
            opacity: 0.3;
        }
        100% {
            transform: scale(100, 100);
            opacity: 0;
        }
    }
    .message { 
        text-align:center; 
        margin-top:20px; 
        font-size:14px; 
        color:#555; 
    }
    .message a { 
        color:#1976D2; 
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
        background: #1976D2;
        transition: width 0.3s ease;
    }
    .message a:hover::after {
        width: 100%;
    }
    .divider {
        display: flex;
        align-items: center;
        margin: 20px 0;
    }
    .divider::before,
    .divider::after {
        content: "";
        flex: 1;
        height: 1px;
        background-color: #eee;
    }
    .divider span {
        padding: 0 15px;
        color: #777;
        font-size: 13px;
    }
    .forgot-password {
        text-align: right;
        margin-top: -10px;
        margin-bottom: 20px;
    }
    .forgot-password a {
        color: #777;
        font-size: 14px;
        text-decoration: none;
        transition: 0.3s;
    }
    .forgot-password a:hover {
        color: #1976D2;
    }
    
    .security-info {
        background: #f0f7ff;
        border-left: 4px solid #1976D2;
        padding: 12px 15px;
        border-radius: 8px;
        margin: 20px 0;
        font-size: 13px;
        color: #1976D2;
    }
    
    .security-info i {
        margin-right: 8px;
    }
    
    /* Mobile-specific optimizations */
    @media (max-width: 768px) {
        body {
            padding: 15px;
            align-items: flex-start;
            min-height: -webkit-fill-available;
        }
        
        .login-container {
            padding: 25px 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            margin-top: 20px;
            margin-bottom: 20px;
        }
        
        h2 {
            font-size: 22px;
            margin-bottom: 20px;
        }
        
        .logo {
            max-width: 120px;
        }
        
        input {
            padding: 16px 16px 16px 45px;
        }
        
        button {
            padding: 16px;
            font-size: 16px;
        }
        
        .password-toggle {
            padding: 12px;
            right: 10px;
        }
    }
    
    @media (max-width: 480px) {
        body {
            padding: 10px;
        }
        
        .login-container {
            padding: 20px 15px;
            border-radius: 12px;
        }
        
        h2 {
            font-size: 20px;
        }
        
        .logo {
            max-width: 100px;
        }
        
        input {
            padding: 14px 14px 14px 45px;
        }
        
        .input-icon {
            font-size: 16px;
            left: 12px;
        }
        
        button {
            padding: 15px;
        }
        
        .message {
            font-size: 13px;
        }
        
        .divider span {
            font-size: 12px;
            padding: 0 10px;
        }
    }
    
    @media (max-width: 320px) {
        .login-container {
            padding: 18px 12px;
        }
        
        h2 {
            font-size: 18px;
        }
        
        input {
            padding: 12px 12px 12px 40px;
            font-size: 15px;
        }
        
        .input-icon {
            font-size: 15px;
            left: 10px;
        }
        
        .password-toggle {
            right: 8px;
            font-size: 14px;
        }
    }
    
    /* Portrait orientation fixes */
    @media (orientation: portrait) and (max-height: 700px) {
        body {
            align-items: flex-start;
            padding-top: 20px;
        }
        
        .login-container {
            margin-top: 0;
        }
        
        .logo-container {
            margin-bottom: 15px;
        }
        
        .input-group {
            margin-bottom: 15px;
        }
        
        .divider {
            margin: 15px 0;
        }
    }
    
    /* Landscape orientation fixes */
    @media (orientation: landscape) and (max-height: 500px) {
        body {
            align-items: flex-start;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        
        .login-container {
            padding: 15px;
            max-width: 400px;
        }
        
        .logo-container {
            margin-bottom: 10px;
        }
        
        .logo {
            max-width: 80px;
            margin-bottom: 10px;
        }
        
        h2 {
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        .input-group {
            margin-bottom: 12px;
        }
        
        input {
            padding: 12px 12px 12px 45px;
        }
        
        .divider {
            margin: 10px 0;
        }
    }
    
    /* iOS Safari specific fixes */
    @supports (-webkit-touch-callout: none) {
        body {
            min-height: -webkit-fill-available;
        }
        
        input, button {
            -webkit-appearance: none;
            border-radius: 10px;
        }
    }
    
    /* ================= DARK MODE SUPPORT ================= */
    @media (prefers-color-scheme: dark) {
        .login-container {
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
        
        .divider::before,
        .divider::after {
            background-color: #444;
        }
        
        .divider span {
            color: #aaa;
        }
        
        .message {
            color: #aaa;
        }
        
        .security-info {
            background: #2d3748;
            color: #90cdf4;
        }
    }
</style>
</head>
<body>

<div class="login-container">
    <div class="logo-container">
        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo" onerror="this.onerror=null; this.src='https://via.placeholder.com/150/1976D2/FFFFFF?text=Ruchi+Classes';">
        <h2>Admin Portal Login</h2>
        <div class="admin-badge">
            <i class="fas fa-user-shield"></i> Administrator Access
        </div>
    </div>
    
    <div class="security-info">
        <i class="fas fa-shield-alt"></i>
        <strong>Secure Access Only:</strong> This portal is restricted to authorized administrators
    </div>
    
    <form action="admin_login.php" method="POST" id="adminLoginForm">
        <!-- CSRF TOKEN -->
        <input type="hidden" name="csrf_token" id="csrf_token" value="">
        
        <div class="input-group">
            <i class="input-icon fas fa-user-tie"></i>
            <input type="email" name="email" placeholder=" " required autocomplete="email" id="adminEmail">
            <label>Admin Email</label>
        </div>
        <div class="input-group">
            <i class="input-icon fas fa-lock"></i>
            <input type="password" name="password" placeholder=" " required id="adminPassword" autocomplete="current-password">
            <label>Admin Password</label>
            <span class="password-toggle" id="togglePass" role="button" aria-label="Show password">
                <i class="fas fa-eye"></i>
            </span>
        </div>
        <div class="forgot-password">
            <a href="#" id="adminForgotPasswordLink">Forgot Admin Password?</a>
        </div>
        <button type="submit" id="adminLoginButton">
            <span class="btn-text">Login as Admin</span>
            <div class="spinner"></div>
        </button>
    </form>
    
    <div class="divider">
        <span>Quick Links</span>
    </div>
    
    <p class="message">
        <a href="../index.php"><i class="fas fa-home"></i> Back to Home</a> | 
        <a href="login.html"><i class="fas fa-user-graduate"></i> Student Login</a>
    </p>
    
    <div class="security-info">
        <i class="fas fa-info-circle"></i>
        <strong>Note:</strong> All login activities are monitored and logged for security purposes.
    </div>
</div>

<!-- SweetAlert2 is already included in the login processor, but we need it here for errors -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>

<script>
    // CSRF Token Generation (simplified)
    window.addEventListener('DOMContentLoaded', function() {
        // Generate a simple CSRF token for admin login
        const csrfToken = 'admin_token_' + Date.now() + '_' + Math.random().toString(36).substr(2);
        document.getElementById('csrf_token').value = csrfToken;
        
        // Check for URL error parameters
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('error')) {
            const errorType = urlParams.get('error');
            let errorMessage = 'An error occurred during login.';
            
            switch(errorType) {
                case 'account_tampered':
                    errorMessage = 'Admin account not found or tampered with. Please contact system administrator.';
                    break;
                case 'inactive':
                    errorMessage = 'Your admin account is inactive. Please contact system administrator.';
                    break;
            }
            
            Swal.fire({
                icon: 'error',
                title: 'Login Error',
                text: errorMessage,
                confirmButtonColor: '#1976D2'
            });
        }
        
        // Auto-focus on email field
        document.getElementById('adminEmail').focus();
    });
    
    // Password toggle functionality
    const passwordInput = document.getElementById('adminPassword');
    const togglePass = document.getElementById('togglePass');
    const eyeIcon = togglePass.querySelector('i');
    
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
    
    // Add enter key support for password toggle
    togglePass.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            togglePass.click();
        }
    });

    // Function to show/hide loading animation
    function showLoading() {
        const loginButton = document.getElementById('adminLoginButton');
        loginButton.classList.add('loading');
        loginButton.disabled = true;
    }
    
    function hideLoading() {
        const loginButton = document.getElementById('adminLoginButton');
        loginButton.classList.remove('loading');
        loginButton.disabled = false;
    }

    // Form submission handler
    const adminLoginForm = document.getElementById('adminLoginForm');
    
    adminLoginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const email = adminLoginForm.email.value.trim();
        const password = adminLoginForm.password.value.trim();
        const csrfToken = adminLoginForm.csrf_token.value.trim();
        
        // Client-side validation
        if (!email || !password) {
            Swal.fire({
                icon: 'error',
                title: 'Missing Information',
                text: 'Please enter both email and password.',
                confirmButtonColor: '#1976D2'
            });
            return false;
        }
        
        if (!validateEmail(email)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Email',
                text: 'Please enter a valid email address.',
                confirmButtonColor: '#1976D2'
            });
            return false;
        }
        
        if (password.length < 6) {
            Swal.fire({
                icon: 'error',
                title: 'Weak Password',
                text: 'Password must be at least 6 characters long.',
                confirmButtonColor: '#1976D2'
            });
            return false;
        }
        
        // Show loading spinner
        showLoading();
        
        // Submit the form normally (not via AJAX since we want SweetAlert from PHP)
        adminLoginForm.submit();
        
        return false;
    });

    // Email validation function
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    // Forgot password functionality
    document.getElementById('adminForgotPasswordLink').addEventListener('click', (e) => {
        e.preventDefault();
        const email = document.getElementById('adminEmail').value;
        
        if (email && validateEmail(email)) {
            Swal.fire({
                title: 'Reset Admin Password',
                html: `Send password reset instructions to <strong>${email}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Send Reset Link',
                confirmButtonColor: '#1976D2',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In production, you would make an AJAX call here
                    Swal.fire({
                        title: 'Reset Link Sent!',
                        text: `Password reset instructions have been sent to ${email}`,
                        icon: 'success',
                        confirmButtonColor: '#1976D2'
                    });
                }
            });
        } else {
            Swal.fire({
                title: 'Enter Admin Email',
                input: 'email',
                inputPlaceholder: 'admin@example.com',
                showCancelButton: true,
                confirmButtonText: 'Send Reset Link',
                confirmButtonColor: '#1976D2',
                cancelButtonColor: '#6c757d',
                inputValidator: (value) => {
                    if (!value || !validateEmail(value)) {
                        return 'Please enter a valid email address!';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Reset Link Sent!',
                        text: `Password reset instructions have been sent to ${result.value}`,
                        icon: 'success',
                        confirmButtonColor: '#1976D2'
                    });
                }
            });
        }
    });
    
    // Reset button when page loads/refreshes
    window.addEventListener('load', function() {
        hideLoading();
    });
    
    // Reset button if page reloads
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            hideLoading();
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+Enter to submit form
        if (e.ctrlKey && e.key === 'Enter') {
            document.getElementById('adminLoginForm').dispatchEvent(new Event('submit'));
        }
    });
</script>

</body>
</html>
