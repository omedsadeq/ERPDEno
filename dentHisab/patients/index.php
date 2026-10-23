<?php
/**
 * Patients List Page
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Doctor Privacy System:
 * - Doctors see only their own patients by default
 * - Can search all clinic patients with restricted view
 * - Admin/Staff see all patients
 */

// Handle delete BEFORE any output — POST only, CSRF-checked, admin-only,
// soft delete + audit (so the redirect and 403 actually work).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_patient') {
    $authGate = new Auth();
    $authGate->requireLogin();
    if (!$authGate->isAdmin()) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }
    Csrf::require();
    $deleteId = intval($_POST['patient_id'] ?? 0);
    if ($deleteId > 0) {
        (new Patient())->delete($deleteId); // soft delete (see Patient::delete)
    }
    header('Location: ' . APP_URL . '/patients/index.php?deleted=1');
    exit;
}

$pageTitle = 'نەخۆشەکان';
$breadcrumb = [['title' => 'نەخۆشەکان']];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Patient.php';

$patientManager = new Patient();
$currentUser = $auth->getCurrentUser();
$isDoctor = $auth->isDoctor();
$isAdmin = $auth->isAdmin();

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');

// Doctor-specific filtering
$viewMode = $_GET['view_mode'] ?? 'own'; // 'own' or 'all'
$doctorId = $currentUser['id'];
$profileDoctorId = max(0, intval($_GET['doctor_id'] ?? 0));
$isDoctorPatientFilter = false;
$profileDoctor = null;

// A doctor profile may open a patient list scoped to that doctor.  Doctors may
// only request their own list; admin/staff users already have full clinic access.
if ($profileDoctorId > 0) {
    $db = Database::getInstance();
    $profileDoctor = $db->fetch(
        "SELECT id, full_name FROM users WHERE id = :id AND role = 'doctor'",
        ['id' => $profileDoctorId]
    );

    if (!$profileDoctor) {
        header('Location: ' . APP_URL . '/patients/index.php');
        exit;
    }

    if ($isDoctor && !$isAdmin && $profileDoctorId !== intval($currentUser['id'])) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }

    $isDoctorPatientFilter = true;
    $viewMode = 'own';
}

if ($isDoctorPatientFilter) {
    $patients = $patientManager->getDoctorPatients($profileDoctorId, $page, ITEMS_PER_PAGE, $search);
    $totalPatients = $patientManager->countDoctorPatients($profileDoctorId, $search);
    $doctorStats = $patientManager->getDoctorPatientsStats($profileDoctorId);
} elseif ($isDoctor && !$isAdmin) {
    // Doctor view - default to own patients
    if ($viewMode === 'own') {
        $patients = $patientManager->getDoctorPatients($doctorId, $page, ITEMS_PER_PAGE, $search);
        $totalPatients = $patientManager->countDoctorPatients($doctorId, $search);
    } else {
        // All clinic patients (restricted view)
        $patients = $patientManager->getAllPatientsRestricted($doctorId, $page, ITEMS_PER_PAGE, $search);
        $totalPatients = $patientManager->countAllPatientsRestricted($doctorId, $search);
    }
    $doctorStats = $patientManager->getDoctorPatientsStats($doctorId);
} else {
    // Admin/Staff - see all patients
    $patients = $patientManager->getAll($page, ITEMS_PER_PAGE, $search);
    $totalPatients = $patientManager->count($search);
}
$totalPages = ceil($totalPatients / ITEMS_PER_PAGE);

// Get statistics (admin/staff or doctor's own stats)
if (!$search) {
    if ($isDoctorPatientFilter || ($isDoctor && !$isAdmin)) {
        // Doctor-specific stats
        $activeFoldersCount = $doctorStats['active_folders'] ?? 0;
        $closedFoldersCount = $doctorStats['closed_folders'] ?? 0;
        $patientsWithDebt = $doctorStats['patients_with_debt'] ?? 0;
        $totalDebtAmount = $doctorStats['total_debt'] ?? 0;
        $patientsWithAlerts = 0; // Not tracked per doctor
        $newPatientsThisMonth = $doctorStats['new_this_month'] ?? 0;
        $activeCases = $doctorStats['active_cases'] ?? 0;
    } else {
        // Global stats for admin/staff
        $activeFoldersCount = $patientManager->countActiveFolders();
        $closedFoldersCount = $patientManager->countClosedFolders();
        $patientsWithDebt = $patientManager->countPatientsWithDebt();
        $folderDebtAmount = $patientManager->getTotalDebtAmount();

        // Add X-ray debt to total
        $db = Database::getInstance();
        $xrayDebtSql = "SELECT COALESCE(SUM(final_amount), 0) as total FROM xray_requests WHERE status = 'completed' AND payment_status = 'debt'";
        $xrayDebtResult = $db->fetch($xrayDebtSql);
        $xrayDebt = $xrayDebtResult['total'] ?? 0;
        $totalDebtAmount = $folderDebtAmount + $xrayDebt;

        $patientsWithAlerts = $patientManager->countPatientsWithMedicalAlerts();
        $newPatientsThisMonth = $patientManager->countNewPatientsThisMonth();
        $activeCases = $patientManager->countActiveCases();
    }
}

