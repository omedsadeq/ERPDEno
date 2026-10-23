<?php
/**
 * Cases List Page - Enhanced with Advanced Filtering & Printing
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'کەیسەکان';
$breadcrumb = [['title' => 'کەیسەکان']];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Installment.php';

$caseManager = new CaseManager();
$installmentManager = new Installment();
$isAdmin = $auth->isAdmin();
$doctorId = $isAdmin ? null : $currentUser['id'];

// Filters
$page = max(1, intval($_GET['page'] ?? 1));
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$filterDoctorId = $isAdmin ? ($_GET['doctor_id'] ?? '') : $doctorId;
$paymentStatus = $_GET['payment_status'] ?? '';

// Get cases
$filters = [
    'status' => $status,
    'search' => $search,
    'doctor_id' => $filterDoctorId ?: $doctorId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];
$cases = $caseManager->getAll($page, ITEMS_PER_PAGE, $filters);
$totalCases = $caseManager->count($filters);
$totalPages = ceil($totalCases / ITEMS_PER_PAGE);

// Get totals for all cases (not just current page)
$totals = $caseManager->getTotals($filters);
$totalAmount = $totals['total_amount'];
$totalPaid = $totals['total_paid'];
$totalDiscount = $totals['total_discount'];
$totalRemaining = $totals['total_remaining'];

// Get installment statistics
$installmentStats = $installmentManager->getStatistics();

// Count by status
$openCount = $caseManager->count(['status' => 'open', 'doctor_id' => $doctorId]);
$pendingCount = $caseManager->count(['status' => 'pending', 'doctor_id' => $doctorId]);
$closedCount = $caseManager->count(['status' => 'closed', 'doctor_id' => $doctorId]);

// Get doctors list for filter (admin only)
$doctors = [];
if ($isAdmin) {
    $sql = "SELECT id, full_name FROM users WHERE role = 'doctor' ORDER BY full_name";
    $doctors = $db->fetchAll($sql);
}
?>

<style>
/* Modern Cases List Styles */
.cases-filter-bar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 20px;
    background: var(--fill-subtle);
    color: var(--text-primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    border: 1px solid transparent;
    transition: all 0.15s ease;
    cursor: pointer;
}

.filter-chip:hover {
    background: var(--fill-secondary);
    border-color: var(--stroke-subtle);
}

.filter-chip.active {
    background: var(--win-accent);
    color: white;
}

.filter-chip .chip-count {
    background: rgba(255,255,255,0.2);
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
}

.filter-chip:not(.active) .chip-count {
    background: var(--fill-secondary);
}

/* Advanced Filter Toolbar */
.filter-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px;
    background: linear-gradient(to bottom, #fafbfc, #f5f6f8);
    border-radius: 8px;
    border: 1px solid var(--stroke-subtle);
    margin-bottom: 16px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 1px solid var(--stroke-subtle);
    border-radius: 6px;
    font-size: 13px;
    min-width: 140px;
    background: white;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--win-accent);
    box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.1);
}

/* Quick Date Filters */
.quick-date-filters {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.quick-date-btn {
    padding: 6px 12px;
    border: 1px solid var(--stroke-subtle);
    border-radius: 16px;
    background: white;
    color: var(--text-primary);
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.quick-date-btn:hover {
    background: var(--fill-secondary);
    border-color: var(--win-accent);
}

.quick-date-btn.active {
    background: var(--win-accent);
    color: white;
    border-color: var(--win-accent);
}

/* Active Filters Display */
.active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 8px 0;
}

.active-filter-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: #e3f2fd;
    color: #1565c0;
    border-radius: 12px;
    font-size: 12px;
}

.active-filter-tag .remove-filter {
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.15s;
}

.active-filter-tag .remove-filter:hover {
    opacity: 1;
}

/* Print & Export Section */
.print-export-section {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-right: auto;
}

.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid var(--stroke-subtle);
    background: white;
    color: var(--text-primary);
    transition: all 0.15s ease;
}

.btn-export:hover {
    background: var(--fill-secondary);
}

.btn-export.btn-print {
    background: #e3f2fd;
    color: #1565c0;
    border-color: #90caf9;
}

.btn-export.btn-print:hover {
    background: #bbdefb;
}

.btn-export.btn-pdf {
    background: #ffebee;
    color: #c62828;
    border-color: #ef9a9a;
}

.btn-export.btn-pdf:hover {
    background: #ffcdd2;
}

.btn-export.btn-excel {
    background: #e8f5e9;
    color: #2e7d32;
    border-color: #a5d6a7;
}

