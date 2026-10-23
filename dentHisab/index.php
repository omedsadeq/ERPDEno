<?php
/**
 * Dashboard - Main Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'داشبۆرد';

require_once __DIR__ . '/includes/header.php';
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

// Stats for all users with relevant permissions
$stats['today_appointments'] = $db->count('appointments', "appointment_date = CURDATE()");
$stats['waiting'] = $db->count('waiting_room', "status = 'waiting'");
$stats['today_visits'] = $db->count('visits', "DATE(visit_date) = CURDATE()");

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

// Waiting room patients
$waitingPatients = $db->fetchAll(
    "SELECT wr.*, p.full_name, p.phone, u.full_name as doctor_name
     FROM waiting_room wr
     LEFT JOIN patients p ON wr.patient_id = p.id
     LEFT JOIN users u ON wr.doctor_id = u.id
     WHERE wr.status = 'waiting'
     ORDER BY wr.priority DESC, wr.check_in_time"
);
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
    background: linear-gradient(135deg, #0078d4, #005a9e);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.3);
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
.icon-blue { background: linear-gradient(135deg, #0078d4, #005a9e); }
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
    background: #0078d4;
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
                    <div class="notif-value" style="color: #0078d4;"><?= $stats['today_visits'] ?></div>
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
            <a href="<?= APP_URL ?>/new_visit.php" class="quick-action-card">
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

<!-- Page Header -->
<div class="page-header">
    <div class="page-title">
        <div class="icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <h2>داشبۆرد</h2>
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
        <div class="stat-icon icon-blue">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['total_patients']) ?></div>
            <div class="stat-label">کۆی نەخۆشەکان</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-green">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_appointments']) ?></div>
            <div class="stat-label">مەوعیدەکانی ئەمڕۆ</div>
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

    <a href="<?= APP_URL ?>/visits.php?today=1" class="stat-card" style="text-decoration: none; color: inherit;">
        <div class="stat-icon icon-teal">
            <i class="fas fa-clinic-medical"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_visits']) ?></div>
            <div class="stat-label">سەردانەکانی ئەمڕۆ</div>
        </div>
    </a>

    <div class="stat-card">
        <div class="stat-icon icon-purple">
            <i class="fas fa-folder-open"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['active_cases'] ?? 0) ?></div>
            <div class="stat-label">کەیسی کراوە</div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="stat-card">
        <div class="stat-icon icon-green">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['today_income']) ?></div>
            <div class="stat-label">داهاتی ئەمڕۆ</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-blue">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['monthly_income']) ?></div>
            <div class="stat-label">داهاتی ئەم مانگە</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-orange">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['pending_payments']) ?></div>
            <div class="stat-label">پارەدانی چاوەڕوان</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon icon-red">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['overdue_installments']) ?></div>
            <div class="stat-label">قیستی دواکەوتوو</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($auth->isDoctor()): ?>
<!-- Doctor's Current Patient & Waiting Queue -->
<div class="grid-2" style="margin-bottom: 16px;">
    <!-- Current Patient Card -->
    <div>
        <?php if ($currentPatient): ?>
        <div class="current-patient-box">
            <div class="patient-label"><i class="fas fa-user"></i> نەخۆشی ئێستا</div>
            <div class="patient-name"><?= htmlspecialchars($currentPatient['full_name']) ?></div>
            <div class="patient-info">
                <span><i class="fas fa-id-card"></i> <?= htmlspecialchars($currentPatient['patient_code']) ?></span>
                <span><i class="fas fa-phone"></i> <?= htmlspecialchars($currentPatient['phone']) ?></span>
                <span><i class="fas fa-clock"></i> <?= $currentPatient['waiting_minutes'] ?> خولەک چاوەڕوانی</span>
            </div>
            <div style="margin-top: 14px;">
                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $currentPatient['patient_id'] ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-eye"></i> بینینی پڕۆفایل
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="current-patient-box" style="background: linear-gradient(135deg, #5c5c5c 0%, #3d3d3d 100%); text-align: center;">
            <i class="fas fa-user-clock" style="font-size: 40px; opacity: 0.5; margin-bottom: 12px;"></i>
            <div class="patient-name" style="font-size: 16px; opacity: 0.8;">هیچ نەخۆشێک لە چاوپێکەوتندا نییە</div>
            <div style="margin-top: 14px;">
                <a href="<?= APP_URL ?>/waiting-room.php" class="btn btn-light btn-sm">
                    <i class="fas fa-users"></i> ڕۆیشتن بۆ ژووری چاوەڕوانی
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Waiting Queue Mini -->
    <div class="waiting-queue-box">
        <div class="queue-header">
            <h4>
                <i class="fas fa-user-clock"></i>
                ڕیزی چاوەڕوانی
                <?php if (!empty($waitingQueue)): ?>
                <span class="queue-count"><?= count($waitingQueue) ?></span>
                <?php endif; ?>
            </h4>
            <a href="<?= APP_URL ?>/waiting-room.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-external-link-alt"></i>
            </a>
        </div>
        <?php if (!empty($waitingQueue)): ?>
        <div>
            <?php $position = 1; foreach ($waitingQueue as $queueItem): ?>
            <div class="queue-item">
                <div class="queue-num"><?= $position ?></div>
                <div class="queue-name"><?= htmlspecialchars($queueItem['full_name']) ?></div>
                <div class="queue-time"><i class="fas fa-clock"></i> <?= $queueItem['waiting_minutes'] ?> خولەک</div>
            </div>
            <?php $position++; endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state-mini">
            <i class="fas fa-check-circle" style="color: #4caf50;"></i>
            <p>هیچ کەسێک لە چاوەڕوانیدا نییە</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Main Content Grid -->
<div class="grid-2">
    <!-- Today's Appointments -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="header-title">
                <div class="card-icon icon-purple">
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
                        <th>دۆخ</th>
                        <th>کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todayAppointments as $apt): ?>
                    <tr>
                        <td><strong><?= date('H:i', strtotime($apt['appointment_time'])) ?></strong></td>
                        <td>
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $apt['patient_id'] ?>">
                                <?= htmlspecialchars($apt['patient_name']) ?>
                            </a>
                        </td>
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
                        <td>
                            <a href="<?= APP_URL ?>/appointments.php?action=check-in&id=<?= $apt['id'] ?>"
                               class="btn btn-sm btn-success" title="هات">
                                <i class="fas fa-check"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($waitingPatients as $index => $wp): ?>
                    <tr>
                        <td><strong><?= $index + 1 ?></strong></td>
                        <td><?= htmlspecialchars($wp['full_name']) ?></td>
                        <td><?= date('H:i', strtotime($wp['check_in_time'])) ?></td>
                        <td><?= htmlspecialchars($wp['doctor_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Patients -->
    <?php if (isset($recentPatients)): ?>
    <div class="form-card">
        <div class="form-card-header">
            <div class="header-title">
                <div class="card-icon icon-blue">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h3>نەخۆشە نوێکان</h3>
            </div>
            <a href="<?= APP_URL ?>/patients/index.php" class="btn btn-sm btn-secondary">هەموو</a>
        </div>
        <div class="form-card-body" style="padding: 0;">
            <?php if (empty($recentPatients)): ?>
            <div class="empty-state-mini">
                <i class="fas fa-users"></i>
                <p>هیچ نەخۆشێک نییە</p>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>کۆد</th>
                        <th>ناو</th>
                        <th>مۆبایل</th>
                        <th>بەروار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPatients as $patient): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>">
                                <?= $patient['patient_code'] ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($patient['full_name']) ?></td>
                        <td><?= $patient['phone'] ?></td>
                        <td><?= date('Y-m-d', strtotime($patient['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin && !empty($overdueInstallments)): ?>
    <!-- Overdue Installments -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="header-title">
                <div class="card-icon icon-red">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>قیستە دواکەوتووەکان</h3>
            </div>
            <a href="<?= APP_URL ?>/installments.php?status=overdue" class="btn btn-sm btn-danger">هەموو</a>
        </div>
        <div class="form-card-body" style="padding: 0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th>مۆبایل</th>
                        <th>بڕ</th>
                        <th>بەرواری دوا</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($overdueInstallments, 0, 5) as $inst): ?>
                    <tr>
                        <td><?= htmlspecialchars($inst['patient_name']) ?></td>
                        <td>
                            <a href="tel:<?= $inst['patient_phone'] ?>"><?= $inst['patient_phone'] ?></a>
                        </td>
                        <td style="color: #f44336; font-weight: 600;">
                            <?= number_format($inst['amount'] - $inst['paid_amount']) ?>
                        </td>
                        <td><?= date('Y-m-d', strtotime($inst['due_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($upcomingInstallments) && !empty($upcomingInstallments)): ?>
    <!-- Upcoming Installments -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="header-title">
                <div class="card-icon icon-teal">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h3>قیستەکانی ئەم هەفتەیە</h3>
            </div>
        </div>
        <div class="form-card-body" style="padding: 0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th>بڕ</th>
                        <th>بەروار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($upcomingInstallments, 0, 5) as $inst): ?>
                    <tr>
                        <td><?= htmlspecialchars($inst['patient_name']) ?></td>
                        <td><?= number_format($inst['amount']) ?></td>
                        <td><?= date('Y-m-d', strtotime($inst['due_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Actions Panel -->
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
            <a href="<?= APP_URL ?>/patients/add.php" class="quick-action-card">
                <div class="action-icon icon-teal">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="action-label">زیادکردنی نەخۆش</div>
                <div class="action-desc">تۆمارکردنی نەخۆشی نوێ</div>
            </a>

            <a href="<?= APP_URL ?>/appointments.php?action=add" class="quick-action-card">
                <div class="action-icon icon-green">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="action-label">مەوعیدی نوێ</div>
                <div class="action-desc">داڕشتنی مەوعید</div>
            </a>

            <a href="<?= APP_URL ?>/waiting-room.php" class="quick-action-card">
                <div class="action-icon icon-orange">
                    <i class="fas fa-users"></i>
                </div>
                <div class="action-label">ژووری چاوەڕوانی</div>
                <div class="action-desc">بەڕێوەبردنی چاوەڕوانی</div>
            </a>

            <?php if ($isAdmin): ?>
            <a href="<?= APP_URL ?>/admin/payments.php?action=add" class="quick-action-card">
                <div class="action-icon icon-blue">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="action-label">وەرگرتنی پارە</div>
                <div class="action-desc">تۆمارکردنی پارەدان</div>
            </a>

            <a href="<?= APP_URL ?>/admin/reports.php" class="quick-action-card">
                <div class="action-icon icon-purple">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="action-label">ڕاپۆرتەکان</div>
                <div class="action-desc">ڕاپۆرتی دارایی</div>
            </a>

            <a href="<?= APP_URL ?>/admin/treatments.php" class="quick-action-card">
                <div class="action-icon icon-red">
                    <i class="fas fa-tooth"></i>
                </div>
                <div class="action-label">چارەسەرەکان</div>
                <div class="action-desc">لیستی چارەسەر</div>
            </a>

            <a href="<?= APP_URL ?>/admin/expenses-general.php" class="quick-action-card">
                <div class="action-icon icon-orange">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="action-label">خەرجی گشتی</div>
                <div class="action-desc">بەڕێوەبردنی خەرجی</div>
            </a>

            <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="quick-action-card">
                <div class="action-icon icon-pink">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="action-label">خەرجی تاقیگە</div>
                <div class="action-desc">خەرجییەکانی تاقیگە</div>
            </a>

            <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="quick-action-card">
                <div class="action-icon icon-indigo">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="action-label">خەرجی کۆگا</div>
                <div class="action-desc">خەرجییەکانی کۆگا</div>
            </a>

            <a href="<?= APP_URL ?>/admin/expense-groups.php" class="quick-action-card">
                <div class="action-icon" style="background: linear-gradient(135deg, #607d8b, #455a64);">
                    <i class="fas fa-folder"></i>
                </div>
                <div class="action-label">گرووپەکانی خەرجی</div>
                <div class="action-desc">بەڕێوەبردنی گرووپەکان</div>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