$paginationContext = $profileDoctorId > 0 ? '&doctor_id=' . $profileDoctorId : '';

if (isset($_GET['deleted'])) {
    echo '<script>window.addEventListener("DOMContentLoaded", function() { showToast("success", "نەخۆشەکە سڕایەوە"); });</script>';
}

// Handle updated success message
if (isset($_GET['updated'])) {
    echo '<script>window.addEventListener("DOMContentLoaded", function() { showToast("success", "زانیاری نەخۆش بە سەرکەوتوویی نوێکرایەوە"); });</script>';
}
?>

<style>
/* Search Bar */
.search-bar-modern {
    background: white;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 16px;
    display: flex;
    gap: 12px;
    align-items: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid var(--border-light);
}
.search-bar-modern i.fa-search {
    color: var(--primary-color);
    font-size: 16px;
}
.search-bar-modern input {
    flex: 1;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    transition: all 0.2s ease;
    position: relative;
}
.search-bar-modern input:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}
.search-bar-modern input.searching {
    background: linear-gradient(to right, white 0%, #f0f7ff 50%, white 100%);
    background-size: 200% 100%;
    animation: searchPulse 1.5s ease-in-out infinite;
}
@keyframes searchPulse {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
/* Patient Cards */
.patient-card {
    background: white;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 10px;
    border: 1px solid #e0e0e0;
    transition: all 0.2s ease;
    display: grid;
    grid-template-columns: 50px 1fr auto;
    gap: 14px;
    align-items: center;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    will-change: transform;
}
.patient-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
    border-color: var(--primary-color);
}
.patient-card.has-alert {
    border-right: 4px solid var(--danger-color);
    background: linear-gradient(to left, transparent, rgba(244, 67, 54, 0.04));
}
.patient-avatar {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 800;
    box-shadow: 0 3px 8px rgba(0, 120, 212, 0.3);
    transition: transform 0.2s ease;
    will-change: transform;
}
.patient-card:hover .patient-avatar {
    transform: scale(1.08);
}
.patient-avatar.female {
    background: linear-gradient(135deg, #e91e63, #c2185b);
    box-shadow: 0 3px 8px rgba(233, 30, 99, 0.3);
}
.patient-info {
    flex: 1;
    min-width: 0;
}
.patient-name {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.3;
}
.patient-name a {
    color: #2c3e50;
    text-decoration: none;
    transition: color 0.2s ease;
}
.patient-name a:hover {
    color: var(--primary-color);
}
.patient-meta {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #546e7a;
}
.patient-meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
}
.patient-meta-item i {
    color: var(--primary-color);
    font-size: 12px;
}
.patient-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
.patient-actions .btn {
    padding: 8px 14px;
    font-size: 13px;
    border-radius: 8px;
    transition: all 0.2s ease;
}
.patient-actions .btn:hover {
    transform: translateY(-1px);
}

/* Stats - Compact */
.stats-grid-patients {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 8px;
    margin-bottom: 16px;
}
.stat-card-patient {
    background: linear-gradient(135deg, white 0%, #f8f9fa 100%);
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #e0e0e0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    transition: all 0.2s ease;
}
.stat-card-patient[data-filter] {
    cursor: pointer;
    user-select: none;
}
.stat-card-patient[data-filter]:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.18);
    border-color: var(--primary-color);
}
.stat-card-patient[data-filter]:active {
    transform: translateY(0);
}
.stat-card-patient.active-filter {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
    background: linear-gradient(135deg, #e3f2fd 0%, #ffffff 100%);
}
.stat-card-patient h4 {
    font-size: 12px;
    color: #546e7a;
    margin: 0 0 6px 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-card-patient h4 i {
    flex-shrink: 0;
}
.stat-card-patient .stat-value {
    font-size: 26px;
    font-weight: 800;
    color: var(--primary-color);
    line-height: 1.2;
}

/* Pagination */
.pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e0e0e0;
}
.pagination-btn {
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    color: #546e7a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s ease;
    cursor: pointer;
    will-change: transform;
}
.pagination-btn:hover:not(.disabled) {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 120, 212, 0.3);
}
.pagination-btn.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
    font-weight: 700;
}
.pagination-btn.disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Empty State */
.empty-state {
    text-align: center;
}

/* Badges */
.badge {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 600;
}
.badge-blue {
    background: #e3f2fd;
    color: #1976d2;
}
.badge-pink {
    background: #fce4ec;
    color: #c2185b;
}

