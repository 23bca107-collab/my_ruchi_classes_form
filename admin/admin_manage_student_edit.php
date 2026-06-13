<?php
declare(strict_types=1);
session_start();

// ==================== IRON-CLAD AUTH CHECK ====================
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    error_log("SECURITY BREACH: Direct admin access from IP: " . $_SERVER['REMOTE_ADDR']);
    session_unset();
    session_destroy();
    header("Location: admin_login.php");
    exit;
}

// Database connection
require __DIR__ . '/../db.php';

// ==================== CREATE/UPDATE PERMISSIONS TABLE ====================
$conn->query("
    CREATE TABLE IF NOT EXISTS student_edit_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class VARCHAR(10) NOT NULL,
        medium ENUM('English', 'Hindi') NOT NULL,
        student_id INT NULL,
        can_edit BOOLEAN DEFAULT FALSE,
        locked_by VARCHAR(100) DEFAULT 'system',
        locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_class_medium (class, medium),
        INDEX idx_student (student_id),
        UNIQUE KEY unique_student_permission (class, medium, student_id)
    )
");

// Ensure default permissions exist for each class-medium combination
$classes = ['8', '9', '10', '11', '12'];
$mediums = ['English', 'Hindi'];

foreach ($classes as $class) {
    foreach ($mediums as $medium) {
        $conn->query("INSERT IGNORE INTO student_edit_permissions (class, medium, student_id, can_edit, locked_by) 
                     VALUES ('$class', '$medium', NULL, FALSE, 'system')");
    }
}

// ==================== HANDLE LOCK/UNLOCK ACTIONS ====================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $class = $_POST['class'] ?? '';
    $medium = $_POST['medium'] ?? '';
    $studentId = $_POST['student_id'] ?? null;
    $adminName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Administrator';
    
    if ($action === 'lock_all') {
        // Lock all students in a specific class and medium
        if ($class && $medium) {
            $stmt = $conn->prepare("UPDATE student_edit_permissions SET can_edit = FALSE, locked_by = ? WHERE class = ? AND medium = ? AND student_id IS NULL");
            $stmt->bind_param("sss", $adminName, $class, $medium);
            if ($stmt->execute()) {
                $message = "All students in Class $class ($medium Medium) have been LOCKED from editing.";
                $messageType = "success";
                
                // Also lock individual student overrides
                $stmt2 = $conn->prepare("UPDATE student_edit_permissions SET can_edit = FALSE, locked_by = ? WHERE class = ? AND medium = ? AND student_id IS NOT NULL");
                $stmt2->bind_param("sss", $adminName, $class, $medium);
                $stmt2->execute();
            } else {
                $message = "Failed to lock students.";
                $messageType = "error";
            }
        }
    } 
    elseif ($action === 'unlock_all') {
        // Unlock all students in a specific class and medium
        if ($class && $medium) {
            $stmt = $conn->prepare("UPDATE student_edit_permissions SET can_edit = TRUE, locked_by = ? WHERE class = ? AND medium = ? AND student_id IS NULL");
            $stmt->bind_param("sss", $adminName, $class, $medium);
            if ($stmt->execute()) {
                $message = "All students in Class $class ($medium Medium) have been UNLOCKED for editing.";
                $messageType = "success";
                
                // Also unlock individual student overrides
                $stmt2 = $conn->prepare("UPDATE student_edit_permissions SET can_edit = TRUE, locked_by = ? WHERE class = ? AND medium = ? AND student_id IS NOT NULL");
                $stmt2->bind_param("sss", $adminName, $class, $medium);
                $stmt2->execute();
            } else {
                $message = "Failed to unlock students.";
                $messageType = "error";
            }
        }
    }
    elseif ($action === 'lock_individual') {
        // Lock a specific student
        if ($studentId && $class && $medium) {
            // First check if record exists
            $checkStmt = $conn->prepare("SELECT id FROM student_edit_permissions WHERE student_id = ? AND class = ? AND medium = ?");
            $checkStmt->bind_param("iss", $studentId, $class, $medium);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE student_edit_permissions SET can_edit = FALSE, locked_by = ? WHERE student_id = ? AND class = ? AND medium = ?");
                $stmt->bind_param("siss", $adminName, $studentId, $class, $medium);
            } else {
                $stmt = $conn->prepare("INSERT INTO student_edit_permissions (class, medium, student_id, can_edit, locked_by) VALUES (?, ?, ?, FALSE, ?)");
                $stmt->bind_param("ssis", $class, $medium, $studentId, $adminName);
            }
            
            if ($stmt->execute()) {
                $message = "Student has been LOCKED from editing.";
                $messageType = "success";
            } else {
                $message = "Failed to lock student.";
                $messageType = "error";
            }
        }
    }
    elseif ($action === 'unlock_individual') {
        // Unlock a specific student
        if ($studentId && $class && $medium) {
            $stmt = $conn->prepare("UPDATE student_edit_permissions SET can_edit = TRUE, locked_by = ? WHERE student_id = ? AND class = ? AND medium = ?");
            $stmt->bind_param("siss", $adminName, $studentId, $class, $medium);
            
            if ($stmt->execute()) {
                $message = "Student has been UNLOCKED for editing.";
                $messageType = "success";
            } else {
                $message = "Failed to unlock student.";
                $messageType = "error";
            }
        }
    }
}

