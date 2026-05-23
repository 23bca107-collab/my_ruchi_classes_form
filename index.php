<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/faculty.php';

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
$facultySectionHtml = faculty_render_homepage_section(
    ($conn instanceof mysqli && !$conn->connect_errno) ? $conn : null
);

$facultyPattern = '~<section class="teacher-section" id="Faculty">.*?</section>~s';
$updatedHtml = preg_replace($facultyPattern, $facultySectionHtml, $homepageHtml, 1);

header('Content-Type: text/html; charset=UTF-8');
echo is_string($updatedHtml) ? $updatedHtml : $homepageHtml;
exit;
