<?php

function student_notifications_table_exists(mysqli $conn, string $table): bool
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

function student_notifications_table_columns(mysqli $conn, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    if (!student_notifications_table_exists($conn, $table)) {
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

function student_notifications_ensure_reads_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_notification_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            notification_key VARCHAR(191) NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_student_notification (student_id, notification_key),
            INDEX idx_student_read (student_id, read_at)
        )
    ");
}

function student_notifications_read_map(mysqli $conn, int $studentId): array
{
    student_notifications_ensure_reads_table($conn);

    $readMap = [];
    $stmt = $conn->prepare("SELECT notification_key FROM student_notification_reads WHERE student_id = ?");
    if (!$stmt) {
        return $readMap;
    }

    $stmt->bind_param('i', $studentId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $readMap[$row['notification_key']] = true;
        }
    }

    $stmt->close();
    return $readMap;
}

function student_notifications_mark_read(mysqli $conn, int $studentId, array $keys): void
{
    if ($studentId <= 0 || $keys === []) {
        return;
    }

    student_notifications_ensure_reads_table($conn);
    $stmt = $conn->prepare("
        INSERT IGNORE INTO student_notification_reads (student_id, notification_key)
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

        $stmt->bind_param('is', $studentId, $key);
        $stmt->execute();
    }

    $stmt->close();
}

function student_notifications_relative_time(?int $timestamp): string
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
        return floor($diff / 86400) . ' day ago' . (floor($diff / 86400) > 1 ? 's' : '');
    }

    return date('d M Y', $timestamp);
}

function student_notifications_normalize_medium(string $medium): string
{
    $medium = trim($medium);
    if ($medium === '') {
        return '';
    }

    $lower = strtolower($medium);
    if ($lower === 'english') {
        return 'English';
    }
    if ($lower === 'hindi') {
        return 'Hindi';
    }

    return $medium;
}

function student_notifications_add(array &$items, array $notification): void
{
    if (empty($notification['notification_key'])) {
        return;
    }

    $timestamp = (int)($notification['sort_timestamp'] ?? 0);
    $notification['sort_timestamp'] = $timestamp;
    $notification['time_label'] = student_notifications_relative_time($timestamp);
    $notification['icon'] = $notification['icon'] ?? 'fa-bell';
    $notification['icon_theme'] = $notification['icon_theme'] ?? 'info';
    $notification['link'] = $notification['link'] ?? '#';
    $notification['title'] = trim((string)($notification['title'] ?? 'Notification'));
    $notification['message'] = trim((string)($notification['message'] ?? ''));
    $items[] = $notification;
}

function student_notifications_fetch_messages(mysqli $conn, int $studentId, array &$items): void
{
    if ($studentId <= 0 || !student_notifications_table_exists($conn, 'messages')) {
        return;
    }

    $columns = student_notifications_table_columns($conn, 'messages');
    if (!isset($columns['receiver_id'], $columns['receiver_type'])) {
        return;
    }

    $select = [
        isset($columns['id']) ? 'id' : '0 AS id',
        isset($columns['subject']) ? 'subject' : "'' AS subject",
        isset($columns['message']) ? 'message' : (isset($columns['body']) ? 'body AS message' : (isset($columns['content']) ? 'content AS message' : "'' AS message")),
        isset($columns['sender_type']) ? 'sender_type' : "'' AS sender_type",
        isset($columns['sender_name']) ? 'sender_name' : "'' AS sender_name",
    ];

    $timeColumn = isset($columns['created_at']) ? 'created_at' : (isset($columns['sent_at']) ? 'sent_at' : (isset($columns['updated_at']) ? 'updated_at' : ''));
    $select[] = $timeColumn !== '' ? "{$timeColumn} AS event_time" : 'NOW() AS event_time';

    $sql = "SELECT " . implode(', ', $select) . "
            FROM messages
            WHERE receiver_id = ?
              AND LOWER(receiver_type) = 'student'";

    if (isset($columns['status'])) {
        $sql .= " AND LOWER(status) = 'unread'";
    }

    $sql .= ' ORDER BY ' . ($timeColumn !== '' ? $timeColumn : 'id') . ' DESC LIMIT 5';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $studentId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $subject = trim((string)($row['subject'] ?? ''));
            $message = trim((string)($row['message'] ?? ''));
            $senderType = ucfirst(strtolower(trim((string)($row['sender_type'] ?? 'Admin'))));
            $senderName = trim((string)($row['sender_name'] ?? ''));
            $displaySender = $senderName !== '' ? $senderName : $senderType;
            $eventTime = strtotime((string)($row['event_time'] ?? 'now')) ?: time();

            student_notifications_add($items, [
                'notification_key' => 'message:' . ($row['id'] ?? md5($displaySender . $subject . $message)),
                'title' => $subject !== '' ? $subject : ($displaySender . ' sent a message'),
                'message' => $message !== '' ? $message : 'You have a new unread message.',
                'sort_timestamp' => $eventTime,
                'icon' => 'fa-envelope',
                'icon_theme' => 'primary',
                'link' => 'student_videos.php',
            ]);
        }
    }

    $stmt->close();
}

