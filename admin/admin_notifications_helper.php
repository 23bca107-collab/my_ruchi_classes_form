<?php

function admin_notifications_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    $cache[$table] = $result instanceof mysqli_result && $result->num_rows > 0;

    return $cache[$table];
}

function admin_notifications_table_columns(mysqli $conn, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    if (!admin_notifications_table_exists($conn, $table)) {
        $cache[$table] = $columns;
        return $columns;
    }

    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function admin_notifications_ensure_reads_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS admin_notification_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            notification_key VARCHAR(191) NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_admin_notification (admin_id, notification_key),
            INDEX idx_admin_read (admin_id, read_at)
        )
    ");
}

function admin_notifications_read_map(mysqli $conn, int $adminId): array
{
    admin_notifications_ensure_reads_table($conn);

    $readMap = [];
    if ($adminId <= 0) {
        return $readMap;
    }

    $stmt = $conn->prepare("SELECT notification_key FROM admin_notification_reads WHERE admin_id = ?");
    if (!$stmt) {
        return $readMap;
    }

    $stmt->bind_param('i', $adminId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $readMap[$row['notification_key']] = true;
        }
    }

    $stmt->close();
    return $readMap;
}

function admin_notifications_mark_read(mysqli $conn, int $adminId, array $keys): void
{
    if ($adminId <= 0 || $keys === []) {
        return;
    }

    admin_notifications_ensure_reads_table($conn);
    $stmt = $conn->prepare("
        INSERT IGNORE INTO admin_notification_reads (admin_id, notification_key)
        VALUES (?, ?)
    ");

    if (!$stmt) {
        return;
    }

    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '') {
            continue;
        }

        $stmt->bind_param('is', $adminId, $key);
        $stmt->execute();
    }

    $stmt->close();
}

function admin_notifications_relative_time(?int $timestamp): string
{
    if (empty($timestamp) || $timestamp <= 0) {
        return 'Just now';
    }

    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' hr ago';
    }
    if ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('d M Y', $timestamp);
}

function admin_notifications_trim_text(string $value, int $limit = 90): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '...' : $value;
    }

    return strlen($value) > $limit ? substr($value, 0, $limit - 1) . '...' : $value;
}

function admin_notifications_add(array &$items, array $notification): void
{
    if (empty($notification['notification_key'])) {
        return;
    }

    $timestamp = (int)($notification['sort_timestamp'] ?? 0);
    $notification['sort_timestamp'] = $timestamp;
    $notification['time_label'] = admin_notifications_relative_time($timestamp);
    $notification['title'] = trim((string)($notification['title'] ?? 'Notification'));
    $notification['message'] = trim((string)($notification['message'] ?? ''));
    $notification['icon'] = $notification['icon'] ?? 'fa-bell';
    $notification['icon_theme'] = $notification['icon_theme'] ?? 'info';
    $notification['link'] = $notification['link'] ?? 'admin_dashboard.php';

    $items[] = $notification;
}

function admin_notifications_resolve_person_name(mysqli $conn, string $userType, int $userId): string
{
    if ($userId <= 0) {
        return ucfirst($userType);
    }

    if ($userType === 'teacher' && admin_notifications_table_exists($conn, 'teachers')) {
        $stmt = $conn->prepare("SELECT CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) AS full_name FROM teachers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                $fullName = trim((string)($row['full_name'] ?? ''));
                $stmt->close();
                if ($fullName !== '') {
                    return $fullName;
                }
            } else {
                $stmt->close();
            }
        }

        return 'Teacher #' . $userId;
    }

    if ($userType === 'student') {
        foreach (['student_english', 'student_hindi'] as $table) {
            if (!admin_notifications_table_exists($conn, $table)) {
                continue;
            }

            $stmt = $conn->prepare("SELECT CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) AS full_name FROM {$table} WHERE id = ? LIMIT 1");
            if (!$stmt) {
                continue;
            }

            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                $fullName = trim((string)($row['full_name'] ?? ''));
                $stmt->close();
                if ($fullName !== '') {
                    return $fullName;
                }
            } else {
                $stmt->close();
            }
        }

        return 'Student #' . $userId;
    }

    return ucfirst($userType) . ' #' . $userId;
}