.btn-export.btn-excel:hover {
    background: #c8e6c9;
}

/* Cases Table */
.cases-table {
    width: 100%;
    border-collapse: collapse;
}

.cases-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.cases-table th {
    padding: 12px 10px;
    text-align: center;
    font-size: 13px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.cases-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.cases-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.cases-table tbody tr.selected {
    background: #e3f2fd;
}

.cases-table td {
    padding: 12px 10px;
    font-size: 13px;
    color: #2c3e50;
    text-align: center;
}

.case-number-link {
    font-family: var(--app-font-family);
    font-weight: 700;
    color: #0078d4;
    text-decoration: none;
    font-size: 14px;
}

.case-number-link:hover {
    text-decoration: underline;
}

.patient-name-cell {
    font-weight: 600;
    color: #2c3e50;
    text-decoration: none;
}

.patient-name-cell:hover {
    color: #0078d4;
}

.doctor-name-cell {
    color: #546e7a;
    font-size: 13px;
}

.amount-value {
    font-weight: 700;
    font-size: 14px;
}

.amount-value.total {
    color: #0078d4;
}

.amount-value.paid {
    color: #4caf50;
}

.amount-value.remaining {
    color: #f44336;
}

.amount-value.remaining.zero {
    color: #4caf50;
}

.status-cell {
    text-align: center;
}

.date-value {
    color: #757575;
    font-size: 12px;
}

.search-box {
    position: relative;
}

.search-box input {
    padding-right: 40px;
    min-width: 200px;
}

.search-box button {
    position: absolute;
    left: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 8px;
}

.search-box button:hover {
    color: var(--win-accent);
}

/* Row Selection Checkbox */
.row-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.select-all-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

/* Pagination Modern Style */
.pagination-modern {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 24px;
}

.pagination-modern .page-btn {
    min-width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: var(--fill-subtle);
    color: var(--text-primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    border: 1px solid transparent;
    transition: all 0.15s ease;
}

.pagination-modern .page-btn:hover {
    background: var(--fill-secondary);
    border-color: var(--stroke-subtle);
}

.pagination-modern .page-btn.active {
    background: var(--win-accent);
    color: white;
}

.pagination-modern .page-btn.disabled {
    opacity: 0.5;
    pointer-events: none;
}

/* Enhanced Summary Bar */
.cases-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 8px;
    margin-top: 16px;
    padding: 12px 14px;
    background: var(--fill-subtle);
    border-radius: 6px;
    border: 1px solid var(--stroke-subtle);
}

.summary-item {
    text-align: center;
    padding: 8px 6px;
    background: white;
    border-radius: 6px;
    border: 1px solid #f0f0f0;
}

.summary-value {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 3px;
}

.summary-value.total { color: var(--win-accent); }
.summary-value.paid { color: var(--status-success); }
.summary-value.discount { color: #CA5010; }
.summary-value.remaining { color: var(--status-error); }
.summary-value.cases-count { color: #6366f1; }

.summary-label {
    font-size: 10px;
    color: var(--text-secondary);
    font-weight: 600;
}

/* Selection Info Bar */
.selection-info {
    display: none;
    padding: 10px 16px;
    background: #fff3e0;
    border: 1px solid #ffcc80;
    border-radius: 6px;
    margin-bottom: 12px;
    align-items: center;
    gap: 12px;
}

.selection-info.visible {
    display: flex;
}

.selection-info .selection-count {
    font-weight: 600;
    color: #e65100;
}

.selection-info .selection-actions {
    display: flex;
    gap: 8px;
    margin-right: auto;
}

/* Print Styles */
@media print {
    body * {
        visibility: hidden;
    }

    .print-area, .print-area * {
        visibility: visible;
    }

    .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }

    .no-print {
        display: none !important;
    }

    .cases-table {
        font-size: 11px;
    }

    .cases-table th, .cases-table td {
        padding: 6px 4px;
    }
}
</style>

<!-- Advanced Filter Toolbar -->
<div class="panel mb-3 no-print">
    <div class="panel-body">
        <!-- Status Filter Chips -->
        <div class="d-flex justify-between align-center flex-wrap gap-3 mb-3">
            <div class="cases-filter-bar">
                <span class="filter-chip <?= empty($status) ? 'active' : '' ?>" data-status="">
                    <i class="fas fa-layer-group"></i>
                    هەموو
                    <span class="chip-count"><?= number_format($totalCases) ?></span>
                </span>
                <span class="filter-chip <?= $status === 'open' ? 'active' : '' ?>" data-status="open">
                    <i class="fas fa-folder-open"></i>
                    کراوە
                    <span class="chip-count"><?= $openCount ?></span>
                </span>
                <span class="filter-chip <?= $status === 'pending' ? 'active' : '' ?>" data-status="pending">
                    <i class="fas fa-clock"></i>
                    چاوەڕوان
                    <span class="chip-count"><?= $pendingCount ?></span>
                </span>
                <span class="filter-chip <?= $status === 'closed' ? 'active' : '' ?>" data-status="closed">
                    <i class="fas fa-check-circle"></i>
                    داخراو
                    <span class="chip-count"><?= $closedCount ?></span>
                </span>
            </div>

            <!-- Print & Export Buttons -->
            <div class="print-export-section">
                <button class="btn-export btn-print" onclick="printBrowser()" title="چاپکردن">
                    <i class="fas fa-print"></i>
                    چاپ
                </button>
                <button class="btn-export btn-pdf" onclick="exportPDF()" title="PDF دروستبکە">
                    <i class="fas fa-file-pdf"></i>
                    PDF
                </button>
                <button class="btn-export btn-excel" onclick="exportExcel()" title="Excel دروستبکە">
                    <i class="fas fa-file-excel"></i>
                    Excel
                </button>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="filter-toolbar">
            <!-- Quick Date Filters -->
            <div class="filter-group">
                <label>فلتەری خێرا</label>
                <div class="quick-date-filters">
                    <button type="button" class="quick-date-btn" data-range="today">ئەمڕۆ</button>
                    <button type="button" class="quick-date-btn" data-range="yesterday">دوێنێ</button>
                    <button type="button" class="quick-date-btn" data-range="week">ئەم هەفتە</button>
                    <button type="button" class="quick-date-btn" data-range="month">ئەم مانگە</button>
                    <button type="button" class="quick-date-btn" data-range="year">ئەمساڵ</button>
                </div>
            </div>

            <!-- Custom Date Range -->
            <div class="filter-group">
                <label>لە بەروار</label>
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>

            <div class="filter-group">
                <label>بۆ بەروار</label>
                <input type="date" id="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
            </div>

            <?php if ($isAdmin): ?>
            <!-- Doctor Filter -->
            <div class="filter-group">
                <label>دکتۆر</label>
                <select id="doctorFilter">
                    <option value="">هەموو دکتۆرەکان</option>
                    <?php foreach ($doctors as $doc): ?>
                    <option value="<?= $doc['id'] ?>" <?= $filterDoctorId == $doc['id'] ? 'selected' : '' ?>>
                        د. <?= htmlspecialchars($doc['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Payment Status Filter -->
            <div class="filter-group">
                <label>بارودۆخی پارەدان</label>
                <select id="paymentStatusFilter">
                    <option value="">هەموو</option>
                    <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>پارەی دراوە</option>
                    <option value="partial" <?= $paymentStatus === 'partial' ? 'selected' : '' ?>>بەشێکی دراوە</option>
                    <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>پارەی نەدراوە</option>
                </select>
            </div>

            <!-- Search Box -->
            <div class="filter-group">
                <label>گەڕان</label>
                <div class="search-box" style="position: relative;">
                    <input type="text" id="searchInput" placeholder="ناو، ژمارەی کەیس، مۆبایل..."
                           value="<?= htmlspecialchars($search) ?>" style="padding-left: 36px;">
                    <button type="button" onclick="performCaseSearch(1)" style="left: 4px;">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>

            <!-- Clear Filters -->
            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline" onclick="clearAllFilters()">
                    <i class="fas fa-times"></i>
                    پاککردنەوە
                </button>
            </div>
        </div>

        <!-- Active Filters Display -->
        <div class="active-filters" id="activeFilters"></div>
    </div>
</div>

<!-- Selection Info Bar -->
<div class="selection-info no-print" id="selectionInfo">
    <span class="selection-count"><span id="selectedCount">0</span> کەیس هەڵبژێردراوە</span>
    <div class="selection-actions">
        <button class="btn-export btn-print btn-sm" onclick="printSelected()">
            <i class="fas fa-print"></i>
            چاپی هەڵبژێردراوەکان
        </button>
        <button class="btn-export btn-pdf btn-sm" onclick="exportSelectedPDF()">
            <i class="fas fa-file-pdf"></i>
            PDF هەڵبژێردراوەکان
        </button>
    </div>
    <button class="btn btn-sm" onclick="clearSelection()">
        <i class="fas fa-times"></i>
        لابردنی هەڵبژاردن
    </button>
</div>

<!-- Cases List -->
<div class="panel print-area">
    <!-- Print Header (Hidden normally, shown when printing) -->
    <div class="print-header" style="display: none;">
        <div style="text-align: center; margin-bottom: 20px;">
            <h2 style="margin: 0;"><?= CLINIC_NAME ?></h2>
            <p style="margin: 5px 0;">ڕاپۆرتی کەیسەکان</p>
            <p style="margin: 5px 0; font-size: 12px;" id="printDateRange"></p>
            <p style="margin: 5px 0; font-size: 11px;">بەروار: <?= date('Y-m-d H:i') ?></p>
        </div>
    </div>

    <div class="panel-header no-print">
        <h3 class="panel-title">
            <i class="fas fa-briefcase-medical"></i>
            لیستی کەیسەکان
            <span class="badge badge-blue" id="totalCasesCount"><?= number_format($totalCases) ?></span>
        </h3>
    </div>
    <div class="panel-body">
        <?php if (empty($cases)): ?>
        <div class="empty-state" id="emptyState">
            <i class="fas fa-folder-open empty-state-icon"></i>
            <p class="empty-state-title">هیچ کەیسێک نەدۆزرایەوە</p>
            <p class="empty-state-text">
                <?= !empty($search) ? 'گەڕانەکەت ئەنجامی نەبوو، تکایە دووبارە هەوڵ بدەرەوە' : 'هێشتا هیچ کەیسێک تۆمار نەکراوە' ?>
            </p>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="cases-table" id="casesTable">
                <thead>
                    <tr>
                        <th class="no-print" style="width: 40px;">
                            <input type="checkbox" class="select-all-checkbox" id="selectAll" title="هەڵبژاردنی هەموو">
                        </th>
                        <th>ژمارەی کەیس</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>کۆی گشتی</th>
                        <th>پارەدراو</th>
                        <th>داشکاندن</th>
                        <th>ماوە</th>
                        <th>دۆخ</th>
                        <th>بەروار</th>
                        <th class="no-print">کردار</th>
                    </tr>
                </thead>
                <tbody id="casesTableBody">
                    <?php foreach ($cases as $case): ?>
                    <?php
                    // Invoice ledger, same figures as the case page header and the
                    // accountant screens. The cached c.* columns miss per-receipt
                    // discounts, which made "ماوە" too high by the discount.
                    $caseTotal     = (float)$case['ledger_total'];
                    $casePaid      = (float)$case['ledger_paid'];
                    $caseDiscount  = (float)$case['ledger_discount'];
                    $remaining     = (float)$case['ledger_balance'];

                    $statusBadges = [
                        'open' => ['badge-green', 'کراوە'],
                        'closed' => ['badge-blue', 'داخراوە'],
                        'pending' => ['badge-orange', 'چاوەڕوان'],
                        'cancelled' => ['badge-red', 'هەڵوەشاوە']
                    ];
                    $badge = $statusBadges[$case['status']] ?? ['', $case['status']];
                    ?>
                    <tr data-case-id="<?= $case['id'] ?>">
                        <td class="no-print">
                            <input type="checkbox" class="row-checkbox" value="<?= $case['id'] ?>">
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>" class="case-number-link">
                                <?= htmlspecialchars($case['case_number']) ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $case['patient_id'] ?? '' ?>" class="patient-name-cell">
                                <?= htmlspecialchars($case['patient_name']) ?>
                            </a>
                        </td>
                        <td class="doctor-name-cell">
                            د. <?= htmlspecialchars($case['doctor_name']) ?>
                        </td>
                        <td>
                            <span class="amount-value total">
                                <?= number_format($caseTotal) ?>
                            </span>
                        </td>
                        <td>
                            <span class="amount-value paid">
                                <?= number_format($casePaid) ?>
                            </span>
                        </td>
                        <td>
                            <span class="amount-value">
                                <?= $caseDiscount > 0 ? number_format($caseDiscount) : '-' ?>
                            </span>
                        </td>
                        <td>
                            <span class="amount-value remaining <?= $remaining <= 0 ? 'zero' : '' ?>">
                                <?= number_format($remaining) ?>
                            </span>
                        </td>
                        <td class="status-cell">
                            <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
                        </td>
                        <td class="date-value">
                            <?= date('Y-m-d', strtotime($case['created_at'])) ?>
                        </td>
                        <td class="no-print">
                            <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>" class="btn btn-sm btn-primary" title="بینین">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="casesPaginationWrapper" class="no-print">
        <?php if ($totalPages > 1): ?>
        <div class="pagination-modern">
            <?php if ($page > 1): ?>
            <a href="#" class="page-btn" data-page="<?= $page - 1 ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            if ($startPage > 1): ?>
            <a href="#" class="page-btn" data-page="1">1</a>
            <?php if ($startPage > 2): ?>
            <span class="page-btn disabled">...</span>
            <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" class="page-btn <?= $i === $page ? 'active' : '' ?>" data-page="<?= $i ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
            <span class="page-btn disabled">...</span>
            <?php endif; ?>
            <a href="#" class="page-btn" data-page="<?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
            <a href="#" class="page-btn" data-page="<?= $page + 1 ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </div><!-- End casesPaginationWrapper -->

        <!-- Cases Summary -->
        <?php if (!empty($cases)): ?>
        <div class="cases-summary" id="casesSummary">
            <div class="summary-item">
                <div class="summary-value cases-count" id="summaryCount"><?= number_format($totalCases) ?></div>
                <div class="summary-label">ژمارەی کەیس</div>
            </div>
            <div class="summary-item">
                <div class="summary-value total" id="summaryTotal"><?= number_format($totalAmount) ?> <small style="font-size: 11px;"></small></div>
                <div class="summary-label">کۆی گشتی</div>
            </div>
            <div class="summary-item">
                <div class="summary-value paid" id="summaryPaid"><?= number_format($totalPaid) ?> <small style="font-size: 11px;"></small></div>
                <div class="summary-label">پارەی دراو</div>
            </div>
            <div class="summary-item">
                <div class="summary-value discount" id="summaryDiscount"><?= number_format($totalDiscount) ?> <small style="font-size: 11px;"></small></div>
                <div class="summary-label">داشکان</div>
            </div>
            <div class="summary-item">
                <div class="summary-value remaining" id="summaryRemaining"><?= number_format($totalRemaining) ?> <small style="font-size: 11px;"></small></div>
                <div class="summary-label">کۆی ماوە</div>
            </div>
            <div class="summary-item" style="border-right: 2px solid #e0e0e0;">
                <div class="summary-value" style="color: var(--win-accent);" id="summaryPlans"><?= $installmentStats['active_plans'] ?></div>
                <div class="summary-label">پلانی قیست</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: var(--status-warning);" id="summaryInstallment"><?= number_format($installmentStats['total_remaining']) ?> <small style="font-size: 11px;"></small></div>
                <div class="summary-label">قیستی ماوە</div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Global state for filters
let currentFilters = {
    page: 1,
    status: '<?= htmlspecialchars($status) ?>',
    search: '<?= htmlspecialchars($search) ?>',
    dateFrom: '<?= htmlspecialchars($dateFrom) ?>',
    dateTo: '<?= htmlspecialchars($dateTo) ?>',
    doctorId: '<?= htmlspecialchars($filterDoctorId) ?>',
    paymentStatus: '<?= htmlspecialchars($paymentStatus) ?>'
};

let searchTimeout = null;
let searchCache = new Map();
const CACHE_DURATION = 60000;
let selectedCases = new Set();

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initializeFilters();
    updateActiveFiltersDisplay();
    attachEventListeners();
});

// Initialize filter values
function initializeFilters() {
    // Set date inputs
    document.getElementById('dateFrom').value = currentFilters.dateFrom;
    document.getElementById('dateTo').value = currentFilters.dateTo;

    // Highlight active quick date if matches
    updateQuickDateButtons();
}

// Attach event listeners
function attachEventListeners() {
    // Status filter chips
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            currentFilters.status = this.dataset.status;
            currentFilters.page = 1;
            performCaseSearch(1);
            updateStatusChips();
        });
    });

    // Quick date buttons
    document.querySelectorAll('.quick-date-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const range = this.dataset.range;
            setDateRange(range);
        });
    });

    // Date inputs
    document.getElementById('dateFrom').addEventListener('change', function() {
        currentFilters.dateFrom = this.value;
        currentFilters.page = 1;
        performCaseSearch(1);
        updateQuickDateButtons();
    });

    document.getElementById('dateTo').addEventListener('change', function() {
        currentFilters.dateTo = this.value;
        currentFilters.page = 1;
        performCaseSearch(1);
        updateQuickDateButtons();
    });

    <?php if ($isAdmin): ?>
    // Doctor filter
    document.getElementById('doctorFilter').addEventListener('change', function() {
        currentFilters.doctorId = this.value;
        currentFilters.page = 1;
        performCaseSearch(1);
    });
    <?php endif; ?>

    // Payment status filter
    document.getElementById('paymentStatusFilter').addEventListener('change', function() {
        currentFilters.paymentStatus = this.value;
        currentFilters.page = 1;
        performCaseSearch(1);
    });

    // Search input
    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();

        if (query.length === 0) {
            currentFilters.search = '';
            performCaseSearch(1);
            return;
        }

        searchTimeout = setTimeout(() => {
            if (query.length >= 2) {
                currentFilters.search = query;
                currentFilters.page = 1;
                performCaseSearch(1);
            }
        }, 300);
    });

    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            currentFilters.search = this.value.trim();
            currentFilters.page = 1;
            performCaseSearch(1);
        }
    });

    // Select all checkbox
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = this.checked;
            const caseId = cb.value;
            if (this.checked) {
                selectedCases.add(caseId);
            } else {
                selectedCases.delete(caseId);
            }
        });
        updateSelectionInfo();
    });

    // Row checkboxes
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('row-checkbox')) {
            const caseId = e.target.value;
            if (e.target.checked) {
                selectedCases.add(caseId);
            } else {
                selectedCases.delete(caseId);
            }
            updateSelectionInfo();
        }
    });

    // Pagination
    attachPaginationHandlers();
}