/* Advanced Filters */
#advancedFilters {
    animation: slideDown 0.3s ease;
}
#toggleFilters {
    transition: all 0.3s ease;
}
#toggleFilters.has-active-filters {
    background: linear-gradient(135deg, var(--primary-color), #0063b1) !important;
    color: white !important;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.3);
}
#toggleFilters.has-active-filters:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.4);
}
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.filter-group {
    position: relative;
}
.filter-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #37474f;
}
.filter-label i {
    color: var(--primary-color);
    font-size: 13px;
}
.filter-control {
    width: 100%;
    padding: 9px 12px;
    font-size: 13px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    transition: all 0.2s ease;
    font-family: inherit;
}
.filter-control:hover {
    border-color: #bdbdbd;
}
.filter-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}
.filter-control option {
    padding: 8px;
}
.filter-control.active {
    border-color: var(--primary-color);
    background: linear-gradient(to right, #e3f2fd, white);
    font-weight: 600;
}
.filter-group.has-value .filter-label {
    color: var(--primary-color);
    font-weight: 700;
}
.filter-group.has-value .filter-label i {
    animation: pulse 1.5s infinite;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}
.filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 2px solid #f5f5f5;
}
.filters-title {
    font-size: 14px;
    font-weight: 700;
    color: #263238;
    display: flex;
    align-items: center;
    gap: 8px;
}
.filters-title i {
    color: var(--primary-color);
}
.active-filter-badge {
    background: var(--primary-color);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    min-width: 20px;
    text-align: center;
}
</style>

<?php if ($isDoctorPatientFilter): ?>
<div style="background: linear-gradient(135deg, #f3e5f5, #e1bee7); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 2px 8px rgba(156, 39, 176, 0.15);">
    <div style="display: flex; align-items: center; gap: 8px; color: #6a1b9a; font-weight: 700;">
        <i class="fas fa-user-md"></i>
        <span>Patients of Dr. <?= htmlspecialchars($profileDoctor['full_name']) ?></span>
    </div>
    <a href="<?= APP_URL ?>/doctors/profile.php?id=<?= $profileDoctorId ?>" class="btn btn-secondary" style="padding: 7px 12px; font-size: 12px;">
        <i class="fas fa-id-card"></i> Doctor profile
    </a>
</div>
<?php endif; ?>

<?php if ($isDoctor && !$isAdmin && !$isDoctorPatientFilter): ?>
<!-- Doctor View Mode Toggle -->
<div class="view-mode-toggle" style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; box-shadow: 0 2px 8px rgba(33, 150, 243, 0.15);">
    <div style="display: flex; align-items: center; gap: 8px;">
        <i class="fas fa-user-md" style="color: #1565c0; font-size: 18px;"></i>
        <span style="font-weight: 700; color: #1565c0; font-size: 14px;">بینینی نەخۆشەکان:</span>
    </div>
    <div class="view-mode-buttons" style="display: flex; gap: 8px;">
        <button type="button" class="view-mode-btn <?= $viewMode === 'own' ? 'active' : '' ?>" data-mode="own"
                style="padding: 8px 16px; border-radius: 8px; border: 2px solid <?= $viewMode === 'own' ? '#1565c0' : '#90caf9' ?>; background: <?= $viewMode === 'own' ? '#1565c0' : 'white' ?>; color: <?= $viewMode === 'own' ? 'white' : '#1565c0' ?>; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s;">
            <i class="fas fa-user-check"></i> نەخۆشەکانم (<?= $patientManager->countDoctorPatients($doctorId) ?>)
        </button>
        <button type="button" class="view-mode-btn <?= $viewMode === 'all' ? 'active' : '' ?>" data-mode="all"
                style="padding: 8px 16px; border-radius: 8px; border: 2px solid <?= $viewMode === 'all' ? '#ff9800' : '#ffe0b2' ?>; background: <?= $viewMode === 'all' ? '#ff9800' : 'white' ?>; color: <?= $viewMode === 'all' ? 'white' : '#ff9800' ?>; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s;">
            <i class="fas fa-users"></i> نەخۆشەکانی تر (<?= $patientManager->countAllPatientsRestricted($doctorId) ?>)
        </button>
    </div>
    <?php if ($viewMode === 'all'): ?>
    <div style="background: rgba(255, 152, 0, 0.15); border: 1px solid #ff9800; border-radius: 6px; padding: 6px 12px; display: flex; align-items: center; gap: 6px;">
        <i class="fas fa-lock" style="color: #e65100; font-size: 12px;"></i>
        <span style="font-size: 12px; color: #e65100; font-weight: 600;">بینینی سنوورمەند - تەنها پرۆفایل و ئێکسڕەی دەبینیت</span>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Advanced Search Bar -->
