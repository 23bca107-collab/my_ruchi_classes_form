<?php

require_once __DIR__ . '/teacher_notifications_helper.php';

if (!function_exists('teacher_notifications_escape')) {
    function teacher_notifications_escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('teacher_notifications_prepare')) {
    function teacher_notifications_prepare(mysqli $conn, array $teacher, int $limit = 12): array
    {
        return teacher_get_notifications($conn, $teacher, $limit);
    }
}

if (!function_exists('teacher_notifications_resolve_profile_data')) {
    function teacher_notifications_resolve_profile_data(array $teacherProfileData = []): array
    {
        $resolved = [
            'id' => (int)($_SESSION['teacher_id'] ?? 0),
            'first_name' => (string)($_SESSION['teacher_first_name'] ?? ''),
            'last_name' => (string)($_SESSION['teacher_last_name'] ?? ''),
            'email' => (string)($_SESSION['teacher_email'] ?? ''),
            'mobile' => (string)($_SESSION['teacher_mobile'] ?? ''),
            'address' => '',
            'subject' => (string)($_SESSION['teacher_subject'] ?? ''),
            'photo' => (string)($_SESSION['teacher_photo'] ?? ''),
            'last_login' => '',
            'profile_completed' => (int)($_SESSION['profile_completed'] ?? 0),
            'update_url' => 'teacher_profile.php',
        ];

        if ($teacherProfileData !== []) {
            $resolved = array_merge($resolved, $teacherProfileData);
        }

        $teacherId = (int)($resolved['id'] ?? 0);
        global $conn;

        if ($teacherId > 0 && isset($conn) && $conn instanceof mysqli) {
            $stmt = $conn->prepare('SELECT id, first_name, last_name, email, mobile, address, subject, photo, last_login, profile_completed FROM teachers WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $teacherId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result && ($row = $result->fetch_assoc())) {
                        $resolved = array_merge($resolved, $row);
                    }
                }
                $stmt->close();
            }
        }

        $resolved['first_name'] = trim((string)($resolved['first_name'] ?? ''));
        $resolved['last_name'] = trim((string)($resolved['last_name'] ?? ''));
        $resolved['email'] = trim((string)($resolved['email'] ?? ''));
        $resolved['mobile'] = trim((string)($resolved['mobile'] ?? ''));
        $resolved['address'] = trim((string)($resolved['address'] ?? ''));
        $resolved['subject'] = trim((string)($resolved['subject'] ?? ''));
        $resolved['photo'] = trim((string)($resolved['photo'] ?? ''));
        $resolved['update_url'] = trim((string)($resolved['update_url'] ?? 'teacher_profile.php')) ?: 'teacher_profile.php';

        $fullName = trim($resolved['first_name'] . ' ' . $resolved['last_name']);
        if ($fullName === '') {
            $fullName = trim((string)($_SESSION['teacher_name'] ?? ''));
        }
        if ($fullName === '') {
            $fullName = $resolved['email'] !== '' ? $resolved['email'] : 'Teacher';
        }

        $initialsSource = $resolved['first_name'] . ' ' . $resolved['last_name'];
        if (trim($initialsSource) === '') {
            $initialsSource = $fullName;
        }

        $initials = '';
        foreach (preg_split('/\s+/', trim($initialsSource)) ?: [] as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }
            if (strlen($initials) >= 2) {
                break;
            }
        }
        if ($initials === '') {
            $initials = 'T';
        }

        $photoFallback = '';
        if ($resolved['photo'] !== '' && !preg_match('~^(?:https?:)?//~i', $resolved['photo'])) {
            $photoFallback = '../' . ltrim($resolved['photo'], './');
        }

        $lastLoginLabel = '';
        if (!empty($resolved['last_login'])) {
            $timestamp = strtotime((string)$resolved['last_login']);
            if ($timestamp !== false) {
                $lastLoginLabel = date('d M Y, h:i A', $timestamp);
            }
        }

        $resolved['id'] = $teacherId;
        $resolved['full_name'] = $fullName;
        $resolved['initials'] = $initials;
        $resolved['photo_fallback'] = $photoFallback;
        $resolved['last_login_label'] = $lastLoginLabel;
        $resolved['role_label'] = $resolved['subject'] !== '' ? ($resolved['subject'] . ' Teacher') : 'Teacher';

        return $resolved;
    }
}

