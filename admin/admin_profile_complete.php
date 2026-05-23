<?php
session_start();
require_once 'admin_auth.php';
requireAdminAuth();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// Check if profile already completed
if (isset($_SESSION['profile_completed']) && $_SESSION['profile_completed'] == 1) {
    header("Location: admin_dashboard.php");
    exit;
}

// Database connection
@require '../db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'ruchi_classes');
}

// Get current admin data to pre-fill form
$admin_id = $_SESSION['admin_id'];
$admin_data = null;
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $admin_data = $result->fetch_assoc();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $admin_id = $_SESSION['admin_id'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $name = $first_name . ' ' . $last_name; // Combine to create full name
    
    // Handle photo upload
    $photo = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $upload_dir = './uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Check file size (max 2MB = 2097152 bytes)
        $max_file_size = 2 * 1024 * 1024; // 2MB
        if ($_FILES['photo']['size'] > $max_file_size) {
            $error = "File size exceeds 2MB limit. Your file is " . round($_FILES['photo']['size'] / (1024 * 1024), 2) . "MB";
        } else {
            // Generate unique filename
            $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $file_name = 'admin_' . $admin_id . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $file_name;
            
            // Check file type
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_types)) {
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                    // Save relative path for database
                    $photo = 'admin/uploads/' . $file_name;
                } else {
                    $error = "Error uploading file. Please try again.";
                }
            } else {
                $error = "Invalid file type. Only JPG, PNG, GIF are allowed.";
            }
        }
    }
    
    // Update admin profile
    $stmt = $conn->prepare("UPDATE admins SET name = ?, first_name = ?, last_name = ?, phone = ?, photo = ?, profile_completed = 1 WHERE id = ?");
    $stmt->bind_param("sssssi", $name, $first_name, $last_name, $phone, $photo, $admin_id);
    
    if ($stmt->execute()) {
        // Update session
        $_SESSION['profile_completed'] = 1;
        $_SESSION['admin_name'] = $name;
        $_SESSION['admin_first_name'] = $first_name;
        $_SESSION['admin_last_name'] = $last_name;
        $_SESSION['admin_phone'] = $phone;
        $_SESSION['admin_photo'] = $photo;
        
        // Redirect to dashboard
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Redirecting...</title>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: "success",
                    title: "Profile Completed!",
                    text: "Your profile has been updated successfully.",
                    confirmButtonText: "Go to Dashboard"
                }).then(() => {
                    window.location.href = "admin_dashboard.php";
                });
            </script>
        </body>
        </html>';
        exit;
    } else {
        $error = "Error updating profile. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Admin Profile - Ruchi Classes</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a2980 0%, #26d0ce 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .profile-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }
        .profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(to right, #667eea, #764ba2);
        }
        .profile-header {
            text-align: center;
            margin-bottom: 35px;
        }
        .profile-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 40px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .profile-header h2 {
            color: #333;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .profile-header p {
            color: #666;
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 15px;
        }
        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.2);
            outline: none;
            background: #fff;
        }
        .row {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
        }
        .col {
            flex: 1;
        }
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
            font-size: 18px;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        .btn-submit:active {
            transform: translateY(0);
        }
        .preview-container {
            text-align: center;
            margin-bottom: 25px;
        }
        .preview-image {
            width: 140px;
            height: 140px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid #667eea;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .upload-btn {
            display: inline-block;
            padding: 12px 25px;
            background: #f8f9fa;
            border: 2px dashed #667eea;
            border-radius: 12px;
            color: #667eea;
            cursor: pointer;
            font-weight: 600;
            transition: 0.3s;
            text-align: center;
            margin-top: 15px;
        }
        .upload-btn:hover {
            background: #667eea;
            color: white;
        }
        .error-message {
            color: #e74c3c;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }
        .alert {
            border-radius: 10px;
            border: none;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .profile-card {
                padding: 30px 25px;
            }
            .row {
                flex-direction: column;
                gap: 0;
            }
            .col {
                margin-bottom: 20px;
            }
            .preview-image {
                width: 120px;
                height: 120px;
            }
        }
        
        @media (max-width: 480px) {
            .profile-card {
                padding: 25px 20px;
            }
            .profile-header h2 {
                font-size: 24px;
            }
            .profile-icon {
                width: 80px;
                height: 80px;
                font-size: 30px;
            }
            .form-control {
                padding: 14px;
            }
            .btn-submit {
                padding: 16px;
                font-size: 16px;
            }
            .preview-image {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>
    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-icon">
                <i class="fas fa-user-shield"></i>
            </div>
            <h2>Complete Your Profile</h2>
            <p>Welcome <?php echo htmlspecialchars($_SESSION['admin_email'] ?? 'Admin'); ?>! Please complete your profile details</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form id="profileForm" method="POST" enctype="multipart/form-data">
            <div class="preview-container">
                <img id="imagePreview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140' viewBox='0 0 140 140'%3E%3Crect width='140' height='140' fill='%23667eea'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' font-family='Arial' font-size='50' fill='white'%3E%3Ctspan x='50%25' dy='0'%3E<?php echo strtoupper(substr($_SESSION['admin_email'] ?? 'A', 0, 1)); ?>%3E%3C/tspan%3E%3C/text%3E%3C/svg%3E" 
                     class="preview-image" alt="Profile Preview">
                <div>
                    <label for="photoInput" class="upload-btn">
                        <i class="fas fa-camera me-2"></i>Upload Profile Photo
                    </label>
                    <input type="file" id="photoInput" name="photo" accept="image/*" style="display: none;">
                    <small style="display: block; margin-top: 8px; color: #666; font-size: 13px;">
                        <i class="fas fa-info-circle"></i> Optional: JPG, PNG, GIF (Max 2MB)
                    </small>
                </div>
            </div>
            
            <div class="row">
                <div class="col">
                    <label class="form-label">First Name *</label>
                    <input type="text" class="form-control" name="first_name" required maxlength="100" 
                           value="<?php echo htmlspecialchars($admin_data['first_name'] ?? ''); ?>">
                    <div class="error-message" id="firstNameError"></div>
                </div>
                <div class="col">
                    <label class="form-label">Last Name *</label>
                    <input type="text" class="form-control" name="last_name" required maxlength="100"
                           value="<?php echo htmlspecialchars($admin_data['last_name'] ?? ''); ?>">
                    <div class="error-message" id="lastNameError"></div>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label">Phone Number *</label>
                <input type="tel" class="form-control" name="phone" required pattern="[0-9]{10}" maxlength="10" 
                       placeholder="10-digit number" value="<?php echo htmlspecialchars($admin_data['phone'] ?? ''); ?>">
                <div class="error-message" id="phoneError"></div>
                <small style="color: #666; font-size: 13px; margin-top: 5px; display: block;">
                    <i class="fas fa-info-circle"></i> Enter 10-digit phone number without country code
                </small>
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-check-circle"></i> Complete Profile & Continue
            </button>
            
            <div style="text-align: center; margin-top: 20px; font-size: 14px; color: #666;">
                <i class="fas fa-info-circle"></i> All fields marked with * are required
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
        // Image preview
        document.getElementById('photoInput').addEventListener('change', function(e) {
            const preview = document.getElementById('imagePreview');
            const file = e.target.files[0];
            
            if (file) {
                // Validate file type
                const validMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
                const validExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                const fileExtension = file.name.split('.').pop().toLowerCase();
                
                if (!validExtensions.includes(fileExtension) || !validMimeTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPEG, PNG, GIF)');
                    this.value = '';
                    return;
                }
                
                // Validate file size (max 2MB)
                const maxSize = 2 * 1024 * 1024; // 2MB
                if (file.size > maxSize) {
                    alert('File size should be less than 2MB. Your file is ' + (file.size / (1024 * 1024)).toFixed(2) + 'MB');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
        
        // Form validation
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => {
                el.style.display = 'none';
                el.textContent = '';
            });
            
            // Validate first name
            const firstName = document.querySelector('input[name="first_name"]');
            if (!firstName.value.trim()) {
                showError('firstNameError', 'First name is required');
                isValid = false;
            } else if (firstName.value.trim().length < 2) {
                showError('firstNameError', 'First name must be at least 2 characters');
                isValid = false;
            }
            
            // Validate last name
            const lastName = document.querySelector('input[name="last_name"]');
            if (!lastName.value.trim()) {
                showError('lastNameError', 'Last name is required');
                isValid = false;
            } else if (lastName.value.trim().length < 2) {
                showError('lastNameError', 'Last name must be at least 2 characters');
                isValid = false;
            }
            
            // Validate phone
            const phone = document.querySelector('input[name="phone"]');
            if (!phone.value.trim()) {
                showError('phoneError', 'Phone number is required');
                isValid = false;
            } else if (!/^\d{10}$/.test(phone.value)) {
                showError('phoneError', 'Please enter a valid 10-digit phone number');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = document.querySelector('.error-message[style*="display: block"]');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
        
        function showError(elementId, message) {
            const element = document.getElementById(elementId);
            element.textContent = message;
            element.style.display = 'block';
        }
        
        // Trigger file input when upload button is clicked
        document.querySelector('.upload-btn').addEventListener('click', function() {
            document.getElementById('photoInput').click();
        });
        
        // Real-time validation
        document.querySelectorAll('input[required]').forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
        });
        
        function validateField(field) {
            const fieldName = field.name;
            const value = field.value.trim();
            
            if (fieldName === 'first_name' || fieldName === 'last_name') {
                if (value && value.length < 2) {
                    showError(fieldName + 'Error', 'Must be at least 2 characters');
                } else {
                    document.getElementById(fieldName + 'Error').style.display = 'none';
                }
            }
            
            if (fieldName === 'phone') {
                if (value && !/^\d{10}$/.test(value)) {
                    showError('phoneError', 'Must be 10 digits');
                } else {
                    document.getElementById('phoneError').style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>