<div class="search-bar-modern">
    <i class="fas fa-search"></i>
    <div style="flex: 1; display: flex; gap: 10px; flex-wrap: wrap;">
        <input type="text" id="searchInput" placeholder="<?= ($isDoctor && !$isAdmin && $viewMode === 'all') ? 'گەڕان لە نەخۆشەکانی تری کلینیک...' : 'گەڕان بە ناو، کۆد، یان ژمارە تەلەفۆن...' ?>"
               value="<?= htmlspecialchars($search) ?>" style="flex: 1; min-width: 200px;">
        <?php if (!($isDoctor && !$isAdmin && $viewMode === 'all')): ?>
        <button type="button" class="btn btn-secondary" id="toggleFilters" style="padding: 10px 18px; font-size: 14px; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-filter"></i> فلتەرە پێشکەوتووەکان
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" id="searchBtn" style="padding: 10px 18px; font-size: 14px; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-search"></i> گەڕان
        </button>
        <button type="button" class="btn btn-secondary" id="clearBtn" style="padding: 10px 18px; font-size: 14px; border-radius: 8px; font-weight: 600; display: none;">
            <i class="fas fa-times"></i> پاککردنەوە
        </button>
    </div>
    <a href="<?= APP_URL ?>/patients/add.php" class="btn btn-primary" style="padding: 10px 20px; font-size: 14px; border-radius: 8px; font-weight: 600; white-space: nowrap;">
        <i class="fas fa-plus-circle"></i>
        نەخۆشی نوێ
    </a>
</div>

<!-- Advanced Filters Panel -->
<div id="advancedFilters" style="display: none; background: linear-gradient(to bottom, #ffffff, #fafafa); border-radius: 12px; padding: 18px 22px; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); border: 1px solid #e0e0e0;">
    <div class="filters-header">
        <div class="filters-title">
            <i class="fas fa-sliders-h"></i>
            فلتەرە پێشکەوتووەکان
            <span class="active-filter-badge" id="activeFilterCount" style="display: none;">0</span>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" id="quickClearFilters" style="padding: 5px 12px; font-size: 12px; display: none;">
            <i class="fas fa-eraser"></i> پاککردنەوە
        </button>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-venus-mars"></i>
                ڕەگەز
            </label>
            <select id="filterGender" class="filter-control">
                <option value="">هەمووی</option>
                <option value="male">نێر</option>
                <option value="female">مێ</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-calendar-day"></i>
                لە بەروار
            </label>
            <input type="date" id="filterDateFrom" class="filter-control">
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-calendar-check"></i>
                بۆ بەروار
            </label>
            <input type="date" id="filterDateTo" class="filter-control">
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-folder-open"></i>
                دۆخی دۆسیە
            </label>
            <select id="filterFolderStatus" class="filter-control">
                <option value="">هەمووی</option>
                <option value="active">کراوە</option>
                <option value="closed">داخراو</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-dollar-sign"></i>
                قەرز
            </label>
            <select id="filterHasDebt" class="filter-control">
                <option value="">هەمووی</option>
                <option value="1">بە قەرز</option>
                <option value="0">بەبێ قەرز</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-exclamation-triangle"></i>
                ئاگاداری پزیشکی
            </label>
            <select id="filterMedicalAlerts" class="filter-control">
                <option value="">هەمووی</option>
                <option value="1">بە ئاگاداری</option>
                <option value="0">بەبێ ئاگاداری</option>
            </select>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid-patients" id="statsContainer" style="<?= $search ? 'display: none;' : '' ?>">
    <div class="stat-card-patient" data-filter="all" title="هەموو نەخۆشەکان">
        <h4><i class="fas fa-users"></i> کۆی نەخۆشەکان</h4>
        <div class="stat-value" id="statTotalPatients"><?= $search ? '0' : number_format($totalPatients) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="new_month" title="نەخۆشی نوێ ئەم مانگە">
        <h4><i class="fas fa-user-plus"></i> نوێ ئەم مانگە</h4>
        <div class="stat-value" id="statNewThisMonth" style="color: #2196f3;"><?= $search ? '0' : number_format($newPatientsThisMonth) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="active_folders" title="نەخۆشانی دۆسیەی کراوە">
        <h4><i class="fas fa-folder-open"></i> دۆسیەی کراوە</h4>
        <div class="stat-value" id="statActiveFolders" style="color: #4caf50;"><?= $search ? '0' : number_format($activeFoldersCount) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="closed_folders" title="نەخۆشانی دۆسیەی داخراو">
        <h4><i class="fas fa-folder"></i> دۆسیەی داخراو</h4>
        <div class="stat-value" id="statClosedFolders" style="color: #9e9e9e;"><?= $search ? '0' : number_format($closedFoldersCount) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="active_cases" title="نەخۆشانی کەیسی کراوە">
        <h4><i class="fas fa-briefcase-medical"></i> کەیسی کراوە</h4>
        <div class="stat-value" id="statActiveCases" style="color: #ff9800;"><?= $search ? '0' : number_format($activeCases) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="has_debt" title="نەخۆشانی بە قەرز">
        <h4><i class="fas fa-hand-holding-usd"></i> نەخۆش بە قەرز</h4>
        <div class="stat-value" id="statPatientsWithDebt" style="color: #e91e63;"><?= $search ? '0' : number_format($patientsWithDebt) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="has_debt" title="نەخۆشانی بە قەرز">
        <h4><i class="fas fa-money-bill-wave"></i> کۆی پارەی قەرز</h4>
        <div class="stat-value" id="statTotalDebt" style="color: #d32f2f;"><?= $search ? '0' : number_format($totalDebtAmount) ?> <small style="font-size: 14px;"></small></div>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" style="display: none; text-align: center; padding: 40px;">
    <i class="fas fa-circle-notch fa-spin" style="font-size: 48px; color: var(--primary-color);"></i>
    <p style="margin-top: 16px; color: var(--text-secondary); font-weight: 600;">چاوەڕوان بە...</p>
