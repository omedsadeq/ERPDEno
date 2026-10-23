<?php
/**
 * Sidebar Include File - Collapsible Navigation Pane
 * Dynamic Permission-Based Menu System
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/finance_nav_map.php';

$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();
$isXrayDepartment = $auth->isXrayDepartment();
$isReceptionist = $auth->isReceptionist();
$isAccountant = $auth->isAccountant();

// Get Permission instance
$permission = Permission::getInstance();
$financeGroups = finance_nav_groups();

// Get current directory for better active state detection
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
$currentPageFull = $currentDir . '/' . $currentPage;

// Approval Center badge = ApprovalQueue::counts()['badge'] (invoices+payments+
// installments+xray) — replaces the frozen accounting_reports.status='pending'
// count (Task 14; accounting_reports is read-only history since Task 13).
$pendingAccountingReports = 0;
$followUpsDueCount = 0;
if (isset($financeGroups['approvals'])) {
    require_once __DIR__ . '/../classes/ApprovalQueue.php';
    $pendingAccountingReports = (new ApprovalQueue())->counts()['badge'];
}
// Follow-ups due today for debt collection badge (admin, accountant, doctor).
// A doctor's badge counts ONLY their own patients.
if ($permission->can('page.debt_collection') && ($isAccountant || $isAdmin || $isDoctor)) {
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

// Prescriptions waiting at the دەرمانخانە counter. Wrapped because the table
// arrives with a migration — an installation mid-upgrade must still render a
// usable sidebar rather than a fatal.
$pendingPrescriptions = 0;
try {
    $pendingPrescriptions = (int)($db->fetch(
        "SELECT COUNT(*) AS cnt FROM erp2_prescriptions WHERE status = 'pending'"
    )['cnt'] ?? 0);
} catch (Throwable $e) {
    $pendingPrescriptions = 0;
}

// Get counts for badges (only if user has permission to see them).
// Doctors see only their own patients; other roles see clinic-wide totals.
// waiting_room.doctor_id, appointments.doctor_id, xray_requests.requested_by all reference users.id.
$badgeDoctorUid = ($isDoctor && !$isAdmin) ? (int)($currentUser['id'] ?? 0) : 0;

$todayAppointments = 0;
if ($permission->can('page.appointments')) {
    $todayAppointments = $badgeDoctorUid
        ? $db->count('appointments', "appointment_date = CURDATE() AND status IN ('scheduled', 'confirmed') AND doctor_id = :uid", ['uid' => $badgeDoctorUid])
        : $db->count('appointments', "appointment_date = CURDATE() AND status IN ('scheduled', 'confirmed')");
}
$waitingCount = 0;
if ($permission->can('page.waiting_room')) {
    $waitingCount = $badgeDoctorUid
        ? $db->count('waiting_room', "status = 'waiting' AND doctor_id = :uid", ['uid' => $badgeDoctorUid])
        : $db->count('waiting_room', "status = 'waiting'");
}
$pendingXrayCount = 0;
if ($permission->can('page.xray')) {
    $pendingXrayCount = $badgeDoctorUid
        ? $db->count('xray_requests', "status = 'pending' AND requested_by = :uid", ['uid' => $badgeDoctorUid])
        : $db->count('xray_requests', "status = 'pending'");
}

// Count X-Ray requests awaiting payment processing by accountant
// New workflow: Doctor → Accountant (payment) → X-ray Dept (scan)
$awaitingXrayPaymentCount = 0;
if ($isAdmin || $isAccountant) {
    $awaitingXrayPaymentCount = $db->count('xray_requests', "payment_status IN ('pending', 'awaiting_payment') AND status != 'cancelled'");
} elseif ($isDoctor && $permission->can('page.xray_payments')) {
    $awaitingXrayPaymentCount = $db->count('xray_requests', "payment_status IN ('pending', 'awaiting_payment') AND status != 'cancelled' AND requested_by = " . $currentUser['id']);
}
// xray/payments.php's own settlement actions retired 2026-08-26 (Task 5);
// accountant/admin now settle via the Approval Center, so this nav item
// points there for them instead. Doctors still use xray/payments.php itself
// — it's still their live "awaiting payment" view, unaffected by that
// retirement.
$xrayPaymentsHref = ($isAdmin || $isAccountant)
    ? APP_URL . '/accountant/approval-center.php?tab=xray'
    : APP_URL . '/xray/payments.php';

// Get today's visits count for receptionist
$todayVisitsCount = $permission->can('page.visits')
    ? $db->count('visits', "DATE(visit_date) = CURDATE()")
    : 0;

// پلانی چارەسەر routing badge: for a doctor, plans sent to him that he has
// not booked yet; for reception, «چەند دکتۆرێک» plans still without a doctor.
$planRoutingBadge = 0;
if ($permission->can('treatment_plan.view')) {
    try {
        require_once __DIR__ . '/../classes/TreatmentPlan.php';
        $planRoutingCounter = new TreatmentPlan();
        if ($isDoctor && !$isAdmin) {
            $planRoutingBadge = $planRoutingCounter->countDirectedUnbooked((int)($currentUser['id'] ?? 0));
        } elseif ($permission->can('treatment_plan.assign')) {
            $planRoutingBadge = $planRoutingCounter->countAwaitingReception();
        }
        unset($planRoutingCounter);
    } catch (Throwable $e) {
        $planRoutingBadge = 0;
    }
}

// New appointment batches doctors sent to this reception/admin account.
$receptionCallListUnreadCount = 0;
if ($isReceptionist || $isAdmin) {
    try {
        $receptionCallListUnreadCount = (new Notification())
            ->getReceptionCallListUnreadCount((int)($currentUser['id'] ?? 0));
    } catch (Throwable $e) {
        // Keep navigation usable while an older installation is migrating.
        $receptionCallListUnreadCount = 0;
    }
}

// Helper: determine which section should be auto-expanded based on current page
$activeSection = '';
if (in_array($currentPage, ['appointments', 'reception-call-lists', 'doctor-schedules', 'waiting-room', 'visits', 'new_visit', 'edit_visit', 'quick-actions'])) {
    $activeSection = 'main';
} elseif ($currentDir === 'patients' || $currentDir === 'cases' || $currentDir === 'folders' || $currentDir === 'treatment-plans') {
    $activeSection = 'patients';
} elseif ($currentDir === 'doctors') {
    $activeSection = 'doctors';
} elseif ($currentDir === 'xray') {
    $activeSection = 'xray';
} elseif (finance_nav_group_of(finance_nav_page()) !== null) {
    $activeSection = 'financial';
} elseif ($currentDir === 'erp') {
    $activeSection = 'erp';
} elseif ($currentDir === 'erp2') {
    $activeSection = 'erp2';
} elseif ($currentDir === 'letters') {
    $activeSection = 'letters';
} elseif ($currentDir === 'admin' && in_array($currentPage, ['users', 'treatments', 'inventory', 'reports', 'settings', 'pdf_settings', 'cancellation-reasons', 'xray-external-doctors', 'role-permissions', 'doctor-permissions'])) {
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
            <img src="<?= htmlspecialchars(Branding::logoUrl()) ?>" alt="<?= htmlspecialchars(APP_NAME) ?>" onerror="this.style.display='none'">
            <span class="nav-pane-logo-text"><?= htmlspecialchars(APP_NAME_EN) ?></span>
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
        <?php if ($permission->can('page.appointments') || $isReceptionist || $isAdmin || $permission->can('page.doctor_schedules') || $permission->can('page.waiting_room') || $permission->can('page.visits') || $permission->can('page.quick_actions')): ?>
        <div class="nav-group <?= $activeSection === 'main' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-calendar"><i class="fas fa-calendar-alt"></i></span>
                <span class="nav-item-text">سەرەکی</span>
                <?php
                $mainBadgeTotal = $todayAppointments + $waitingCount;
                if ($isReceptionist) $mainBadgeTotal += $todayVisitsCount;
                if ($isReceptionist || $isAdmin) $mainBadgeTotal += $receptionCallListUnreadCount;
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

                <?php if ($isReceptionist || $isAdmin): ?>
                <a href="<?= APP_URL ?>/reception-call-lists.php" class="nav-item <?= $currentPage === 'reception-call-lists' ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0f766e;background:rgba(15,118,110,.1);"><i class="fas fa-phone-volume"></i></span>
                    <span class="nav-item-text">لیستی پەیوەندیکردن</span>
                    <?php if ($receptionCallListUnreadCount > 0): ?>
                    <span class="nav-item-badge"><?= $receptionCallListUnreadCount ?></span>
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

        <!-- Patients Section - Collapsible -->
        <?php if ($permission->can('page.patients') || $permission->can('page.patients_add') || $permission->can('page.cases') || $permission->can('page.folders')): ?>
        <div class="nav-group <?= $activeSection === 'patients' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon icon-patients"><i class="fas fa-user-injured"></i></span>
                <span class="nav-item-text">نەخۆشەکان</span>
                <?php if ($planRoutingBadge > 0): ?>
                <span class="nav-item-badge"><?= $planRoutingBadge ?></span>
                <?php endif; ?>
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
                <a href="<?= APP_URL ?>/cases/index.php" class="nav-item <?= ($currentDir === 'cases' && $currentPage !== 'folders' && $currentPage !== 'deleted') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-cases"><i class="fas fa-briefcase-medical"></i></span>
                    <span class="nav-item-text">کەیسەکان</span>
                </a>
                <?php if ($auth->isAdmin() || $auth->isDoctor()): ?>
                <a href="<?= APP_URL ?>/cases/deleted.php" class="nav-item <?= ($currentDir === 'cases' && $currentPage === 'deleted') ? 'active' : '' ?>">
                    <span class="nav-item-icon"><i class="fas fa-history"></i></span>
                    <span class="nav-item-text">کەیسە سڕاوەکان</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($permission->can('treatment_plan.view')): ?>
                <a href="<?= APP_URL ?>/treatment-plans/index.php" class="nav-item <?= $currentDir === 'treatment-plans' ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#5e35b1;background:rgba(94,53,177,.1);"><i class="fas fa-clipboard-list"></i></span>
                    <span class="nav-item-text">پلانەکانی چارەسەر</span>
                    <?php if ($planRoutingBadge > 0): ?>
                    <span class="nav-item-badge"><?= $planRoutingBadge ?></span>
                    <?php endif; ?>
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
        <?php if ($permission->can('page.doctors') || $permission->can('page.doctor_profile') || $permission->can('page.doctor_report') || ($isDoctor && $permission->can('page.cashboxes'))): ?>
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
                <a href="<?= APP_URL ?>/doctors/daily-summary.php" class="nav-item <?= ($currentDir === 'doctors' && $currentPage === 'daily-summary') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-doctor-report"><i class="fas fa-file-invoice-dollar"></i></span>
                    <span class="nav-item-text">پوختەی ڕۆژانەم</span>
                </a>
                <?php endif; ?>
                <?php if ($isDoctor && $permission->can('page.cashboxes')): ?>
                <a href="<?= APP_URL ?>/doctors/cashbox.php" class="nav-item <?= ($currentDir === 'doctors' && $currentPage === 'cashbox') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0f766e;background:#e6f5f0;"><i class="fas fa-vault"></i></span>
                    <span class="nav-item-text">سندوقی من</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- X-Ray Section - Collapsible -->
        <?php if ($permission->can('page.xray') || $permission->can('page.xray_request') || $permission->can('page.xray_payments') || $permission->can('page.xray_history') || ($isXrayDepartment && $permission->can('page.cashboxes'))): ?>
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
                <a href="<?= $xrayPaymentsHref ?>" class="nav-item <?= ($currentDir === 'xray' && $currentPage === 'payments') ? 'active' : '' ?>">
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
                <?php if ($isXrayDepartment && $permission->can('page.cashboxes')): ?>
                <a href="<?= APP_URL ?>/xray/cashbox.php" class="nav-item <?= ($currentDir === 'xray' && $currentPage === 'cashbox') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#0f766e;background:#e6f5f0;"><i class="fas fa-vault"></i></span>
                    <span class="nav-item-text">سندوقی ئێکسڕەی</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php include __DIR__ . '/finance_sidebar.php'; ?>

        <!-- کۆگا (erp) - Collapsible. One entry per group; every other screen
             is on the bar at the top of each store page (erp/_nav.php). Both
             read erp/_nav_map.php. -->
        <?php
        $stSideGroups = [];
        if (is_file(__DIR__ . '/../erp/_nav_map.php')) {
            require_once __DIR__ . '/../erp/_nav_map.php';
            $stSideGroups = erp_nav_groups();
        }
        $stSideCurrent = ($stSideGroups && $currentDir === 'erp') ? erp_nav_group_of($currentPage) : null;
        ?>
        <?php if ($stSideGroups): ?>
        <div class="nav-group <?= $activeSection === 'erp' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon" style="color:#0369a1;"><i class="fas fa-store"></i></span>
                <span class="nav-item-text">کۆگا</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php foreach ($stSideGroups as $stKey => $stG): ?>
                <a href="<?= htmlspecialchars(module_nav_href(APP_URL . '/erp/', $stG['items'][0])) ?>" class="nav-item <?= $stSideCurrent === $stKey ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:<?= htmlspecialchars($stG['color']) ?>;"><i class="fas <?= htmlspecialchars($stG['icon']) ?>"></i></span>
                    <span class="nav-item-text"><?= htmlspecialchars($stG['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- دەرمانخانە (erp2) - Collapsible. Only the main entries: one per
             group plus the prescriptions queue. Every other screen is on the
             bar at the top of each pharmacy page (erp2/_nav.php); both read
             erp2/_nav_map.php. -->
        <?php
        $rxSideGroups = [];
        if (is_file(__DIR__ . '/../erp2/_nav_map.php')) {
            require_once __DIR__ . '/../erp2/_nav_map.php';
            $rxSideGroups = erp2_nav_groups();
        }
        $rxSideCurrent = ($rxSideGroups && $currentDir === 'erp2') ? erp2_nav_group_of($currentPage) : null;
        $rxSideCanRx = $permission->can('erp2.prescriptions.view');
        ?>
        <?php if ($rxSideGroups): ?>
        <div class="nav-group <?= $activeSection === 'erp2' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon" style="color:#0f766e;"><i class="fas fa-prescription-bottle-medical"></i></span>
                <span class="nav-item-text">دەرمانخانە</span>
                <?php if ($rxSideCanRx && $pendingPrescriptions > 0): ?>
                <span class="nav-item-badge"><?= $pendingPrescriptions ?></span>
                <?php endif; ?>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php foreach ($rxSideGroups as $rxKey => $rxG):
                    // The prescriptions queue has its own entry right after
                    // «فرۆشتن», so only that entry lights up on its page.
                    $rxOn = $rxSideCurrent === $rxKey && $currentPage !== 'prescriptions';
                    // Someone who may only see prescriptions gets that entry alone.
                    $rxOnlyRx = $rxG['items'][0]['page'] === 'prescriptions';
                ?>
                <?php if (!$rxOnlyRx): ?>
                <a href="<?= APP_URL ?>/erp2/<?= htmlspecialchars($rxG['items'][0]['url']) ?>" class="nav-item <?= $rxOn ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:<?= htmlspecialchars($rxG['color']) ?>;"><i class="fas <?= htmlspecialchars($rxG['icon']) ?>"></i></span>
                    <span class="nav-item-text"><?= htmlspecialchars($rxG['label']) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($rxKey === 'sales' && $rxSideCanRx): ?>
                <a href="<?= APP_URL ?>/erp2/prescriptions.php" class="nav-item <?= ($currentDir === 'erp2' && $currentPage === 'prescriptions') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#c2185b;"><i class="fas fa-prescription-bottle-medical"></i></span>
                    <span class="nav-item-text">داواکاری دەرمان</span>
                    <?php if ($pendingPrescriptions > 0): ?>
                    <span class="nav-item-badge"><?= $pendingPrescriptions ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- نوسراوی فەرمی (letters/) - Collapsible. The archive, a new letter,
             templates and the letterhead; letters/_nav.php carries the same
             screens on a bar at the top of each letters page. -->
        <?php
        $ltSideView = $permission->can('letters.view');
        $ltSideWrite = $permission->can('letters.create');
        $ltSideSettings = $permission->can('letters.settings');
        ?>
        <?php if ($ltSideView || $ltSideWrite || $ltSideSettings): ?>
        <div class="nav-group <?= $activeSection === 'letters' ? 'open' : '' ?>">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <span class="nav-item-icon" style="color:#1f3b63;"><i class="fas fa-envelope-open-text"></i></span>
                <span class="nav-item-text">نوسراوی فەرمی</span>
                <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
            </div>
            <div class="nav-group-items">
                <?php if ($ltSideView): ?>
                <a href="<?= APP_URL ?>/letters/index.php" class="nav-item <?= ($currentDir === 'letters' && in_array($currentPage, ['index', 'print'], true)) ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#1f3b63;"><i class="fas fa-archive"></i></span>
                    <span class="nav-item-text">ئەرشیفی نوسراوەکان</span>
                </a>
                <?php endif; ?>
                <?php if ($ltSideWrite): ?>
                <a href="<?= APP_URL ?>/letters/edit.php" class="nav-item <?= ($currentDir === 'letters' && $currentPage === 'edit') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#16a34a;"><i class="fas fa-file-signature"></i></span>
                    <span class="nav-item-text">نوسراوی نوێ</span>
                </a>
                <a href="<?= APP_URL ?>/letters/templates.php" class="nav-item <?= ($currentDir === 'letters' && $currentPage === 'templates') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#7c3aed;"><i class="fas fa-copy"></i></span>
                    <span class="nav-item-text">قاڵبەکان</span>
                </a>
                <?php endif; ?>
                <?php if ($ltSideSettings): ?>
                <a href="<?= APP_URL ?>/letters/settings.php" class="nav-item <?= ($currentDir === 'letters' && $currentPage === 'settings') ? 'active' : '' ?>">
                    <span class="nav-item-icon" style="color:#b45309;"><i class="fas fa-stamp"></i></span>
                    <span class="nav-item-text">سەردێڕ و واژۆ</span>
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

                <a href="<?= APP_URL ?>/admin/xray-external-doctors.php" class="nav-item <?= ($currentDir === 'admin' && $currentPage === 'xray-external-doctors') ? 'active' : '' ?>">
                    <span class="nav-item-icon icon-doctors"><i class="fas fa-user-md"></i></span>
                    <span class="nav-item-text">دکتۆرانی دەرەوە</span>
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