function admin_notifications_fetch_complaints(mysqli $conn, array &$items): void
{
    if (!admin_notifications_table_exists($conn, 'complaints')) {
        return;
    }

    $columns = admin_notifications_table_columns($conn, 'complaints');
    if (!isset($columns['id'], $columns['user_type'], $columns['user_id'], $columns['complaint'])) {
        return;
    }

    $createdColumn = isset($columns['created_at']) ? 'created_at' : 'NOW()';
    $sql = "
        SELECT id, user_type, user_id, complaint, " . (isset($columns['status']) ? 'status,' : "'pending' AS status,") . " {$createdColumn} AS event_time
        FROM complaints
        WHERE LOWER(COALESCE(user_type, '')) IN ('student', 'teacher')
    ";

    if (isset($columns['status'])) {
        $sql .= " AND LOWER(COALESCE(status, 'pending')) = 'pending'";
    }

    if ($createdColumn !== 'NOW()') {
        $sql .= " AND {$createdColumn} >= DATE_SUB(NOW(), INTERVAL 120 DAY)";
    }

    $sql .= " ORDER BY {$createdColumn} DESC LIMIT 8";

    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $userType = strtolower(trim((string)($row['user_type'] ?? '')));
        $userId = (int)($row['user_id'] ?? 0);
        $fullName = admin_notifications_resolve_person_name($conn, $userType, $userId);
        $eventTime = strtotime((string)($row['event_time'] ?? 'now')) ?: time();
        $complaintText = admin_notifications_trim_text((string)($row['complaint'] ?? ''));

        admin_notifications_add($items, [
            'notification_key' => 'complaint:' . ($row['id'] ?? md5(json_encode($row))),
            'title' => ucfirst($userType) . ' complaint received',
            'message' => $fullName . ($complaintText !== '' ? ' submitted: ' . $complaintText : ' submitted a new complaint.'),
            'sort_timestamp' => $eventTime,
            'icon' => 'fa-comment-dots',
            'icon_theme' => $userType === 'teacher' ? 'warning' : 'info',
            'link' => 'admin_complaints.php',
        ]);
    }
}

function admin_notifications_fetch_update_requests(mysqli $conn, array &$items): void
{
    if (!admin_notifications_table_exists($conn, 'attendance_update_requests')) {
        return;
    }

    $sql = "
        SELECT aur.id, aur.teacher_id, aur.class, aur.medium, aur.subject, aur.date, aur.reason, aur.requested_at,
               t.first_name, t.last_name
        FROM attendance_update_requests aur
        LEFT JOIN teachers t ON aur.teacher_id = t.id
        WHERE LOWER(COALESCE(aur.status, 'pending')) = 'pending'
        ORDER BY aur.requested_at DESC
        LIMIT 8
    ";

    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $teacherName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($teacherName === '') {
            $teacherName = 'Teacher #' . (int)($row['teacher_id'] ?? 0);
        }

        $classLabel = trim((string)($row['class'] ?? ''));
        $mediumLabel = trim((string)($row['medium'] ?? ''));
        $subjectLabel = trim((string)($row['subject'] ?? 'Attendance'));
        $requestDate = strtotime((string)($row['date'] ?? '')) ?: time();
        $requestedAt = strtotime((string)($row['requested_at'] ?? '')) ?: $requestDate;
        $reason = admin_notifications_trim_text((string)($row['reason'] ?? ''), 80);

        $message = $teacherName . ' requested attendance update for Class ' . $classLabel;
        if ($mediumLabel !== '') {
            $message .= ' (' . $mediumLabel . ')';
        }
        $message .= ' - ' . $subjectLabel . ' on ' . date('d M Y', $requestDate) . '.';
        if ($reason !== '') {
            $message .= ' Reason: ' . $reason;
        }

        admin_notifications_add($items, [
            'notification_key' => 'attendance-request:' . ($row['id'] ?? md5(json_encode($row))),
            'title' => 'Attendance update request',
            'message' => $message,
            'sort_timestamp' => $requestedAt,
            'icon' => 'fa-clipboard-question',
            'icon_theme' => 'danger',
            'link' => 'admin_assign_attendance.php',
        ]);
    }
}

