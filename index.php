<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/faculty.php';
require_once __DIR__ . '/includes/site_settings.php';

$homepage = __DIR__ . '/ruchihomepage.html';

if (!is_file($homepage)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Homepage file not found: ruchihomepage.html';
    exit;
}

$homepageHtml = file_get_contents($homepage);

if ($homepageHtml === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to read homepage template.';
    exit;
}

$conn = @new mysqli('localhost', 'root', '', 'ruchi_classes');
$faviconConn = ($conn instanceof mysqli && !$conn->connect_errno) ? $conn : null;
site_settings_start_favicon_buffer($faviconConn);
$facultySectionHtml = faculty_render_homepage_section(
    $faviconConn
);

$facultyPattern = '~<section class="teacher-section" id="Faculty">.*?</section>~s';
$updatedHtml = preg_replace($facultyPattern, $facultySectionHtml, $homepageHtml, 1);

header('Content-Type: text/html; charset=UTF-8');
echo is_string($updatedHtml) ? $updatedHtml : $homepageHtml;
exit;
