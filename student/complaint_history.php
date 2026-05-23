<?php
// ==================== IRON-CLAD AUTH CHECK ====================
session_start();

// If NOT logged in, DESTROY EVERYTHING and redirect
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    // Log this security breach
    error_log("SECURITY BREACH: Direct complaint history access from IP: " . $_SERVER['REMOTE_ADDR']);
    
    // Nuke the session
    session_unset();
    session_destroy();
    
    // Nuke all cookies
    if (isset($_SERVER['HTTP_COOKIE'])) {
        $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
        foreach($cookies as $cookie) {
            $parts = explode('=', $cookie);
            $name = trim($parts[0]);
            setcookie($name, '', time() - 3600, '/');
        }
    }
    
    // Send HARD redirect with no chance of bypass
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="refresh" content="0;url=login.html">
        <script>
            // Clear localStorage too
            localStorage.clear();
            sessionStorage.clear();
            
            // Force redirect even if meta fails
            setTimeout(function() {
                window.location.replace("login.html");
            }, 100);
        </script>
        <title>Redirecting...</title>
    </head>
    <body>
        <center style="margin-top:100px;">
            <h2 style="color:red;">ACCESS DENIED</h2>
            <p>Redirecting to login page...</p>
        </center>
    </body>
    </html>
    ');
    exit;
}

// ==================== DOUBLE-CHECK SESSION ====================
// Additional checks for session hijacking
$required_session_vars = ['student_id', 'student_email', 'login_time'];

foreach ($required_session_vars as $var) {
    if (!isset($_SESSION[$var]) || empty($_SESSION[$var])) {
        // Session is incomplete - destroy and redirect
        session_unset();
        session_destroy();
        header("Location: login.html?error=session_corrupted");
        exit;
    }
}

// Check session age (max 2 hours)
if (time() - $_SESSION['login_time'] > 7200) {
    session_unset();
    session_destroy();
    header("Location: login.html?error=session_expired");
    exit;
}

// Now we're 100% sure user is authenticated
// Continue with database connection...
require __DIR__ . '/../db.php';

// Get student information from session
$student_id = $_SESSION['student_id'];
$student_email = $_SESSION['student_email'];

