<?php
declare(strict_types=1);

session_start();
ob_start();

require_once 'admin_auth.php';
require_once __DIR__ . '/../includes/site_settings.php';
require_once __DIR__ . '/../includes/faculty.php';
require_once __DIR__ . '/admin_sidebar.php';

requireAdminAuth();

$adminInfo = getAdminInfo();
$csrfToken = generateAdminCSRFToken();
$canManageFaculty = checkAdminPermission('manage_users');
$successMessage = (string)($_SESSION['faculty_success_message'] ?? '');
$errorMessage = (string)($_SESSION['faculty_error_message'] ?? '');
$defaultSiteTitle = 'Ruchi Classes';

unset($_SESSION['faculty_success_message'], $_SESSION['faculty_error_message']);

faculty_ensure_table($conn);

function faculty_admin_normalize_path(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function faculty_admin_is_uploaded_photo(string $relativePath): bool
{
    $normalized = faculty_admin_normalize_path($relativePath);
    return $normalized !== '' && strpos($normalized, 'uploads/faculty/') === 0;
}

function faculty_admin_delete_uploaded_photo(string $relativePath): void
{
    $normalized = faculty_admin_normalize_path($relativePath);
    if (!faculty_admin_is_uploaded_photo($normalized)) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function faculty_admin_allowed_image_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function faculty_admin_public_image_url(string $relativePath): string
{
    $normalized = faculty_admin_normalize_path($relativePath);
    if ($normalized === '') {
        return '../assets/Ruchi logo.jpg';
    }

    return '../' . $normalized;
}

function faculty_admin_flash_redirect(string $message, bool $success = true): void
{
    if ($success) {
        $_SESSION['faculty_success_message'] = $message;
    } else {
        $_SESSION['faculty_error_message'] = $message;
    }

    header('Location: admin_faculty.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateAdminCSRFToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Security token invalid or expired. Please refresh the page and try again.';
    } elseif (!$canManageFaculty) {
        $errorMessage = 'You do not have permission to manage faculty records.';
    } elseif (isset($_POST['delete_faculty'])) {
        $facultyId = (int)($_POST['faculty_id'] ?? 0);
        $facultyRecord = faculty_fetch_by_id($conn, $facultyId);

        if (!$facultyRecord) {
            $errorMessage = 'Faculty member not found.';
        } else {
            $stmt = $conn->prepare('DELETE FROM homepage_faculty WHERE id = ?');

            if (!$stmt) {
                $errorMessage = 'Unable to prepare delete request right now.';
            } else {
                $stmt->bind_param('i', $facultyId);

                if ($stmt->execute()) {
                    faculty_admin_delete_uploaded_photo((string)($facultyRecord['photo_path'] ?? ''));

                    if (function_exists('logAdminActivity')) {
                        logAdminActivity('FACULTY_DELETED', 'Deleted homepage faculty member: ' . ($facultyRecord['name'] ?? 'Unknown'));
                    }

                    faculty_admin_flash_redirect('Faculty member deleted successfully.');
                } else {
                    $errorMessage = 'Unable to delete faculty member right now.';
                }

                $stmt->close();
            }
        }
    } elseif (isset($_POST['save_faculty'])) {
        $facultyId = (int)($_POST['faculty_id'] ?? 0);
        $isUpdate = $facultyId > 0;
        $existingFaculty = $isUpdate ? faculty_fetch_by_id($conn, $facultyId) : null;

        if ($isUpdate && !$existingFaculty) {
            $errorMessage = 'Faculty member not found for editing.';
        } else {
            $name = trim((string)($_POST['name'] ?? ''));
            $experienceText = trim((string)($_POST['experience_text'] ?? ''));
            $qualification = trim((string)($_POST['qualification'] ?? ''));
            $displayOrder = max(0, (int)($_POST['display_order'] ?? 0));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $currentPhotoPath = trim((string)($existingFaculty['photo_path'] ?? ''));
            $newPhotoPath = '';
            $uploadedAbsolutePath = '';

            if ($displayOrder === 0) {
                $displayOrder = count(faculty_fetch_all($conn, false)) + ($isUpdate ? 0 : 1);
                if ($displayOrder <= 0) {
                    $displayOrder = 1;
                }
            }

            if ($name === '') {
                $errorMessage = 'Please enter the faculty name.';
            } elseif ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 150) {
                $errorMessage = 'Faculty name should be 150 characters or less.';
            } elseif ($experienceText === '') {
                $errorMessage = 'Please enter the experience text shown on the homepage.';
            } elseif ((function_exists('mb_strlen') ? mb_strlen($experienceText) : strlen($experienceText)) > 150) {
                $errorMessage = 'Experience text should be 150 characters or less.';
            } elseif ($qualification === '') {
                $errorMessage = 'Please enter the qualification line.';
            } elseif ((function_exists('mb_strlen') ? mb_strlen($qualification) : strlen($qualification)) > 255) {
                $errorMessage = 'Qualification should be 255 characters or less.';
            } else {
                $photoErrorCode = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);

                if ($photoErrorCode !== UPLOAD_ERR_NO_FILE) {
                    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
                        $errorMessage = 'Photo upload data is invalid.';
                    } elseif ($photoErrorCode !== UPLOAD_ERR_OK) {
                        $errorMessage = 'Photo upload failed. Please try again.';
                    } elseif ((int)($_FILES['photo']['size'] ?? 0) > 3 * 1024 * 1024) {
                        $errorMessage = 'Photo must be smaller than 3 MB.';
                    } else {
                        $allowedTypes = faculty_admin_allowed_image_types();
                        $tmpName = (string)($_FILES['photo']['tmp_name'] ?? '');
                        $detectedMime = '';

                        if (function_exists('finfo_open')) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            if ($finfo !== false) {
                                $detectedMime = (string)finfo_file($finfo, $tmpName);
                                finfo_close($finfo);
                            }
                        }

                        if ($detectedMime === '') {
                            $detectedMime = (string)($_FILES['photo']['type'] ?? '');
                        }

                        if (!isset($allowedTypes[$detectedMime])) {
                            $errorMessage = 'Only JPG, PNG, and WEBP faculty photos are allowed.';
                        } else {
                            $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'faculty';

                            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                                $errorMessage = 'Unable to create faculty upload directory.';
                            } else {
                                try {
                                    $randomSuffix = bin2hex(random_bytes(6));
                                } catch (Throwable $exception) {
                                    $randomSuffix = uniqid('faculty_', true);
                                }

                                $extension = $allowedTypes[$detectedMime];
                                $fileName = 'faculty_' . time() . '_' . $randomSuffix . '.' . $extension;
                                $uploadedAbsolutePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                                $newPhotoPath = 'uploads/faculty/' . $fileName;

                                if (!move_uploaded_file($tmpName, $uploadedAbsolutePath)) {
                                    $newPhotoPath = '';
                                    $uploadedAbsolutePath = '';
                                    $errorMessage = 'Unable to save uploaded faculty photo.';
                                }
                            }
                        }
                    }
                } elseif (!$isUpdate && $currentPhotoPath === '') {
                    $errorMessage = 'Please upload a faculty photo.';
                }
            }

            if ($errorMessage === '') {
                $photoPathToSave = $newPhotoPath !== '' ? $newPhotoPath : $currentPhotoPath;

                if ($isUpdate) {
                    $stmt = $conn->prepare(
                        'UPDATE homepage_faculty
                         SET name = ?, experience_text = ?, qualification = ?, photo_path = ?, display_order = ?, is_active = ?
                         WHERE id = ?'
                    );

                    if (!$stmt) {
                        $errorMessage = 'Unable to prepare update request right now.';
                    } else {
                        $stmt->bind_param(
                            'ssssiii',
                            $name,
                            $experienceText,
                            $qualification,
                            $photoPathToSave,
                            $displayOrder,
                            $isActive,
                            $facultyId
                        );

                        if ($stmt->execute()) {
                            if ($newPhotoPath !== '' && $currentPhotoPath !== '' && $currentPhotoPath !== $newPhotoPath) {
                                faculty_admin_delete_uploaded_photo($currentPhotoPath);
                            }

                            if (function_exists('logAdminActivity')) {
                                logAdminActivity('FACULTY_UPDATED', 'Updated homepage faculty member: ' . $name);
                            }

                            faculty_admin_flash_redirect('Faculty member updated successfully.');
                        } else {
                            $errorMessage = 'Unable to update faculty member right now.';
                        }

                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO homepage_faculty (name, experience_text, qualification, photo_path, display_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );

                    if (!$stmt) {
                        $errorMessage = 'Unable to prepare create request right now.';
                    } else {
                        $stmt->bind_param(
                            'ssssii',
                            $name,
                            $experienceText,
                            $qualification,
                            $photoPathToSave,
                            $displayOrder,
                            $isActive
                        );

                        if ($stmt->execute()) {
                            if (function_exists('logAdminActivity')) {
                                logAdminActivity('FACULTY_CREATED', 'Created homepage faculty member: ' . $name);
                            }

                            faculty_admin_flash_redirect('Faculty member added successfully.');
                        } else {
                            $errorMessage = 'Unable to save faculty member right now.';
                        }

                        $stmt->close();
                    }
                }

                if ($errorMessage !== '' && $uploadedAbsolutePath !== '' && is_file($uploadedAbsolutePath)) {
                    @unlink($uploadedAbsolutePath);
                }
            }
        }
    }
}

