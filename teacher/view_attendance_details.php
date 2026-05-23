<?php
session_start();
require '../db.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header("Location: teacher_login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$class = $_GET['class'] ?? '';
$medium = $_GET['medium'] ?? '';
$subject = $_GET['subject'] ?? '';
$date = $_GET['date'] ?? '';

if (empty($class) || empty($medium) || empty($subject) || empty($date)) {
    header("Location: attendance_history.php");
    exit();
}

// Get teacher info
$teacher = [];
$stmt = $conn->prepare("SELECT first_name, last_name, email, mobile, subject, photo FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$teacher = $result->fetch_assoc() ?? [];

// Get attendance details
$attendance_details = [];
$stmt = $conn->prepare("
    SELECT a.*, 
           CASE 
               WHEN ? = 'English' THEN se.first_name 
               ELSE sh.first_name 
           END as first_name,
           CASE 
               WHEN ? = 'English' THEN se.last_name 
               ELSE sh.last_name 
           END as last_name,
           CASE 
               WHEN ? = 'English' THEN se.photo 
               ELSE sh.photo 
           END as photo
    FROM attendance a
    LEFT JOIN student_english se ON a.medium = 'English' AND a.student_id = se.id
    LEFT JOIN student_hindi sh ON a.medium = 'Hindi' AND a.student_id = sh.id
    WHERE a.teacher_id = ? 
    AND a.class = ? 
    AND a.medium = ? 
    AND a.subject = ? 
    AND a.date = ?
    ORDER BY first_name
");
$stmt->bind_param("sssiisss", $medium, $medium, $medium, $teacher_id, $class, $medium, $subject, $date);
$stmt->execute();
$result = $stmt->get_result();
$attendance_details = $result->fetch_all(MYSQLI_ASSOC);

// Get attendance statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'P' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'A' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'S' THEN 1 ELSE 0 END) as suspended,
        SUM(CASE WHEN status = 'R' THEN 1 ELSE 0 END) as remaining
    FROM attendance 
    WHERE teacher_id = ? 
    AND class = ? 
    AND medium = ? 
    AND subject = ? 
    AND date = ?
");
$stats->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
$stats->execute();
$statistics = $stats->get_result()->fetch_assoc();

// Check if attendance is locked
$is_locked = false;
$lockStmt = $conn->prepare("
    SELECT is_locked, submitted_at 
    FROM attendance_tasks 
    WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND task_date=?
");
$lockStmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
$lockStmt->execute();
$lockResult = $lockStmt->get_result();
$lockData = $lockResult->fetch_assoc();

if ($lockData && $lockData['is_locked'] == 1) {
    $is_locked = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Details - Ruchi Classes</title>
    <!-- ADD THESE TWO LINES FOR SweetAlert -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS styles for the details page - similar to your existing style */
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8fafc;
            overflow-x: hidden;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border-radius: 12px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .attendance-table {
            width: 100%;
            min-width: 560px;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .attendance-table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .attendance-table th {
            background: #2563eb;
            color: white;
            padding: 15px;
            text-align: left;
        }
        
        .attendance-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-present { background: #d1fae5; color: #065f46; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-remaining { background: #e0e7ff; color: #3730a3; }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            justify-content: center;
        }
        
        .btn {
            background: #2563eb;
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
        }
        
        .btn-warning {
            background: #f59e0b;
        }
        
        .btn-success {
            background: #10b981;
        }
        
        .lock-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 14px;
            }

            .header {
                padding: 16px;
                margin-bottom: 18px;
            }

            .header h1 {
                font-size: 1.5rem;
                margin-bottom: 8px;
            }

            .stats-cards {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .stat-card,
            .lock-message {
                padding: 16px;
            }

            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-check"></i> Attendance Details</h1>
            <p>
                Class <?php echo htmlspecialchars($class); ?> | 
                <?php echo htmlspecialchars($medium); ?> Medium | 
                <?php echo htmlspecialchars($subject); ?> | 
                <?php echo date('d M Y', strtotime($date)); ?>
            </p>
        </div>
        
        <?php if ($is_locked): ?>
        <div class="lock-message">
            <i class="fas fa-lock"></i> Attendance is locked and cannot be edited directly
        </div>
        <?php endif; ?>
        
        <div class="stats-cards">
            <div class="stat-card">
                <h3>Total Students</h3>
                <div style="font-size: 2rem; font-weight: bold; color: #2563eb;">
                    <?php echo $statistics['total'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Present</h3>
                <div style="font-size: 2rem; font-weight: bold; color: #10b981;">
                    <?php echo $statistics['present'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Absent</h3>
                <div style="font-size: 2rem; font-weight: bold; color: #ef4444;">
                    <?php echo $statistics['absent'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Other Status</h3>
                <div style="font-size: 2rem; font-weight: bold; color: #f59e0b;">
                    <?php echo ($statistics['suspended'] ?? 0) + ($statistics['remaining'] ?? 0); ?>
                </div>
            </div>
        </div>
        
        <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <h2 style="margin-bottom: 20px; color: #2563eb;">
                <i class="fas fa-users"></i> Student Attendance List
            </h2>
            
            <div class="attendance-table-wrapper">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Status</th>
                            <th>Marked At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_details as $index => $student): 
                            $status_class = '';
                            switch($student['status']) {
                                case 'P': $status_class = 'status-present'; break;
                                case 'A': $status_class = 'status-absent'; break;
                                case 'S': $status_class = 'status-suspended'; break;
                                case 'R': $status_class = 'status-remaining'; break;
                            }
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $student['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $student['submitted_at'] 
                                    ? date('d M Y, h:i A', strtotime($student['submitted_at'])) 
                                    : 'Not recorded'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="action-buttons">
                <a href="attendance_history.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to History
                </a>
                
                <?php if (!$is_locked): ?>
                <a href="teacher_attendance.php?class=<?php echo $class; ?>&medium=<?php echo $medium; ?>&subject=<?php echo $subject; ?>&date=<?php echo $date; ?>" 
                   class="btn btn-success">
                    <i class="fas fa-edit"></i> Edit Attendance
                </a>
                <?php else: ?>
                <button onclick="requestUpdate(this)" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Request Update
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- ADD SweetAlert JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    function requestUpdate(triggerButton) {
        Swal.fire({
            title: 'Request Attendance Update',
            html: `
                <div style="text-align: left; margin: 20px 0;">
                    <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <div><strong>Class:</strong> <?php echo $class; ?></div>
                        <div><strong>Medium:</strong> <?php echo $medium; ?></div>
                        <div><strong>Subject:</strong> <?php echo $subject; ?></div>
                        <div><strong>Date:</strong> <?php echo date('d M Y', strtotime($date)); ?></div>
                    </div>
                    <div>
                        <label for="reason" style="display: block; margin-bottom: 8px; font-weight: 600;">
                            <i class="fas fa-comment"></i> Reason for Update:
                        </label>
                        <textarea id="reason" style="width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; resize: vertical;" 
                                  placeholder="Please explain why you need to update this attendance..." rows="4"></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Submit Request',
            cancelButtonText: 'Cancel',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                const reason = document.getElementById('reason').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Please provide a reason for the update request');
                    return false;
                }
                
                return fetch('submit_update_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        class: '<?php echo $class; ?>',
                        medium: '<?php echo $medium; ?>',
                        subject: '<?php echo $subject; ?>',
                        date: '<?php echo $date; ?>',
                        reason: reason
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        return data;
                    } else {
                        if (data.can_edit_directly) {
                            Swal.fire({
                                icon: 'info',
                                title: 'Direct Edit Available',
                                text: data.message,
                                confirmButtonColor: '#2563eb',
                                showCancelButton: true,
                                confirmButtonText: 'Edit Now',
                                cancelButtonText: 'Cancel'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = 'teacher_attendance.php?class=<?php echo $class; ?>&medium=<?php echo $medium; ?>&subject=<?php echo $subject; ?>&date=<?php echo $date; ?>';
                                }
                            });
                            return false;
                        }
                        throw new Error(data.message || 'Failed to submit request');
                    }
                })
                .catch(error => {
                    Swal.showValidationMessage(`Request failed: ${error}`);
                });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Request Submitted!',
                    text: 'Your update request has been sent to the administrator for approval.',
                    timer: 3000,
                    showConfirmButton: false
                }).then(() => {
                    if (triggerButton instanceof HTMLElement) {
                        triggerButton.disabled = true;
                        triggerButton.classList.remove('btn-warning');
                        triggerButton.classList.add('btn-secondary');
                        triggerButton.innerHTML = '<i class="fas fa-clock"></i> Request Pending';
                    }
                });
            }
        });
    }
    </script>
</body>
</html>
