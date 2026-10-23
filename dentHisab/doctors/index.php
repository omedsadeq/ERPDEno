<?php
/**
 * Doctors Management Page
 * بەڕێوەبردنی دکتۆرەکان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

if (!$permission->can('page.doctors')) {
    // Non-permitted users: redirect to own profile if doctor, else 403
    if ($auth->isDoctor()) {
        header('Location: ' . APP_URL . '/doctors/profile.php?id=' . $currentUser['id']);
    } else {
        header('Location: ' . APP_URL . '/403.php');
    }
    exit;
}

$pageTitle = 'دکتۆرەکان';
$currentPage = 'doctors';

// Filters
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$specializationFilter = $_GET['specialization'] ?? '';
$monthFilter = $_GET['month'] ?? date('Y-m'); // Default to current month

// Parse month filter for date range
$monthStart = $monthFilter . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// Build date condition for financial queries
$dateCondition = "AND pf.created_at BETWEEN '{$monthStart}' AND '{$monthEnd} 23:59:59'";
$dateConditionNone = ""; // For total stats without date filter

// Get all doctors with statistics
$sql = "SELECT u.*,
        -- Total folders (all time)
        (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id) as total_folders,
        (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id AND pf.status = 'active') as active_folders,
        (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id AND pf.status = 'archived') as archived_folders,

        -- Total cases (all time)
        (SELECT COUNT(*) FROM cases c
         INNER JOIN patient_folders pf ON c.folder_id = pf.id
         WHERE pf.doctor_id = u.id) as total_cases,
        (SELECT COUNT(*) FROM cases c
         INNER JOIN patient_folders pf ON c.folder_id = pf.id
         WHERE pf.doctor_id = u.id AND c.status = 'open') as open_cases,
        (SELECT COUNT(*) FROM cases c
         INNER JOIN patient_folders pf ON c.folder_id = pf.id
         WHERE pf.doctor_id = u.id AND c.status = 'closed') as closed_cases,

        -- Financial for selected month
        (SELECT COALESCE(SUM(pf.total_amount), 0) FROM patient_folders pf
         WHERE pf.doctor_id = u.id AND pf.created_at BETWEEN '{$monthStart}' AND '{$monthEnd} 23:59:59') as month_total,
        (SELECT COALESCE(SUM(pf.paid_amount), 0) FROM patient_folders pf
         WHERE pf.doctor_id = u.id AND pf.created_at BETWEEN '{$monthStart}' AND '{$monthEnd} 23:59:59') as month_paid,
        (SELECT COALESCE(SUM(pf.discount_amount), 0) FROM patient_folders pf
         WHERE pf.doctor_id = u.id AND pf.created_at BETWEEN '{$monthStart}' AND '{$monthEnd} 23:59:59') as month_discount,

        -- Folders created this month
        (SELECT COUNT(*) FROM patient_folders pf
         WHERE pf.doctor_id = u.id AND pf.created_at BETWEEN '{$monthStart}' AND '{$monthEnd} 23:59:59') as month_folders,

        -- Cases created this month
        (SELECT COUNT(*) FROM cases c
         INNER JOIN patient_folders pf ON c.folder_id = pf.id
         WHERE pf.doctor_id = u.id AND c.created_at BETWEEN '{$monthStart}' AND '{$monthEnd} 23:59:59') as month_cases,

        -- All time totals
        (SELECT COALESCE(SUM(pf.total_amount), 0) FROM patient_folders pf WHERE pf.doctor_id = u.id) as total_amount,
        (SELECT COALESCE(SUM(pf.paid_amount), 0) FROM patient_folders pf WHERE pf.doctor_id = u.id) as paid_amount,
        (SELECT COALESCE(SUM(pf.discount_amount), 0) FROM patient_folders pf WHERE pf.doctor_id = u.id) as discount_amount,

        -- Debt patients (all time)
        (SELECT COUNT(DISTINCT pf.patient_id) FROM patient_folders pf
         WHERE pf.doctor_id = u.id
         AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0) as debt_patients,

        -- Total debt amount (all time)
        (SELECT COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0) FROM patient_folders pf
         WHERE pf.doctor_id = u.id
         AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0) as debt_amount,

        -- Today appointments
        (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = u.id AND a.appointment_date = CURDATE() AND a.status IN ('scheduled', 'confirmed')) as today_appointments
        FROM users u
        WHERE u.role = 'doctor'";

$params = [];

if ($search) {
    $sql .= " AND (u.full_name LIKE :search OR u.phone LIKE :search2 OR u.specialization LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}

if ($statusFilter !== '') {
    $sql .= " AND u.is_active = :status";
    $params['status'] = $statusFilter;
}

if ($specializationFilter) {
    $sql .= " AND u.specialization = :specialization";
    $params['specialization'] = $specializationFilter;
}

$sql .= " ORDER BY u.full_name ASC";

$doctorsResult = $db->fetchAll($sql, $params);
$doctors = is_array($doctorsResult) ? $doctorsResult : [];

// Get specializations for filter
$specializationsResult = $db->fetchAll("SELECT DISTINCT specialization FROM users WHERE role = 'doctor' AND specialization IS NOT NULL AND specialization != '' ORDER BY specialization");
$specializations = is_array($specializationsResult) ? $specializationsResult : [];

// Overall statistics
$totalDoctors = count($doctors);
$activeDoctors = 0;
$totalFoldersAll = 0;
$totalCasesAll = 0;
$totalAmountAll = 0;
$totalPaidAll = 0;
$monthTotalAll = 0;
$monthPaidAll = 0;
$totalDebtPatientsAll = 0;
$totalDebtAmountAll = 0;

foreach ($doctors as $d) {
    if ($d['is_active']) $activeDoctors++;
    $totalFoldersAll += $d['total_folders'];
    $totalCasesAll += $d['total_cases'];
    $totalAmountAll += $d['total_amount'];
    $totalPaidAll += $d['paid_amount'];
    $monthTotalAll += $d['month_total'];
    $monthPaidAll += $d['month_paid'];
    $totalDebtPatientsAll += $d['debt_patients'];
    $totalDebtAmountAll += $d['debt_amount'];
}

$totalRemainingAll = $totalAmountAll - $totalPaidAll;
$monthRemainingAll = $monthTotalAll - $monthPaidAll;

// Get month name in Kurdish
$monthNames = [
    '01' => 'کانوونی دووەم',
    '02' => 'شوبات',
    '03' => 'ئازار',
    '04' => 'نیسان',
    '05' => 'ئایار',
    '06' => 'حوزەیران',
    '07' => 'تەمموز',
    '08' => 'ئاب',
    '09' => 'ئەیلوول',
    '10' => 'تشرینی یەکەم',
    '11' => 'تشرینی دووەم',
    '12' => 'کانوونی یەکەم'
];
$selectedMonthNum = date('m', strtotime($monthFilter . '-01'));
$selectedYear = date('Y', strtotime($monthFilter . '-01'));
$selectedMonthName = $monthNames[$selectedMonthNum] . ' ' . $selectedYear;
$formatMoney = static function ($amount): string {
    return '<span class="money-inline" dir="ltr"><bdi>' . number_format((float)$amount) . '</bdi><span class="money-currency" dir="rtl">&#1583;.&#1593;</span></span>';
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.doctors-container {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
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
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
}

.page-actions {
    display: flex;
    gap: 8px;
}

.btn-action {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
    text-decoration: none;
}

.btn-print {
    background: #6c757d;
    color: white;
}

.btn-print:hover {
    background: #5a6268;
}

/* Filters */
.filters-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 11px;
    color: #666;
    font-weight: 600;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    min-width: 150px;
}

