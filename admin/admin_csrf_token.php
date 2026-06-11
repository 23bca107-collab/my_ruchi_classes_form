<?php
session_start();

require_once __DIR__ . '/../includes/site_settings.php';
site_settings_start_favicon_buffer(null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Logout Successful - Ruchi Classes</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0d47a1 0%, #1565c0 50%, #1976d2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
    </style>
</head>
<body>
    <script>
        Swal.fire({
            title: '<div style="color: white;"><i class="fas fa-sign-out-alt" style="font-size: 60px;"></i><br>Admin Logout Successful</div>',
            html: `
                <div style="text-align: center; color: white;">
                    <h3 style="margin-bottom: 20px;">You have been securely logged out</h3>
                    <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 10px; margin: 20px 0;">
                        <i class="fas fa-shield-alt" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <h4>Security Recommendations:</h4>
                        <ul style="text-align: left; margin: 10px 0; padding-left: 20px;">
                            <li>Close your browser window</li>
                            <li>Clear browser cache if using public computer</li>
                            <li>Never share admin credentials</li>
                            <li>Use secure networks for admin access</li>
                        </ul>
                    </div>
                    <div style="margin-top: 20px;">
                        <a href="admin_login_form.php" style="display: inline-block; background: white; color: #1976D2; padding: 12px 25px; border-radius: 25px; text-decoration: none; font-weight: bold; margin-right: 10px;">
                            <i class="fas fa-sign-in-alt"></i> Login Again
                        </a>
                        <a href="../index.php" style="display: inline-block; background: transparent; color: white; border: 2px solid white; padding: 12px 25px; border-radius: 25px; text-decoration: none; font-weight: bold;">
                            <i class="fas fa-home"></i> Back to Home
                        </a>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            backdrop: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            timer: 8000,
            timerProgressBar: true,
            didOpen: () => {
                // Auto-redirect after 8 seconds
                setTimeout(() => {
                    window.location.href = "admin_login_form.php";
                }, 8000);
            }
        });
    </script>
</body>
</html>
