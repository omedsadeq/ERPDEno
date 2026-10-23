<?php
/**
 * Financial Reports Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'ڕاپۆرتەکان';
$breadcrumb = [['title' => 'ڕاپۆرتەکان']];

// Admin gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/Installment.php';

// Check admin access
if (!$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$paymentManager = new Payment();
$installmentManager = new Installment();

// Date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$reportType = $_GET['type'] ?? 'overview';

// Get statistics
$paymentStats = $paymentManager->getStatistics(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$installmentStats = $installmentManager->getStatistics();

// Calculate expenses — sum ALL expense tables (legacy `expenses` plus the
// general/lab/store tables the newer expense pages write to), or profit is overstated.
$expParams = ['from' => $dateFrom, 'to' => $dateTo];
$totalExpenses = (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date BETWEEN :from AND :to", $expParams)['total'] ?? 0);
foreach (['expenses_general', 'expenses_lab', 'expenses_store'] as $expTable) {
    if ($db->tableExists($expTable)) {
        $totalExpenses += (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM {$expTable} WHERE expense_date BETWEEN :from AND :to", $expParams)['total'] ?? 0);
    }
}

// Calculate profit
$totalIncome = $paymentStats['total'] ?? 0;
$netProfit = $totalIncome - $totalExpenses;

// Get daily report
$dailyReport = $db->fetchAll(
    "SELECT DATE(payment_date) as date, SUM(amount) as total, COUNT(*) as count
     FROM payments WHERE status = 'approved'
     AND payment_date BETWEEN :from AND :to
     GROUP BY DATE(payment_date)
     ORDER BY date DESC",
    ['from' => $dateFrom, 'to' => $dateTo]
);

// Get income by doctor
$incomeByDoctor = $db->fetchAll(
    "SELECT u.full_name as doctor_name, SUM(p.amount) as total, COUNT(p.id) as count
     FROM payments p
     INNER JOIN cases c ON p.case_id = c.id
     INNER JOIN patient_folders pf ON c.folder_id = pf.id
     INNER JOIN users u ON pf.doctor_id = u.id
     WHERE p.status = 'approved' AND p.payment_date BETWEEN :from AND :to
     GROUP BY pf.doctor_id
     ORDER BY total DESC",
    ['from' => $dateFrom, 'to' => $dateTo]
);

// Get treatment statistics
$treatmentStats = $db->fetchAll(
    "SELECT t.treatment_name, COUNT(ct.id) as count, SUM(ct.final_price) as revenue
     FROM case_treatments ct
     INNER JOIN treatments t ON ct.treatment_id = t.id
     WHERE ct.created_at BETWEEN :from AND :to
     GROUP BY ct.treatment_id
     ORDER BY revenue DESC
     LIMIT 10",
    ['from' => $dateFrom, 'to' => $dateTo]
);
?>

<!-- Date Filter -->
<div class="panel mb-3">
    <div class="panel-body">
        <form method="GET" class="d-flex gap-2 align-center flex-wrap">
            <div class="d-flex gap-1 align-center">
                <label class="form-label" style="margin: 0;">لە:</label>
                <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>" style="width: 150px;">
            </div>
            <div class="d-flex gap-1 align-center">
                <label class="form-label" style="margin: 0;">بۆ:</label>
                <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>" style="width: 150px;">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> فلتەرکردن</button>
            <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-secondary">ئەم مانگە</a>
            <a href="?date_from=<?= date('Y-01-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-secondary">ئەم ساڵە</a>
        </form>
    </div>
</div>

<!-- Overview Stats -->
<div class="stats-row mb-3">
    <div class="stat-panel">
        <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalIncome) ?></div>
            <div class="stat-label">کۆی داهات</div>
        </div>
    </div>
    <div class="stat-panel">
        <div class="stat-icon red"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalExpenses) ?></div>
            <div class="stat-label">کۆی خەرجی</div>
        </div>
    </div>
    <div class="stat-panel">
        <div class="stat-icon <?= $netProfit >= 0 ? 'blue' : 'red' ?>"><i class="fas fa-chart-line"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($netProfit) ?></div>
            <div class="stat-label">قازانجی ساف</div>
        </div>
    </div>
    <div class="stat-panel">
        <div class="stat-icon orange"><i class="fas fa-credit-card"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($installmentStats['overdue_amount']) ?></div>
            <div class="stat-label">قیستی دواکەوتوو</div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Daily Report -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-calendar-day"></i> ڕاپۆرتی ڕۆژانە</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($dailyReport)): ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar empty-state-icon"></i>
                <p class="empty-state-title">هیچ داتایەک نییە</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>بەروار</th>
                            <th>ژمارەی پارەدان</th>
                            <th>داهات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dailyReport as $row): ?>
                        <tr>
                            <td><?= $row['date'] ?></td>
                            <td><?= $row['count'] ?></td>
                            <td><strong><?= number_format($row['total']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700; background: var(--bg-main);">
                            <td>کۆ</td>
                            <td><?= array_sum(array_column($dailyReport, 'count')) ?></td>
                            <td><?= number_format(array_sum(array_column($dailyReport, 'total'))) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Income by Doctor -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-user-md"></i> داهات بەپێی دکتۆر</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($incomeByDoctor)): ?>
            <div class="empty-state">
                <i class="fas fa-users empty-state-icon"></i>
                <p class="empty-state-title">هیچ داتایەک نییە</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>دکتۆر</th>
                            <th>ژمارە</th>
                            <th>داهات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomeByDoctor as $row): ?>
                        <tr>
                            <td>د. <?= htmlspecialchars($row['doctor_name']) ?></td>
                            <td><?= $row['count'] ?></td>
                            <td><strong><?= number_format($row['total']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Treatments -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-tooth"></i> چارەسەرە سەرەکییەکان</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($treatmentStats)): ?>
            <div class="empty-state">
                <i class="fas fa-syringe empty-state-icon"></i>
                <p class="empty-state-title">هیچ داتایەک نییە</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>چارەسەر</th>
                            <th>ژمارە</th>
                            <th>داهات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($treatmentStats as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['treatment_name']) ?></td>
                            <td><?= $row['count'] ?></td>
                            <td><strong><?= number_format($row['revenue']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Methods -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-credit-card"></i> شێوازەکانی پارەدان</h3>
        </div>
        <div class="panel-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <?php
                $byType = $paymentStats['by_type'] ?? [];
                $typeLabels = ['cash' => 'نەقد', 'credit' => 'قەرز', 'installment' => 'قیست'];
                foreach ($byType as $type => $data):
                ?>
                <div style="background: var(--fill-subtle); padding: 16px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 24px; font-weight: 700; color: var(--win-accent);">
                        <?= number_format($data['total']) ?>
                    </div>
                    <div class="text-secondary"><?= $typeLabels[$type] ?? $type ?></div>
                    <small class="text-secondary"><?= $data['count'] ?> پارەدان</small>
                </div>
                <?php endforeach; ?>

                <?php if (empty($byType)): ?>
                <div class="text-center text-secondary" style="grid-column: 1/-1;">
                    هیچ داتایەک نییە
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Installment Summary -->
<div class="panel mt-3">
    <div class="panel-header">
        <h3 class="panel-title"><i class="fas fa-credit-card"></i> پوختەی قیستەکان</h3>
    </div>
    <div class="panel-body">
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px;">
            <div style="background: var(--fill-subtle); padding: 20px; border-radius: 12px; text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: var(--win-accent);">
                    <?= $installmentStats['active_plans'] ?>
                </div>
                <div class="text-secondary">پلانی کراوە</div>
            </div>
            <div style="background: var(--fill-subtle); padding: 20px; border-radius: 12px; text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: var(--status-warning);">
                    <?= number_format($installmentStats['total_remaining']) ?>
                </div>
                <div class="text-secondary">ماوەی گشتی</div>
            </div>
            <div style="background: var(--fill-subtle); padding: 20px; border-radius: 12px; text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: var(--status-error);">
                    <?= number_format($installmentStats['overdue_amount']) ?>
                </div>
                <div class="text-secondary">دواکەوتوو</div>
            </div>
            <div style="background: var(--fill-subtle); padding: 20px; border-radius: 12px; text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: var(--status-success);">
                    <?= number_format($installmentStats['due_this_month']) ?>
                </div>
                <div class="text-secondary">ئەم مانگە</div>
            </div>
        </div>
    </div>
</div>

<!-- Print Button -->
<div class="mt-3 text-center">
    <button class="btn btn-secondary" onclick="window.print()">
        <i class="fas fa-print"></i> چاپکردن
    </button>
    <button class="btn btn-primary" onclick="exportReport()">
        <i class="fas fa-file-excel"></i> ئیکسپۆرت
    </button>
</div>

<script>
function exportReport() {
    alert('تایبەتمەندی ئیکسپۆرت لە داهاتوودا زیاد دەکرێت');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
