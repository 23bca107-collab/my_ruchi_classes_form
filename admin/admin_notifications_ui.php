<?php

require_once __DIR__ . '/admin_notifications_helper.php';

if (!function_exists('admin_notifications_escape')) {
    function admin_notifications_escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_notifications_prepare')) {
    function admin_notifications_prepare(mysqli $conn, array $admin = [], int $limit = 12): array
    {
        return admin_get_notifications($conn, $admin, $limit);
    }
}

if (!function_exists('admin_notifications_render_widget')) {
    function admin_notifications_render_widget(array $notificationData, string $apiEndpoint = 'admin_notifications_api.php', int $pollIntervalMs = 30000): void
    {
        $notifications = array_values($notificationData['notifications'] ?? []);
        $unreadCount = (int)($notificationData['unread_count'] ?? 0);
        $payload = [
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ];
        $encodedPayload = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $encodedEndpoint = json_encode($apiEndpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $pollIntervalMs = max(10000, $pollIntervalMs);
        ?>
<style>
.admin-notifications-trigger {
    appearance: none;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 16px;
    color: var(--text-secondary, #475569);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 50px;
    min-height: 50px;
    padding: 12px;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease, color 0.2s ease, background 0.2s ease;
    flex-shrink: 0;
}

.admin-notifications-trigger:hover {
    color: var(--primary, #2563eb);
    background: #f8fafc;
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
}

.admin-notifications-trigger:focus-visible {
    outline: none;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.18);
}

.admin-notifications-trigger > i {
    font-size: 1.15rem;
}

.admin-notifications-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #ffffff;
    border: 2px solid #ffffff;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 14px rgba(239, 68, 68, 0.22);
}

.admin-notifications-badge.hidden {
    display: none;
}

.admin-notifications-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.42);
    backdrop-filter: blur(3px);
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.22s ease, visibility 0.22s ease;
    z-index: 2147483000;
}

.admin-notifications-overlay.active {
    opacity: 1;
    visibility: visible;
}

.admin-notifications-panel {
    position: fixed;
    top: 96px;
    right: 24px;
    width: min(380px, calc(100vw - 28px));
    max-height: min(560px, calc(100dvh - 120px));
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.95);
    border-radius: 24px;
    box-shadow: 0 24px 50px rgba(15, 23, 42, 0.18);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translateY(-12px) scale(0.98);
    transform-origin: top right;
    transition: transform 0.22s ease, opacity 0.2s ease, visibility 0s linear 0.22s;
    z-index: 2147483001;
}

.admin-notifications-panel.open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translateY(0) scale(1);
    transition: transform 0.22s ease, opacity 0.2s ease;
}

.admin-notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 1.2rem 1.35rem;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
}

.admin-notifications-title-wrap h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    gap: 8px;
}

.admin-notifications-title-wrap p {
    margin: 6px 0 0;
    color: var(--text-muted, #64748b);
    font-size: 0.82rem;
    line-height: 1.45;
}

.admin-notifications-close {
    appearance: none;
    border: none;
    background: none;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
    flex-shrink: 0;
}

.admin-notifications-close:hover {
    background: #f1f5f9;
    color: #ef4444;
    transform: rotate(90deg);
}

.admin-notifications-list {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem 0;
}

.admin-notifications-item {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 1rem 1.2rem;
    text-decoration: none;
    color: inherit;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.18s ease;
}

.admin-notifications-item:last-child {
    border-bottom: none;
}

.admin-notifications-item:hover {
    background: #f8fafc;
}

.admin-notifications-item.unread {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.02));
}

.admin-notifications-item.unread:hover {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.04));
}

.admin-notifications-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
}

.admin-notifications-icon.theme-primary {
    background: rgba(37, 99, 235, 0.12);
    color: #2563eb;
}

.admin-notifications-icon.theme-success {
    background: rgba(16, 185, 129, 0.12);
    color: #10b981;
}

.admin-notifications-icon.theme-warning {
    background: rgba(245, 158, 11, 0.14);
    color: #f59e0b;
}

.admin-notifications-icon.theme-danger {
    background: rgba(239, 68, 68, 0.12);
    color: #ef4444;
}

