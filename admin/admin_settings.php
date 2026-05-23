<?php
declare(strict_types=1);

session_start();
ob_start();

require_once 'admin_auth.php';
require_once __DIR__ . '/../includes/site_settings.php';

requireAdminAuth();

$adminInfo = getAdminInfo();
$csrfToken = generateAdminCSRFToken();
$successMessage = '';
$errorMessage = '';
$defaultSiteTitle = 'Ruchi Classes';

site_settings_ensure_table($conn);

function admin_settings_normalize_path(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function admin_settings_delete_uploaded_favicon(string $relativePath): void
{
    $normalized = admin_settings_normalize_path($relativePath);
    if ($normalized === '' || strpos($normalized, 'uploads/site_settings/') !== 0) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function admin_settings_allowed_favicon_types(): array
{
    return [
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateAdminCSRFToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Security token invalid or expired. Please refresh the page and try again.';
    } elseif (isset($_POST['save_site_title'])) {
        $submittedTitle = trim((string)($_POST['site_title'] ?? ''));
        $submittedTitleLength = function_exists('mb_strlen') ? mb_strlen($submittedTitle) : strlen($submittedTitle);

        if ($submittedTitle === '') {
            $errorMessage = 'Please enter a site title before saving.';
        } elseif ($submittedTitleLength > 120) {
            $errorMessage = 'Site title should be 120 characters or less.';
        } elseif (site_settings_set($conn, 'site_title', $submittedTitle)) {
            $successMessage = 'Site title updated successfully.';
            if (function_exists('logAdminActivity')) {
                logAdminActivity('SETTINGS_SITE_TITLE_UPDATED', 'Admin updated site title to: ' . $submittedTitle);
            }
        } else {
            $errorMessage = 'Unable to save site title right now. Please try again.';
        }
    } elseif (isset($_POST['reset_site_title'])) {
        if (site_settings_delete($conn, 'site_title')) {
            $successMessage = 'Site title reset to default successfully.';
            if (function_exists('logAdminActivity')) {
                logAdminActivity('SETTINGS_SITE_TITLE_RESET', 'Admin reset site title to default.');
            }
        } else {
            $errorMessage = 'Unable to reset site title right now. Please try again.';
        }
    } elseif (isset($_POST['reset_favicon'])) {
        $existingCustomPath = site_settings_get($conn, 'site_favicon_path', '');
        admin_settings_delete_uploaded_favicon($existingCustomPath);

        if (site_settings_delete($conn, 'site_favicon_path')) {
            $successMessage = 'Favicon reset to default successfully.';
            if (function_exists('logAdminActivity')) {
                logAdminActivity('SETTINGS_FAVICON_RESET', 'Admin reset site favicon to default.');
            }
        } else {
            $errorMessage = 'Unable to reset favicon right now. Please try again.';
        }
    } elseif (isset($_POST['save_favicon'])) {
        if (
            !isset($_FILES['site_favicon']) ||
            !is_array($_FILES['site_favicon']) ||
            (int)($_FILES['site_favicon']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            $errorMessage = 'Please choose a favicon file first.';
        } else {
            $upload = $_FILES['site_favicon'];
            $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($uploadError !== UPLOAD_ERR_OK) {
                $errorMessage = 'File upload failed. Please try again.';
            } elseif ((int)($upload['size'] ?? 0) > 2 * 1024 * 1024) {
                $errorMessage = 'Favicon file must be smaller than 2 MB.';
            } else {
                $allowedTypes = admin_settings_allowed_favicon_types();
                $tmpName = (string)($upload['tmp_name'] ?? '');
                $detectedMime = '';

                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo !== false) {
                        $detectedMime = (string)finfo_file($finfo, $tmpName);
                        finfo_close($finfo);
                    }
                }

                if ($detectedMime === '') {
                    $detectedMime = (string)($upload['type'] ?? '');
                }

                if (!isset($allowedTypes[$detectedMime])) {
                    $errorMessage = 'Only ICO, PNG, JPG, JPEG, and WEBP files are allowed for favicon.';
                } else {
                    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'site_settings';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                        $errorMessage = 'Unable to create favicon upload directory.';
                    } else {
                        $extension = $allowedTypes[$detectedMime];
                        $fileName = 'favicon_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                        $storedRelativePath = 'uploads/site_settings/' . $fileName;

                        if (!move_uploaded_file($tmpName, $targetPath)) {
                            $errorMessage = 'Unable to save uploaded favicon. Please try again.';
                        } else {
                            $oldCustomPath = site_settings_get($conn, 'site_favicon_path', '');

                            if (site_settings_set($conn, 'site_favicon_path', $storedRelativePath)) {
                                admin_settings_delete_uploaded_favicon($oldCustomPath);
                                $successMessage = 'Favicon updated successfully.';

                                if (function_exists('logAdminActivity')) {
                                    logAdminActivity('SETTINGS_FAVICON_UPDATED', 'Admin updated site favicon.');
                                }
                            } else {
                                @unlink($targetPath);
                                $errorMessage = 'Unable to save favicon setting in database.';
                            }
                        }
                    }
                }
            }
        }
    }
}

