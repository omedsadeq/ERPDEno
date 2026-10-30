<?php
/**
 * Dashboard - Main Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'داشبۆرد';
$hidePageHeader = true;   // each view has its own heading: the greeting banner, or the receptionist's title

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/case-button.php';
require_once __DIR__ . '/classes/Patient.php';
require_once __DIR__ . '/classes/Appointment.php';
require_once __DIR__ . '/classes/Payment.php';
require_once __DIR__ . '/classes/Installment.php';
require_once __DIR__ . '/classes/Notification.php';
require_once __DIR__ . '/classes/Permission.php';

$patientManager = new Patient();
$appointmentManager = new Appointment();
$paymentManager = new Payment();
$installmentManager = new Installment();
$notificationManager = new Notification();
$permission = Permission::getInstance();

$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$doctorId = $isAdmin ? null : $currentUser['id'];

// Get current patient and waiting queue for doctors
$currentPatient = null;
$waitingQueue = [];
if ($auth->isDoctor()) {
    $currentPatient = $notificationManager->getCurrentPatient($currentUser['id']);
    $waitingQueue = $notificationManager->getWaitingQueue($currentUser['id'], 5);
}

// Get Statistics
$stats = [];

// Stats for all users with relevant permissions.
// Doctors see only their own patients; other roles see clinic-wide totals.
// waiting_room.doctor_id and appointments.doctor_id reference users.id.
if ($isDoctor && !$isAdmin) {
    $uid = (int)($currentUser['id'] ?? 0);
    $stats['today_appointments'] = $db->count('appointments', "appointment_date = CURDATE() AND doctor_id = :uid", ['uid' => $uid]);
    $stats['waiting'] = $db->count('waiting_room', "status = 'waiting' AND doctor_id = :uid", ['uid' => $uid]);
    // visits doctor columns hold doctors.id, not users.id — resolve via
    // doctors.user_id (legacy name-match fallback) and fail closed to 0,
    // the same rules visits.php applies.
    require_once __DIR__ . '/includes/search_helpers.php';
    $doctorTableRow = $db->fetch(
        "SELECT id FROM doctors WHERE user_id = :uid
         UNION
         SELECT d.id FROM doctors d JOIN users u ON u.full_name = d.name AND u.id = :uid2
         LIMIT 1",
        ['uid' => $uid, 'uid2' => $uid]
    );
    $doctorTableId = (int)($doctorTableRow['id'] ?? 0);
    $stats['today_visits'] = 0;
    if ($doctorTableId > 0) {
        $hasVisitDoctorCols = false;
        try {
            $hasVisitDoctorCols = (bool)$db->fetch("SHOW COLUMNS FROM visits LIKE 'checkup_doctor_id'");
        } catch (Exception $e) {}
        $visitParams = [];
        $visitCond = visits_doctor_condition($doctorTableId, '', $visitParams, 'dv', $hasVisitDoctorCols, '');
        $stats['today_visits'] = (int)$db->count('visits', "DATE(visit_date) = CURDATE() AND {$visitCond}", $visitParams);
    }
} else {
    $stats['today_appointments'] = $db->count('appointments', "appointment_date = CURDATE()");
    $stats['waiting'] = $db->count('waiting_room', "status = 'waiting'");
    $stats['today_visits'] = $db->count('visits', "DATE(visit_date) = CURDATE()");
}

// Receptionist-specific stats
if ($isReceptionist) {
    // Today's visits breakdown
    $stats['today_new_patients'] = $db->count('visits', "DATE(visit_date) = CURDATE() AND patient_status = 'new'");
    $stats['today_old_patients'] = $db->count('visits', "DATE(visit_date) = CURDATE() AND patient_status = 'old'");

    // Get waiting room by doctor
    $doctorsInWaiting = $db->fetchAll(
        "SELECT u.id, u.full_name as doctor_name, COUNT(wr.id) as patient_count
         FROM users u
         LEFT JOIN waiting_room wr ON u.id = wr.doctor_id AND wr.status = 'waiting'
         WHERE u.role = 'doctor' AND u.is_active = 1
         GROUP BY u.id
         ORDER BY patient_count DESC, u.full_name"
    );

    // Get today's visits list
    $todayVisits = $db->fetchAll(
        "SELECT v.*, d1.name as requested_doctor_name, d2.name as assigned_doctor_name
         FROM visits v
         LEFT JOIN doctors d1 ON v.requested_doctor_id = d1.id
         LEFT JOIN doctors d2 ON v.assigned_doctor_id = d2.id
         WHERE DATE(v.visit_date) = CURDATE()
         ORDER BY v.arrival_time DESC
         LIMIT 10"
    );
} else {
    // Total Patients (for non-receptionist)
    $stats['total_patients'] = $db->count('patients');

    // Active Cases
    if ($isAdmin) {
        $stats['active_cases'] = $db->count('cases', "status = 'open'");
    } else {
        $sql = "SELECT COUNT(*) as count FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.doctor_id = :doctor_id AND c.status = 'open'";
        $result = $db->fetch($sql, ['doctor_id' => $doctorId]);
        $stats['active_cases'] = $result ? $result['count'] : 0;
    }
}

// Admin financial stats
if ($isAdmin) {
    $todayPayments = $paymentManager->getDailyTotal();
    $stats['today_income'] = $todayPayments;
    $monthlyIncome = $paymentManager->getMonthlyTotal();
    $stats['monthly_income'] = $monthlyIncome;
    $stats['pending_payments'] = $db->count('payments', "status = 'pending'");
    $overdueInstallments = $installmentManager->getOverdue();
    $stats['overdue_installments'] = count($overdueInstallments);
}

// Get Today's Appointments
$todayAppointments = $appointmentManager->getToday($isAdmin || $isReceptionist ? null : $doctorId);

// Get Upcoming Appointments
$upcomingAppointments = $appointmentManager->getUpcoming($isAdmin || $isReceptionist ? null : $doctorId, 5);

// Get Recent Patients (only for non-receptionist)
if (!$isReceptionist) {
    $recentPatients = $patientManager->getAll(1, 5);
}

// Get installments due this week (only for admin)
if ($isAdmin) {
    $upcomingInstallments = $installmentManager->getUpcoming(7);
}

// Waiting room patients — doctors see only their own queue
$waitingWhere = "wr.status = 'waiting'";
$waitingParams = [];
if ($isDoctor && !$isAdmin) {
    $waitingWhere .= " AND wr.doctor_id = :wp_uid";
    $waitingParams['wp_uid'] = (int)($currentUser['id'] ?? 0);
}
$waitingPatients = $db->fetchAll(
    "SELECT wr.*, p.full_name, p.phone, u.full_name as doctor_name
     FROM waiting_room wr
     LEFT JOIN patients p ON wr.patient_id = p.id
     LEFT JOIN users u ON wr.doctor_id = u.id
     WHERE {$waitingWhere}
     ORDER BY wr.priority DESC, wr.check_in_time",
    $waitingParams
);

// ── The admin & doctor view's own figures (assets/css/dashboard.css) ──
$dxHour = (int)date('G');
$dxGreeting = $dxHour < 12 ? 'بەیانیت باش' : ($dxHour < 17 ? 'ڕۆژت باش' : 'ئێوارەت باش');
$dxWeekdays = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
$dxAptStatus = [
    'scheduled' => ['داڕێژراو', '#64748b'], 'confirmed' => ['پشتڕاستکراوە', '#2563eb'],
    'waiting' => ['چاوەڕوان', '#d97706'], 'in_progress' => ['لە چارەسەردا', '#7c3aed'],
    'completed' => ['تەواوبوو', '#16a34a'], 'cancelled' => ['هەڵوەشاوە', '#dc2626'],
    'no_show' => ['نەهات', '#dc2626'], 'rescheduled' => ['دواخراو', '#d97706'],
];
// [label, value, icon, colour, page]
$dxKpis = [
    ['مەوعیدەکانی ئەمڕۆ', $stats['today_appointments'], 'fa-calendar-check', '#7c3aed', APP_URL . '/appointments.php'],
    ['لە چاوەڕوانیدا', $stats['waiting'], 'fa-user-clock', '#ea580c', APP_URL . '/waiting-room.php'],
    ['سەردانەکانی ئەمڕۆ', $stats['today_visits'], 'fa-clinic-medical', '#0d9488', APP_URL . '/visits.php?today=1'],
    ['کەیسی کراوە', $stats['active_cases'] ?? 0, 'fa-folder-open', '#db2777', APP_URL . '/cases/index.php'],
    ['کۆی نەخۆشەکان', $stats['total_patients'] ?? 0, 'fa-users', '#2563eb', APP_URL . '/patients/index.php'],
];
// [page, icon, colour, label, line]
$dxQuick = [
    [APP_URL . '/patients/add.php', 'fa-user-plus', '#0d9488', 'زیادکردنی نەخۆش', 'تۆمارکردنی نەخۆشی نوێ'],
    [APP_URL . '/appointments.php?action=add', 'fa-calendar-plus', '#16a34a', 'مەوعیدی نوێ', 'داڕشتنی مەوعید'],
    [APP_URL . '/waiting-room.php', 'fa-users', '#ea580c', 'ژووری چاوەڕوانی', 'بەڕێوەبردنی چاوەڕوانی'],
];
if ($isAdmin) {
    array_push($dxQuick,
        [APP_URL . '/admin/payments.php?action=add', 'fa-money-bill-wave', '#2563eb', 'وەرگرتنی پارە', 'تۆمارکردنی پارەدان'],
        [APP_URL . '/admin/reports.php', 'fa-chart-bar', '#7c3aed', 'ڕاپۆرتەکان', 'ڕاپۆرتی دارایی'],
        [APP_URL . '/admin/treatments.php', 'fa-tooth', '#dc2626', 'چارەسەرەکان', 'لیستی چارەسەر'],
        [APP_URL . '/admin/expenses-general.php', 'fa-receipt', '#d97706', 'خەرجی گشتی', 'بەڕێوەبردنی خەرجی'],
        [APP_URL . '/admin/expenses-lab.php', 'fa-flask', '#db2777', 'خەرجی تاقیگە', 'خەرجییەکانی تاقیگە'],
        [APP_URL . '/admin/expenses-inventory.php', 'fa-boxes-stacked', '#4f46e5', 'خەرجی کۆگا', 'خەرجییەکانی کۆگا'],
        [APP_URL . '/admin/expense-groups.php', 'fa-folder', '#475569', 'گرووپەکانی خەرجی', 'بەڕێوەبردنی گرووپەکان']
    );
}
/** 1,800,000 → 1.8M — for the chart's axis and labels. */
function dx_compact($n) {
    $n = (float)$n;
    foreach ([[1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$size, $unit]) {
        if (abs($n) >= $size) {
            return rtrim(rtrim(number_format($n / $size, 1, '.', ''), '0'), '.') . $unit;
        }
    }
    return number_format($n);
}
// Admin: the last seven days' approved income, a column a day — the same
// rows Payment::getDailyTotal sums for «داهاتی ئەمڕۆ».
$dxIncomeDays = [];
$dxIncomeWeek = 0;
$dxIncomeTop = 0;
$dxIncomePeak = -1;
if ($isAdmin) {
    $dxIncomeByDay = array_column($db->fetchAll(
        "SELECT DATE(payment_date) AS d, COALESCE(SUM(amount), 0) AS t FROM payments
         WHERE status = 'approved' AND payment_date >= CURDATE() - INTERVAL 6 DAY
         GROUP BY DATE(payment_date)"
    ), 't', 'd');
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $dxIncomeDays[] = ['day' => $day, 'name' => $dxWeekdays[(int)date('w', strtotime($day))], 'total' => (float)($dxIncomeByDay[$day] ?? 0)];
    }
    $totals = array_column($dxIncomeDays, 'total');
    $dxIncomeWeek = array_sum($totals);
    $peak = max($totals);
    $dxIncomePeak = $peak > 0 ? array_search($peak, $totals, true) : -1;
    // a clean top for the axis: 1, 2, 2.5 or 5 × a power of ten, just above the peak
    if ($peak > 0) {
        $mag = pow(10, floor(log10($peak)));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($step * $mag >= $peak) { $dxIncomeTop = $step * $mag; break; }
        }
    }
}
?>

