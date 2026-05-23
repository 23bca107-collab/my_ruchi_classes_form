<?php

function teacher_notifications_table_exists(mysqli $conn, string $table): bool
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

function teacher_notifications_table_columns(mysqli $conn, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    if (!teacher_notifications_table_exists($conn, $table)) {
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

function teacher_notifications_ensure_reads_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS teacher_notification_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            notification_key VARCHAR(191) NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_teacher_notification (teacher_id, notification_key),
            INDEX idx_teacher_read (teacher_id, read_at)
        )
    ");
}

function teacher_notifications_read_map(mysqli $conn, int $teacherId): array
{
    teacher_notifications_ensure_reads_table($conn);

    $readMap = [];
    if ($teacherId <= 0) {
        return $readMap;
    }

    $stmt = $conn->prepare("SELECT notification_key FROM teacher_notification_reads WHERE teacher_id = ?");
    if (!$stmt) {
        return $readMap;
    }

    $stmt->bind_param('i', $teacherId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $readMap[$row['notification_key']] = true;
        }
    }

    $stmt->close();
    return $readMap;
}

function teacher_notifications_mark_read(mysqli $conn, int $teacherId, array $keys): void
{
    if ($teacherId <= 0 || $keys === []) {
        return;
    }

    teacher_notifications_ensure_reads_table($conn);
    $stmt = $conn->prepare("
        INSERT IGNORE INTO teacher_notification_reads (teacher_id, notification_key)
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

        $stmt->bind_param('is', $teacherId, $key);
        $stmt->execute();
    }

    $stmt->close();
}

function teacher_notifications_relative_time(?int $timestamp): string
{
    if (empty($timestamp) || $timestamp <= 0) {
        return 'Just now';
    }

    if ($timestamp > time()) {
        $secondsAhead = $timestamp - time();
        if ($secondsAhead < 86400) {
            return 'Today ' . date('h:i A', $timestamp);
        }
        if ($secondsAhead < 172800) {
            return 'Tomorrow';
        }

        return date('d M, h:i A', $timestamp);
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

function teacher_notifications_add(array &$items, array $notification): void
{
    if (empty($notification['notification_key'])) {
        return;
    }

    $timestamp = (int)($notification['sort_timestamp'] ?? 0);
    $notification['sort_timestamp'] = $timestamp;
    $notification['time_label'] = teacher_notifications_relative_time($timestamp);
    $notification['title'] = trim((string)($notification['title'] ?? 'Notification'));
    $notification['message'] = trim((string)($notification['message'] ?? ''));
    $notification['icon'] = $notification['icon'] ?? 'fa-bell';
    $notification['icon_theme'] = $notification['icon_theme'] ?? 'info';
    $notification['link'] = $notification['link'] ?? 'teacher_dashboard.php';

    $items[] = $notification;
}

function teacher_notifications_enrich_teacher(mysqli $conn, array $teacher): array
{
    $teacherId = (int)($teacher['id'] ?? 0);
    if (
        $teacherId <= 0
        || !teacher_notifications_table_exists($conn, 'teachers')
        || (
            !empty($teacher['email'])
            && !empty($teacher['first_name'])
            && !empty($teacher['last_name'])
        )
    ) {
        return $teacher;
    }

    $stmt = $conn->prepare("
        SELECT id, email, first_name, last_name, subject, status
        FROM teachers
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $teacher;
    }

    $stmt->bind_param('i', $teacherId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($row) {
            $teacher = array_merge($row, $teacher);
            $teacher['id'] = $teacherId;
        }
    }

    $stmt->close();
    return $teacher;
}

function teacher_notifications_base_filters(array $columns): array
{
    $filters = ["receiver_id = ?", "LOWER(receiver_type) = 'teacher'"];

    if (isset($columns['sender_type'])) {
        $filters[] = "(LOWER(COALESCE(sender_type, 'admin')) = 'admin' OR LOWER(sender_type) = 'system' OR sender_type = '')";
    }

    return $filters;
}

function teacher_notifications_fetch_messages(mysqli $conn, int $teacherId, array &$items): void
{
    if ($teacherId <= 0 || !teacher_notifications_table_exists($conn, 'messages')) {
        return;
    }

    $columns = teacher_notifications_table_columns($conn, 'messages');
    if (!isset($columns['receiver_id'], $columns['receiver_type'])) {
        return;
    }

    $select = [
        isset($columns['id']) ? 'id' : '0 AS id',
        isset($columns['subject']) ? 'subject' : "'' AS subject",
        isset($columns['message']) ? 'message' : (isset($columns['body']) ? 'body AS message' : (isset($columns['content']) ? 'content AS message' : "'' AS message")),
        isset($columns['sender_type']) ? 'sender_type' : "'Admin' AS sender_type",
        isset($columns['sender_name']) ? 'sender_name' : "'' AS sender_name",
    ];

    $timeColumn = isset($columns['created_at']) ? 'created_at' : (isset($columns['sent_at']) ? 'sent_at' : (isset($columns['updated_at']) ? 'updated_at' : ''));
    $select[] = $timeColumn !== '' ? "{$timeColumn} AS event_time" : 'NOW() AS event_time';

    $filters = teacher_notifications_base_filters($columns);
    if ($timeColumn !== '') {
        $filters[] = "{$timeColumn} >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
    }

    $sql = "SELECT " . implode(', ', $select) . "
            FROM messages
            WHERE " . implode(' AND ', $filters) . "
            ORDER BY " . ($timeColumn !== '' ? $timeColumn : 'id') . " DESC
            LIMIT 8";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $teacherId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $subject = trim((string)($row['subject'] ?? ''));
            $message = trim((string)($row['message'] ?? ''));
            $senderType = ucfirst(strtolower(trim((string)($row['sender_type'] ?? 'Admin'))));
            $senderName = trim((string)($row['sender_name'] ?? ''));
            $displaySender = $senderName !== '' ? $senderName : $senderType;
            $eventTime = strtotime((string)($row['event_time'] ?? 'now')) ?: time();

            teacher_notifications_add($items, [
                'notification_key' => 'message:' . ($row['id'] ?? md5($displaySender . $subject . $message . $eventTime)),
                'title' => $subject !== '' ? $subject : ($displaySender . ' sent a message'),
                'message' => $message !== '' ? $message : 'You have a new admin message.',
                'sort_timestamp' => $eventTime,
                'icon' => 'fa-envelope',
                'icon_theme' => 'primary',
                'link' => 'teacher_dashboard.php',
            ]);
        }
    }

    $stmt->close();
}