// Set date range from quick buttons
function setDateRange(range) {
    const today = new Date();
    let fromDate, toDate;

    switch(range) {
        case 'today':
            fromDate = toDate = formatDate(today);
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            fromDate = toDate = formatDate(yesterday);
            break;
        case 'week':
            const weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay());
            fromDate = formatDate(weekStart);
            toDate = formatDate(today);
            break;
        case 'month':
            fromDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            toDate = formatDate(today);
            break;
        case 'year':
            fromDate = formatDate(new Date(today.getFullYear(), 0, 1));
            toDate = formatDate(today);
            break;
    }

    document.getElementById('dateFrom').value = fromDate;
    document.getElementById('dateTo').value = toDate;
    currentFilters.dateFrom = fromDate;
    currentFilters.dateTo = toDate;
    currentFilters.page = 1;

    performCaseSearch(1);
    updateQuickDateButtons();
}

// Format date as YYYY-MM-DD
function formatDate(date) {
    return date.toISOString().split('T')[0];
}

// Update quick date button styles
function updateQuickDateButtons() {
    const today = formatDate(new Date());
    const yesterday = formatDate(new Date(Date.now() - 86400000));
    const weekStart = formatDate(new Date(new Date().setDate(new Date().getDate() - new Date().getDay())));
    const monthStart = formatDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    const yearStart = formatDate(new Date(new Date().getFullYear(), 0, 1));

    document.querySelectorAll('.quick-date-btn').forEach(btn => {
        btn.classList.remove('active');
        const range = btn.dataset.range;

        if (range === 'today' && currentFilters.dateFrom === today && currentFilters.dateTo === today) {
            btn.classList.add('active');
        } else if (range === 'yesterday' && currentFilters.dateFrom === yesterday && currentFilters.dateTo === yesterday) {
            btn.classList.add('active');
        } else if (range === 'week' && currentFilters.dateFrom === weekStart && currentFilters.dateTo === today) {
            btn.classList.add('active');
        } else if (range === 'month' && currentFilters.dateFrom === monthStart && currentFilters.dateTo === today) {
            btn.classList.add('active');
        } else if (range === 'year' && currentFilters.dateFrom === yearStart && currentFilters.dateTo === today) {
            btn.classList.add('active');
        }
    });
}