function student_notifications_fetch_videos(mysqli $conn, string $class, string $medium, array &$items): void
{
    if ($class === '' || $medium === '' || !student_notifications_table_exists($conn, 'youtube_videos')) {
        return;
    }

    $columns = student_notifications_table_columns($conn, 'youtube_videos');
    $timeColumn = isset($columns['created_at']) ? 'created_at' : (isset($columns['updated_at']) ? 'updated_at' : '');

    $sql = "
        SELECT id, title, subject, chapter" . ($timeColumn !== '' ? ", {$timeColumn} AS event_time" : '') . "
        FROM youtube_videos
        WHERE is_active = 1
          AND class_name = ?
          AND (LOWER(medium) = LOWER(?) OR LOWER(medium) = 'both')";

    if ($timeColumn !== '') {
        $sql .= " AND {$timeColumn} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }

    $sql .= ' ORDER BY ' . ($timeColumn !== '' ? $timeColumn : 'id') . ' DESC LIMIT 4';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $class, $medium);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $subjectLine = trim((string)($row['subject'] ?? ''));
            $chapterLine = trim((string)($row['chapter'] ?? ''));
            $parts = [];
            if ($subjectLine !== '') {
                $parts[] = $subjectLine;
            }
            if ($chapterLine !== '') {
                $parts[] = $chapterLine;
            }

            student_notifications_add($items, [
                'notification_key' => 'video:' . ($row['id'] ?? md5(json_encode($row))),
                'title' => trim((string)($row['title'] ?? 'New video uploaded')),
                'message' => $parts !== [] ? 'New lecture uploaded for ' . implode(' | ', $parts) : 'A new lecture has been uploaded for your class.',
                'sort_timestamp' => $timeColumn !== '' ? (strtotime((string)$row['event_time']) ?: time()) : time(),
                'icon' => 'fa-play-circle',
                'icon_theme' => 'danger',
                'link' => 'student_videos.php',
            ]);
        }
    }

    $stmt->close();
}

function student_notifications_fetch_exams(mysqli $conn, string $class, string $medium, array &$items): void
{
    if ($class === '' || $medium === '' || !student_notifications_table_exists($conn, 'exams')) {
        return;
    }

    $sql = "
        SELECT id, subject, topic, exam_date, exam_time, exam_type, marks
        FROM exams
        WHERE class = ?
          AND LOWER(medium) = LOWER(?)
          AND exam_date >= CURDATE()
        ORDER BY exam_date ASC, exam_time ASC
        LIMIT 3
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $class, $medium);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $examDate = trim((string)($row['exam_date'] ?? ''));
            $examTime = trim((string)($row['exam_time'] ?? ''));
            $examTimestamp = strtotime(trim($examDate . ' ' . $examTime)) ?: time();
            $topic = trim((string)($row['topic'] ?? ''));
            $type = trim((string)($row['exam_type'] ?? ''));
            $marks = trim((string)($row['marks'] ?? ''));

            $details = [];
            if ($topic !== '') {
                $details[] = $topic;
            }
            if ($type !== '') {
                $details[] = $type;
            }
            if ($marks !== '') {
                $details[] = $marks . ' marks';
            }
            $details[] = date('d M Y, h:i A', $examTimestamp);

            student_notifications_add($items, [
                'notification_key' => 'exam:' . ($row['id'] ?? '') . ':' . md5(json_encode($row)),
                'title' => trim((string)($row['subject'] ?? 'Upcoming exam')),
                'message' => 'Upcoming exam: ' . implode(' | ', $details),
                'sort_timestamp' => $examTimestamp,
                'icon' => 'fa-file-alt',
                'icon_theme' => 'warning',
                'link' => 'student_exams.php',
            ]);
        }
    }

    $stmt->close();
}