.filter-group input:focus,
.filter-group select:focus {
    border-color: #9c27b0;
    outline: none;
}

.btn-filter {
    padding: 8px 16px;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
}

.btn-filter:hover {
    background: linear-gradient(135deg, #7b1fa2, #6a1b9a);
}

.btn-reset {
    padding: 8px 16px;
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    text-decoration: none;
}

/* Stats Cards - Compact */
.stats-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}

.stat-card {
    background: white;
    border-radius: 8px;
    padding: 8px 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    gap: 8px;
    border-right: 3px solid transparent;
}

.stat-card.doctors { border-right-color: #9c27b0; }
.stat-card.folders { border-right-color: #2196f3; }
.stat-card.cases { border-right-color: #ff9800; }
.stat-card.amount { border-right-color: #4caf50; }
.stat-card.remaining { border-right-color: #f44336; }
.stat-card.month { border-right-color: #00bcd4; }
.stat-card.debt { border-right-color: #e91e63; }

.stat-value {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
}

.stat-label {
    font-size: 11px;
    color: #888;
}

/* Month Badge */
.month-badge {
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Doctor Cards Grid */
.doctors-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}

.doctor-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    overflow: hidden;
    transition: all 0.3s;
}

.doctor-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
}

.doctor-card-header {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
}

.doctor-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 700;
    color: white;
    border: 2px solid rgba(255,255,255,0.3);
    overflow: hidden;
}

.doctor-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.doctor-info h3 {
    margin: 0 0 2px;
    font-size: 14px;
    color: white;
    font-weight: 600;
}

.doctor-info .specialization {
    font-size: 11px;
    color: rgba(255,255,255,0.8);
    display: flex;
    align-items: center;
    gap: 4px;
}

.doctor-status {
    margin-right: auto;
}

.status-badge {
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.status-active {
    background: rgba(76, 175, 80, 0.2);
    color: #a5d6a7;
    border: 1px solid rgba(76, 175, 80, 0.3);
}

.status-inactive {
    background: rgba(244, 67, 54, 0.2);
    color: #ef9a9a;
    border: 1px solid rgba(244, 67, 54, 0.3);
}

.today-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: #ff9800;
    color: white;
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 600;
}

.doctor-card-body {
    padding: 12px;
}

/* Stats Grid - 2x2 */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}

.stat-box {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 10px;
    text-align: center;
}

.stat-box .title {
    font-size: 10px;
    color: #888;
    margin-bottom: 4px;
    font-weight: 600;
}

.stat-box .numbers {
    display: flex;
    justify-content: center;
    gap: 8px;
    align-items: baseline;
}

.stat-box .total {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}

.stat-box .detail {
    font-size: 10px;
    color: #666;
}

.stat-box .detail.open { color: #4caf50; }
.stat-box .detail.closed { color: #9e9e9e; }

.stat-box.folders { border-top: 3px solid #2196f3; }
.stat-box.cases { border-top: 3px solid #ff9800; }
.stat-box.debt { border-top: 3px solid #e91e63; }

/* Stats Grid - 3 columns for debt */
.stats-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}

.stat-box .debt-amount {
    font-size: 10px;
    color: #e91e63;
    font-weight: 600;
    margin-top: 2px;
}

.money-inline {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    direction: ltr;
    unicode-bidi: isolate;
    white-space: nowrap;
}

.money-currency {
    direction: rtl;
    unicode-bidi: isolate;
}

/* Financial Section */
.financial-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 10px;
    margin-top: 8px;
}

.financial-title {
    font-size: 10px;
    color: #666;
    font-weight: 600;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.financial-title i {
    color: #00bcd4;
}

.financial-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 11px;
    border-bottom: 1px solid #e8e8e8;
}

.financial-row:last-child {
    border-bottom: none;
    padding-top: 6px;
    margin-top: 2px;
}

.financial-row .label {
    color: #666;
}

.financial-row .value {
    font-weight: 600;
}

.financial-row .value.total { color: #333; }
.financial-row .value.paid { color: #4caf50; }
.financial-row .value.remaining { color: #f44336; }

.doctor-card-footer {
    padding: 10px 12px;
    background: #fafafa;
    border-top: 1px solid #f0f0f0;
    display: flex;
    gap: 6px;
}

.btn-profile {
    flex: 1;
    padding: 7px 10px;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all 0.2s;
}

.btn-profile:hover {
    background: linear-gradient(135deg, #7b1fa2, #6a1b9a);
}

.btn-folders {
    padding: 7px 10px;
    background: white;
    color: #2196f3;
    border: 1.5px solid #2196f3;
    border-radius: 6px;
    font-size: 11px;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.btn-folders:hover {
    background: #e3f2fd;
}

.btn-cases {
    padding: 7px 10px;
    background: white;
    color: #ff9800;
    border: 1.5px solid #ff9800;
    border-radius: 6px;
    font-size: 11px;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.btn-cases:hover {
    background: #fff3e0;
}

.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #888;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.no-data i {
    font-size: 50px;
    color: #ddd;
    margin-bottom: 15px;
    display: block;
}

/* Print */
@media print {
    .no-print { display: none !important; }
    .doctor-card { break-inside: avoid; }
    .doctors-grid { gap: 10px; }
}

/* Responsive */
@media (max-width: 768px) {
    .doctors-grid {
        grid-template-columns: 1fr;
    }

    .stats-row {
        flex-direction: column;
    }

    .filters-row {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }
}
</style>

<div class="doctors-container">
    <!-- Header -->
    <div class="page-header no-print">
        <div class="page-title">
            <div class="icon"><i class="fas fa-user-md"></i></div>
            <div>
                <h2>بەڕێوەبردنی دکتۆرەکان</h2>
                <small style="color: #888;">کۆی دکتۆرەکان: <?= $totalDoctors ?></small>
            </div>
        </div>
        <div class="page-actions">
            <span class="month-badge">
                <i class="fas fa-calendar-alt"></i>
                <?= $selectedMonthName ?>
            </span>
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="fas fa-print"></i> چاپکردن
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card no-print">
        <form method="GET" action="">
            <div class="filters-row">
                <div class="filter-group">
                    <label>گەڕان</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ناو، تەلەفۆن، پسپۆڕی...">
                </div>
                <div class="filter-group">
                    <label>مانگ (دارایی)</label>
                    <input type="month" name="month" value="<?= $monthFilter ?>">
                </div>
                <div class="filter-group">
                    <label>دۆخ</label>
                    <select name="status">
                        <option value="">هەموو</option>
                        <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>چالاک</option>
                        <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>ناچالاک</option>
                    </select>
                </div>
                <?php if (!empty($specializations)): ?>
                <div class="filter-group">
                    <label>پسپۆڕی</label>
                    <select name="specialization">
                        <option value="">هەموو</option>
                        <?php foreach ($specializations as $s): ?>
                        <option value="<?= htmlspecialchars($s['specialization']) ?>" <?= $specializationFilter === $s['specialization'] ? 'selected' : '' ?>><?= htmlspecialchars($s['specialization']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> گەڕان
                </button>
                <a href="<?= APP_URL ?>/doctors/index.php" class="btn-reset">
                    <i class="fas fa-redo"></i> ڕیسێت
                </a>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <div class="stats-row no-print">
        <div class="stat-card doctors">
            <div class="stat-value"><?= $totalDoctors ?></div>
            <div class="stat-label">دکتۆر (<?= $activeDoctors ?> چالاک)</div>
        </div>
        <div class="stat-card folders">
            <div class="stat-value"><?= number_format($totalFoldersAll) ?></div>
            <div class="stat-label">کۆی دۆسیە</div>
        </div>
        <div class="stat-card cases">
            <div class="stat-value"><?= number_format($totalCasesAll) ?></div>
            <div class="stat-label">کۆی کەیس</div>
        </div>
        <div class="stat-card month">
            <div class="stat-value"><?= number_format($monthTotalAll) ?></div>
            <div class="stat-label">کۆی مانگ</div>
        </div>
        <div class="stat-card amount">
            <div class="stat-value"><?= number_format($monthPaidAll) ?></div>
            <div class="stat-label">دراو (مانگ)</div>
        </div>
        <div class="stat-card remaining">
            <div class="stat-value"><?= number_format($monthRemainingAll) ?></div>
            <div class="stat-label">ماوە (مانگ)</div>
        </div>
        <div class="stat-card debt">
            <div class="stat-value"><?= number_format($totalDebtPatientsAll) ?></div>
            <div class="stat-label">نەخۆشی قەرزار</div>
        </div>
    </div>

    <!-- Doctors Grid -->
    <?php if (empty($doctors)): ?>
    <div class="no-data">
        <i class="fas fa-user-md"></i>
        <p>هیچ دکتۆرێک نەدۆزرایەوە</p>
    </div>
    <?php else: ?>
    <div class="doctors-grid">
        <?php foreach ($doctors as $doctor): ?>
        <?php $monthRemaining = $doctor['month_total'] - $doctor['month_paid'] - $doctor['month_discount']; ?>
        <div class="doctor-card">
            <div class="doctor-card-header">
                <?php if ($doctor['today_appointments'] > 0): ?>
                <span class="today-badge">
                    <i class="fas fa-calendar-check"></i> <?= $doctor['today_appointments'] ?>
                </span>
                <?php endif; ?>

                <div class="doctor-avatar">
                    <?php if (!empty($doctor['profile_image'])): ?>
                    <img src="<?= APP_URL ?>/uploads/profiles/<?= $doctor['profile_image'] ?>" alt="">
                    <?php else: ?>
                    <?= mb_substr($doctor['full_name'], 0, 1) ?>
                    <?php endif; ?>
                </div>
                <div class="doctor-info">
                    <h3>د. <?= htmlspecialchars($doctor['full_name']) ?></h3>
                    <?php if ($doctor['specialization']): ?>
                    <div class="specialization">
                        <i class="fas fa-stethoscope"></i>
                        <?= htmlspecialchars($doctor['specialization']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="doctor-status">
                    <span class="status-badge <?= $doctor['is_active'] ? 'status-active' : 'status-inactive' ?>">
                        <?= $doctor['is_active'] ? 'چالاک' : 'ناچالاک' ?>
                    </span>
                </div>
            </div>

            <div class="doctor-card-body">
                <!-- Folders, Cases & Debt Stats -->
                <div class="stats-grid-3">
                    <div class="stat-box folders">
                        <div class="title"><i class="fas fa-folder"></i> دۆسیەکان</div>
                        <div class="numbers">
                            <span class="total"><?= $doctor['total_folders'] ?></span>
                        </div>
                        <div class="detail open"><?= $doctor['active_folders'] ?> کراوە</div>
                        <div class="detail closed"><?= $doctor['archived_folders'] ?> داخراو</div>
                    </div>
                    <div class="stat-box cases">
                        <div class="title"><i class="fas fa-briefcase-medical"></i> کەیسەکان</div>
                        <div class="numbers">
                            <span class="total"><?= $doctor['total_cases'] ?></span>
                        </div>
                        <div class="detail open"><?= $doctor['open_cases'] ?> کراوە</div>
                        <div class="detail closed"><?= $doctor['closed_cases'] ?> داخراو</div>
                    </div>
                    <div class="stat-box debt">
                        <div class="title"><i class="fas fa-user-clock"></i> قەرزاران</div>
                        <div class="numbers">
                            <span class="total"><?= $doctor['debt_patients'] ?></span>
                        </div>
                        <div class="debt-amount"><?= $formatMoney($doctor['debt_amount']) ?></div>
                    </div>
                </div>

                <!-- Monthly Financial -->
                <div class="financial-section">
                    <div class="financial-title">
                        <i class="fas fa-calendar-alt"></i>
                        دارایی مانگ (<?= $doctor['month_folders'] ?> دۆسیە نوێ)
                    </div>
                    <div class="financial-row">
                        <span class="label">کۆی گشتی:</span>
                        <span class="value total"><?= $formatMoney($doctor['month_total']) ?></span>
                    </div>
                    <div class="financial-row">
                        <span class="label">پارەی دراو:</span>
                        <span class="value paid"><?= $formatMoney($doctor['month_paid']) ?></span>
                    </div>
                    <div class="financial-row">
                        <span class="label">ماوە:</span>
                        <span class="value remaining"><?= $formatMoney($monthRemaining) ?></span>
                    </div>
                </div>
            </div>

            <div class="doctor-card-footer">
                <a href="<?= APP_URL ?>/doctors/profile.php?id=<?= $doctor['id'] ?>" class="btn-profile">
                    <i class="fas fa-user"></i> پرۆفایل
                </a>
                <a href="<?= APP_URL ?>/folders/index.php?doctor=<?= $doctor['id'] ?>" class="btn-folders">
                    <i class="fas fa-folder"></i>
                </a>
                <a href="<?= APP_URL ?>/cases/index.php?doctor=<?= $doctor['id'] ?>" class="btn-cases">
                    <i class="fas fa-briefcase-medical"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
