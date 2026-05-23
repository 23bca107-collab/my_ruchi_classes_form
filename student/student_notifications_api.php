<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

require __DIR__ . '/../db.php';
require_once __DIR__ . '/student_notifications_helper.php';

$email = $_SESSION['student_email'] ?? '';
if ($email === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Student email not found',
    ]);
    exit;
}

$student = null;

try {
    $sql = "SELECT * FROM student_english WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result && $result->num_rows > 0) {
            $student = $result->fetch_assoc();
            $student['medium'] = 'English';
        }
        $stmt->close();
    }

    if (!$student) {
        $sqlHindi = "SELECT * FROM student_hindi WHERE email = ? LIMIT 1";
        $stmtHindi = $conn->prepare($sqlHindi);
        if ($stmtHindi) {
            $stmtHindi->bind_param('s', $email);
            $stmtHindi->execute();
            $resultHindi = $stmtHindi->get_result();
            if ($resultHindi instanceof mysqli_result && $resultHindi->num_rows > 0) {
                $student = $resultHindi->fetch_assoc();
                $student['medium'] = 'Hindi';
            }
            $stmtHindi->close();
        }
    }
} catch (Throwable $e) {
    error_log('Student notifications API error: ' . $e->getMessage());
}

if (!$student) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Student not found',
    ]);
    exit;
}

$studentId = (int)($student['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $notificationData = student_get_notifications($conn, $student, 20);
        $keys = array_map(
            static fn(array $item): string => (string)($item['notification_key'] ?? ''),
            $notificationData['notifications'] ?? []
        );
        student_notifications_mark_read($conn, $studentId, $keys);

        $notificationData = student_get_notifications($conn, $student, 20);
        echo json_encode([
            'success' => true,
            'unread_count' => $notificationData['unread_count'] ?? 0,
            'notifications' => $notificationData['notifications'] ?? [],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action',
    ]);
    exit;
}

$notificationData = student_get_notifications($conn, $student, 20);
echo json_encode([
    'success' => true,
    'unread_count' => $notificationData['unread_count'] ?? 0,
    'notifications' => $notificationData['notifications'] ?? [],
    'generated_at' => $notificationData['generated_at'] ?? date('c'),
]);
