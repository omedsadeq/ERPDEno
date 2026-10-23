<?php
/**
 * Folders Management Page
 * بەڕێوەبردنی دۆسیەکان
 */

$pageTitle = 'دۆسیەکان';
$currentPage = 'folders';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Folder.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();

// Filters
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$doctorFilter = $_GET['doctor'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

// Build query
$sql = "SELECT pf.*,
        p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
        u.full_name as doctor_name,
        (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count,
        (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id AND c.status = 'open') as open_cases
        FROM patient_folders pf
        LEFT JOIN patients p ON pf.patient_id = p.id
        LEFT JOIN users u ON pf.doctor_id = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM patient_folders pf
             LEFT JOIN patients p ON pf.patient_id = p.id
             WHERE 1=1";

$params = [];

// Search filter
if ($search) {
    $sql .= " AND (p.full_name LIKE :search OR p.patient_code LIKE :search2 OR pf.folder_name LIKE :search3)";
    $countSql .= " AND (p.full_name LIKE :search OR p.patient_code LIKE :search2 OR pf.folder_name LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}

// Status filter
if ($statusFilter) {
    $sql .= " AND pf.status = :status";
    $countSql .= " AND pf.status = :status";
    $params['status'] = $statusFilter;
}

// Doctor filter (admin only)
if ($isAdmin && $doctorFilter) {
    $sql .= " AND pf.doctor_id = :doctor_id";
    $countSql .= " AND pf.doctor_id = :doctor_id";
    $params['doctor_id'] = $doctorFilter;
}

// Non-admin sees only their own folders
if (!$isAdmin) {
    $sql .= " AND pf.doctor_id = :current_doctor";
    $countSql .= " AND pf.doctor_id = :current_doctor";
    $params['current_doctor'] = $currentUser['id'];
}

// Type filter
if ($typeFilter) {
    $sql .= " AND pf.folder_type = :folder_type";
    $countSql .= " AND pf.folder_type = :folder_type";
    $params['folder_type'] = $typeFilter;
}

// Date filter
if ($dateFrom) {
    $sql .= " AND DATE(pf.created_at) >= :date_from";
    $countSql .= " AND DATE(pf.created_at) >= :date_from";
    $params['date_from'] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(pf.created_at) <= :date_to";
    $countSql .= " AND DATE(pf.created_at) <= :date_to";
    $params['date_to'] = $dateTo;
}

// Get total count
$totalResult = $db->fetch($countSql, $params);
$totalFolders = $totalResult['total'];
$totalPages = ceil($totalFolders / $perPage);

// Add pagination
$offset = ($page - 1) * $perPage;
$sql .= " ORDER BY pf.updated_at DESC LIMIT {$perPage} OFFSET {$offset}";

$folders = $db->fetchAll($sql, $params);

// Get doctors for filter (admin only)
$doctors = $isAdmin ? $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name") : [];

// Statistics
$statsParams = [];
$statsWhere = " WHERE 1=1";

if (!$isAdmin) {
    $statsWhere .= " AND pf.doctor_id = :current_doctor";
    $statsParams['current_doctor'] = $currentUser['id'];
}

// Total folders
$totalAllFolders = $db->fetch("SELECT COUNT(*) as count FROM patient_folders pf {$statsWhere}", $statsParams)['count'];

// Active folders
$activeFolders = $db->fetch("SELECT COUNT(*) as count FROM patient_folders pf {$statsWhere} AND pf.status = 'active'", $statsParams)['count'];

// Archived folders
$archivedFolders = $db->fetch("SELECT COUNT(*) as count FROM patient_folders pf {$statsWhere} AND pf.status = 'archived'", $statsParams)['count'];

// Total amounts
$financialStats = $db->fetch("SELECT
    COALESCE(SUM(pf.total_amount), 0) as total_amount,
    COALESCE(SUM(pf.paid_amount), 0) as paid_amount,
    COALESCE(SUM(pf.discount_amount), 0) as discount_amount
    FROM patient_folders pf {$statsWhere}", $statsParams);

$totalAmount = $financialStats['total_amount'];
$paidAmount = $financialStats['paid_amount'];
$discountAmount = $financialStats['discount_amount'];
$remainingAmount = $totalAmount - $paidAmount - $discountAmount;

// Folder types for filter
$folderTypes = [
    'general' => 'گشتی',
    'cosmetic' => 'جوانکاری',
    'orthodontic' => 'ڕاستکردنەوە',
    'surgery' => 'نەشتەرگەری',
    'other' => 'هی تر'
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.report-container {
    max-width: 1400px;
    margin: 0 auto;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.report-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.report-title h2 {
    margin: 0;
    font-size: 20px;
    color: #1a1a1a;
}

.report-title .icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #0078d4, #0063b1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.3);
}

.report-actions {
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
    transform: translateY(-1px);
}

.btn-pdf {
    background: #dc3545;
    color: white;
}

.btn-pdf:hover {
    background: #c82333;
    transform: translateY(-1px);
}

.btn-excel {
    background: #28a745;
    color: white;
}

.btn-excel:hover {
    background: #218838;
    transform: translateY(-1px);
}

/* Filters */
.filters-card {
    background: white;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
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
    gap: 5px;
}

.filter-group label {
    font-size: 11px;
    color: #666;
    font-weight: 600;
}

.filter-group input,
.filter-group select {
    padding: 9px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    min-width: 150px;
    transition: all 0.2s;
}

.filter-group input:focus,
.filter-group select:focus {
    border-color: #0078d4;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
}

.filter-group input[type="text"] {
    min-width: 200px;
}

.btn-filter {
    padding: 9px 18px;
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-filter:hover {
    background: linear-gradient(135deg, #0063b1, #005a9e);
    transform: translateY(-1px);
}

.btn-reset {
    padding: 9px 18px;
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-reset:hover {
    background: #eeeeee;
    border-color: #bdbdbd;
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
    transition: all 0.2s;
    border-right: 3px solid transparent;
}

.stat-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-card.total { border-right-color: #0078d4; }
.stat-card.active { border-right-color: #4caf50; }
.stat-card.archived { border-right-color: #9e9e9e; }
.stat-card.amount { border-right-color: #ff9800; }
.stat-card.paid { border-right-color: #4caf50; }
.stat-card.remaining { border-right-color: #f44336; }

.stat-value {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
}

.stat-label {
    font-size: 11px;
    color: #888;
}

/* Table */
.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    overflow: hidden;
}

.table-header {
    padding: 16px 18px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #fafafa, #f5f5f5);
}

.table-header h3 {
    margin: 0;
    font-size: 15px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.table-count {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    padding: 5px 12px;
    border-radius: 14px;
    font-size: 12px;
    font-weight: 600;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.report-table th {
    background: #f8f9fa;
    padding: 13px 14px;
    text-align: right;
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #e0e0e0;
    white-space: nowrap;
}

.report-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.report-table tr:hover {
    background: #f9f9f9;
}

.patient-info {
    display: flex;
    flex-direction: column;
}

.patient-name {
    font-weight: 600;
    color: #1a1a1a;
}

.patient-code {
    font-size: 11px;
    color: #888;
}

.folder-link {
    color: #0078d4;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.folder-link:hover {
    color: #005a9e;
    text-decoration: underline;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 600;
}

.status-active {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    color: #2e7d32;
}

.status-archived {
    background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
    color: #616161;
}

.type-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #e3f2fd;
    color: #1565c0;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.amount-cell {
    font-weight: 600;
}

.amount-total { color: #1a1a1a; }
.amount-paid { color: #4caf50; }
.amount-remaining { color: #f44336; }

.case-count {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: #fff3e0;
    color: #e65100;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.actions-cell {
    display: flex;
    gap: 6px;
}

.btn-icon {
    width: 32px;
    height: 32px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-view {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
}

.btn-view:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.3);
}

.btn-patient {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
}

.btn-patient:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
}

.no-data {
    text-align: center;
    padding: 50px;
    color: #888;
}

.no-data i {
    font-size: 50px;
    margin-bottom: 15px;
    display: block;
    color: #ddd;
}

/* Pagination */
.pagination-wrapper {
    padding: 16px 18px;
    border-top: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fafafa;
}

.pagination-info {
    font-size: 13px;
    color: #666;
}

.pagination {
    display: flex;
    gap: 6px;
}

.pagination a,
.pagination span {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 13px;
    text-decoration: none;
    transition: all 0.2s;
}

.pagination a {
    background: white;
    color: #0078d4;
    border: 1.5px solid #e0e0e0;
}

.pagination a:hover {
    background: #0078d4;
    color: white;
    border-color: #0078d4;
}

.pagination .active {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    border: none;
}

.pagination .disabled {
    background: #f5f5f5;
    color: #bdbdbd;
    border: 1.5px solid #e0e0e0;
    cursor: not-allowed;
}

/* Print Styles */
@media print {
    .no-print {
        display: none !important;
    }

    .report-container {
        max-width: 100%;
    }

    .filters-card,
    .report-actions,
    .pagination-wrapper,
    .actions-cell,
    .nav-pane,
    .header {
        display: none !important;
    }

    .table-card {
        box-shadow: none;
        border: 1px solid #ddd;
    }

    .report-table th {
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact;
    }

    body {
        padding: 0;
        margin: 0;
    }

    .main-content {
        padding: 10px !important;
        margin: 0 !important;
    }

    .stats-row {
        display: none !important;
    }
}

/* Responsive */
@media (max-width: 1024px) {
    .report-table {
        font-size: 12px;
    }

    .report-table th,
    .report-table td {
        padding: 10px 8px;
    }
}

@media (max-width: 768px) {
    .report-header {
        flex-direction: column;
        align-items: stretch;
    }

    .filters-row {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }

    .filter-group input,
    .filter-group select {
        width: 100%;
    }

    .stats-row {
        grid-template-columns: repeat(2, 1fr);
    }

    .table-card {
        overflow-x: auto;
    }

    .report-table {
        min-width: 900px;
    }

    .pagination-wrapper {
        flex-direction: column;
        gap: 12px;
    }
}
</style>

<div class="report-container">
    <!-- Header -->
    <div class="report-header no-print">
        <div class="report-title">
            <div class="icon"><i class="fas fa-folder-open"></i></div>
            <div>
                <h2>بەڕێوەبردنی دۆسیەکان</h2>
                <small style="color: #888;">کۆی دۆسیەکان: <?= number_format($totalAllFolders) ?></small>
            </div>
        </div>
        <div class="report-actions">
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="fas fa-print"></i> چاپکردن
            </button>
            <button onclick="exportPDF()" class="btn-action btn-pdf">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <button onclick="exportExcel()" class="btn-action btn-excel">
                <i class="fas fa-file-excel"></i> Excel
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card no-print">
        <form method="GET" action="">
            <div class="filters-row">
                <div class="filter-group">
                    <label>گەڕان</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ناوی نەخۆش، کۆد، یان ناوی دۆسیە...">
                </div>
                <div class="filter-group">
                    <label>دۆخ</label>
                    <select name="status">
                        <option value="">هەموو</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>کراوە</option>
                        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>داخراو</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>جۆر</label>
                    <select name="type">
                        <option value="">هەموو</option>
                        <?php foreach ($folderTypes as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isAdmin && !empty($doctors)): ?>
                <div class="filter-group">
                    <label>دکتۆر</label>
                    <select name="doctor">
                        <option value="">هەموو</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $doctorFilter == $d['id'] ? 'selected' : '' ?>>د. <?= htmlspecialchars($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group">
                    <label>لە بەروار</label>
                    <input type="date" name="from" value="<?= $dateFrom ?>">
                </div>
                <div class="filter-group">
                    <label>بۆ بەروار</label>
                    <input type="date" name="to" value="<?= $dateTo ?>">
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> گەڕان
                </button>
                <a href="<?= APP_URL ?>/folders/index.php" class="btn-reset">
                    <i class="fas fa-redo"></i> ڕیسێت
                </a>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <div class="stats-row no-print">
        <div class="stat-card total">
            <div class="stat-value"><?= number_format($totalAllFolders) ?></div>
            <div class="stat-label">کۆی دۆسیەکان</div>
        </div>
        <div class="stat-card active">
            <div class="stat-value"><?= number_format($activeFolders) ?></div>
            <div class="stat-label">دۆسیەی کراوە</div>
        </div>
        <div class="stat-card archived">
            <div class="stat-value"><?= number_format($archivedFolders) ?></div>
            <div class="stat-label">دۆسیەی داخراو</div>
        </div>
        <div class="stat-card amount">
            <div class="stat-value"><?= number_format($totalAmount) ?></div>
            <div class="stat-label">کۆی گشتی</div>
        </div>
        <div class="stat-card paid">
            <div class="stat-value"><?= number_format($paidAmount) ?></div>
            <div class="stat-label">پارەی دراو</div>
        </div>
        <div class="stat-card remaining">
            <div class="stat-value"><?= number_format($remainingAmount) ?></div>
            <div class="stat-label">ماوە</div>
        </div>
    </div>

    <!-- Table -->
    <div class="table-card" id="reportTable">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> لیستی دۆسیەکان</h3>
            <span class="table-count"><?= number_format($totalFolders) ?> دۆسیە</span>
        </div>

        <?php if (empty($folders)): ?>
        <div class="no-data">
            <i class="fas fa-folder-open"></i>
            <p>هیچ دۆسیەیەک نەدۆزرایەوە</p>
            <small>تکایە فلتەرەکان بگۆڕە یان گەڕانێکی تر بکە</small>
        </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نەخۆش</th>
                        <th>ناوی دۆسیە</th>
                        <th>دکتۆر</th>
                        <th>جۆر</th>
                        <th>دۆخ</th>
                        <th>کەیسەکان</th>
                        <th>کۆی گشتی</th>
                        <th>دراو</th>
                        <th>ماوە</th>
                        <th>بەروار</th>
                        <th class="no-print">کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($folders as $index => $f): ?>
                    <?php $remaining = $f['total_amount'] - $f['paid_amount'] - $f['discount_amount']; ?>
                    <tr>
                        <td><?= ($page - 1) * $perPage + $index + 1 ?></td>
                        <td>
                            <div class="patient-info">
                                <span class="patient-name"><?= htmlspecialchars($f['patient_name'] ?? 'نادیار') ?></span>
                                <span class="patient-code"><?= htmlspecialchars($f['patient_code'] ?? '') ?></span>
                            </div>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/folders/view.php?id=<?= $f['id'] ?>" class="folder-link">
                                <i class="fas fa-folder"></i> <?= htmlspecialchars($f['folder_name']) ?>
                            </a>
                        </td>
                        <td>د. <?= htmlspecialchars($f['doctor_name'] ?? 'نادیار') ?></td>
                        <td>
                            <span class="type-badge"><?= $folderTypes[$f['folder_type']] ?? $f['folder_type'] ?></span>
                        </td>
                        <td>
                            <span class="status-badge <?= $f['status'] === 'active' ? 'status-active' : 'status-archived' ?>">
                                <i class="fas <?= $f['status'] === 'active' ? 'fa-folder-open' : 'fa-archive' ?>"></i>
                                <?= $f['status'] === 'active' ? 'کراوە' : 'داخراو' ?>
                            </span>
                        </td>
                        <td>
                            <span class="case-count">
                                <i class="fas fa-briefcase-medical"></i>
                                <?= $f['case_count'] ?>
                                <?php if ($f['open_cases'] > 0): ?>
                                <small>(<?= $f['open_cases'] ?> کراوە)</small>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="amount-cell amount-total"><?= number_format($f['total_amount']) ?></td>
                        <td class="amount-cell amount-paid"><?= number_format($f['paid_amount']) ?></td>
                        <td class="amount-cell <?= $remaining > 0 ? 'amount-remaining' : 'amount-paid' ?>">
                            <?= number_format($remaining) ?>
                        </td>
                        <td><?= date('Y/m/d', strtotime($f['created_at'])) ?></td>
                        <td class="no-print">
                            <div class="actions-cell">
                                <a href="<?= APP_URL ?>/folders/view.php?id=<?= $f['id'] ?>" class="btn-icon btn-view" title="بینینی دۆسیە">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $f['patient_id'] ?>" class="btn-icon btn-patient" title="بینینی نەخۆش">
                                    <i class="fas fa-user"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper no-print">
            <div class="pagination-info">
                نیشاندانی <?= ($page - 1) * $perPage + 1 ?> - <?= min($page * $perPage, $totalFolders) ?> لە <?= number_format($totalFolders) ?>
            </div>
            <div class="pagination">
                <?php
                $queryParams = $_GET;
                unset($queryParams['page']);
                $queryString = http_build_query($queryParams);
                $queryString = $queryString ? '&' . $queryString : '';
                ?>

                <?php if ($page > 1): ?>
                <a href="?page=1<?= $queryString ?>"><i class="fas fa-angle-double-right"></i></a>
                <a href="?page=<?= $page - 1 ?><?= $queryString ?>"><i class="fas fa-angle-right"></i></a>
                <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                <span class="disabled"><i class="fas fa-angle-right"></i></span>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);

                for ($i = $start; $i <= $end; $i++):
                ?>
                <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
                <?php else: ?>
                <a href="?page=<?= $i ?><?= $queryString ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $queryString ?>"><i class="fas fa-angle-left"></i></a>
                <a href="?page=<?= $totalPages ?><?= $queryString ?>"><i class="fas fa-angle-double-left"></i></a>
                <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-left"></i></span>
                <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/html2pdf/html2pdf.bundle.min.js"></script>
<script>
function exportPDF() {
    const element = document.getElementById('reportTable');
    const opt = {
        margin: 10,
        filename: 'folders-report-<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    // Add header for PDF
    const header = document.createElement('div');
    header.innerHTML = `
        <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0078d4;">
            <h2 style="margin: 0; color: #0078d4;">ڕاپۆرتی دۆسیەکان</h2>
            <p style="margin: 5px 0 0; color: #666; font-size: 12px;">کۆی گشتی: <?= number_format($totalFolders) ?> دۆسیە - بەرواری ڕاپۆرت: <?= date('Y/m/d') ?></p>
        </div>
    `;

    const clone = element.cloneNode(true);
    clone.insertBefore(header, clone.firstChild);

    // Remove action buttons and pagination from PDF
    clone.querySelectorAll('.no-print').forEach(el => el.remove());
    clone.querySelector('.table-header').style.display = 'none';

    html2pdf().set(opt).from(clone).save();
}

function exportExcel() {
    // Create CSV content
    let csv = '\ufeff'; // BOM for UTF-8
    csv += '#,نەخۆش,کۆدی نەخۆش,ناوی دۆسیە,دکتۆر,جۆر,دۆخ,کەیس,کۆی گشتی,دراو,ماوە,بەروار\n';

    const rows = document.querySelectorAll('.report-table tbody tr');
    rows.forEach((row, index) => {
        const cells = row.querySelectorAll('td');
        const patientName = cells[1].querySelector('.patient-name')?.textContent.trim() || '';
        const patientCode = cells[1].querySelector('.patient-code')?.textContent.trim() || '';
        const folderName = cells[2].textContent.trim().replace(/[\n\r]+/g, ' ');
        const doctor = cells[3].textContent.trim();
        const type = cells[4].textContent.trim();
        const status = cells[5].textContent.trim().replace(/[\n\r]+/g, ' ');
        const cases = cells[6].textContent.trim().replace(/[\n\r]+/g, ' ');
        const total = cells[7].textContent.trim();
        const paid = cells[8].textContent.trim();
        const remaining = cells[9].textContent.trim();
        const date = cells[10].textContent.trim();

        csv += `${index + 1},"${patientName}","${patientCode}","${folderName}","${doctor}","${type}","${status}","${cases}","${total}","${paid}","${remaining}","${date}"\n`;
    });

    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'folders-report-<?= date('Y-m-d') ?>.csv';
    link.click();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
