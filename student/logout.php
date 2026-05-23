<?php
session_start();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Logout</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            title: 'Logout?',
            text: 'Are you sure you want to logout?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Redirect to destroy session
                window.location.href = 'destroy_session.php';
            } else {
                // Go back to dashboard
                window.location.href = 'dashboard.php';
            }
        });
    </script>
</body>
</html>