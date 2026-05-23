<?php
session_start();
require __DIR__ . '/../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit();
}

// ==================== AJAX HANDLERS ====================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['ajax_action'] == 'toggle_student') {
            $student_id = intval($_POST['student_id']);
            $action = $_POST['action'];
            $class = $_POST['class'];
            $medium = $_POST['medium'];
            
            $can_edit = ($action == 'unlock') ? 1 : 0;
            
            // IMPORTANT FIX: Use both student_id AND medium to identify unique student
            $check = $conn->prepare("SELECT id FROM student_edit_permissions WHERE student_id = ? AND medium = ?");
            $check->bind_param("is", $student_id, $medium);
            $check->execute();
            $check_result = $check->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update existing record with both student_id and medium
                $stmt = $conn->prepare("UPDATE student_edit_permissions SET can_edit = ?, locked_by = 'admin' WHERE student_id = ? AND medium = ?");
                $stmt->bind_param("iis", $can_edit, $student_id, $medium);
            } else {
                // Insert new record with medium to differentiate
                $stmt = $conn->prepare("INSERT INTO student_edit_permissions (class, medium, student_id, can_edit, locked_by) VALUES (?, ?, ?, ?, 'admin')");
                $stmt->bind_param("ssii", $class, $medium, $student_id, $can_edit);
            }
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => ($medium == 'English' ? 'English' : 'Hindi') . ' medium student #' . $student_id . ' has been ' . ($action == 'unlock' ? 'unlocked' : 'locked') . ' successfully!'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error occurred']);
            }
            exit();
        }
        
        if ($_POST['ajax_action'] == 'toggle_class') {
            $class = $_POST['class'];
            $medium = $_POST['medium'];
            $action = $_POST['action'];
            
            $can_edit = ($action == 'unlock') ? 1 : 0;
            
            // Update class-level permission
            $stmt = $conn->prepare("UPDATE student_edit_permissions SET can_edit = ?, locked_by = 'admin' WHERE class = ? AND medium = ? AND student_id IS NULL");
            $stmt->bind_param("iss", $can_edit, $class, $medium);
            $stmt->execute();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Class ' . $class . ' (' . $medium . ') has been ' . ($action == 'unlock' ? 'unlocked' : 'locked') . ' successfully!'
            ]);
            exit();
        }
        
        if ($_POST['ajax_action'] == 'toggle_all_students') {
            $action = $_POST['action'];
            $can_edit = ($action == 'unlock') ? 1 : 0;
            
            // Update all existing student permissions
            $conn->query("UPDATE student_edit_permissions SET can_edit = $can_edit, locked_by = 'admin' WHERE student_id IS NOT NULL");
            
            echo json_encode([
                'success' => true, 
                'message' => 'All students have been ' . ($action == 'unlock' ? 'unlocked' : 'locked') . ' successfully!'
            ]);
            exit();
        }
        
        if ($_POST['ajax_action'] == 'toggle_all_classes') {
            $action = $_POST['action'];
            $can_edit = ($action == 'unlock') ? 1 : 0;
            
            // Update all class-level permissions
            $conn->query("UPDATE student_edit_permissions SET can_edit = $can_edit, locked_by = 'admin' WHERE student_id IS NULL");
            
            echo json_encode([
                'success' => true, 
                'message' => 'All classes have been ' . ($action == 'unlock' ? 'unlocked' : 'locked') . ' successfully!'
            ]);
            exit();
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// ==================== FETCH ALL STUDENTS ====================

// English medium students - ADD MEDIUM TO UNIQUE IDENTIFIER
$eng_sql = "SELECT id, CONCAT('ENG-', id) as unique_id, first_name, last_name, class, 'English' as medium,
           COALESCE((SELECT can_edit FROM student_edit_permissions WHERE student_id = se.id AND medium = 'English'), 
                    (SELECT can_edit FROM student_edit_permissions WHERE class = se.class AND medium = 'English' AND student_id IS NULL), 
                    FALSE) as can_edit
    FROM student_english se
    ORDER BY class, first_name";
$eng_result = $conn->query($eng_sql);

// Hindi medium students - ADD MEDIUM TO UNIQUE IDENTIFIER
$hin_sql = "SELECT id, CONCAT('HIN-', id) as unique_id, first_name, last_name, class, 'Hindi' as medium,
           COALESCE((SELECT can_edit FROM student_edit_permissions WHERE student_id = sh.id AND medium = 'Hindi'), 
                    (SELECT can_edit FROM student_edit_permissions WHERE class = sh.class AND medium = 'Hindi' AND student_id IS NULL), 
                    FALSE) as can_edit
    FROM student_hindi sh
    ORDER BY class, first_name";
$hin_result = $conn->query($hin_sql);

// Combine all students
$all_students = [];
if ($eng_result) {
    while ($row = $eng_result->fetch_assoc()) {
        $all_students[] = $row;
    }
}
if ($hin_result) {
    while ($row = $hin_result->fetch_assoc()) {
        $all_students[] = $row;
    }
}

// Sort by class and medium
usort($all_students, function($a, $b) {
    if ($a['class'] != $b['class']) {
        return $a['class'] - $b['class'];
    }
    return strcmp($a['medium'], $b['medium']);
});

// Get class-level permissions
$class_permissions = [];
$class_sql = "SELECT * FROM student_edit_permissions WHERE student_id IS NULL ORDER BY class, medium";
$class_result = $conn->query($class_sql);
if ($class_result) {
    while ($row = $class_result->fetch_assoc()) {
        $class_permissions[$row['class'] . '_' . $row['medium']] = $row['can_edit'];
    }
}

// Calculate statistics
$total_students = count($all_students);
$unlocked_count = 0;
$locked_count = 0;
foreach ($all_students as $student) {
    if ($student['can_edit']) {
        $unlocked_count++;
    } else {
        $locked_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Edit Permissions - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ==================== CSS VARIABLES ==================== */
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f72585;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --locked: #ef233c;
            --unlocked: #06d6a0;
            --english: #3b71ca;
            --hindi: #f48c06;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ==================== HEADER ==================== */
        .header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .header h1 {
            color: var(--dark);
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header h1 i {
            color: var(--primary);
        }

        .header p {
            color: #666;
            line-height: 1.6;
        }

        /* ==================== STATS CARDS ==================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 16px;
            padding: 20px;
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .stat-info h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .stat-info .stat-number {
            font-size: 32px;
            font-weight: 700;
        }

        /* ==================== BUTTONS ==================== */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }

        .btn-lock {
            background: #ef233c;
            color: white;
        }

        .btn-unlock {
            background: #06d6a0;
            color: white;
        }

        .btn-lock-all {
            background: #d90429;
            color: white;
        }

        .btn-unlock-all {
            background: #2dc653;
            color: white;
        }

        .btn-class-lock {
            background: #f48c06;
            color: white;
        }

        .btn-class-unlock {
            background: #3b71ca;
            color: white;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

        /* ==================== GLOBAL ACTIONS ==================== */
        .global-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .global-actions .btn {
            flex: 1;
            min-width: 200px;
            justify-content: center;
        }

        /* ==================== CLASS GRID ==================== */
        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(600px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .class-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .class-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .class-header h2 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .class-badges {
            display: flex;
            gap: 10px;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
        }

        /* ==================== MEDIUM SECTIONS ==================== */
        .medium-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            padding: 20px;
        }

        .medium-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            border: 2px solid #e9ecef;
        }

        .medium-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #dee2e6;
        }

        .medium-header h3 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .english-header i {
            color: var(--english);
        }

        .hindi-header i {
            color: var(--hindi);
        }

        .status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-locked {
            background: #ffccd5;
            color: #d90429;
        }

        .status-unlocked {
            background: #b7efcd;
            color: #2dc653;
        }

        .medium-actions {
            display: flex;
            gap: 10px;
        }

        /* ==================== STUDENTS TABLE ==================== */
        .students-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }

        .students-section h2 {
            color: var(--dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .medium-tag {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .medium-english {
            background: #cfe2ff;
            color: #0a58ca;
        }

        .medium-hindi {
            background: #fff3cd;
            color: #997404;
        }

        .unique-id {
            font-size: 11px;
            color: #6c757d;
            display: block;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        /* ==================== LOADING OVERLAY ==================== */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 20px;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 6px solid #e9ecef;
            border-top: 6px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ==================== RESPONSIVE ==================== */
        @media (max-width: 768px) {
            .class-grid {
                grid-template-columns: 1fr;
            }

            .medium-row {
                grid-template-columns: 1fr;
            }

            .global-actions {
                flex-direction: column;
            }

            .global-actions .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div style="color: var(--primary); font-weight: 600;">Processing...</div>
    </div>

    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <h1>
                <i class="fas fa-lock"></i>
                Student Profile Edit Permissions
            </h1>
            <p>Control which students can edit their profiles. Each student is uniquely identified by ID + Medium.</p>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Students</h3>
                        <div class="stat-number"><?php echo $total_students; ?></div>
                    </div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #06d6a0, #2dc653);">
                    <div class="stat-icon">
                        <i class="fas fa-lock-open"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Can Edit</h3>
                        <div class="stat-number"><?php echo $unlocked_count; ?></div>
                    </div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #ef233c, #d90429);">
                    <div class="stat-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Locked</h3>
                        <div class="stat-number"><?php echo $locked_count; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Global Actions -->
        <div class="global-actions">
            <button class="btn btn-lock-all" onclick="toggleAllStudents('lock')">
                <i class="fas fa-user-lock"></i> Lock All Students
            </button>
            <button class="btn btn-unlock-all" onclick="toggleAllStudents('unlock')">
                <i class="fas fa-user-check"></i> Unlock All Students
            </button>
            <button class="btn btn-lock-all" onclick="toggleAllClasses('lock')">
                <i class="fas fa-lock"></i> Lock All Classes
            </button>
            <button class="btn btn-unlock-all" onclick="toggleAllClasses('unlock')">
                <i class="fas fa-lock-open"></i> Unlock All Classes
            </button>
        </div>

        <!-- Class-wise Controls -->
        <div class="class-grid">
            <?php for ($class = 8; $class <= 12; $class++): ?>
                <div class="class-card">
                    <div class="class-header">
                        <h2>
                            <i class="fas fa-graduation-cap"></i>
                            Class <?php echo $class; ?>
                        </h2>
                        <div class="class-badges">
                            <span class="badge">English</span>
                            <span class="badge">Hindi</span>
                        </div>
                    </div>
                    
                    <div class="medium-row">
                        <!-- English Medium -->
                        <div class="medium-card">
                            <div class="medium-header">
                                <h3 class="english-header">
                                    <i class="fas fa-language"></i> English Medium
                                </h3>
                                <span class="status <?php echo isset($class_permissions[$class . '_English']) && $class_permissions[$class . '_English'] ? 'status-unlocked' : 'status-locked'; ?>" id="status-<?php echo $class; ?>-english">
                                    <?php echo isset($class_permissions[$class . '_English']) && $class_permissions[$class . '_English'] ? 'UNLOCKED' : 'LOCKED'; ?>
                                </span>
                            </div>
                            <div class="medium-actions">
                                <button class="btn btn-class-lock btn-sm" onclick="toggleClass('<?php echo $class; ?>', 'English', 'lock')" style="flex:1;">
                                    <i class="fas fa-lock"></i> Lock Class
                                </button>
                                <button class="btn btn-class-unlock btn-sm" onclick="toggleClass('<?php echo $class; ?>', 'English', 'unlock')" style="flex:1;">
                                    <i class="fas fa-lock-open"></i> Unlock Class
                                </button>
                            </div>
                        </div>
                        
                        <!-- Hindi Medium -->
                        <div class="medium-card">
                            <div class="medium-header">
                                <h3 class="hindi-header">
                                    <i class="fas fa-language"></i> Hindi Medium
                                </h3>
                                <span class="status <?php echo isset($class_permissions[$class . '_Hindi']) && $class_permissions[$class . '_Hindi'] ? 'status-unlocked' : 'status-locked'; ?>" id="status-<?php echo $class; ?>-hindi">
                                    <?php echo isset($class_permissions[$class . '_Hindi']) && $class_permissions[$class . '_Hindi'] ? 'UNLOCKED' : 'LOCKED'; ?>
                                </span>
                            </div>
                            <div class="medium-actions">
                                <button class="btn btn-class-lock btn-sm" onclick="toggleClass('<?php echo $class; ?>', 'Hindi', 'lock')" style="flex:1;">
                                    <i class="fas fa-lock"></i> Lock Class
                                </button>
                                <button class="btn btn-class-unlock btn-sm" onclick="toggleClass('<?php echo $class; ?>', 'Hindi', 'unlock')" style="flex:1;">
                                    <i class="fas fa-lock-open"></i> Unlock Class
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Individual Student Control -->
        <div class="students-section">
            <h2>
                <i class="fas fa-user-edit"></i>
                Individual Student Control
            </h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Medium</th>
                            <th>Current Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_students as $student): ?>
                            <tr id="student-row-<?php echo $student['id']; ?>-<?php echo $student['medium']; ?>">
                                <td>
                                    <strong>#<?php echo $student['id']; ?></strong>
                                    <span class="unique-id"><?php echo $student['medium']; ?> medium</span>
                                </td>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                <td>Class <?php echo $student['class']; ?></td>
                                <td>
                                    <span class="medium-tag medium-<?php echo strtolower($student['medium']); ?>">
                                        <?php echo $student['medium']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status <?php echo $student['can_edit'] ? 'status-unlocked' : 'status-locked'; ?>" id="student-status-<?php echo $student['id']; ?>-<?php echo $student['medium']; ?>">
                                        <?php echo $student['can_edit'] ? 'UNLOCKED' : 'LOCKED'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($student['can_edit']): ?>
                                            <button class="btn btn-lock btn-sm" onclick="toggleStudent(<?php echo $student['id']; ?>, 'lock', '<?php echo $student['class']; ?>', '<?php echo $student['medium']; ?>')">
                                                <i class="fas fa-lock"></i> Lock
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-unlock btn-sm" onclick="toggleStudent(<?php echo $student['id']; ?>, 'unlock', '<?php echo $student['class']; ?>', '<?php echo $student['medium']; ?>')">
                                                <i class="fas fa-lock-open"></i> Unlock
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Show/Hide Loading
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('show');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }

        // ==================== TOGGLE INDIVIDUAL STUDENT ====================
        function toggleStudent(studentId, action, classNum, medium) {
            Swal.fire({
                title: 'Are you sure?',
                text: `Do you want to ${action} ${medium} medium student #${studentId}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'lock' ? '#ef233c' : '#06d6a0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} it!`,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();

                    const formData = new FormData();
                    formData.append('ajax_action', 'toggle_student');
                    formData.append('student_id', studentId);
                    formData.append('action', action);
                    formData.append('class', classNum);
                    formData.append('medium', medium);

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message,
                                timer: 2000,
                                showConfirmButton: false
                            });

                            // Update UI with medium-specific ID
                            const statusSpan = document.getElementById(`student-status-${studentId}-${medium}`);
                            const actionCell = document.querySelector(`#student-row-${studentId}-${medium} td:last-child .action-buttons`);

                            if (action === 'unlock') {
                                statusSpan.className = 'status status-unlocked';
                                statusSpan.textContent = 'UNLOCKED';
                                actionCell.innerHTML = `
                                    <button class="btn btn-lock btn-sm" onclick="toggleStudent(${studentId}, 'lock', '${classNum}', '${medium}')">
                                        <i class="fas fa-lock"></i> Lock
                                    </button>
                                `;
                            } else {
                                statusSpan.className = 'status status-locked';
                                statusSpan.textContent = 'LOCKED';
                                actionCell.innerHTML = `
                                    <button class="btn btn-unlock btn-sm" onclick="toggleStudent(${studentId}, 'unlock', '${classNum}', '${medium}')">
                                        <i class="fas fa-lock-open"></i> Unlock
                                    </button>
                                `;
                            }
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Something went wrong. Please try again.'
                        });
                    });
                }
            });
        }

        // ==================== TOGGLE CLASS ====================
        function toggleClass(classNum, medium, action) {
            Swal.fire({
                title: 'Are you sure?',
                text: `Do you want to ${action} all students in Class ${classNum} (${medium} Medium)?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'lock' ? '#f48c06' : '#3b71ca',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} class!`,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();

                    const formData = new FormData();
                    formData.append('ajax_action', 'toggle_class');
                    formData.append('class', classNum);
                    formData.append('medium', medium);
                    formData.append('action', action);

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message,
                                timer: 2000,
                                showConfirmButton: false
                            });

                            // Update class status
                            const statusSpan = document.getElementById(`status-${classNum}-${medium.toLowerCase()}`);
                            if (action === 'unlock') {
                                statusSpan.className = 'status status-unlocked';
                                statusSpan.textContent = 'UNLOCKED';
                            } else {
                                statusSpan.className = 'status status-locked';
                                statusSpan.textContent = 'LOCKED';
                            }

                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Something went wrong. Please try again.'
                        });
                    });
                }
            });
        }

        // ==================== TOGGLE ALL STUDENTS ====================
        function toggleAllStudents(action) {
            Swal.fire({
                title: `Are you sure?`,
                text: `Do you want to ${action} ALL students? This will affect <?php echo $total_students; ?> students.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: action === 'lock' ? '#d90429' : '#2dc653',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} all!`,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();

                    const formData = new FormData();
                    formData.append('ajax_action', 'toggle_all_students');
                    formData.append('action', action);

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Something went wrong. Please try again.'
                        });
                    });
                }
            });
        }

        // ==================== TOGGLE ALL CLASSES ====================
        function toggleAllClasses(action) {
            Swal.fire({
                title: `Are you sure?`,
                text: `Do you want to ${action} ALL classes? This will affect all class-level permissions.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: action === 'lock' ? '#d90429' : '#2dc653',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} all!`,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();

                    const formData = new FormData();
                    formData.append('ajax_action', 'toggle_all_classes');
                    formData.append('action', action);

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Something went wrong. Please try again.'
                        });
                    });
                }
            });
        }
    </script>
</body>
</html>