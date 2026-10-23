<?php
require_once __DIR__ . '/../config/config.php';
/**
 * Accountant - Financial Summary
 * پوختەی دارایی
 */

$pageTitle = 'پوختەی دارایی';
$breadcrumb = [
    ['title' => 'داشبۆردی ژمێریار', 'url' => APP_URL . '/accountant/dashboard.php'],
    ['title' => 'پوختەی دارایی']
];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAccountant() && !$__gate->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$xrayManager = new XrayManager();

$period   = $_GET['period'] ?? 'month';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

// Strict Y-m-d validation — bad values fall back to the month preset instead of
// silently producing an all-zero page (and never reach HTML unescaped)
$validDate = function ($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
};

// Set date range based on period
if ($period === 'today') {
    $dateFrom = $dateTo = date('Y-m-d');
} elseif ($period === 'week') {
    // Iraqi work week starts on Saturday
    $dateFrom = date('N') == 6 ? date('Y-m-d') : date('Y-m-d', strtotime('last saturday'));
    $dateTo   = date('Y-m-d');
} elseif ($period === 'month') {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-d');
} elseif ($period === 'lastmonth') {
    $dateFrom = date('Y-m-01', strtotime('first day of last month'));
    $dateTo   = date('Y-m-t', strtotime('first day of last month'));
} elseif ($period === 'year') {
    $dateFrom = date('Y-01-01');
    $dateTo   = date('Y-m-d');
} elseif ($period === 'custom' && $validDate($dateFrom) && $validDate($dateTo)) {
    if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }
} else {
    $period   = 'month';
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-d');
}

// ── Income ────────────────────────────────────────────────────────
// Keyed to payment_date (same column Payment/admin reports use) with a
// half-open range so the payment_date index is usable; one pass instead of 4.
$incomeParams = ['df' => $dateFrom, 'dt' => $dateTo];
$incomeSql = "SELECT COALESCE(SUM(amount),0) AS total,
                     COALESCE(SUM(CASE WHEN payment_type='cash' THEN amount END),0) AS cash,
                     COALESCE(SUM(CASE WHEN payment_type='installment' THEN amount END),0) AS inst,
                     COALESCE(SUM(CASE WHEN payment_type='credit' THEN amount END),0) AS credit
              FROM payments
              WHERE status='approved' AND payment_date >= :df AND payment_date < DATE_ADD(:dt, INTERVAL 1 DAY)";
$incomeRow    = $db->fetch($incomeSql, $incomeParams) ?: [];
$cashIncome   = (float)($incomeRow['cash'] ?? 0);
$instIncome   = (float)($incomeRow['inst'] ?? 0);
$creditIncome = (float)($incomeRow['credit'] ?? 0);
// X-ray income is not in the `payments` table — add it to the total and show as its own type
$xrayIncome   = $xrayManager->getIncome($dateFrom, $dateTo);
$totalIncome  = (float)($incomeRow['total'] ?? 0) + $xrayIncome;