$facultyMembers = faculty_fetch_all($conn, false);
$activeFacultyCount = 0;

foreach ($facultyMembers as $member) {
    if ((int)($member['is_active'] ?? 0) === 1) {
        $activeFacultyCount++;
    }
}

$editFacultyId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingFaculty = $editFacultyId > 0 ? faculty_fetch_by_id($conn, $editFacultyId) : null;

$formState = [
    'id' => 0,
    'name' => '',
    'experience_text' => '',
    'qualification' => '',
    'display_order' => count($facultyMembers) + 1,
    'is_active' => 1,
    'photo_path' => '',
];

if ($editingFaculty) {
    $formState = [
        'id' => (int)($editingFaculty['id'] ?? 0),
        'name' => (string)($editingFaculty['name'] ?? ''),
        'experience_text' => (string)($editingFaculty['experience_text'] ?? ''),
        'qualification' => (string)($editingFaculty['qualification'] ?? ''),
        'display_order' => (int)($editingFaculty['display_order'] ?? 0),
        'is_active' => (int)($editingFaculty['is_active'] ?? 1),
        'photo_path' => (string)($editingFaculty['photo_path'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_faculty']) && $errorMessage !== '') {
    $formState = [
        'id' => (int)($_POST['faculty_id'] ?? 0),
        'name' => trim((string)($_POST['name'] ?? '')),
        'experience_text' => trim((string)($_POST['experience_text'] ?? '')),
        'qualification' => trim((string)($_POST['qualification'] ?? '')),
        'display_order' => max(1, (int)($_POST['display_order'] ?? 1)),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'photo_path' => (string)($editingFaculty['photo_path'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(site_settings_page_title_text($conn, 'Faculty Manager', $defaultSiteTitle), ENT_QUOTES, 'UTF-8'); ?></title>
    <?php echo site_settings_render_favicon_tags($conn); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0f766e;
            --primary-dark: #115e59;
            --primary-soft: #ccfbf1;
            --success: #15803d;
            --success-soft: #dcfce7;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --warning: #b45309;
            --warning-soft: #fef3c7;
            --text: #0f172a;
            --muted: #475569;
            --border: #dbe4ea;
            --bg: #f3f7f7;
            --card: rgba(255, 255, 255, 0.96);
            --shadow: 0 24px 50px rgba(15, 23, 42, 0.08);
            --radius: 22px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e5e7eb 100%);
            color: var(--text);
            padding: 0;
            overflow-x: hidden;
        }

        .page-shell {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 24px;
        }

        .sidebar-card,
        .content-card {
            background: var(--card);
            border: 1px solid rgba(219, 228, 234, 0.92);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
        }

        .sidebar-card {
            padding: 24px;
            position: sticky;
            top: 24px;
            align-self: start;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 26px;
        }

        .brand img {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            object-fit: cover;
            border: 3px solid var(--primary);
            background: #fff;
            padding: 4px;
        }

        .brand h1 {
            font-size: 22px;
            line-height: 1.1;
        }

        .brand span {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .admin-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 700;
            margin-bottom: 24px;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            text-decoration: none;
            color: var(--text);
            border-radius: 16px;
            font-weight: 600;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(15, 118, 110, 0.08);
            color: var(--primary-dark);
            border-color: rgba(15, 118, 110, 0.12);
        }

        .content-card {
            padding: 32px;
        }

        .faculty-content {
            max-width: 1280px;
            margin: 0 auto 32px;
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 800;
            color: var(--primary-dark);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .page-head h2 {
            font-size: 32px;
            line-height: 1.1;
            margin-bottom: 8px;
        }

        .page-head p {
            color: var(--muted);
            max-width: 620px;
            line-height: 1.6;
        }

        .head-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 14px 28px rgba(15, 118, 110, 0.18);
        }

        .btn-secondary {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-danger {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .btn-sm {
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            padding: 20px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(240, 253, 250, 0.98));
            border: 1px solid rgba(204, 251, 241, 0.9);
        }

        .stat-card .label {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 30px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .layout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(300px, 0.9fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .panel {
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            background: rgba(255, 255, 255, 0.92);
        }

        .panel h3 {
            font-size: 22px;
            margin-bottom: 8px;
        }

        .panel p {
            color: var(--muted);
            line-height: 1.6;
        }

        .faculty-form {
            margin-top: 20px;
            display: grid;
            gap: 16px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .field input,
        .field textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 13px 14px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }

        .field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .field input:focus,
        .field textarea:focus {
            outline: none;
            border-color: rgba(15, 118, 110, 0.45);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.08);
        }

        .field small {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .checkbox-row {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 49px;
            padding-top: 30px;
        }

        .checkbox-row input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .message {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 18px;
            border-radius: 18px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }

        .message-success {
            background: var(--success-soft);
            color: var(--success);
            border-color: rgba(21, 128, 61, 0.15);
        }

        .message-error {
            background: var(--danger-soft);
            color: var(--danger);
            border-color: rgba(220, 38, 38, 0.15);
        }

        .preview-card {
            display: grid;
            gap: 16px;
        }

        .photo-preview {
            width: 100%;
            max-width: 280px;
            aspect-ratio: 4 / 4.4;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid rgba(15, 118, 110, 0.16);
            background: linear-gradient(135deg, rgba(204, 251, 241, 0.65), rgba(255, 255, 255, 0.96));
        }

        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .preview-meta {
            display: grid;
            gap: 12px;
        }

        .preview-meta strong {
            display: block;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .preview-note {
            padding: 16px 18px;
            border-radius: 16px;
            background: var(--warning-soft);
            color: #92400e;
            border: 1px solid rgba(180, 83, 9, 0.12);
            line-height: 1.6;
        }

        .table-panel {
            overflow: hidden;
        }

        .table-wrap {
            overflow-x: auto;
            margin-top: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }

        thead th {
            text-align: left;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            background: rgba(248, 250, 252, 0.92);
        }

        tbody td {
            padding: 16px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            vertical-align: top;
        }

        .faculty-cell {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .faculty-thumb {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(15, 118, 110, 0.14);
            background: #f8fafc;
            flex-shrink: 0;
        }

        .faculty-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .faculty-name {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .faculty-sub {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
        }

        .status-active {
            background: var(--success-soft);
            color: var(--success);
        }

        .status-inactive {
            background: rgba(226, 232, 240, 0.9);
            color: var(--muted);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .actions form {
            margin: 0;
        }

        .empty-state {
            padding: 26px 18px;
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.9);
            border: 1px dashed var(--border);
            color: var(--muted);
            text-align: center;
            margin-top: 18px;
        }

        @media (max-width: 1120px) {
            .page-shell {
                grid-template-columns: 1fr;
            }

            .sidebar-card {
                position: static;
            }
        }

        @media (max-width: 860px) {
            body {
                padding: 18px 12px;
            }

            .content-card,
            .sidebar-card {
                padding: 20px;
            }

            .stats-row,
            .layout-grid,
            .field-grid {
                grid-template-columns: 1fr;
            }

            .checkbox-row {
                padding-top: 0;
            }
        }
    </style>
    <?php echo admin_render_sidebar_styles(); ?>
</head>
<body>
    <div class="dashboard-container">
        <?php echo admin_render_sidebar($adminInfo, $conn, 'admin_faculty.php'); ?>

        <main class="main-content" id="mainContent">
            <?php echo admin_render_page_header('Faculty Manager', 'Homepage faculty section manage karein', 'fas fa-user-tie', $adminInfo); ?>

            <section class="content-card faculty-content">
            <div class="page-head">
                <div>
                    <div class="eyebrow">
                        <i class="fas fa-user-tie"></i>
                        Homepage Module
                    </div>
                    <h2>Manage Faculty</h2>
                    <p>Update the dynamic <strong>Meet Our Faculty</strong> section from the admin panel. Changes here are reflected on the homepage automatically.</p>
                </div>

                <div class="head-actions">
                    <a href="../index.php" class="btn btn-secondary" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-arrow-up-right-from-square"></i>
                        View Homepage
                    </a>
                    <?php if ($formState['id'] > 0): ?>
                        <a href="admin_faculty.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Add New Faculty
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="message message-success">
                    <i class="fas fa-circle-check"></i>
                    <div><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="message message-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="label">Total Faculty Records</div>
                    <div class="value"><?php echo count($facultyMembers); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Active on Homepage</div>
                    <div class="value"><?php echo $activeFacultyCount; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Current Form Mode</div>
                    <div class="value" style="font-size: 22px;"><?php echo $formState['id'] > 0 ? 'Edit' : 'Create'; ?></div>
                </div>
            </div>

            <div class="layout-grid">
                <section class="panel">
                    <h3><?php echo $formState['id'] > 0 ? 'Edit Faculty Member' : 'Add Faculty Member'; ?></h3>
                    <p>Keep the content close to the homepage card layout: name, experience text, qualification, image, and order.</p>

                    <form method="POST" enctype="multipart/form-data" class="faculty-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="faculty_id" value="<?php echo (int)$formState['id']; ?>">

                        <div class="field-grid">
                            <div class="field">
                                <label for="faculty_name">Faculty Name</label>
                                <input
                                    type="text"
                                    id="faculty_name"
                                    name="name"
                                    maxlength="150"
                                    value="<?php echo htmlspecialchars((string)$formState['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Mr. Praveen Kumar Sharma"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="display_order">Display Order</label>
                                <input
                                    type="number"
                                    id="display_order"
                                    name="display_order"
                                    min="1"
                                    value="<?php echo max(1, (int)$formState['display_order']); ?>"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="experience_text">Experience Text</label>
                                <input
                                    type="text"
                                    id="experience_text"
                                    name="experience_text"
                                    maxlength="150"
                                    value="<?php echo htmlspecialchars((string)$formState['experience_text'], ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Experience: 16 Years"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="photo">Faculty Photo</label>
                                <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp">
                                <small><?php echo $formState['id'] > 0 ? 'Upload only if you want to replace the current image.' : 'Upload a portrait photo for the homepage card.'; ?></small>
                            </div>

                            <div class="field full">
                                <label for="qualification">Qualification</label>
                                <textarea
                                    id="qualification"
                                    name="qualification"
                                    maxlength="255"
                                    placeholder="B.Sc, M.Sc, MBA"
                                    required
                                ><?php echo htmlspecialchars((string)$formState['qualification'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="field full">
                                <div class="checkbox-row">
                                    <input type="checkbox" id="is_active" name="is_active" <?php echo (int)$formState['is_active'] === 1 ? 'checked' : ''; ?>>
                                    <label for="is_active" style="margin: 0;">Show this faculty member on homepage</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="save_faculty" class="btn btn-primary">
                                <i class="fas fa-floppy-disk"></i>
                                <?php echo $formState['id'] > 0 ? 'Update Faculty' : 'Save Faculty'; ?>
                            </button>

                            <?php if ($formState['id'] > 0): ?>
                                <a href="admin_faculty.php" class="btn btn-secondary">
                                    <i class="fas fa-xmark"></i>
                                    Cancel Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>

                <aside class="panel">
                    <h3>Current Preview</h3>
                    <p>This helps you confirm what the card content will look like before saving or while editing.</p>

                    <div class="preview-card" style="margin-top: 20px;">
                        <div class="photo-preview">
                            <img
                                src="<?php echo htmlspecialchars(faculty_admin_public_image_url((string)$formState['photo_path']), ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Faculty preview"
                            >
                        </div>

                        <div class="preview-meta">
                            <div>
                                <strong>Name</strong>
                                <div><?php echo htmlspecialchars((string)($formState['name'] !== '' ? $formState['name'] : 'Faculty Name'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div>
                                <strong>Experience</strong>
                                <div><?php echo htmlspecialchars((string)($formState['experience_text'] !== '' ? $formState['experience_text'] : 'Experience text'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div>
                                <strong>Qualification</strong>
                                <div><?php echo htmlspecialchars((string)($formState['qualification'] !== '' ? $formState['qualification'] : 'Qualification line'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div>
                                <strong>Status</strong>
                                <div><?php echo (int)$formState['is_active'] === 1 ? 'Visible on homepage' : 'Hidden from homepage'; ?></div>
                            </div>
                        </div>

                        <div class="preview-note">
                            Existing homepage faculty cards were seeded automatically into this module, so the current homepage stays intact while you start managing records here.
                        </div>
                    </div>
                </aside>
            </div>

            <section class="panel table-panel">
                <h3>Faculty List</h3>
                <p>Use edit for content updates and delete only when the card should be removed permanently.</p>

                <?php if ($facultyMembers === []): ?>
                    <div class="empty-state">
                        No faculty records found yet. Use the form above to add the first faculty member.
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Faculty</th>
                                    <th>Experience</th>
                                    <th>Qualification</th>
                                    <th>Order</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($facultyMembers as $member): ?>
                                    <tr>
                                        <td>
                                            <div class="faculty-cell">
                                                <div class="faculty-thumb">
                                                    <img
                                                        src="<?php echo htmlspecialchars(faculty_admin_public_image_url((string)($member['photo_path'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                                        alt="<?php echo htmlspecialchars((string)($member['name'] ?? 'Faculty'), ENT_QUOTES, 'UTF-8'); ?>"
                                                    >
                                                </div>
                                                <div>
                                                    <div class="faculty-name"><?php echo htmlspecialchars((string)($member['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="faculty-sub">Updated <?php echo htmlspecialchars(date('d M Y', strtotime((string)($member['updated_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)($member['experience_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($member['qualification'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo (int)($member['display_order'] ?? 0); ?></td>
                                        <td>
                                            <?php if ((int)($member['is_active'] ?? 0) === 1): ?>
                                                <span class="status-badge status-active"><i class="fas fa-eye"></i> Active</span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive"><i class="fas fa-eye-slash"></i> Hidden</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <a href="admin_faculty.php?edit=<?php echo (int)($member['id'] ?? 0); ?>" class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-pen"></i>
                                                    Edit
                                                </a>
                                                <form method="POST" onsubmit="return confirm('Delete this faculty member from the homepage?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="faculty_id" value="<?php echo (int)($member['id'] ?? 0); ?>">
                                                    <button type="submit" name="delete_faculty" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i>
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            </section>
        </main>
    </div>
    <?php echo admin_render_sidebar_script(); ?>
</body>
</html>