$currentCustomFavicon = site_settings_get($conn, 'site_favicon_path', '');
$currentFaviconRelative = site_settings_favicon_relative_path($conn);
$currentFaviconUrl = site_settings_favicon_url($conn);
$usingDefaultFavicon = trim($currentCustomFavicon) === '';
$currentCustomSiteTitle = site_settings_get($conn, 'site_title', '');
$currentSiteTitle = site_settings_site_title($conn, $defaultSiteTitle);
$usingDefaultSiteTitle = trim($currentCustomSiteTitle) === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(site_settings_page_title_text($conn, 'Admin Settings', $defaultSiteTitle), ENT_QUOTES, 'UTF-8'); ?></title>
    <?php echo site_settings_render_favicon_tags($conn); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-soft: #dbeafe;
            --success: #16a34a;
            --success-soft: #dcfce7;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --warning-soft: #fff7ed;
            --text: #0f172a;
            --muted: #475569;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
            --shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
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
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.14), transparent 35%),
                linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            color: var(--text);
            padding: 32px 18px;
        }

        .settings-shell {
            max-width: 1120px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 24px;
        }

        .sidebar-card,
        .content-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
        }

        .sidebar-card {
            padding: 24px;
            align-self: start;
            position: sticky;
            top: 24px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 26px;
        }

        .brand img {
            width: 56px;
            height: 56px;
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
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
            font-weight: 500;
        }

        .admin-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .nav-links {
            display: grid;
            gap: 12px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            padding: 14px 16px;
            border-radius: 16px;
            color: var(--muted);
            background: #fff;
            border: 1px solid var(--border);
            transition: transform 0.2s ease, box-shadow 0.2s ease, color 0.2s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(37, 99, 235, 0.12);
            color: var(--primary-dark);
            border-color: rgba(37, 99, 235, 0.28);
        }

        .content-card {
            padding: 32px;
        }

        .page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .eyebrow {
            color: var(--primary-dark);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .page-head h2 {
            font-size: 34px;
            line-height: 1.1;
            margin-bottom: 10px;
        }

        .page-head p {
            color: var(--muted);
            max-width: 640px;
            line-height: 1.7;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--warning-soft);
            color: #9a3412;
            font-weight: 700;
            font-size: 13px;
        }

        .message {
            padding: 16px 18px;
            border-radius: 18px;
            margin-bottom: 22px;
            font-weight: 600;
            line-height: 1.6;
            border: 1px solid transparent;
        }

        .message.success {
            background: var(--success-soft);
            border-color: rgba(22, 163, 74, 0.24);
            color: #166534;
        }

        .message.error {
            background: var(--danger-soft);
            border-color: rgba(220, 38, 38, 0.22);
            color: #991b1b;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr);
            gap: 24px;
        }

        .panel {
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), #ffffff);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 24px;
        }

        .panel h3 {
            font-size: 22px;
            margin-bottom: 10px;
        }

        .panel p {
            color: var(--muted);
            line-height: 1.7;
        }

        .preview-card {
            display: grid;
            gap: 18px;
        }

        .favicon-preview {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 112px;
            height: 112px;
            border-radius: 28px;
            background: #fff;
            border: 2px dashed rgba(37, 99, 235, 0.24);
            box-shadow: inset 0 0 0 10px rgba(219, 234, 254, 0.55);
        }

        .favicon-preview img {
            width: 64px;
            height: 64px;
            object-fit: contain;
        }

        .meta-list {
            display: grid;
            gap: 10px;
        }

        .meta-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid var(--border);
            border-radius: 14px;
        }

        .meta-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .meta-value {
            text-align: right;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .upload-box {
            margin-top: 20px;
            border: 2px dashed rgba(37, 99, 235, 0.24);
            background: rgba(219, 234, 254, 0.34);
            border-radius: 20px;
            padding: 20px;
        }

        .field-label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .subsection {
            margin-top: 24px;
        }

        .file-input {
            width: 100%;
            padding: 14px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            margin-bottom: 14px;
        }

        .helper-text {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            border: none;
            border-radius: 16px;
            padding: 14px 20px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff;
            box-shadow: 0 14px 26px rgba(37, 99, 235, 0.2);
        }

        .btn-secondary {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            color: #fff;
            box-shadow: 0 14px 26px rgba(220, 38, 38, 0.18);
        }

        .tips {
            margin-top: 22px;
            display: grid;
            gap: 12px;
        }

        .tip {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid var(--border);
        }

        .tip i {
            color: var(--primary-dark);
            margin-top: 2px;
        }

        @media (max-width: 980px) {
            .settings-shell {
                grid-template-columns: 1fr;
            }

            .sidebar-card {
                position: static;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 16px 12px;
            }

            .content-card,
            .sidebar-card,
            .panel {
                padding: 20px;
            }

            .page-head h2 {
                font-size: 28px;
            }

            .meta-row {
                flex-direction: column;
            }

            .meta-value {
                text-align: left;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="settings-shell">
        <aside class="sidebar-card">
            <div class="brand">
                <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes">
                <div>
                    <h1><?php echo htmlspecialchars($currentSiteTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <span>Admin Control Panel</span>
                </div>
            </div>

            <div class="admin-chip">
                <i class="fas fa-user-shield"></i>
                <?php echo htmlspecialchars($adminInfo['name'] ?: 'Administrator', ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <nav class="nav-links">
                <a href="admin_dashboard.php">
                    <i class="fas fa-chart-pie"></i>
                    Dashboard
                </a>
                <a href="admin_faculty.php">
                    <i class="fas fa-user-tie"></i>
                    Faculty
                </a>
                <a href="admin_settings.php" class="active">
                    <i class="fas fa-cog"></i>
                    Settings
                </a>
                <a href="admin_logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </nav>
        </aside>

        <main class="content-card">
            <div class="page-head">
                <div>
                    <div class="eyebrow">Branding Settings</div>
                    <h2>Dynamic Branding</h2>
                    <p>Yahan se admin browser tab ka favicon aur title dono update kar sakta hai. Save karte hi admin login, settings, aur dashboard pages nayi branding use karenge.</p>
                </div>
                <div class="status-badge">
                    <i class="fas fa-image"></i>
                    <?php echo $usingDefaultFavicon ? 'Default Favicon Active' : 'Custom Favicon Active'; ?>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="grid">
                <section class="panel preview-card">
                    <div>
                        <h3>Current Preview</h3>
                        <p>Abhi browser tab me jo favicon dikhna chahiye uska live preview yahan hai.</p>
                    </div>

                    <div class="favicon-preview">
                        <img src="<?php echo htmlspecialchars($currentFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Current favicon preview">
                    </div>

                    <div class="meta-list">
                        <div class="meta-row">
                            <span class="meta-label">Mode</span>
                            <span class="meta-value"><?php echo $usingDefaultFavicon ? 'Default file' : 'Custom upload'; ?></span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">Stored Path</span>
                            <span class="meta-value"><?php echo htmlspecialchars($currentFaviconRelative, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">Browser URL</span>
                            <span class="meta-value"><?php echo htmlspecialchars($currentFaviconUrl, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">Current Title</span>
                            <span class="meta-value"><?php echo htmlspecialchars($currentSiteTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">Title Mode</span>
                            <span class="meta-value"><?php echo $usingDefaultSiteTitle ? 'Default title' : 'Custom title'; ?></span>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h3>Upload New Favicon</h3>
                    <p>Recommended square icon upload karein, jaise `32x32`, `64x64`, ya `128x128`.</p>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="upload-box">
                            <label class="field-label" for="site_favicon">Choose favicon file</label>
                            <input class="file-input" type="file" id="site_favicon" name="site_favicon" accept=".ico,.png,.jpg,.jpeg,.webp" required>
                            <div class="helper-text">
                                Allowed formats: `ICO`, `PNG`, `JPG`, `JPEG`, `WEBP`.<br>
                                Maximum file size: `2 MB`.
                            </div>
                        </div>

                        <div class="actions">
                            <button type="submit" name="save_favicon" class="btn btn-primary">
                                <i class="fas fa-upload"></i>
                                Save Favicon
                            </button>
                            <button type="submit" name="reset_favicon" class="btn btn-danger" onclick="return confirm('Reset favicon to default?');">
                                <i class="fas fa-rotate-left"></i>
                                Reset to Default
                            </button>
                            <a href="admin_dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i>
                                Back to Dashboard
                            </a>
                        </div>
                    </form>

                    <div class="upload-box subsection">
                        <label class="field-label" for="site_title">Browser Title / Site Name</label>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input
                                class="file-input"
                                type="text"
                                id="site_title"
                                name="site_title"
                                maxlength="120"
                                value="<?php echo htmlspecialchars($currentSiteTitle, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Enter site title"
                                required
                            >
                            <div class="helper-text">
                                Ye value browser tab title me use hogi.<br>
                                Example: `Admin Dashboard | <?php echo htmlspecialchars($currentSiteTitle, ENT_QUOTES, 'UTF-8'); ?>`
                            </div>

                            <div class="actions">
                                <button type="submit" name="save_site_title" class="btn btn-primary">
                                    <i class="fas fa-font"></i>
                                    Save Title
                                </button>
                                <button type="submit" name="reset_site_title" class="btn btn-secondary" onclick="return confirm('Reset title to default?');">
                                    <i class="fas fa-rotate-left"></i>
                                    Reset Title
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="tips">
                        <div class="tip">
                            <i class="fas fa-lightbulb"></i>
                            <div>Best result ke liye transparent background wala square icon use karein.</div>
                        </div>
                        <div class="tip">
                            <i class="fas fa-rotate"></i>
                            <div>Save ke baad browser favicon cache ki wajah se change dekhne ke liye hard refresh karna pad sakta hai.</div>
                        </div>
                        <div class="tip">
                            <i class="fas fa-globe"></i>
                            <div>Ye favicon aur title admin login, settings, aur admin dashboard pages par dynamic tarike se render honge.</div>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