</div>

<div class="panel" id="patientsPanel" style="border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
    <div class="panel-body" style="padding: 16px;">
        <!-- Patient Cards -->
        <div id="patientsList">
        <?php if (empty($patients)): ?>
        <div class="empty-state" style="padding: 40px 20px;">
            <i class="fas fa-users empty-state-icon" style="font-size: 64px; color: var(--text-secondary); opacity: 0.3;"></i>
            <p class="empty-state-title" style="font-size: 18px; font-weight: 700; margin: 16px 0 8px;">هیچ نەخۆشێک نەدۆزرایەوە</p>
            <p class="empty-state-text" style="font-size: 14px; color: var(--text-secondary); margin-bottom: 20px;">
                <?= $search ? 'گەڕانەکەت ئەنجامی نەبوو' : 'هێشتا هیچ نەخۆشێک تۆمار نەکراوە' ?>
            </p>
            <a href="<?= APP_URL ?>/patients/add.php" class="btn btn-primary" style="padding: 12px 24px; font-size: 14px; border-radius: 8px; font-weight: 600;">
                <i class="fas fa-plus-circle"></i>
                زیادکردنی نەخۆش
            </a>
        </div>
        <?php else: ?>
            <?php foreach ($patients as $patient): ?>
            <?php
            // Check for medical conditions
            $hasConditions = $patientManager->hasMedicalAlert($patient['id']);
            // Get first letter of name for avatar
            $firstLetter = mb_substr($patient['full_name'], 0, 1, 'UTF-8');
            ?>
            <div class="patient-card <?= $hasConditions ? 'has-alert' : '' ?>">
                <!-- Avatar -->
                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>">
                    <div class="patient-avatar <?= $patient['gender'] === 'female' ? 'female' : '' ?>">
                        <?= htmlspecialchars($firstLetter) ?>
                    </div>
                </a>

                <!-- Patient Info -->
                <div class="patient-info">
                    <div class="patient-name">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>">
                            <?= htmlspecialchars($patient['full_name']) ?>
                        </a>
                        <?php if ($hasConditions): ?>
                        <i class="fas fa-exclamation-triangle" style="color: var(--danger-color); font-size: 13px;" title="ئاگادارکردنەوەی پزیشکی"></i>
                        <?php endif; ?>
                        <span class="badge <?= $patient['gender'] === 'male' ? 'badge-blue' : 'badge-pink' ?>">
                            <?= $patient['gender'] === 'male' ? 'نێر' : 'مێ' ?>
                        </span>
                    </div>
                    <div class="patient-meta">
                        <div class="patient-meta-item">
                            <i class="fas fa-id-card"></i>
                            <span><?= $patient['patient_code'] ?></span>
                        </div>
                        <div class="patient-meta-item">
                            <i class="fas fa-phone"></i>
                            <a href="tel:<?= $patient['phone'] ?>" style="color: inherit;"><?= $patient['phone'] ?></a>
                        </div>
                        <div class="patient-meta-item">
                            <i class="fas fa-folder"></i>
                            <span><?= $patient['folder_count'] ?? 0 ?> دۆسیە</span>
                        </div>
                        <?php if (($patient['total_debt'] ?? 0) > 0): ?>
                        <div class="patient-meta-item" style="color: var(--danger-color); font-weight: 600;">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>قەرز: <?= number_format($patient['total_debt']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="patient-meta-item">
                            <i class="fas fa-calendar"></i>
                            <span><?= date('Y-m-d', strtotime($patient['created_at'])) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="patient-actions">
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>"
                       class="btn btn-primary" title="بینین">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="<?= APP_URL ?>/patients/edit.php?id=<?= $patient['id'] ?>"
                       class="btn btn-secondary" title="دەستکاری">
                        <i class="fas fa-edit"></i>
                    </a>
                    <?php if ($auth->isAdmin()): ?>
                    <button type="button"
                            onclick="confirmDelete('ئایا دڵنیایت لە سڕینەوەی ئەم نەخۆشە؟ زانیاری نەخۆش دەشاردرێتەوە بەڵام مێژووی دارایی و کەیسەکان دەمێننەوە.', () => document.getElementById('deletePatientForm<?= $patient['id'] ?>').submit())"
                            class="btn btn-danger" title="سڕینەوە">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <form id="deletePatientForm<?= $patient['id'] ?>" method="POST" action="<?= APP_URL ?>/patients/index.php" style="display:none;">
                        <input type="hidden" name="action" value="delete_patient">
                        <input type="hidden" name="patient_id" value="<?= $patient['id'] ?>">
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div><!-- End patientsList -->

        <!-- Pagination -->
        <div id="paginationContainer">
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
                <i class="fas fa-angle-double-right"></i>
            </a>
            <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
                <i class="fas fa-angle-right"></i>
            </a>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>
                <i class="fas fa-angle-left"></i>
            </a>
            <a href="?page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>
                <i class="fas fa-angle-double-left"></i>
            </a>
        </div>
        <?php endif; ?>
        </div><!-- End pagination container -->
    </div>
</div>

<script>
// AJAX Patient Search
let currentPage = <?= $page ?>;
let searchTimeout = null;
let searchCache = new Map(); // Result cache for performance
const CACHE_DURATION = 60000; // Cache results for 60 seconds
let currentViewMode = '<?= $viewMode ?>'; // Doctor view mode
const profileDoctorId = <?= $profileDoctorId ?>;
const isDoctor = <?= ($isDoctor && !$isAdmin) ? 'true' : 'false' ?>;

// View mode toggle for doctors
<?php if ($isDoctor && !$isAdmin && !$isDoctorPatientFilter): ?>
document.querySelectorAll('.view-mode-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const mode = this.getAttribute('data-mode');
        if (mode !== currentViewMode) {
            currentViewMode = mode;
            // Clear cache when switching modes
            searchCache.clear();
            // Reload page with new mode
            window.location.href = '<?= APP_URL ?>/patients/index.php?view_mode=' + mode;
        }
    });
});
<?php endif; ?>

