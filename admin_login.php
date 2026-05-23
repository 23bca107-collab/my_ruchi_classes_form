<?php
session_start();
@require __DIR__ . '/db.php';
require_once __DIR__ . '/includes/site_settings.php';
// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (isset($_SESSION['profile_completed']) && $_SESSION['profile_completed'] === true) {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: admin_profile_complete.php");
    }
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?php echo htmlspecialchars(site_settings_page_title_text($conn ?? null, 'Admin Login'), ENT_QUOTES, 'UTF-8'); ?></title>
  <?php echo site_settings_render_favicon_tags($conn ?? null); ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <style>
    * { 
        margin: 0; 
        padding: 0; 
        box-sizing: border-box; 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
    }
    
    body { 
        display: flex; 
        justify-content: center; 
        align-items: center; 
        min-height: 100vh; 
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 20px; 
        -webkit-tap-highlight-color: transparent;
    }
    
    .container { 
        width: 400px; 
        max-width: 100%; 
        background: white; 
        border-radius: 15px; 
        box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
        padding: 40px; 
        border: 2px solid #e2e8f0;
    }
    
    /* Logo Section */
    .logo-section {
        text-align: center;
        margin-bottom: 30px;
    }
    
    .logo-img {
        width: 80px;
        height: 80px;
        margin: 0 auto 15px;
        border-radius: 12px;
        overflow: hidden;
        border: 3px solid #4a6fa5;
        background: white;
        padding: 5px;
        box-shadow: 0 5px 15px rgba(74, 111, 165, 0.2);
    }
    
    .logo-img img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    
    .logo-section h1 { 
        color: #2c3e50; 
        font-size: 28px; 
        margin-bottom: 5px; 
        font-weight: 700;
    }
    
    .logo-section span { 
        color: #4a6fa5; 
        font-weight: 800;
    }
    
    .logo-section p { 
        color: #666; 
        font-size: 14px; 
        margin-top: 5px;
    }
    
    /* Form Styles */
    .login-form { 
        width: 100%; 
    }
    
    .form-group { 
        margin-bottom: 20px; 
        position: relative;
    }
    
    .form-group input { 
        width: 100%; 
        padding: 14px 15px; 
        border: 2px solid #ddd; 
        border-radius: 8px; 
        font-size: 16px; 
        background: #f8fafc;
        transition: all 0.3s ease;
        -webkit-appearance: none;
    }
    
    .form-group input:focus { 
        border-color: #4a6fa5; 
        outline: none; 
        box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        background: white;
    }
    
    .form-icon {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #4a6fa5;
        font-size: 1.1rem;
    }
    
    /* Password toggle */
    .password-toggle {
        position: absolute;
        right: 40px;
        top: 50%;
        transform: translateY(-50%);
        color: #666;
        cursor: pointer;
        font-size: 1rem;
        z-index: 2;
    }
    
    /* Button */
    .login-btn { 
        width: 100%; 
        padding: 15px; 
        background: linear-gradient(135deg, #4a6fa5, #3a5a80); 
        border: none; 
        border-radius: 8px; 
        color: white; 
        font-size: 16px; 
        font-weight: 600; 
        cursor: pointer; 
        transition: all 0.3s ease; 
        margin-top: 10px;
        -webkit-appearance: none;
        touch-action: manipulation;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    .login-btn:hover, .login-btn:active { 
        background: linear-gradient(135deg, #3a5a80, #2a4a70);
        transform: translateY(-2px);
        box-shadow: 0 7px 20px rgba(74, 111, 165, 0.4);
    }
    
    .login-btn:disabled { 
        background: #95a5a6; 
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    
    .spinner {
        display: none;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255,255,255,0.3);
        border-top-color: white;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Error */
    .error { 
        color: #e74c3c; 
        font-size: 12px; 
        margin-top: 5px; 
        display: none; 
    }
    
    /* Remember Forgot */
    .remember-forgot { 
        display: flex; 
        justify-content: space-between; 
        margin-bottom: 20px; 
        font-size: 13px; 
    }
    
    .remember-forgot a { 
        color: #4a6fa5; 
        text-decoration: none; 
        font-weight: 500;
    }
    
    .remember-forgot a:hover { 
        text-decoration: underline; 
    }
    
    /* Security Notice */
    .security-notice { 
        background: #f8f9fa; 
        border: 2px solid #e9ecef; 
        border-radius: 8px; 
        padding: 12px; 
        text-align: center; 
        margin-top: 25px; 
        font-size: 12px; 
        color: #666;
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
        body {
            padding: 15px;
        }
        
        .container {
            padding: 30px;
            border-radius: 12px;
        }
        
        .logo-img {
            width: 70px;
            height: 70px;
        }
        
        .logo-section h1 {
            font-size: 24px;
        }
        
        .form-group input {
            padding: 12px 15px;
        }
        
        .login-btn {
            padding: 14px;
        }
    }
    
    @media (max-width: 480px) {
        .container {
            padding: 25px;
        }
        
        .logo-img {
            width: 60px;
            height: 60px;
        }
        
        .logo-section h1 {
            font-size: 22px;
        }
        
        .login-btn {
            padding: 13px;
        }
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Logo Section -->
    <div class="logo-section">
        <div class="logo-img">
            <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo">
        </div>
        <h1>Ruchi <span>Classes</span></h1>
        <p>Admin Login Portal</p>
    </div>

    <form class="login-form" id="loginForm" method="POST" action="admin_login_check.php">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
      
      <div class="form-group">
        <input type="email" id="email" name="email" placeholder="Email Address" required autocomplete="email">
        <i class="fas fa-envelope form-icon"></i>
        <div class="error" id="email-error"></div>
      </div>
      
      <div class="form-group">
        <input type="password" id="password" name="password" placeholder="Password" required autocomplete="current-password">
        <i class="fas fa-lock form-icon"></i>
        <span class="password-toggle" id="togglePassword">
            <i class="fas fa-eye"></i>
        </span>
        <div class="error" id="password-error"></div>
      </div>
      
      <div class="remember-forgot">
        <label><input type="checkbox" name="remember"> Remember me</label>
        <a href="#" id="forgot-password">Forgot Password?</a>
      </div>
      
      <button type="submit" class="login-btn" id="loginBtn">
        <span class="btn-text">Login to Dashboard</span>
        <div class="spinner"></div>
      </button>

      <div class="security-notice">
        <i class="fas fa-shield-alt"></i> Secure access for authorized personnel only
      </div>
    </form>
  </div>

  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <script>
    // DOM Elements
    const loginForm = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const loginBtn = document.getElementById('loginBtn');
    const forgotPasswordLink = document.getElementById('forgot-password');
    const togglePassword = document.getElementById('togglePassword');
    const passwordToggleIcon = togglePassword.querySelector('i');
    
    // Password toggle functionality
    togglePassword.addEventListener('click', function() {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            passwordToggleIcon.classList.remove('fa-eye');
            passwordToggleIcon.classList.add('fa-eye-slash');
            this.setAttribute('aria-label', 'Hide password');
        } else {
            passwordInput.type = 'password';
            passwordToggleIcon.classList.remove('fa-eye-slash');
            passwordToggleIcon.classList.add('fa-eye');
            this.setAttribute('aria-label', 'Show password');
        }
    });
    
    // Email validation
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    // Show loading state
    function showLoading() {
        loginBtn.disabled = true;
        loginBtn.classList.add('loading');
        loginBtn.querySelector('.spinner').style.display = 'block';
        loginBtn.querySelector('.btn-text').style.opacity = '0.5';
    }
    
    // Hide loading state
    function hideLoading() {
        loginBtn.disabled = false;
        loginBtn.classList.remove('loading');
        loginBtn.querySelector('.spinner').style.display = 'none';
        loginBtn.querySelector('.btn-text').style.opacity = '1';
    }
    
    // Form submission
    loginForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const email = emailInput.value.trim();
        const password = passwordInput.value.trim();
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        
        // Reset errors
        document.querySelectorAll('.error').forEach(el => {
            el.style.display = 'none';
            el.textContent = '';
        });
        
        // Validation
        let isValid = true;
        
        if (!email) {
            showError('email', 'Please enter email address');
            isValid = false;
        } else if (!validateEmail(email)) {
            showError('email', 'Please enter a valid email address');
            isValid = false;
        }
        
        if (!password) {
            showError('password', 'Please enter password');
            isValid = false;
        } else if (password.length < 6) {
            showError('password', 'Password must be at least 6 characters');
            isValid = false;
        }
        
        if (!isValid) return;
        
        // Show loading state
        showLoading();
        
        try {
            // Send AJAX request
            const formData = new FormData();
            formData.append('email', email);
            formData.append('password', password);
            formData.append('csrf_token', csrfToken);
            
            const response = await fetch('admin_login_check.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: 'Login Successful!',
                    text: data.message,
                    showConfirmButton: false,
                    timer: 1500,
                    background: '#f9fafb',
                    color: '#2c3e50',
                    timerProgressBar: true,
                    willClose: () => {
                        window.location.href = data.redirect;
                    }
                });
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: 'Login Failed',
                    text: data.message,
                    confirmButtonColor: '#e74c3c',
                    background: '#f9fafb',
                    color: '#2c3e50'
                });
                
                // Reset button
                hideLoading();
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Please check your internet connection and try again.',
                confirmButtonColor: '#e74c3c',
                background: '#f9fafb',
                color: '#2c3e50'
            });
            
            // Reset button
            hideLoading();
        }
    });
    
    // Error display function
    function showError(field, message) {
        const errorEl = document.getElementById(field + '-error');
        errorEl.textContent = message;
        errorEl.style.display = 'block';
        document.getElementById(field).focus();
    }
    
    // Forgot password handler
    forgotPasswordLink.addEventListener('click', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Password Recovery',
            html: `
                <div style="text-align: left; margin: 20px 0;">
                    <p>Please contact the system administrator:</p>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>Email: <strong>admin@ruchiclasses.com</strong></li>
                        <li>Phone: <strong>+91 1234567890</strong></li>
                    </ul>
                    <p style="margin-top: 15px; color: #666; font-size: 14px;">
                        Provide your registered email address for verification.
                    </p>
                </div>
            `,
            icon: 'info',
            confirmButtonText: 'Got it',
            confirmButtonColor: '#4a6fa5',
            background: '#f9fafb',
            color: '#2c3e50',
            width: '450px'
        });
    });
    
    // Input focus effects
    document.querySelectorAll('.form-group input').forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
            const icon = this.parentElement.querySelector('.form-icon');
            if (icon) {
                icon.style.color = '#2c3e50';
            }
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = '';
            const icon = this.parentElement.querySelector('.form-icon');
            if (icon) {
                icon.style.color = '#4a6fa5';
            }
        });
    });
    
    // Auto-focus email input on load
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(() => {
            emailInput.focus();
        }, 300);
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.type !== 'submit') {
            e.preventDefault();
            if (e.target === emailInput) {
                passwordInput.focus();
            } else if (e.target === passwordInput) {
                loginForm.requestSubmit();
            }
        }
    });
    
    // Prevent form resubmission
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
  </script>
</body>
</html>