// ==================== FETCH ALL STUDENTS ====================
$students = [];
$sql = "SELECT id, first_name, last_name, email, class, medium, photo, 
        (SELECT can_edit FROM student_edit_permissions sep2 
         WHERE (sep2.student_id = s.id OR (sep2.class = s.class AND sep2.medium = s.medium AND sep2.student_id IS NULL))
         ORDER BY sep2.student_id DESC LIMIT 1) as can_edit
        FROM (
            SELECT id, first_name, last_name, email, class, 'English' as medium, photo FROM student_english
            UNION ALL
            SELECT id, first_name, last_name, email, class, 'Hindi' as medium, photo FROM student_hindi
        ) s
        ORDER BY class ASC, medium ASC, first_name ASC";

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Group students by class and medium
$groupedStudents = [];
foreach ($students as $student) {
    $key = $student['class'] . '_' . $student['medium'];
    if (!isset($groupedStudents[$key])) {
        $groupedStudents[$key] = [
            'class' => $student['class'],
            'medium' => $student['medium'],
            'students' => [],
            'overall_status' => null
        ];
    }
    $groupedStudents[$key]['students'][] = $student;
}

// Get overall lock status for each class-medium group
foreach ($groupedStudents as $key => &$group) {
    $stmt = $conn->prepare("SELECT can_edit FROM student_edit_permissions WHERE class = ? AND medium = ? AND student_id IS NULL LIMIT 1");
    $stmt->bind_param("ss", $group['class'], $group['medium']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $group['overall_status'] = $row['can_edit'] ? 'unlocked' : 'locked';
    } else {
        $group['overall_status'] = 'locked';
    }
}

$adminName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Administrator';
$adminPhoto = $_SESSION['admin_photo'] ?? '';

