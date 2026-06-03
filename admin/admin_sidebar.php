<?php
declare(strict_types=1);

if (!function_exists('admin_sidebar_escape')) {
    function admin_sidebar_escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_sidebar_full_name')) {
    function admin_sidebar_full_name(array $adminInfo): string
    {
        $firstName = trim((string)($adminInfo['first_name'] ?? ''));
        $lastName = trim((string)($adminInfo['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);

        if ($fullName !== '') {
            return $fullName;
        }

        $name = trim((string)($adminInfo['name'] ?? ''));
        return $name !== '' ? $name : 'Administrator';
    }
}

if (!function_exists('admin_sidebar_role')) {
    function admin_sidebar_role(array $adminInfo): string
    {
        $type = (string)($adminInfo['type'] ?? $adminInfo['admin_type'] ?? '');
        return $type === 'first_admin' ? 'Super Admin' : 'Administrator';
    }
}

if (!function_exists('admin_sidebar_photo_path')) {
    function admin_sidebar_photo_path(array $adminInfo): string
    {
        $photo = trim((string)($adminInfo['photo'] ?? ''));

        if ($photo === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $photo);
        $fileName = basename($normalized);
        $candidates = [
            '../' . ltrim($normalized, '/'),
            ltrim($normalized, '/'),
            '../uploads/admin_photos/' . $fileName,
            'uploads/admin_photos/' . $fileName,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('admin_sidebar_total_videos')) {
    function admin_sidebar_total_videos($conn): int
    {
        if (!$conn instanceof mysqli) {
            return 0;
        }

        $tableResult = @$conn->query("SHOW TABLES LIKE 'youtube_videos'");
        if (!$tableResult || $tableResult->num_rows === 0) {
            return 0;
        }

        $result = @$conn->query('SELECT COUNT(*) AS total_videos FROM youtube_videos');
        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();
        return (int)($row['total_videos'] ?? 0);
    }
}

if (!function_exists('admin_sidebar_active_class')) {
    function admin_sidebar_active_class(string $activePage, string $page): string
    {
        return $activePage === $page ? ' class="active"' : '';
    }
}

if (!function_exists('admin_render_sidebar_styles')) {
    function admin_render_sidebar_styles(): string
    {
        return <<<'HTML'
<style>
    :root {
        --admin-sidebar-primary: #27ae60;
        --admin-sidebar-primary-dark: #229954;
        --admin-sidebar-primary-light: #d5f4e6;
        --admin-sidebar-secondary: #7f8c8d;
        --admin-sidebar-dark: #2c3e50;
        --admin-sidebar-bg: #ffffff;
        --admin-sidebar-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --admin-sidebar-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --admin-sidebar-radius: 12px;
        --admin-sidebar-transition: all 0.3s ease;
    }

    .dashboard-container {
        display: flex;
        min-height: 100vh;
        width: 100%;
        position: relative;
    }

    .mobile-menu-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1002;
        width: 45px;
        height: 45px;
        border: 0;
        border-radius: 10px;
        background: var(--admin-sidebar-primary);
        color: #fff;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        transition: var(--admin-sidebar-transition);
    }

    .mobile-menu-toggle:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
    }

    .mobile-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(5px);
    }

    .mobile-overlay.active {
        display: block;
    }

    .sidebar {
        width: 300px;
        background: var(--admin-sidebar-bg);
        color: var(--admin-sidebar-dark);
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        overflow-y: auto;
        box-shadow: 2px 0 20px rgba(0, 0, 0, 0.08);
        z-index: 1001;
        border-right: 4px solid var(--admin-sidebar-primary);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        scroll-behavior: smooth;
    }

    .sidebar.active {
        transform: translateX(0);
    }

    .logo-container {
        padding: 25px 20px;
        background: linear-gradient(135deg, var(--admin-sidebar-primary), var(--admin-sidebar-primary-dark));
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 20px;
        position: relative;
        overflow: hidden;
    }

    .logo-container::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
    }

    .logo {
        display: flex;
        flex-direction: column;
        align-items: center;
        color: white;
        text-decoration: none;
        position: relative;
        z-index: 1;
    }

    .logo-img {
        width: 80px;
        height: 80px;
        border-radius: 16px;
        overflow: hidden;
        border: 3px solid #fff;
        background: #fff;
        padding: 8px;
        margin-bottom: 15px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        transition: var(--admin-sidebar-transition);
    }

    .logo-img:hover {
        transform: scale(1.05) rotate(3deg);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3);
    }

    .logo-img img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .logo-text h2 {
        font-size: 1.6rem;
        font-weight: 800;
        color: white;
        line-height: 1.2;
        margin-bottom: 8px;
        letter-spacing: 0.5px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .logo-text span {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 500;
        background: rgba(255, 255, 255, 0.1);
        padding: 6px 16px;
        border-radius: 20px;
        display: inline-block;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .profile-card {
        padding: 20px;
        background: #fff;
        margin: 20px;
        border-radius: var(--admin-sidebar-radius);
        box-shadow: var(--admin-sidebar-shadow);
        border: 2px solid var(--admin-sidebar-primary-light);
        text-align: center;
        transition: var(--admin-sidebar-transition);
    }

    .profile-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--admin-sidebar-shadow-lg);
        border-color: var(--admin-sidebar-primary);
    }

    .profile-avatar {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        overflow: hidden;
        margin: 0 auto 15px;
        border: 3px solid var(--admin-sidebar-primary);
        background: var(--admin-sidebar-primary-light);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .profile-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-avatar i {
        font-size: 2.5rem;
        color: var(--admin-sidebar-primary);
    }

    .profile-name {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--admin-sidebar-dark);
        margin-bottom: 5px;
    }

    .profile-email {
        font-size: 0.85rem;
        color: var(--admin-sidebar-secondary);
        margin-bottom: 8px;
        word-break: break-all;
    }

    .profile-role {
        display: inline-block;
        padding: 6px 14px;
        background: linear-gradient(135deg, var(--admin-sidebar-primary), var(--admin-sidebar-primary-dark));
        color: white;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 12px;
        box-shadow: 0 3px 8px rgba(39, 174, 96, 0.3);
    }

    .admin-profile-meta {
        display: flex;
        justify-content: space-around;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 2px solid var(--admin-sidebar-primary-light);
    }

    .admin-meta-item {
        text-align: center;
    }

    .admin-meta-value {
        font-size: 1rem;
        font-weight: 700;
        color: var(--admin-sidebar-primary-dark);
        display: block;
    }

    .admin-meta-label {
        font-size: 0.75rem;
        color: var(--admin-sidebar-secondary);
    }

    .nav-section {
        padding: 20px 0;
    }

    .nav-section h3 {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--admin-sidebar-secondary);
        margin-bottom: 15px;
        padding: 0 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .nav-section h3::before,
    .nav-section h3::after {
        content: '';
        flex: 1;
        height: 2px;
        background: linear-gradient(to right, transparent, var(--admin-sidebar-primary-light));
    }

    .nav-section h3::after {
        background: linear-gradient(to left, transparent, var(--admin-sidebar-primary-light));
    }

    .nav-links {
        list-style: none;
        padding: 0;
        margin: 0;
        display: block;
        padding: 0 10px;
    }

    .nav-links li {
        margin-bottom: 10px;
        position: relative;
    }

    .nav-links a {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 25px;
        color: var(--admin-sidebar-dark);
        text-decoration: none;
        transition: var(--admin-sidebar-transition);
        font-weight: 500;
        border: 1px solid #dbe4ea;
        border-left: 4px solid transparent;
        border-radius: 16px;
        position: relative;
        font-size: 0.95rem;
        background: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.03);
    }

    .nav-links a:hover {
        background: linear-gradient(90deg, rgba(39, 174, 96, 0.08), rgba(39, 174, 96, 0.04));
        color: var(--admin-sidebar-primary-dark);
        border-color: #b7e4ce;
        border-left-color: var(--admin-sidebar-primary);
        padding-left: 28px;
        transform: translateX(4px);
    }

    .nav-links a.active {
        background: linear-gradient(90deg, rgba(39, 174, 96, 0.15), rgba(39, 174, 96, 0.08));
        color: var(--admin-sidebar-primary-dark);
        border-color: #b7e4ce;
        border-left-color: var(--admin-sidebar-primary);
        font-weight: 600;
        box-shadow: inset 0 0 20px rgba(39, 174, 96, 0.05), 0 10px 24px rgba(15, 23, 42, 0.04);
    }

    .nav-links a.active i {
        color: var(--admin-sidebar-primary);
    }

    .nav-links a.active::before {
        content: '';
        position: absolute;
        right: -1px;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 20px;
        background: var(--admin-sidebar-primary);
        border-radius: 3px 0 0 3px;
        box-shadow: -2px 0 8px rgba(39, 174, 96, 0.3);
    }

    .nav-links a i {
        font-size: 1.1rem;
        width: 24px;
        text-align: center;
        color: var(--admin-sidebar-secondary);
        transition: var(--admin-sidebar-transition);
    }

    .nav-links a:hover i {
        color: var(--admin-sidebar-primary);
        transform: scale(1.1);
    }

    .main-content {
        flex: 1;
        margin-left: 300px;
        padding: 20px 30px;
        min-height: 100vh;
        width: calc(100% - 300px);
        overflow-y: auto;
    }

    .admin-page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        width: 100%;
        max-width: 1280px;
        margin: 0 auto 30px;
        padding: 20px 25px;
        background: rgba(255, 255, 255, 0.95);
        border-radius: var(--admin-sidebar-radius);
        box-shadow: var(--admin-sidebar-shadow-lg);
        border: 2px solid #fff;
        flex-wrap: wrap;
    }

    .admin-header-title {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }

    .admin-header-icon {
        width: 45px;
        height: 45px;
        border-radius: 10px;
        background: linear-gradient(135deg, var(--admin-sidebar-primary), var(--admin-sidebar-primary-dark));
        color: white;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        box-shadow: 0 8px 18px rgba(39, 174, 96, 0.18);
        flex: 0 0 auto;
    }

    .admin-header-title h1 {
        font-size: 1.6rem;
        line-height: 1.2;
        color: var(--admin-sidebar-dark);
        margin: 0;
    }

    .admin-header-title p {
        margin: 4px 0 0;
        color: var(--admin-sidebar-secondary);
        font-size: 0.95rem;
    }

    .admin-header-profile {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        border: 2px solid #e0e6ed;
        border-radius: var(--admin-sidebar-radius);
        background: rgba(255, 255, 255, 0.95);
        color: var(--admin-sidebar-dark);
        text-decoration: none;
        box-shadow: var(--admin-sidebar-shadow);
        transition: var(--admin-sidebar-transition);
    }

    .admin-header-profile:hover {
        transform: translateY(-2px);
        border-color: var(--admin-sidebar-primary-light);
        box-shadow: var(--admin-sidebar-shadow-lg);
    }

    .admin-header-avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        overflow: hidden;
        background: var(--admin-sidebar-primary-light);
        color: var(--admin-sidebar-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
    }

    .admin-header-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .admin-header-name {
        font-weight: 800;
        color: var(--admin-sidebar-dark);
        line-height: 1.1;
        text-transform: uppercase;
        letter-spacing: 0;
    }

    .admin-header-role {
        display: inline-block;
        margin-top: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--admin-sidebar-primary-light);
        color: var(--admin-sidebar-primary-dark);
        font-size: 0.8rem;
        font-weight: 700;
    }

    @media (max-width: 1199px) {
        .sidebar {
            width: 280px;
            transform: translateX(-100%);
        }

        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 20px 15px;
        }

        .mobile-menu-toggle {
            display: flex;
        }

        .admin-page-header {
            margin-top: 60px;
            padding: 15px 20px;
        }
    }

    @media (max-width: 768px) {
        .main-content {
            padding: 15px;
        }

        .admin-page-header {
            align-items: stretch;
            flex-direction: column;
            padding: 15px;
        }

        .admin-header-title h1 {
            font-size: 1.3rem;
        }

        .admin-header-profile {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 10px;
        }

        .profile-card {
            margin: 15px;
            padding: 15px;
        }

        .nav-links {
            padding: 0 8px;
        }

        .nav-links a {
            padding: 13px 20px;
        }

        .profile-avatar {
            width: 70px;
            height: 70px;
        }
    }
