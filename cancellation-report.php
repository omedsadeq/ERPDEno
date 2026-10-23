<?php
/**
 * Cancellation Report Page
 * ڕاپۆرتی هەڵوەشاندنەوەکان
 */

$pageTitle = 'ڕاپۆرتی هەڵوەشاندنەوەکان';
$currentPage = 'cancellation-report';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

// Filters
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$reasonFilter = $_GET['reason'] ?? '';
$doctorFilter = $_GET['doctor'] ?? '';

// Build query
$sql = "SELECT a.*,
        p.full_name as patient_name, p.phone as patient_phone,
        u.full_name as doctor_name,
        cr.reason_text
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.doctor_id = u.id
        LEFT JOIN cancellation_reasons cr ON a.cancellation_reason = cr.reason_key
        WHERE a.status = 'cancelled'
        AND a.appointment_date BETWEEN ? AND ?";

$params = [$dateFrom, $dateTo];

if ($reasonFilter) {
    $sql .= " AND a.cancellation_reason = ?";
    $params[] = $reasonFilter;
}

if ($doctorFilter) {
    $sql .= " AND a.doctor_id = ?";
    $params[] = $doctorFilter;
}

// Non-admin sees only their cancellations
if (!$isAdmin) {
    $sql .= " AND a.doctor_id = ?";
    $params[] = $currentUser['id'];
}

$sql .= " ORDER BY a.cancelled_at DESC, a.appointment_date DESC";

$cancellations = $db->fetchAll($sql, $params);

// Get reasons for filter
$reasons = $db->fetchAll("SELECT * FROM cancellation_reasons ORDER BY sort_order");

// Get doctors for filter (admin only)
$doctors = $isAdmin ? $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name") : [];

// Statistics
$totalCancelled = count($cancellations);
$reasonStats = [];
foreach ($cancellations as $c) {
    $key = $c['cancellation_reason'] ?: 'unknown';
    if (!isset($reasonStats[$key])) {
        $reasonStats[$key] = ['count' => 0, 'text' => $c['reason_text'] ?: 'نادیار'];
    }
    $reasonStats[$key]['count']++;
}
arsort($reasonStats);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.report-container {
    max-width: 1200px;
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
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #f44336, #c62828);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
}

.report-actions {
    display: flex;
    gap: 8px;
}

.btn-action {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
}

.btn-print {
    background: #6c757d;
    color: white;
}

.btn-print:hover {
    background: #5a6268;
}

.btn-pdf {
    background: #dc3545;
    color: white;
}

.btn-pdf:hover {
    background: #c82333;
}

