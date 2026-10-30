/**
 * Real-time Notification System
 * سیستەمی ئاگادارکردنەوەی ڕاستەوخۆ
 *
 * - Polls the API for new notifications (badge, toast, sound)
 * - Dropdown with latest read/unread items
 * - Detail modal with "open related page" action
 * - Sound plays once per new batch; user can toggle it off
 */

class NotificationSystem {
    constructor(options = {}) {
        this.pollInterval = options.pollInterval || 10000; // 10 seconds
        this.apiUrl = options.apiUrl || (window.APP_URL || '/dentHisab') + '/api/notifications.php';
        this.baseUrl = window.APP_URL || '/dentHisab';
        this.soundEnabled = options.soundEnabled !== false;
        this.toastEnabled = options.toastEnabled !== false;
        this.lastCheck = null;
        this.firstPoll = true; // suppress toast/sound flood on page load
        this.pollTimer = null;
        this.isPolling = false;
        this.audioUnlocked = false;
        this.items = new Map(); // id -> notification (for dropdown clicks)

        this.notificationSound = new Audio(this.baseUrl + '/assets/sounds/notification.wav');
        this.notificationSound.preload = 'auto';

        this.init();
    }

    init() {
        this.createToastContainer();
        this.createDetailModal();
        this.bindEvents();
        this.startPolling();
        this.loadSettings();
    }

    // ---------- helpers ----------

    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    createToastContainer() {
        if (!document.getElementById('notification-toast-container')) {
            const container = document.createElement('div');
            container.id = 'notification-toast-container';
            container.className = 'notification-toast-container';
            document.body.appendChild(container);
        }
    }

