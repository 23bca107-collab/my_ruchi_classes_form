<?php

session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/teacher_auth.php';
require_once __DIR__ . '/teacher_notifications_helper.php';

if (!function_exists('teacher_notifications_api_response')) {
    function teacher_notifications_api_response(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!isTeacherAuthenticated()) {
    teacher_notifications_api_response([
        'success' => false,
        'message' => 'Unauthorized',
    ], 401);
}

$teacherId = (int)($_SESSION['teacher_id'] ?? 0);
$teacher = getTeacherInfo();
$teacher['id'] = $teacher['id'] ?? $teacherId;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'mark_all_read') {
            teacher_notifications_mark_all_read($conn, $teacher, 20);
            $notificationData = teacher_get_notifications($conn, $teacher, 12);

            teacher_notifications_api_response([
                'success' => true,
                'unread_count' => $notificationData['unread_count'] ?? 0,
                'notifications' => $notificationData['notifications'] ?? [],
                'generated_at' => $notificationData['generated_at'] ?? date('c'),
            ]);
        }

        teacher_notifications_api_response([
            'success' => false,
            'message' => 'Invalid action',
        ], 400);
    }

    $notificationData = teacher_get_notifications($conn, $teacher, 12);
    teacher_notifications_api_response([
        'success' => true,
        'unread_count' => $notificationData['unread_count'] ?? 0,
        'notifications' => $notificationData['notifications'] ?? [],
        'generated_at' => $notificationData['generated_at'] ?? date('c'),
    ]);
} catch (Throwable $e) {
    error_log('Teacher notifications API error: ' . $e->getMessage());

    teacher_notifications_api_response([
        'success' => false,
        'message' => 'Unable to load notifications',
    ], 500);
}