// Toggle filters panel
const toggleFiltersBtn = document.getElementById('toggleFilters');
if (toggleFiltersBtn) {
    toggleFiltersBtn.addEventListener('click', function() {
        const filtersPanel = document.getElementById('advancedFilters');
        const isVisible = filtersPanel.style.display !== 'none';
        filtersPanel.style.display = isVisible ? 'none' : 'block';

        // Update button text based on active filters
        updateToggleButtonText();
    });
}

// Update toggle button text with filter count
function updateToggleButtonText() {
    const toggleBtn = document.getElementById('toggleFilters');
    const filtersPanel = document.getElementById('advancedFilters');
    const isVisible = filtersPanel.style.display !== 'none';
    const badge = document.getElementById('activeFilterCount');
    const count = badge.textContent;

    if (isVisible) {
        toggleBtn.innerHTML = '<i class="fas fa-times"></i> شاردنەوەی فلتەر';
    } else {
        if (count && count > 0) {
            toggleBtn.innerHTML = `<i class="fas fa-filter"></i> فلتەرەکان (${count})`;
            toggleBtn.classList.add('has-active-filters');
        } else {
            toggleBtn.innerHTML = '<i class="fas fa-filter"></i> فلتەرە پێشکەوتووەکان';
            toggleBtn.classList.remove('has-active-filters');
        }
    }
}

// Update active filter count and visual feedback
function updateActiveFilterCount() {
    const searchValue = document.getElementById('searchInput').value.trim();

    // Only count advanced filters (not search box) for button styling
    const advancedFilters = {
        'filterGender': document.getElementById('filterGender').value,
        'filterDateFrom': document.getElementById('filterDateFrom').value,
        'filterDateTo': document.getElementById('filterDateTo').value,
        'filterFolderStatus': document.getElementById('filterFolderStatus').value,
        'filterHasDebt': document.getElementById('filterHasDebt').value,
        'filterMedicalAlerts': document.getElementById('filterMedicalAlerts').value
    };

    let count = 0;

    // Update visual feedback for advanced filters only
    Object.keys(advancedFilters).forEach(key => {
        const element = document.getElementById(key);
        const filterGroup = element.closest('.filter-group');

        if (advancedFilters[key]) {
            count++;
            element.classList.add('active');
            if (filterGroup) filterGroup.classList.add('has-value');
        } else {
            element.classList.remove('active');
            if (filterGroup) filterGroup.classList.remove('has-value');
        }
    });

    const badge = document.getElementById('activeFilterCount');
    const quickClear = document.getElementById('quickClearFilters');

    if (count > 0) {
        badge.textContent = count;
        badge.style.display = 'inline-block';
        quickClear.style.display = 'inline-flex';
    } else {
        badge.style.display = 'none';
        quickClear.style.display = 'none';
    }

    // Update toggle button text
    updateToggleButtonText();

    // Return true if either search or filters are active (for clear button)
    return (searchValue.length > 0 || count > 0);
}