</style>
HTML;
    }
}

if (!function_exists('admin_render_sidebar')) {
    function admin_render_sidebar(array $adminInfo, $conn, string $activePage = ''): string
    {
        $activePage = $activePage !== '' ? $activePage : basename((string)($_SERVER['PHP_SELF'] ?? ''));
        $adminName = admin_sidebar_full_name($adminInfo);
        $adminEmail = (string)($adminInfo['email'] ?? 'admin@ruchiclasses.com');
        $adminRole = admin_sidebar_role($adminInfo);
        $photoPath = admin_sidebar_photo_path($adminInfo);
        $totalVideos = admin_sidebar_total_videos($conn);

        ob_start();
        ?>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu">
            <i class="fas fa-bars"></i>
        </button>

        <div class="mobile-overlay" id="mobileOverlay"></div>

        <aside class="sidebar" id="sidebar">
            <div class="logo-container">
                <a href="admin_dashboard.php" class="logo">
                    <div class="logo-img">
                        <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes">
                    </div>
                    <div class="logo-text">
                        <h2>RUCHI CLASSES</h2>
                        <span>Administration Portal</span>
                    </div>
                </a>
            </div>

            <div class="profile-card">
                <div class="profile-avatar">
                    <?php if ($photoPath !== ''): ?>
                        <img src="<?php echo admin_sidebar_escape($photoPath); ?>" alt="Profile Photo">
                    <?php else: ?>
                        <i class="fas fa-user-shield"></i>
                    <?php endif; ?>
                </div>
                <div class="profile-name"><?php echo admin_sidebar_escape($adminName); ?></div>
                <div class="profile-email"><?php echo admin_sidebar_escape($adminEmail); ?></div>
                <div class="profile-role"><?php echo admin_sidebar_escape($adminRole); ?></div>
                <div class="admin-profile-meta">
                    <div class="admin-meta-item">
                        <span class="admin-meta-value"><?php echo (int)$totalVideos; ?></span>
                        <span class="admin-meta-label">Videos</span>
                    </div>
                    <div class="admin-meta-item">
                        <span class="admin-meta-value"><?php echo admin_sidebar_escape(date('d/m')); ?></span>
                        <span class="admin-meta-label">Date</span>
                    </div>
                </div>
            </div>

            <nav class="nav-section">
                <h3>Navigation Menu</h3>
                <ul class="nav-links">
                    <li><a href="admin_dashboard.php"<?php echo admin_sidebar_active_class($activePage, 'admin_dashboard.php'); ?>><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="admin_profile_edit.php"<?php echo admin_sidebar_active_class($activePage, 'admin_profile_edit.php'); ?>><i class="fas fa-user-edit"></i> Edit Profile</a></li>
                    <li><a href="manage_teacher.php"<?php echo admin_sidebar_active_class($activePage, 'manage_teacher.php'); ?>><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                    <li><a href="admission_report.php"<?php echo admin_sidebar_active_class($activePage, 'admission_report.php'); ?>><i class="fas fa-user-graduate"></i> Students</a></li>
                    <li><a href="admin_assign_students.php"<?php echo admin_sidebar_active_class($activePage, 'admin_assign_students.php'); ?>><i class="fas fa-users"></i> Assign Students</a></li>
                    <li><a href="admin_complaints.php"<?php echo admin_sidebar_active_class($activePage, 'admin_complaints.php'); ?>><i class="fas fa-comment-dots"></i> Complaints</a></li>
                    <li><a href="add_schedule.php"<?php echo admin_sidebar_active_class($activePage, 'add_schedule.php'); ?>><i class="fas fa-calendar-alt"></i> Schedule</a></li>
                    <li><a href="admin_assign_attendance.php"<?php echo admin_sidebar_active_class($activePage, 'admin_assign_attendance.php'); ?>><i class="fa-solid fa-clipboard-check"></i> Assign Attendance</a></li>
                    <li><a href="admin_videos.php"<?php echo admin_sidebar_active_class($activePage, 'admin_videos.php'); ?>><i class="fas fa-video"></i> Videos</a></li>
                </ul>
            </nav>

            <nav class="nav-section">
                <h3>System Controls</h3>
                <ul class="nav-links">
                    <li><a href="admin_settings.php"<?php echo admin_sidebar_active_class($activePage, 'admin_settings.php'); ?>><i class="fas fa-cog"></i> Settings</a></li>
                    <li><a href="admin_faculty.php"<?php echo admin_sidebar_active_class($activePage, 'admin_faculty.php'); ?>><i class="fas fa-user-tie"></i> Faculty</a></li>
                    <li><a href="admin_logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </nav>
        </aside>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('admin_render_page_header')) {
    function admin_render_page_header(string $title, string $subtitle, string $iconClass, array $adminInfo): string
    {
        $adminName = admin_sidebar_full_name($adminInfo);
        $adminRole = admin_sidebar_role($adminInfo);
        $photoPath = admin_sidebar_photo_path($adminInfo);

        ob_start();
        ?>
        <header class="admin-page-header">
            <div class="admin-header-title">
                <span class="admin-header-icon"><i class="<?php echo admin_sidebar_escape($iconClass); ?>"></i></span>
                <div>
                    <h1><?php echo admin_sidebar_escape($title); ?></h1>
                    <p><?php echo admin_sidebar_escape($subtitle); ?></p>
                </div>
            </div>

            <a href="admin_profile_edit.php" class="admin-header-profile">
                <span class="admin-header-avatar">
                    <?php if ($photoPath !== ''): ?>
                        <img src="<?php echo admin_sidebar_escape($photoPath); ?>" alt="Profile Photo">
                    <?php else: ?>
                        <i class="fas fa-user-shield"></i>
                    <?php endif; ?>
                </span>
                <span>
                    <span class="admin-header-name"><?php echo admin_sidebar_escape($adminName); ?></span>
                    <span class="admin-header-role"><?php echo admin_sidebar_escape($adminRole); ?></span>
                </span>
            </a>
        </header>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('admin_render_sidebar_script')) {
    function admin_render_sidebar_script(): string
    {
        return <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const mainContent = document.getElementById('mainContent');

    if (mobileMenuToggle && sidebar && mobileOverlay) {
        mobileMenuToggle.addEventListener('click', function(event) {
            event.stopPropagation();
            sidebar.classList.toggle('active');
            mobileOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : 'auto';
        });

        mobileOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = 'auto';
        });

        document.querySelectorAll('.nav-links a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth < 1200) {
                    sidebar.classList.remove('active');
                    mobileOverlay.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1200) {
                sidebar.classList.remove('active');
                mobileOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        window.addEventListener('beforeunload', function() {
            localStorage.setItem('sidebarScrollPosition', String(sidebar.scrollTop));
        });

        const savedScrollPosition = localStorage.getItem('sidebarScrollPosition');
        if (savedScrollPosition) {
            setTimeout(function() {
                sidebar.scrollTop = parseInt(savedScrollPosition, 10);
                localStorage.removeItem('sidebarScrollPosition');
            }, 100);
        }
    }

    document.querySelectorAll('.nav-links a.active').forEach(function(link) {
        link.addEventListener('click', function(event) {
            event.preventDefault();
            if (mainContent) {
                mainContent.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
});
</script>
HTML;
    }
}