// Update status chips
function updateStatusChips() {
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.classList.remove('active');
        if (chip.dataset.status === currentFilters.status) {
            chip.classList.add('active');
        }
    });
}

// Update active filters display
function updateActiveFiltersDisplay() {
    const container = document.getElementById('activeFilters');
    container.innerHTML = '';

    const filters = [];

    if (currentFilters.search) {
        filters.push({ key: 'search', label: 'گەڕان', value: currentFilters.search });
    }
    if (currentFilters.dateFrom) {
        filters.push({ key: 'dateFrom', label: 'لە', value: currentFilters.dateFrom });
    }
    if (currentFilters.dateTo) {
        filters.push({ key: 'dateTo', label: 'بۆ', value: currentFilters.dateTo });
    }
    if (currentFilters.doctorId) {
        const select = document.getElementById('doctorFilter');
        if (select) {
            const selectedOption = select.options[select.selectedIndex];
            filters.push({ key: 'doctorId', label: 'دکتۆر', value: selectedOption.text });
        }
    }
    if (currentFilters.paymentStatus) {
        const statusLabels = { paid: 'پارەی دراوە', partial: 'بەشێکی دراوە', unpaid: 'پارەی نەدراوە' };
        filters.push({ key: 'paymentStatus', label: 'پارەدان', value: statusLabels[currentFilters.paymentStatus] || currentFilters.paymentStatus });
    }

    filters.forEach(filter => {
        const tag = document.createElement('span');
        tag.className = 'active-filter-tag';
        tag.innerHTML = `
            ${filter.label}: ${filter.value}
            <span class="remove-filter" data-filter="${filter.key}">
                <i class="fas fa-times"></i>
            </span>
        `;
        container.appendChild(tag);

        tag.querySelector('.remove-filter').addEventListener('click', function() {
            removeFilter(this.dataset.filter);
        });
    });
}

