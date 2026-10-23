<?php
/**
 * Doctor Profile Page
 * پرۆفایلی دکتۆر
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

if (!$permission->can('page.doctor_profile')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$doctorId = intval($_GET['id'] ?? 0);

// If no ID provided, show current user's profile (if doctor)
if (!$doctorId) {
    if ($auth->isDoctor()) {
        $doctorId = $currentUser['id'];
    } else {
        header('Location: ' . APP_URL . '/doctors/index.php');
        exit;
    }
}

// Users with page.doctors permission can see any doctor profile, others only their own
if (!$permission->can('page.doctors') && $doctorId != $currentUser['id']) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Get doctor info with statistics
$doctor = $db->fetch("SELECT u.*,
    (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id) as total_folders,
    (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id AND pf.status = 'active') as active_folders,
    (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id AND pf.status = 'archived') as archived_folders,
    (SELECT COUNT(*) FROM cases c INNER JOIN patient_folders pf ON c.folder_id = pf.id WHERE pf.doctor_id = u.id) as total_cases,
    (SELECT COUNT(*) FROM cases c INNER JOIN patient_folders pf ON c.folder_id = pf.id WHERE pf.doctor_id = u.id AND c.status = 'open') as open_cases,
    (SELECT COUNT(*) FROM cases c INNER JOIN patient_folders pf ON c.folder_id = pf.id WHERE pf.doctor_id = u.id AND c.status = 'closed') as closed_cases,
    (SELECT COUNT(DISTINCT pf.patient_id) FROM patient_folders pf WHERE pf.doctor_id = u.id) as total_patients,
    (SELECT COALESCE(SUM(pf.total_amount), 0) FROM patient_folders pf WHERE pf.doctor_id = u.id) as total_amount,
    (SELECT COALESCE(SUM(pf.paid_amount), 0) FROM patient_folders pf WHERE pf.doctor_id = u.id) as paid_amount,
    (SELECT COALESCE(SUM(pf.discount_amount), 0) FROM patient_folders pf WHERE pf.doctor_id = u.id) as discount_amount
    FROM users u WHERE u.id = :id", ['id' => $doctorId]);

if (!$doctor || $doctor['role'] !== 'doctor') {
    header('Location: ' . APP_URL . '/doctors/index.php');
    exit;
}

$pageTitle = 'د. ' . $doctor['full_name'];
$currentPage = 'doctors';

// Calculate remaining
$remainingAmount = $doctor['total_amount'] - $doctor['paid_amount'] - $doctor['discount_amount'];
$paymentPercentage = $doctor['total_amount'] > 0 ? round(($doctor['paid_amount'] / $doctor['total_amount']) * 100) : 0;

// Get debt patients
$debtPatientsResult = $db->fetchAll("SELECT p.*, pf.folder_name,
    (pf.total_amount - pf.paid_amount - pf.discount_amount) as debt_amount
    FROM patients p
    INNER JOIN patient_folders pf ON p.id = pf.patient_id
    WHERE pf.doctor_id = :doctor_id
    AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0
    ORDER BY debt_amount DESC
    LIMIT 10", ['doctor_id' => $doctorId]);
$debtPatients = is_array($debtPatientsResult) ? $debtPatientsResult : [];

$totalDebtPatientsResult = $db->fetch("SELECT COUNT(DISTINCT pf.patient_id) as count
    FROM patient_folders pf
    WHERE pf.doctor_id = :doctor_id
    AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0", ['doctor_id' => $doctorId]);
$totalDebtPatients = $totalDebtPatientsResult['count'] ?? 0;

// Get recent folders
$recentFoldersResult = $db->fetchAll("SELECT pf.*, p.full_name as patient_name, p.patient_code,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count
    FROM patient_folders pf
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id
    ORDER BY pf.updated_at DESC
    LIMIT 5", ['doctor_id' => $doctorId]);
$recentFolders = is_array($recentFoldersResult) ? $recentFoldersResult : [];

// Get monthly statistics (last 6 months)
$monthlyStatsResult = $db->fetchAll("SELECT
    DATE_FORMAT(pf.created_at, '%Y-%m') as month,
    COUNT(*) as folders,
    COALESCE(SUM(pf.total_amount), 0) as amount,
    COALESCE(SUM(pf.paid_amount), 0) as paid
    FROM patient_folders pf
    WHERE pf.doctor_id = :doctor_id
    AND pf.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(pf.created_at, '%Y-%m')
    ORDER BY month DESC", ['doctor_id' => $doctorId]);
$monthlyStats = is_array($monthlyStatsResult) ? $monthlyStatsResult : [];

// Folder types distribution
$folderTypesResult = $db->fetchAll("SELECT folder_type, COUNT(*) as count
    FROM patient_folders
    WHERE doctor_id = :doctor_id
    GROUP BY folder_type
    ORDER BY count DESC", ['doctor_id' => $doctorId]);
$folderTypes = is_array($folderTypesResult) ? $folderTypesResult : [];

$typeLabels = [
    'general' => 'گشتی',
    'cosmetic' => 'جوانکاری',
    'orthodontic' => 'ڕاستکردنەوە',
    'surgery' => 'نەشتەرگەری',
    'other' => 'هی تر'
];

// Get active folders list
$activeFoldersResult = $db->fetchAll("SELECT pf.*, p.full_name as patient_name, p.patient_code,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id AND c.status = 'open') as open_case_count
    FROM patient_folders pf
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND pf.status = 'active'
    ORDER BY pf.updated_at DESC
    LIMIT 10", ['doctor_id' => $doctorId]);
$activeFolders = is_array($activeFoldersResult) ? $activeFoldersResult : [];

// Get archived folders list (for modal)
$archivedFoldersResult = $db->fetchAll("SELECT pf.*, p.full_name as patient_name, p.patient_code,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count
    FROM patient_folders pf
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND pf.status = 'archived'
    ORDER BY pf.updated_at DESC
    LIMIT 50", ['doctor_id' => $doctorId]);
$archivedFolders = is_array($archivedFoldersResult) ? $archivedFoldersResult : [];

// Get open cases list
$openCasesResult = $db->fetchAll("SELECT c.*, pf.folder_name, p.full_name as patient_name, p.patient_code
    FROM cases c
    INNER JOIN patient_folders pf ON c.folder_id = pf.id
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND c.status = 'open'
    ORDER BY c.updated_at DESC
    LIMIT 10", ['doctor_id' => $doctorId]);
$openCases = is_array($openCasesResult) ? $openCasesResult : [];

// Get closed cases list (for modal)
$closedCasesResult = $db->fetchAll("SELECT c.*, pf.folder_name, p.full_name as patient_name, p.patient_code
    FROM cases c
    INNER JOIN patient_folders pf ON c.folder_id = pf.id
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND c.status = 'closed'
    ORDER BY c.updated_at DESC
    LIMIT 50", ['doctor_id' => $doctorId]);
$closedCases = is_array($closedCasesResult) ? $closedCasesResult : [];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profile-container {
    max-width: 1200px;
    margin: 0 auto;
}

/* Profile Header */
.profile-header {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 24px;
    box-shadow: 0 6px 24px rgba(156, 39, 176, 0.3);
    position: relative;
    overflow: hidden;
}