// Generate cache key from search parameters
function getCacheKey(page, searchInput, filters) {
    return `${currentViewMode}|${page}|${searchInput}|${filters.gender}|${filters.dateFrom}|${filters.dateTo}|${filters.folderStatus}|${filters.hasDebt}|${filters.medicalAlerts}`;
}

// Clean expired cache entries
function cleanExpiredCache() {
    const now = Date.now();
    for (let [key, value] of searchCache.entries()) {
        if (now - value.timestamp > CACHE_DURATION) {
            searchCache.delete(key);
        }
    }
}

// Perform search
function performSearch(page = 1) {
    const searchInputEl = document.getElementById('searchInput');
    const searchInput = searchInputEl.value.trim();
    const filterGender = document.getElementById('filterGender').value;
    const filterDateFrom = document.getElementById('filterDateFrom').value;
    const filterDateTo = document.getElementById('filterDateTo').value;
    const filterFolderStatus = document.getElementById('filterFolderStatus').value;
    const filterHasDebt = document.getElementById('filterHasDebt').value;
    const filterMedicalAlerts = document.getElementById('filterMedicalAlerts').value;

    // Update active filter count and show/hide clear buttons
    const hasFilters = updateActiveFilterCount();
    document.getElementById('clearBtn').style.display = hasFilters ? 'inline-flex' : 'none';

    // Create cache key
    const filters = {
        gender: filterGender,
        dateFrom: filterDateFrom,
        dateTo: filterDateTo,
        folderStatus: filterFolderStatus,
        hasDebt: filterHasDebt,
        medicalAlerts: filterMedicalAlerts
    };
    const cacheKey = getCacheKey(page, searchInput, filters);

    // Check cache first for instant results
    cleanExpiredCache();
    if (searchCache.has(cacheKey)) {
        const cachedData = searchCache.get(cacheKey);
        // Use cached data immediately for instant display
        updateUIWithResults(cachedData.data, hasFilters);
        return;
    }

    // Add searching visual feedback
    searchInputEl.classList.add('searching');

    // Show loading indicator with optimized transition
    document.getElementById('loadingIndicator').style.display = 'block';
    document.getElementById('patientsPanel').style.opacity = '0.6';
    document.getElementById('patientsPanel').style.transform = 'scale(0.98)';
    document.getElementById('patientsPanel').style.transition = 'all 0.15s ease';

    // Prepare form data
    const formData = new FormData();
    formData.append('page', page);
    formData.append('search', searchInput);
    formData.append('view_mode', currentViewMode); // Include doctor view mode
    if (profileDoctorId > 0) formData.append('doctor_id', profileDoctorId);
    if (filterGender) formData.append('gender', filterGender);
    if (filterDateFrom) formData.append('date_from', filterDateFrom);
    if (filterDateTo) formData.append('date_to', filterDateTo);
    if (filterFolderStatus) formData.append('folder_status', filterFolderStatus);
    if (filterHasDebt) formData.append('has_debt', filterHasDebt);
    if (filterMedicalAlerts) formData.append('has_medical_alerts', filterMedicalAlerts);

    // Perform AJAX request
    fetch('<?= APP_URL ?>/patients/ajax_search.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Cache the results
        searchCache.set(cacheKey, {
            data: data,
            timestamp: Date.now()
        });

        // Update UI with results
        updateUIWithResults(data, hasFilters);

        // Remove searching visual feedback
        searchInputEl.classList.remove('searching');
    })
    .catch(error => {
        console.error('AJAX error:', error);
        showToast('error', 'هەڵەیەک ڕوویدا لە پەیوەندی');
        searchInputEl.classList.remove('searching');
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('patientsPanel').style.opacity = '1';
        document.getElementById('patientsPanel').style.transform = 'scale(1)';
    });
}

