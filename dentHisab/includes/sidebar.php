<?php
/**
 * Sidebar Include File - Collapsible Navigation Pane
 * Dynamic Permission-Based Menu System
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../classes/Permission.php';

$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();
$isXrayDepartment = $auth->isXrayDepartment();
$isReceptionist = $auth->isReceptionist();
$isAccountant = $auth->isAccountant();

// Get Permission instance
$permission = Permission::getInstance();

// Get current directory for better active state detection
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
$currentPageFull = $currentDir . '/' . $currentPage;

// Get pending accounting reports count for accountant badge
$pendingAccountingReports = 0;
$followUpsDueCount = 0;
if ($isAccountant || $isAdmin) {
    $pendingAccountingReports = $db->count('accounting_reports', "status = 'pending'");
}
// Follow-ups due today for debt collection badge (admin, accountant, doctor).
// A doctor's badge counts ONLY their own patients.
if ($isAccountant || $isAdmin || $isDoctor) {
    $fuWhere = "cl.next_follow_up IS NOT NULL AND cl.next_follow_up <= CURDATE()";
    $fuParams = [];
    if ($isDoctor && !$isAdmin) {
        $fuWhere .= " AND EXISTS (SELECT 1 FROM patient_folders pf_fu WHERE pf_fu.patient_id = cl.patient_id AND pf_fu.doctor_id = :fu_doc)";
        $fuParams['fu_doc'] = (int)($currentUser['id'] ?? 0);
    }
    $followUpsDueCount = (int)($db->fetch("SELECT COUNT(DISTINCT cl.patient_id) as cnt
        FROM call_logs cl
        INNER JOIN (SELECT patient_id, MAX(call_date) as max_date FROM call_logs GROUP BY patient_id) latest
            ON cl.patient_id = latest.patient_id AND cl.call_date = latest.max_date
        WHERE {$fuWhere}", $fuParams)['cnt'] ?? 0);
}

// Get counts for badges (only if user has permission to see them)
$todayAppointments = $permission->can('page.appointments')
    ? $db->count('appointments', "appointment_date = CURDATE() AND status IN ('scheduled', 'confirmed')")
    : 0;
$waitingCount = $permission->can('page.waiting_room')
    ? $db->count('waiting_room', "status = 'waiting'")
    : 0;
$pendingXrayCount = $permission->can('page.xray')
    ? $db->count('xray_requests', "status = 'pending'")
    : 0;

// Count X-Ray requests awaiting payment processing by accountant
// New workflow: Doctor → Accountant (payment) → X-ray Dept (scan)
$awaitingXrayPaymentCount = 0;
if ($isAdmin || $isAccountant) {
    $awaitingXrayPaymentCount = $db->count('xray_requests', "payment_status IN ('pending', 'awaiting_payment') AND status != 'cancelled'");
} elseif ($isDoctor && $permission->can('page.xray_payments')) {
    $awaitingXrayPaymentCount = $db->count('xray_requests', "payment_status IN ('pending', 'awaiting_payment') AND status != 'cancelled' AND requested_by = " . $currentUser['id']);
}

// Get today's visits count for receptionist
$todayVisitsCount = $permission->can('page.visits')
    ? $db->count('visits', "DATE(visit_date) = CURDATE()")
    : 0;

// Helper: determine which section should be auto-expanded based on current page
$activeSection = '';
if (in_array($currentPage, ['appointments', 'doctor-schedules', 'waiting-room', 'visits', 'new_visit', 'edit_visit', 'quick-actions'])) {
    $activeSection = 'main';
} elseif ($currentDir === 'patients' || $currentDir === 'cases' || $currentDir === 'folders') {
    $activeSection = 'patients';
} elseif ($currentDir === 'doctors') {
    $activeSection = 'doctors';
} elseif ($currentDir === 'xray') {
    $activeSection = 'xray';
} elseif (in_array($currentPage, ['debts', 'installments']) || ($currentDir === 'admin' && in_array($currentPage, ['payments', 'expenses', 'invoices']))) {
    $activeSection = 'financial';
} elseif ($currentDir === 'accountant' && $currentPage === 'debt-collection' && !$isAccountant) {
    // Debt collection page: for non-accountant users, highlight Financial section
    $activeSection = 'financial';
} elseif ($currentDir === 'admin' && in_array($currentPage, ['expenses-inventory', 'expenses-lab', 'expenses-general', 'expense-groups', 'expense-reports'])) {
    $activeSection = 'expenses';
} elseif ($currentDir === 'accountant') {
    $activeSection = 'accountant';
} elseif ($currentDir === 'admin' && in_array($currentPage, ['users', 'treatments', 'inventory', 'reports', 'settings', 'pdf_settings', 'cancellation-reasons', 'role-permissions', 'doctor-permissions'])) {
    $activeSection = 'admin';
}
?>
<nav class="nav-pane" id="navPane">
    <!-- Navigation Pane Header -->
    <div class="nav-pane-header">
        <button class="nav-pane-toggle" onclick="toggleNavPane()" title="کچکردنی لیست">
            <i class="fas fa-bars"></i>
        </button>
        <a href="<?= APP_URL ?>/index.php" class="nav-pane-logo">
            <img src="<?= APP_URL ?>/assets/images/logo.png" alt="<?= APP_NAME ?>" onerror="this.style.display='none'">
            <span class="nav-pane-logo-text"><?= APP_NAME_EN ?? 'Dental Clinic' ?></span>
        </a>
    </div>

    <!-- Navigation Content -->
    <div class="nav-pane-content">

        <!-- Dashboard - standalone item -->
        <?php if ($permission->can('page.dashboard')): ?>
        <a href="<?= APP_URL ?>/index.php" class="nav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
            <span class="nav-item-icon icon-home"><i class="fas fa-home"></i></span>
            <span class="nav-item-text">داشبۆرد</span>
        </a>
        <?php endif; ?>

        <!-- Reports Hub - standalone item -->
        <a href="<?= APP_URL ?>/reports.php" class="nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>">
            <span class="nav-item-icon" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;"><i class="fas fa-chart-pie"></i></span>
            <span class="nav-item-text">ڕاپۆرتەکان</span>
        </a>

        <!-- Main Section - Collapsible -->
        <?php if ($permission->can('page.appointments') || $permission->can('page.doctor_schedules') || $permission->can('page.waiting_room') || $permission->can('page.visits') || $permission->can('page.quick_actions')): ?>
        <div class="nav-group <?= $activeSection === 'main' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-calendar"><i class="fas fa-calendar-alt"></i></span>
                <span class="nav-item-text">سەرەکی</span>
                <?php
                $mainBadgeTotal = $todayAppointments + $waitingCount;
                if ($isReceptionist) $mainBadgeTotal += $todayVisitsCount;
                if ($mainBadgeTotal > 0): ?>
                <span class="nav-item-badge"><?= $mainBadgeTotal ?></span>
                <?php endif; ?>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.appointments')): ?>
                <a href="<?= APP_URL ?>/appointments.php" class="nav-item <?= $currentPage === 'appointments' ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-calendar"><i class="fas fa-calendar-alt"></i></span>
                    <span class="nav-item-text">مەوعیدەکان</span>
                    <?php if ($todayAppointments > 0): ?>
                    <span class="nav-item-badge"><?= $todayAppointments ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.doctor_schedules')): ?>
                <a href="<?= APP_URL ?>/admin/doctor-schedules.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'doctor-schedules') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-schedule"><i class="fas fa-clock"></i></span>
                    <span class="nav-item-text">کاتەکانی دکتۆر</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.waiting_room')): ?>
                <a href="<?= APP_URL ?>/waiting-room.php" class="nav-item <?= $currentPage === 'waiting-room' ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-waiting"><i class="fas fa-users"></i></span>
                    <span class="nav-item-text">ژووری چاوەڕوانی</span>
                    <?php if ($waitingCount > 0): ?>
                    <span class="nav-item-badge"><?= $waitingCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.visits')): ?>
                <a href="<?= APP_URL ?>/visits.php" class="nav-item <?= $currentPage === 'visits' || $currentPage === 'new_visit' || $currentPage === 'edit_visit' ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-visits"><i class="fas fa-clinic-medical"></i></span>
                    <span class="nav-item-text">سەردان</span>
                    <?php if ($isReceptionist && $todayVisitsCount > 0): ?>
                    <span class="nav-item-badge badge-green"><?= $todayVisitsCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.quick_actions')): ?>
                <a href="<?= APP_URL ?>/admin/quick-actions.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'quick-actions') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-quick-actions"><i class="fas fa-bolt"></i></span>
                    <span class="nav-item-text">کردارە خێرەکان</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isAccountant): ?>
        <!-- Accountant Section (shown second for accountant users) -->
        <?php if ($permission->can('page.accountant_dashboard') || $permission->can('page.accountant_reports') || $permission->can('page.accountant_financial')): ?>
        <div class="nav-group <?= $activeSection === 'accountant' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon" style="color:#0078d4;background:rgba(0,120,212,0.1);"><i class="fas fa-calculator"></i></span>
                <span class="nav-item-text">ژمێریاری</span>
                <?php if ($pendingAccountingReports > 0): ?>
                <span class="nav-item-badge"><?= $pendingAccountingReports ?></span>
                <?php endif; ?>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.accountant_dashboard')): ?>
                <a href="<?= APP_URL ?>/accountant/dashboard.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'dashboard') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0078d4;"><i class="fas fa-calculator"></i></span>
                    <span class="nav-item-text">داشبۆردی ژمێریار</span>
                </a>
                <a href="<?= APP_URL ?>/accountant/call-reports.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'call-reports') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#8e44ad;"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی پەیوەندییەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.accountant_reports')): ?>
                <a href="<?= APP_URL ?>/accountant/receiving.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'receiving') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#c50f1f;"><i class="fas fa-inbox"></i></span>
                    <span class="nav-item-text">وەرگرتنی نێردراوەکان</span>
                    <?php if ($pendingAccountingReports > 0): ?>
                    <span class="nav-item-badge"><?= $pendingAccountingReports ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>/accountant/pending-reports.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'pending-reports') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#ff8c00;"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی چاوەڕوان</span>
                    <?php if ($pendingAccountingReports > 0): ?>
                    <span class="nav-item-badge"><?= $pendingAccountingReports ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.accountant_payments')): ?>
                <a href="<?= APP_URL ?>/accountant/payments.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'payments') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#107c10;"><i class="fas fa-money-bill-wave"></i></span>
                    <span class="nav-item-text">وەرگرتنی پارە</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.accountant_financial')): ?>
                <a href="<?= APP_URL ?>/accountant/financial-summary.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'financial-summary') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0078d4;"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-item-text">پوختەی دارایی</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.debt_collection')): ?>
                <a href="<?= APP_URL ?>/accountant/debt-collection.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'debt-collection') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#e74c3c;"><i class="fas fa-phone-volume"></i></span>
                    <span class="nav-item-text">کۆکردنەوەی قەرز</span>
                    <?php if ($followUpsDueCount > 0): ?>
                    <span class="nav-item-badge"><?= $followUpsDueCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Patients Section - Collapsible -->
        <?php if ($permission->can('page.patients') || $permission->can('page.patients_add') || $permission->can('page.cases') || $permission->can('page.folders')): ?>
        <div class="nav-group <?= $activeSection === 'patients' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-patients"><i class="fas fa-user-injured"></i></span>
                <span class="nav-item-text">نەخۆشەکان</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.patients')): ?>
                <a href="<?= APP_URL ?>/patients/index.php" class="nav-item <?= ($currentDir === 'patients' && $currentPage === 'index') || ($currentDir === 'patients' && $currentPage === 'view') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-patients"><i class="fas fa-user-injured"></i></span>
                    <span class="nav-item-text">نەخۆشەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.patients_add')): ?>
                <a href="<?= APP_URL ?>/patients/add.php" class="nav-item <?= ($currentDir === 'patients' && $currentPage === 'add') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-add-patient"><i class="fas fa-user-plus"></i></span>
                    <span class="nav-item-text">نەخۆشی نوێ</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.cases')): ?>
                <a href="<?= APP_URL ?>/cases/index.php" class="nav-item <?= ($currentDir === 'cases' && $currentPage !== 'folders') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-cases"><i class="fas fa-briefcase-medical"></i></span>
                    <span class="nav-item-text">کەیسەکان</span>
                </a>
                <?php endif; ?>

                <?php /* Auto-Folder: the folder level is hidden from the UI —
                         cases are managed directly on the patient (Patient → Case).
                         folders/index.php stays reachable by URL for legacy data. */ ?>

                <a href="<?= APP_URL ?>/patients/report.php" class="nav-item <?= ($currentDir === 'patients' && $currentPage === 'report') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی ڕۆژانە</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Doctors Section - Collapsible -->
        <?php if ($permission->can('page.doctors') || $permission->can('page.doctor_profile') || $permission->can('page.doctor_report')): ?>
        <div class="nav-group <?= $activeSection === 'doctors' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-doctors"><i class="fas fa-user-md"></i></span>
                <span class="nav-item-text">دکتۆرەکان</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.doctors')): ?>
                <a href="<?= APP_URL ?>/doctors/index.php" class="nav-item <?= ($currentDir === 'doctors' && $currentPage === 'index') || $currentPage === 'doctors' ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-doctors"><i class="fas fa-user-md"></i></span>
                    <span class="nav-item-text">هەموو دکتۆرەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.doctor_profile')): ?>
                <a href="<?= APP_URL ?>/doctors/profile.php<?= $isDoctor && !$isAdmin ? '?id=' . $currentUser['id'] : '' ?>" class="nav-item <?= ($currentDir === 'doctors' && $currentPage === 'profile') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-doctor-profile"><i class="fas fa-id-card"></i></span>
                    <span class="nav-item-text"><?= $isAdmin ? 'پرۆفایلی دکتۆر' : 'پرۆفایلم' ?></span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.doctor_report')): ?>
                <a href="<?= APP_URL ?>/doctors/report.php" class="nav-item <?= ($currentDir === 'doctors' && $currentPage === 'report') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-doctor-report"><i class="fas fa-chart-line"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی ڕۆژانە</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- X-Ray Section - Collapsible -->
        <?php if ($permission->can('page.xray') || $permission->can('page.xray_request') || $permission->can('page.xray_payments') || $permission->can('page.xray_history')): ?>
        <div class="nav-group <?= $activeSection === 'xray' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-xray"><i class="fas fa-x-ray"></i></span>
                <span class="nav-item-text">ئێکسڕەی</span>
                <?php
                $xrayBadgeTotal = $pendingXrayCount + $awaitingXrayPaymentCount;
                if ($xrayBadgeTotal > 0): ?>
                <span class="nav-item-badge"><?= $xrayBadgeTotal ?></span>
                <?php endif; ?>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.xray')): ?>
                <a href="<?= APP_URL ?>/xray/index.php" class="nav-item <?= ($currentDir === 'xray' && $currentPage === 'index') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-xray"><i class="fas fa-x-ray"></i></span>
                    <span class="nav-item-text">داواکارییەکان</span>
                    <?php if ($pendingXrayCount > 0): ?>
                    <span class="nav-item-badge"><?= $pendingXrayCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.xray_request')): ?>
                <a href="<?= APP_URL ?>/xray/request.php" class="nav-item <?= ($currentDir === 'xray' && $currentPage === 'request') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-xray-request"><i class="fas fa-file-medical"></i></span>
                    <span class="nav-item-text">داواکاری نوێ</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.xray_payments')): ?>
                <a href="<?= APP_URL ?>/xray/payments.php" class="nav-item <?= ($currentDir === 'xray' && $currentPage === 'payments') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-xray-payment"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-item-text">پارەدانی ئێکسڕەی</span>
                    <?php if ($awaitingXrayPaymentCount > 0): ?>
                    <span class="nav-item-badge badge-warning"><?= $awaitingXrayPaymentCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.xray_history')): ?>
                <a href="<?= APP_URL ?>/xray/payment-history.php" class="nav-item <?= ($currentDir === 'xray' && $currentPage === 'payment-history') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-xray-history"><i class="fas fa-history"></i></span>
                    <span class="nav-item-text">مێژووی پارەدان</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Financial Section - Collapsible -->
        <?php if ($permission->can('page.debts') || $permission->can('page.payments') || $permission->can('page.installments') || $permission->can('page.expenses') || $permission->can('page.invoices')): ?>
        <div class="nav-group <?= $activeSection === 'financial' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-debts"><i class="fas fa-coins"></i></span>
                <span class="nav-item-text">دارایی</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.debts')): ?>
                <a href="<?= APP_URL ?>/debts.php" class="nav-item <?= $currentPage === 'debts' ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-debts"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-item-text">قەرز و قیست</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.payments')): ?>
                <a href="<?= APP_URL ?>/admin/payments.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'payments') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-payments"><i class="fas fa-money-bill-wave"></i></span>
                    <span class="nav-item-text">پارەدانەکان</span>
                    <?php if (isset($pendingPayments) && $pendingPayments > 0): ?>
                    <span class="nav-item-badge"><?= $pendingPayments ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.installments')): ?>
                <a href="<?= APP_URL ?>/installments.php" class="nav-item <?= $currentPage === 'installments' ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-installments"><i class="fas fa-credit-card"></i></span>
                    <span class="nav-item-text">قیستەکان</span>
                    <?php if (isset($overdueInstallments) && $overdueInstallments > 0): ?>
                    <span class="nav-item-badge"><?= $overdueInstallments ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.debt_collection')): ?>
                <a href="<?= APP_URL ?>/accountant/debt-collection.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'debt-collection') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#e74c3c;"><i class="fas fa-phone-volume"></i></span>
                    <span class="nav-item-text">کۆکردنەوەی قەرز</span>
                    <?php if ($followUpsDueCount > 0): ?>
                    <span class="nav-item-badge"><?= $followUpsDueCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.expenses')): ?>
                <a href="<?= APP_URL ?>/admin/expenses.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'expenses') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-expenses"><i class="fas fa-receipt"></i></span>
                    <span class="nav-item-text">خەرجییەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.invoices')): ?>
                <a href="<?= APP_URL ?>/admin/invoices.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'invoices') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-invoices"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-item-text">وەسڵەکان</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Expenses Section - Collapsible -->
        <?php if ($permission->can('page.expenses_inventory') || $permission->can('page.expenses_lab') || $permission->can('page.expenses_general') || $permission->can('page.expense_groups')): ?>
        <div class="nav-group <?= $activeSection === 'expenses' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-expenses"><i class="fas fa-receipt"></i></span>
                <span class="nav-item-text">خەرجیەکان</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.expenses_inventory')): ?>
                <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'expenses-inventory') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-expense-inventory"><i class="fas fa-warehouse"></i></span>
                    <span class="nav-item-text">خەرجی کۆگا</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.expenses_lab')): ?>
                <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'expenses-lab') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-expense-lab"><i class="fas fa-flask"></i></span>
                    <span class="nav-item-text">خەرجی تاقیگە</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.expenses_general')): ?>
                <a href="<?= APP_URL ?>/admin/expenses-general.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'expenses-general') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-expense-general"><i class="fas fa-coins"></i></span>
                    <span class="nav-item-text">خەرجی گشتی</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.expense_groups')): ?>
                <a href="<?= APP_URL ?>/admin/expense-groups.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'expense-groups') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-expense-groups"><i class="fas fa-layer-group"></i></span>
                    <span class="nav-item-text">گرووپی خەرجی</span>
                </a>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                <a href="<?= APP_URL ?>/admin/expense-reports.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'expense-reports') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-expense-reports"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی خەرجی</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Accountant Section - Collapsible (skip for accountant users, already shown above) -->
        <?php if (!$isAccountant && ($permission->can('page.accountant_dashboard') || $permission->can('page.accountant_reports') || $permission->can('page.accountant_financial'))): ?>
        <div class="nav-group <?= $activeSection === 'accountant' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon" style="color:#0078d4;background:rgba(0,120,212,0.1);"><i class="fas fa-calculator"></i></span>
                <span class="nav-item-text">ژمێریاری</span>
                <?php if ($pendingAccountingReports > 0): ?>
                <span class="nav-item-badge"><?= $pendingAccountingReports ?></span>
                <?php endif; ?>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($permission->can('page.accountant_dashboard')): ?>
                <a href="<?= APP_URL ?>/accountant/dashboard.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'dashboard') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0078d4;"><i class="fas fa-calculator"></i></span>
                    <span class="nav-item-text">داشبۆردی ژمێریار</span>
                </a>
                <a href="<?= APP_URL ?>/accountant/call-reports.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'call-reports') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#8e44ad;"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی پەیوەندییەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.accountant_reports')): ?>
                <a href="<?= APP_URL ?>/accountant/receiving.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'receiving') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#c50f1f;"><i class="fas fa-inbox"></i></span>
                    <span class="nav-item-text">وەرگرتنی نێردراوەکان</span>
                    <?php if ($pendingAccountingReports > 0): ?>
                    <span class="nav-item-badge"><?= $pendingAccountingReports ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>/accountant/pending-reports.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'pending-reports') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#ff8c00;"><i class="fas fa-file-invoice"></i></span>
                    <span class="nav-item-text">ڕاپۆرتی چاوەڕوان</span>
                    <?php if ($pendingAccountingReports > 0): ?>
                    <span class="nav-item-badge"><?= $pendingAccountingReports ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.accountant_payments')): ?>
                <a href="<?= APP_URL ?>/accountant/payments.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'payments') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#107c10;"><i class="fas fa-money-bill-wave"></i></span>
                    <span class="nav-item-text">وەرگرتنی پارە</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.debts') && $isAccountant): ?>
                <a href="<?= APP_URL ?>/accountant/debts.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'debts') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#c50f1f;"><i class="fas fa-hand-holding-usd"></i></span>
                    <span class="nav-item-text">قەرزەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.installments') && $isAccountant): ?>
                <a href="<?= APP_URL ?>/accountant/installments.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'installments') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#8764b8;"><i class="fas fa-credit-card"></i></span>
                    <span class="nav-item-text">قیستەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.expenses') && $isAccountant): ?>
                <a href="<?= APP_URL ?>/accountant/expenses.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'expenses') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#69797e;"><i class="fas fa-receipt"></i></span>
                    <span class="nav-item-text">خەرجییەکان</span>
                </a>
                <?php endif; ?>

                <?php if ($permission->can('page.accountant_financial')): ?>
                <a href="<?= APP_URL ?>/accountant/financial-summary.php" class="nav-item <?= ($currentDir === 'accountant' && $currentPage === 'financial-summary') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0078d4;"><i class="fas fa-chart-pie"></i></span>
                    <span class="nav-item-text">پوختەی دارایی</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Admin Section - Collapsible -->
        <?php if ($isAdmin): ?>
        <div class="nav-group <?= $activeSection === 'admin' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-settings"><i class="fas fa-cogs"></i></span>
                <span class="nav-item-text">بەڕێوەبردن</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <a href="<?= APP_URL ?>/admin/users.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'users') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-users"><i class="fas fa-users-cog"></i></span>
                    <span class="nav-item-text">بەکارهێنەران</span>
                </a>

                <a href="<?= APP_URL ?>/admin/treatments.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'treatments') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-treatments"><i class="fas fa-tooth"></i></span>
                    <span class="nav-item-text">چارەسەرەکان</span>
                </a>

                <a href="<?= APP_URL ?>/admin/inventory.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'inventory') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-inventory"><i class="fas fa-boxes"></i></span>
                    <span class="nav-item-text">کۆگا</span>
                </a>

                <a href="<?= APP_URL ?>/admin/reports.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'reports') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-reports"><i class="fas fa-chart-bar"></i></span>
                    <span class="nav-item-text">ڕاپۆرتەکان</span>
                </a>

                <a href="<?= APP_URL ?>/admin/settings.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'settings') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-settings"><i class="fas fa-cog"></i></span>
                    <span class="nav-item-text">ڕێکخستنەکان</span>
                </a>

                <a href="<?= APP_URL ?>/admin/pdf_settings.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'pdf_settings') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-pdf"><i class="fas fa-file-pdf"></i></span>
                    <span class="nav-item-text">ڕێکخستنی PDF</span>
                </a>

                <a href="<?= APP_URL ?>/admin/cancellation-reasons.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'cancellation-reasons') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-cancel"><i class="fas fa-calendar-times"></i></span>
                    <span class="nav-item-text">هۆکاری هەڵوەشاندنەوە</span>
                </a>

                <a href="<?= APP_URL ?>/admin/role-permissions.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'role-permissions') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-permissions"><i class="fas fa-shield-alt"></i></span>
                    <span class="nav-item-text">مۆڵەتی ڕۆڵەکان</span>
                </a>

                <a href="<?= APP_URL ?>/admin/doctor-permissions.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'doctor-permissions') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-doctor-perms"><i class="fas fa-user-shield"></i></span>
                    <span class="nav-item-text">مۆڵەتەکانی دکتۆر</span>
                </a>

                <a href="<?= APP_URL ?>/admin/backups.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'backups') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-backup"><i class="fas fa-database"></i></span>
                    <span class="nav-item-text">باکئەپی داتابەیس</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Navigation Footer - User Profile -->
    <div class="nav-pane-footer">
        <div class="nav-user" onclick="toggleUserMenu()">
            <div class="nav-user-avatar">
                <?php if (!empty($currentUser['profile_image'])): ?>
                <img src="<?= APP_URL ?>/uploads/profiles/<?= $currentUser['profile_image'] ?>" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                <?= mb_substr($currentUser['full_name'], 0, 1) ?>
                <?php endif; ?>
            </div>
            <div class="nav-user-info">
                <div class="nav-user-name"><?= htmlspecialchars($currentUser['full_name']) ?></div>
                <div class="nav-user-role">
                    <?php
                    $roleLabels = [
                        'admin' => 'بەڕێوەبەر',
                        'doctor' => 'دکتۆر',
                        'receptionist' => 'کارمەند',
                        'xray_department' => 'بەشی ئێکسڕەی',
                        'accountant' => 'ژمێریار'
                    ];
                    echo $roleLabels[$currentUser['role']] ?? $currentUser['role'];
                    ?>
                </div>
            </div>
        </div>

        <!-- User Menu (Hidden by default) -->
        <div class="context-menu" id="userContextMenu" style="display: none; position: absolute; bottom: 60px; right: 8px; left: 8px;">
            <a href="<?= APP_URL ?>/profile.php" class="context-menu-item">
                <i class="fas fa-user"></i>
                <span>پرۆفایل</span>
            </a>
            <a href="<?= APP_URL ?>/settings.php" class="context-menu-item">
                <i class="fas fa-cog"></i>
                <span>ڕێکخستنەکان</span>
            </a>
            <div class="context-menu-divider"></div>
            <a href="<?= APP_URL ?>/help.php" class="context-menu-item">
                <i class="fas fa-question-circle"></i>
                <span>یارمەتی</span>
            </a>
            <div class="context-menu-divider"></div>
            <a href="<?= APP_URL ?>/logout.php" class="context-menu-item" style="color: var(--status-error);">
                <i class="fas fa-sign-out-alt"></i>
                <span>دەرچوون</span>
            </a>
        </div>
    </div>
</nav>