<style>
/* Dashboard Container - Matching debt/new_visit style */
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title h2 {
    margin: 0;
    font-size: 20px;
    color: #1a1a1a;
}

.page-title .icon {
    width: 46px;
    height: 46px;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    box-shadow: 0 4px 12px rgba(var(--win-accent-rgb, 0, 120, 212), 0.3);
}

.page-title .icon.purple {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
}

/* Statistics Cards */
.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    display: flex;
    align-items: center;
    gap: 14px;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
}

.stat-card .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    flex-shrink: 0;
}

.stat-card .stat-info {
    flex: 1;
}

.stat-card .stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a1a;
    line-height: 1.2;
}

.stat-card .stat-label {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
}

/* Icon Colors */
.icon-blue { background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e)); }
.icon-green { background: linear-gradient(135deg, #4caf50, #388e3c); }
.icon-purple { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
.icon-orange { background: linear-gradient(135deg, #ff9800, #f57c00); }
.icon-teal { background: linear-gradient(135deg, #00bcd4, #0097a7); }
.icon-red { background: linear-gradient(135deg, #f44336, #d32f2f); }
.icon-pink { background: linear-gradient(135deg, #e91e63, #c2185b); }
.icon-indigo { background: linear-gradient(135deg, #3f51b5, #303f9f); }

/* Form Card Style */
.form-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    margin-bottom: 16px;
    overflow: hidden;
}

.form-card-header {
    padding: 14px 18px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, #fafafa, #f5f5f5);
}

.form-card-header .header-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-card-header .card-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: white;
}

.form-card-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #333;
}

.form-card-body {
    padding: 16px 18px;
}

/* Notification Cards */
.notification-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}

.notification-card {
    background: white;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
    border: 1px solid #f0f0f0;
}

.notification-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: #e0e0e0;
}

.notification-card .notif-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    flex-shrink: 0;
}

.notification-card .notif-value {
    font-size: 22px;
    font-weight: 700;
    line-height: 1.2;
}

.notification-card .notif-label {
    font-size: 12px;
    color: #666;
}

/* Doctor Waiting Cards */
.doctor-waiting-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
}

.doctor-waiting-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 16px;
    text-align: center;
    transition: all 0.2s;
}

.doctor-waiting-card:hover {
    background: #f0f0f0;
}

.doctor-waiting-card .count {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    margin: 0 auto 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    font-weight: 700;
}

.doctor-waiting-card .count.active {
    background: linear-gradient(135deg, #4caf50, #388e3c);
}

.doctor-waiting-card .count.empty {
    background: #e0e0e0;
    color: #999;
}

.doctor-waiting-card .doctor-name {
    font-weight: 600;
    color: #333;
    font-size: 13px;
}

.doctor-waiting-card .label {
    font-size: 11px;
    color: #888;
}

/* Data Table Style */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: right;
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #666;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
}

.data-table td {
    padding: 10px 12px;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.data-table tr:hover {
    background: #f8f9fa;
}

.data-table tr:last-child td {
    border-bottom: none;
}

/* Quick Actions Grid */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
}

.quick-action-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 18px 14px;
    text-align: center;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.quick-action-card:hover {
    background: white;
    border-color: #e0e0e0;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.quick-action-card .action-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    margin: 0 auto 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
}

.quick-action-card .action-label {
    font-weight: 600;
    font-size: 13px;
    color: #333;
    margin-bottom: 2px;
}

.quick-action-card .action-desc {
    font-size: 11px;
    color: #888;
}

/* Grid Layouts */
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

@media (max-width: 1024px) {
    .grid-2, .grid-3 {
        grid-template-columns: 1fr;
    }
}

/* Empty State */
.empty-state-mini {
    text-align: center;
    padding: 30px 20px;
    color: #888;
}

.empty-state-mini i {
    font-size: 36px;
    color: #ddd;
    margin-bottom: 10px;
}

.empty-state-mini p {
    margin: 0;
    font-size: 13px;
}

/* Badge Styles */
.badge-sm {
    display: inline-block;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 4px;
    background: #f0f0f0;
    color: #666;
}

.badge-sm.green { background: #e8f5e9; color: #2e7d32; }
.badge-sm.blue { background: #e3f2fd; color: #1565c0; }
.badge-sm.orange { background: #fff3e0; color: #ef6c00; }
.badge-sm.red { background: #ffebee; color: #c62828; }
.badge-sm.purple { background: #f3e5f5; color: #7b1fa2; }

/* Doctor Current Patient Card */
.current-patient-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 20px;
    color: white;
}

.current-patient-box .patient-label {
    font-size: 12px;
    opacity: 0.8;
    margin-bottom: 8px;
}

.current-patient-box .patient-name {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 10px;
}

.current-patient-box .patient-info {
    font-size: 13px;
    opacity: 0.9;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}

.current-patient-box .patient-info span {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Waiting Queue Mini */
.waiting-queue-box {
    background: white;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.waiting-queue-box .queue-header {
    padding: 12px 16px;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.waiting-queue-box .queue-header h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.waiting-queue-box .queue-count {
    background: var(--win-accent, #0078d4);
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
}

.queue-item {
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #f0f0f0;
}

.queue-item:last-child {
    border-bottom: none;
}

.queue-item .queue-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
    color: #666;
}

.queue-item .queue-name {
    flex: 1;
    font-size: 13px;
    font-weight: 500;
}

.queue-item .queue-time {
    font-size: 11px;
    color: #888;
}
</style>

<div class="dashboard-container">

<?php if ($isReceptionist): ?>
<!-- ==================== RECEPTIONIST DASHBOARD ==================== -->

<!-- Page Header -->
<div class="page-header">
    <div class="page-title">
        <div class="icon purple">
            <i class="fas fa-clinic-medical"></i>
        </div>
        <div>
            <h2>داشبۆردی پەیامبەر</h2>
            <span style="font-size: 12px; color: #888;">بەخێربێیت، <?= htmlspecialchars($currentUser['full_name']) ?></span>
        </div>
    </div>
    <div>
        <span style="font-size: 13px; color: #666;"><i class="fas fa-calendar-alt"></i> <?= date('Y-m-d') ?></span>
    </div>
</div>

<!-- Statistics Cards -->
<div class="stats-overview">
    <div class="stat-card">
        <div class="stat-icon icon-purple">
            <i class="fas fa-clinic-medical"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_visits']) ?></div>
            <div class="stat-label">سەردانەکانی ئەمڕۆ</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-green">
            <i class="fas fa-user-plus"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_new_patients']) ?></div>
            <div class="stat-label">نەخۆشی نوێ</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-blue">
            <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_old_patients']) ?></div>
            <div class="stat-label">نەخۆشی کۆن</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-orange">
            <i class="fas fa-user-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['waiting']) ?></div>
            <div class="stat-label">لە چاوەڕوانیدا</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-teal">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_appointments']) ?></div>
            <div class="stat-label">مەوعیدەکانی ئەمڕۆ</div>
        </div>
    </div>
</div>

<!-- Notifications Section -->
<div class="form-card">
    <div class="form-card-header">
        <div class="header-title">
            <div class="card-icon icon-purple">
                <i class="fas fa-bell"></i>
            </div>
            <h3>ئاگادارییەکان</h3>
        </div>
    </div>
    <div class="form-card-body">
        <div class="notification-grid">
            <?php if ($stats['waiting'] > 0): ?>
            <a href="<?= APP_URL ?>/waiting-room.php" class="notification-card">
                <div class="notif-icon icon-orange">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="notif-value" style="color: #ff9800;"><?= $stats['waiting'] ?></div>
                    <div class="notif-label">کەس لە چاوەڕوانیدا</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($stats['today_appointments'] > 0): ?>
            <a href="<?= APP_URL ?>/appointments.php" class="notification-card">
                <div class="notif-icon icon-green">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <div class="notif-value" style="color: #4caf50;"><?= $stats['today_appointments'] ?></div>
                    <div class="notif-label">مەوعید بۆ ئەمڕۆ</div>
                </div>
            </a>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/visits.php?today=1" class="notification-card">
                <div class="notif-icon icon-blue">
                    <i class="fas fa-clinic-medical"></i>
                </div>
                <div>
                    <div class="notif-value" style="color: var(--win-accent, #0078d4);"><?= $stats['today_visits'] ?></div>
                    <div class="notif-label">سەردانی ئەمڕۆ</div>
                </div>
            </a>

            <?php if ($stats['waiting'] == 0 && $stats['today_appointments'] == 0): ?>
            <div class="notification-card" style="justify-content: center; color: #888;">
                <i class="fas fa-check-circle" style="color: #4caf50; font-size: 24px;"></i>
                <span>هیچ ئاگادارییەکی نوێ نییە</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Doctor Waiting Room Summary -->
<?php if (!empty($doctorsInWaiting)): ?>
<div class="form-card">
    <div class="form-card-header">
        <div class="header-title">
            <div class="card-icon icon-orange">
                <i class="fas fa-user-md"></i>
            </div>
            <h3>نەخۆشی دکتۆرەکان لە چاوەڕوانیدا</h3>
        </div>
    </div>
    <div class="form-card-body">
        <div class="doctor-waiting-grid">
            <?php foreach ($doctorsInWaiting as $doc): ?>
            <div class="doctor-waiting-card">
                <div class="count <?= $doc['patient_count'] > 0 ? 'active' : 'empty' ?>">
                    <?= $doc['patient_count'] ?>
                </div>
                <div class="doctor-name"><?= htmlspecialchars($doc['doctor_name']) ?></div>
                <div class="label">نەخۆش</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Content Grid -->
<div class="grid-2">
    <!-- Waiting Room -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="header-title">
                <div class="card-icon icon-teal">
                    <i class="fas fa-users"></i>
                </div>
                <h3>ژووری چاوەڕوانی</h3>
            </div>
            <a href="<?= APP_URL ?>/waiting-room.php" class="btn btn-sm btn-secondary">هەموو</a>
        </div>
        <div class="form-card-body" style="padding: 0;">
            <?php if (empty($waitingPatients)): ?>
            <div class="empty-state-mini">
                <i class="fas fa-chair"></i>
                <p>ژووری چاوەڕوانی بەتاڵە</p>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نەخۆش</th>
                        <th>کاتی هاتن</th>
                        <th>دکتۆر</th>
                        <th>پلە</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($waitingPatients, 0, 8) as $index => $wp): ?>
                    <tr>
                        <td><strong><?= $index + 1 ?></strong></td>
                        <td><?= htmlspecialchars($wp['full_name']) ?></td>
                        <td><?= date('H:i', strtotime($wp['check_in_time'])) ?></td>
                        <td><?= htmlspecialchars($wp['doctor_name'] ?? '-') ?></td>
                        <td>
                            <?php
                            $priorityLabels = [
                                0 => ['', 'ئاسایی'],
                                1 => ['orange', 'گرنگ'],
                                2 => ['red', 'فریا']
                            ];
                            $priority = $priorityLabels[$wp['priority'] ?? 0] ?? $priorityLabels[0];
                            ?>
                            <span class="badge-sm <?= $priority[0] ?>"><?= $priority[1] ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Today's Visits -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="header-title">
                <div class="card-icon icon-purple">
                    <i class="fas fa-clinic-medical"></i>
                </div>
                <h3>سەردانەکانی ئەمڕۆ</h3>
            </div>
            <a href="<?= APP_URL ?>/visits.php?today=1" class="btn btn-sm btn-secondary">هەموو</a>
        </div>
        <div class="form-card-body" style="padding: 0;">
            <?php if (empty($todayVisits)): ?>
            <div class="empty-state-mini">
                <i class="fas fa-calendar-times"></i>
                <p>هیچ سەردانێک نییە</p>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th>کاتی هاتن</th>
                        <th>دۆخ</th>
                        <th>دکتۆر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todayVisits as $visit): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($visit['patient_name']) ?></strong>
                            <br><small style="color: #888;"><?= htmlspecialchars($visit['mobile_number']) ?></small>
                        </td>
                        <td><?= date('H:i', strtotime($visit['arrival_time'])) ?></td>
                        <td>
                            <span class="badge-sm <?= $visit['patient_status'] === 'new' ? 'green' : 'blue' ?>">
                                <?= $visit['patient_status'] === 'new' ? 'نوێ' : 'کۆن' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($visit['assigned_doctor_name'] ?? $visit['requested_doctor_name'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Today's Appointments -->
<div class="form-card">
    <div class="form-card-header">
        <div class="header-title">
            <div class="card-icon icon-green">
                <i class="fas fa-calendar-day"></i>
            </div>
            <h3>مەوعیدەکانی ئەمڕۆ</h3>
        </div>
        <a href="<?= APP_URL ?>/appointments.php" class="btn btn-sm btn-secondary">هەموو</a>
    </div>
    <div class="form-card-body" style="padding: 0;">
        <?php if (empty($todayAppointments)): ?>
        <div class="empty-state-mini">
            <i class="fas fa-calendar-check"></i>
            <p>هیچ مەوعیدێک نییە</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>کات</th>
                    <th>نەخۆش</th>
                    <th>دکتۆر</th>
                    <th>دۆخ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($todayAppointments, 0, 8) as $apt): ?>
                <tr>
                    <td><strong><?= date('H:i', strtotime($apt['appointment_time'])) ?></strong></td>
                    <td><?= htmlspecialchars($apt['patient_name']) ?></td>
                    <td><?= htmlspecialchars($apt['doctor_name'] ?? '-') ?></td>
                    <td>
                        <?php
                        $statusLabels = [
                            'scheduled' => ['', 'داڕێژراو'],
                            'confirmed' => ['blue', 'پشتڕاستکراوە'],
                            'waiting' => ['orange', 'چاوەڕوان'],
                            'in_progress' => ['blue', 'لە چارەسەردا'],
                            'completed' => ['green', 'تەواوبوو'],
                            'cancelled' => ['red', 'هەڵوەشاوە'],
                            'no_show' => ['red', 'نەهات']
                        ];
                        $status = $statusLabels[$apt['status']] ?? ['', $apt['status']];
                        ?>
                        <span class="badge-sm <?= $status[0] ?>"><?= $status[1] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="form-card">
    <div class="form-card-header">
        <div class="header-title">
            <div class="card-icon icon-blue">
                <i class="fas fa-bolt"></i>
            </div>
            <h3>کردارە خێراکان</h3>
        </div>
    </div>
    <div class="form-card-body">
        <div class="quick-actions-grid">
            <?php if ($permission->can('page.new_visit')): ?>
            <a href="<?= APP_URL ?>/new_visit.php" data-new-visit-dialog class="quick-action-card">
                <div class="action-icon icon-purple">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <div class="action-label">سەردانی نوێ</div>
                <div class="action-desc">تۆمارکردنی سەردان</div>
            </a>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/waiting-room.php" class="quick-action-card">
                <div class="action-icon icon-orange">
                    <i class="fas fa-users"></i>
                </div>
                <div class="action-label">ژووری چاوەڕوانی</div>
                <div class="action-desc">بەڕێوەبردنی چاوەڕوانی</div>
            </a>

            <a href="<?= APP_URL ?>/appointments.php?action=add" class="quick-action-card">
                <div class="action-icon icon-green">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="action-label">مەوعیدی نوێ</div>
                <div class="action-desc">داڕشتنی مەوعید</div>
            </a>

            <a href="<?= APP_URL ?>/visits.php?today=1" class="quick-action-card">
                <div class="action-icon icon-blue">
                    <i class="fas fa-list"></i>
                </div>
                <div class="action-label">سەردانەکانی ئەمڕۆ</div>
                <div class="action-desc">بینینی ڕاپۆرت</div>
            </a>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ==================== NORMAL DASHBOARD (Admin/Doctor) ==================== -->
<link rel="stylesheet" href="<?= asset_url('assets/css/dashboard.css') ?>">
<div class="dx">

<!-- The greeting, the day in a line, and where to go next -->
<section class="dx-hero">
    <div class="dx-hero-text">
        <h2 class="dx-hero-hi"><?= $dxGreeting ?>، <span><?= htmlspecialchars($currentUser['full_name']) ?></span></h2>
        <div class="dx-hero-date"><i class="far fa-calendar"></i> <?= $dxWeekdays[(int)date('w')] ?> · <bdi><?= date('Y/m/d') ?></bdi></div>
        <div class="dx-hero-sum">
            <span><i class="fas fa-calendar-check"></i> <b><?= number_format($stats['today_appointments']) ?></b> مەوعیدی ئەمڕۆ</span>
            <span><i class="fas fa-user-clock"></i> <b><?= number_format($stats['waiting']) ?></b> لە چاوەڕوانیدا</span>
            <span><i class="fas fa-clinic-medical"></i> <b><?= number_format($stats['today_visits']) ?></b> سەردان</span>
        </div>
    </div>
    <div class="dx-hero-actions">
        <?php if ($permission->can('page.new_visit')): ?>
        <button type="button" class="dx-hero-btn dx-hero-btn--primary" onclick="openNewVisitDialog()"><i class="fas fa-door-open"></i> سەردانی نوێ</button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/patients/add.php" class="dx-hero-btn"><i class="fas fa-user-plus"></i> نەخۆشی نوێ</a>
        <a href="<?= APP_URL ?>/appointments.php?action=add" class="dx-hero-btn"><i class="fas fa-calendar-plus"></i> مەوعید</a>
        <a href="<?= APP_URL ?>/waiting-room.php" class="dx-hero-btn"><i class="fas fa-users"></i> ژووری چاوەڕوانی</a>
    </div>
</section>

<!-- The day's figures: each opens its own page -->
<div class="dx-kpis">
    <?php foreach ($dxKpis as [$kLabel, $kValue, $kIcon, $kColor, $kUrl]): ?>
    <a href="<?= $kUrl ?>" class="dx-kpi" style="--k: <?= $kColor ?>">
        <span class="dx-kpi-ico"><i class="fas <?= $kIcon ?>"></i></span>
        <span class="dx-kpi-text">
            <span class="dx-kpi-val"><?= number_format((float)$kValue) ?></span>
            <span class="dx-kpi-label"><?= $kLabel ?></span>
        </span>
        <i class="fas fa-arrow-left dx-kpi-go"></i>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($auth->isDoctor()): ?>
<!-- Who is in the doctor's chair now -->
<?php if ($currentPatient): ?>
<section class="dx-now">
    <span class="dx-now-ico"><i class="fas fa-user-injured"></i></span>
    <div class="dx-main">
        <small>نەخۆشی ئێستا لە ژوورەکەت</small>
        <b><?= htmlspecialchars($currentPatient['full_name']) ?></b>
        <div class="dx-sub">
            <span><i class="fas fa-id-card"></i><?= htmlspecialchars($currentPatient['patient_code']) ?></span>
            <span class="ltr"><i class="fas fa-phone"></i><?= htmlspecialchars($currentPatient['phone']) ?></span>
            <span><i class="fas fa-clock"></i><?= (int)$currentPatient['waiting_minutes'] ?> خولەک</span>
        </div>
    </div>
    <div class="dx-acts">
        <?php if (case_button_allowed($auth, (int)$currentPatient['patient_id'])): ?>
        <?= case_button((int)$currentPatient['patient_id'], ['size' => 'lg']) ?>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$currentPatient['patient_id'] ?>" class="dx-now-link"><i class="fas fa-id-badge"></i> پرۆفایل</a>
    </div>
</section>
<?php else: ?>
<section class="dx-now dx-now--idle">
    <span class="dx-now-ico"><i class="fas fa-user-clock"></i></span>
    <div class="dx-main">
        <b style="font-size: 15px; color: var(--dx-ink);">هیچ نەخۆشێک لە ژوورەکەتدا نییە</b>
        <small style="opacity: 1; color: var(--dx-ink-3);">نەخۆشی دواتر لە ژووری چاوەڕوانییەوە بانگ بکە</small>
    </div>
    <a href="<?= APP_URL ?>/waiting-room.php" class="dx-now-link"><i class="fas fa-bullhorn"></i> ژووری چاوەڕوانی</a>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- Income: today as the figure, the week as columns -->
<section class="dx-card dx-fin">
    <div class="dx-fin-chart">
        <div class="dx-card-head" style="padding-inline: 0;">
            <span class="dx-card-ico" style="--k: #2563eb"><i class="fas fa-chart-column"></i></span>
            <div>
                <h3 class="dx-card-title">داهاتی ٧ ڕۆژی ڕابردوو</h3>
                <span class="dx-card-sub">کۆ: <bdi><?= number_format($dxIncomeWeek) ?></bdi> — پارەی پەسەندکراو</span>
            </div>
            <a href="<?= APP_URL ?>/admin/reports.php" class="dx-more">ڕاپۆرت</a>
        </div>
        <div class="dx-chart" role="img" aria-label="داهاتی ٧ ڕۆژی ڕابردوو، هەر ڕۆژێک ستوونێک">
            <div class="dx-plot">
                <div class="dx-gl" style="bottom: 100%"><span><?= dx_compact($dxIncomeTop) ?></span></div>
                <div class="dx-gl" style="bottom: 50%"><span><?= dx_compact($dxIncomeTop / 2) ?></span></div>
                <div class="dx-gl dx-gl--base" style="bottom: 0"><span>0</span></div>
                <div class="dx-cols">
                    <?php foreach ($dxIncomeDays as $i => $d):
                        $isToday = $i === count($dxIncomeDays) - 1;
                        $h = $dxIncomeTop > 0 ? round($d['total'] / $dxIncomeTop * 100, 2) : 0;
                        $tip = $d['name'] . ' ' . date('m/d', strtotime($d['day'])) . ' — ' . number_format($d['total']);
                    ?>
                    <div class="dx-col<?= $isToday ? ' is-today' : '' ?>" style="--h: <?= $h ?>%" tabindex="0" data-tip="<?= htmlspecialchars($tip) ?>" aria-label="<?= htmlspecialchars($tip) ?>">
                        <?php if (($isToday || $i === $dxIncomePeak) && $d['total'] > 0): // label the peak and today only ?>
                        <span class="dx-col-val"><?= dx_compact($d['total']) ?></span>
                        <?php endif; ?>
                        <span class="dx-bar"></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="dx-days">
                <?php foreach ($dxIncomeDays as $i => $d): ?>
                <span<?= $i === count($dxIncomeDays) - 1 ? ' class="is-today"' : '' ?>><b><?= $i === count($dxIncomeDays) - 1 ? 'ئەمڕۆ' : $d['name'] ?></b><small><?= date('m/d', strtotime($d['day'])) ?></small></span>
                <?php endforeach; ?>
            </div>
            <?php if ($dxIncomeWeek <= 0): ?>
            <div class="dx-chart-note">لەم حەوت ڕۆژەدا هیچ پارەیەکی پەسەندکراو وەرنەگیراوە</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="dx-fin-side">
        <div>
            <div class="dx-fig-label">داهاتی ئەمڕۆ</div>
            <div class="dx-fig"><?= number_format($stats['today_income']) ?></div>
        </div>
        <a href="<?= APP_URL ?>/admin/payments.php" class="dx-fin-stat" style="--k: #2563eb">
            <i class="fas fa-chart-line"></i><span>داهاتی ئەم مانگە</span><b class="dx-amount"><?= number_format($stats['monthly_income']) ?></b>
        </a>
        <a href="<?= APP_URL ?>/admin/payments.php" class="dx-fin-stat" style="--k: #f59e0b">
            <i class="fas fa-hourglass-half"></i><span>پارەدانی چاوەڕوان</span><b class="dx-amount"><?= number_format($stats['pending_payments']) ?></b>
        </a>
        <a href="<?= APP_URL ?>/installments.php?status=overdue" class="dx-fin-stat" style="--k: #dc2626">
            <i class="fas fa-triangle-exclamation"></i><span>قیستی دواکەوتوو</span><b class="dx-amount"><?= number_format($stats['overdue_installments']) ?></b>
        </a>
    </div>
</section>
<?php endif; ?>

<!-- The day's lists -->
<div class="dx-grid">
    <!-- Today's appointments -->
    <section class="dx-card">
        <div class="dx-card-head">
            <span class="dx-card-ico" style="--k: #7c3aed"><i class="fas fa-calendar-day"></i></span>
            <div>
                <h3 class="dx-card-title">مەوعیدەکانی ئەمڕۆ</h3>
                <span class="dx-card-sub"><?= $dxWeekdays[(int)date('w')] ?></span>
            </div>
            <?php if (!empty($todayAppointments)): ?><span class="dx-count" style="--k: #7c3aed"><?= count($todayAppointments) ?></span><?php endif; ?>
            <a href="<?= APP_URL ?>/appointments.php" class="dx-more">هەموو</a>
        </div>
        <?php if (empty($todayAppointments)): ?>
        <div class="dx-empty"><i class="fas fa-calendar-check"></i><b>هیچ مەوعیدێک نییە</b>ئەمڕۆ هیچ مەوعیدێک دانەنراوە</div>
        <?php else: ?>
        <div class="dx-list">
            <?php foreach ($todayAppointments as $apt):
                $st = $dxAptStatus[$apt['status']] ?? [$apt['status'], '#64748b'];
            ?>
            <div class="dx-row">
                <span class="dx-time"><?= date('H:i', strtotime($apt['appointment_time'])) ?></span>
                <div class="dx-main">
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$apt['patient_id'] ?>" class="dx-name"><?= htmlspecialchars($apt['patient_name']) ?></a>
                    <div class="dx-sub">
                        <?php if (!empty($apt['doctor_name'])): ?><span><i class="fas fa-user-doctor"></i>د. <?= htmlspecialchars($apt['doctor_name']) ?></span><?php endif; ?>
                    </div>
                </div>
                <span class="dx-chip" style="--c: <?= $st[1] ?>"><?= htmlspecialchars($st[0]) ?></span>
                <div class="dx-acts">
                    <?php if (case_button_allowed($auth, (int)$apt['patient_id'])): ?>
                    <?= case_button((int)$apt['patient_id'], ['size' => 'sm', 'doctor' => (int)$apt['doctor_id']]) ?>
                    <?php endif; ?>
                    <?php if (in_array($apt['status'], ['scheduled', 'confirmed'], true)): // هات: into the waiting room (appointments.php check_in) ?>
                    <form method="POST" action="<?= APP_URL ?>/appointments.php">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="check_in">
                        <input type="hidden" name="appointment_id" value="<?= (int)$apt['id'] ?>">
                        <button type="submit" class="dx-ok" title="هات — ناردن بۆ ژووری چاوەڕوانی"><i class="fas fa-check"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- The waiting room -->
    <section class="dx-card">
        <div class="dx-card-head">
            <span class="dx-card-ico" style="--k: #ea580c"><i class="fas fa-couch"></i></span>
            <div>
                <h3 class="dx-card-title">ژووری چاوەڕوانی</h3>
                <span class="dx-card-sub"><?= ($isDoctor && !$isAdmin) ? 'ڕیزی نەخۆشەکانی خۆت' : 'هەموو دکتۆرەکان' ?></span>
            </div>
            <?php if (!empty($waitingPatients)): ?><span class="dx-count" style="--k: #ea580c"><?= count($waitingPatients) ?></span><?php endif; ?>
            <a href="<?= APP_URL ?>/waiting-room.php" class="dx-more">هەموو</a>
        </div>
        <?php if (empty($waitingPatients)): ?>
        <div class="dx-empty"><i class="fas fa-mug-hot"></i><b>کەس چاوەڕوان نییە</b>ژووری چاوەڕوانی بەتاڵە</div>
        <?php else: ?>
        <div class="dx-list">
            <?php foreach ($waitingPatients as $index => $wp):
                $waitMin = max(0, (int)round((time() - strtotime($wp['check_in_time'])) / 60));
            ?>
            <div class="dx-row">
                <span class="dx-num"><?= $index + 1 ?></span>
                <div class="dx-main">
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$wp['patient_id'] ?>" class="dx-name"><?= htmlspecialchars($wp['full_name']) ?></a>
                    <div class="dx-sub">
                        <?php if (!empty($wp['doctor_name'])): ?><span><i class="fas fa-user-doctor"></i>د. <?= htmlspecialchars($wp['doctor_name']) ?></span><?php endif; ?>
                        <span><i class="far fa-clock"></i><?= date('H:i', strtotime($wp['check_in_time'])) ?> · <?= $waitMin ?> خولەک</span>
                    </div>
                </div>
                <div class="dx-acts">
                    <?php if (case_button_allowed($auth, (int)$wp['patient_id'])): ?>
                    <?= case_button((int)$wp['patient_id'], ['size' => 'sm', 'doctor' => (int)($wp['doctor_id'] ?? 0)]) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if (isset($recentPatients)): ?>
    <!-- The newest patients -->
    <section class="dx-card">
        <div class="dx-card-head">
            <span class="dx-card-ico" style="--k: #2563eb"><i class="fas fa-user-plus"></i></span>
            <div>
                <h3 class="dx-card-title">نەخۆشە نوێیەکان</h3>
                <span class="dx-card-sub">دوایین تۆمارکراوەکان</span>
            </div>
            <a href="<?= APP_URL ?>/patients/index.php" class="dx-more">هەموو</a>
        </div>
        <?php if (empty($recentPatients)): ?>
        <div class="dx-empty"><i class="fas fa-users"></i><b>هیچ نەخۆشێک نییە</b></div>
        <?php else: ?>
        <div class="dx-list">
            <?php foreach ($recentPatients as $patient): ?>
            <div class="dx-row">
                <span class="dx-avatar<?= ($patient['gender'] ?? '') === 'female' ? ' dx-avatar--f' : '' ?>"><?= htmlspecialchars(mb_substr($patient['full_name'], 0, 1, 'UTF-8')) ?></span>
                <div class="dx-main">
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$patient['id'] ?>" class="dx-name"><?= htmlspecialchars($patient['full_name']) ?></a>
                    <div class="dx-sub">
                        <span><i class="fas fa-id-card"></i><?= htmlspecialchars($patient['patient_code']) ?></span>
                        <span class="ltr"><i class="fas fa-phone"></i><?= htmlspecialchars($patient['phone']) ?></span>
                        <span><i class="far fa-calendar"></i><?= date('Y/m/d', strtotime($patient['created_at'])) ?></span>
                    </div>
                </div>
                <div class="dx-acts">
                    <?php if (case_button_allowed($auth, (int)$patient['id'])): ?>
                    <?= case_button((int)$patient['id'], ['size' => 'sm']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($isAdmin && (!empty($overdueInstallments) || !empty($upcomingInstallments))): ?>
    <!-- Installments: overdue first, then this week's -->
    <section class="dx-card">
        <div class="dx-card-head">
            <span class="dx-card-ico" style="--k: #dc2626"><i class="fas fa-file-invoice-dollar"></i></span>
            <div>
                <h3 class="dx-card-title">قیستەکان</h3>
                <span class="dx-card-sub">دواکەوتوو و ئەم هەفتەیە</span>
            </div>
            <a href="<?= APP_URL ?>/installments.php?status=overdue" class="dx-more">هەموو</a>
        </div>
        <div class="dx-list">
            <?php foreach (array_slice($overdueInstallments ?? [], 0, 5) as $inst): ?>
            <div class="dx-row">
                <span class="dx-avatar" style="color: #dc2626; background: #fee2e2;"><i class="fas fa-triangle-exclamation" style="font-size: 14px;"></i></span>
                <div class="dx-main">
                    <span class="dx-name"><?= htmlspecialchars($inst['patient_name']) ?></span>
                    <div class="dx-sub">
                        <a href="tel:<?= htmlspecialchars($inst['patient_phone']) ?>" class="ltr" style="color: inherit; text-decoration: none;"><i class="fas fa-phone"></i><?= htmlspecialchars($inst['patient_phone']) ?></a>
                        <span><i class="far fa-calendar-xmark"></i><?= date('Y/m/d', strtotime($inst['due_date'])) ?></span>
                    </div>
                </div>
                <b class="dx-amount dx-amount--due"><?= number_format($inst['amount'] - $inst['paid_amount']) ?></b>
            </div>
            <?php endforeach; ?>
            <?php foreach (array_slice($upcomingInstallments ?? [], 0, 5) as $inst): ?>
            <div class="dx-row">
                <span class="dx-avatar" style="color: #0f766e; background: #ccfbf1;"><i class="far fa-calendar" style="font-size: 14px;"></i></span>
                <div class="dx-main">
                    <span class="dx-name"><?= htmlspecialchars($inst['patient_name']) ?></span>
                    <div class="dx-sub"><span><i class="far fa-calendar"></i><?= date('Y/m/d', strtotime($inst['due_date'])) ?> — ئەم هەفتەیە</span></div>
                </div>
                <b class="dx-amount"><?= number_format($inst['amount']) ?></b>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<!-- Quick actions -->
<section class="dx-card">
    <div class="dx-card-head">
        <span class="dx-card-ico" style="--k: #0ea5e9"><i class="fas fa-bolt"></i></span>
        <div><h3 class="dx-card-title">کردارە خێراکان</h3></div>
    </div>
    <div class="dx-quick">
        <?php foreach ($dxQuick as [$qUrl, $qIcon, $qColor, $qLabel, $qDesc]): ?>
        <a href="<?= $qUrl ?>" class="dx-q" style="--k: <?= $qColor ?>">
            <span class="dx-q-ico"><i class="fas <?= $qIcon ?>"></i></span>
            <b><?= $qLabel ?></b>
            <small><?= $qDesc ?></small>
        </a>
        <?php endforeach; ?>
    </div>
</section>

</div><!-- .dx -->
<?php endif; ?>

</div>

<?php if ($permission->can('page.new_visit')) require __DIR__ . '/includes/new_visit_dialog.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
