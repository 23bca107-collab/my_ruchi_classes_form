<?php
declare(strict_types=1);

function site_settings_ensure_table($conn): bool
{
    static $checked = false;

    if ($checked) {
        return true;
    }

    if (!($conn instanceof mysqli)) {
        return false;
    }

    $checked = true;

    $sql = "
        CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";

    return (bool)$conn->query($sql);
}

function site_settings_get($conn, string $key, string $default = ''): string
{
    if (!($conn instanceof mysqli) || !site_settings_ensure_table($conn)) {
        return $default;
    }

    $stmt = $conn->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        return $default;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    return is_array($row) ? (string)($row['setting_value'] ?? $default) : $default;
}

function site_settings_set($conn, string $key, string $value): bool
{
    if (!($conn instanceof mysqli) || !site_settings_ensure_table($conn)) {
        return false;
    }

    $stmt = $conn->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $key, $value);
    return $stmt->execute();
}

function site_settings_delete($conn, string $key): bool
{
    if (!($conn instanceof mysqli) || !site_settings_ensure_table($conn)) {
        return false;
    }

    $stmt = $conn->prepare('DELETE FROM site_settings WHERE setting_key = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $key);
    return $stmt->execute();
}

function site_settings_project_base_path(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $projectRoot = realpath(dirname(__DIR__));

    if ($documentRoot && $projectRoot) {
        $documentRoot = str_replace('\\', '/', $documentRoot);
        $projectRoot = str_replace('\\', '/', $projectRoot);

        if (strpos($projectRoot, $documentRoot) === 0) {
            $relative = trim(substr($projectRoot, strlen($documentRoot)), '/');
            $basePath = $relative === '' ? '' : '/' . $relative;
            return $basePath;
        }
    }

    $projectFolder = basename(dirname(__DIR__));
    $basePath = $projectFolder === '' ? '' : '/' . rawurlencode($projectFolder);
    return $basePath;
}

function site_settings_asset_url(string $relativePath): string
{
    $normalized = trim(str_replace('\\', '/', $relativePath), '/');
    if ($normalized === '') {
        return '';
    }

    $segments = array_map('rawurlencode', explode('/', $normalized));
    $basePath = site_settings_project_base_path();

    return ($basePath === '' ? '' : $basePath) . '/' . implode('/', $segments);
}

function site_settings_guess_icon_type(string $relativePath): string
{
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    return match ($extension) {
        'ico' => 'image/x-icon',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'image/x-icon',
    };
}

function site_settings_favicon_relative_path($conn, string $fallback = 'assets/Ruchi logo.jpg'): string
{
    $storedPath = trim(site_settings_get($conn, 'site_favicon_path', ''));
    return $storedPath !== '' ? $storedPath : $fallback;
}

function site_settings_site_title($conn, string $default = 'Ruchi Classes'): string
{
    $storedTitle = trim(site_settings_get($conn, 'site_title', ''));
    return $storedTitle !== '' ? $storedTitle : $default;
}

function site_settings_page_title_text($conn, string $pageTitle, string $defaultSiteTitle = 'Ruchi Classes', string $separator = ' | '): string
{
    $siteTitle = site_settings_site_title($conn, $defaultSiteTitle);
    $pageTitle = trim($pageTitle);

    return $pageTitle === '' ? $siteTitle : $pageTitle . $separator . $siteTitle;
}

function site_settings_favicon_url($conn, string $fallback = 'assets/Ruchi logo.jpg'): string
{
    return site_settings_asset_url(site_settings_favicon_relative_path($conn, $fallback));
}

function site_settings_favicon_endpoint_url(): string
{
    $basePath = site_settings_project_base_path();

    return ($basePath === '' ? '' : $basePath) . '/favicon.php';
}

function site_settings_render_favicon_tags($conn, string $fallback = 'assets/Ruchi logo.jpg'): string
{
    $faviconUrl = site_settings_favicon_endpoint_url();

    if ($faviconUrl === '') {
        return '';
    }

    $escapedUrl = htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8');
    $iconType = '';

    if ($conn instanceof mysqli) {
        $relativePath = site_settings_favicon_relative_path($conn, $fallback);
        $iconType = ' type="' . htmlspecialchars(site_settings_guess_icon_type($relativePath), ENT_QUOTES, 'UTF-8') . '"';
    }

    return '<link rel="icon"' . $iconType . ' href="' . $escapedUrl . '">' . PHP_EOL
        . '<link rel="shortcut icon" href="' . $escapedUrl . '">';
}

function site_settings_html_has_favicon(string $html): bool
{
    return stripos($html, 'rel="icon"') !== false
        || stripos($html, "rel='icon'") !== false
        || stripos($html, 'shortcut icon') !== false
        || stripos($html, 'apple-touch-icon') !== false;
}

function site_settings_inject_favicon_into_html(string $html, $conn): string
{
    if ($html === '' || stripos($html, '<head') === false || site_settings_html_has_favicon($html)) {
        return $html;
    }

    $faviconTags = site_settings_render_favicon_tags($conn);
    if ($faviconTags === '') {
        return $html;
    }

    $replacement = PHP_EOL . '    ' . $faviconTags . PHP_EOL;
    $updatedHtml = preg_replace('~</head>~i', $replacement . '</head>', $html, 1);

    return is_string($updatedHtml) ? $updatedHtml : $html;
}

function site_settings_start_favicon_buffer($conn): void
{
    static $bufferStarted = false;

    if ($bufferStarted || php_sapi_name() === 'cli' || headers_sent()) {
        return;
    }

    $bufferStarted = true;

    ob_start(static function (string $buffer) use ($conn): string {
        return site_settings_inject_favicon_into_html($buffer, $conn);
    });
}
