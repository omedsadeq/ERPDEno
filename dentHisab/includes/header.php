<?php
/**
 * Header Include File - Windows 11 Desktop App Style
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Notification.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();
$isReceptionist = $auth->isReceptionist();
$isAccountant = $auth->isAccountant();
$hasFullAccess = $isAdmin || $isReceptionist; // Receptionist has full access like admin

// Get page title
$pageTitle = $pageTitle ?? 'داشبۆرد';

// Get pending notifications count
$db = Database::getInstance();

// Accounting-section visibility — driven by the role-permissions page so the
// header badge and the sidebar menu always agree (admin always; plus any role
// granted the accountant pages). Previously the header badge used a hard-coded
// role check and ignored role-permissions.php.
require_once __DIR__ . '/../classes/Permission.php';
$permission = Permission::getInstance();
$canSeeAccounting = $permission->can('page.accountant_dashboard')
    || $permission->can('page.accountant_reports')
    || $permission->can('page.accountant_financial');

$appFontScale = 1.0;
try {
    if ($db->tableExists('settings')) {
        $fontScaleRow = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'app_font_scale' LIMIT 1");
        $fontScaleValue = isset($fontScaleRow['setting_value']) ? (float)$fontScaleRow['setting_value'] : 1.0;
        if ($fontScaleValue >= 1.25) {
            $appFontScale = 1.3;
        } elseif ($fontScaleValue >= 1.08) {
            $appFontScale = 1.15;
        } else {
            $appFontScale = 1.0;
        }
    }
} catch (Throwable $e) {
    $appFontScale = 1.0;
}
$bodyClasses = [];
if ($appFontScale > 1.0) {
    $bodyClasses[] = 'app-font-scaled';
}
$pendingPayments = 0;
$overdueInstallments = 0;
$waitingCount = 0;

if ($isAdmin) {
    $pendingPayments = $db->count('payments', "status = 'pending'");
    $sql = "SELECT COUNT(*) as count FROM installment_schedule WHERE status IN ('pending', 'partial', 'overdue') AND due_date < CURDATE()";
    $overdueInstallments = $db->fetch($sql)['count'];
}

$pendingAccountingReports = 0;
if ($canSeeAccounting && $db->tableExists('accounting_reports')) {
    $pendingAccountingReports = $db->count('accounting_reports', "status = 'pending'");
}

$waitingCount = $db->count('waiting_room', "status = 'waiting'");

// Today's appointments count
$todayAppointments = $db->count('appointments', "DATE(appointment_date) = CURDATE() AND status IN ('scheduled', 'confirmed')");

// X-Ray payments awaiting
$xrayPaymentsCount = $db->count('xray_requests', "status = 'completed' AND payment_status = 'pending'");

// Pending X-Ray requests
$xrayRequestsCount = $db->count('xray_requests', "status IN ('pending', 'in_progress')");

$totalNotifications = $pendingPayments + $overdueInstallments;

// Real-time notification badge = unread count
// (financial types are visible to admins & accountants only)
$notificationObj = new Notification();
$realtimeNotifCount = $notificationObj->getUnreadCount(
    $currentUser['id'],
    ($isAdmin || $isAccountant) ? null : Notification::FINANCIAL_TYPES
);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>

    <!-- PWA -->
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <meta name="theme-color" content="#0078D4">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Lezan Dental">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/images/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= APP_URL ?>/assets/images/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= APP_URL ?>/assets/images/icons/favicon-16.png">
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(function () { /* SW optional */ });
        });
    }
    </script>

    <!-- CSRF: inject the session token into every form and AJAX request -->
    <script>
    (function () {
        var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        window.CSRF_TOKEN = CSRF;

        function addTokenToForm(form) {
            if (!form || form.method.toLowerCase() !== 'post') return;
            if (form.querySelector('input[name="csrf_token"]')) return;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = CSRF;
            form.appendChild(input);
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('form').forEach(addTokenToForm);
        });
        // Forms created/submitted dynamically
        document.addEventListener('submit', function (e) {
            if (e.target && e.target.tagName === 'FORM') addTokenToForm(e.target);
        }, true);
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            addTokenToForm(this);
            return nativeSubmit.apply(this, arguments);
        };

        // fetch(): add X-CSRF-Token header on same-origin non-GET requests
        var nativeFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || (input && input.method) || 'GET').toUpperCase();
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            var sameOrigin = !/^https?:\/\//i.test(url) || url.indexOf(window.location.origin) === 0;
            if (method !== 'GET' && sameOrigin) {
                if (init.body instanceof FormData) {
                    if (!init.body.has('csrf_token')) init.body.append('csrf_token', CSRF);
                }
                init.headers = init.headers || {};
                if (init.headers instanceof Headers) {
                    if (!init.headers.has('X-CSRF-Token')) init.headers.set('X-CSRF-Token', CSRF);
                } else if (!init.headers['X-CSRF-Token']) {
                    init.headers['X-CSRF-Token'] = CSRF;
                }
            }
            return nativeFetch.call(this, input, init);
        };

        // XMLHttpRequest: same header
        var nativeOpen = XMLHttpRequest.prototype.open;
        var nativeSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this._csrfNeeded = String(method).toUpperCase() !== 'GET'
                && (!/^https?:\/\//i.test(url) || String(url).indexOf(window.location.origin) === 0);
            return nativeOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function (body) {
            if (this._csrfNeeded) {
                try { this.setRequestHeader('X-CSRF-Token', CSRF); } catch (e) {}
            }
            return nativeSend.apply(this, arguments);
        };
    })();
    </script>

    <!-- App Font - NRT (local, /fonts — single global config in fonts.css) -->
    <link rel="preload" href="<?= APP_URL ?>/fonts/NRT%20Font/NRT-Reg.woff" as="font" type="font/woff" crossorigin>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Main CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

    <!-- Responsive Layer (loaded after style.css to override where needed) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/responsive.css">

    <!-- Custom Page CSS -->
    <?php if (isset($customCSS)): ?>
    <style><?= $customCSS ?></style>
    <?php endif; ?>

    <?php if ($appFontScale > 1.0): ?>
    <style id="app-font-scale-setting">
        :root { --app-font-scale: <?= htmlspecialchars(number_format($appFontScale, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>; }
    </style>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars(implode(' ', $bodyClasses), ENT_QUOTES, 'UTF-8') ?>">
    <!-- Windows 11 Desktop Application Window -->
    <div class="app-window">

        <!-- Title Bar -->
        <div class="titlebar">
            <div class="titlebar-drag-region">
                <img src="<?= APP_URL ?>/assets/images/logo.png" alt="" class="titlebar-icon" onerror="this.style.display='none'">
                <span class="titlebar-title"><?= APP_NAME ?> - <?= $pageTitle ?></span>

                <!-- Notification Icons -->
                <div class="titlebar-notifications">
                    <!-- Real-time Notification Bell -->
                    <div class="titlebar-notif notification-bell-wrapper" id="notification-bell" title="ئاگادارکردنەوەکان">
                        <i class="fas fa-bell"></i>
                        <span class="notif-badge notification-badge" id="notification-badge" style="<?= $realtimeNotifCount > 0 ? '' : 'display:none;' ?>"><?= $realtimeNotifCount ?></span>
                    </div>
                    <a href="<?= APP_URL ?>/appointments.php" class="titlebar-notif" title="مەوعیدەکان">
                        <i class="fas fa-calendar-check"></i>
                        <?php if ($todayAppointments > 0): ?>
                        <span class="notif-badge"><?= $todayAppointments ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= APP_URL ?>/waiting-room.php" class="titlebar-notif" title="ژووری چاوەڕوانی">
                        <i class="fas fa-users"></i>
                        <?php if ($waitingCount > 0): ?>
                        <span class="notif-badge"><?= $waitingCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= APP_URL ?>/xray/payments.php" class="titlebar-notif" title="پارەدانی ئێکسرەی">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <?php if ($xrayPaymentsCount > 0): ?>
                        <span class="notif-badge"><?= $xrayPaymentsCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= APP_URL ?>/xray/index.php" class="titlebar-notif" title="داواکاریەکان">
                        <i class="fas fa-x-ray"></i>
                        <?php if ($xrayRequestsCount > 0): ?>
                        <span class="notif-badge"><?= $xrayRequestsCount ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($canSeeAccounting): ?>
                    <a href="<?= APP_URL ?>/accountant/pending-reports.php" class="titlebar-notif" title="ڕاپۆرتی چاوەڕوانی ژمێریاری">
                        <i class="fas fa-file-invoice"></i>
                        <?php if ($pendingAccountingReports > 0): ?>
                        <span class="notif-badge"><?= $pendingAccountingReports ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="titlebar-controls">
                <button class="titlebar-btn minimize" title="Minimize">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="titlebar-btn maximize" title="Maximize">
                    <i class="far fa-square"></i>
                </button>
                <button class="titlebar-btn close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>


        <!-- Notifications Dropdown (Hidden by default) -->
        <div class="dialog-overlay" id="notificationDialog" onclick="if(event.target === this) closeNotifications()">
            <div class="dialog" style="max-width: 360px;">
                <div class="dialog-header">
                    <h3 class="dialog-title">ئاگادارییەکان</h3>
                    <button class="dialog-close" onclick="closeNotifications()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="dialog-body">
                    <?php if ($pendingPayments > 0): ?>
                    <a href="<?= APP_URL ?>/admin/payments.php?status=pending" class="nav-item" style="margin-bottom: 8px;">
                        <span class="nav-item-icon text-warning"><i class="fas fa-money-bill-wave"></i></span>
                        <span class="nav-item-text"><?= $pendingPayments ?> پارەدان چاوەڕوانن</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($overdueInstallments > 0): ?>
                    <a href="<?= APP_URL ?>/installments.php?status=overdue" class="nav-item" style="margin-bottom: 8px;">
                        <span class="nav-item-icon text-error"><i class="fas fa-exclamation-triangle"></i></span>
                        <span class="nav-item-text"><?= $overdueInstallments ?> قیست دواکەوتووە</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($totalNotifications == 0): ?>
                    <div class="empty-state" style="padding: 32px;">
                        <div class="empty-state-icon"><i class="fas fa-check-circle text-success"></i></div>
                        <div class="empty-state-title">هیچ ئاگادارییەکی نوێ نییە</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Real-time Notification Dropdown -->
        <div class="notification-dropdown" id="notification-dropdown">
            <div class="notification-dropdown-header">
                <h4>ئاگادارکردنەوەکان</h4>
                <div class="notification-header-actions">
                    <button class="notification-mark-all" id="notification-sound-toggle" title="دەنگی ئاگادارکردنەوە">
                        <i class="fas fa-volume-up"></i>
                    </button>
                    <button class="notification-mark-all" id="mark-all-read" title="هەمووی وەک خوێندراوەتەوە نیشانبکە">
                        <i class="fas fa-check-double"></i>
                    </button>
                </div>
            </div>
            <div class="notification-list" id="notification-list">
                <div class="notification-loading">
                    <i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...
                </div>
            </div>
            <div class="notification-dropdown-footer">
                <a href="<?= APP_URL ?>/notifications.php">هەموو ئاگادارکردنەوەکان ببینە</a>
            </div>
        </div>

        <!-- Main Application Container -->
        <div class="app-container">

            <!-- Navigation Pane (Sidebar) -->
            <?php include __DIR__ . '/sidebar.php'; ?>

            <!-- Content Area -->
            <div class="content-area">

                <!-- Mobile Header (Only visible on mobile) -->
                <div class="mobile-header">
                    <button class="mobile-menu-btn" onclick="toggleMobileNav()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <span class="text-body-strong"><?= $pageTitle ?></span>
                    <button class="mobile-menu-btn" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($totalNotifications > 0): ?>
                        <span class="nav-item-badge" style="position: absolute; top: 4px; right: 4px; min-width: 16px; height: 16px; font-size: 10px;"><?= $totalNotifications ?></span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Mobile Navigation Overlay -->
                <div class="mobile-nav-overlay" id="mobileNavOverlay" onclick="toggleMobileNav()"></div>

                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-header-top">
                        <h1 class="page-title"><?= $pageTitle ?></h1>
                        <?php if (isset($pageActions)): ?>
                        <div class="page-actions d-flex gap-2">
                            <?= $pageActions ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($breadcrumb) && !empty($breadcrumb)): ?>
                    <nav class="page-breadcrumb">
                        <a href="<?= APP_URL ?>/index.php">سەرەتا</a>
                        <?php foreach ($breadcrumb as $item): ?>
                        <span class="page-breadcrumb-separator">/</span>
                        <?php if (isset($item['url'])): ?>
                        <a href="<?= $item['url'] ?>"><?= $item['title'] ?></a>
                        <?php else: ?>
                        <span><?= $item['title'] ?></span>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                    <?php endif; ?>
                </div>

                <!-- Page Content -->
                <div class="page-content">