require_once 'admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Manage Student Profile Editing | Ruchi Classes Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php echo admin_render_sidebar_styles(); ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: #f0f2f5;
            overflow-x: hidden;
        }

        /* Main Content Styles Override */
        .main-content {
            background: #f0f2f5;
            padding: 25px 35px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid rgba(39, 174, 96, 0.1);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(39, 174, 96, 0.12);
        }

        .stat-info h3 {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 800;
            color: #2c3e50;
        }

        .stat-icon {
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, #d5f4e6, #b7e4ce);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #27ae60;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 24px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.4s ease;
        }

        .alert-success {
            background: #d5f4e6;
            color: #1e7e34;
            border-left: 5px solid #27ae60;
        }

        .alert-error {
            background: #fde2e2;
            color: #c0392b;
            border-left: 5px solid #e74c3c;
        }

        .alert i {
            font-size: 20px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Section Headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-header h2 i {
            color: #27ae60;
            font-size: 26px;
        }

        /* Class Group Cards */
        .class-group {
            background: white;
            border-radius: 28px;
            margin-bottom: 35px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
            border: 1px solid #eef2f6;
            transition: all 0.3s ease;
        }

        .class-group:hover {
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
        }

        .class-header {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            padding: 22px 28px;
            border-bottom: 2px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .class-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .class-badge {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #27ae60, #229954);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 800;
        }

        .class-title h3 {
            font-size: 22px;
            font-weight: 700;
            color: #2c3e50;
        }

        .class-title p {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 4px;
        }

        .class-actions {
            display: flex;
            gap: 15px;
        }

        .lock-all-btn, .unlock-all-btn {
            padding: 12px 28px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            border: none;
        }

        .lock-all-btn {
            background: #fee2e2;
            color: #c0392b;
        }

        .lock-all-btn:hover {
            background: #fcc5c5;
            transform: translateY(-2px);
        }

        .unlock-all-btn {
            background: #d5f4e6;
            color: #1e7e34;
        }

        .unlock-all-btn:hover {
            background: #b7e4ce;
            transform: translateY(-2px);
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-locked {
            background: #fee2e2;
            color: #c0392b;
        }

        .status-unlocked {
            background: #d5f4e6;
            color: #1e7e34;
        }

        /* Students Table */
        .students-table-wrapper {
            overflow-x: auto;
            padding: 0 20px 20px 20px;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            text-align: left;
            padding: 18px 16px;
            background: #f8fafc;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #eef2f6;
            font-size: 14px;
        }

        .students-table td {
            padding: 16px;
            border-bottom: 1px solid #eef2f6;
            color: #475569;
            vertical-align: middle;
        }

        .students-table tr:hover {
            background: #fafcff;
        }

        .student-avatar {
            width: 45px;
            height: 45px;
            border-radius: 14px;
            object-fit: cover;
            border: 2px solid #27ae60;
        }

        .student-name {
            font-weight: 600;
            color: #1e293b;
        }

        .student-email {
            font-size: 13px;
            color: #7f8c8d;
        }

        .action-btns {
            display: flex;
            gap: 12px;
        }

        .btn-lock, .btn-unlock {
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-lock {
            background: #fee2e2;
            color: #c0392b;
        }

        .btn-lock:hover {
            background: #fcc5c5;
            transform: translateY(-2px);
        }

        .btn-unlock {
            background: #d5f4e6;
            color: #1e7e34;
        }

        .btn-unlock:hover {
            background: #b7e4ce;
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 24px;
        }

        .empty-state i {
            font-size: 70px;
            color: #d5f4e6;
            margin-bottom: 20px;
        }

        .empty-state p {
            color: #7f8c8d;
            font-size: 16px;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content {
                padding: 20px;
            }
            
            .class-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .class-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .action-btns {
                flex-direction: column;
            }
            
            .students-table th, .students-table td {
                padding: 12px;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .section-header h2 {
                font-size: 20px;
            }
            
            .students-table-wrapper {
                padding: 0 15px 15px 15px;
            }
        }
        
        /* Loading Animation */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #d5f4e6;
            border-top-color: #27ae60;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <div class="dashboard-container">
        <?php 
        $adminInfo = [
            'first_name' => $_SESSION['admin_name'] ?? 'Administrator',
            'email' => $_SESSION['admin_email'] ?? 'admin@ruchiclasses.com',
            'type' => $_SESSION['admin_type'] ?? 'admin',
            'photo' => $adminPhoto
        ];
        echo admin_render_sidebar($adminInfo, $conn, 'admin_manage_student_edit.php');
        ?>
        
        <main class="main-content" id="mainContent">
            <?php echo admin_render_page_header(
                'Manage Student Profile Editing',
                'Lock or unlock student profile editing permissions',
                'fas fa-lock',
                $adminInfo
            ); ?>
            
            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <?php 
                $totalStudents = count($students);
                $lockedCount = 0;
                $unlockedCount = 0;
                foreach ($students as $student) {
                    if ($student['can_edit']) {
                        $unlockedCount++;
                    } else {
                        $lockedCount++;
                    }
                }
                ?>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Students</h3>
                        <div class="stat-number"><?php echo $totalStudents; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Unlocked Profiles</h3>
                        <div class="stat-number" style="color: #1e7e34;"><?php echo $unlockedCount; ?></div>
                    </div>
                    <div class="stat-icon" style="background: #d5f4e6; color: #27ae60;">
                        <i class="fas fa-lock-open"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Locked Profiles</h3>
                        <div class="stat-number" style="color: #c0392b;"><?php echo $lockedCount; ?></div>
                    </div>
                    <div class="stat-icon" style="background: #fee2e2; color: #e74c3c;">
                        <i class="fas fa-lock"></i>
                    </div>
                </div>
            </div>
            
            <!-- Students List Grouped by Class -->
            <div class="section-header">
                <h2><i class="fas fa-graduation-cap"></i> Students by Class</h2>
            </div>
            
            <?php if (empty($groupedStudents)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <p>No students found. Please add students first.</p>
                </div>
            <?php else: ?>
                <?php foreach ($groupedStudents as $group): ?>
                    <div class="class-group">
                        <div class="class-header">
                            <div class="class-title">
                                <div class="class-badge">
                                    <?php echo htmlspecialchars($group['class']); ?>
                                </div>
                                <div>
                                    <h3>Class <?php echo htmlspecialchars($group['class']); ?> - <?php echo htmlspecialchars($group['medium']); ?> Medium</h3>
                                    <p><?php echo count($group['students']); ?> students • 
                                        <span class="status-badge <?php echo $group['overall_status'] === 'locked' ? 'status-locked' : 'status-unlocked'; ?>">
                                            <i class="fas <?php echo $group['overall_status'] === 'locked' ? 'fa-lock' : 'fa-lock-open'; ?>"></i>
                                            <?php echo $group['overall_status'] === 'locked' ? 'All Locked' : 'All Unlocked'; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="class-actions">
                                <form method="POST" style="display: inline;" onsubmit="return confirmAction('lock', 'Class <?php echo htmlspecialchars($group['class']); ?> - <?php echo htmlspecialchars($group['medium']); ?> Medium')">
                                    <input type="hidden" name="action" value="lock_all">
                                    <input type="hidden" name="class" value="<?php echo htmlspecialchars($group['class']); ?>">
                                    <input type="hidden" name="medium" value="<?php echo htmlspecialchars($group['medium']); ?>">
                                    <button type="submit" class="lock-all-btn">
                                        <i class="fas fa-lock"></i> Lock All
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirmAction('unlock', 'Class <?php echo htmlspecialchars($group['class']); ?> - <?php echo htmlspecialchars($group['medium']); ?> Medium')">
                                    <input type="hidden" name="action" value="unlock_all">
                                    <input type="hidden" name="class" value="<?php echo htmlspecialchars($group['class']); ?>">
                                    <input type="hidden" name="medium" value="<?php echo htmlspecialchars($group['medium']); ?>">
                                    <button type="submit" class="unlock-all-btn">
                                        <i class="fas fa-lock-open"></i> Unlock All
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="students-table-wrapper">
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th>Photo</th>
                                        <th>Student Name</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['students'] as $student): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $photoPath = !empty($student['photo']) ? '../' . $student['photo'] : '';
                                                ?>
                                                <img src="<?php echo htmlspecialchars($photoPath); ?>" 
                                                     class="student-avatar"
                                                     onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($student['first_name'] . ' ' . $student['last_name']); ?>&background=27ae60&color=fff'"
                                                     alt="Student">
                                            </td>
                                            <td>
                                                <div class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                            </td>
                                            <td>
                                                <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $student['can_edit'] ? 'status-unlocked' : 'status-locked'; ?>">
                                                    <i class="fas <?php echo $student['can_edit'] ? 'fa-lock-open' : 'fa-lock'; ?>"></i>
                                                    <?php echo $student['can_edit'] ? 'Unlocked' : 'Locked'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <?php if ($student['can_edit']): ?>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirmAction('lock', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
                                                            <input type="hidden" name="action" value="lock_individual">
                                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                            <input type="hidden" name="class" value="<?php echo htmlspecialchars($group['class']); ?>">
                                                            <input type="hidden" name="medium" value="<?php echo htmlspecialchars($group['medium']); ?>">
                                                            <button type="submit" class="btn-lock">
                                                                <i class="fas fa-lock"></i> Lock
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirmAction('unlock', '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')">
                                                            <input type="hidden" name="action" value="unlock_individual">
                                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                            <input type="hidden" name="class" value="<?php echo htmlspecialchars($group['class']); ?>">
                                                            <input type="hidden" name="medium" value="<?php echo htmlspecialchars($group['medium']); ?>">
                                                            <button type="submit" class="btn-unlock">
                                                                <i class="fas fa-lock-open"></i> Unlock
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        function confirmAction(action, name) {
            const actionText = action === 'lock' ? 'LOCK' : 'UNLOCK';
            const actionVerb = action === 'lock' ? 'lock' : 'unlock';
            const icon = action === 'lock' ? '🔒' : '🔓';
            
            Swal.fire({
                title: `${icon} ${actionText} Profile Editing?`,
                html: `Are you sure you want to <strong>${actionVerb}</strong> profile editing for:<br><span style="color: #27ae60; font-weight: bold;">${name}</span>`,
                icon: action === 'lock' ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'lock' ? '#e74c3c' : '#27ae60',
                cancelButtonColor: '#7f8c8d',
                confirmButtonText: `Yes, ${actionVerb} it!`,
                cancelButtonText: 'Cancel',
                background: '#fff',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('loadingOverlay').classList.add('show');
                    setTimeout(() => {
                        const form = event.target.closest('form');
                        if (form) form.submit();
                    }, 100);
                    return true;
                }
                return false;
            });
            return false;
        }
        
        // Show loading on any form submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                document.getElementById('loadingOverlay').classList.add('show');
            });
        });
        
        // Hide loading on page load
        window.addEventListener('load', function() {
            document.getElementById('loadingOverlay').classList.remove('show');
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
    
    <?php echo admin_render_sidebar_script(); ?>
</body>
</html>