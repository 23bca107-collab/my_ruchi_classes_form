<?php
session_start();

// Try to include database connection
@require '../db.php';
require_once __DIR__ . '/../includes/site_settings.php';

// Check if database connection exists
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
    
    if ($conn->connect_error) {
        showAlert('error', 'Database Error', 'Please make sure MySQL is running in XAMPP');
    }
}

// Function to show SweetAlert
function adminDynamicFaviconTags(): string {
    global $conn;
    return site_settings_render_favicon_tags($conn ?? null);
}

function adminDynamicPageTitle(string $pageTitle): string {
    global $conn;
    return htmlspecialchars(site_settings_page_title_text($conn ?? null, $pageTitle), ENT_QUOTES, 'UTF-8');
}

function showAlert($icon, $title, $text, $redirect = null){
    $icon_json = json_encode((string) $icon);
    $title_json = json_encode((string) $title);
    $text_json = json_encode((string) $text);
    $redirect_script = $redirect
        ? 'window.location.href = ' . json_encode((string) $redirect) . ';'
        : 'window.location.href = "admin_login.php";';

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . adminDynamicPageTitle('Admin Login Alert') . '</title>
        ' . adminDynamicFaviconTags() . '
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
            .admin-icon {
                font-size: 80px;
                color: #667eea;
                margin-bottom: 20px;
                animation: pulse 1.5s ease infinite;
            }
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }
            .security-badge {
                background: #f8f9fa;
                padding: 10px;
                border-radius: 8px;
                margin-top: 15px;
                text-align: center;
                border: 2px solid #667eea;
                font-size: 12px;
                color: #555;
            }
        </style>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: '.$icon_json.',
                title: '.$title_json.',
                html: `<div style="text-align: center;">
                    <i class="fas fa-user-shield admin-icon"></i>
                    <h3 style="margin: 10px 0; color: #333;">` + '.$text_json.' + `</h3>
                    <div class="security-badge">
                        <i class="fas fa-shield-alt"></i> Admin Portal • Secure Access
                    </div>
                </div>`,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                willClose: () => {
                    ' . $redirect_script . '
                }
            });
        </script>
    </body>
    </html>';
    exit;
}

// Function for error alerts
function showErrorAlert($icon, $title, $text, $redirect = 'admin_login.php'){
    $icon_json = json_encode((string) $icon);
    $title_json = json_encode((string) $title);
    $text_json = json_encode((string) $text);
    $redirect_json = json_encode((string) $redirect);

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . adminDynamicPageTitle('Admin Login Error') . '</title>
        ' . adminDynamicFaviconTags() . '
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
            }
        </style>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: '.$icon_json.',
                title: '.$title_json.',
                text: '.$text_json.',
                confirmButtonText: "Try Again",
                confirmButtonColor: "#667eea",
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = '.$redirect_json.';
            });
        </script>
    </body>
    </html>';
    exit;
}

function getAdminLoginRedirect($email = '') {
    $email = trim((string) $email);
    if ($email === '') {
        return 'admin_login.php';
    }

    return 'admin_login.php?email=' . rawurlencode($email);
}