function teacher_notifications_activity_actions(mysqli $conn): string
{
    $actions = [
        'CLASS_ASSIGNED',
        'CLASS_ASSIGNMENT_COMPLETE',
        'CLASS_ASSIGNMENT_REMOVED',
        'STUDENT_ASSIGNED',
        'STUDENT_ASSIGNMENT_COMPLETE',
        'ASSIGNMENT_REMOVED',
        'AUTO_UNASSIGN',
        'TEACHER_STATUS_CHANGED',
        'TEACHER_ADDED',
        'ATTENDANCE_TASK_LOCKED',
        'ATTENDANCE_TASK_UNLOCKED',
        'ATTENDANCE_TASK_REMOVED',
        'COMPLAINT_STATUS_UPDATED',
    ];

    $safeActions = array_map(
        static fn(string $action): string => "'" . $conn->real_escape_string($action) . "'",
        $actions
    );

    return implode(', ', $safeActions);
}

function teacher_notifications_activity_matches_teacher(string $details, int $teacherId, string $teacherEmail): bool
{
    $details = strtolower($details);
    $teacherEmail = strtolower(trim($teacherEmail));

    if ($teacherId > 0) {
        $patterns = [
            "teacher id: {$teacherId}",
            "teacher id {$teacherId}",
            "teacher_id: {$teacherId}",
            "teacher_id={$teacherId}",
        ];

        foreach ($patterns as $pattern) {
            if (strpos($details, $pattern) !== false) {
                return true;
            }
        }
    }

    return $teacherEmail !== '' && strpos($details, $teacherEmail) !== false;
}

