<?php
session_start();

// Include the main auth file
require_once 'admin_auth.php';

// This file will automatically check auth when included
// You can use functions from admin_auth.php in your pages
?>

<!-- Optional: Display admin info if logged in -->
<?php if (checkAdminAuth()): ?>
<div class="admin-info-bar" style="position: fixed; top: 0; left: 0; right: 0; background: #1976D2; color: white; padding: 10px; font-size: 14px; z-index: 9999; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <i class="fas fa-user-shield"></i>
        <strong>Admin Portal:</strong> 
        <span id="adminSessionTimer" style="margin-left: 20px;">
            <i class="fas fa-clock"></i> Session: 30:00
        </span>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <span>
            <i class="fas fa-user-tie"></i>
            <?php 
            $admin = getAdminInfo();
            echo htmlspecialchars($admin['name']); 
            ?> 
            (<?php echo htmlspecialchars($admin['type']); ?>)
        </span>
        <a href="admin_logout.php" style="color: white; text-decoration: none; background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 15px;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>

<script>
// Session timer countdown
let sessionTime = 1800; // 30 minutes in seconds
const timerElement = document.getElementById('adminSessionTimer');

function updateSessionTimer() {
    if (sessionTime <= 0) {
        // Session expired
        window.location.href = 'admin_logout.php?timeout=1';
        return;
    }
    
    const minutes = Math.floor(sessionTime / 60);
    const seconds = sessionTime % 60;
    
    timerElement.innerHTML = `
        <i class="fas fa-clock"></i> 
        Session: ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}
        <span style="font-size: 12px; color: ${sessionTime < 300 ? '#ffcc00' : 'white'}">
            ${sessionTime < 300 ? '(Expiring Soon)' : ''}
        </span>
    `;
    
    sessionTime--;
}

// Update timer every second
setInterval(updateSessionTimer, 1000);

// Reset timer on user activity
document.addEventListener('mousemove', () => {
    // Optional: Reset session timer on activity
    // In real implementation, you might want to refresh via AJAX
});

// Warn before session expires
setTimeout(() => {
    if (sessionTime < 300) { // 5 minutes warning
        Swal.fire({
            title: 'Session Expiring Soon',
            text: 'Your admin session will expire in 5 minutes. Please save your work.',
            icon: 'warning',
            timer: 10000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false
        });
    }
}, (1800 - 300) * 1000);
</script>
<?php endif; ?>