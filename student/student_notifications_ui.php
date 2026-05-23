<?php

require_once __DIR__ . '/student_notifications_helper.php';

if (!function_exists('student_notifications_escape')) {
    function student_notifications_escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('student_notifications_prepare')) {
    function student_notifications_prepare(mysqli $conn, array $student, int $limit = 12): array
    {
        return student_get_notifications($conn, $student, $limit);
    }
}

if (!function_exists('student_notifications_render_styles')) {
    function student_notifications_render_styles(): void
    {
        ?>
<style>
.notifications {
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
}

.notifications:hover {
  background: var(--bg-secondary, #f8fafc);
  color: var(--text-primary, #1e293b);
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}

.notifications:focus-visible {
  outline: none;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.18);
}

.notifications > i {
  font-size: 1.2rem;
}

.notification-badge {
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

.notification-badge.hidden {
  display: none;
}

.notifications-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.4);
  backdrop-filter: blur(3px);
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.25s ease, visibility 0.25s ease;
  z-index: 9500;
}

.notifications-overlay.active {
  opacity: 1;
  visibility: visible;
}

.notifications-panel {
  position: fixed;
  top: 0;
  right: 0;
  width: min(380px, calc(100vw - 24px));
  max-width: 90vw;
  height: 100dvh;
  background: #fff;
  border-left: 1px solid var(--border, #e2e8f0);
  box-shadow: none;
  z-index: 9600;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateX(calc(100% + 40px));
  transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), opacity 0.2s ease, visibility 0s linear 0.3s;
  border-radius: 24px 0 0 24px;
  overscroll-behavior: contain;
}

.notifications-panel.open {
  box-shadow: -5px 0 30px rgba(15, 23, 42, 0.15);
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateX(0);
  transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), opacity 0.2s ease;
}

.notifications-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding: 1.25rem 1.5rem;
  border-bottom: 2px solid var(--border, #e2e8f0);
  background: rgba(255, 255, 255, 0.98);
}

.notifications-header-copy {
  min-width: 0;
}

.notifications-header h3 {
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

.notifications-header p {
  margin: 6px 0 0;
  color: var(--text-muted, #64748b);
  font-size: 0.82rem;
  line-height: 1.5;
}

.close-notifications {
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

.close-notifications:hover {
  background: #f1f5f9;
  color: var(--danger, #ef4444);
  transform: rotate(90deg);
}

.close-notifications:focus-visible {
  outline: none;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.16);
}

.notification-list {
  flex: 1;
  overflow-y: auto;
  padding: 0.5rem 0;
  -webkit-overflow-scrolling: touch;
}

.notification-item {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f0f2f5;
  text-decoration: none;
  color: inherit;
  transition: background 0.2s ease, transform 0.2s ease;
}

.notification-item:last-child {
  border-bottom: none;
}

.notification-item:hover {
  background: #f8fafc;
}

.notification-item.unread {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.02));
}

.notification-item.unread:hover {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.04));
}

.notification-icon-wrap {
  width: 44px;
  height: 44px;
  min-width: 44px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
}