function student_notifications_fetch_marks(mysqli $conn, int $studentId, array &$items): void
{
    if ($studentId <= 0 || !student_notifications_table_exists($conn, 'exam_marks') || !student_notifications_table_exists($conn, 'exams')) {
        return;
    }

    $sql = "
        SELECT e.id AS exam_id, e.subject, e.exam_date, e.exam_time, e.total_marks, em.obtained_marks
        FROM exam_marks em
        JOIN exams e ON em.exam_id = e.id
        WHERE em.student_id = ?
          AND e.exam_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ORDER BY e.exam_date DESC, e.exam_time DESC
        LIMIT 4
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $studentId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $examTimestamp = strtotime(trim((string)($row['exam_date'] ?? '') . ' ' . (string)($row['exam_time'] ?? ''))) ?: time();
            $totalMarks = (int)($row['total_marks'] ?? 0);
            $obtainedMarks = (int)($row['obtained_marks'] ?? 0);

            student_notifications_add($items, [
                'notification_key' => 'mark:' . ($row['exam_id'] ?? '') . ':' . md5(json_encode($row)),
                'title' => trim((string)($row['subject'] ?? 'Marks updated')),
                'message' => 'Marks published: ' . $obtainedMarks . ($totalMarks > 0 ? ' / ' . $totalMarks : ''),
                'sort_timestamp' => $examTimestamp,
                'icon' => 'fa-chart-line',
                'icon_theme' => 'success',
                'link' => 'student_marks.php',
            ]);
        }
    }

    $stmt->close();
}

function student_notifications_fetch_attendance(mysqli $conn, int $studentId, array &$items): void
{
    if ($studentId <= 0 || !student_notifications_table_exists($conn, 'attendance')) {
        return;
    }

    $sql = "
        SELECT subject, date, status, submitted_at
        FROM attendance
        WHERE student_id = ?
          AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY submitted_at DESC, date DESC
        LIMIT 4
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $studentId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $statusMap = [
                'P' => 'Present',
                'A' => 'Absent',
                'S' => 'Suspended',
                'R' => 'Remaining',
            ];
            $status = $statusMap[$row['status'] ?? ''] ?? (string)($row['status'] ?? 'Updated');
            $timestamp = strtotime((string)($row['submitted_at'] ?? '')) ?: (strtotime((string)($row['date'] ?? '')) ?: time());

            student_notifications_add($items, [
                'notification_key' => 'attendance:' . md5(json_encode($row)),
                'title' => trim((string)($row['subject'] ?? 'Attendance updated')),
                'message' => 'Attendance marked as ' . $status . ' for ' . date('d M Y', strtotime((string)($row['date'] ?? 'now'))),
                'sort_timestamp' => $timestamp,
                'icon' => 'fa-calendar-check',
                'icon_theme' => 'primary',
                'link' => 'attendance.php',
            ]);
        }
    }

    $stmt->close();
}

function student_notifications_fetch_complaints(mysqli $conn, int $studentId, array &$items): void
{
    if ($studentId <= 0 || !student_notifications_table_exists($conn, 'complaints')) {
        return;
    }

    $sql = "
        SELECT id, complaint, status, created_at
        FROM complaints
        WHERE user_type = 'student'
          AND user_id = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        ORDER BY created_at DESC
        LIMIT 3
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $studentId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $status = ucfirst(strtolower(trim((string)($row['status'] ?? 'Pending'))));
            $complaintText = trim((string)($row['complaint'] ?? ''));
            $shortText = $complaintText !== '' ? substr($complaintText, 0, 90) : 'Your complaint status was updated.';

            student_notifications_add($items, [
                'notification_key' => 'complaint:' . ($row['id'] ?? '') . ':' . $status,
                'title' => $status === 'Resolved' ? 'Complaint resolved' : 'Complaint ' . strtolower($status),
                'message' => $shortText,
                'sort_timestamp' => strtotime((string)($row['created_at'] ?? '')) ?: time(),
                'icon' => 'fa-comment-dots',
                'icon_theme' => $status === 'Resolved' ? 'success' : 'warning',
                'link' => 'complaint_history.php',
            ]);
        }
    }

    $stmt->close();
}

function student_get_notifications(mysqli $conn, array $student, int $limit = 12): array
{
    $studentId = (int)($student['id'] ?? 0);
    $class = trim((string)($student['class'] ?? ''));
    $medium = student_notifications_normalize_medium((string)($student['medium'] ?? ''));

    $items = [];
    student_notifications_fetch_messages($conn, $studentId, $items);
    student_notifications_fetch_videos($conn, $class, strtolower($medium), $items);
    student_notifications_fetch_exams($conn, $class, $medium, $items);
    student_notifications_fetch_marks($conn, $studentId, $items);
    student_notifications_fetch_attendance($conn, $studentId, $items);
    student_notifications_fetch_complaints($conn, $studentId, $items);

    usort($items, static function (array $left, array $right): int {
        return ($right['sort_timestamp'] ?? 0) <=> ($left['sort_timestamp'] ?? 0);
    });

    if ($limit > 0) {
        $items = array_slice($items, 0, $limit);
    }

    $readMap = student_notifications_read_map($conn, $studentId);
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