// Payments still awaiting admin approval in this range (reconciliation aid)
$pendingPayRow = $db->fetch("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS t FROM payments
                             WHERE status='pending' AND payment_date >= :df AND payment_date < DATE_ADD(:dt, INTERVAL 1 DAY)", $incomeParams) ?: ['c' => 0, 't' => 0];

// ── Expenses — sum ALL expense tables (legacy + general/lab/store), same
//    pattern as admin/reports.php, or profit is overstated ─────────────
function fsSumExpenses($db, $df, $dt) {
    $p = ['df' => $df, 'dt' => $dt];
    $total = (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE expense_date BETWEEN :df AND :dt", $p)['t'] ?? 0);
    foreach (['expenses_general', 'expenses_lab', 'expenses_store'] as $expTable) {
        if ($db->tableExists($expTable)) {
            $total += (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM {$expTable} WHERE expense_date BETWEEN :df AND :dt", $p)['t'] ?? 0);
        }
    }
    return $total;
}
$totalExpenses = fsSumExpenses($db, $dateFrom, $dateTo);
$netProfit     = $totalIncome - $totalExpenses;

// ── Previous period of the same length, for the comparison sub-lines ──
$spanDays = (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1;
$prevTo   = date('Y-m-d', strtotime($dateFrom . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($spanDays - 1) . ' days'));
$prevIncome   = (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM payments
                                    WHERE status='approved' AND payment_date >= :df AND payment_date < DATE_ADD(:dt, INTERVAL 1 DAY)",
                                    ['df' => $prevFrom, 'dt' => $prevTo])['t'] ?? 0)
              + $xrayManager->getIncome($prevFrom, $prevTo);
$prevExpenses = fsSumExpenses($db, $prevFrom, $prevTo);
$prevProfit   = $prevIncome - $prevExpenses;

// Delta vs previous period: [text, color]. $goodWhenUp: is an increase good news?
function fsDelta($current, $previous, $goodWhenUp = true) {
    if ($previous == 0) return null;
    $pct = round((($current - $previous) / abs($previous)) * 100);
    if ($pct == 0) return ['وەک ماوەی پێشوو', '#999'];
    $up    = $pct > 0;
    $good  = $up === $goodWhenUp;
    $arrow = $up ? '▲' : '▼';
    return [$arrow . ' ' . abs($pct) . '% بەراورد بە ماوەی پێشوو', $good ? '#107c10' : '#c50f1f'];
}

// ── Debts (case debts + X-ray debts, which live outside `cases`) ───
$totalDebts    = ($db->fetch("SELECT COALESCE(SUM(total_amount - paid_amount - discount_amount),0) as t FROM cases WHERE (total_amount - paid_amount - discount_amount) > 0 AND status != 'closed'")['t'] ?? 0)
               + $xrayManager->getOutstandingDebt();

// ── Doctor Revenue (case payments only — X-ray income has no doctor) ──
$doctorRevenue = $db->fetchAll("
    SELECT u.full_name, COALESCE(SUM(py.amount),0) as total, COUNT(py.id) as cnt
    FROM payments py
    JOIN cases c ON py.case_id = c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN users u ON pf.doctor_id = u.id
    WHERE py.status = 'approved' AND py.payment_date >= :df AND py.payment_date < DATE_ADD(:dt, INTERVAL 1 DAY)
    GROUP BY u.id, u.full_name
    ORDER BY total DESC
", $incomeParams);

// ── Monthly Income Chart Data (last 12 whole months, incl. X-ray so the
//    chart agrees with the cards) ─────────────────────────────────────
$trendStart  = date('Y-m-01', strtotime('-11 months'));
$monthlyRows = $db->fetchAll("
    SELECT DATE_FORMAT(payment_date,'%Y-%m') as month, COALESCE(SUM(amount),0) as total
    FROM payments
    WHERE status='approved' AND payment_date >= :start
    GROUP BY month ORDER BY month ASC
", ['start' => $trendStart]);
$monthlyMap = [];
foreach ($monthlyRows as $mr) { $monthlyMap[$mr['month']] = (float)$mr['total']; }
foreach ($xrayManager->getMonthlyIncome($trendStart) as $m => $t) {
    $monthlyMap[$m] = ($monthlyMap[$m] ?? 0) + $t;
}
ksort($monthlyMap);
$monthlyData = [];
foreach ($monthlyMap as $m => $t) { $monthlyData[] = ['month' => $m, 'total' => $t]; }

// ── Expense by Category ───────────────────────────────────────────
$expenseByCategory = $db->fetchAll("
    SELECT ec.category_name, COALESCE(SUM(e.amount),0) as total
    FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN :df AND :dt
    GROUP BY ec.id, ec.category_name ORDER BY total DESC
", $incomeParams);

// ── Pending Reports Count ─────────────────────────────────────────
$pendingReports = $db->tableExists('accounting_reports') ? $db->count('accounting_reports', "status='pending'") : 0;
$overdueInst    = $db->fetch("SELECT COUNT(*) as cnt FROM installment_schedule WHERE status IN ('pending','partial','overdue') AND due_date < CURDATE()")['cnt'] ?? 0;
?>

<style>
.report-container { max-width:1400px; margin:0 auto; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.report-title { display:flex; align-items:center; gap:12px; }
.report-title h2 { margin:0; font-size:20px; color:#1a1a1a; }
.report-title .icon { width:45px; height:45px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; }
.tab-group { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
.tab-btn { padding:8px 18px; border-radius:20px; border:1.5px solid #e0e0e0; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; color:#666; background:white; transition:all 0.2s; display:inline-flex; align-items:center; }
.tab-btn.active { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border-color:#0078d4; }
.tab-btn:hover:not(.active) { border-color:#0078d4; color:#0078d4; }
.tab-btn-custom { padding:7px 14px; border-radius:20px; border:1.5px solid #e0e0e0; font-size:12px; font-weight:600; background:white; display:inline-flex; align-items:center; gap:6px; }
.tab-btn-custom.active { border-color:#0078d4; background:#f0f7ff; }
.tab-btn-custom input[type=date] { border:none; background:transparent; font-size:12px; color:#444; padding:0; outline:none; font-family:inherit; }
.tab-btn-custom button { background:none; border:none; cursor:pointer; color:#0078d4; font-size:12px; padding:0; }
.date-banner { display:flex; align-items:center; gap:8px; font-size:12px; color:#888; background:white; border:1.5px solid #e8e8e8; border-radius:8px; padding:8px 14px; margin-bottom:18px; width:fit-content; }
.summary-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
.summary-card { background:white; border-radius:12px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,0.06); text-align:center; border-top:3px solid transparent; }
.summary-card .card-label { font-size:12px; color:#888; margin-bottom:8px; }
.summary-card .card-value { font-size:28px; font-weight:700; line-height:1; }
.summary-card .card-sub { font-size:11px; color:#999; margin-top:6px; }
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.section-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; margin-bottom:16px; }
.section-header { padding:14px 18px; background:linear-gradient(135deg,#fafafa,#f5f5f5); border-bottom:1px solid #f0f0f0; font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px; }
.section-body { padding:18px; }
.bar-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; font-size:13px; }
.bar-label { width:80px; font-size:12px; color:#666; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bar-track { flex:1; background:#f0f0f0; border-radius:6px; height:10px; }
.bar-fill { height:10px; border-radius:6px; min-width:4px; transition:width 0.4s; }
.bar-value { width:110px; text-align:left; font-size:12px; font-weight:600; color:#333; }
.empty-state { text-align:center; color:#aaa; padding:24px 16px; font-size:13px; }
@media(max-width:768px) {
    .two-col, .summary-grid { grid-template-columns:1fr; }
}
</style>

<div class="report-container">

    <!-- Report Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon" style="background:linear-gradient(135deg,#8764b8,#6a3db8);">
                <i class="fas fa-chart-line"></i>
            </div>
            <h2>پوختەی دارایی</h2>
        </div>
    </div>

    <!-- Period Tabs -->
    <div class="tab-group">
        <a href="?period=today"  class="tab-btn <?= $period==='today'  ? 'active' : '' ?>">ئەمرۆ</a>
        <a href="?period=week"   class="tab-btn <?= $period==='week'   ? 'active' : '' ?>">ئەم هەفتەیە</a>
        <a href="?period=month"  class="tab-btn <?= $period==='month'  ? 'active' : '' ?>">ئەم مانگە</a>
        <a href="?period=lastmonth" class="tab-btn <?= $period==='lastmonth' ? 'active' : '' ?>">مانگی ڕابردوو</a>
        <a href="?period=year"   class="tab-btn <?= $period==='year'   ? 'active' : '' ?>">ئەمساڵ</a>
        <span class="tab-btn-custom <?= $period==='custom' ? 'active' : '' ?>">
            <form method="GET" style="display:flex;gap:6px;align-items:center;margin:0;">
                <input type="hidden" name="period" value="custom">
                <input type="date" name="date_from" value="<?= $period==='custom' ? htmlspecialchars($dateFrom) : '' ?>">
                <span style="color:#ccc;">—</span>
                <input type="date" name="date_to"   value="<?= $period==='custom' ? htmlspecialchars($dateTo)   : '' ?>">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
        </span>
    </div>

    <!-- Date Range Banner -->
    <div class="date-banner">
        <i class="fas fa-calendar-alt" style="color:#8764b8;"></i>
        <span><?= htmlspecialchars($dateFrom) ?></span>
        <span style="color:#ccc;">—</span>
        <span><?= htmlspecialchars($dateTo) ?></span>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card" style="border-top-color:#107c10;">
            <div class="card-label">کۆی داهات</div>
            <div class="card-value" style="color:#107c10;"><?= number_format($totalIncome) ?></div>
            <?php $d = fsDelta($totalIncome, $prevIncome, true); ?>
            <div class="card-sub"><?php if ($d): ?><span style="color:<?= $d[1] ?>;"><?= $d[0] ?></span><?php endif; ?></div>
        </div>
        <div class="summary-card" style="border-top-color:#c50f1f;">
            <div class="card-label">کۆی خەرجی</div>
            <div class="card-value" style="color:#c50f1f;"><?= number_format($totalExpenses) ?></div>
            <?php $d = fsDelta($totalExpenses, $prevExpenses, false); ?>
            <div class="card-sub"><?php if ($d): ?><span style="color:<?= $d[1] ?>;"><?= $d[0] ?></span><?php endif; ?></div>
        </div>
        <div class="summary-card" style="border-top-color:<?= $netProfit >= 0 ? '#107c10' : '#c50f1f' ?>; background:<?= $netProfit >= 0 ? '#f0faf0' : '#fff5f5' ?>;">
            <div class="card-label">قازانجی خالص</div>
            <div class="card-value" style="color:<?= $netProfit >= 0 ? '#107c10' : '#c50f1f' ?>;"><?= number_format($netProfit) ?></div>
            <?php $d = fsDelta($netProfit, $prevProfit, true); ?>
            <div class="card-sub"><?php if ($d): ?><span style="color:<?= $d[1] ?>;"><?= $d[0] ?></span><?php endif; ?></div>
        </div>
        <div class="summary-card" style="border-top-color:#8764b8;">
            <div class="card-label">کۆی قەرز ماوە</div>
            <div class="card-value" style="color:#8764b8;"><?= number_format($totalDebts) ?></div>
            <div class="card-sub"></div>
        </div>
        <div class="summary-card" style="border-top-color:#ff8c00;">
            <div class="card-label">ڕاپۆرتی چاوەڕوان</div>
            <div class="card-value" style="color:#ff8c00;"><?= number_format($pendingReports) ?></div>
            <div class="card-sub"><a href="<?= APP_URL ?>/accountant/pending-reports.php" style="font-size:11px;color:#0078d4;">بینین</a></div>
        </div>
        <div class="summary-card" style="border-top-color:#c50f1f;">
            <div class="card-label">قیستی دواکەوتوو</div>
            <div class="card-value" style="color:#c50f1f;"><?= number_format($overdueInst) ?></div>
            <div class="card-sub"><a href="<?= APP_URL ?>/accountant/installments.php?filter=overdue" style="font-size:11px;color:#0078d4;">بینین</a></div>
        </div>
    </div>

    <?php if (($pendingPayRow['c'] ?? 0) > 0): ?>
    <!-- Payments awaiting approval in this range — not yet inside the income card -->
    <div style="display:flex;align-items:center;gap:10px;background:#fff8e6;border:1.5px solid #ffd77a;border-radius:10px;padding:10px 16px;margin-bottom:18px;font-size:13px;color:#7a5b00;">
        <i class="fas fa-hourglass-half" style="color:#ff8c00;"></i>
        <span><b><?= number_format($pendingPayRow['c']) ?></b> پارەدان بە کۆی <b><?= number_format($pendingPayRow['t']) ?></b> لەم ماوەیەدا چاوەڕوانی پەسەندکردنن و لە کۆی داهات نەژمێردراون</span>
    </div>
    <?php endif; ?>

    <!-- Two-column section cards -->
    <div class="two-col">

        <!-- Income Breakdown -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-money-bill-wave" style="color:#107c10;"></i>
                داهات بە جۆر
            </div>
            <div class="section-body">
                <?php
                $incomeTypes = [
                    'cash'        => ['کاش',  $cashIncome,   '#107c10'],
                    'installment' => ['قیست', $instIncome,   '#0078d4'],
                    'credit'      => ['قەرز', $creditIncome, '#ff8c00'],
                    'xray'        => ['ئێکسڕەی', $xrayIncome, '#9c27b0'],
                ];
                foreach ($incomeTypes as $key => [$label, $amt, $color]):
                    $pct = $totalIncome > 0 ? round(($amt / $totalIncome) * 100) : 0;
                ?>
                <div class="bar-row">
                    <div class="bar-label"><?= $label ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                    </div>
                    <div class="bar-value"><?= number_format($amt) ?> <span style="color:#aaa;font-weight:400;">(<?= $pct ?>%)</span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Doctor Revenue -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-user-md" style="color:#0078d4;"></i>
                داهاتی دکتۆرەکان
                <span style="font-size:11px;font-weight:400;color:#999;margin-right:auto;">(بێجگە لە ئێکسڕەی)</span>
            </div>
            <div class="section-body">
                <?php if (empty($doctorRevenue)): ?>
                <div class="empty-state"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>داتا نییە</div>
                <?php else: ?>
                <?php
                $maxDr = max(array_column($doctorRevenue, 'total') ?: [1]);
                $sumDr = array_sum(array_column($doctorRevenue, 'total'));
                ?>
                <?php foreach ($doctorRevenue as $dr): ?>
                <?php
                $pct   = $maxDr > 0 ? round(($dr['total'] / $maxDr) * 100) : 0;
                $share = $sumDr > 0 ? round(($dr['total'] / $sumDr) * 100) : 0;
                ?>
                <div class="bar-row">
                    <div class="bar-label" style="width:90px;" title="<?= htmlspecialchars($dr['full_name']) ?>"><?= htmlspecialchars($dr['full_name']) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:#0078d4;"></div>
                    </div>
                    <div class="bar-value"><?= number_format($dr['total']) ?> <span style="color:#aaa;font-weight:400;">(<?= $share ?>%)</span></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expense by Category -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-receipt" style="color:#69797e;"></i>
                خەرجی بە جۆر
            </div>
            <div class="section-body">
                <?php if (empty($expenseByCategory)): ?>
                <div class="empty-state"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>خەرجی نییە</div>
                <?php else: ?>
                <?php $maxExp = max(array_column($expenseByCategory, 'total') ?: [1]); ?>
                <?php foreach ($expenseByCategory as $ec): ?>
                <?php $pct = $maxExp > 0 ? round(($ec['total'] / $maxExp) * 100) : 0; ?>
                <div class="bar-row">
                    <div class="bar-label" style="width:90px;" title="<?= htmlspecialchars($ec['category_name']) ?>"><?= htmlspecialchars($ec['category_name']) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:#c50f1f;"></div>
                    </div>
                    <div class="bar-value"><?= number_format($ec['total']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Monthly Income Trend -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-chart-line" style="color:#0078d4;"></i>
                داهاتی مانگانە (١٢ مانگی ڕابردوو)
            </div>
            <div class="section-body">
                <?php if (empty($monthlyData)): ?>
                <div class="empty-state"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>داتا نییە</div>
                <?php else: ?>
                <?php $maxM = max(array_column($monthlyData, 'total') ?: [1]); ?>
                <?php foreach ($monthlyData as $md): ?>
                <?php $pct = $maxM > 0 ? round(($md['total'] / $maxM) * 100) : 0; ?>
                <div class="bar-row">
                    <div class="bar-label" style="width:60px;font-size:11px;"><?= $md['month'] ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:linear-gradient(135deg,#107c10,#0e6b0e);"></div>
                    </div>
                    <div class="bar-value" style="font-size:11px;"><?= number_format($md['total']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.two-col -->

</div><!-- /.report-container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
