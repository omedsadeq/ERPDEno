<?php
/**
 * Quick Actions Page
 * کردارە خێرەکان - دەستگەیشتنی خێرا بە هەموو لاپەڕەکان
 * سیستەمی زیرەکی کلینیکی ددان
 */

$currentPage = 'quick-actions';
$pageTitle = 'کردارە خێرەکان';
$breadcrumb = [['title' => 'کردارە خێرەکان']];

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Permission.php';

$permission = Permission::getInstance();

// Check permission
if (!$permission->can('page.quick_actions')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();
?>

<style>
.quick-actions-page { padding: 0; }
.quick-section { margin-bottom: 24px; }
.quick-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e0e0e0;
}
.quick-section-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: white;
}
.quick-section-title { font-size: 16px; font-weight: 700; color: #333; }
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}
.quick-action-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: white;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    text-decoration: none;
    color: #333;
    transition: all 0.2s ease;
}
.quick-action-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-color: var(--card-color, #2196f3);
}
.quick-action-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
    background: var(--card-color, #2196f3);
}
.quick-action-content { flex: 1; overflow: hidden; }
.quick-action-title { font-size: 14px; font-weight: 600; margin-bottom: 2px; }
.quick-action-desc { font-size: 11px; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Responsive */
@media (max-width: 768px) {
    .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .quick-actions-grid { grid-template-columns: 1fr; }
}
</style>

<div class="quick-actions-page">
    <!-- Main Section - سەرەکی -->
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #2196f3, #1976d2);">
                <i class="fas fa-home"></i>
            </div>
            <div class="quick-section-title">سەرەکی</div>
        </div>
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.dashboard')): ?>
            <a href="<?= APP_URL ?>/index.php" class="quick-action-card" style="--card-color: #2196f3;">
                <div class="quick-action-icon"><i class="fas fa-home"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">داشبۆرد</div>
                    <div class="quick-action-desc">سەرەتای سیستەم</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.appointments')): ?>
            <a href="<?= APP_URL ?>/appointments.php" class="quick-action-card" style="--card-color: #4caf50;">
                <div class="quick-action-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">مەوعیدەکان</div>
                    <div class="quick-action-desc">بەڕێوەبردنی مەوعیدەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.doctor_schedules')): ?>
            <a href="<?= APP_URL ?>/admin/doctor-schedules.php" class="quick-action-card" style="--card-color: #ff9800;">
                <div class="quick-action-icon"><i class="fas fa-clock"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">کاتەکانی دکتۆر</div>
                    <div class="quick-action-desc">خشتەی کاتەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.waiting_room')): ?>
            <a href="<?= APP_URL ?>/waiting-room.php" class="quick-action-card" style="--card-color: #9c27b0;">
                <div class="quick-action-icon"><i class="fas fa-users"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">ژووری چاوەڕوانی</div>
                    <div class="quick-action-desc">نەخۆشە چاوەڕوانەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.visits')): ?>
            <a href="<?= APP_URL ?>/visits.php" class="quick-action-card" style="--card-color: #00bcd4;">
                <div class="quick-action-icon"><i class="fas fa-clinic-medical"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">سەردان</div>
                    <div class="quick-action-desc">سەردانەکانی نەخۆشان</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Patients Section - نەخۆشەکان -->
    <?php if ($permission->can('page.patients') || $permission->can('page.patients_add') || $permission->can('page.cases') || $permission->can('page.folders')): ?>
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #e91e63, #c2185b);">
                <i class="fas fa-user-injured"></i>
            </div>
            <div class="quick-section-title">نەخۆشەکان</div>
        </div>
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.patients')): ?>
            <a href="<?= APP_URL ?>/patients/index.php" class="quick-action-card" style="--card-color: #e91e63;">
                <div class="quick-action-icon"><i class="fas fa-user-injured"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">نەخۆشەکان</div>
                    <div class="quick-action-desc">لیستی هەموو نەخۆشان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.patients_add')): ?>
            <a href="<?= APP_URL ?>/patients/add.php" class="quick-action-card" style="--card-color: #4caf50;">
                <div class="quick-action-icon"><i class="fas fa-user-plus"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">نەخۆشی نوێ</div>
                    <div class="quick-action-desc">زیادکردنی نەخۆش</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.cases')): ?>
            <a href="<?= APP_URL ?>/cases/index.php" class="quick-action-card" style="--card-color: #673ab7;">
                <div class="quick-action-icon"><i class="fas fa-briefcase-medical"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">کەیسەکان</div>
                    <div class="quick-action-desc">بەڕێوەبردنی کەیسەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.folders')): ?>
            <a href="<?= APP_URL ?>/folders/index.php" class="quick-action-card" style="--card-color: #ff5722;">
                <div class="quick-action-icon"><i class="fas fa-folder-open"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">دۆسیەکان</div>
                    <div class="quick-action-desc">دۆسیەی نەخۆشان</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Doctors Section - دکتۆرەکان -->
    <?php if ($permission->can('page.doctors') || $permission->can('page.doctor_profile') || $permission->can('page.doctor_report')): ?>
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #3f51b5, #303f9f);">
                <i class="fas fa-user-md"></i>
            </div>
            <div class="quick-section-title">دکتۆرەکان</div>
        </div>
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.doctors')): ?>
            <a href="<?= APP_URL ?>/doctors/index.php" class="quick-action-card" style="--card-color: #3f51b5;">
                <div class="quick-action-icon"><i class="fas fa-user-md"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">هەموو دکتۆرەکان</div>
                    <div class="quick-action-desc">لیستی دکتۆرەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.doctor_profile')): ?>
            <a href="<?= APP_URL ?>/doctors/profile.php<?= $isDoctor && !$isAdmin ? '?id=' . $currentUser['id'] : '' ?>" class="quick-action-card" style="--card-color: #009688;">
                <div class="quick-action-icon"><i class="fas fa-id-card"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title"><?= $isAdmin ? 'پرۆفایلی دکتۆر' : 'پرۆفایلم' ?></div>
                    <div class="quick-action-desc">زانیاری دکتۆر</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.doctor_report')): ?>
            <a href="<?= APP_URL ?>/doctors/report.php" class="quick-action-card" style="--card-color: #ff5722;">
                <div class="quick-action-icon"><i class="fas fa-chart-line"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">ڕاپۆرتی ڕۆژانە</div>
                    <div class="quick-action-desc">ڕاپۆرتی کاری دکتۆر</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- X-Ray Section - ئێکسڕەی -->
    <?php if ($permission->can('page.xray') || $permission->can('page.xray_request') || $permission->can('page.xray_payments') || $permission->can('page.xray_history')): ?>
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #607d8b, #455a64);">
                <i class="fas fa-x-ray"></i>
            </div>
            <div class="quick-section-title">ئێکسڕەی</div>
        </div>
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.xray')): ?>
            <a href="<?= APP_URL ?>/xray/index.php" class="quick-action-card" style="--card-color: #607d8b;">
                <div class="quick-action-icon"><i class="fas fa-x-ray"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">داواکارییەکان</div>
                    <div class="quick-action-desc">داواکاری ئێکسڕەی</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.xray_request')): ?>
            <a href="<?= APP_URL ?>/xray/request.php" class="quick-action-card" style="--card-color: #4caf50;">
                <div class="quick-action-icon"><i class="fas fa-file-medical"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">داواکاری نوێ</div>
                    <div class="quick-action-desc">ناردنی ئێکسڕەی نوێ</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.xray_payments')): ?>
            <a href="<?= APP_URL ?>/xray/payments.php" class="quick-action-card" style="--card-color: #ff9800;">
                <div class="quick-action-icon"><i class="fas fa-cash-register"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">پارەدانی ئێکسڕەی</div>
                    <div class="quick-action-desc">وەرگرتنی پارە</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.xray_history')): ?>
            <a href="<?= APP_URL ?>/xray/payment-history.php" class="quick-action-card" style="--card-color: #795548;">
                <div class="quick-action-icon"><i class="fas fa-history"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">مێژووی پارەدان</div>
                    <div class="quick-action-desc">مێژووی پارەدانەکان</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Financial Section - دارایی -->
    <?php if ($permission->can('page.debts') || $permission->can('page.payments') || $permission->can('page.installments') || $permission->can('page.expenses') || $permission->can('page.invoices')): ?>
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #4caf50, #388e3c);">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="quick-section-title">دارایی</div>
        </div>
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.debts')): ?>
            <a href="<?= APP_URL ?>/debts.php" class="quick-action-card" style="--card-color: #f44336;">
                <div class="quick-action-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">قەرز و قیست</div>
                    <div class="quick-action-desc">بەڕێوەبردنی قەرزەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.payments')): ?>
            <a href="<?= APP_URL ?>/admin/payments.php" class="quick-action-card" style="--card-color: #4caf50;">
                <div class="quick-action-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">پارەدانەکان</div>
                    <div class="quick-action-desc">هەموو پارەدانەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.installments')): ?>
            <a href="<?= APP_URL ?>/installments.php" class="quick-action-card" style="--card-color: #2196f3;">
                <div class="quick-action-icon"><i class="fas fa-credit-card"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">قیستەکان</div>
                    <div class="quick-action-desc">بەڕێوەبردنی قیستەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.expenses')): ?>
            <a href="<?= APP_URL ?>/admin/expenses.php" class="quick-action-card" style="--card-color: #ff9800;">
                <div class="quick-action-icon"><i class="fas fa-receipt"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">خەرجییەکان</div>
                    <div class="quick-action-desc">تۆمارکردنی خەرجی</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.invoices')): ?>
            <a href="<?= APP_URL ?>/admin/invoices.php" class="quick-action-card" style="--card-color: #673ab7;">
                <div class="quick-action-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">وەسڵەکان</div>
                    <div class="quick-action-desc">دروستکردنی وەسڵ</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Expenses Section - خەرجیەکان -->
    <?php if ($permission->can('page.expenses_inventory') || $permission->can('page.expenses_lab') || $permission->can('page.expenses_general') || $permission->can('page.expense_groups')): ?>
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #ff5722, #e64a19);">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="quick-section-title">خەرجیەکان</div>
        </div>
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.expenses_inventory')): ?>
            <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="quick-action-card" style="--card-color: #ff9800;">
                <div class="quick-action-icon"><i class="fas fa-warehouse"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">خەرجی کۆگا</div>
                    <div class="quick-action-desc">خەرجی کەلوپەلەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.expenses_lab')): ?>
            <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="quick-action-card" style="--card-color: #9c27b0;">
                <div class="quick-action-icon"><i class="fas fa-flask"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">خەرجی تاقیگە</div>
                    <div class="quick-action-desc">خەرجی لابەراتوار</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.expenses_general')): ?>
            <a href="<?= APP_URL ?>/admin/expenses-general.php" class="quick-action-card" style="--card-color: #4caf50;">
                <div class="quick-action-icon"><i class="fas fa-coins"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">خەرجی گشتی</div>
                    <div class="quick-action-desc">خەرجی گشتی کلینیک</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($permission->can('page.expense_groups')): ?>
            <a href="<?= APP_URL ?>/admin/expense-groups.php" class="quick-action-card" style="--card-color: #607d8b;">
                <div class="quick-action-icon"><i class="fas fa-layer-group"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">گرووپی خەرجی</div>
                    <div class="quick-action-desc">پۆلێنی خەرجییەکان</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
            <a href="<?= APP_URL ?>/admin/expense-reports.php" class="quick-action-card" style="--card-color: #f44336;">
                <div class="quick-action-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">ڕاپۆرتی خەرجی</div>
                    <div class="quick-action-desc">شیکاری خەرجییەکان</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Admin Section - بەڕێوەبردن -->
    <?php if ($isAdmin): ?>
    <div class="quick-section">
        <div class="quick-section-header">
            <div class="quick-section-icon" style="background: linear-gradient(135deg, #795548, #5d4037);">
                <i class="fas fa-cog"></i>
            </div>
            <div class="quick-section-title">بەڕێوەبردن</div>
        </div>
        <div class="quick-actions-grid">
            <a href="<?= APP_URL ?>/admin/users.php" class="quick-action-card" style="--card-color: #3f51b5;">
                <div class="quick-action-icon"><i class="fas fa-users-cog"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">بەکارهێنەران</div>
                    <div class="quick-action-desc">بەڕێوەبردنی بەکارهێنەران</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/treatments.php" class="quick-action-card" style="--card-color: #00bcd4;">
                <div class="quick-action-icon"><i class="fas fa-tooth"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">چارەسەرەکان</div>
                    <div class="quick-action-desc">جۆرەکانی چارەسەر</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/inventory.php" class="quick-action-card" style="--card-color: #ff9800;">
                <div class="quick-action-icon"><i class="fas fa-boxes"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">کۆگا</div>
                    <div class="quick-action-desc">بەڕێوەبردنی کۆگا</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/reports.php" class="quick-action-card" style="--card-color: #e91e63;">
                <div class="quick-action-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">ڕاپۆرتەکان</div>
                    <div class="quick-action-desc">ڕاپۆرتی سیستەم</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/settings.php" class="quick-action-card" style="--card-color: #607d8b;">
                <div class="quick-action-icon"><i class="fas fa-cog"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">ڕێکخستنەکان</div>
                    <div class="quick-action-desc">ڕێکخستنی سیستەم</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/pdf_settings.php" class="quick-action-card" style="--card-color: #f44336;">
                <div class="quick-action-icon"><i class="fas fa-file-pdf"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">ڕێکخستنی PDF</div>
                    <div class="quick-action-desc">شێوازی چاپکردن</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/cancellation-reasons.php" class="quick-action-card" style="--card-color: #9c27b0;">
                <div class="quick-action-icon"><i class="fas fa-calendar-times"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">هۆکاری هەڵوەشاندنەوە</div>
                    <div class="quick-action-desc">هۆکاری ڕەتکردنەوە</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/role-permissions.php" class="quick-action-card" style="--card-color: #009688;">
                <div class="quick-action-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">مۆڵەتی ڕۆڵەکان</div>
                    <div class="quick-action-desc">دەستوورەکانی ڕۆڵ</div>
                </div>
            </a>

            <a href="<?= APP_URL ?>/admin/doctor-permissions.php" class="quick-action-card" style="--card-color: #673ab7;">
                <div class="quick-action-icon"><i class="fas fa-user-shield"></i></div>
                <div class="quick-action-content">
                    <div class="quick-action-title">مۆڵەتەکانی دکتۆر</div>
                    <div class="quick-action-desc">دەستووری دکتۆرەکان</div>
                </div>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