// Remove single filter
function removeFilter(filterKey) {
    currentFilters[filterKey] = '';

    switch(filterKey) {
        case 'search':
            document.getElementById('searchInput').value = '';
            break;
        case 'dateFrom':
            document.getElementById('dateFrom').value = '';
            break;
        case 'dateTo':
            document.getElementById('dateTo').value = '';
            break;
        case 'doctorId':
            const docSelect = document.getElementById('doctorFilter');
            if (docSelect) docSelect.value = '';
            break;
        case 'paymentStatus':
            document.getElementById('paymentStatusFilter').value = '';
            break;
    }

    currentFilters.page = 1;
    performCaseSearch(1);
}

// Clear all filters
function clearAllFilters() {
    currentFilters = {
        page: 1,
        status: '',
        search: '',
        dateFrom: '',
        dateTo: '',
        doctorId: '',
        paymentStatus: ''
    };

    document.getElementById('searchInput').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    document.getElementById('paymentStatusFilter').value = '';

    const docSelect = document.getElementById('doctorFilter');
    if (docSelect) docSelect.value = '';

    updateStatusChips();
    updateQuickDateButtons();
    performCaseSearch(1);
}

// Perform search
function performCaseSearch(page = 1) {
    currentFilters.page = page;

    const tableBody = document.getElementById('casesTableBody');
    const searchInput = document.getElementById('searchInput');

    // Visual feedback
    if (tableBody) {
        tableBody.style.opacity = '0.6';
    }

    // Prepare form data
    const formData = new FormData();
    formData.append('page', currentFilters.page);
    formData.append('search', currentFilters.search);
    formData.append('status', currentFilters.status);
    formData.append('date_from', currentFilters.dateFrom);
    formData.append('date_to', currentFilters.dateTo);
    formData.append('doctor_id', currentFilters.doctorId);
    formData.append('payment_status', currentFilters.paymentStatus);

    // Perform AJAX request
    fetch('<?= APP_URL ?>/cases/ajax_cases_search.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateUIWithResults(data);
            updateActiveFiltersDisplay();
        } else {
            console.error('Search error:', data.error);
        }

        if (tableBody) {
            tableBody.style.opacity = '1';
        }
    })
    .catch(error => {
        console.error('AJAX error:', error);
        if (tableBody) {
            tableBody.style.opacity = '1';
        }
    });
}