if (!function_exists('teacher_notifications_render_styles')) {
    function teacher_notifications_render_styles(): void
    {
        ?>
<style>
.teacher-notifications-trigger {
  appearance: none;
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 14px;
  color: var(--text-secondary, #475569);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 48px;
  min-height: 48px;
  padding: 12px;
  position: relative;
  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, color 0.2s ease;
  font: inherit;
  flex-shrink: 0;
}

.teacher-notifications-trigger:hover {
  background: var(--bg-secondary, #f8fafc);
  color: var(--text-primary, #1e293b);
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}

.teacher-notifications-trigger:focus-visible {
  outline: none;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.18);
}

.teacher-notifications-trigger > i {
  font-size: 1.2rem;
}

.teacher-notifications-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  background: var(--danger, #ef4444);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  min-width: 20px;
  height: 20px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 5px;
  border: 2px solid #fff;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
}

.teacher-notifications-badge.hidden {
  display: none;
}

.teacher-notifications-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.4);
  backdrop-filter: blur(3px);
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.25s ease, visibility 0.25s ease;
  z-index: 2147483000;
}

.teacher-notifications-overlay.active {
  opacity: 1;
  visibility: visible;
}

.teacher-notifications-panel {
  position: fixed;
  top: var(--teacher-notifications-top, 96px);
  right: var(--teacher-notifications-right, 24px);
  width: min(360px, calc(100vw - 32px));
  max-width: calc(100vw - 32px);
  max-height: min(520px, calc(100dvh - var(--teacher-notifications-top, 96px) - 24px));
  background: #fff;
  border: 1px solid var(--border, #e2e8f0);
  z-index: 2147483001;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateY(-12px) scale(0.98);
  transform-origin: top right;
  transition: transform 0.22s ease, opacity 0.2s ease, visibility 0s linear 0.22s;
  border-radius: 24px;
  isolation: isolate;
}

.teacher-notifications-panel.open {
  box-shadow: 0 24px 50px rgba(15, 23, 42, 0.18);
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateY(0) scale(1);
  transition: transform 0.22s ease, opacity 0.2s ease;
}

.teacher-notifications-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding: 1.25rem 1.5rem;
  border-bottom: 2px solid var(--border, #e2e8f0);
  background: rgba(255, 255, 255, 0.98);
}

.teacher-notifications-header-copy {
  min-width: 0;
}

.teacher-notifications-header h3 {
  font-size: 1.35rem;
  font-weight: 700;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  color: transparent;
  background: var(--gradient-primary, linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%));
  -webkit-background-clip: text;
  background-clip: text;
}

.teacher-notifications-header p {
  margin: 6px 0 0;
  color: var(--text-muted, #64748b);
  font-size: 0.82rem;
  line-height: 1.5;
}

.teacher-notifications-close {
  appearance: none;
  background: none;
  border: none;
  color: var(--text-muted, #64748b);
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 1.15rem;
  transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
  flex-shrink: 0;
}

.teacher-notifications-close:hover {
  background: #f1f5f9;
  color: var(--danger, #ef4444);
  transform: rotate(90deg);
}

.teacher-notifications-list {
  flex: 1;
  overflow-y: auto;
  padding: 0.5rem 0;
  -webkit-overflow-scrolling: touch;
}

.teacher-notifications-item {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f0f2f5;
  text-decoration: none;
  color: inherit;
  transition: background 0.2s ease, transform 0.2s ease;
}

.teacher-notifications-item:last-child {
  border-bottom: none;
}

.teacher-notifications-item:hover {
  background: #f8fafc;
}

.teacher-notifications-item.unread {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.02));
}

.teacher-notifications-item.unread:hover {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.04));
}

.teacher-notifications-icon {
  width: 44px;
  height: 44px;
  min-width: 44px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
}

.teacher-notifications-icon.theme-primary {
  background: rgba(37, 99, 235, 0.12);
  color: var(--primary, #2563eb);
}

.teacher-notifications-icon.theme-success {
  background: rgba(16, 185, 129, 0.12);
  color: var(--success, #10b981);
}

.teacher-notifications-icon.theme-warning {
  background: rgba(245, 158, 11, 0.14);
  color: var(--warning, #f59e0b);
}

.teacher-notifications-icon.theme-danger {
  background: rgba(239, 68, 68, 0.12);
  color: var(--danger, #ef4444);
}

.teacher-notifications-icon.theme-info {
  background: rgba(6, 182, 212, 0.12);
  color: var(--info, #06b6d4);
}

.teacher-notifications-content {
  min-width: 0;
  flex: 1;
}

.teacher-notifications-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--text-primary, #1e293b);
  line-height: 1.4;
  margin-bottom: 4px;
}

.teacher-notifications-message {
  font-size: 13px;
  color: var(--text-secondary, #475569);
  line-height: 1.55;
  word-break: break-word;
}

.teacher-notifications-time {
  font-size: 12px;
  color: var(--text-muted, #64748b);
  white-space: nowrap;
  margin-top: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.teacher-notifications-empty {
  padding: 30px 22px;
  text-align: center;
  color: var(--text-muted, #64748b);
}

.teacher-notifications-empty i {
  font-size: 34px;
  margin-bottom: 12px;
  opacity: 0.65;
}

body.teacher-notifications-open {
  overflow: hidden;
}

.profile-menu {
  display: none;
  flex-direction: column;
  position: fixed;
  top: var(--teacher-profile-menu-top, 96px);
  left: var(--teacher-profile-menu-left, auto);
  right: var(--teacher-profile-menu-right, 24px);
  bottom: auto;
  width: min(320px, calc(100vw - 24px));
  max-width: calc(100vw - 24px);
  max-height: min(420px, calc(100dvh - 32px));
  background: #fff;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 24px;
  box-shadow: 0 24px 50px rgba(15, 23, 42, 0.18);
  z-index: 2147483002;
  overflow: hidden;
  padding: 0;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateY(-12px) scale(0.98);
  transform-origin: top right;
  transition: transform 0.22s ease, opacity 0.2s ease, visibility 0s linear 0.22s;
  isolation: isolate;
}

.profile-menu.open {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateY(0) scale(1);
  transition: transform 0.22s ease, opacity 0.2s ease;
}

.profile-menu-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border, #e2e8f0);
  background: rgba(255, 255, 255, 0.98);
  flex-shrink: 0;
}

.profile-menu-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--text-primary, #1e293b);
}

.profile-menu-actions {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-height: 0;
  padding: 0.5rem 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}

.profile-menu-summary {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 1.1rem 1.25rem 1rem;
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(29, 78, 216, 0.03));
  border-bottom: 1px solid rgba(37, 99, 235, 0.08);
}

.profile-menu-avatar-shell {
  width: 72px;
  height: 72px;
  border-radius: 22px;
  overflow: hidden;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.14), rgba(59, 130, 246, 0.08));
  border: 2px solid rgba(37, 99, 235, 0.16);
  box-shadow: 0 12px 24px rgba(37, 99, 235, 0.12);
}

.profile-menu-avatar {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.profile-menu-initials {
  font-size: 1.55rem;
  font-weight: 800;
  color: var(--primary, #2563eb);
  letter-spacing: 0.04em;
}

.profile-menu-meta {
  min-width: 0;
}

.profile-menu-meta h4 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 800;
  color: var(--text-primary, #1e293b);
  line-height: 1.3;
}

.profile-menu-role,
.profile-menu-status {
  margin-top: 4px;
  color: var(--text-secondary, #475569);
  font-size: 0.9rem;
}

.profile-menu-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  background: rgba(16, 185, 129, 0.12);
  color: var(--success, #10b981);
  font-size: 0.78rem;
  font-weight: 700;
}

.profile-menu-details {
  display: grid;
  gap: 10px;
  padding: 1rem 1.25rem;
}

.profile-menu-detail {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 16px;
  background: #fff;
}

.profile-menu-detail-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: rgba(37, 99, 235, 0.1);
  color: var(--primary, #2563eb);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.profile-menu-detail-copy {
  min-width: 0;
}

.profile-menu-detail-label {
  display: block;
  font-size: 0.76rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text-muted, #64748b);
  margin-bottom: 4px;
}

.profile-menu-detail-value {
  display: block;
  font-size: 0.93rem;
  font-weight: 600;
  color: var(--text-primary, #1e293b);
  line-height: 1.45;
  word-break: break-word;
}

.profile-menu-action-grid {
  display: grid;
  gap: 10px;
  padding: 0 1.25rem 1.25rem;
  margin-top: auto;
}

.profile-menu-action {
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 0.95rem 1rem !important;
  border-radius: 16px;
  font-size: 0.94rem;
  font-weight: 700;
  border: 1px solid transparent;
  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, color 0.2s ease;
}

.profile-menu-action:hover {
  transform: translateY(-1px);
}

.profile-menu-action-primary {
  background: var(--gradient-primary, linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%));
  color: #fff !important;
  box-shadow: 0 16px 28px rgba(37, 99, 235, 0.18);
}

.profile-menu-action-primary:hover {
  background: var(--gradient-primary, linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%));
  color: #fff !important;
}

.profile-menu-action-danger {
  background: rgba(239, 68, 68, 0.08);
  color: var(--danger, #ef4444) !important;
  border-color: rgba(239, 68, 68, 0.12);
}

.profile-menu-action-danger:hover {
  background: rgba(239, 68, 68, 0.12) !important;
  color: var(--danger, #ef4444) !important;
}

.profile-menu-close {
  appearance: none;
  background: none;
  border: none;
  color: var(--text-muted, #64748b);
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 1.05rem;
  transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
  flex-shrink: 0;
}

.profile-menu-close:hover {
  background: #f1f5f9;
  color: var(--danger, #ef4444);
  transform: rotate(90deg);
}

.profile-menu a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0.95rem 1.25rem;
  text-decoration: none;
  color: var(--text-primary, #1e293b);
  transition: background 0.2s ease, color 0.2s ease;
}

.profile-menu a i {
  width: 18px;
  text-align: center;
}

.profile-menu a:hover {
  background: var(--bg-hover, #f8fafc);
}

.profile-menu a[data-profile-action="logout"] {
  color: var(--danger, #ef4444);
}

.profile-menu hr {
  margin: 0;
  border: 0;
  border-top: 1px solid var(--border, #e2e8f0);
}

body.teacher-profile-menu-open {
  overflow: hidden;
}

@media (min-width: 769px) {
  .teacher-notifications-overlay {
    background: transparent;
    backdrop-filter: none;
  }

  body.teacher-notifications-open {
    overflow: auto;
  }

  body.teacher-profile-menu-open {
    overflow: auto;
  }
}

@media (max-width: 768px) {
  .teacher-notifications-overlay {
    background: rgba(15, 23, 42, 0.4);
    backdrop-filter: blur(3px);
  }

  .teacher-notifications-panel {
    top: 0;
    right: 0;
    width: 100%;
    max-width: 100%;
    height: 100dvh;
    max-height: 100dvh;
    border-left: 1px solid var(--border, #e2e8f0);
    border-top: none;
    border-right: none;
    border-bottom: none;
    border-radius: 20px 0 0 20px;
    transform: translateX(calc(100% + 40px));
    transform-origin: center right;
    transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), opacity 0.2s ease, visibility 0s linear 0.3s;
  }

  .teacher-notifications-panel.open {
    transform: translateX(0);
    transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), opacity 0.2s ease;
  }

  .profile-menu {
    top: auto !important;
    left: 12px !important;
    right: 12px !important;
    bottom: max(12px, env(safe-area-inset-bottom, 0px)) !important;
    width: auto;
    max-width: none;
    max-height: min(340px, calc(100dvh - 24px));
    border-radius: 24px;
    transform: translateY(16px);
    transform-origin: center bottom;
  }

  .profile-menu.open {
    transform: translateY(0);
  }

  .profile-menu-summary {
    flex-direction: column;
    text-align: center;
    align-items: center;
  }

  .profile-menu-status {
    justify-content: center;
  }
}

@media (max-width: 1024px) {
  .sidebar {
    max-width: min(320px, calc(100vw - 32px)) !important;
  }

  .main-content,
  #mainContent {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
  }
}

@media (max-width: 768px) {
  html,
  body {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden !important;
  }

  body {
    position: relative;
  }

  img,
  svg,
  canvas,
  video,
  iframe {
    max-width: 100%;
    height: auto;
  }

  .main-content,
  #mainContent {
    padding: 1rem !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
  }

  .header,
  .page-header,
  .page-title,
  .dashboard-section,
  .welcome-banner,
  .teacher-profile-card,
  .teacher-profile-content,
  .table-container,
  .table-wrapper,
  .students-table-container,
  .filter-form,
  .filter-buttons,
  .form-grid,
  .action-buttons,
  .mobile-action-buttons,
  .stats-container,
  .section-header,
  .card-header,
  .history-card,
  .attendance-card,
  .content-card,
  .form-card,
  .teacher-info-badge {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
  }

  .page-header,
  .welcome-banner,
  .teacher-profile-content,
  .section-header,
  .filter-buttons,
  .action-buttons,
  .mobile-action-buttons {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 12px !important;
  }

  .header {
    height: auto !important;
    min-height: unset !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
  }

  .stats-container,
  .form-grid {
    grid-template-columns: 1fr !important;
  }

  .table-container,
  .table-wrapper,
  .students-table-container {
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
  }

  .table-container table,
  .table-wrapper table,
  .students-table-container table {
    min-width: 640px !important;
    width: max-content !important;
  }

  #mainContent input,
  #mainContent select,
  #mainContent textarea,
  #mainContent .form-control,
  #mainContent .btn,
  #mainContent button {
    max-width: 100% !important;
  }

  #mainContent [style*="min-width"]:not(table):not(th):not(td) {
    min-width: 0 !important;
  }

  #mainContent [style*="max-width"] {
    max-width: 100% !important;
  }

  .page-title h1,
  .page-header h1,
  .page-title {
    font-size: clamp(1.5rem, 6vw, 2rem) !important;
  }

  .page-title p,
  .page-header p {
    font-size: 0.95rem !important;
  }

  .user-menu {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-end !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
    width: auto !important;
    margin-left: auto !important;
  }

  .teacher-info-badge {
    justify-content: center !important;
    flex-wrap: wrap !important;
    text-align: center;
  }

  .user-profile {
    display: inline-flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 12px !important;
    width: auto !important;
    max-width: 100% !important;
    padding: 10px 14px !important;
  }
}

@media (max-width: 480px) {
  .main-content,
  #mainContent {
    padding: 0.85rem !important;
  }

  .table-container table,
  .table-wrapper table,
  .students-table-container table {
    min-width: 560px !important;
  }
}
</style>
        <?php
    }
}

if (!function_exists('teacher_notifications_render_button')) {
    function teacher_notifications_render_button(array $notificationData): void
    {
        $notifications = $notificationData['notifications'] ?? [];
        $unreadCount = (int)($notificationData['unread_count'] ?? 0);
        ?>
<button type="button" class="teacher-notifications-trigger" id="teacherNotificationsButton" title="Notifications" aria-haspopup="dialog" aria-expanded="false" aria-controls="teacherNotificationsPanel">
  <i class="fas fa-bell" aria-hidden="true"></i>
  <span class="teacher-notifications-badge<?php echo $unreadCount > 0 ? '' : ' hidden'; ?>" id="teacherNotificationsBadge">
    <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
  </span>
</button>
<div class="teacher-notifications-overlay" id="teacherNotificationsOverlay" hidden></div>
<div class="teacher-notifications-panel" id="teacherNotificationsPanel" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="teacherNotificationsPanelTitle">
  <div class="teacher-notifications-header">
    <div class="teacher-notifications-header-copy">
      <h3 id="teacherNotificationsPanelTitle">
        <i class="fas fa-bell" aria-hidden="true"></i>
        Notifications
      </h3>
      <p>Admin updates will appear here automatically.</p>
    </div>
    <button type="button" class="teacher-notifications-close" id="teacherNotificationsClose" aria-label="Close notifications">
      <i class="fas fa-times" aria-hidden="true"></i>
    </button>
  </div>
  <div class="teacher-notifications-list" id="teacherNotificationsList">
    <?php if ($notifications !== []): ?>
      <?php foreach ($notifications as $notification): ?>
        <a href="<?php echo teacher_notifications_escape($notification['link'] ?? '#'); ?>" class="teacher-notifications-item<?php echo !empty($notification['unread']) ? ' unread' : ''; ?>">
          <div class="teacher-notifications-icon theme-<?php echo teacher_notifications_escape($notification['icon_theme'] ?? 'primary'); ?>">
            <i class="fas <?php echo teacher_notifications_escape($notification['icon'] ?? 'fa-bell'); ?>" aria-hidden="true"></i>
          </div>
          <div class="teacher-notifications-content">
            <div class="teacher-notifications-title"><?php echo teacher_notifications_escape($notification['title'] ?? 'Notification'); ?></div>
            <div class="teacher-notifications-message"><?php echo teacher_notifications_escape($notification['message'] ?? ''); ?></div>
            <div class="teacher-notifications-time">
              <i class="far fa-clock" aria-hidden="true"></i>
              <?php echo teacher_notifications_escape($notification['time_label'] ?? 'Just now'); ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="teacher-notifications-empty">
        <i class="fas fa-bell-slash" aria-hidden="true"></i>
        <div>No notifications yet</div>
      </div>
    <?php endif; ?>
  </div>
</div>
        <?php
    }
}

if (!function_exists('teacher_notifications_render_script')) {
    function teacher_notifications_render_script(array $notificationData, array $teacherProfileData = [], string $apiEndpoint = 'teacher_notifications_api.php', int $pollIntervalMs = 30000): void
    {
        $payload = [
            'unread_count' => (int)($notificationData['unread_count'] ?? 0),
            'notifications' => array_values($notificationData['notifications'] ?? []),
        ];
        $profilePayload = teacher_notifications_resolve_profile_data($teacherProfileData);
        $encodedPayload = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $encodedProfilePayload = json_encode($profilePayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $encodedEndpoint = json_encode($apiEndpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $pollIntervalMs = max(10000, $pollIntervalMs);
        ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const notificationsButton = document.getElementById('teacherNotificationsButton');
  const notificationBadge = document.getElementById('teacherNotificationsBadge');
  const notificationsOverlay = document.getElementById('teacherNotificationsOverlay');
  const notificationsPanel = document.getElementById('teacherNotificationsPanel');
  const closeNotificationsPanel = document.getElementById('teacherNotificationsClose');
  const notificationList = document.getElementById('teacherNotificationsList');
  const profileMenu = document.getElementById('profileMenu');
  const initialProfileTrigger = document.querySelector('.user-profile');
  const apiEndpoint = <?php echo $encodedEndpoint ?: '"teacher_notifications_api.php"'; ?>;
  let notificationState = <?php echo $encodedPayload ?: '{"unread_count":0,"notifications":[]}'; ?>;
  const profileState = <?php echo $encodedProfilePayload ?: '{"full_name":"Teacher","role_label":"Teacher","update_url":"teacher_profile.php"}'; ?>;
  let lastUnreadCount = Number(notificationState.unread_count || 0);
  let isPanelOpen = false;
  let overlayHideTimer = null;
  let profileHideTimer = null;
  let activeProfileTrigger = null;
  const mobileMediaQuery = window.matchMedia('(max-width: 768px)');

  function isProfileMenuOpen() {
    if (!profileMenu) {
      return false;
    }

    const isVisible = profileMenu.classList.contains('open') && profileMenu.style.display !== 'none';
    if (!isVisible && profileMenu.style.display === 'none') {
      profileMenu.classList.remove('open');
      profileMenu.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('teacher-profile-menu-open');
    }

    return isVisible;
  }

  function normalizeViewportMeta() {
    const viewportMeta = document.querySelector('meta[name="viewport"]');
    const content = 'width=device-width, initial-scale=1, viewport-fit=cover';

    if (viewportMeta) {
      viewportMeta.setAttribute('content', content);
      return;
    }

    const createdMeta = document.createElement('meta');
    createdMeta.name = 'viewport';
    createdMeta.content = content;
    document.head.appendChild(createdMeta);
  }

  function mountNotificationsLayer() {
    if (notificationsOverlay && notificationsOverlay.parentElement !== document.body) {
      document.body.appendChild(notificationsOverlay);
    }

    if (notificationsPanel && notificationsPanel.parentElement !== document.body) {
      document.body.appendChild(notificationsPanel);
    }
  }

  function buildProfileDetailRows() {
    const rows = [
      { icon: 'fa-envelope', label: 'Email', value: profileState.email || 'Not added yet' },
      { icon: 'fa-phone', label: 'Mobile', value: profileState.mobile || 'Not added yet' },
      { icon: 'fa-book-open', label: 'Subject', value: profileState.subject || 'Not added yet' }
    ];

    if (profileState.address) {
      rows.push({ icon: 'fa-location-dot', label: 'Address', value: profileState.address });
    }

    rows.push({
      icon: 'fa-clock',
      label: 'Last Login',
      value: profileState.last_login_label || 'Not available'
    });

    return rows;
  }

  function buildProfileMenuMarkup() {
    const fullName = escapeHtml(profileState.full_name || 'Teacher');
    const roleLabel = escapeHtml(profileState.role_label || 'Teacher');
    const initials = escapeHtml(profileState.initials || 'T');
    const updateUrl = escapeHtml(profileState.update_url || 'teacher_profile.php');
    const statusText = profileState.profile_completed ? 'Saved in database' : 'Profile incomplete';
    const photoSrc = escapeHtml(profileState.photo || '');
    const photoFallback = escapeHtml(profileState.photo_fallback || '');
    const detailsHtml = buildProfileDetailRows().map(function(detail) {
      return `
        <div class="profile-menu-detail">
          <div class="profile-menu-detail-icon">
            <i class="fas ${escapeHtml(detail.icon)}" aria-hidden="true"></i>
          </div>
          <div class="profile-menu-detail-copy">
            <span class="profile-menu-detail-label">${escapeHtml(detail.label)}</span>
            <span class="profile-menu-detail-value">${escapeHtml(detail.value)}</span>
          </div>
        </div>
      `;
    }).join('');

    const avatarHtml = photoSrc !== ''
      ? `
        <div class="profile-menu-avatar-shell">
          <img
            src="${photoSrc}"
            alt="${fullName}"
            class="profile-menu-avatar"
            data-fallback-src="${photoFallback}"
            data-profile-initials="${initials}"
          >
        </div>
      `
      : `
        <div class="profile-menu-avatar-shell">
          <div class="profile-menu-initials">${initials}</div>
        </div>
      `;

    return `
      <div class="profile-menu-summary">
        ${avatarHtml}
        <div class="profile-menu-meta">
          <h4>${fullName}</h4>
          <div class="profile-menu-role">${roleLabel}</div>
          <div class="profile-menu-status">
            <i class="fas fa-database" aria-hidden="true"></i>
            ${escapeHtml(statusText)}
          </div>
        </div>
      </div>
      <div class="profile-menu-details">
        ${detailsHtml}
      </div>
      <div class="profile-menu-action-grid">
        <a href="${updateUrl}" class="profile-menu-action profile-menu-action-primary" data-profile-action="update">
          <i class="fas fa-pen-to-square" aria-hidden="true"></i>
          Update Profile
        </a>
        <a href="#" class="profile-menu-action profile-menu-action-danger" data-profile-action="logout">
          <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
          Logout
        </a>
      </div>
    `;
  }

  function fallbackProfileAvatar(imageElement) {
    if (!imageElement) {
      return;
    }

    const shell = imageElement.closest('.profile-menu-avatar-shell');
    if (!shell) {
      return;
    }

    const fallbackSrc = imageElement.dataset.fallbackSrc || '';
    if (fallbackSrc && imageElement.dataset.fallbackApplied !== 'true') {
      imageElement.dataset.fallbackApplied = 'true';
      imageElement.src = fallbackSrc;
      return;
    }

    imageElement.remove();
    if (!shell.querySelector('.profile-menu-initials')) {
      const fallbackBadge = document.createElement('div');
      fallbackBadge.className = 'profile-menu-initials';
      fallbackBadge.textContent = imageElement.dataset.profileInitials || 'T';
      shell.appendChild(fallbackBadge);
    }
  }

  function bindProfileMenuActions() {
    if (!profileMenu) {
      return;
    }

    profileMenu.querySelectorAll('.profile-menu-avatar').forEach(function(imageElement) {
      if (!imageElement.dataset.profileBound) {
        imageElement.dataset.profileBound = 'true';
        imageElement.addEventListener('error', function() {
          fallbackProfileAvatar(imageElement);
        });
      }

      if (imageElement.complete && imageElement.naturalWidth === 0) {
        fallbackProfileAvatar(imageElement);
      }
    });

    const logoutLink = profileMenu.querySelector('[data-profile-action="logout"]');
    if (logoutLink && !logoutLink.dataset.profileBound) {
      logoutLink.dataset.profileBound = 'true';
      logoutLink.addEventListener('click', function(event) {
        event.preventDefault();
        closeProfileMenu();
        if (typeof window.confirmLogout === 'function') {
          window.confirmLogout(event);
          return;
        }
        window.location.href = 'teacher_logout.php';
      });
    }
  }

  function upgradeProfileMenu() {
    if (!profileMenu) {
      return;
    }

    profileMenu.classList.add('profile-menu');
    profileMenu.setAttribute('role', 'dialog');
    profileMenu.setAttribute('aria-modal', 'false');
    profileMenu.setAttribute('aria-label', 'Profile menu');
    profileMenu.setAttribute('aria-hidden', 'true');
    profileMenu.style.display = 'none';
    profileMenu.style.position = 'fixed';
    profileMenu.style.padding = '0';
    profileMenu.style.minWidth = '';
    profileMenu.style.background = '#fff';
    profileMenu.style.borderRadius = '24px';
    profileMenu.style.boxShadow = '';
    profileMenu.style.left = '';
    profileMenu.style.right = '';
    profileMenu.style.top = '';
    profileMenu.style.bottom = '';
    profileMenu.style.zIndex = '2147483002';

    if (!profileMenu.querySelector('.profile-menu-actions')) {
      const actionsWrapper = document.createElement('div');
      actionsWrapper.className = 'profile-menu-actions';

      while (profileMenu.firstChild) {
        actionsWrapper.appendChild(profileMenu.firstChild);
      }

      profileMenu.appendChild(actionsWrapper);
    }

    const actionsWrapper = profileMenu.querySelector('.profile-menu-actions');
    if (actionsWrapper) {
      actionsWrapper.innerHTML = buildProfileMenuMarkup();
    }

    if (!profileMenu.querySelector('.profile-menu-header')) {
      const menuHeader = document.createElement('div');
      menuHeader.className = 'profile-menu-header';
      menuHeader.innerHTML = `
        <div class="profile-menu-title">Profile Menu</div>
        <button type="button" class="profile-menu-close" aria-label="Close profile menu">
          <i class="fas fa-times" aria-hidden="true"></i>
        </button>
      `;
      profileMenu.insertBefore(menuHeader, profileMenu.firstChild);
    }

    const closeButton = profileMenu.querySelector('.profile-menu-close');
    if (closeButton && !closeButton.dataset.bound) {
      closeButton.dataset.bound = 'true';
      closeButton.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();
        closeProfileMenu();
      });
    }

    if (profileMenu.parentElement !== document.body) {
      document.body.appendChild(profileMenu);
    }

    bindProfileMenuActions();
  }

  function syncProfileMenuPosition(triggerElement = activeProfileTrigger || initialProfileTrigger) {
    if (!profileMenu) {
      return;
    }

    if (mobileMediaQuery.matches) {
      profileMenu.style.removeProperty('--teacher-profile-menu-left');
      profileMenu.style.removeProperty('--teacher-profile-menu-right');
      profileMenu.style.removeProperty('--teacher-profile-menu-top');
      return;
    }

    const fallbackLeft = Math.max(16, Math.round(window.innerWidth - 300 - 24));
    const menuWidth = Math.min(320, Math.max(260, window.innerWidth - 32));

    if (!triggerElement) {
      profileMenu.style.setProperty('--teacher-profile-menu-top', '96px');
      profileMenu.style.setProperty('--teacher-profile-menu-left', `${fallbackLeft}px`);
      profileMenu.style.setProperty('--teacher-profile-menu-right', 'auto');
      return;
    }

    const rect = triggerElement.getBoundingClientRect();
    const panelTop = Math.max(16, Math.round(rect.bottom + 14));
    const panelLeft = Math.min(
      Math.max(16, Math.round(rect.right - menuWidth)),
      Math.max(16, window.innerWidth - menuWidth - 16)
    );

    profileMenu.style.setProperty('--teacher-profile-menu-top', `${panelTop}px`);
    profileMenu.style.setProperty('--teacher-profile-menu-left', `${panelLeft}px`);
    profileMenu.style.setProperty('--teacher-profile-menu-right', 'auto');
  }

  function openProfileMenu(triggerElement = initialProfileTrigger) {
    if (!profileMenu) {
      return;
    }

    activeProfileTrigger = triggerElement || initialProfileTrigger || null;
    closeNotifications();
    window.clearTimeout(profileHideTimer);
    upgradeProfileMenu();
    syncProfileMenuPosition(activeProfileTrigger);
    profileMenu.style.display = 'flex';
    profileMenu.setAttribute('aria-hidden', 'false');

    if (mobileMediaQuery.matches) {
      document.body.classList.add('teacher-profile-menu-open');
    }

    requestAnimationFrame(() => {
      profileMenu.classList.add('open');
    });
  }

  function closeProfileMenu() {
    if (!profileMenu) {
      return;
    }

    profileMenu.classList.remove('open');
    profileMenu.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('teacher-profile-menu-open');

    window.clearTimeout(profileHideTimer);
    profileHideTimer = window.setTimeout(() => {
      if (!profileMenu.classList.contains('open')) {
        profileMenu.style.display = 'none';
      }
    }, 220);
  }

  window.closeProfileMenu = closeProfileMenu;
  window.toggleProfileMenu = function(event) {
    const triggerElement = event && event.currentTarget ? event.currentTarget : (document.querySelector('.user-profile') || activeProfileTrigger);

    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    if (!profileMenu) {
      return;
    }

    if (isProfileMenuOpen()) {
      closeProfileMenu();
      return;
    }

    openProfileMenu(triggerElement);
  };

  window.showProfileMenu = function(event) {
    window.toggleProfileMenu(event);
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function toastIcon(type) {
    switch (type) {
      case 'success':
        return 'fa-check-circle';
      case 'error':
        return 'fa-exclamation-circle';
      case 'warning':
        return 'fa-exclamation-triangle';
      default:
        return 'fa-info-circle';
    }
  }

  function showNotificationToast(message, type = 'info') {
    const existingToast = document.querySelector('.teacher-notifications-toast');
    if (existingToast) {
      existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `teacher-notifications-toast teacher-notifications-toast-${type}`;
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.padding = '16px 20px';
    toast.style.background = '#fff';
    toast.style.borderRadius = '14px';
    toast.style.boxShadow = '0 15px 35px rgba(0,0,0,0.2)';
    toast.style.display = 'flex';
    toast.style.alignItems = 'center';
    toast.style.gap = '12px';
    toast.style.zIndex = '10050';
    toast.style.transform = 'translateX(150%)';
    toast.style.transition = 'transform 0.35s ease';

    const icon = document.createElement('i');
    icon.className = `fas ${toastIcon(type)}`;

    const text = document.createElement('span');
    text.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(text);
    document.body.appendChild(toast);

    requestAnimationFrame(() => {
      toast.style.transform = 'translateX(0)';
    });

    window.setTimeout(() => {
      toast.style.transform = 'translateX(150%)';
      window.setTimeout(() => toast.remove(), 350);
    }, 3500);
  }

  function renderNotificationBadge(count) {
    if (!notificationBadge) {
      return;
    }

    const safeCount = Number(count || 0);
    if (safeCount > 0) {
      notificationBadge.textContent = safeCount > 99 ? '99+' : String(safeCount);
      notificationBadge.classList.remove('hidden');
    } else {
      notificationBadge.textContent = '0';
      notificationBadge.classList.add('hidden');
    }
  }

  function renderNotifications(notifications) {
    if (!notificationList) {
      return;
    }

    if (!Array.isArray(notifications) || notifications.length === 0) {
      notificationList.innerHTML = `
        <div class="teacher-notifications-empty">
          <i class="fas fa-bell-slash" aria-hidden="true"></i>
          <div>No notifications yet</div>
        </div>
      `;
      return;
    }

    notificationList.innerHTML = notifications.map(notification => {
      const unreadClass = notification.unread ? ' unread' : '';
      const iconTheme = escapeHtml(notification.icon_theme || 'primary');
      const icon = escapeHtml(notification.icon || 'fa-bell');
      const title = escapeHtml(notification.title || 'Notification');
      const message = escapeHtml(notification.message || '');
      const timeLabel = escapeHtml(notification.time_label || 'Just now');
      const link = escapeHtml(notification.link || '#');

      return `
        <a href="${link}" class="teacher-notifications-item${unreadClass}">
          <div class="teacher-notifications-icon theme-${iconTheme}">
            <i class="fas ${icon}" aria-hidden="true"></i>
          </div>
          <div class="teacher-notifications-content">
            <div class="teacher-notifications-title">${title}</div>
            <div class="teacher-notifications-message">${message}</div>
            <div class="teacher-notifications-time">
              <i class="far fa-clock" aria-hidden="true"></i>
              ${timeLabel}
            </div>
          </div>
        </a>
      `;
    }).join('');
  }

  function updateNotificationUI(data) {
    notificationState = {
      unread_count: Number((data && data.unread_count) || 0),
      notifications: Array.isArray(data && data.notifications) ? data.notifications : []
    };

    renderNotificationBadge(notificationState.unread_count);
    renderNotifications(notificationState.notifications);
  }

  function syncNotificationsPanelPosition() {
    if (!notificationsButton || !notificationsPanel || mobileMediaQuery.matches) {
      if (notificationsPanel) {
        notificationsPanel.style.removeProperty('--teacher-notifications-top');
        notificationsPanel.style.removeProperty('--teacher-notifications-right');
      }
      return;
    }

    const rect = notificationsButton.getBoundingClientRect();
    const panelTop = Math.max(16, Math.round(rect.bottom + 14));
    const panelRight = Math.max(16, Math.round(window.innerWidth - rect.right));

    notificationsPanel.style.setProperty('--teacher-notifications-top', `${panelTop}px`);
    notificationsPanel.style.setProperty('--teacher-notifications-right', `${panelRight}px`);
  }

  function openNotifications() {
    if (!notificationsPanel || !notificationsOverlay || isPanelOpen) {
      return;
    }

    closeProfileMenu();
    syncNotificationsPanelPosition();
    window.clearTimeout(overlayHideTimer);
    isPanelOpen = true;
    notificationsOverlay.hidden = false;
    notificationsButton.setAttribute('aria-expanded', 'true');
    notificationsPanel.setAttribute('aria-hidden', 'false');
    if (mobileMediaQuery.matches) {
      document.body.classList.add('teacher-notifications-open');
    }

    requestAnimationFrame(() => {
      notificationsOverlay.classList.add('active');
      notificationsPanel.classList.add('open');
    });
  }

  function closeNotifications() {
    if (!notificationsPanel || !notificationsOverlay || !isPanelOpen) {
      return;
    }

    isPanelOpen = false;
    notificationsOverlay.classList.remove('active');
    notificationsPanel.classList.remove('open');
    notificationsButton.setAttribute('aria-expanded', 'false');
    notificationsPanel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('teacher-notifications-open');

    overlayHideTimer = window.setTimeout(() => {
      if (!isPanelOpen) {
        notificationsOverlay.hidden = true;
      }
    }, 250);
  }

  async function fetchNotifications(options = {}) {
    const announce = Boolean(options.announce);
    const markRead = Boolean(options.markRead);

    try {
      const response = await fetch(apiEndpoint, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
      });

      if (!response.ok) {
        throw new Error('Unable to load notifications');
      }

      const data = await response.json();
      if (!data.success) {
        throw new Error(data.message || 'Unable to load notifications');
      }

      const previousUnread = lastUnreadCount;
      updateNotificationUI(data);

      if (announce && notificationState.unread_count > previousUnread) {
        const newCount = notificationState.unread_count - previousUnread;
        showNotificationToast(`${newCount} new notification${newCount > 1 ? 's' : ''} received`, 'info');
      }

      lastUnreadCount = notificationState.unread_count;

      if (markRead && notificationState.unread_count > 0) {
        await markAllNotificationsRead();
      }
    } catch (error) {
      console.error(error);
    }
  }

  async function markAllNotificationsRead() {
    try {
      const response = await fetch(apiEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({ action: 'mark_all_read' })
      });

      if (!response.ok) {
        throw new Error('Unable to mark notifications as read');
      }

      const data = await response.json();
      if (!data.success) {
        throw new Error(data.message || 'Unable to mark notifications as read');
      }

      updateNotificationUI(data);
      lastUnreadCount = notificationState.unread_count;
    } catch (error) {
      console.error(error);
    }
  }

  normalizeViewportMeta();
  upgradeProfileMenu();

  if (notificationsButton) {
    updateNotificationUI(notificationState);
    mountNotificationsLayer();
    fetchNotifications();

    notificationsButton.addEventListener('click', function() {
      if (isPanelOpen) {
        closeNotifications();
        return;
      }

      openNotifications();
      fetchNotifications({ markRead: true });
    });
  }

  if (closeNotificationsPanel) {
    closeNotificationsPanel.addEventListener('click', closeNotifications);
  }

  if (notificationsOverlay) {
    notificationsOverlay.addEventListener('click', closeNotifications);
  }

  document.addEventListener('click', function(event) {
    const target = event.target;
    if (profileMenu && isProfileMenuOpen()) {
      if (profileMenu.contains(target) || target.closest('.user-profile')) {
        return;
      }

      closeProfileMenu();
    }

    if (!notificationsPanel || !notificationsButton || !isPanelOpen || mobileMediaQuery.matches) {
      return;
    }

    if (notificationsPanel.contains(target) || notificationsButton.contains(target)) {
      return;
    }

    closeNotifications();
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeProfileMenu();
      closeNotifications();
    }
  });

  window.addEventListener('resize', function() {
    syncNotificationsPanelPosition();
    if (!mobileMediaQuery.matches) {
      document.body.classList.remove('teacher-notifications-open');
    }

    if (isProfileMenuOpen()) {
      syncProfileMenuPosition();
      if (!mobileMediaQuery.matches) {
        document.body.classList.remove('teacher-profile-menu-open');
      }
    }
  });

  window.addEventListener('scroll', function() {
    if (isPanelOpen) {
      syncNotificationsPanelPosition();
    }

    if (isProfileMenuOpen() && !mobileMediaQuery.matches) {
      syncProfileMenuPosition();
    }
  }, { passive: true });

  if (notificationsButton) {
    window.setInterval(function() {
      fetchNotifications({ announce: true });
    }, <?php echo $pollIntervalMs; ?>);
  }
});
</script>
        <?php
    }
}