.notification-icon-wrap.theme-primary {
  background: rgba(37, 99, 235, 0.12);
  color: var(--primary, #2563eb);
}

.notification-icon-wrap.theme-success {
  background: rgba(16, 185, 129, 0.12);
  color: var(--success, #10b981);
}

.notification-icon-wrap.theme-warning {
  background: rgba(245, 158, 11, 0.15);
  color: #b45309;
}

.notification-icon-wrap.theme-danger {
  background: rgba(239, 68, 68, 0.12);
  color: var(--danger, #ef4444);
}

.notification-icon-wrap.theme-info {
  background: rgba(6, 182, 212, 0.12);
  color: var(--info, #06b6d4);
}

.notification-content {
  flex: 1;
  min-width: 0;
}

.notification-title {
  font-weight: 700;
  margin-bottom: 4px;
  color: var(--text-primary, #1e293b);
  font-size: 0.95rem;
  line-height: 1.35;
}

.notification-message {
  font-size: 0.85rem;
  color: var(--text-secondary, #475569);
  line-height: 1.45;
  word-break: break-word;
}

.notification-time {
  font-size: 0.72rem;
  color: var(--text-muted, #64748b);
  margin-top: 6px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.notification-empty {
  text-align: center;
  padding: 3rem 1.5rem;
  color: var(--text-muted, #64748b);
}

.notification-empty i {
  font-size: 2rem;
  opacity: 0.5;
  margin-bottom: 12px;
  display: block;
}

body.notifications-panel-open {
  overflow: hidden !important;
}

@supports (padding: max(0px)) {
  .notifications-panel {
    padding-right: env(safe-area-inset-right, 0px);
  }

  .notifications-header {
    padding-right: max(1rem, calc(1.5rem + env(safe-area-inset-right, 0px)));
  }

  .notification-item {
    padding-right: max(1rem, calc(1.25rem + env(safe-area-inset-right, 0px)));
  }
}

@media (max-width: 768px) {
  .header {
    gap: 12px;
    flex-wrap: wrap;
  }

  .user-menu {
    gap: 12px;
    max-width: 100%;
    flex-shrink: 0;
  }

  .notifications-panel {
    width: min(85vw, 360px);
    max-width: 360px;
  }

  .notifications-header h3 {
    font-size: 1.2rem;
  }

  .notification-item {
    padding: 1rem;
  }
}

@media (max-width: 480px) {
  .notifications {
    padding: 10px;
  }

  .notification-badge {
    top: -5px;
    right: -5px;
    min-width: 18px;
    height: 18px;
    font-size: 10px;
  }

  .notifications-panel {
    width: 100%;
    max-width: 100%;
    border-radius: 20px 0 0 20px;
  }

  .notifications-header {
    padding: 1rem;
  }

  .notifications-header h3 {
    font-size: 1.15rem;
  }

  .notification-title {
    font-size: 0.92rem;
  }

  .notification-message {
    font-size: 0.8rem;
  }
}
</style>
        <?php
    }
}

if (!function_exists('student_notifications_render_button')) {
    function student_notifications_render_button(array $notificationData): void
    {
        $notifications = $notificationData['notifications'] ?? [];
        $unreadCount = (int)($notificationData['unread_count'] ?? 0);
        ?>
<button type="button" class="notifications" id="notificationsButton" title="Notifications" aria-haspopup="dialog" aria-expanded="false" aria-controls="notificationsPanel">
  <i class="fas fa-bell" aria-hidden="true"></i>
  <span class="notification-badge<?php echo $unreadCount > 0 ? '' : ' hidden'; ?>" id="notificationBadge">
    <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
  </span>
</button>
<div class="notifications-overlay" id="notificationsOverlay" hidden></div>
<div class="notifications-panel" id="notificationsPanel" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="notificationsPanelTitle">
  <div class="notifications-header">
    <div class="notifications-header-copy">
      <h3 id="notificationsPanelTitle">
        <i class="fas fa-bell" aria-hidden="true"></i>
        Notifications
      </h3>
      <p>Admin and teacher updates will appear here live.</p>
    </div>
    <button type="button" class="close-notifications" id="closeNotificationsPanel" aria-label="Close notifications">
      <i class="fas fa-times" aria-hidden="true"></i>
    </button>
  </div>
  <div class="notification-list" id="notificationList">
    <?php if ($notifications !== []): ?>
      <?php foreach ($notifications as $notification): ?>
        <a href="<?php echo student_notifications_escape($notification['link'] ?? '#'); ?>" class="notification-item<?php echo !empty($notification['unread']) ? ' unread' : ''; ?>">
          <div class="notification-icon-wrap theme-<?php echo student_notifications_escape($notification['icon_theme'] ?? 'info'); ?>">
            <i class="fas <?php echo student_notifications_escape($notification['icon'] ?? 'fa-bell'); ?>" aria-hidden="true"></i>
          </div>
          <div class="notification-content">
            <div class="notification-title"><?php echo student_notifications_escape($notification['title'] ?? 'Notification'); ?></div>
            <div class="notification-message"><?php echo student_notifications_escape($notification['message'] ?? ''); ?></div>
            <div class="notification-time">
              <i class="far fa-clock" aria-hidden="true"></i>
              <?php echo student_notifications_escape($notification['time_label'] ?? 'Just now'); ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="notification-empty">
        <i class="fas fa-bell-slash" aria-hidden="true"></i>
        <div>No notifications yet</div>
      </div>
    <?php endif; ?>
  </div>
</div>
        <?php
    }
}

if (!function_exists('student_notifications_render_script')) {
    function student_notifications_render_script(array $notificationData, string $apiEndpoint = 'student_notifications_api.php', int $pollIntervalMs = 30000): void
    {
        $payload = [
            'unread_count' => (int)($notificationData['unread_count'] ?? 0),
            'notifications' => array_values($notificationData['notifications'] ?? []),
        ];
        $encodedPayload = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $encodedEndpoint = json_encode($apiEndpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $pollIntervalMs = max(10000, $pollIntervalMs);
        ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const notificationsButton = document.getElementById('notificationsButton');
  if (!notificationsButton) {
    return;
  }

  const notificationBadge = document.getElementById('notificationBadge');
  const notificationsOverlay = document.getElementById('notificationsOverlay');
  const notificationsPanel = document.getElementById('notificationsPanel');
  const closeNotificationsPanel = document.getElementById('closeNotificationsPanel');
  const notificationList = document.getElementById('notificationList');
  const apiEndpoint = <?php echo $encodedEndpoint ?: '"student_notifications_api.php"'; ?>;
  let notificationState = <?php echo $encodedPayload ?: '{"unread_count":0,"notifications":[]}'; ?>;
  let lastUnreadCount = Number(notificationState.unread_count || 0);
  let isPanelOpen = false;
  let overlayHideTimer = null;

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
    const existingToast = document.querySelector('.toast');
    if (existingToast) {
      existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const icon = document.createElement('i');
    icon.className = `fas ${toastIcon(type)}`;

    const text = document.createElement('span');
    text.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(text);
    document.body.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.add('show');
    });

    window.setTimeout(() => {
      toast.classList.remove('show');
      window.setTimeout(() => {
        toast.remove();
      }, 300);
    }, 4000);
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
        <div class="notification-empty">
          <i class="fas fa-bell-slash" aria-hidden="true"></i>
          <div>No notifications yet</div>
        </div>
      `;
      return;
    }

    notificationList.innerHTML = notifications.map(notification => {
      const unreadClass = notification.unread ? ' unread' : '';
      const iconTheme = escapeHtml(notification.icon_theme || 'info');
      const icon = escapeHtml(notification.icon || 'fa-bell');
      const title = escapeHtml(notification.title || 'Notification');
      const message = escapeHtml(notification.message || '');
      const timeLabel = escapeHtml(notification.time_label || 'Just now');
      const link = escapeHtml(notification.link || '#');

      return `
        <a href="${link}" class="notification-item${unreadClass}">
          <div class="notification-icon-wrap theme-${iconTheme}">
            <i class="fas ${icon}" aria-hidden="true"></i>
          </div>
          <div class="notification-content">
            <div class="notification-title">${title}</div>
            <div class="notification-message">${message}</div>
            <div class="notification-time">
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

  function openNotifications() {
    if (!notificationsPanel || !notificationsOverlay || isPanelOpen) {
      return;
    }

    window.clearTimeout(overlayHideTimer);
    isPanelOpen = true;
    notificationsOverlay.hidden = false;
    notificationsButton.setAttribute('aria-expanded', 'true');
    notificationsPanel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('notifications-panel-open');

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
    document.body.classList.remove('notifications-panel-open');

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

  updateNotificationUI(notificationState);
  fetchNotifications();

  notificationsButton.addEventListener('click', function() {
    if (isPanelOpen) {
      closeNotifications();
      return;
    }

    openNotifications();
    fetchNotifications({ markRead: true });
  });

  notificationsButton.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      notificationsButton.click();
    }
  });

  if (closeNotificationsPanel) {
    closeNotificationsPanel.addEventListener('click', closeNotifications);
  }

  if (notificationsOverlay) {
    notificationsOverlay.addEventListener('click', closeNotifications);
  }

  if (notificationsPanel) {
    notificationsPanel.addEventListener('click', function(e) {
      e.stopPropagation();
    });
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeNotifications();
    }
  });

  window.addEventListener('resize', function() {
    document.body.classList.toggle('notifications-panel-open', isPanelOpen);
  });

  window.setInterval(function() {
    fetchNotifications({ announce: true });
  }, <?php echo $pollIntervalMs; ?>);
});
</script>
        <?php
    }
}
