<?php
/**
 * Rejected Patients Report Page
 * ڕاپۆرتی نەخۆشە ڕەتکراوەکان
 */

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
$isReceptionist = $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist; // admin + reception manage the waiting room
$doctors = $auth->getDoctors();

// Get filter values
$selectedDoctor = $_GET['doctor'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$selectedStatus = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Handle restore/delete — CSRF + role gated (only admin/reception manage these)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    if (!$hasFullAccess) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }
    $waitingId = intval($_POST['waiting_id'] ?? 0);
    $action = $_POST['action'];

    if ($action === 'restore' && $waitingId) {
        $db->update('waiting_room', ['status' => 'waiting', 'notes' => ''], 'id = ?', [$waitingId]);
        AuditLog::log('waiting_restore', 'waiting_room', $waitingId, null, ['status' => 'waiting', 'source' => 'rejected_report']);
        header('Location: ' . APP_URL . '/rejected-patients.php?restored=1&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo));
        exit;
    } elseif ($action === 'delete' && $waitingId) {
        // Hard delete is admin-only; snapshot to the audit log first
        if (!$isAdmin) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
        $oldRow = $db->fetch("SELECT * FROM waiting_room WHERE id = ?", [$waitingId]);
        if ($oldRow) {
            AuditLog::log('waiting_delete', 'waiting_room', $waitingId, $oldRow);
        }
        $db->delete('waiting_room', 'id = ?', [$waitingId]);
        header('Location: ' . APP_URL . '/rejected-patients.php?deleted=1&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo));
        exit;
    }
}

$pageTitle = 'ڕاپۆرتی نەخۆشە ڕەتکراوەکان';
$currentPage = 'rejected-patients';
require_once __DIR__ . '/includes/header.php';

// Build query
$sql = "SELECT wr.*, p.full_name, p.phone, p.patient_code,
        u.full_name as doctor_name
        FROM waiting_room wr
        LEFT JOIN patients p ON wr.patient_id = p.id
        LEFT JOIN users u ON wr.doctor_id = u.id
        WHERE wr.status IN ('rejected', 'cancelled')
        AND DATE(wr.check_in_time) BETWEEN ? AND ?";

$params = [$dateFrom, $dateTo];

if (!$hasFullAccess) {
    // Non-privileged users (doctors) only ever see their own — the ?doctor=
    // param cannot widen this (was an IDOR that exposed other doctors' patients).
    $sql .= " AND wr.doctor_id = ?";
    $params[] = $currentUser['id'];
} elseif ($selectedDoctor) {
    $sql .= " AND wr.doctor_id = ?";
    $params[] = $selectedDoctor;
}

if ($selectedStatus !== 'all') {
    $sql .= " AND wr.status = ?";
    $params[] = $selectedStatus;
}

if ($searchQuery) {
    $sql .= " AND (p.full_name LIKE ? OR p.phone LIKE ? OR p.patient_code LIKE ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
}

$sql .= " ORDER BY wr.check_in_time DESC";
$rejectedPatients = $db->fetchAll($sql, $params);

// Statistics
$totalCount = count($rejectedPatients);
$rejectedCount = count(array_filter($rejectedPatients, fn($p) => $p['status'] === 'rejected'));
$cancelledCount = count(array_filter($rejectedPatients, fn($p) => $p['status'] === 'cancelled'));

// Top rejection reasons
$reasonStats = [];
foreach ($rejectedPatients as $p) {
    if (!empty($p['notes'])) {
        $note = $p['notes'];
        if (!isset($reasonStats[$note])) {
            $reasonStats[$note] = 0;
        }
        $reasonStats[$note]++;
    }
}
arsort($reasonStats);
$reasonStats = array_slice($reasonStats, 0, 4, true);
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
    background: linear-gradient(135deg, #d32f2f, #b71c1c);
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
    text-decoration: none;
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
}

.btn-back:hover {
    background: var(--win-accent-dark, #005a9e);
    color: white;
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
    min-width: 130px;
}

.filter-group input:focus,
.filter-group select:focus {
    border-color: #d32f2f;
    outline: none;
}

.btn-filter {
    padding: 8px 16px;
    background: #d32f2f;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
}

.btn-filter:hover {
    background: #b71c1c;
}

.btn-reset {
    padding: 8px 14px;
    background: #f5f5f5;
    color: #666;
    border: 1px solid #ddd;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
}

/* Stats Cards */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
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

.stat-card.total { border-top: 3px solid #607d8b; }
.stat-card.rejected { border-top: 3px solid #f44336; }
.stat-card.cancelled { border-top: 3px solid #ff9800; }

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

/* Reasons Tags */
.reasons-card {
    background: white;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.reasons-title {
    font-size: 13px;
    font-weight: 600;
    color: #333;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.reasons-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.reason-tag {
    background: #ffebee;
    color: #c62828;
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.reason-tag .count {
    background: #c62828;
    color: white;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
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
    background: #d32f2f;
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

.patient-name a {
    color: inherit;
    text-decoration: none;
}

.patient-name a:hover {
    color: #d32f2f;
}

.patient-phone {
    font-size: 11px;
    color: #888;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.status-badge.rejected {
    background: #ffebee;
    color: #c62828;
}

.status-badge.cancelled {
    background: #fff3e0;
    color: #e65100;
}

.reason-text {
    max-width: 180px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #666;
    font-size: 12px;
}

.action-btns {
    display: flex;
    gap: 6px;
}

.btn-sm {
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 11px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-family: inherit;
}

.btn-restore {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.btn-restore:hover {
    background: #c8e6c9;
}

.btn-delete {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ef9a9a;
}

.btn-delete:hover {
    background: #ffcdd2;
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
    .header,
    .action-btns {
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

<?php if (isset($_GET['restored'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نەخۆش گەڕایەوە بۆ چاوەڕوانی</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-trash alert-icon"></i> تۆمار سڕایەوە</div>
<?php endif; ?>

<div class="report-container">
    <!-- Header -->
    <div class="report-header no-print">
        <div class="report-title">
            <div class="icon"><i class="fas fa-user-times"></i></div>
            <div>
                <h2>ڕاپۆرتی نەخۆشە ڕەتکراوەکان</h2>
                <small style="color: #888;">لە <?= date('Y/m/d', strtotime($dateFrom)) ?> بۆ <?= date('Y/m/d', strtotime($dateTo)) ?></small>
            </div>
        </div>
        <div class="report-actions">
            <a href="<?= APP_URL ?>/waiting-room.php" class="btn-action btn-back">
                <i class="fas fa-arrow-right"></i> ژووری چاوەڕوانی
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
                    <label>دۆخ</label>
                    <select name="status">
                        <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>هەموو</option>
                        <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>ڕەتکراوە</option>
                        <option value="cancelled" <?= $selectedStatus === 'cancelled' ? 'selected' : '' ?>>هەڵوەشاوە</option>
                    </select>
                </div>
                <?php if ($isAdmin && !empty($doctors)): ?>
                <div class="filter-group">
                    <label>دکتۆر</label>
                    <select name="doctor">
                        <option value="">هەموو</option>
                        <?php foreach ($doctors as $doc): ?>
                        <option value="<?= $doc['id'] ?>" <?= $selectedDoctor == $doc['id'] ? 'selected' : '' ?>>د. <?= htmlspecialchars($doc['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group">
                    <label>گەڕان</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="ناو، تەلەفۆن...">
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> گەڕان
                </button>
                <a href="<?= APP_URL ?>/rejected-patients.php" class="btn-reset">
                    <i class="fas fa-redo"></i> ڕیسێت
                </a>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card total">
            <div class="stat-value"><?= $totalCount ?></div>
            <div class="stat-label">کۆی گشتی</div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-value"><?= $rejectedCount ?></div>
            <div class="stat-label">ڕەتکراوە</div>
        </div>
        <div class="stat-card cancelled">
            <div class="stat-value"><?= $cancelledCount ?></div>
            <div class="stat-label">هەڵوەشاوە</div>
        </div>
        <?php foreach ($reasonStats as $reason => $count): ?>
        <div class="stat-card" style="border-top: 3px solid #9c27b0;">
            <div class="stat-value"><?= $count ?></div>
            <div class="stat-label" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($reason) ?>"><?= htmlspecialchars(mb_substr($reason, 0, 15)) ?><?= mb_strlen($reason) > 15 ? '...' : '' ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Top Reasons -->
    <?php if (!empty($reasonStats)): ?>
    <div class="reasons-card no-print">
        <div class="reasons-title"><i class="fas fa-chart-pie"></i> هۆکارە باوەکان</div>
        <div class="reasons-list">
            <?php foreach ($reasonStats as $reason => $count): ?>
            <span class="reason-tag">
                <?= htmlspecialchars($reason) ?>
                <span class="count"><?= $count ?></span>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="table-card" id="reportTable">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> لیستی نەخۆشە ڕەتکراوەکان</h3>
            <span class="table-count"><?= $totalCount ?> نەخۆش</span>
        </div>

        <?php if (empty($rejectedPatients)): ?>
        <div class="no-data">
            <i class="fas fa-check-circle"></i>
            <p>هیچ نەخۆشێکی ڕەتکراو نییە لەم ماوەیەدا</p>
        </div>
        <?php else: ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نەخۆش</th>
                    <th>دکتۆر</th>
                    <th>دۆخ</th>
                    <th>هۆکار</th>
                    <th>کات</th>
                    <th class="no-print">کردار</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rejectedPatients as $index => $patient): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <div class="patient-info">
                            <span class="patient-name">
                                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['patient_id'] ?>">
                                    <?= htmlspecialchars($patient['full_name']) ?>
                                </a>
                            </span>
                            <span class="patient-phone"><?= htmlspecialchars($patient['phone'] ?? '') ?></span>
                        </div>
                    </td>
                    <td>د. <?= htmlspecialchars($patient['doctor_name'] ?? 'نادیار') ?></td>
                    <td>
                        <span class="status-badge <?= $patient['status'] ?>">
                            <?= $patient['status'] === 'rejected' ? 'ڕەتکراوە' : 'هەڵوەشاوە' ?>
                        </span>
                    </td>
                    <td>
                        <span class="reason-text" title="<?= htmlspecialchars($patient['notes'] ?? '') ?>">
                            <?= htmlspecialchars($patient['notes'] ?? '-') ?>
                        </span>
                    </td>
                    <td style="white-space: nowrap;">
                        <?= date('Y/m/d H:i', strtotime($patient['check_in_time'])) ?>
                    </td>
                    <td class="no-print">
                        <div class="action-btns">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="waiting_id" value="<?= $patient['id'] ?>">
                                <button type="submit" class="btn-sm btn-restore" title="گەڕاندنەوە">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوە؟')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="waiting_id" value="<?= $patient['id'] ?>">
                                <button type="submit" class="btn-sm btn-delete" title="سڕینەوە">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
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
        filename: 'rejected-patients-<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    const header = document.createElement('div');
    header.innerHTML = `
        <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #d32f2f;">
            <h2 style="margin: 0; color: #b71c1c;">ڕاپۆرتی نەخۆشە ڕەتکراوەکان</h2>
            <p style="margin: 5px 0 0; color: #666; font-size: 12px;">لە <?= date('Y/m/d', strtotime($dateFrom)) ?> بۆ <?= date('Y/m/d', strtotime($dateTo)) ?> - کۆی گشتی: <?= $totalCount ?></p>
        </div>
    `;

    const clone = element.cloneNode(true);
    clone.insertBefore(header, clone.firstChild);
    clone.querySelector('.table-header').style.display = 'none';

    // Remove action buttons from PDF
    clone.querySelectorAll('.no-print').forEach(el => el.remove());

    html2pdf().set(opt).from(clone).save();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