.btn-back {
    background: var(--win-accent, #0078d4);
    color: white;
    text-decoration: none;
}

.btn-back:hover {
    background: var(--win-accent-dark, #005a9e);
}

/* Filters */
.filters-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
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
    min-width: 140px;
}

.filter-group input:focus,
.filter-group select:focus {
    border-color: var(--win-accent, #0078d4);
    outline: none;
}

.btn-filter {
    padding: 8px 16px;
    background: var(--win-accent, #0078d4);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
}

.btn-filter:hover {
    background: var(--win-accent-dark, #005a9e);
}

/* Stats Cards */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    text-align: center;
}

.stat-card.total {
    border-top: 3px solid #f44336;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a1a;
}

.stat-label {
    font-size: 12px;
    color: #666;
    margin-top: 4px;
}

/* Table */
.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.table-header {
    padding: 14px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header h3 {
    margin: 0;
    font-size: 14px;
    color: #333;
}

.table-count {
    background: #f44336;
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
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
    padding: 12px 14px;
    text-align: right;
    font-weight: 600;
    color: #333;
    border-bottom: 1px solid #e0e0e0;
}

.report-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.report-table tr:hover {
    background: #f8f9fa;
}

.patient-info {
    display: flex;
    flex-direction: column;
}

.patient-name {
    font-weight: 600;
    color: #1a1a1a;
}

.patient-phone {
    font-size: 11px;
    color: #888;
}

.reason-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #ffebee;
    color: #c62828;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.comment-text {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #666;
    font-size: 12px;
}

.date-cell {
    white-space: nowrap;
}

.no-data {
    text-align: center;
    padding: 40px;
    color: #888;
}

.no-data i {
    font-size: 40px;
    margin-bottom: 10px;
    display: block;
    color: #ddd;
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
}

/* Responsive */
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
}
</style>

<div class="report-container">
    <!-- Header -->
    <div class="report-header no-print">
        <div class="report-title">
            <div class="icon"><i class="fas fa-calendar-times"></i></div>
            <div>
                <h2>ڕاپۆرتی هەڵوەشاندنەوەکان</h2>
                <small style="color: #888;">لە <?= date('Y/m/d', strtotime($dateFrom)) ?> بۆ <?= date('Y/m/d', strtotime($dateTo)) ?></small>
            </div>
        </div>
        <div class="report-actions">
            <a href="<?= APP_URL ?>/appointments.php" class="btn-action btn-back">
                <i class="fas fa-arrow-right"></i> مەوعیدەکان
            </a>
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="fas fa-print"></i> چاپکردن
            </button>
            <button onclick="exportPDF()" class="btn-action btn-pdf">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card no-print">
        <form method="GET" action="">
            <div class="filters-row">
                <div class="filter-group">
                    <label>لە بەروار</label>
                    <input type="date" name="from" value="<?= $dateFrom ?>">
                </div>
                <div class="filter-group">
                    <label>بۆ بەروار</label>
                    <input type="date" name="to" value="<?= $dateTo ?>">
                </div>
                <div class="filter-group">
                    <label>هۆکار</label>
                    <select name="reason">
                        <option value="">هەموو</option>
                        <?php foreach ($reasons as $r): ?>
                        <option value="<?= $r['reason_key'] ?>" <?= $reasonFilter === $r['reason_key'] ? 'selected' : '' ?>><?= htmlspecialchars($r['reason_text']) ?></option>
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
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> گەڕان
                </button>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card total">
            <div class="stat-value"><?= $totalCancelled ?></div>
            <div class="stat-label">کۆی هەڵوەشاندنەوە</div>
        </div>
        <?php $i = 0; foreach (array_slice($reasonStats, 0, 4) as $key => $stat): $i++; ?>
        <div class="stat-card" style="border-top: 3px solid <?= ['#ff9800', '#2196f3', '#4caf50', '#9c27b0'][$i-1] ?? '#666' ?>;">
            <div class="stat-value"><?= $stat['count'] ?></div>
            <div class="stat-label"><?= htmlspecialchars($stat['text']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Table -->
    <div class="table-card" id="reportTable">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> لیستی هەڵوەشاندنەوەکان</h3>
            <span class="table-count"><?= $totalCancelled ?> مەوعید</span>
        </div>

        <?php if (empty($cancellations)): ?>
        <div class="no-data">
            <i class="fas fa-check-circle"></i>
            <p>هیچ مەوعیدێکی هەڵوەشاوە نییە لەم ماوەیەدا</p>
        </div>
        <?php else: ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نەخۆش</th>
                    <th>دکتۆر</th>
                    <th>بەرواری مەوعید</th>
                    <th>هۆکار</th>
                    <th>تێبینی</th>
                    <th>کاتی هەڵوەشاندنەوە</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cancellations as $index => $c): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <div class="patient-info">
                            <span class="patient-name"><?= htmlspecialchars($c['patient_name'] ?? 'نادیار') ?></span>
                            <span class="patient-phone"><?= htmlspecialchars($c['patient_phone'] ?? '') ?></span>
                        </div>
                    </td>
                    <td>د. <?= htmlspecialchars($c['doctor_name'] ?? 'نادیار') ?></td>
                    <td class="date-cell">
                        <?= date('Y/m/d', strtotime($c['appointment_date'])) ?>
                        <small style="color: #888;"><?= date('H:i', strtotime($c['appointment_time'])) ?></small>
                    </td>
                    <td>
                        <span class="reason-badge"><?= htmlspecialchars($c['reason_text'] ?? $c['cancellation_reason'] ?? 'نادیار') ?></span>
                    </td>
                    <td>
                        <span class="comment-text" title="<?= htmlspecialchars($c['cancellation_comment'] ?? '') ?>">
                            <?= htmlspecialchars($c['cancellation_comment'] ?? '-') ?>
                        </span>
                    </td>
                    <td class="date-cell">
                        <?php if ($c['cancelled_at']): ?>
                        <?= date('Y/m/d H:i', strtotime($c['cancelled_at'])) ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/html2pdf/html2pdf.bundle.min.js"></script>
<script>
function exportPDF() {
    const element = document.getElementById('reportTable');
    const opt = {
        margin: 10,
        filename: 'cancellation-report-<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    // Add header for PDF
    const header = document.createElement('div');
    header.innerHTML = `
        <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f44336;">
            <h2 style="margin: 0; color: #c62828;">ڕاپۆرتی هەڵوەشاندنەوەکان</h2>
            <p style="margin: 5px 0 0; color: #666; font-size: 12px;">لە <?= date('Y/m/d', strtotime($dateFrom)) ?> بۆ <?= date('Y/m/d', strtotime($dateTo)) ?> - کۆی گشتی: <?= $totalCancelled ?></p>
        </div>
    `;

    const clone = element.cloneNode(true);
    clone.insertBefore(header, clone.firstChild);
    clone.querySelector('.table-header').style.display = 'none';

    html2pdf().set(opt).from(clone).save();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
