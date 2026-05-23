<?php
declare(strict_types=1);

function faculty_default_seed_data(): array
{
    return [
        [
            'name' => 'Mr. Praveen Kumar Sharma',
            'experience_text' => 'Experience: 16 Years',
            'qualification' => 'B.Sc, M.Sc, MBA',
            'photo_path' => 'assets/pravin.jpg',
            'display_order' => 1,
            'is_active' => 1,
        ],
        [
            'name' => 'Mr. Talib Sir',
            'experience_text' => 'Experience: 9 Years',
            'qualification' => 'B.Com, M.Com, CMA',
            'photo_path' => 'assets/talib.jpg',
            'display_order' => 2,
            'is_active' => 1,
        ],
        [
            'name' => 'Mr. Sunil Gupta',
            'experience_text' => 'Experience: 36 Years',
            'qualification' => 'B.Com, M.Com, Inter CA',
            'photo_path' => 'assets/sunilgupta.jpg',
            'display_order' => 3,
            'is_active' => 1,
        ],
        [
            'name' => 'Mr. Rishi Sir',
            'experience_text' => 'Experience: 7 Years',
            'qualification' => 'M.Sc, B.Ed (Physics)',
            'photo_path' => 'assets/rishi.jpg',
            'display_order' => 4,
            'is_active' => 1,
        ],
        [
            'name' => 'Mr. Sambhu Sir',
            'experience_text' => 'Experience: 24 Years',
            'qualification' => 'M.A (Economics)',
            'photo_path' => 'assets/sambhu.jpg',
            'display_order' => 5,
            'is_active' => 1,
        ],
    ];
}

function faculty_ensure_table($conn): bool
{
    static $checked = false;

    if ($checked) {
        return true;
    }

    if (!($conn instanceof mysqli) || $conn->connect_errno) {
        return false;
    }

    $tableAlreadyExists = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'homepage_faculty'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $tableAlreadyExists = true;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS homepage_faculty (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            experience_text VARCHAR(150) NOT NULL DEFAULT '',
            qualification VARCHAR(255) NOT NULL DEFAULT '',
            photo_path VARCHAR(255) NOT NULL DEFAULT '',
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_display_order (display_order),
            INDEX idx_is_active (is_active)
        )
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    $checked = true;

    if (!$tableAlreadyExists) {
        faculty_seed_defaults($conn);
    }

    return true;
}

function faculty_seed_defaults($conn): void
{
    if (!($conn instanceof mysqli) || $conn->connect_errno) {
        return;
    }

    $countResult = $conn->query('SELECT COUNT(*) AS total FROM homepage_faculty');
    if (!$countResult) {
        return;
    }

    $countRow = $countResult->fetch_assoc();
    $existingCount = (int)($countRow['total'] ?? 0);

    if ($existingCount > 0) {
        return;
    }

    $defaults = faculty_default_seed_data();
    $stmt = $conn->prepare(
        'INSERT INTO homepage_faculty (name, experience_text, qualification, photo_path, display_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        return;
    }

    foreach ($defaults as $record) {
        $name = (string)$record['name'];
        $experienceText = (string)$record['experience_text'];
        $qualification = (string)$record['qualification'];
        $photoPath = (string)$record['photo_path'];
        $displayOrder = (int)$record['display_order'];
        $isActive = (int)$record['is_active'];

        $stmt->bind_param(
            'ssssii',
            $name,
            $experienceText,
            $qualification,
            $photoPath,
            $displayOrder,
            $isActive
        );
        $stmt->execute();
    }

    $stmt->close();
}

function faculty_fetch_all($conn, bool $activeOnly = false): array
{
    if (!faculty_ensure_table($conn)) {
        return [];
    }

    $sql = 'SELECT id, name, experience_text, qualification, photo_path, display_order, is_active, created_at, updated_at
            FROM homepage_faculty';

    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }

    $sql .= ' ORDER BY display_order ASC, id ASC';

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }

    return $records;
}

function faculty_fetch_by_id($conn, int $facultyId): ?array
{
    if ($facultyId <= 0 || !faculty_ensure_table($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, name, experience_text, qualification, photo_path, display_order, is_active, created_at, updated_at
         FROM homepage_faculty
         WHERE id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($record) ? $record : null;
}

function faculty_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function faculty_render_homepage_card(array $faculty, bool $isDuplicate = false): string
{
    $name = trim((string)($faculty['name'] ?? 'Faculty Member'));
    $experienceText = trim((string)($faculty['experience_text'] ?? ''));
    $qualification = trim((string)($faculty['qualification'] ?? ''));
    $photoPath = trim((string)($faculty['photo_path'] ?? 'assets/Ruchi logo.jpg'));

    if ($name === '') {
        $name = 'Faculty Member';
    }

    if ($photoPath === '') {
        $photoPath = 'assets/Ruchi logo.jpg';
    }

    $cardClass = $isDuplicate ? 'teacher-card duplicate-card' : 'teacher-card';
    $html = '        <div class="' . $cardClass . '">' . PHP_EOL;
    $html .= '          <img src="' . faculty_escape($photoPath) . '" alt="' . faculty_escape($name) . '">' . PHP_EOL;
    $html .= '          <div class="teacher-card-body">' . PHP_EOL;
    $html .= '            <h3>' . faculty_escape($name) . '</h3>' . PHP_EOL;

    if ($experienceText !== '') {
        $html .= '            <p>' . faculty_escape($experienceText) . '</p>' . PHP_EOL;
    }

    if ($qualification !== '') {
        $html .= '            <ul>' . PHP_EOL;
        $html .= '              <li>' . faculty_escape($qualification) . '</li>' . PHP_EOL;
        $html .= '            </ul>' . PHP_EOL;
    }

    $html .= '          </div>' . PHP_EOL;
    $html .= '        </div>';

    return $html;
}

function faculty_render_homepage_section($conn): string
{
    if (!faculty_ensure_table($conn)) {
        $facultyMembers = faculty_default_seed_data();
    } else {
        $facultyMembers = faculty_fetch_all($conn, true);
        if ($facultyMembers === []) {
            return '';
        }
    }

    $cards = [];
    foreach ($facultyMembers as $faculty) {
        $cards[] = faculty_render_homepage_card($faculty, false);
    }

    if (count($facultyMembers) > 1) {
        foreach ($facultyMembers as $faculty) {
            $cards[] = faculty_render_homepage_card($faculty, true);
        }
    }

    $navigation = '';
    if (count($facultyMembers) > 1) {
        $navigation = PHP_EOL
            . '    <div class="teacher-nav-buttons">' . PHP_EOL
            . '      <button class="teacher-nav-btn" id="prevTeacher">&larr; Previous</button>' . PHP_EOL
            . '      <button class="teacher-nav-btn" id="nextTeacher">Next &rarr;</button>' . PHP_EOL
            . '    </div>';
    }

    return '  <section class="teacher-section" id="Faculty">' . PHP_EOL
        . '    <div class="teacher-header">' . PHP_EOL
        . '      <h4>OUR TEACHER</h4>' . PHP_EOL
        . '      <h2>Meet Our Faculty</h2>' . PHP_EOL
        . '    </div>' . PHP_EOL
        . PHP_EOL
        . '    <div class="teacher-slider">' . PHP_EOL
        . '      <div class="teacher-slider-track">' . PHP_EOL
        . implode(PHP_EOL . PHP_EOL, $cards) . PHP_EOL
        . '      </div>' . PHP_EOL
        . '    </div>'
        . $navigation . PHP_EOL
        . '  </section>';
}
