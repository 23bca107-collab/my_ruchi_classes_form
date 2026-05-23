<?php
session_start();
require '../db.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get raw POST data
    $input = file_get_contents('php://input');
    parse_str($input, $data);
    
    $class = trim($data['class'] ?? '');
    $medium = trim($data['medium'] ?? '');
    $subject = trim($data['subject'] ?? '');
    $date = trim($data['date'] ?? '');
    $reason = trim($data['reason'] ?? '');
    
    // Debug log
    error_log("Request received: class=$class, medium=$medium, subject=$subject, date=$date, reason=$reason");
    
    // Validate data
    if (empty($class) || empty($medium) || empty($subject) || empty($date) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }
    
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
    
    if (!$attendance_exists) {
        echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
        exit();
    }
    
    // Check if attendance is locked via attendance_tasks
    $lock_stmt = $conn->prepare("
        SELECT is_locked, status FROM attendance_tasks 
        WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND task_date=?
    ");
    $lock_stmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
    $lock_stmt->execute();
    $lock_result = $lock_stmt->get_result();
    $lock_data = $lock_result->fetch_assoc();
    
    if ($lock_data && $lock_data['is_locked'] == 0 && $lock_data['status'] != 'completed') {
        // If attendance is not locked and not completed, teacher can edit directly
        echo json_encode([
            'success' => false, 
            'message' => 'Attendance is not locked. You can edit directly without requesting.',
            'can_edit_directly' => true
        ]);
        exit();
    }
    
    // Check if request already exists
    $stmt = $conn->prepare("
        SELECT id, status FROM attendance_update_requests 
        WHERE teacher_id=? AND class=? AND medium=? AND subject=? AND date=? 
        AND status IN ('pending', 'approved')
    ");
    $stmt->bind_param("iisss", $teacher_id, $class, $medium, $subject, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $existing_request = $result->fetch_assoc();
        $status = $existing_request['status'];
        
        if ($status == 'pending') {
            echo json_encode(['success' => false, 'message' => 'An update request for this date is already pending approval']);
        } elseif ($status == 'approved') {
            echo json_encode(['success' => false, 'message' => 'Update request already approved. You can update attendance now.']);
        }
        exit();
    }
    
    // Create new update request
    $stmt = $conn->prepare("
        INSERT INTO attendance_update_requests 
        (teacher_id, class, medium, subject, date, reason, status, requested_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param("iissss", $teacher_id, $class, $medium, $subject, $date, $reason);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Update request submitted successfully! Waiting for admin approval.',
            'request_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit request. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>