    createDetailModal() {
        if (document.getElementById('notifDetailOverlay')) return;
        const overlay = document.createElement('div');
        overlay.className = 'dialog-overlay';
        overlay.id = 'notifDetailOverlay';
        overlay.innerHTML = `
            <div class="dialog" style="max-width: 420px;">
                <div class="dialog-header">
                    <h3 class="dialog-title" id="notifDetailTitle">ئاگادارکردنەوە</h3>
                    <button class="dialog-close" type="button" id="notifDetailClose">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="dialog-body" id="notifDetailBody"></div>
                <div class="dialog-footer" style="display:flex; gap:8px; justify-content:flex-start;">
                    <a class="btn btn-primary" id="notifDetailOpenLink" style="display:none;">
                        <i class="fas fa-external-link-alt"></i> کردنەوەی پەڕەی پەیوەندیدار
                    </a>
                    <button class="btn" type="button" id="notifDetailCloseBtn">داخستن</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.closeDetailModal();
        });
        overlay.querySelector('#notifDetailClose').addEventListener('click', () => this.closeDetailModal());
        overlay.querySelector('#notifDetailCloseBtn').addEventListener('click', () => this.closeDetailModal());
    }

    closeDetailModal() {
        const overlay = document.getElementById('notifDetailOverlay');
        if (overlay) overlay.classList.remove('open');
    }

    bindEvents() {
        // Bell icon click
        const bellIcon = document.getElementById('notification-bell');
        if (bellIcon) {
            bellIcon.addEventListener('click', () => this.toggleDropdown());
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const dropdown = document.getElementById('notification-dropdown');
            const bell = document.getElementById('notification-bell');
            if (dropdown && !dropdown.contains(e.target) && !bell?.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Mark all as read button
        const markAllBtn = document.getElementById('mark-all-read');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.markAllAsRead();
            });
        }

        // Sound on/off toggle
        const soundBtn = document.getElementById('notification-sound-toggle');
        if (soundBtn) {
            soundBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleSound();
            });
        }

        // Browsers block audio before the first user gesture — unlock silently
        const unlock = () => {
            if (this.audioUnlocked) return;
            this.audioUnlocked = true;
            const s = this.notificationSound;
            s.muted = true;
            s.play().then(() => { s.pause(); s.currentTime = 0; s.muted = false; })
                    .catch(() => { s.muted = false; });
            document.removeEventListener('click', unlock);
            document.removeEventListener('keydown', unlock);
        };
        document.addEventListener('click', unlock);
        document.addEventListener('keydown', unlock);

        // Pause polling while the tab is hidden (saves server load)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopPolling();
            } else {
                this.startPolling();
            }
        });
    }

    async loadSettings() {
        try {
            const response = await fetch(`${this.apiUrl}?action=get_settings`);
            const data = await response.json();
            if (data.success) {
                this.soundEnabled = data.settings.sound_enabled == 1;
                this.toastEnabled = data.settings.toast_enabled == 1;
                this.renderSoundToggle();
            }
        } catch (error) {
            console.error('Error loading notification settings:', error);
        }
    }

    renderSoundToggle() {
        const soundBtn = document.getElementById('notification-sound-toggle');
        if (!soundBtn) return;
        soundBtn.innerHTML = this.soundEnabled
            ? '<i class="fas fa-volume-up"></i>'
            : '<i class="fas fa-volume-mute"></i>';
        soundBtn.title = this.soundEnabled ? 'دەنگ کوژاندنەوە' : 'دەنگ هەڵکردن';
        soundBtn.style.opacity = this.soundEnabled ? '1' : '0.5';
    }

    async toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        this.renderSoundToggle();
        try {
            await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_settings&sound_enabled=${this.soundEnabled ? 1 : 0}&toast_enabled=${this.toastEnabled ? 1 : 0}`
            });
        } catch (error) {
            console.error('Error saving sound setting:', error);
        }
        if (this.soundEnabled) this.playSound(); // audible feedback
    }

    // ---------- polling ----------

    startPolling() {
        if (this.isPolling) return;
        this.isPolling = true;
        this.poll();
        this.pollTimer = setInterval(() => this.poll(), this.pollInterval);
    }

    stopPolling() {
        this.isPolling = false;
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    async poll() {
        try {
            let url = `${this.apiUrl}?action=poll`;
            if (this.lastCheck) {
                url += `&last_check=${encodeURIComponent(this.lastCheck)}`;
            }

            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                this.lastCheck = data.server_time;
                this.updateBadge(data.unseen_count);

                const fresh = data.notifications || [];

                // First poll after page load only sets the baseline —
                // no toast/sound replay of things that happened while away.
                if (this.firstPoll) {
                    this.firstPoll = false;
                    return;
                }

                if (fresh.length > 0) {
                    fresh.forEach(n => this.showToast(n));
                    // One sound per batch, not per notification
                    if (this.soundEnabled) this.playSound();
                }
            }
        } catch (error) {
            console.error('Notification poll error:', error);
        }
    }

    updateBadge(count) {
        const badge = document.getElementById('notification-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    // ---------- toast ----------

    showToast(notification) {
        if (!this.toastEnabled) return;

        const container = document.getElementById('notification-toast-container');
        if (!container) return;

        this.items.set(Number(notification.id), notification);

        const color = /^#[0-9a-fA-F]{3,8}$/.test(notification.color || '') ? notification.color : '#0078d4';
        const toast = document.createElement('div');
        toast.className = 'notification-toast';
        toast.innerHTML = `
            <div class="notification-toast-icon" style="background-color: ${color}20; color: ${color}">
                <i class="fas ${this.escapeHtml(notification.icon)}"></i>
            </div>
            <div class="notification-toast-content">
                <div class="notification-toast-title">${this.escapeHtml(notification.title)}</div>
                <div class="notification-toast-message">${this.escapeHtml(notification.message)}</div>
                <div class="notification-toast-time">${this.escapeHtml(notification.time_ago)}</div>
            </div>
            <button class="notification-toast-close" type="button">
                <i class="fas fa-times"></i>
            </button>`;

        toast.querySelector('.notification-toast-close').addEventListener('click', (e) => {
            e.stopPropagation();
            toast.remove();
        });
        toast.addEventListener('click', () => {
            this.openDetail(notification.id);
            toast.remove();
        });

        container.appendChild(toast);

        // Auto remove after 8 seconds
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 8000);
    }

    playSound() {
        try {
            this.notificationSound.currentTime = 0;
            this.notificationSound.play().catch(() => {});
        } catch (error) {
            // Silently fail if sound can't play
        }
    }

    // ---------- dropdown ----------

    async toggleDropdown() {
        const dropdown = document.getElementById('notification-dropdown');
        if (!dropdown) return;

        const isShowing = dropdown.classList.toggle('show');

        if (isShowing) {
            // The bell counts what is new: once the list is open, nothing is
            this.updateBadge(0);
            this.markAllAsSeen();
            await this.loadNotifications();
        }
    }

    async loadNotifications() {
        const list = document.getElementById('notification-list');
        if (!list) return;

        list.innerHTML = '<div class="notification-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...</div>';

        try {
            const response = await fetch(`${this.apiUrl}?action=get_recent`);
            const data = await response.json();

            if (data.success) {
                if (data.notifications.length === 0) {
                    list.innerHTML = `
                        <div class="notification-empty">
                            <i class="fas fa-bell-slash"></i>
                            <p>هیچ ئاگادارییەک نییە</p>
                        </div>`;
                } else {
                    list.innerHTML = '';
                    data.notifications.forEach(n => {
                        this.items.set(Number(n.id), n);
                        list.appendChild(this.renderNotificationItem(n));
                    });
                }
            }
        } catch (error) {
            list.innerHTML = '<div class="notification-error">هەڵە ڕوویدا</div>';
        }
    }

    renderNotificationItem(notification) {
        const item = document.createElement('div');
        item.className = `notification-item ${Number(notification.is_read) ? 'read' : 'unread'}`;
        item.dataset.id = notification.id;

        const color = /^#[0-9a-fA-F]{3,8}$/.test(notification.color || '') ? notification.color : '#0078d4';
        item.innerHTML = `
            <div class="notification-item-icon" style="background-color: ${color}20; color: ${color}">
                <i class="fas ${this.escapeHtml(notification.icon)}"></i>
            </div>
            <div class="notification-item-content">
                <div class="notification-item-title">${this.escapeHtml(notification.title)}</div>
                <div class="notification-item-message">${this.escapeHtml(notification.message)}</div>
                <div class="notification-item-time">${this.escapeHtml(notification.time_ago)}</div>
            </div>`;

        item.addEventListener('click', () => this.openDetail(notification.id));
        return item;
    }

    // ---------- detail modal ----------

    async openDetail(notificationId) {
        try {
            const response = await fetch(`${this.apiUrl}?action=get_detail&id=${encodeURIComponent(notificationId)}`);
            const data = await response.json();
            if (!data.success) return;

            const n = data.notification;
            this.updateBadge(data.unseen_count);

            // Reflect read state in the open dropdown, if visible
            const item = document.querySelector(`.notification-item[data-id="${CSS.escape(String(n.id))}"]`);
            if (item) {
                item.classList.remove('unread');
                item.classList.add('read');
            }

            const overlay = document.getElementById('notifDetailOverlay');
            if (!overlay) return;

            overlay.querySelector('#notifDetailTitle').textContent = n.title;

            const color = /^#[0-9a-fA-F]{3,8}$/.test(n.color || '') ? n.color : '#0078d4';
            let html = `
                <div class="notif-detail-head">
                    <div class="notification-item-icon" style="background-color: ${color}20; color: ${color}; width:44px; height:44px;">
                        <i class="fas ${this.escapeHtml(n.icon)}"></i>
                    </div>
                    <div>
                        <span class="badge" style="background:${color}20; color:${color};">${this.escapeHtml(data.module)}</span>
                    </div>
                </div>
                <p class="notif-detail-message">${this.escapeHtml(n.message).replace(/\n/g, '<br>')}</p>
                <div class="notif-detail-meta">`;

            (data.related || []).forEach(row => {
                html += `
                    <div class="notif-detail-row">
                        <span class="notif-detail-label">${this.escapeHtml(row.label)}</span>
                        <span class="notif-detail-value">${this.escapeHtml(row.value)}</span>
                    </div>`;
            });

            html += `
                    <div class="notif-detail-row">
                        <span class="notif-detail-label">کات</span>
                        <span class="notif-detail-value">${this.escapeHtml(data.created_at_formatted)} (${this.escapeHtml(n.time_ago)})</span>
                    </div>`;

            if (n.created_by_name) {
                html += `
                    <div class="notif-detail-row">
                        <span class="notif-detail-label">لە لایەن</span>
                        <span class="notif-detail-value">${this.escapeHtml(n.created_by_name)}</span>
                    </div>`;
            }
            html += '</div>';

            overlay.querySelector('#notifDetailBody').innerHTML = html;

            // "Open related page" button
            const openBtn = overlay.querySelector('#notifDetailOpenLink');
            if (n.link) {
                const url = n.link.startsWith('http') ? n.link : this.baseUrl + n.link;
                openBtn.href = url;
                openBtn.style.display = '';
            } else {
                openBtn.style.display = 'none';
            }

            // Close the dropdown behind the modal
            const dropdown = document.getElementById('notification-dropdown');
            if (dropdown) dropdown.classList.remove('show');

            overlay.classList.add('open');
        } catch (error) {
            console.error('Error opening notification detail:', error);
        }
    }

    // ---------- mark read / seen ----------

    async markAsRead(notificationId) {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=mark_read&notification_id=${encodeURIComponent(notificationId)}`
            });
            const data = await response.json();
            if (data.success && typeof data.unseen_count !== 'undefined') {
                this.updateBadge(data.unseen_count);
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

    async markAllAsRead() {
        try {
            await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            });
            this.updateBadge(0);
            await this.loadNotifications();
        } catch (error) {
            console.error('Error marking all notifications as read:', error);
        }
    }

    async markAllAsSeen() {
        try {
            await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_seen'
            });
        } catch (error) {
            console.error('Error marking notifications as seen:', error);
        }
    }
}

// Initialize notification system when DOM is ready
let notificationSystem;
document.addEventListener('DOMContentLoaded', function() {
    notificationSystem = new NotificationSystem({
        pollInterval: 10000, // 10 seconds
        apiUrl: window.APP_URL ? window.APP_URL + '/api/notifications.php' : '/dentHisab/api/notifications.php'
    });
});
