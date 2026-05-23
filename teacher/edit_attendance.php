<?php
session_start();
require '../db.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header("Location: teacher_login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

// First, let's check if this is an edit request
$is_edit_mode = false;
$edit_data = [];

// Check if we're editing via approved update request
$update_request_id = $_GET['update_request'] ?? 0;
if ($update_request_id > 0) {
    $stmt = $conn->prepare("
        SELECT * FROM attendance_update_requests 
        WHERE id=? AND teacher_id=? AND status='approved'
    ");
    $stmt->bind_param("ii", $update_request_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $approved_request = $result->fetch_assoc();
    
    if ($approved_request) {
        $is_edit_mode = true;
        $class = $approved_request['class'];
        $medium = $approved_request['medium'];
        $subject = $approved_request['subject'];
        $date = $approved_request['date'];
    }
} else {
    // Regular edit via parameters
    $class = $_GET['class'] ?? '';
    $medium = $_GET['medium'] ?? '';
    $subject = $_GET['subject'] ?? '';
    $date = $_GET['date'] ?? date('Y-m-d');
}

// Get teacher info for sidebar
$teacher = [];
$stmt = $conn->prepare("SELECT first_name, last_name, email, mobile, subject, photo FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$teacher = $result->fetch_assoc() ?? [];

// Get messages count
$messages_count = 0;
$table_check = $conn->query("SHOW TABLES LIKE 'messages'");
if ($table_check->num_rows > 0) {
    $messages_stmt = $conn->prepare("SELECT COUNT(*) as msg_count FROM messages WHERE receiver_id=? AND receiver_type='teacher' AND status='unread'");
    $messages_stmt->bind_param("i", $teacher_id);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    $messages_count = $messages_result->fetch_assoc()['msg_count'];
}

// Check if attendance exists and get current data
$attendance_exists = false;
$current_attendance = [];

if ($class && $medium && $subject && $date) {
    // Check if attendance exists
    $check_stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM attendance 
        WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=?
    ");
    $check_stmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $attendance_data = $check_result->fetch_assoc();
    $attendance_exists = $attendance_data['count'] > 0;
    
    // Get current attendance data
    if ($attendance_exists) {
        $attendance_stmt = $conn->prepare("
            SELECT student_id, status 
            FROM attendance 
            WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=?
        ");
        $attendance_stmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
        $attendance_stmt->execute();
        $attendance_result = $attendance_stmt->get_result();
        while ($row = $attendance_result->fetch_assoc()) {
            $current_attendance[$row['student_id']] = $row['status'];
        }
    }
}

// Fetch students for this class
$students = [];
if ($class && $medium) {
    $table = ($medium == 'English') ? 'student_english' : 'student_hindi';
    $stmt = $conn->prepare("SELECT id, first_name, last_name, photo FROM $table WHERE class=? ORDER BY first_name");
    $stmt->bind_param("s", $class);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Handle form submission for editing attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_attendance'])) {
    // Verify we have all required parameters
    $post_class = $_POST['class'];
    $post_medium = $_POST['medium'];
    $post_subject = $_POST['subject'];
    $post_date = $_POST['date'];
    $update_request_id = $_POST['update_request_id'] ?? 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, delete existing attendance for this class/date/subject
        $delete_stmt = $conn->prepare("
            DELETE FROM attendance 
            WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=?
        ");
        $delete_stmt->bind_param("iisss", $teacher_id, $post_class, $post_medium, $post_subject, $post_date);
        $delete_stmt->execute();
        
        // Now insert updated attendance records
        foreach ($_POST['attendance'] as $student_id => $status) {
            $insert_stmt = $conn->prepare("
                INSERT INTO attendance 
                (student_id, class, medium, subject, date, status, teacher_id, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->bind_param("isssssi", $student_id, $post_class, $post_medium, $post_subject, $post_date, $status, $teacher_id);
            $insert_stmt->execute();
        }
        
        // If this was from an approved update request, mark it as completed
        if ($update_request_id > 0) {
            $update_req_stmt = $conn->prepare("
                UPDATE attendance_update_requests 
                SET status='completed', reviewed_at=NOW() 
                WHERE id=?
            ");
            $update_req_stmt->bind_param("i", $update_request_id);
            $update_req_stmt->execute();
        }
        
        // Update attendance task to lock it again
        $update_task_stmt = $conn->prepare("
            UPDATE attendance_tasks 
            SET is_locked=1, completed_at=NOW() 
            WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND task_date=?
        ");
        $update_task_stmt->bind_param("iisss", $teacher_id, $post_class, $post_medium, $post_subject, $post_date);
        $update_task_stmt->execute();
        
        // If no task exists, create one
        if ($update_task_stmt->affected_rows == 0) {
            $create_task_stmt = $conn->prepare("
                INSERT INTO attendance_tasks 
                (teacher_id, class, medium, subject, task_date, status, is_locked, submitted_at, completed_at, assigned_at)
                VALUES (?, ?, ?, ?, ?, 'completed', 1, NOW(), NOW(), NOW())
            ");
            $create_task_stmt->bind_param("iisss", $teacher_id, $post_class, $post_medium, $post_subject, $post_date);
            $create_task_stmt->execute();
        }
        
        $conn->commit();
        
        $_SESSION['success_message'] = 'Attendance updated successfully!';
        header("Location: attendance_history.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = 'Failed to update attendance: ' . $e->getMessage();
        header("Location: edit_attendance.php?class=$class&medium=$medium&subject=$subject&date=$date");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Attendance - Ruchi Classes</title>
    <!-- SweetAlert CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --secondary: #f8fafc;
            --secondary-light: #f1f5f9;
            --accent: #f59e0b;
            --accent-light: #fbbf24;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;

            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;

            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-card: #ffffff;
            --bg-hover: #f1f5f9;

            --border: #e2e8f0;
            --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);

            --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent) 0%, #ea580c 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 2rem;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .page-title {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .page-title h1 {
            color: var(--text-primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .page-title p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .info-banner {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
        }
        
        .warning-banner {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);
        }
        
        .date-info {
            text-align: center;
            margin-bottom: 25px;
            padding: 15px;
            background: var(--gradient-primary);
            color: white;
            border-radius: 12px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }
        
        .today-badge {
            background: var(--accent);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            margin-left: 10px;
            font-weight: 600;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 25px 0;
            font-size: 16px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .attendance-table th {
            background: var(--gradient-primary);
            color: white;
            font-weight: 600;
            padding: 20px 15px;
            text-align: left;
            position: sticky;
            top: 0;
        }
        
        .attendance-table td {
            padding: 20px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            background: white;
        }
        
        .attendance-table tr:hover td {
            background: rgba(37, 99, 235, 0.05);
        }
        
        .student-photo {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: var(--shadow);
        }
        
        .attendance-select {
            padding: 10px 15px;
            border-radius: 8px;
            border: 2px solid var(--border);
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            min-width: 150px;
            font-weight: 500;
        }
        
        .attendance-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            min-width: 90px;
            margin-top: 8px;
        }
        
        .status-present { background: #d1fae5; color: #065f46; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-remaining { background: #e0e7ff; color: #3730a3; }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 28px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .btn-success:hover {
            background: #0da271;
        }
        
        .btn-secondary {
            background: var(--text-muted);
        }
        
        .btn-secondary:hover {
            background: #475569;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
            justify-content: center;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            color: var(--text-secondary);
            font-size: 14px;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .page-title h1 {
                font-size: 28px;
            }
            
            .attendance-table {
                display: block;
                overflow-x: auto;
            }
            
            .attendance-table th,
            .attendance-table td {
                padding: 15px 10px;
                font-size: 14px;
            }
            
            .student-photo {
                width: 50px;
                height: 50px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="attendance_history.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to History
            </a>
            <h2 style="color: var(--primary);">Edit Attendance</h2>
            <div style="display: flex; align-items: center; gap: 15px;">
                <?php if (!empty($teacher['photo'])): ?>
                    <img src="<?php echo htmlspecialchars($teacher['photo']); ?>" alt="Profile" style="width: 48px; height: 48px; border-radius: 50%; border: 3px solid var(--primary);">
                <?php endif; ?>
                <span style="font-weight: 600;"><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></span>
            </div>
        </div>

        <div class="page-title">
            <h1><i class="fas fa-edit"></i> Edit Attendance</h1>
            <p>Update attendance records for your class</p>
        </div>
        
        <?php if ($is_edit_mode): ?>
        <div class="info-banner">
            <i class="fas fa-check-circle"></i> 
            ADMIN APPROVED: You can now update attendance for <?= date('F j, Y', strtotime($date)) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($class && $medium && $subject && $date && !empty($students)): ?>
        <div class="card">
            <div class="date-info">
                <i class="fas fa-calendar-alt"></i> 
                Editing attendance for: <strong><?= date('F j, Y', strtotime($date)) ?></strong>
                <?php if ($date == date('Y-m-d')): ?>
                    <span class="today-badge">Today</span>
                <?php endif; ?>
            </div>
            
            <h2 style="margin-bottom: 20px; color: var(--primary);">
                <i class="fas fa-users"></i> Class <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($medium) ?>)
            </h2>
            <p style="margin-bottom: 25px; color: var(--text-secondary);">
                <i class="fas fa-book"></i> Subject: <strong><?= htmlspecialchars($subject) ?></strong>
            </p>
            
            <form method="post" id="attendanceForm">
                <input type="hidden" name="class" value="<?= htmlspecialchars($class) ?>">
                <input type="hidden" name="medium" value="<?= htmlspecialchars($medium) ?>">
                <input type="hidden" name="subject" value="<?= htmlspecialchars($subject) ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
                <?php if ($update_request_id > 0): ?>
                <input type="hidden" name="update_request_id" value="<?= $update_request_id ?>">
                <?php endif; ?>
                
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Photo</th>
                            <th>Student Name</th>
                            <th>Attendance Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $index => $student): 
                            $prev_status = $current_attendance[$student['id']] ?? 'P';
                            $status_class = '';
                            switch($prev_status) {
                                case 'P': $status_class = 'status-present'; break;
                                case 'A': $status_class = 'status-absent'; break;
                                case 'S': $status_class = 'status-suspended'; break;
                                case 'R': $status_class = 'status-remaining'; break;
                            }
                        ?>
                        <tr>
                            <td style="font-weight: 600;"><?= $index + 1 ?></td>
                            <td>
                                <?php
                                $photoFile = $student['photo']; 
                                $serverPath = $_SERVER['DOCUMENT_ROOT'] . "/ruchi_classes_form/student/uploads/" . basename($photoFile);
                                $webPath = "/ruchi_classes_form/student/uploads/" . basename($photoFile);
                                if (!empty($photoFile) && file_exists($serverPath)) {
                                    echo '<img src="'.$webPath.'" alt="'.htmlspecialchars($student['first_name']).'" class="student-photo">';
                                } else {
                                    $avatarName = urlencode($student['first_name'].' '.$student['last_name']);
                                    echo '<img src="https://ui-avatars.com/api/?name='.$avatarName.'&size=65" class="student-photo">';
                                }
                                ?>
                            </td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($student['first_name'].' '.$student['last_name']) ?></td>
                            <td>
                                <select name="attendance[<?= $student['id'] ?>]" class="attendance-select" required>
                                    <option value="P" <?= ($prev_status == 'P') ? 'selected' : '' ?>>Present</option>
                                    <option value="A" <?= ($prev_status == 'A') ? 'selected' : '' ?>>Absent</option>
                                    <option value="S" <?= ($prev_status == 'S') ? 'selected' : '' ?>>Suspended</option>
                                    <option value="R" <?= ($prev_status == 'R') ? 'selected' : '' ?>>Remaining</option>
                                </select>
                                <div class="status-badge <?= $status_class ?>">
                                    <?= $prev_status ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="action-buttons">
                    <button type="submit" name="update_attendance" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="attendance_history.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="card">
            <div style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
                <i class="fas fa-exclamation-circle" style="font-size: 64px; margin-bottom: 20px; color: var(--danger);"></i>
                <h3 style="margin-bottom: 10px;">Invalid Request</h3>
                <p>No attendance data found or missing parameters.</p>
                <p style="margin-top: 10px; font-size: 14px;">
                    Please select attendance from history page to edit.
                </p>
                <a href="attendance_history.php" class="btn" style="margin-top: 20px;">
                    <i class="fas fa-history"></i> View Attendance History
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>&copy; <?= date('Y') ?> Ruchi Classes. All rights reserved.</p>
            <p style="margin-top: 5px; font-size: 12px; opacity: 0.7;">
                <i class="fas fa-shield-alt"></i> Secure Attendance System
            </p>
        </div>
    </div>

    <!-- SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const attendanceForm = document.getElementById('attendanceForm');
            
            if (attendanceForm) {
                attendanceForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    Swal.fire({
                        title: 'Confirm Update',
                        text: 'Are you sure you want to update attendance for <?= date('F j, Y', strtotime($date)) ?>? After updating, attendance will be locked again.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#10b981',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Yes, update it!',
                        cancelButtonText: 'Cancel',
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Show loading state
                            const submitBtn = this.querySelector('button[name="update_attendance"]');
                            const originalText = submitBtn.innerHTML;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                            submitBtn.disabled = true;
                            
                            this.submit();
                        }
                    });
                });
            }
            
            // Update status badge when select changes
            document.querySelectorAll('.attendance-select').forEach(select => {
                select.addEventListener('change', function() {
                    const badge = this.nextElementSibling;
                    const value = this.value;
                    
                    badge.className = 'status-badge';
                    badge.textContent = value;
                    
                    switch(value) {
                        case 'P': badge.classList.add('status-present'); break;
                        case 'A': badge.classList.add('status-absent'); break;
                        case 'S': badge.classList.add('status-suspended'); break;
                        case 'R': badge.classList.add('status-remaining'); break;
                    }
                });
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + S to save
                if (e.ctrlKey && e.key === 's' && attendanceForm && !e.target.matches('select, input, textarea')) {
                    e.preventDefault();
                    attendanceForm.querySelector('button[name="update_attendance"]').click();
                }
            });
        });
    </script>
</body>
</html>