.profile-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
}

.profile-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    font-weight: 700;
    color: white;
    border: 4px solid rgba(255,255,255,0.3);
    flex-shrink: 0;
    overflow: hidden;
    z-index: 1;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-info {
    flex: 1;
    z-index: 1;
}

.profile-info h1 {
    margin: 0 0 6px;
    font-size: 26px;
    color: white;
    font-weight: 700;
}

.profile-info .specialization {
    font-size: 14px;
    color: rgba(255,255,255,0.85);
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}

.profile-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.profile-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: rgba(255,255,255,0.8);
}

.profile-actions {
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.btn-back {
    padding: 10px 20px;
    background: rgba(255,255,255,0.2);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 8px;
    font-size: 13px;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-back:hover {
    background: rgba(255,255,255,0.3);
}

.status-badge-lg {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}

.status-active-lg {
    background: rgba(76, 175, 80, 0.2);
    color: #a5d6a7;
    border: 1px solid rgba(76, 175, 80, 0.3);
}

.status-inactive-lg {
    background: rgba(244, 67, 54, 0.2);
    color: #ef9a9a;
    border: 1px solid rgba(244, 67, 54, 0.3);
}

/* Stats Cards */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card-lg {
    background: white;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    text-align: center;
    border-top: 4px solid transparent;
    transition: all 0.2s;
}

.stat-card-lg:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.stat-card-lg.patients { border-top-color: #2196f3; }
.stat-card-lg.folders { border-top-color: #9c27b0; }
.stat-card-lg.cases { border-top-color: #ff9800; }
.stat-card-lg.amount { border-top-color: #4caf50; }
.stat-card-lg.remaining { border-top-color: #f44336; }
.stat-card-lg.debt { border-top-color: #ff5722; }

.stat-card-lg .icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 20px;
}

.stat-card-lg.patients .icon { background: #e3f2fd; color: #1976d2; }
.stat-card-lg.folders .icon { background: #f3e5f5; color: #7b1fa2; }
.stat-card-lg.cases .icon { background: #fff3e0; color: #f57c00; }
.stat-card-lg.amount .icon { background: #e8f5e9; color: #388e3c; }
.stat-card-lg.remaining .icon { background: #ffebee; color: #d32f2f; }
.stat-card-lg.debt .icon { background: #fbe9e7; color: #e64a19; }

.stat-card-lg .value {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a1a;
}

.stat-card-lg .label {
    font-size: 12px;
    color: #888;
    margin-top: 4px;
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
}

/* Panel */
.panel {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    margin-bottom: 20px;
}

.panel-header {
    padding: 14px 18px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-title {
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.panel-title i {
    color: #9c27b0;
}

.panel-badge {
    background: #9c27b0;
    color: white;
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}

.panel-body {
    padding: 16px 18px;
}

.panel-link {
    font-size: 12px;
    color: #9c27b0;
    text-decoration: none;
}

.panel-link:hover {
    text-decoration: underline;
}

/* Recent Folders Table */
.mini-table {
    width: 100%;
    font-size: 12px;
}

.mini-table th {
    text-align: right;
    padding: 10px 8px;
    background: #f8f9fa;
    color: #666;
    font-weight: 600;
    border-bottom: 1px solid #e0e0e0;
}

.mini-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0f0f0;
}

.mini-table tr:last-child td {
    border-bottom: none;
}

.mini-table a {
    color: #9c27b0;
    text-decoration: none;
    font-weight: 600;
}

.mini-table a:hover {
    text-decoration: underline;
}

.status-sm {
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.status-sm.active { background: #e8f5e9; color: #2e7d32; }
.status-sm.archived { background: #f5f5f5; color: #616161; }

/* Debt Patients */
.debt-list {
    max-height: 300px;
    overflow-y: auto;
}

.debt-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}

.debt-item:last-child {
    border-bottom: none;
}

.debt-patient {
    display: flex;
    flex-direction: column;
}

.debt-patient-name {
    font-size: 13px;
    font-weight: 600;
    color: #333;
}

.debt-folder {
    font-size: 11px;
    color: #888;
}

.debt-amount {
    font-size: 14px;
    font-weight: 700;
    color: #f44336;
}

/* Folder Types */
.type-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.type-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.type-bar {
    flex: 1;
    height: 24px;
    background: #f0f0f0;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
}

.type-bar-fill {
    height: 100%;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    padding: 0 10px;
    min-width: 40px;
}

.type-bar-fill span {
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.type-label {
    font-size: 12px;
    color: #666;
    min-width: 80px;
}

.type-count {
    font-size: 12px;
    font-weight: 600;
    color: #333;
    min-width: 30px;
    text-align: left;
}

/* Progress */
.progress-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #f0f0f0;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.progress-label {
    font-size: 12px;
    color: #666;
}

.progress-value {
    font-size: 12px;
    font-weight: 600;
    color: #4caf50;
}

.progress-bar {
    height: 10px;
    background: #f0f0f0;
    border-radius: 5px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #66bb6a);
    border-radius: 5px;
    transition: width 0.5s ease;
}

/* No Data */
.no-data-sm {
    text-align: center;
    padding: 30px 15px;
    color: #888;
}

.no-data-sm i {
    font-size: 30px;
    color: #ddd;
    margin-bottom: 10px;
    display: block;
}

/* Responsive */
@media (max-width: 1024px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }

    .profile-meta {
        justify-content: center;
    }

    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Button for showing closed items */
.btn-show-closed {
    padding: 6px 12px;
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 11px;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.btn-show-closed:hover {
    background: #eeeeee;
    border-color: #bdbdbd;
    color: #333;
}

.btn-show-closed .count {
    background: #9e9e9e;
    color: white;
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 600;
}

/* Active/Open status highlight */
.status-sm.open { background: #e3f2fd; color: #1976d2; }

/* Case item in list */
.case-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}

.case-item:last-child {
    border-bottom: none;
}

.case-info {
    flex: 1;
}

.case-treatment {
    font-size: 13px;
    font-weight: 600;
    color: #333;
    margin-bottom: 2px;
}

.case-patient {
    font-size: 11px;
    color: #888;
}

.case-folder {
    font-size: 10px;
    color: #9c27b0;
}

.case-amount {
    text-align: left;
    font-size: 12px;
}

.case-amount .total {
    font-weight: 600;
    color: #333;
}

.case-amount .remaining {
    font-size: 10px;
    color: #f44336;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 20px;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 16px;
    width: 100%;
    max-width: 700px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    animation: modalSlide 0.3s ease;
}

@keyframes modalSlide {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.modal-title i {
    color: #9c27b0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border: none;
    background: #f5f5f5;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #eeeeee;
    color: #333;
}

.modal-body {
    padding: 16px 20px;
    overflow-y: auto;
    flex: 1;
}

.modal-table {
    width: 100%;
    font-size: 12px;
}

.modal-table th {
    text-align: right;
    padding: 10px 8px;
    background: #f8f9fa;
    color: #666;
    font-weight: 600;
    border-bottom: 1px solid #e0e0e0;
    position: sticky;
    top: 0;
}

.modal-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0f0f0;
}

.modal-table tr:last-child td {
    border-bottom: none;
}

.modal-table a {
    color: #9c27b0;
    text-decoration: none;
    font-weight: 600;
}

.modal-table a:hover {
    text-decoration: underline;
}

.modal-table .amount {
    font-weight: 600;
    color: #333;
}

/* Print */
@media print {
    .no-print { display: none !important; }
    .profile-header { box-shadow: none; }
    .panel { break-inside: avoid; }
    .modal-overlay { display: none !important; }
}
</style>

<div class="profile-container">
    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar">
            <?php if (!empty($doctor['profile_image'])): ?>
            <img src="<?= APP_URL ?>/uploads/profiles/<?= $doctor['profile_image'] ?>" alt="">
            <?php else: ?>
            <?= mb_substr($doctor['full_name'], 0, 1) ?>
            <?php endif; ?>
        </div>

        <div class="profile-info">
            <h1>د. <?= htmlspecialchars($doctor['full_name']) ?></h1>
            <?php if ($doctor['specialization']): ?>
            <div class="specialization">
                <i class="fas fa-stethoscope"></i>
                <?= htmlspecialchars($doctor['specialization']) ?>
            </div>
            <?php endif; ?>
            <div class="profile-meta">
                <?php if ($doctor['phone']): ?>
                <div class="profile-meta-item">
                    <i class="fas fa-phone"></i>
                    <?= htmlspecialchars($doctor['phone']) ?>
                </div>
                <?php endif; ?>
                <?php if ($doctor['email']): ?>
                <div class="profile-meta-item">
                    <i class="fas fa-envelope"></i>
                    <?= htmlspecialchars($doctor['email']) ?>
                </div>
                <?php endif; ?>
                <div class="profile-meta-item">
                    <i class="fas fa-calendar"></i>
                    ئەندام لە: <?= date('Y/m/d', strtotime($doctor['created_at'])) ?>
                </div>
            </div>
        </div>

        <div class="profile-actions no-print">
            <span class="status-badge-lg <?= $doctor['is_active'] ? 'status-active-lg' : 'status-inactive-lg' ?>">
                <i class="fas <?= $doctor['is_active'] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                <?= $doctor['is_active'] ? 'چالاک' : 'ناچالاک' ?>
            </span>
            <?php if ($isAdmin): ?>
            <a href="<?= APP_URL ?>/doctors/index.php" class="btn-back">
                <i class="fas fa-arrow-right"></i> گەڕانەوە
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-cards">
        <a href="<?= APP_URL ?>/patients/index.php?doctor_id=<?= $doctor['id'] ?>" class="stat-card-lg patients" style="text-decoration: none; color: inherit;">
            <div class="icon"><i class="fas fa-users"></i></div>
            <div class="value"><?= number_format($doctor['total_patients']) ?></div>
            <div class="label">کۆی نەخۆشەکان</div>
        </a>
        <div class="stat-card-lg folders">
            <div class="icon"><i class="fas fa-folder-open"></i></div>
            <div class="value"><?= number_format($doctor['total_folders']) ?></div>
            <div class="label">کۆی دۆسیەکان (<?= $doctor['active_folders'] ?> کراوە)</div>
        </div>
        <div class="stat-card-lg cases">
            <div class="icon"><i class="fas fa-briefcase-medical"></i></div>
            <div class="value"><?= number_format($doctor['total_cases']) ?></div>
            <div class="label">کۆی کەیسەکان (<?= $doctor['open_cases'] ?> کراوە)</div>
        </div>
        <div class="stat-card-lg amount">
            <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="value"><?= number_format($doctor['paid_amount']) ?></div>
            <div class="label">پارەی وەرگیراو</div>
        </div>
        <div class="stat-card-lg remaining">
            <div class="icon"><i class="fas fa-clock"></i></div>
            <div class="value"><?= number_format($remainingAmount) ?></div>
            <div class="label">پارەی ماوە</div>
        </div>
        <div class="stat-card-lg debt">
            <div class="icon"><i class="fas fa-user-clock"></i></div>
            <div class="value"><?= number_format($totalDebtPatients) ?></div>
            <div class="label">نەخۆشی قەرزار</div>
        </div>
    </div>

    <div class="content-grid">
        <!-- Main Content -->
        <div>
            <!-- Active Folders -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-folder-open"></i>
                        دۆسیە کراوەکان
                        <span class="panel-badge" style="background: #4caf50;"><?= $doctor['active_folders'] ?></span>
                    </h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if ($doctor['archived_folders'] > 0): ?>
                        <button class="btn-show-closed" onclick="openModal('archivedFoldersModal')">
                            <i class="fas fa-archive"></i>
                            داخراو
                            <span class="count"><?= $doctor['archived_folders'] ?></span>
                        </button>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/folders/index.php?doctor=<?= $doctor['id'] ?>" class="panel-link">هەموو</a>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if (empty($activeFolders)): ?>
                    <div class="no-data-sm">
                        <i class="fas fa-folder-open"></i>
                        <p>هیچ دۆسیەیەکی کراوە نییە</p>
                    </div>
                    <?php else: ?>
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>نەخۆش</th>
                                <th>دۆسیە</th>
                                <th>کەیس کراوە</th>
                                <th>بڕی ماوە</th>
                                <th>بەروار</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeFolders as $folder):
                                $folderRemaining = $folder['total_amount'] - $folder['paid_amount'] - $folder['discount_amount'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($folder['patient_name']) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/folders/view.php?id=<?= $folder['id'] ?>">
                                        <?= htmlspecialchars($folder['folder_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="status-sm open"><?= $folder['open_case_count'] ?>/<?= $folder['case_count'] ?></span>
                                </td>
                                <td style="color: <?= $folderRemaining > 0 ? '#f44336' : '#4caf50' ?>; font-weight: 600;">
                                    <?= number_format($folderRemaining) ?>
                                </td>
                                <td><?= date('m/d', strtotime($folder['updated_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Open Cases -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-briefcase-medical"></i>
                        کەیسە کراوەکان
                        <span class="panel-badge" style="background: #ff9800;"><?= $doctor['open_cases'] ?></span>
                    </h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if ($doctor['closed_cases'] > 0): ?>
                        <button class="btn-show-closed" onclick="openModal('closedCasesModal')">
                            <i class="fas fa-check-circle"></i>
                            داخراو
                            <span class="count"><?= $doctor['closed_cases'] ?></span>
                        </button>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/cases/index.php?doctor=<?= $doctor['id'] ?>" class="panel-link">هەموو</a>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if (empty($openCases)): ?>
                    <div class="no-data-sm">
                        <i class="fas fa-briefcase-medical"></i>
                        <p>هیچ کەیسێکی کراوە نییە</p>
                    </div>
                    <?php else: ?>
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>نەخۆش</th>
                                <th>کەیس</th>
                                <th>دۆسیە</th>
                                <th>بڕی ماوە</th>
                                <th>بەروار</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($openCases as $case):
                                $caseRemaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($case['patient_name']) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>">
                                        <?= htmlspecialchars($case['case_name'] ?? 'کەیس') ?>
                                    </a>
                                </td>
                                <td style="font-size: 11px; color: #888;">
                                    <?= htmlspecialchars($case['folder_name']) ?>
                                </td>
                                <td style="color: <?= $caseRemaining > 0 ? '#f44336' : '#4caf50' ?>; font-weight: 600;">
                                    <?= number_format($caseRemaining) ?>
                                </td>
                                <td><?= date('m/d', strtotime($case['updated_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Debt Patients -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-user-clock"></i>
                        نەخۆشە قەرزارەکان
                    </h3>
                    <span class="panel-badge"><?= $totalDebtPatients ?></span>
                </div>
                <div class="panel-body">
                    <?php if (empty($debtPatients)): ?>
                    <div class="no-data-sm">
                        <i class="fas fa-check-circle" style="color: #4caf50;"></i>
                        <p>هیچ نەخۆشێکی قەرزار نییە</p>
                    </div>
                    <?php else: ?>
                    <div class="debt-list">
                        <?php foreach ($debtPatients as $dp): ?>
                        <div class="debt-item">
                            <div class="debt-patient">
                                <span class="debt-patient-name"><?= htmlspecialchars($dp['full_name']) ?></span>
                                <span class="debt-folder"><?= htmlspecialchars($dp['folder_name']) ?></span>
                            </div>
                            <span class="debt-amount"><?= number_format($dp['debt_amount']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <!-- Folder Types -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-chart-pie"></i>
                        جۆری دۆسیەکان
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($folderTypes)): ?>
                    <div class="no-data-sm">
                        <i class="fas fa-chart-pie"></i>
                        <p>هیچ داتایەک نییە</p>
                    </div>
                    <?php else: ?>
                    <div class="type-list">
                        <?php
                        $maxCount = max(array_column($folderTypes, 'count'));
                        foreach ($folderTypes as $ft):
                            $percentage = $maxCount > 0 ? ($ft['count'] / $maxCount) * 100 : 0;
                        ?>
                        <div class="type-item">
                            <span class="type-label"><?= $typeLabels[$ft['folder_type']] ?? $ft['folder_type'] ?></span>
                            <div class="type-bar">
                                <div class="type-bar-fill" style="width: <?= $percentage ?>%;">
                                    <span><?= $ft['count'] ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-calculator"></i>
                        پوختەی دارایی
                    </h3>
                </div>
                <div class="panel-body">
                    <div class="financial-row">
                        <span class="label">کۆی گشتی:</span>
                        <span class="value total"><?= number_format($doctor['total_amount']) ?></span>
                    </div>
                    <div class="financial-row">
                        <span class="label">داشکاندن:</span>
                        <span class="value" style="color: #ff9800;"><?= number_format($doctor['discount_amount']) ?></span>
                    </div>
                    <div class="financial-row">
                        <span class="label">پارەی دراو:</span>
                        <span class="value paid"><?= number_format($doctor['paid_amount']) ?></span>
                    </div>
                    <div class="financial-row" style="border-top: 2px solid #e0e0e0; padding-top: 12px; margin-top: 8px;">
                        <span class="label" style="font-weight: 600;">ماوە:</span>
                        <span class="value remaining" style="font-size: 16px;"><?= number_format($remainingAmount) ?></span>
                    </div>

                    <div class="progress-section">
                        <div class="progress-header">
                            <span class="progress-label">پێشکەوتنی پارەدان</span>
                            <span class="progress-value"><?= $paymentPercentage ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $paymentPercentage ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Archived Folders Modal -->
<div class="modal-overlay" id="archivedFoldersModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-archive"></i>
                دۆسیە داخراوەکان
            </h3>
            <button class="modal-close" onclick="closeModal('archivedFoldersModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <?php if (empty($archivedFolders)): ?>
            <div class="no-data-sm">
                <i class="fas fa-folder"></i>
                <p>هیچ دۆسیەیەکی داخراو نییە</p>
            </div>
            <?php else: ?>
            <table class="modal-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th>دۆسیە</th>
                        <th>کەیس</th>
                        <th>کۆی گشتی</th>
                        <th>بەروار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($archivedFolders as $folder): ?>
                    <tr>
                        <td><?= htmlspecialchars($folder['patient_name']) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/folders/view.php?id=<?= $folder['id'] ?>">
                                <?= htmlspecialchars($folder['folder_name']) ?>
                            </a>
                        </td>
                        <td><?= $folder['case_count'] ?></td>
                        <td class="amount"><?= number_format($folder['total_amount']) ?></td>
                        <td><?= date('Y/m/d', strtotime($folder['updated_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Closed Cases Modal -->
<div class="modal-overlay" id="closedCasesModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-check-circle"></i>
                کەیسە داخراوەکان
            </h3>
            <button class="modal-close" onclick="closeModal('closedCasesModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <?php if (empty($closedCases)): ?>
            <div class="no-data-sm">
                <i class="fas fa-briefcase-medical"></i>
                <p>هیچ کەیسێکی داخراو نییە</p>
            </div>
            <?php else: ?>
            <table class="modal-table">
                <thead>
                    <tr>
                        <th>کەیس</th>
                        <th>نەخۆش</th>
                        <th>دۆسیە</th>
                        <th>کۆی گشتی</th>
                        <th>بەروار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($closedCases as $case): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>">
                                <?= htmlspecialchars($case['case_name'] ?? 'کەیس') ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($case['patient_name']) ?></td>
                        <td><?= htmlspecialchars($case['folder_name']) ?></td>
                        <td class="amount"><?= number_format($case['total_amount']) ?></td>
                        <td><?= date('Y/m/d', strtotime($case['updated_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = '';
}

function closeModalOnOverlay(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