// Update UI with results
function updateUIWithResults(data) {
    const tableBody = document.getElementById('casesTableBody');
    const paginationWrapper = document.getElementById('casesPaginationWrapper');

    // Update table body
    if (tableBody && data.html) {
        tableBody.innerHTML = data.html;
    }

    // Update pagination
    if (paginationWrapper) {
        paginationWrapper.innerHTML = data.pagination;
        attachPaginationHandlers();
    }

    // Update total cases badge
    const totalBadge = document.getElementById('totalCasesCount');
    if (totalBadge) {
        totalBadge.textContent = numberFormat(data.totalCases);
    }

    // Update statistics
    if (data.statistics) {
        updateStatistics(data.statistics);
    }

    // Update status counts if provided
    if (data.statusCounts) {
        updateStatusCounts(data.statusCounts);
    }

    // Re-check selected cases
    selectedCases.forEach(caseId => {
        const checkbox = document.querySelector(`.row-checkbox[value="${caseId}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    });

    updateSelectionInfo();
}

// Update statistics
function updateStatistics(stats) {
    const currency = '';

    document.getElementById('summaryCount').textContent = numberFormat(stats.totalCases);
    document.getElementById('summaryTotal').innerHTML = `${numberFormat(stats.totalAmount)} <small style="font-size: 11px;">${currency}</small>`;
    document.getElementById('summaryPaid').innerHTML = `${numberFormat(stats.totalPaid)} <small style="font-size: 11px;">${currency}</small>`;
    document.getElementById('summaryDiscount').innerHTML = `${numberFormat(stats.totalDiscount)} <small style="font-size: 11px;">${currency}</small>`;
    document.getElementById('summaryRemaining').innerHTML = `${numberFormat(stats.totalRemaining)} <small style="font-size: 11px;">${currency}</small>`;
    document.getElementById('summaryPlans').textContent = stats.activePlans;
    document.getElementById('summaryInstallment').innerHTML = `${numberFormat(stats.installmentRemaining)} <small style="font-size: 11px;">${currency}</small>`;
}

// Update status counts
function updateStatusCounts(counts) {
    document.querySelectorAll('.filter-chip').forEach(chip => {
        const status = chip.dataset.status;
        const countEl = chip.querySelector('.chip-count');
        if (countEl) {
            if (status === '') {
                countEl.textContent = numberFormat(counts.total);
            } else if (counts[status] !== undefined) {
                countEl.textContent = numberFormat(counts[status]);
            }
        }
    });
}

// Attach pagination handlers
function attachPaginationHandlers() {
    document.querySelectorAll('.page-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const page = parseInt(this.getAttribute('data-page'));
            if (page && !this.classList.contains('active') && !this.classList.contains('disabled')) {
                performCaseSearch(page);
            }
        });
    });
}

// Number format helper
function numberFormat(num) {
    return parseFloat(num || 0).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

// Selection management
function updateSelectionInfo() {
    const info = document.getElementById('selectionInfo');
    const count = document.getElementById('selectedCount');

    count.textContent = selectedCases.size;

    if (selectedCases.size > 0) {
        info.classList.add('visible');
    } else {
        info.classList.remove('visible');
    }
}

function clearSelection() {
    selectedCases.clear();
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateSelectionInfo();
}

// Print functions
function printBrowser() {
    // Show print header
    document.querySelector('.print-header').style.display = 'block';

    // Set date range in print header
    let dateRange = '';
    if (currentFilters.dateFrom || currentFilters.dateTo) {
        dateRange = `لە ${currentFilters.dateFrom || 'سەرەتا'} بۆ ${currentFilters.dateTo || 'ئێستا'}`;
    }
    document.getElementById('printDateRange').textContent = dateRange;

    // Print
    window.print();

    // Hide print header after print
    setTimeout(() => {
        document.querySelector('.print-header').style.display = 'none';
    }, 1000);
}

function printSelected() {
    if (selectedCases.size === 0) {
        alert('تکایە کەیسێک هەڵبژێرە');
        return;
    }

    // Hide non-selected rows
    document.querySelectorAll('#casesTableBody tr').forEach(row => {
        const checkbox = row.querySelector('.row-checkbox');
        if (checkbox && !selectedCases.has(checkbox.value)) {
            row.style.display = 'none';
        }
    });

    printBrowser();

    // Show all rows again
    setTimeout(() => {
        document.querySelectorAll('#casesTableBody tr').forEach(row => {
            row.style.display = '';
        });
    }, 1000);
}

// Export functions
function exportPDF() {
    const params = new URLSearchParams({
        status: currentFilters.status,
        search: currentFilters.search,
        date_from: currentFilters.dateFrom,
        date_to: currentFilters.dateTo,
        doctor_id: currentFilters.doctorId,
        payment_status: currentFilters.paymentStatus
    });

    window.open('<?= APP_URL ?>/cases/export_pdf.php?' + params.toString(), '_blank');
}

function exportSelectedPDF() {
    if (selectedCases.size === 0) {
        alert('تکایە کەیسێک هەڵبژێرە');
        return;
    }

    const params = new URLSearchParams({
        case_ids: Array.from(selectedCases).join(',')
    });

    window.open('<?= APP_URL ?>/cases/export_pdf.php?' + params.toString(), '_blank');
}

function exportExcel() {
    const params = new URLSearchParams({
        status: currentFilters.status,
        search: currentFilters.search,
        date_from: currentFilters.dateFrom,
        date_to: currentFilters.dateTo,
        doctor_id: currentFilters.doctorId,
        payment_status: currentFilters.paymentStatus
    });

    window.location.href = '<?= APP_URL ?>/cases/export_excel.php?' + params.toString();
}

// Initialize pagination handlers
attachPaginationHandlers();

// Add CSS animation for search pulse
const style = document.createElement('style');
style.textContent = `
    @keyframes searchPulse {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
`;
document.head.appendChild(style);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