function isAdminPasswordValid($enteredPassword, $storedPassword) {
    if (!is_string($storedPassword) || $storedPassword === '') {
        return false;
    }

    return password_verify($enteredPassword, $storedPassword) || hash_equals($storedPassword, $enteredPassword);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get credentials
    $email = trim($_POST['email'] ?? '');
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $password = trim($_POST['password'] ?? '');
    $login_redirect = getAdminLoginRedirect($email);
    
    // Validate inputs
    if (empty($email) || empty($password)) {
        showErrorAlert('error', 'Missing Information', 'Please enter both email and password.', $login_redirect);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        showErrorAlert('error', 'Invalid Email', 'Please enter a valid email address.', $login_redirect);
    }
    
    // Check admin credentials
    $stmt = $conn->prepare("SELECT * FROM admins WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows == 0){
        showErrorAlert('error', 'Email Not Found', 'This admin email is not registered. Please check your email address.', $login_redirect);
    }

    $admin = $result->fetch_assoc();

    // Verify password
    if(!isAdminPasswordValid($password, $admin['password'])){
        showErrorAlert('error', 'Incorrect Password', 'The password you entered is incorrect. Please try again.', getAdminLoginRedirect($admin['email']));
    }

    // Set session variables
    session_regenerate_id(true);
    
    // Get full admin data from database
    $stmt = $conn->prepare("SELECT id, name, email, admin_type, profile_completed, first_name, last_name, phone, photo FROM admins WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $admin['id']);
    $stmt->execute();
    $admin_data = $stmt->get_result()->fetch_assoc();
    
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = $admin_data['id'];
    $_SESSION['admin_email'] = $admin_data['email'];
    $_SESSION['admin_name'] = $admin_data['name'];
    $_SESSION['admin_first_name'] = $admin_data['first_name'];
    $_SESSION['admin_last_name'] = $admin_data['last_name'];
    $_SESSION['admin_phone'] = $admin_data['phone'];
    $_SESSION['admin_photo'] = $admin_data['photo'];
    $_SESSION['admin_type'] = $admin_data['admin_type'];
    $_SESSION['profile_completed'] = $admin_data['profile_completed'];
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

    // Log admin login
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $status = 'success';
    
    // Check if admin_log table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'admin_log'");
    if ($table_check->num_rows == 0) {
        // Create admin_log table if not exists
        $create_table = $conn->query("CREATE TABLE IF NOT EXISTS admin_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            admin_id INT,
            action VARCHAR(50),
            ip_address VARCHAR(45),
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }
    
    $stmt = $conn->prepare("INSERT INTO admin_log (admin_id, action, ip_address) VALUES (?, 'login', ?)");
    $stmt->bind_param("is", $admin['id'], $ip_address);
    $stmt->execute();

    // Check if profile is completed
    if ($admin['profile_completed'] == 1) {
        // Redirect to dashboard
        showAlert('success', 'Login Successful!', 'Welcome back Administrator!', 'admin_dashboard.php');
    } else {
        // Redirect to complete profile
        showAlert('info', 'Profile Incomplete', 'Please complete your profile to continue', 'complete_profile.php');
    }
}

// If accessed directly without POST, show login form
$saved_email = isset($_GET['email']) ? htmlspecialchars($_GET['email'], ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo adminDynamicPageTitle('Admin Login'); ?></title>
<?php echo adminDynamicFaviconTags(); ?>
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
    .login-container { 
        background:#fff; 
        padding: 40px 35px; 
        border-radius:20px; 
        box-shadow:0 20px 60px rgba(0,0,0,0.3); 
        width:100%; 
        max-width:480px;
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
    .login-container::before {
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
    
    /* Mobile touch feedback */
    @media (hover: none) and (pointer: coarse) {
        button:active {
            opacity: 0.8;
            transform: scale(0.98);
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
    
    .security-note {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        margin-top: 25px;
        text-align: center;
        border: 1px solid #e0e0e0;
        font-size: 13px;
        color: #666;
    }
    .security-note i {
        color: #667eea;
        margin-right: 5px;
    }
    
    /* Mobile-specific optimizations */
    @media (max-width: 768px) {
        body {
            padding: 15px;
        }
        
        .login-container {
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
    }
    
    @media (max-width: 480px) {
        .login-container {
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
        
        .security-note {
            background: #2d2d2d;
            border-color: #444;
            color: #aaa;
        }
    }
</style>
</head>
<body>

<div class="login-container">
    <div class="logo-container">
        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo" onerror="this.onerror=null; this.src='https://via.placeholder.com/180/667eea/FFFFFF?text=Ruchi+Classes';">
        <h2><i class="fas fa-user-shield"></i> Admin Portal</h2>
    </div>
    <form action="admin_login.php" method="POST" id="loginForm">
        <div class="input-group">
            <i class="input-icon fas fa-envelope"></i>
            <input type="email" name="email" placeholder=" " required autocomplete="email" value="<?php echo $saved_email; ?>">
            <label>Admin Email</label>
        </div>
        <div class="input-group">
            <i class="input-icon fas fa-lock"></i>
            <input type="password" name="password" placeholder=" " required id="passwordInput" autocomplete="current-password">
            <label>Password</label>
            <span class="password-toggle" id="togglePass" role="button" aria-label="Show password">
                <i class="fas fa-eye"></i>
            </span>
        </div>
        
        <div class="security-note">
            <i class="fas fa-exclamation-triangle"></i>
            Restricted access. Authorized personnel only.
        </div>
        
        <button type="submit" id="loginButton">
            <span class="btn-text">Admin Login</span>
            <div class="spinner"></div>
        </button>
        
        <p class="message">
            <a href="#" id="forgotPassword">Forgot Password?</a> 
        </p>
    </form>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
    // Password toggle functionality
    const passwordInput = document.getElementById('passwordInput');
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
    
    togglePass.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            togglePass.click();
        }
    });

    // Loading animation
    function showLoading() {
        const loginButton = document.getElementById('loginButton');
        loginButton.classList.add('loading');
        loginButton.disabled = true;
    }
    
    function hideLoading() {
        const loginButton = document.getElementById('loginButton');
        loginButton.classList.remove('loading');
        loginButton.disabled = false;
    }

    // Form submission
    const loginForm = document.getElementById('loginForm');
    
    loginForm.addEventListener('submit', () => {
        const email = loginForm.email.value.trim();
        const password = loginForm.password.value.trim();
        
        if (!email || !password) {
            return;
        }
        
        showLoading();
    });

    // Forgot password
    document.getElementById('forgotPassword').addEventListener('click', (e) => {
        e.preventDefault();
        const email = prompt('Enter your admin email to reset password:');
        if (email) {
            alert('Password reset instructions have been sent to the super administrator.');
        }
    });
    
    // Reset button when page loads
    window.addEventListener('load', function() {
        hideLoading();
    });
</script>

</body>
</html>