function admin_notifications_fetch_admissions_from_table(mysqli $conn, string $table, string $mediumLabel, array &$items): void
{
    if (!admin_notifications_table_exists($conn, $table)) {
        return;
    }

    $columns = admin_notifications_table_columns($conn, $table);
    if (!isset($columns['id'], $columns['first_name'], $columns['last_name'], $columns['class'])) {
        return;
    }

    $timeColumn = isset($columns['verified_at']) ? 'verified_at' : (isset($columns['created_at']) ? 'created_at' : '');
    if ($timeColumn === '') {
        return;
    }

    $sql = "
        SELECT id, first_name, last_name, class, {$timeColumn} AS event_time
        FROM {$table}
        WHERE {$timeColumn} IS NOT NULL
          AND {$timeColumn} >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY {$timeColumn} DESC
        LIMIT 6
    ";

    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $studentName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($studentName === '') {
            $studentName = 'Student #' . (int)($row['id'] ?? 0);
        }

        $eventTime = strtotime((string)($row['event_time'] ?? 'now')) ?: time();
        $classLabel = trim((string)($row['class'] ?? ''));

        admin_notifications_add($items, [
            'notification_key' => 'admission:' . $table . ':' . ($row['id'] ?? md5(json_encode($row))),
            'title' => 'New student admission',
            'message' => $studentName . ' joined Class ' . $classLabel . ' (' . $mediumLabel . ' medium).',
            'sort_timestamp' => $eventTime,
            'icon' => 'fa-user-graduate',
            'icon_theme' => 'success',
            'link' => 'admission_report.php',
        ]);
    }
}

function admin_notifications_fetch_recent_admissions(mysqli $conn, array &$items): void
{
    admin_notifications_fetch_admissions_from_table($conn, 'student_english', 'English', $items);
    admin_notifications_fetch_admissions_from_table($conn, 'student_hindi', 'Hindi', $items);
}

function admin_notifications_mark_all_read(mysqli $conn, array $admin, int $limit = 20): void
{
    $adminId = (int)($admin['id'] ?? 0);
    if ($adminId <= 0) {
        return;
    }

    $notificationData = admin_get_notifications($conn, $admin, $limit);
    $keys = array_map(
        static fn(array $item): string => (string)($item['notification_key'] ?? ''),
        $notificationData['notifications'] ?? []
    );

    admin_notifications_mark_read($conn, $adminId, $keys);
}

function admin_get_notifications(mysqli $conn, array $admin = [], int $limit = 12): array
{
    $adminId = (int)($admin['id'] ?? 0);

    $items = [];
    admin_notifications_fetch_complaints($conn, $items);
    admin_notifications_fetch_update_requests($conn, $items);
    admin_notifications_fetch_recent_admissions($conn, $items);

    usort($items, static function (array $left, array $right): int {
        return ($right['sort_timestamp'] ?? 0) <=> ($left['sort_timestamp'] ?? 0);
    });

    if ($limit > 0) {
        $items = array_slice($items, 0, $limit);
    }

    $readMap = admin_notifications_read_map($conn, $adminId);
    $unreadCount = 0;

    foreach ($items as &$item) {
        $item['unread'] = !isset($readMap[$item['notification_key']]);
        if ($item['unread']) {
            $unreadCount++;
        }
    }
    unset($item);

    return [
        'unread_count' => $unreadCount,
        'notifications' => $items,
        'generated_at' => date('c'),
    ];
}
