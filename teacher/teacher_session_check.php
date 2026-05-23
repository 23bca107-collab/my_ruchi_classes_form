<?php
// teacher_session_check.php - Include this in all teacher pages for session timer

if (!isset($_SESSION['teacher_logged_in']) || $_SESSION['teacher_logged_in'] !== true) {
    return; // Not logged in, don't show timer
}
?>

<!-- Teacher Session Timer Bar -->
<div class="teacher-session-bar" style="position: fixed; top: 0; left: 0; right: 0; background: linear-gradient(135deg, #2c3e50, #3498db); color: white; padding: 10px 20px; font-size: 14px; z-index: 9999; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.2);">
    <div>
        <i class="fas fa-chalkboard-teacher"></i>
        <strong>Teacher Portal:</strong> 
        <span style="margin-left: 15px;">
            <i class="fas fa-user"></i> 
            <?php echo htmlspecialchars($_SESSION['teacher_name'] ?? 'Teacher'); ?>
            <?php if (!empty($_SESSION['teacher_subject'])): ?>
            <span style="margin-left: 10px; background: rgba(255,255,255,0.2); padding: 3px 8px; border-radius: 12px;">
                <i class="fas fa-book"></i> <?php echo htmlspecialchars($_SESSION['teacher_subject']); ?>
            </span>
            <?php endif; ?>
        </span>
        <span id="teacherSessionTimer" style="margin-left: 20px;">
            <i class="fas fa-clock"></i> Session: <span id="timerDisplay">30:00</span>
        </span>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <span id="sessionWarning" style="color: #ffcc00; display: none;">
            <i class="fas fa-exclamation-triangle"></i> Expiring Soon
        </span>
        <a href="teacher_logout.php" style="color: white; text-decoration: none; background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; transition: all 0.3s ease;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>

<style>
    .teacher-session-bar {
        animation: slideDown 0.5s ease;
    }
    @keyframes slideDown {
        from { transform: translateY(-100%); }
        to { transform: translateY(0); }
    }
    @media (max-width: 768px) {
        .teacher-session-bar {
            flex-direction: column;
            gap: 10px;
            padding: 10px;
            font-size: 12px;
        }
    }
</style>

<script>
// Session timer countdown
let sessionTime = 1800; // 30 minutes in seconds
const timerDisplay = document.getElementById('timerDisplay');
const sessionWarning = document.getElementById('sessionWarning');
let extendAttempts = 0;
const maxExtendAttempts = 3;

function updateSessionTimer() {
    if (sessionTime <= 0) {
        // Session expired
        Swal.fire({
            icon: 'warning',
            title: 'Session Expired',
            text: 'Your session has expired. Please login again.',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false
        }).then(() => {
            window.location.href = 'teacher_logout.php?timeout=1';
        });
        return;
    }
    
    const minutes = Math.floor(sessionTime / 60);
    const seconds = sessionTime % 60;
    
    timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    // Show warning when less than 5 minutes
    if (sessionTime < 300) {
        sessionWarning.style.display = 'inline';
        if (sessionTime % 60 === 0) { // Every minute when < 5 min
            // Try to extend session
            extendSession();
        }
    } else {
        sessionWarning.style.display = 'none';
    }
    
    sessionTime--;
}

// Function to extend session via AJAX
function extendSession() {
    if (extendAttempts >= maxExtendAttempts) {
        return; // Too many extend attempts
    }
    
    fetch('teacher_session_extend.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reset timer
            sessionTime = 1800;
            extendAttempts = 0;
            
            // Show success message (optional)
            if (sessionTime < 300) {
                Swal.fire({
                    icon: 'success',
                    title: 'Session Extended',
                    text: 'Your session has been extended by 30 minutes.',
                    timer: 2000,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }
        } else {
            extendAttempts++;
            if (extendAttempts >= maxExtendAttempts) {
                console.log('Max extend attempts reached');
            }
        }
    })
    .catch(error => {
        console.error('Session extend error:', error);
        extendAttempts++;
    });
}

// Update timer every second
setInterval(updateSessionTimer, 1000);

// Reset timer on user activity
let activityTimer;
document.addEventListener('mousemove', () => {
    clearTimeout(activityTimer);
    activityTimer = setTimeout(() => {
        // Reset timer on activity
        if (sessionTime < 300) {
            extendSession();
        }
    }, 10000); // Wait 10 seconds after activity
});

// Initial call
updateSessionTimer();

// Warn before session expires
setTimeout(() => {
    if (sessionTime < 300) {
        Swal.fire({
            icon: 'warning',
            title: 'Session Expiring Soon',
            html: 'Your session will expire in <strong>5 minutes</strong>. Please save your work.',
            timer: 10000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false
        });
    }
}, (1800 - 300) * 1000);
</script>