// Update UI with search results (extracted for cache reuse)
function updateUIWithResults(data, hasFilters) {
    if (data.success) {
        // Update patients list
        document.getElementById('patientsList').innerHTML = data.html;

        // Update pagination
        document.getElementById('paginationContainer').innerHTML = data.pagination;

        // Update statistics cards
        if (data.statistics) {
            document.getElementById('statTotalPatients').textContent = data.statistics.totalPatients.toLocaleString();
            document.getElementById('statNewThisMonth').textContent = data.statistics.newThisMonth.toLocaleString();
            document.getElementById('statActiveFolders').textContent = data.statistics.activeFolders.toLocaleString();
            document.getElementById('statClosedFolders').textContent = data.statistics.closedFolders.toLocaleString();
            document.getElementById('statActiveCases').textContent = data.statistics.activeCases.toLocaleString();
            document.getElementById('statPatientsWithDebt').textContent = data.statistics.patientsWithDebt.toLocaleString();
            document.getElementById('statTotalDebt').innerHTML = data.statistics.totalDebt.toLocaleString() + ' <small style="font-size: 14px;"></small>';

            // Show stats container when filters are active
            document.getElementById('statsContainer').style.display = hasFilters ? 'grid' : 'none';
        }

        // Attach pagination click handlers
        attachPaginationHandlers();

        currentPage = data.page;

        // Hide loading indicator with smooth transition
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('patientsPanel').style.opacity = '1';
        document.getElementById('patientsPanel').style.transform = 'scale(1)';

        // Scroll to top of results
        document.getElementById('patientsPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        console.error('Search error:', data.error);
        showToast('error', 'هەڵەیەک ڕوویدا لە گەڕان');
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('patientsPanel').style.opacity = '1';
        document.getElementById('patientsPanel').style.transform = 'scale(1)';
    }
}

// Attach pagination handlers
function attachPaginationHandlers() {
    document.querySelectorAll('.pagination-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!this.classList.contains('disabled') && !this.classList.contains('active')) {
                const page = parseInt(this.getAttribute('data-page'));
                performSearch(page);
            }
        });
    });
}

// Search button click
document.getElementById('searchBtn').addEventListener('click', function() {
    performSearch(1);
});

// Search on Enter key
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        performSearch(1);
    }
});

// Real-time search (debounced) - optimized to 250ms for instant feel
document.getElementById('searchInput').addEventListener('input', function() {
    updateActiveFilterCount();
    clearTimeout(searchTimeout);

    const query = this.value.trim();

    // Instant clear when empty
    if (query.length === 0) {
        performSearch(1);
        return;
    }

    // Debounce for better performance (250ms feels instant)
    searchTimeout = setTimeout(() => {
        if (query.length >= 2) {
            performSearch(1);
        }
    }, 250);
});

// Filter change triggers search and updates count
['filterGender', 'filterDateFrom', 'filterDateTo', 'filterFolderStatus', 'filterHasDebt', 'filterMedicalAlerts'].forEach(id => {
    document.getElementById(id).addEventListener('change', function() {
        updateActiveFilterCount();
        performSearch(1);
    });
});

// Clear all filters function
function clearAllFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('filterGender').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterFolderStatus').value = '';
    document.getElementById('filterHasDebt').value = '';
    document.getElementById('filterMedicalAlerts').value = '';
    updateActiveFilterCount();
    // Clear cache when filters are cleared
    searchCache.clear();
    // Hide statistics container when clearing filters
    document.getElementById('statsContainer').style.display = 'none';
    performSearch(1);
}

// Clear all filters (main button)
document.getElementById('clearBtn').addEventListener('click', function() {
    clearAllFilters();
});

// Clear all filters (quick button in filters panel)
document.getElementById('quickClearFilters').addEventListener('click', function() {
    clearAllFilters();
});

// Stat card click → apply matching filter
function applyStatCardFilter(filter, cardEl) {
    // Reset all filter inputs
    document.getElementById('searchInput').value = '';
    document.getElementById('filterGender').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterFolderStatus').value = '';
    document.getElementById('filterHasDebt').value = '';
    document.getElementById('filterMedicalAlerts').value = '';

    let openPanel = false;
    switch (filter) {
        case 'all':
            // no filters
            break;
        case 'new_month': {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const firstDay = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`;
            const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
            document.getElementById('filterDateFrom').value = firstDay;
            document.getElementById('filterDateTo').value = today;
            openPanel = true;
            break;
        }
        case 'active_folders':
        case 'active_cases':
            document.getElementById('filterFolderStatus').value = 'active';
            openPanel = true;
            break;
        case 'closed_folders':
            document.getElementById('filterFolderStatus').value = 'closed';
            openPanel = true;
            break;
        case 'has_debt':
            document.getElementById('filterHasDebt').value = '1';
            openPanel = true;
            break;
    }

    // Visual highlight on selected card
    document.querySelectorAll('.stat-card-patient.active-filter')
        .forEach(c => c.classList.remove('active-filter'));
    if (cardEl && filter !== 'all') cardEl.classList.add('active-filter');

    // Open filter panel so user can see the applied filter
    const panel = document.getElementById('advancedFilters');
    if (panel && openPanel) panel.style.display = 'block';
    if (panel && filter === 'all') panel.style.display = 'none';

    searchCache.clear();
    performSearch(1);
}

document.querySelectorAll('.stat-card-patient[data-filter]').forEach(card => {
    card.addEventListener('click', function() {
        applyStatCardFilter(this.dataset.filter, this);
    });
});

// Initial pagination handlers and filter count
attachPaginationHandlers();
updateActiveFilterCount();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
