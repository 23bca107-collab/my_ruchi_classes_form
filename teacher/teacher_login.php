<?php
session_start();

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/../includes/site_settings.php';
site_settings_start_favicon_buffer($conn);

// Clear any existing session
if (isset($_SESSION['teacher_logged_in'])) {
    session_unset();
    session_destroy();
    session_start();
}

// Function to show SweetAlert
function showTeacherAlert($icon, $title, $text, $redirect = null, $auto_redirect_ms = 0){
    $icon_json = json_encode((string) $icon);
    $title_json = json_encode((string) $title);
    $text_json = json_encode((string) $text);
    $auto_redirect_ms = max(0, (int) $auto_redirect_ms);
    $show_confirm_button = $auto_redirect_ms > 0 ? 'false' : 'true';
    $timer_options = $auto_redirect_ms > 0
        ? "\n                timer: " . $auto_redirect_ms . ",\n                timerProgressBar: true,"
        : '';
    $redirect_script = $redirect
        ? 'window.location.href = ' . json_encode((string) $redirect) . ';'
        : 'window.history.back();';

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Teacher Login Alert</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { 
                background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;
            }
        </style>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: '.$icon_json.',
                title: '.$title_json.',
                text: '.$text_json.',
                confirmButtonText: "Continue",
                confirmButtonColor: "#3498db",
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: ' . $show_confirm_button . ',' . $timer_options . '
            }).then(() => {
                ' . $redirect_script . '
            });
        </script>
    </body>
    </html>';
    exit;
}

function getTeacherLoginRedirect($email = '') {
    $email = trim((string) $email);
    if ($email === '') {
        return 'teacher_login.php';
    }

    return 'teacher_login.php?email=' . rawurlencode($email);
}

function redirectToTeacherSetup($email, $teacher_id, $entered_password = '') {
    $_SESSION['pending_teacher_setup'] = [
        'email' => $email,
        'teacher_id' => (int) $teacher_id,
        'password' => $entered_password
    ];

    header('Location: teacher_login.php?setup=1');
    exit;
}

