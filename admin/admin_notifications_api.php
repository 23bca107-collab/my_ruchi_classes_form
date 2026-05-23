<?php

session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/admin_notifications_helper.php';

if (!function_exists('admin_notifications_api_response')) {
    function admin_notifications_api_response(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!isAdminAuthenticated()) {
    admin_notifications_api_response([
        'success' => false,
        'message' => 'Unauthorized',
    ], 401);
}

$admin = [
    'id' => (int)($_SESSION['admin_id'] ?? 0),
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'mark_all_read') {
            admin_notifications_mark_all_read($conn, $admin, 20);
            $notificationData = admin_get_notifications($conn, $admin, 12);

            admin_notifications_api_response([
                'success' => true,
                'unread_count' => $notificationData['unread_count'] ?? 0,
                'notifications' => $notificationData['notifications'] ?? [],
                'generated_at' => $notificationData['generated_at'] ?? date('c'),
            ]);
        }

        admin_notifications_api_response([
            'success' => false,
            'message' => 'Invalid action',
        ], 400);
    }

    $notificationData = admin_get_notifications($conn, $admin, 12);
    admin_notifications_api_response([
        'success' => true,
        'unread_count' => $notificationData['unread_count'] ?? 0,
        'notifications' => $notificationData['notifications'] ?? [],
        'generated_at' => $notificationData['generated_at'] ?? date('c'),
    ]);
} catch (Throwable $e) {
    error_log('Admin notifications API error: ' . $e->getMessage());

    admin_notifications_api_response([
        'success' => false,
        'message' => 'Unable to load notifications',
    ], 500);
}