.admin-notifications-icon.theme-info {
    background: rgba(6, 182, 212, 0.12);
    color: #06b6d4;
}

.admin-notifications-copy {
    flex: 1;
    min-width: 0;
}

.admin-notifications-copy strong {
    display: block;
    margin-bottom: 5px;
    color: var(--text-primary, #1e293b);
    font-size: 0.94rem;
    line-height: 1.4;
}

.admin-notifications-copy p {
    margin: 0;
    color: var(--text-secondary, #475569);
    font-size: 0.84rem;
    line-height: 1.55;
    word-break: break-word;
}

.admin-notifications-time {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    color: var(--text-muted, #64748b);
    font-size: 0.76rem;
}

.admin-notifications-empty {
    padding: 2rem 1.5rem;
    text-align: center;
    color: var(--text-muted, #64748b);
}

.admin-notifications-empty i {
    display: block;
    font-size: 2rem;
    margin-bottom: 0.8rem;
    opacity: 0.65;
}

body.admin-notifications-open {
    overflow: hidden;
}

@media (max-width: 768px) {
    .admin-notifications-panel {
        top: 86px;
        right: 12px;
        width: calc(100vw - 24px);
        max-height: calc(100dvh - 100px);
    }
}
</style>
<button
    type="button"
    class="admin-notifications-trigger"
    id="adminNotificationsButton"
    title="Notifications"
    aria-haspopup="dialog"
    aria-expanded="false"
    aria-controls="adminNotificationsPanel"
>
    <i class="fas fa-bell" aria-hidden="true"></i>
    <span class="admin-notifications-badge<?php echo $unreadCount > 0 ? '' : ' hidden'; ?>" id="adminNotificationsBadge">
        <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
    </span>
</button>
<div class="admin-notifications-overlay" id="adminNotificationsOverlay" hidden></div>
<div
    class="admin-notifications-panel"
    id="adminNotificationsPanel"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="adminNotificationsTitle"
>
    <div class="admin-notifications-header">
        <div class="admin-notifications-title-wrap">
            <h3 id="adminNotificationsTitle">
                <i class="fas fa-bell" aria-hidden="true"></i>
                Notifications
            </h3>
            <p>Student and teacher activity will appear here automatically.</p>
        </div>
        <button type="button" class="admin-notifications-close" id="adminNotificationsClose" aria-label="Close notifications">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <div class="admin-notifications-list" id="adminNotificationsList">
        <?php if ($notifications !== []): ?>
            <?php foreach ($notifications as $notification): ?>
                <a
                    href="<?php echo admin_notifications_escape($notification['link'] ?? '#'); ?>"
                    class="admin-notifications-item<?php echo !empty($notification['unread']) ? ' unread' : ''; ?>"
                >
                    <div class="admin-notifications-icon theme-<?php echo admin_notifications_escape($notification['icon_theme'] ?? 'info'); ?>">
                        <i class="fas <?php echo admin_notifications_escape($notification['icon'] ?? 'fa-bell'); ?>" aria-hidden="true"></i>
                    </div>
                    <div class="admin-notifications-copy">
                        <strong><?php echo admin_notifications_escape($notification['title'] ?? 'Notification'); ?></strong>
                        <p><?php echo admin_notifications_escape($notification['message'] ?? ''); ?></p>
                        <div class="admin-notifications-time">
                            <i class="far fa-clock" aria-hidden="true"></i>
                            <?php echo admin_notifications_escape($notification['time_label'] ?? 'Just now'); ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="admin-notifications-empty">
                <i class="fas fa-bell-slash" aria-hidden="true"></i>
                <div>No notifications yet</div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('adminNotificationsButton');
    const badge = document.getElementById('adminNotificationsBadge');
    const overlay = document.getElementById('adminNotificationsOverlay');
    const panel = document.getElementById('adminNotificationsPanel');
    const closeButton = document.getElementById('adminNotificationsClose');
    const list = document.getElementById('adminNotificationsList');
    const apiEndpoint = <?php echo $encodedEndpoint ?: '"admin_notifications_api.php"'; ?>;
    let state = <?php echo $encodedPayload ?: '{"unread_count":0,"notifications":[]}'; ?>;
    let isOpen = false;
    let overlayTimer = null;

    if (!button || !overlay || !panel || !list) {
        return;
    }

    function mountLayer() {
        if (overlay.parentElement !== document.body) {
            document.body.appendChild(overlay);
        }

        if (panel.parentElement !== document.body) {
            document.body.appendChild(panel);
        }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderBadge(count) {
        const safeCount = Number(count || 0);
        if (safeCount > 0) {
            badge.textContent = safeCount > 99 ? '99+' : String(safeCount);
            badge.classList.remove('hidden');
        } else {
            badge.textContent = '0';
            badge.classList.add('hidden');
        }
    }

    function renderList(notifications) {
        if (!Array.isArray(notifications) || notifications.length === 0) {
            list.innerHTML = `
                <div class="admin-notifications-empty">
                    <i class="fas fa-bell-slash" aria-hidden="true"></i>
                    <div>No notifications yet</div>
                </div>
            `;
            return;
        }

        list.innerHTML = notifications.map(function (notification) {
            const unreadClass = notification.unread ? ' unread' : '';
            const iconTheme = escapeHtml(notification.icon_theme || 'info');
            const icon = escapeHtml(notification.icon || 'fa-bell');
            const title = escapeHtml(notification.title || 'Notification');
            const message = escapeHtml(notification.message || '');
            const timeLabel = escapeHtml(notification.time_label || 'Just now');
            const link = escapeHtml(notification.link || '#');

            return `
                <a href="${link}" class="admin-notifications-item${unreadClass}">
                    <div class="admin-notifications-icon theme-${iconTheme}">
                        <i class="fas ${icon}" aria-hidden="true"></i>
                    </div>
                    <div class="admin-notifications-copy">
                        <strong>${title}</strong>
                        <p>${message}</p>
                        <div class="admin-notifications-time">
                            <i class="far fa-clock" aria-hidden="true"></i>
                            ${timeLabel}
                        </div>
                    </div>
                </a>
            `;
        }).join('');
    }

    function updateUI(data) {
        state = {
            unread_count: Number((data && data.unread_count) || 0),
            notifications: Array.isArray(data && data.notifications) ? data.notifications : []
        };

        renderBadge(state.unread_count);
        renderList(state.notifications);
    }

    function openPanel() {
        if (isOpen) {
            return;
        }

        window.clearTimeout(overlayTimer);
        isOpen = true;
        overlay.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('admin-notifications-open');

        requestAnimationFrame(function () {
            overlay.classList.add('active');
            panel.classList.add('open');
        });
    }

    function closePanel() {
        if (!isOpen) {
            return;
        }

        isOpen = false;
        overlay.classList.remove('active');
        panel.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('admin-notifications-open');

        overlayTimer = window.setTimeout(function () {
            if (!isOpen) {
                overlay.hidden = true;
            }
        }, 250);
    }

    async function fetchNotifications(options = {}) {
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

            updateUI(data);

            if (markRead && state.unread_count > 0) {
                await markAllRead();
            }
        } catch (error) {
            console.error(error);
        }
    }

    async function markAllRead() {
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

            updateUI(data);
        } catch (error) {
            console.error(error);
        }
    }

    function refreshNotificationsSilently() {
        fetch(apiEndpoint, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Unable to load notifications');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || 'Unable to load notifications');
                }
                updateUI(data);
            })
            .catch(function (error) {
                console.error(error);
            });
    }

    mountLayer();
    updateUI(state);

    button.addEventListener('click', function () {
        if (isOpen) {
            closePanel();
            return;
        }

        openPanel();
        fetchNotifications({ markRead: true });
    });

    if (closeButton) {
        closeButton.addEventListener('click', closePanel);
    }

    overlay.addEventListener('click', closePanel);
    panel.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closePanel();
        }
    });

    window.setInterval(refreshNotificationsSilently, <?php echo (int)$pollIntervalMs; ?>);
});
</script>
        <?php
    }
}
