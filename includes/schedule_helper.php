<?php

if (!function_exists('schedule_table_exists')) {
    function schedule_table_exists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'schedule'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

if (!function_exists('schedule_table_columns')) {
    function schedule_table_columns(mysqli $conn): array
    {
        $columns = [];
        if (!schedule_table_exists($conn)) {
            return $columns;
        }

        $result = $conn->query("SHOW COLUMNS FROM `schedule`");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $columns[$row['Field']] = true;
            }
        }

        return $columns;
    }
}

if (!function_exists('schedule_ensure_management_columns')) {
    function schedule_ensure_management_columns(mysqli $conn): void
    {
        if (!schedule_table_exists($conn)) {
            return;
        }

        $columns = schedule_table_columns($conn);

        if (!isset($columns['schedule_type'])) {
            $conn->query("ALTER TABLE `schedule` ADD COLUMN `schedule_type` VARCHAR(20) NOT NULL DEFAULT 'permanent' AFTER `medium`");
            $columns = schedule_table_columns($conn);
        }

        if (!isset($columns['expires_at'])) {
            $conn->query("ALTER TABLE `schedule` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL AFTER `schedule_type`");
        }
    }
}

if (!function_exists('schedule_normalize_type')) {
    function schedule_normalize_type(?string $type): string
    {
        return strtolower(trim((string)$type)) === 'temporary' ? 'temporary' : 'permanent';
    }
}

if (!function_exists('schedule_compute_expiry')) {
    function schedule_compute_expiry(string $dayName, string $endTime, ?DateTimeImmutable $reference = null): ?string
    {
        $dayMap = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 7,
        ];

        $dayName = ucfirst(strtolower(trim($dayName)));
        $endTime = trim($endTime);

        if (!isset($dayMap[$dayName]) || $endTime === '') {
            return null;
        }

        $timezone = new DateTimeZone('Asia/Kolkata');
        $reference = $reference ?: new DateTimeImmutable('now', $timezone);

        $timeValue = DateTimeImmutable::createFromFormat('!H:i', $endTime, $timezone)
            ?: DateTimeImmutable::createFromFormat('!H:i:s', $endTime, $timezone);

        if (!$timeValue) {
            $timestamp = strtotime($endTime);
            if ($timestamp === false) {
                return null;
            }

            $timeValue = (new DateTimeImmutable('now', $timezone))->setTime(
                (int)date('H', $timestamp),
                (int)date('i', $timestamp),
                (int)date('s', $timestamp)
            );
        }

        $todayIndex = (int)$reference->format('N');
        $daysAhead = ($dayMap[$dayName] - $todayIndex + 7) % 7;
        $expiry = $reference
            ->modify(sprintf('+%d days', $daysAhead))
            ->setTime(
                (int)$timeValue->format('H'),
                (int)$timeValue->format('i'),
                (int)$timeValue->format('s')
            );

        if ($daysAhead === 0 && $expiry <= $reference) {
            $expiry = $expiry->modify('+7 days');
        }

        return $expiry->format('Y-m-d H:i:s');
    }
}

if (!function_exists('schedule_is_visible')) {
    function schedule_is_visible(array $scheduleRow, ?DateTimeImmutable $reference = null): bool
    {
        $scheduleType = schedule_normalize_type($scheduleRow['schedule_type'] ?? 'permanent');
        if ($scheduleType !== 'temporary') {
            return true;
        }

        $expiresAt = trim((string)($scheduleRow['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return true;
        }

        $timezone = new DateTimeZone('Asia/Kolkata');
        $reference = $reference ?: new DateTimeImmutable('now', $timezone);

        try {
            $expiry = new DateTimeImmutable($expiresAt, $timezone);
        } catch (Throwable $e) {
            $timestamp = strtotime($expiresAt);
            if ($timestamp === false) {
                return true;
            }

            $expiry = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        }

        return $expiry > $reference;
    }
}

if (!function_exists('schedule_expiry_label')) {
    function schedule_expiry_label(?string $expiresAt): string
    {
        $expiresAt = trim((string)$expiresAt);
        if ($expiresAt === '') {
            return '';
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            return '';
        }

        return date('d M Y, h:i A', $timestamp);
    }
}