// Function to show password setup modal with auto-filled email and password
function showPasswordSetup($email, $teacher_id, $entered_password = '') {
    $safe_email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safe_password = htmlspecialchars($entered_password, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Set Up Your Password - First Time Login</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "Inter", sans-serif;
                background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .setup-container {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                max-width: 550px;
                width: 100%;
                padding: 40px;
                animation: fadeIn 0.5s ease-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .header { text-align: center; margin-bottom: 30px; }
            .logo {
                width: 80px;
                height: 80px;
                margin-bottom: 20px;
                border-radius: 15px;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            }
            h2 { color: #333; font-size: 1.8rem; font-weight: 700; margin-bottom: 10px; }
            .subtitle { color: #666; font-size: 0.9rem; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem; }
            label .required { color: #e74c3c; margin-left: 4px; }
            .input-wrapper { position: relative; }
            .input-wrapper i {
                position: absolute;
                left: 15px;
                top: 50%;
                transform: translateY(-50%);
                color: #999;
                font-size: 1rem;
                z-index: 1;
            }
            input, select {
                width: 100%;
                padding: 14px 15px 14px 45px;
                border: 2px solid #e0e6ed;
                border-radius: 12px;
                font-size: 1rem;
                transition: all 0.3s ease;
                font-family: "Inter", sans-serif;
            }
            input:focus, select:focus {
                outline: none;
                border-color: #3498db;
                box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1);
            }
            input:disabled {
                background: #f5f5f5;
                color: #666;
                cursor: not-allowed;
            }
            input[readonly] {
                background: #f5f5f5;
                color: #666;
                cursor: not-allowed;
            }
            .password-toggle {
                position: absolute;
                right: 15px;
                top: 50%;
                transform: translateY(-50%);
                cursor: pointer;
                color: #999;
                transition: color 0.3s;
                z-index: 2;
            }
            .password-toggle:hover { color: #3498db; }
            .error-message {
                background: #fee;
                color: #c33;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 0.9rem;
                display: flex;
                align-items: center;
                gap: 10px;
                border-left: 4px solid #c33;
            }
            .btn {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #2c3e50, #3498db);
                color: white;
                border: none;
                border-radius: 12px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                margin-top: 10px;
            }
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(52, 152, 219, 0.4);
            }
            .requirements {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 10px;
                margin-top: 20px;
                font-size: 0.85rem;
                color: #666;
            }
            .requirements p { margin-bottom: 8px; font-weight: 600; }
            .requirements ul { list-style: none; padding-left: 0; }
            .requirements li {
                margin-bottom: 5px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .requirements li i { font-size: 0.8rem; width: 18px; }
            .requirements li i.fa-check-circle { color: #27ae60; }
            .requirements li i.fa-times { color: #e74c3c; }
            .info-note {
                background: #e8f4fd;
                padding: 12px;
                border-radius: 8px;
                margin-top: 15px;
                font-size: 0.85rem;
                color: #2c3e50;
                display: flex;
                align-items: center;
                gap: 10px;
                border-left: 3px solid #3498db;
            }
            .row { display: flex; gap: 15px; }
            .row .form-group { flex: 1; }
            
            /* Photo Upload Styles */
            .photo-upload {
                text-align: center;
                margin-bottom: 20px;
            }
            .photo-preview {
                width: 120px;
                height: 120px;
                border-radius: 50%;
                margin: 0 auto 15px;
                border: 3px solid #3498db;
                overflow: hidden;
                background: #f8f9fa;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.3s ease;
                position: relative;
            }
            .photo-preview:hover {
                transform: scale(1.05);
                border-color: #2c3e50;
            }
            .photo-preview img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .photo-preview .placeholder {
                font-size: 48px;
                color: #999;
            }
            .photo-preview .upload-overlay {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(0,0,0,0.7);
                color: white;
                font-size: 12px;
                padding: 5px;
                text-align: center;
                opacity: 0;
                transition: opacity 0.3s;
            }
            .photo-preview:hover .upload-overlay {
                opacity: 1;
            }
            .file-input {
                display: none;
            }
            .upload-hint {
                font-size: 12px;
                color: #666;
                margin-top: 8px;
            }
            
            @media (max-width: 480px) {
                .setup-container { padding: 30px 20px; }
                h2 { font-size: 1.5rem; }
                input, select { font-size: 16px; padding: 12px 12px 12px 40px; }
                .input-wrapper i { left: 12px; }
                .row { flex-direction: column; gap: 20px; }
                .photo-preview { width: 100px; height: 100px; }
            }
        </style>
    </head>
    <body>
        <div class="setup-container">
            <div class="header">
                <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes" class="logo" onerror="this.onerror=null; this.src=\'https://via.placeholder.com/80/3498db/FFFFFF?text=RC\'">
                <h2>First Time Login</h2>
                <p class="subtitle">Welcome! Please set up your password and complete your profile</p>
            </div>
            
            <form id="setupForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="teacher_id" value="' . $teacher_id . '">
                <input type="hidden" name="email" value="' . $safe_email . '">
                <input type="hidden" name="first_time_setup" value="1">
                <input type="hidden" id="setup_storage_key" value="teacher_setup_' . $teacher_id . '">
                
                <!-- Photo Upload Section -->
                <div class="photo-upload">
                    <div class="photo-preview" onclick="document.getElementById(\'photoInput\').click()">
                        <img id="photoPreview" src="" style="display: none;">
                        <div id="photoPlaceholder" class="placeholder">
                            <i class="fas fa-camera"></i>
                        </div>
                        <div class="upload-overlay">
                            <i class="fas fa-upload"></i> Click to upload
                        </div>
                    </div>
                    <input type="file" id="photoInput" name="teacher_photo" accept="image/jpeg,image/png,image/jpg" required style="display: none;">
                    <div class="upload-hint">
                        <i class="fas fa-info-circle"></i> Profile photo is required (JPG, PNG - Max 2MB)
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email_display">Email Address</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email_display" value="' . $safe_email . '" disabled style="background: #f8f9fa; color: #2c3e50; font-weight: 500;">
                    </div>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label for="first_name">First Name <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="first_name" name="first_name" required placeholder="Enter your first name" autocomplete="given-name">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="last_name" name="last_name" required placeholder="Enter your last name" autocomplete="family-name">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="mobile">Mobile Number <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" id="mobile" name="mobile" required placeholder="10-digit mobile number" pattern="[0-9]{10}" maxlength="10" autocomplete="tel">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="subject">Subject/Specialization <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-book"></i>
                        <select id="subject" name="subject" required>
                            <option value="">Select your subject</option>
                            <option value="Mathematics">Mathematics</option>
                            <option value="Science">Science</option>
                            <option value="English">English</option>
                            <option value="Hindi">Hindi</option>
                            <option value="Sanskrit">Sanskrit</option>
                            <option value="Social Studies">Social Studies</option>
                            <option value="Physics">Physics</option>
                            <option value="Chemistry">Chemistry</option>
                            <option value="Biology">Biology</option>
                            <option value="Computer Science">Computer Science</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="text" id="password" name="password" value="' . $safe_password . '" required minlength="8" autocomplete="new-password" readonly>
                        <span class="password-toggle" onclick="togglePassword(\'password\')">
                            <i class="fas fa-eye-slash"></i>
                        </span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-check-circle"></i>
                        <input type="text" id="confirm_password" name="confirm_password" value="' . $safe_password . '" required autocomplete="new-password" readonly>
                        <span class="password-toggle" onclick="togglePassword(\'confirm_password\')">
                            <i class="fas fa-eye-slash"></i>
                        </span>
                    </div>
                </div>
                
                <div class="info-note">
                    <i class="fas fa-info-circle"></i>
                    <span>Password is taken from the login form and cannot be edited here. After setup, you will be automatically logged in to your teacher dashboard.</span>
                </div>
                
                <button type="submit" class="btn" id="submitBtn">
                    <i class="fas fa-check-circle"></i> Complete Setup & Login
                </button>
            </form>
        </div>
        
        <script>
        // Photo preview
        const photoInput = document.getElementById("photoInput");
        const photoPreview = document.getElementById("photoPreview");
        const photoPlaceholder = document.getElementById("photoPlaceholder");
        const setupStorageKeyField = document.getElementById("setup_storage_key");
        const setupStorageKey = setupStorageKeyField ? setupStorageKeyField.value : "teacher_setup_form";
        const passwordInput = document.getElementById("password");
        const setupForm = document.getElementById("setupForm");

        function saveSetupFormData() {
            const firstNameField = document.getElementById("first_name");
            const lastNameField = document.getElementById("last_name");
            const mobileField = document.getElementById("mobile");
            const subjectField = document.getElementById("subject");
            const confirmPasswordField = document.getElementById("confirm_password");

            const formData = {
                first_name: firstNameField ? firstNameField.value : "",
                last_name: lastNameField ? lastNameField.value : "",
                mobile: mobileField ? mobileField.value : "",
                subject: subjectField ? subjectField.value : "",
                password: passwordInput ? passwordInput.value : "",
                confirm_password: confirmPasswordField ? confirmPasswordField.value : ""
            };

            sessionStorage.setItem(setupStorageKey, JSON.stringify(formData));
        }

        function restoreSetupFormData() {
            const savedData = sessionStorage.getItem(setupStorageKey);
            if (!savedData) {
                return;
            }

            try {
                const formData = JSON.parse(savedData);

                if (formData.first_name && document.getElementById("first_name")) {
                    document.getElementById("first_name").value = formData.first_name;
                }
                if (formData.last_name && document.getElementById("last_name")) {
                    document.getElementById("last_name").value = formData.last_name;
                }
                if (formData.mobile && document.getElementById("mobile")) {
                    document.getElementById("mobile").value = formData.mobile;
                }
                if (formData.subject && document.getElementById("subject")) {
                    document.getElementById("subject").value = formData.subject;
                }
                if (formData.password && passwordInput) {
                    passwordInput.value = formData.password;
                }
                if (formData.confirm_password && document.getElementById("confirm_password")) {
                    document.getElementById("confirm_password").value = formData.confirm_password;
                }
            } catch (error) {
                sessionStorage.removeItem(setupStorageKey);
            }
        }
        
        if (photoInput) {
            photoInput.addEventListener("change", function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        photoPreview.src = event.target.result;
                        photoPreview.style.display = "block";
                        photoPlaceholder.style.display = "none";
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.nextElementSibling.querySelector("i");
            
            if (field.type === "password") {
                field.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                field.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }

        function showSetupError(message, fieldId = null, shouldOpenPhotoPicker = false) {
            Swal.fire({
                icon: "error",
                title: "Setup Failed",
                text: message,
                confirmButtonText: "OK",
                confirmButtonColor: "#3498db",
                allowOutsideClick: false
            }).then(() => {
                if (fieldId) {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        field.focus();
                    }
                }

                if (shouldOpenPhotoPicker && photoInput) {
                    photoInput.click();
                }
            });
        }
        
        // Auto-focus on first name field
        restoreSetupFormData();
        document.getElementById("first_name").focus();
        const persistedFields = ["first_name", "last_name", "mobile", "subject", "confirm_password"];

        persistedFields.forEach(function(fieldId) {
            const field = document.getElementById(fieldId);
            if (!field) {
                return;
            }

            const eventName = field.tagName === "SELECT" ? "change" : "input";
            field.addEventListener(eventName, saveSetupFormData);
        });

        if (passwordInput) {
            saveSetupFormData();
        }
        
        // Real-time validation for mobile number
        const mobileInput = document.getElementById("mobile");
        if (mobileInput) {
            mobileInput.addEventListener("input", function() {
                this.value = this.value.replace(/[^0-9]/g, "").slice(0, 10);
            });
        }
        
        // Form validation
        setupForm.addEventListener("submit", function(e) {
            const password = passwordInput.value;
            const confirm = document.getElementById("confirm_password").value;
            const firstName = document.getElementById("first_name").value.trim();
            const lastName = document.getElementById("last_name").value.trim();
            const mobile = document.getElementById("mobile").value.trim();
            const subject = document.getElementById("subject").value;
            const selectedPhoto = photoInput ? photoInput.files[0] : null;
            
            if (!firstName) {
                e.preventDefault();
                showSetupError("Please enter your first name", "first_name");
                return false;
            }
            
            if (!lastName) {
                e.preventDefault();
                showSetupError("Please enter your last name", "last_name");
                return false;
            }
            
            if (!mobile) {
                e.preventDefault();
                showSetupError("Please enter your mobile number", "mobile");
                return false;
            }
            
            if (!/^\\d{10}$/.test(mobile)) {
                e.preventDefault();
                showSetupError("Please enter a valid 10-digit mobile number", "mobile");
                return false;
            }
            
            if (!subject) {
                e.preventDefault();
                showSetupError("Please select your subject", "subject");
                return false;
            }

            if (!selectedPhoto) {
                e.preventDefault();
                showSetupError("Please upload your profile photo", null, true);
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                showSetupError("Password must be at least 8 characters long!", "password");
                return false;
            }
            
            if (!/[A-Z]/.test(password)) {
                e.preventDefault();
                showSetupError("Password must contain at least one uppercase letter!", "password");
                return false;
            }
            
            if (!/[a-z]/.test(password)) {
                e.preventDefault();
                showSetupError("Password must contain at least one lowercase letter!", "password");
                return false;
            }
            
            if (!/[0-9]/.test(password)) {
                e.preventDefault();
                showSetupError("Password must contain at least one number!", "password");
                return false;
            }
            
            if (!/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                e.preventDefault();
                showSetupError("Password must contain at least one special character (!@#$%^&*)!", "password");
                return false;
            }
            
            if (password !== confirm) {
                e.preventDefault();
                showSetupError("Passwords do not match!", "confirm_password");
                return false;
            }
            
            const submitBtn = document.getElementById("submitBtn");
            submitBtn.disabled = true;
            submitBtn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> Setting up...";
            return true;
        });
        </script>
    </body>
    </html>';
    exit;
}

// Function to get user IP
function getTeacherIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// Create uploads directory if not exists
$upload_dir = __DIR__ . '/uploads/teacher_photos/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle first-time password setup with photo upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['first_time_setup'])) {
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $error = '';
    
    // Validate inputs
    if (empty($first_name)) $error = "Please enter your first name";
    elseif (empty($last_name)) $error = "Please enter your last name";
    elseif (empty($mobile)) $error = "Please enter your mobile number";
    elseif (!preg_match('/^[0-9]{10}$/', $mobile)) $error = "Please enter a valid 10-digit mobile number";
    elseif (empty($subject)) $error = "Please select your subject";
    elseif (!isset($_FILES['teacher_photo']) || $_FILES['teacher_photo']['error'] === UPLOAD_ERR_NO_FILE) $error = "Profile photo is required";
    elseif (strlen($password) < 8) $error = "Password must be at least 8 characters long";
    elseif (!preg_match('/[A-Z]/', $password)) $error = "Password must contain at least one uppercase letter";
    elseif (!preg_match('/[a-z]/', $password)) $error = "Password must contain at least one lowercase letter";
    elseif (!preg_match('/[0-9]/', $password)) $error = "Password must contain at least one number";
    elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) $error = "Password must contain at least one special character (!@#$%^&*)";
    elseif ($password !== $confirm_password) $error = "Passwords do not match";
    
    // Handle photo upload
    $photo_path = '';
    if (isset($_FILES['teacher_photo']) && $_FILES['teacher_photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $file_type = $_FILES['teacher_photo']['type'];
        $file_size = $_FILES['teacher_photo']['size'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($file_type, $allowed_types)) {
            $error = "Only JPG, JPEG, and PNG images are allowed!";
        } elseif ($file_size > $max_size) {
            $error = "Image size must be less than 2MB!";
        } else {
            $file_extension = pathinfo($_FILES['teacher_photo']['name'], PATHINFO_EXTENSION);
            $photo_filename = 'teacher_' . $teacher_id . '_' . time() . '.' . $file_extension;
            $photo_path = 'uploads/teacher_photos/' . $photo_filename;
            $upload_path = __DIR__ . '/' . $photo_path;
            
            if (move_uploaded_file($_FILES['teacher_photo']['tmp_name'], $upload_path)) {
                // Photo uploaded successfully
            } else {
                $error = "Failed to upload photo. Please try again.";
            }
        }
    }
    
    if (!empty($error)) {
        showTeacherAlert('error', 'Setup Failed', $error, 'teacher_login.php?setup=1');
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $ip = getTeacherIP();
    
    // Update teacher record
    $update_stmt = $conn->prepare("UPDATE teachers SET password = ?, first_name = ?, last_name = ?, mobile = ?, subject = ?, photo = ?, status = 'active', profile_completed = 1, ip_address = ?, last_login = NOW() WHERE id = ? AND (password IS NULL OR password = '')");
    $update_stmt->bind_param("sssssssi", $hashed_password, $first_name, $last_name, $mobile, $subject, $photo_path, $ip, $teacher_id);
    
    if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
        unset($_SESSION['pending_teacher_setup']);

        // Set session for auto-login
        $_SESSION['teacher_logged_in'] = true;
        $_SESSION['teacher_id'] = $teacher_id;
        $_SESSION['teacher_email'] = $email;
        $_SESSION['teacher_name'] = $first_name . ' ' . $last_name;
        $_SESSION['teacher_first_name'] = $first_name;
        $_SESSION['teacher_last_name'] = $last_name;
        $_SESSION['teacher_mobile'] = $mobile;
        $_SESSION['teacher_subject'] = $subject;
        $_SESSION['teacher_photo'] = $photo_path;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $ip;
        
        // Log the activity
        $audit = $conn->prepare("INSERT INTO teacher_audit (teacher_id, email, teacher_name, ip_address, action, timestamp, user_agent) VALUES (?, ?, ?, ?, 'first_time_setup', NOW(), ?)");
        $audit->bind_param("issss", $teacher_id, $email, $_SESSION['teacher_name'], $ip, $_SERVER['HTTP_USER_AGENT']);
        $audit->execute();
        
        showTeacherAlert('success', 'Setup Complete!', 'Your account has been set up successfully. Welcome to Ruchi Classes!', 'teacher_dashboard.php');
    } else {
        showTeacherAlert('error', 'Setup Failed', 'Unable to complete setup. Please contact administrator.', 'teacher_login.php');
    }
    exit;
}

if (isset($_GET['setup']) && $_GET['setup'] == '1' && !empty($_SESSION['pending_teacher_setup'])) {
    $pending_setup = $_SESSION['pending_teacher_setup'];
    showPasswordSetup(
        $pending_setup['email'] ?? '',
        (int) ($pending_setup['teacher_id'] ?? 0),
        $pending_setup['password'] ?? ''
    );
}

// Handle regular login (including first-time detection)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['first_time_setup'])) {
    // Get credentials
    $email = trim($_POST['email'] ?? '');
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $password = trim($_POST['password'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    $login_redirect = getTeacherLoginRedirect($email);
    
    // Validate CSRF token
    if (!isset($_SESSION['teacher_csrf_token']) || !hash_equals($_SESSION['teacher_csrf_token'], $csrf_token)) {
        showTeacherAlert('error', 'Security Error', 'Invalid security token. Please refresh the page.', $login_redirect);
    }
    
    // Validate inputs
    if (empty($email) || empty($password)) {
        showTeacherAlert('error', 'Missing Information', 'Please enter both email and password.', $login_redirect);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        showTeacherAlert('error', 'Invalid Email', 'Please enter a valid email address.', $login_redirect);
    }
    
    $ip = getTeacherIP();
    
    // Check teacher credentials
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows == 0){
        showTeacherAlert('error', 'Email Not Found', 'This email is not registered as a teacher. Please check your email address.', $login_redirect);
    }

    $teacher = $result->fetch_assoc();

    if (($teacher['status'] ?? '') === 'inactive') {
        $log = $conn->prepare("INSERT INTO teacher_login_attempts (teacher_id, email, ip_address, attempt_time, success) VALUES (?, ?, ?, NOW(), 0)");
        $log->bind_param("iss", $teacher['id'], $teacher['email'], $ip);
        $log->execute();

        showTeacherAlert('error', 'Account Deactivated', 'Your account has been deactivated by the administrator. Please contact administrator.', getTeacherLoginRedirect($teacher['email']));
    }
    
    // CHECK IF TEACHER NEEDS TO SETUP PASSWORD (FIRST TIME LOGIN)
    if (empty($teacher['password']) || $teacher['password'] == '') {
        // First time login - redirect to password setup with email auto-filled
        redirectToTeacherSetup($teacher['email'], $teacher['id'], $password);
    }
    
    // Verify password for existing users
    if(!password_verify($password, $teacher['password'])){
        // Log failed attempt
        $log = $conn->prepare("INSERT INTO teacher_login_attempts (teacher_id, email, ip_address, attempt_time, success) VALUES (?, ?, ?, NOW(), 0)");
        $log->bind_param("iss", $teacher['id'], $teacher['email'], $ip);
        $log->execute();
        
        showTeacherAlert('error', 'Incorrect Password', 'The password you entered is incorrect. Please try again.', getTeacherLoginRedirect($teacher['email']));
    }
    
    // Check if account is active
    if (($teacher['status'] ?? '') !== 'active') {
        showTeacherAlert('error', 'Account Restricted', 'Your account is not active yet. Please contact administrator.', getTeacherLoginRedirect($teacher['email']));
    }

    // Log successful login
    $log = $conn->prepare("INSERT INTO teacher_login_attempts (teacher_id, email, ip_address, attempt_time, success) VALUES (?, ?, ?, NOW(), 1)");
    $log->bind_param("iss", $teacher['id'], $teacher['email'], $ip);
    $log->execute();
    
    // Set session variables
    session_regenerate_id(true);
    
    $_SESSION['teacher_logged_in'] = true;
    $_SESSION['teacher_id'] = $teacher['id'];
    $_SESSION['teacher_email'] = $teacher['email'];
    $_SESSION['teacher_name'] = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
    $_SESSION['teacher_first_name'] = $teacher['first_name'];
    $_SESSION['teacher_last_name'] = $teacher['last_name'];
    $_SESSION['teacher_mobile'] = $teacher['mobile'];
    $_SESSION['teacher_photo'] = $teacher['photo'];
    $_SESSION['teacher_subject'] = $teacher['subject'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['ip_address'] = $ip;
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

    // Generate CSRF token for forms
    $_SESSION['teacher_csrf_token'] = bin2hex(random_bytes(32));

    // Update last login
    $update = $conn->prepare("UPDATE teachers SET last_login = NOW(), ip_address = ? WHERE id = ?");
    $update->bind_param("si", $ip, $teacher['id']);
    $update->execute();

    // Log to teacher_audit
    $audit = $conn->prepare("INSERT INTO teacher_audit (teacher_id, email, teacher_name, ip_address, action, timestamp, user_agent) VALUES (?, ?, ?, ?, 'login', NOW(), ?)");
    $audit->bind_param("issss", $teacher['id'], $teacher['email'], $_SESSION['teacher_name'], $ip, $_SERVER['HTTP_USER_AGENT']);
    $audit->execute();

    // Redirect to dashboard
    showTeacherAlert('success', 'Login Successful!', 'Welcome back ' . $_SESSION['teacher_name'] . '!', 'teacher_dashboard.php', 1500);
}

// Generate CSRF token for form
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['teacher_csrf_token'] = $csrf_token;

// Check for saved credentials from browser autofill
$saved_email = isset($_GET['email']) ? htmlspecialchars($_GET['email']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Teacher Login - Ruchi Classes</title>
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
        background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); 
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
        border: 2px solid rgba(52, 152, 219, 0.1);
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
        background: linear-gradient(to right, #2c3e50, #3498db);
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
        box-shadow: 0 8px 25px rgba(52, 152, 219, 0.2);
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
        background: linear-gradient(to right, #2c3e50, #3498db);
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
        border-color:#3498db; 
        box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.2); 
        outline: none;
        background: #fff;
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
        color:#3498db; 
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
        color: #3498db;
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
        color: #3498db;
    }
    button { 
        width:100%; 
        padding:18px; 
        background:linear-gradient(to right, #2c3e50, #3498db); 
        color:white; 
        font-size:18px; 
        font-weight:600; 
        border:none; 
        border-radius:12px; 
        cursor:pointer; 
        transition:0.3s; 
        margin-top: 20px;
        box-shadow: 0 6px 20px rgba(52, 152, 219, 0.3);
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
        box-shadow: 0 8px 25px rgba(52, 152, 219, 0.4);
    }
    button:active {
        transform: translateY(0);
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
        color: #3498db;
        margin-right: 5px;
    }
    
    /* Autofill styles */
    input:-webkit-autofill,
    input:-webkit-autofill:hover,
    input:-webkit-autofill:focus,
    input:-webkit-autofill:active {
        -webkit-box-shadow: 0 0 0 30px #f8f9fa inset !important;
        box-shadow: 0 0 0 30px #f8f9fa inset !important;
        background-color: #f8f9fa !important;
        background-image: none !important;
    }
    
    @media (max-width: 768px) {
        body { padding: 15px; }
        .login-container { padding: 30px 25px; }
        h2 { font-size: 24px; }
        .logo { max-width: 140px; }
        input { padding: 16px 16px 16px 50px; }
        button { padding: 17px; font-size: 17px; }
    }
    
    @media (max-width: 480px) {
        .login-container { padding: 25px 20px; }
        h2 { font-size: 22px; }
        .logo { max-width: 120px; }
        input { padding: 15px 15px 15px 45px; }
        .input-icon { left: 15px; font-size: 18px; }
        .password-toggle { right: 15px; }
    }
    
    .spinner {
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
        display: none;
    }
    @keyframes spin {
        0% { transform: translate(-50%, -50%) rotate(0deg); }
        100% { transform: translate(-50%, -50%) rotate(360deg); }
    }
    button.loading .btn-text {
        opacity: 0;
    }
    button.loading .spinner {
        display: block;
    }
    
    /* Saved credentials message */
    .saved-credentials-note {
        background: #e8f4fd;
        padding: 10px 15px;
        border-radius: 8px;
        margin-top: 15px;
        font-size: 12px;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 8px;
        border-left: 3px solid #3498db;
    }
    .password-requirements-login {
        display: none;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        margin: -5px 0 20px;
        border: 1px solid #e0e0e0;
        font-size: 13px;
        color: #666;
    }
    .password-requirements-login.visible {
        display: block;
    }
    .password-requirements-login p {
        margin-bottom: 10px;
        font-weight: 600;
        color: #2c3e50;
    }
    .password-requirements-login ul {
        list-style: none;
        padding-left: 0;
        margin: 0;
    }
    .password-requirements-login li {
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .password-requirements-login li i {
        width: 16px;
        font-size: 12px;
    }
    .password-requirements-login li i.fa-check-circle {
        color: #27ae60;
    }
    .password-requirements-login li i.fa-times {
        color: #e74c3c;
    }
</style>
</head>
<body>

<div class="login-container">
    <div class="logo-container">
        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo" onerror="this.onerror=null; this.src='https://via.placeholder.com/180/2c3e50/FFFFFF?text=Ruchi+Classes';">
        <h2><i class="fas fa-chalkboard-teacher"></i> Teacher Portal</h2>
    </div>
    
    <?php if (!empty($saved_email)): ?>
    <div class="saved-credentials-note">
        <i class="fas fa-check-circle" style="color: #27ae60;"></i>
        <span>Welcome back! Your email has been auto-filled. Enter your password to continue.</span>
    </div>
    <?php endif; ?>
    
    <form action="teacher_login.php" method="POST" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="input-group">
            <i class="input-icon fas fa-envelope"></i>
            <input 
                type="email" 
                name="email" 
                id="email" 
                placeholder=" " 
                required 
                autocomplete="email"
                value="<?php echo $saved_email; ?>"
            >
            <label>Teacher Email</label>
        </div>
        <div class="input-group">
            <i class="input-icon fas fa-lock"></i>
            <input 
                type="password" 
                name="password" 
                id="passwordInput" 
                placeholder=" " 
                required 
                autocomplete="current-password"
            >
            <label>Password</label>
            <span class="password-toggle" id="togglePass" role="button" aria-label="Show password">
                <i class="fas fa-eye"></i>
            </span>
        </div>

        <div class="password-requirements-login" id="loginPasswordRequirements">
            <p><i class="fas fa-shield-alt"></i> Password Requirements:</p>
            <ul>
                <li id="login-length-check"><i class="fas fa-times"></i> At least 8 characters</li>
                <li id="login-lowercase-check"><i class="fas fa-times"></i> At least one lowercase letter</li>
                <li id="login-uppercase-check"><i class="fas fa-times"></i> At least one uppercase letter</li>
                <li id="login-number-check"><i class="fas fa-times"></i> At least one number</li>
                <li id="login-special-check"><i class="fas fa-times"></i> At least one special character (!@#$%^&*)</li>
            </ul>
        </div>
        
        <div class="security-note">
            <i class="fas fa-shield-alt"></i> First time login? You'll set up your password after entering your email.
        </div>
        
        <button type="submit" id="loginButton">
            <span class="btn-text">Login</span>
            <div class="spinner"></div>
        </button>
        
        <p style="text-align:center; margin-top:15px; font-size:13px; color:#666;">
            <i class="fas fa-info-circle"></i> If this is your first time, you'll be prompted to set up your password
        </p>
    </form>
</div>

<script>
    // Password toggle functionality
    const passwordInput = document.getElementById('passwordInput');
    const togglePass = document.getElementById('togglePass');
    const eyeIcon = togglePass.querySelector('i');
    const passwordRequirements = document.getElementById('loginPasswordRequirements');

    function updateRequirementState(elementId, isValid, text) {
        const element = document.getElementById(elementId);
        if (!element) {
            return;
        }

        element.innerHTML = `<i class="fas ${isValid ? 'fa-check-circle' : 'fa-times'}"></i> ${text}`;
        element.style.color = isValid ? '#27ae60' : '#e74c3c';
    }

    function validateLoginPassword() {
        const password = passwordInput.value;

        updateRequirementState('login-length-check', password.length >= 8, 'At least 8 characters');
        updateRequirementState('login-lowercase-check', /[a-z]/.test(password), 'At least one lowercase letter');
        updateRequirementState('login-uppercase-check', /[A-Z]/.test(password), 'At least one uppercase letter');
        updateRequirementState('login-number-check', /[0-9]/.test(password), 'At least one number');
        updateRequirementState('login-special-check', /[!@#$%^&*(),.?":{}|<>]/.test(password), 'At least one special character (!@#$%^&*)');
    }

    function syncPasswordRequirementsVisibility() {
        if (!passwordRequirements) {
            return;
        }

        const shouldShow = document.activeElement === passwordInput || passwordInput.value.trim() !== '';
        passwordRequirements.classList.toggle('visible', shouldShow);
    }
    
    if (togglePass) {
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
    }

    if (passwordInput) {
        passwordInput.addEventListener('focus', () => {
            validateLoginPassword();
            syncPasswordRequirementsVisibility();
        });

        passwordInput.addEventListener('input', () => {
            validateLoginPassword();
            syncPasswordRequirementsVisibility();
        });

        passwordInput.addEventListener('blur', syncPasswordRequirementsVisibility);
    }

    // Form submission loading
    const loginForm = document.getElementById('loginForm');
    const loginButton = document.getElementById('loginButton');
    
    if (loginForm) {
        loginForm.addEventListener('submit', () => {
            const email = document.getElementById('email').value.trim();
            if (email) {
                loginButton.classList.add('loading');
                loginButton.disabled = true;
            }
        });
    }
    
    // Auto-focus on password if email is already filled
    const emailField = document.getElementById('email');
    const passwordField = document.getElementById('passwordInput');
    
    if (emailField && emailField.value.trim() !== '') {
        // If email is already filled, focus on password field
        passwordField.focus();
    } else {
        // Otherwise focus on email field
        emailField.focus();
    }
    
    // Save email to localStorage for better UX on subsequent visits
    if (emailField) {
        emailField.addEventListener('change', function() {
            if (this.value.trim() !== '') {
                localStorage.setItem('saved_teacher_email', this.value.trim());
            }
        });
        
        // Check if there's a saved email in localStorage and field is empty
        if (emailField.value.trim() === '') {
            const savedEmail = localStorage.getItem('saved_teacher_email');
            if (savedEmail) {
                emailField.value = savedEmail;
                passwordField.focus();
            }
        }
    }

    validateLoginPassword();
    syncPasswordRequirementsVisibility();

    window.addEventListener('load', () => {
        setTimeout(() => {
            validateLoginPassword();
            syncPasswordRequirementsVisibility();
        }, 200);
    });
</script>
</body>
</html>
