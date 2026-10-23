<?php
/**
 * All Notifications Page
 * هەموو ئاگادارکردنەوەکان
 */

$pageTitle = 'ئاگادارکردنەوەکان';
$breadcrumb = [['title' => 'ئاگادارکردنەوەکان']];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/classes/Notification.php';

$notification = new Notification();
$userId = $currentUser['id'];

// Financial notification types — visible to admins & accountants only
$adminOnlyTypes = Notification::FINANCIAL_TYPES;
$canSeeFinancial = $isAdmin || $isAccountant;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $notification->markAllAsRead($userId);
        header('Location: ' . APP_URL . '/notifications.php?marked=1');
        exit;
    } elseif ($action === 'delete' && isset($_POST['notification_id'])) {
        $notification->delete(intval($_POST['notification_id']), $userId);
        header('Location: ' . APP_URL . '/notifications.php?deleted=1');
        exit;
    }
}

// Get pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

// Get notifications
$notifications = $notification->getAll($userId, $page, $perPage);

// Filter out financial notifications for other roles
if (!$canSeeFinancial) {
    $notifications = array_filter($notifications, function($n) use ($adminOnlyTypes) {
        return !in_array($n['type'], $adminOnlyTypes);
    });
    $notifications = array_values($notifications); // Re-index array
}

$unreadCount = $notification->getUnreadCount($userId, $canSeeFinancial ? null : $adminOnlyTypes);

// Mark all as seen when viewing this page
$notification->markAllAsSeen($userId);
?>

<style>
.notifications-page {
    max-width: 800px;
    margin: 0 auto;
}

.notifications-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    border-radius: 12px;
    color: white;
}

.notifications-header h2 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.notifications-header .badge {
    background: rgba(255,255,255,0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
}

.mark-all-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.mark-all-btn:hover {
    background: rgba(255,255,255,0.3);
}

.notifications-list {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.notification-card {
    display: flex;
    gap: 16px;
    padding: 18px 20px;
    border-bottom: 1px solid #f1f5f9;
    transition: all 0.2s;
    position: relative;
}

.notification-card:last-child {
    border-bottom: none;
}

.notification-card:hover {
    background: #f8fafc;
}

.notification-card.unread {
    background: linear-gradient(135deg, rgba(0, 120, 212, 0.06) 0%, rgba(0, 120, 212, 0.02) 100%);
}

.notification-card.unread::before {
    content: '';
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, #0078d4 0%, #00a8ff 100%);
}

.notification-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.notification-icon.patient_called {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    color: #1976d2;
}

.notification-icon.patient_waiting {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #388e3c;
}

.notification-icon.xray_ready {
    background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
    color: #7b1fa2;
}

.notification-icon.xray_payment {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    color: #c62828;
}

.notification-icon.case_completed {
    background: linear-gradient(135deg, #e8f5e9 0%, #a5d6a7 100%);
    color: #2e7d32;
}

.notification-icon.system {
    background: linear-gradient(135deg, #eceff1 0%, #cfd8dc 100%);
    color: #546e7a;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-title {
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
    margin-bottom: 4px;
}

.notification-message {
    font-size: 14px;
    color: #64748b;
    line-height: 1.5;
    margin-bottom: 8px;
}

.notification-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 12px;
    color: #94a3b8;
}

.notification-meta i {
    margin-left: 4px;
}

.notification-actions {
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.notification-actions .btn {
    padding: 6px 12px;
    font-size: 12px;
}

.empty-notifications {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.empty-notifications i {
    font-size: 64px;
    margin-bottom: 16px;
    color: #e2e8f0;
}

.empty-notifications h3 {
    margin: 0 0 8px;
    color: #64748b;
    font-size: 18px;
}

.empty-notifications p {
    margin: 0;
    font-size: 14px;
}

.pagination-wrapper {
    display: flex;
    justify-content: center;
    margin-top: 24px;
}
</style>

<div class="notifications-page">
    <?php if (isset($_GET['marked'])): ?>
    <div class="alert alert-success" data-auto-hide>
        <i class="fas fa-check-circle"></i>
        هەموو ئاگادارکردنەوەکان وەک خوێندراوەتەوە نیشانکران
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success" data-auto-hide>
        <i class="fas fa-trash"></i>
        ئاگادارکردنەوە سڕایەوە
    </div>
    <?php endif; ?>

    <div class="notifications-header">
        <h2>
            <i class="fas fa-bell"></i>
            ئاگادارکردنەوەکان
            <?php if ($unreadCount > 0): ?>
            <span class="badge"><?= $unreadCount ?> نەخوێندراوە</span>
            <?php endif; ?>
        </h2>
        <?php if ($unreadCount > 0): ?>
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="mark-all-btn">
                <i class="fas fa-check-double"></i>
                هەموو بخوێنەوە
            </button>
        </form>
        <?php endif; ?>
    </div>

    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
        <div class="empty-notifications">
            <i class="fas fa-bell-slash"></i>
            <h3>هیچ ئاگادارکردنەوەیەک نییە</h3>
            <p>کاتێک ئاگادارکردنەوەی نوێت هەبێت، لێرە دەردەکەوێت</p>
        </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
            <?php
                $data = $notif['data'] ? json_decode($notif['data'], true) : [];
                $iconClass = $notif['type'] ?? 'system';
                $icon = Notification::getTypeIcon($notif['type']);

                // Prefer the stored link; fall back to the type-based route
                $relativeLink = !empty($notif['link'])
                    ? $notif['link']
                    : Notification::resolveLink($notif['type'], $data);
                $link = (strpos($relativeLink, 'http') === 0) ? $relativeLink : APP_URL . $relativeLink;
            ?>
            <div class="notification-card <?= $notif['is_read'] ? '' : 'unread' ?>"
                 onclick="if (window.notificationSystem) { notificationSystem.openDetail(<?= (int)$notif['id'] ?>); this.classList.remove('unread'); } else { window.location.href='<?= htmlspecialchars($link) ?>'; }"
                 style="cursor: pointer;">
                <div class="notification-icon <?= $iconClass ?>">
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                    <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notification-meta">
                        <span>
                            <i class="fas fa-clock"></i>
                            <?= date('Y/m/d - H:i', strtotime($notif['created_at'])) ?>
                        </span>
                        <?php if ($notif['created_by_name']): ?>
                        <span>
                            <i class="fas fa-user"></i>
                            <?= htmlspecialchars($notif['created_by_name']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="notification-actions" onclick="event.stopPropagation();">
                    <a href="<?= $link ?>" class="btn btn-sm btn-primary" title="بینین">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <form method="POST" style="margin: 0;" onsubmit="return confirm('دڵنیایت لە سڕینەوە؟')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="سڕینەوە">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (count($notifications) >= $perPage): ?>
    <div class="pagination-wrapper">
        <div class="btn-group">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary">
                <i class="fas fa-chevron-right"></i> پێشتر
            </a>
            <?php endif; ?>
            <span class="btn btn-light disabled">لاپەڕەی <?= $page ?></span>
            <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary">
                دواتر <i class="fas fa-chevron-left"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