function teacher_notifications_activity_payload(array $row): array
{
    $action = strtoupper(trim((string)($row['action'] ?? '')));
    $details = trim((string)($row['details'] ?? ''));
    $timestamp = strtotime((string)($row['timestamp'] ?? 'now')) ?: time();

    $title = 'Admin update';
    $message = $details !== '' ? $details : 'Admin updated your information.';
    $icon = 'fa-bell';
    $theme = 'info';
    $link = 'teacher_dashboard.php';

    switch ($action) {
        case 'CLASS_ASSIGNED':
            $title = 'Class assigned';
            $icon = 'fa-chalkboard';
            $theme = 'primary';
            $link = 'teacher_students.php';
            break;
        case 'CLASS_ASSIGNMENT_COMPLETE':
            $title = 'Class assignments updated';
            $icon = 'fa-layer-group';
            $theme = 'primary';
            $link = 'teacher_students.php';
            break;
        case 'CLASS_ASSIGNMENT_REMOVED':
            $title = 'Class removed';
            $icon = 'fa-chalkboard-user';
            $theme = 'warning';
            $link = 'teacher_students.php';
            break;
        case 'STUDENT_ASSIGNED':
            $title = 'Student assigned';
            $icon = 'fa-user-plus';
            $theme = 'success';
            $link = 'teacher_students.php';
            break;
        case 'STUDENT_ASSIGNMENT_COMPLETE':
            $title = 'Student list updated';
            $icon = 'fa-users';
            $theme = 'success';
            $link = 'teacher_students.php';
            break;
        case 'ASSIGNMENT_REMOVED':
        case 'AUTO_UNASSIGN':
            $title = 'Student assignment removed';
            $icon = 'fa-user-minus';
            $theme = 'warning';
            $link = 'teacher_students.php';
            break;
        case 'TEACHER_STATUS_CHANGED':
            $title = 'Account status updated';
            $icon = 'fa-user-shield';
            $theme = stripos($details, 'inactive') !== false ? 'danger' : 'success';
            break;
        case 'TEACHER_ADDED':
            $title = 'Teacher account created';
            $icon = 'fa-id-badge';
            $theme = 'info';
            break;
        case 'ATTENDANCE_TASK_LOCKED':
            $title = 'Attendance locked';
            $icon = 'fa-lock';
            $theme = 'danger';
            $link = 'teacher_attendance.php';
            break;
        case 'ATTENDANCE_TASK_UNLOCKED':
            $title = 'Attendance unlocked';
            $icon = 'fa-lock-open';
            $theme = 'success';
            $link = 'teacher_attendance.php';
            break;
        case 'ATTENDANCE_TASK_REMOVED':
            $title = 'Attendance task removed';
            $icon = 'fa-trash-can';
            $theme = 'warning';
            $link = 'teacher_attendance.php';
            break;
        case 'COMPLAINT_STATUS_UPDATED':
            $title = 'Complaint status updated';
            $icon = 'fa-comment-dots';
            $theme = stripos($details, 'resolved') !== false ? 'success' : 'info';
            $link = 'complaint_history.php';
            break;
    }

    return [
        'notification_key' => 'admin-activity:' . ($row['id'] ?? md5($action . $details . $timestamp)),
        'title' => $title,
        'message' => $message,
        'sort_timestamp' => $timestamp,
        'icon' => $icon,
        'icon_theme' => $theme,
        'link' => $link,
    ];
}

function teacher_notifications_fetch_admin_activity(mysqli $conn, array $teacher, array &$items): void
{
    if (!teacher_notifications_table_exists($conn, 'admin_activity')) {
        return;
    }

    $teacherId = (int)($teacher['id'] ?? 0);
    $teacherEmail = trim((string)($teacher['email'] ?? ''));
    if ($teacherId <= 0 && $teacherEmail === '') {
        return;
    }

    $actionList = teacher_notifications_activity_actions($conn);
    if ($actionList === '') {
        return;
    }

    $sql = "
        SELECT id, action, details, timestamp
        FROM admin_activity
        WHERE action IN ({$actionList})
          AND timestamp >= DATE_SUB(NOW(), INTERVAL 120 DAY)
        ORDER BY timestamp DESC
        LIMIT 120
    ";

    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        if (!teacher_notifications_activity_matches_teacher((string)($row['details'] ?? ''), $teacherId, $teacherEmail)) {
            continue;
        }

        teacher_notifications_add($items, teacher_notifications_activity_payload($row));
    }
}

function teacher_notifications_fetch_attendance_tasks(mysqli $conn, int $teacherId, array &$items): void
{
    if ($teacherId <= 0 || !teacher_notifications_table_exists($conn, 'attendance_tasks')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT id, class, medium, subject, task_date, status, is_locked, lock_reason, assigned_at
        FROM attendance_tasks
        WHERE teacher_id = ?
          AND assigned_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)
        ORDER BY assigned_at DESC
        LIMIT 8
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $teacherId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $taskDate = strtotime((string)($row['task_date'] ?? '')) ?: time();
            $assignedAt = strtotime((string)($row['assigned_at'] ?? '')) ?: $taskDate;
            $classLabel = trim((string)($row['class'] ?? ''));
            $mediumLabel = trim((string)($row['medium'] ?? ''));
            $subjectLabel = trim((string)($row['subject'] ?? 'Attendance'));
            $message = 'Admin assigned attendance for Class ' . $classLabel . ' (' . $mediumLabel . ') - ' . $subjectLabel . ' on ' . date('d M Y', $taskDate) . '.';

            if (!empty($row['is_locked'])) {
                $lockReason = trim((string)($row['lock_reason'] ?? ''));
                $message .= $lockReason !== '' ? ' ' . $lockReason . '.' : ' This task is currently locked.';
            }

            teacher_notifications_add($items, [
                'notification_key' => 'attendance-task:' . ($row['id'] ?? md5(json_encode($row))),
                'title' => 'Attendance assigned',
                'message' => $message,
                'sort_timestamp' => $assignedAt,
                'icon' => 'fa-clipboard-check',
                'icon_theme' => 'warning',
                'link' => 'teacher_attendance.php',
            ]);
        }
    }

    $stmt->close();
}