// Try to get student_name from session, if not available fetch from database
if (isset($_SESSION['student_name']) && !empty($_SESSION['student_name'])) {
    $student_name = $_SESSION['student_name'];
} else {
    // Fetch student name from database based on email
    $stmt_name = $conn->prepare("SELECT first_name, last_name FROM student_english WHERE email = ? 
                                 UNION 
                                 SELECT first_name, last_name FROM student_hindi WHERE email = ? 
                                 LIMIT 1");
    $stmt_name->bind_param("ss", $student_email, $student_email);
    $stmt_name->execute();
    $name_result = $stmt_name->get_result();
    
    if ($name_result && $name_result->num_rows > 0) {
        $student_data = $name_result->fetch_assoc();
        $student_name = trim($student_data['first_name'] . ' ' . $student_data['last_name']);
        // Store in session for future use
        $_SESSION['student_name'] = $student_name;
    } else {
        $student_name = "Student";
    }
}

// Initialize $result variable
$result = null;

// ✅ Fetch complaints from database
try {
    $stmt = $conn->prepare("SELECT * FROM complaints WHERE user_type = 'student' AND user_id = ? ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        throw new Exception("Database query preparation failed");
    }
} catch (Exception $e) {
    error_log("Error fetching complaints: " . $e->getMessage());
    $result = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>My Complaint History</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      padding: 15px;
      -webkit-tap-highlight-color: transparent;
      overflow-x: hidden;
    }
    
    .container {
      max-width: 1000px;
      margin: 0 auto;
      background: #fff;
      padding: 20px;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    
    .header-info {
      text-align: center;
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid #f0f0f0;
    }
    
    h2 {
      color: #333;
      margin-bottom: 10px;
      font-size: 24px;
    }
    
    .student-info {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: center;
      gap: 10px;
      margin-top: 10px;
    }
    
    .student-name {
      font-size: 18px;
      color: #444;
      font-weight: 600;
    }
    
    .student-id {
      background: #e9ecef;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 14px;
      color: #666;
    }
    
    /* ============== DESKTOP TABLE ============== */
    .desktop-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
      display: none;
    }
    
    @media (min-width: 768px) {
      .desktop-table {
        display: table;
      }
    }
    
    .desktop-table th, 
    .desktop-table td {
      padding: 14px;
      text-align: left;
      border-bottom: 1px solid #eee;
    }
    
    .desktop-table th {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      font-weight: 600;
      position: sticky;
      top: 0;
    }
    
    .desktop-table tr:hover {
      background: #f9f9f9;
    }
    
    /* ============== MOBILE CARDS ============== */
    .mobile-cards {
      display: block;
      margin-top: 20px;
    }
    
    @media (min-width: 768px) {
      .mobile-cards {
        display: none;
      }
    }
    
    .complaint-card {
      background: #fff;
      border-radius: 12px;
      padding: 18px;
      margin-bottom: 15px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      border-left: 4px solid #667eea;
      transition: transform 0.3s ease;
    }
    
    .complaint-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }
    
    .card-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 12px;
      padding-bottom: 10px;
      border-bottom: 1px dashed #eee;
    }
    
    .card-label {
      font-weight: 600;
      color: #666;
      font-size: 14px;
      min-width: 100px;
    }
    
    .card-value {
      flex: 1;
      color: #333;
      text-align: right;
      font-size: 15px;
      word-break: break-word;
    }
    
    .complaint-text {
      background: #f8f9fa;
      padding: 12px;
      border-radius: 8px;
      margin: 10px 0;
      font-size: 15px;
      line-height: 1.5;
      color: #444;
    }
    
    /* ============== STATUS STYLES ============== */
    .status {
      display: inline-block;
      padding: 6px 14px;
      border-radius: 20px;
      font-weight: 600;
      font-size: 13px;
      text-align: center;
      min-width: 90px;
    }
    
    .status-pending { 
      background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
      color: #856404; 
      border: 1px solid #ffeaa7;
    }
    
    .status-resolved { 
      background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
      color: #155724; 
      border: 1px solid #c3e6cb;
    }
    
    .status-rejected { 
      background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
      color: #721c24; 
      border: 1px solid #f5c6cb;
    }
    
    /* ============== ACTION BUTTONS ============== */
    .action-buttons {
      display: flex;
      justify-content: center;
      gap: 15px;
      margin-top: 30px;
      flex-wrap: wrap;
    }
    
    .back-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 25px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      text-decoration: none;
      border-radius: 25px;
      font-weight: 600;
      font-size: 15px;
      transition: all 0.3s ease;
      border: none;
      cursor: pointer;
      min-width: 180px;
      box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .back-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
      text-decoration: none;
      color: white;
    }
    
    .back-btn i {
      margin-right: 8px;
    }
    
    /* ============== NO DATA ============== */
    .no-data {
      text-align: center;
      padding: 50px 20px;
      color: #666;
    }
    
    .no-data-icon {
      font-size: 60px;
      color: #ddd;
      margin-bottom: 20px;
    }
    
    .no-data h3 {
      font-size: 20px;
      margin-bottom: 10px;
      color: #555;
    }
    
    .no-data p {
      font-size: 15px;
      color: #888;
      max-width: 400px;
      margin: 0 auto;
    }
    
    /* ============== RESPONSIVE ADJUSTMENTS ============== */
    @media (max-width: 767px) {
      body {
        padding: 10px;
        background: #f5f7fa;
      }
      
      .container {
        padding: 15px;
        border-radius: 12px;
        margin-top: 0;
      }
      
      h2 {
        font-size: 22px;
      }
      
      .student-name {
        font-size: 16px;
      }

      .student-info {
        flex-direction: column;
        gap: 8px;
      }
      
      .student-id {
        font-size: 13px;
        padding: 4px 10px;
      }
      
      .complaint-card {
        padding: 15px;
      }
      
      .card-row {
        flex-direction: column;
        gap: 5px;
        margin-bottom: 15px;
      }
      
      .card-label {
        min-width: auto;
        font-size: 13px;
        color: #777;
      }
      
      .card-value {
        text-align: left;
        font-size: 14px;
      }

      .date-badge {
        justify-content: flex-start;
      }
      
      .back-btn {
        width: 100%;
        padding: 14px;
        font-size: 16px;
      }
      
      .action-buttons {
        flex-direction: column;
        gap: 10px;
      }
    }
    
    @media (max-width: 480px) {
      body {
        padding: 8px;
      }

      .container {
        padding: 12px;
        border-radius: 10px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.08);
      }
      
      h2 {
        font-size: 20px;
      }
      
      .header-info {
        margin-bottom: 20px;
      }
      
      .status {
        padding: 5px 10px;
        font-size: 12px;
        min-width: 80px;
      }
      
      .complaint-text {
        padding: 10px;
        font-size: 14px;
      }

      .complaint-card {
        padding: 14px;
        border-radius: 10px;
      }

      .card-row {
        margin-bottom: 12px;
        padding-bottom: 8px;
      }

      .back-btn {
        min-width: 0;
        padding: 13px 14px;
        font-size: 15px;
      }
    }
    
    /* ============== DATE BADGE ============== */
    .date-badge {
      background: #f0f2f5;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 12px;
      color: #666;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      flex-wrap: wrap;
      max-width: 100%;
    }
    
    .date-badge i {
      font-size: 11px;
    }
    
    /* ============== ERROR MESSAGE ============== */
    .error-message {
      background: #f8d7da;
      color: #721c24;
      padding: 15px;
      border-radius: 8px;
      margin: 20px 0;
      text-align: center;
      border-left: 4px solid #dc3545;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header-info">
      <h2>📝 Complaint History</h2>
      <div class="student-info">
        <span class="student-name"><?php echo htmlspecialchars($student_name ?? 'Student'); ?></span>
        <span class="student-id">ID: STU<?php echo htmlspecialchars($student_id ?? 'N/A'); ?></span>
      </div>
    </div>

    <?php 
    // Check if result is valid and has rows
    if ($result && $result->num_rows > 0): 
    ?>
      <!-- Desktop Table (Hidden on Mobile) -->
      <table class="desktop-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Complaint</th>
            <th>Date & Time</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $result->fetch_assoc()): ?>
            <?php 
              $status = $row['status'] ?? 'Pending';
              $status_lower = strtolower($status);
              $class = 'status-pending';
              if ($status_lower === 'resolved') $class = 'status-resolved';
              elseif ($status_lower === 'rejected') $class = 'status-rejected';
            ?>
            <tr>
              <td>#<?php echo htmlspecialchars($row['id']); ?></td>
              <td><?php echo htmlspecialchars($row['complaint']); ?></td>
              <td>
                <div class="date-badge">
                  <i class="fas fa-calendar-alt"></i>
                  <?php echo date("d M Y, h:i A", strtotime($row['created_at'])); ?>
                </div>
              </td>
              <td><span class="status <?php echo $class; ?>"><?php echo ucfirst($status); ?></span></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>

      <!-- Mobile Cards (Hidden on Desktop) -->
      <div class="mobile-cards">
        <?php 
        // Reset pointer to beginning
        $result->data_seek(0);
        while($row = $result->fetch_assoc()): 
          $status = $row['status'] ?? 'Pending';
          $status_lower = strtolower($status);
          $class = 'status-pending';
          if ($status_lower === 'resolved') $class = 'status-resolved';
          elseif ($status_lower === 'rejected') $class = 'status-rejected';
        ?>
          <div class="complaint-card">
            <div class="card-row">
              <span class="card-label">Complaint ID:</span>
              <span class="card-value">#<?php echo htmlspecialchars($row['id']); ?></span>
            </div>
            
            <div class="card-row">
              <span class="card-label">Status:</span>
              <span class="card-value">
                <span class="status <?php echo $class; ?>"><?php echo ucfirst($status); ?></span>
              </span>
            </div>
            
            <div class="card-row">
              <span class="card-label">Date:</span>
              <span class="card-value">
                <div class="date-badge">
                  <i class="fas fa-clock"></i>
                  <?php echo date("d M Y, h:i A", strtotime($row['created_at'])); ?>
                </div>
              </span>
            </div>
            
            <div style="margin-top: 10px;">
              <div class="card-label" style="margin-bottom: 8px;">Complaint Details:</div>
              <div class="complaint-text">
                <?php echo htmlspecialchars($row['complaint']); ?>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>

      <div style="text-align: center; margin-top: 20px; color: #666; font-size: 14px;">
        <p>📊 Total complaints: <strong><?php echo $result->num_rows; ?></strong></p>
      </div>
    <?php elseif ($result && $result->num_rows === 0): ?>
      <div class="no-data">
        <div class="no-data-icon">📭</div>
        <h3>No Complaints Found</h3>
        <p>You haven't submitted any complaints yet. You can submit a complaint from the dashboard.</p>
      </div>
    <?php else: ?>
      <div class="error-message">
        <h3>⚠️ Database Error</h3>
        <p>Unable to fetch complaint data. Please try again later.</p>
        <p style="font-size: 12px; margin-top: 10px;">
          If this problem persists, please contact support.
        </p>
      </div>
    <?php endif; ?>

    <div class="action-buttons">
      <a href="complain.php" class="back-btn">
        <i class="fas fa-plus-circle"></i> New Complaint
      </a>
      <a href="dashboard.php" class="back-btn" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>
  </div>

  <!-- Font Awesome for Icons -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
  
  <script>
    // Mobile touch improvements
    document.addEventListener('DOMContentLoaded', function() {
      // Add touch feedback to cards on mobile
      const cards = document.querySelectorAll('.complaint-card');
      cards.forEach(card => {
        card.addEventListener('touchstart', function() {
          this.style.transform = 'scale(0.98)';
        });
        
        card.addEventListener('touchend', function() {
          this.style.transform = 'scale(1)';
        });
      });
      
      // Prevent zoom on iOS
      document.addEventListener('touchstart', function(e) {
        if (e.touches.length > 1) {
          e.preventDefault();
        }
      }, { passive: false });
      
      // Update current date/time
      function updateDateTime() {
        const now = new Date();
        const dateOptions = { 
          weekday: 'long', 
          year: 'numeric', 
          month: 'long', 
          day: 'numeric' 
        };
        const timeOptions = { 
          hour: '2-digit', 
          minute: '2-digit', 
          hour12: true 
        };
        
        const dateStr = now.toLocaleDateString('en-US', dateOptions);
        const timeStr = now.toLocaleTimeString('en-US', timeOptions);
        
        // Update page title with time if needed
        document.title = `Complaint History | ${timeStr}`;
      }
      
      // Update time every minute
      updateDateTime();
      setInterval(updateDateTime, 60000);
    });
  </script>
</body>
</html>
