<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site_settings.php';

$conn = @new mysqli('localhost', 'root', '', 'ruchi_classes');
if (!($conn instanceof mysqli) || $conn->connect_error) {
    $conn = null;
}

function favicon_resolve_absolute_path(string $relativePath): string
{
    $normalizedPath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($normalizedPath === '') {
        return '';
    }

    $candidatePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
    $resolvedPath = realpath($candidatePath);
    if ($resolvedPath === false) {
        return '';
    }

    $projectRoot = realpath(__DIR__);
    if ($projectRoot === false) {
        return '';
    }

    $projectRootWithSeparator = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($resolvedPath !== $projectRoot && strpos($resolvedPath, $projectRootWithSeparator) !== 0) {
        return '';
    }

    return $resolvedPath;
}

$relativePath = site_settings_favicon_relative_path($conn);
$normalizedPath = trim(str_replace('\\', '/', $relativePath), '/');

if (
    $normalizedPath === '' ||
    (
        strpos($normalizedPath, 'assets/') !== 0 &&
        strpos($normalizedPath, 'uploads/site_settings/') !== 0
    )
) {
    $normalizedPath = 'assets/Ruchi logo.jpg';
}

$absolutePath = favicon_resolve_absolute_path($normalizedPath);

if (!is_file($absolutePath)) {
    $normalizedPath = 'assets/Ruchi logo.jpg';
    $absolutePath = favicon_resolve_absolute_path($normalizedPath);
}

if (!is_file($absolutePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Favicon file not found.';
    exit;
}

header('Content-Type: ' . site_settings_guess_icon_type($normalizedPath));
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($absolutePath);
exit;