function teacher_notifications_fetch_update_requests(mysqli $conn, int $teacherId, array &$items): void
{
    if ($teacherId <= 0 || !teacher_notifications_table_exists($conn, 'attendance_update_requests')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT id, class, medium, subject, date, status, requested_at, reviewed_at, admin_notes
        FROM attendance_update_requests
        WHERE teacher_id = ?
          AND LOWER(status) <> 'pending'
        ORDER BY COALESCE(reviewed_at, requested_at) DESC
        LIMIT 8
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $teacherId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $status = strtolower(trim((string)($row['status'] ?? 'reviewed')));
            $timestamp = strtotime((string)($row['reviewed_at'] ?? '')) ?: (strtotime((string)($row['requested_at'] ?? '')) ?: time());
            $requestDate = strtotime((string)($row['date'] ?? '')) ?: time();
            $classLabel = trim((string)($row['class'] ?? ''));
            $mediumLabel = trim((string)($row['medium'] ?? ''));
            $subjectLabel = trim((string)($row['subject'] ?? 'Attendance'));
            $adminNotes = trim((string)($row['admin_notes'] ?? ''));

            $title = 'Attendance update reviewed';
            $theme = 'info';
            if ($status === 'approved') {
                $title = 'Update request approved';
                $theme = 'success';
            } elseif ($status === 'rejected') {
                $title = 'Update request rejected';
                $theme = 'danger';
            } elseif ($status === 'completed') {
                $title = 'Update request completed';
                $theme = 'primary';
            }

            $message = 'Admin marked your request for Class ' . $classLabel . ' (' . $mediumLabel . ') - ' . $subjectLabel . ' on ' . date('d M Y', $requestDate) . ' as ' . ucfirst($status) . '.';
            if ($adminNotes !== '') {
                $message .= ' Note: ' . $adminNotes;
            }

            teacher_notifications_add($items, [
                'notification_key' => 'attendance-update:' . ($row['id'] ?? md5(json_encode($row))) . ':' . $status,
                'title' => $title,
                'message' => $message,
                'sort_timestamp' => $timestamp,
                'icon' => $status === 'rejected' ? 'fa-circle-xmark' : 'fa-circle-check',
                'icon_theme' => $theme,
                'link' => 'teacher_attendance.php',
            ]);
        }
    }

    $stmt->close();
}

function teacher_notifications_mark_all_read(mysqli $conn, array $teacher, int $limit = 20): void
{
    $teacherId = (int)($teacher['id'] ?? 0);
    if ($teacherId <= 0) {
        return;
    }

    $notificationData = teacher_get_notifications($conn, $teacher, $limit);
    $keys = array_map(
        static fn(array $item): string => (string)($item['notification_key'] ?? ''),
        $notificationData['notifications'] ?? []
    );

    teacher_notifications_mark_read($conn, $teacherId, $keys);
}

function teacher_get_notifications(mysqli $conn, array $teacher, int $limit = 12): array
{
    $teacher = teacher_notifications_enrich_teacher($conn, $teacher);
    $teacherId = (int)($teacher['id'] ?? 0);

    $items = [];
    teacher_notifications_fetch_messages($conn, $teacherId, $items);
    teacher_notifications_fetch_admin_activity($conn, $teacher, $items);
    teacher_notifications_fetch_attendance_tasks($conn, $teacherId, $items);
    teacher_notifications_fetch_update_requests($conn, $teacherId, $items);

    usort($items, static function (array $left, array $right): int {
        return ($right['sort_timestamp'] ?? 0) <=> ($left['sort_timestamp'] ?? 0);
    });

    if ($limit > 0) {
        $items = array_slice($items, 0, $limit);
    }

    $readMap = teacher_notifications_read_map($conn, $teacherId);
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
