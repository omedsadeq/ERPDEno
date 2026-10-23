<?php
/**
 * Accountant Dashboard
 * داشبۆردی ژمێریار
 */

$pageTitle = 'داشبۆردی ژمێریار';
$breadcrumb = [['title' => 'داشبۆردی ژمێریار']];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAccountant() && !$__gate->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$permission = Permission::getInstance();
$xrayManager = new XrayManager();

// ── Stats ─────────────────────────────────────────────────────────
$pendingReports  = $db->count('accounting_reports', "status = 'pending'");

// Today's income (case payments + X-ray income, which lives outside `payments`).
// Keyed to payment_date — same column Payment/admin reports use — with a
// half-open range so idx_payments_date is usable.
$todayIncomeSql  = "SELECT COALESCE(SUM(amount),0) as total FROM payments
                    WHERE status = 'approved'
                      AND payment_date >= CURDATE() AND payment_date < CURDATE() + INTERVAL 1 DAY";
$todayIncome     = ($db->fetch($todayIncomeSql)['total'] ?? 0)
                 + $xrayManager->getIncome(date('Y-m-d'), date('Y-m-d'));

// Month income (case payments + X-ray income)
$monthIncomeSql  = "SELECT COALESCE(SUM(amount),0) as total FROM payments
                    WHERE status = 'approved'
                      AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                      AND payment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH";
$monthIncome     = ($db->fetch($monthIncomeSql)['total'] ?? 0)
                 + $xrayManager->getIncome(date('Y-m-01'), date('Y-m-t'));

// Total outstanding debts (case debts + X-ray debts, which live outside `cases`)
$debtSql         = "SELECT COALESCE(SUM(total_amount - paid_amount - discount_amount),0) as total FROM cases WHERE status != 'closed' AND (total_amount - paid_amount - discount_amount) > 0";
$totalDebts      = ($db->fetch($debtSql)['total'] ?? 0) + $xrayManager->getOutstandingDebt();

// Overdue installments ('overdue' included — Installment::updateOverdueStatus flips rows to it)
$overdueInstSql  = "SELECT COUNT(*) as cnt FROM installment_schedule WHERE status IN ('pending','partial','overdue') AND due_date < CURDATE()";
$overdueInst     = $db->fetch($overdueInstSql)['cnt'] ?? 0;

// Month expenses — sum ALL expense tables (legacy `expenses` plus the
// general/lab/store tables the newer admin expense pages write to),
// same pattern as admin/reports.php, or spending is understated.
$monthExpenses   = (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())")['total'] ?? 0);
foreach (['expenses_general', 'expenses_lab', 'expenses_store'] as $expTable) {
    if ($db->tableExists($expTable)) {
        $monthExpenses += (float)($db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM {$expTable} WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())")['total'] ?? 0);
    }
}

// Oldest 5 pending reports — oldest first, so aging reports surface instead of
// hiding beyond the LIMIT
$recentReports = $db->fetchAll("
    SELECT ar.*, c.case_number, c.total_amount, c.paid_amount, c.discount_amount,
           p.full_name AS patient_name, u.full_name AS doctor_name
    FROM accounting_reports ar
    JOIN cases c ON ar.case_id = c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN patients p ON pf.patient_id = p.id
    JOIN users u ON ar.sent_by = u.id
    WHERE ar.status = 'pending'
    ORDER BY ar.sent_at ASC
    LIMIT 5
");

// Last 5 payments collected today
$todayPayments = $db->fetchAll("
    SELECT py.*, c.case_number, p.full_name AS patient_name, u.full_name AS collected_by
    FROM payments py
    JOIN cases c ON py.case_id = c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN patients p ON pf.patient_id = p.id
    LEFT JOIN users u ON py.received_by = u.id
    WHERE py.status = 'approved'
      AND py.payment_date >= CURDATE() AND py.payment_date < CURDATE() + INTERVAL 1 DAY
    ORDER BY py.payment_date DESC
    LIMIT 5
");

// Relative age in Kurdish for the pending-reports table
function dashRelativeAge($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 3600)       return floor($diff / 60) . ' خولەک';
    if ($diff < 86400)      return floor($diff / 3600) . ' کاتژمێر';
    return floor($diff / 86400) . ' ڕۆژ';
}
?>

<style>
.report-container { max-width:1400px; margin:0 auto; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.report-title { display:flex; align-items:center; gap:12px; }
.report-title h2 { margin:0; font-size:20px; color:#1a1a1a; }
.report-title .icon { width:45px; height:45px; background:linear-gradient(135deg,#0078d4,#0063b1); border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; box-shadow:0 4px 12px rgba(0,120,212,0.3); }
.stats-row { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
.stat-card { background:white; border-radius:8px; padding:10px 16px; box-shadow:0 1px 4px rgba(0,0,0,0.06); display:flex; align-items:center; gap:10px; border-right:3px solid transparent; flex:1; min-width:160px; text-decoration:none; transition:all 0.15s; }
a.stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,0.12); }
.stat-card .stat-icon { width:38px; height:38px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.stat-card .stat-body .stat-value { font-size:18px; font-weight:700; color:#1a1a1a; line-height:1.1; }
.stat-card .stat-body .stat-label { font-size:11px; color:#888; margin-top:1px; }
.quick-links-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
.quick-link-btn { flex:1; min-width:130px; display:flex; flex-direction:column; align-items:center; gap:7px; padding:16px 12px; background:white; border:1.5px solid #e8e8e8; border-radius:12px; text-decoration:none; color:#333; font-size:13px; font-weight:600; transition:all 0.2s; box-shadow:0 1px 4px rgba(0,0,0,0.04); position:relative; }
.quick-link-btn i { font-size:22px; }
.quick-link-btn:hover { border-color:#0078d4; color:#0078d4; transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,120,212,0.15); }
.quick-link-btn .ql-badge { position:absolute; top:10px; left:10px; background:#e53935; color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; line-height:1.4; }
.tables-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
@media(max-width:900px){ .tables-grid { grid-template-columns:1fr; } .stats-row .stat-card { min-width:calc(50% - 8px); } }
.table-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; }
.table-header { padding:14px 18px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#fafafa,#f5f5f5); }
.table-header h3 { margin:0; font-size:14px; color:#333; display:flex; align-items:center; gap:8px; }
.table-header .see-all { font-size:12px; color:#0078d4; text-decoration:none; font-weight:600; }
.table-header .see-all:hover { text-decoration:underline; }
.report-table { width:100%; border-collapse:collapse; font-size:13px; }
.report-table th { background:#f8f9fa; padding:10px 14px; text-align:right; font-weight:600; color:#555; border-bottom:2px solid #e8e8e8; white-space:nowrap; font-size:12px; }
.report-table td { padding:10px 14px; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
.report-table tr:last-child td { border-bottom:none; }
.report-table tr:hover td { background:#f9fbff; }
.no-data { text-align:center; padding:40px 20px; color:#aaa; }
.no-data i { font-size:36px; margin-bottom:10px; display:block; color:#ddd; }
.no-data p { margin:0; font-size:13px; }
.btn-icon { width:30px; height:30px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:7px; border:none; cursor:pointer; transition:all 0.2s; text-decoration:none; font-size:13px; }
.btn-view { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; }
.btn-view:hover { transform:translateY(-2px) scale(1.05); box-shadow:0 4px 12px rgba(0,120,212,0.3); }
.amount-positive { color:#107c10; font-weight:700; }
.amount-negative { color:#c50f1f; font-weight:700; }
.type-badge { display:inline-block; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:600; }
</style>

<div class="report-container">

    <!-- Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon"><i class="fas fa-calculator"></i></div>
            <div>
                <h2>داشبۆردی ژمێریار</h2>
                <?php $kuDays = ['Saturday'=>'شەممە','Sunday'=>'یەکشەممە','Monday'=>'دووشەممە','Tuesday'=>'سێشەممە','Wednesday'=>'چوارشەممە','Thursday'=>'پێنجشەممە','Friday'=>'هەینی']; ?>
                <div style="font-size:12px;color:#888;margin-top:2px;"><?= $kuDays[date('l')] ?? '' ?> — <?= date('Y-m-d') ?></div>
            </div>
        </div>
    </div>

    <!-- Stats Row (each card links to its page) -->
    <div class="stats-row">
        <a href="<?= APP_URL ?>/accountant/pending-reports.php" class="stat-card" style="border-right-color:#ff8c00;">
            <div class="stat-icon" style="background:#fff4ce;color:#ff8c00;"><i class="fas fa-file-invoice"></i></div>
            <div class="stat-body">
                <div class="stat-value" style="color:#ff8c00;"><?= number_format($pendingReports) ?></div>
                <div class="stat-label">ڕاپۆرتی چاوەڕوان</div>
            </div>
        </a>
        <a href="<?= APP_URL ?>/accountant/payments.php" class="stat-card" style="border-right-color:#107c10;">
            <div class="stat-icon" style="background:#dff6dd;color:#107c10;"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-body">
                <div class="stat-value" style="color:#107c10;"><?= number_format($todayIncome) ?></div>
                <div class="stat-label">داهاتی ئەمرۆ</div>
            </div>
        </a>
        <a href="<?= APP_URL ?>/accountant/financial-summary.php" class="stat-card" style="border-right-color:#0078d4;">
            <div class="stat-icon" style="background:#deecf9;color:#0078d4;"><i class="fas fa-chart-line"></i></div>
            <div class="stat-body">
                <div class="stat-value" style="color:#0078d4;"><?= number_format($monthIncome) ?></div>
                <div class="stat-label">داهاتی مانگی ئێستا</div>
            </div>
        </a>
        <a href="<?= APP_URL ?>/accountant/debts.php" class="stat-card" style="border-right-color:#c50f1f;">
            <div class="stat-icon" style="background:#fde7e9;color:#c50f1f;"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="stat-body">
                <div class="stat-value" style="color:#c50f1f;"><?= number_format($totalDebts) ?></div>
                <div class="stat-label">کۆی قەرزەکان</div>
            </div>
        </a>
        <a href="<?= APP_URL ?>/accountant/installments.php" class="stat-card" style="border-right-color:#8764b8;">
            <div class="stat-icon" style="background:#f4f0fa;color:#8764b8;"><i class="fas fa-credit-card"></i></div>
            <div class="stat-body">
                <div class="stat-value" style="color:#8764b8;"><?= number_format($overdueInst) ?></div>
                <div class="stat-label">قیستی دواکەوتوو</div>
            </div>
        </a>
        <a href="<?= APP_URL ?>/accountant/expenses.php" class="stat-card" style="border-right-color:#69797e;">
            <div class="stat-icon" style="background:#f2f2f2;color:#69797e;"><i class="fas fa-receipt"></i></div>
            <div class="stat-body">
                <div class="stat-value" style="color:#69797e;"><?= number_format($monthExpenses) ?></div>
                <div class="stat-label">خەرجی مانگی ئێستا</div>
            </div>
        </a>
    </div>

    <!-- Quick Links -->
    <div class="quick-links-row">
        <a href="<?= APP_URL ?>/accountant/pending-reports.php" class="quick-link-btn">
            <i class="fas fa-file-invoice" style="color:#ff8c00;"></i>
            ڕاپۆرتی چاوەڕوان
            <?php if ($pendingReports > 0): ?>
            <span class="ql-badge"><?= $pendingReports ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/accountant/payments.php" class="quick-link-btn">
            <i class="fas fa-money-bill-wave" style="color:#107c10;"></i>
            وەرگرتنی پارە
        </a>
        <a href="<?= APP_URL ?>/accountant/financial-summary.php" class="quick-link-btn">
            <i class="fas fa-chart-pie" style="color:#0078d4;"></i>
            پوختەی دارایی
        </a>
        <a href="<?= APP_URL ?>/accountant/debt-collection.php" class="quick-link-btn">
            <i class="fas fa-phone-volume" style="color:#e74c3c;"></i>
            کۆکردنەوەی قەرز
        </a>
        <a href="<?= APP_URL ?>/accountant/installments.php" class="quick-link-btn">
            <i class="fas fa-credit-card" style="color:#8764b8;"></i>
            قیستەکان
        </a>
        <a href="<?= APP_URL ?>/accountant/expenses.php" class="quick-link-btn">
            <i class="fas fa-receipt" style="color:#69797e;"></i>
            خەرجییەکان
        </a>
    </div>

    <!-- Tables Grid -->
    <div class="tables-grid">

        <!-- Pending Reports Table -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-file-invoice" style="color:#ff8c00;"></i> کۆنترین ڕاپۆرتی چاوەڕوان</h3>
                <a href="<?= APP_URL ?>/accountant/pending-reports.php" class="see-all">هەموویان ببینە &lsaquo;</a>
            </div>
            <?php if (empty($recentReports)): ?>
            <div class="no-data">
                <i class="fas fa-check-circle" style="color:#107c10;"></i>
                <p>هیچ ڕاپۆرتێکی چاوەڕوان نییە</p>
            </div>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th style="text-align:center;">ماوە</th>
                        <th style="text-align:center;">چاوەڕوانە</th>
                        <th style="text-align:center;">کردار</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentReports as $rpt): ?>
                <?php $rem = $rpt['total_amount'] - $rpt['paid_amount'] - $rpt['discount_amount']; ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($rpt['patient_name']) ?></div>
                        <div style="font-size:11px;color:#999;"><?= htmlspecialchars($rpt['case_number'] ?? '') ?></div>
                    </td>
                    <td style="color:#666;font-size:12px;"><?= htmlspecialchars($rpt['doctor_name']) ?></td>
                    <td style="text-align:center;" class="<?= $rem > 0 ? 'amount-negative' : 'amount-positive' ?>"><?= number_format($rem) ?></td>
                    <td style="text-align:center;font-size:12px;color:#888;"><?= dashRelativeAge($rpt['sent_at']) ?></td>
                    <td style="text-align:center;">
                        <a href="<?= APP_URL ?>/accountant/report-detail.php?case_id=<?= $rpt['case_id'] ?>" class="btn-icon btn-view" title="بینین">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Today's Payments Table -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-money-bill-wave" style="color:#107c10;"></i> پارەدانەکانی ئەمرۆ</h3>
                <a href="<?= APP_URL ?>/accountant/payments.php" class="see-all">هەموویان ببینە &lsaquo;</a>
            </div>
            <?php if (empty($todayPayments)): ?>
            <div class="no-data">
                <i class="fas fa-inbox"></i>
                <p>هیچ پارەیەکی ئەمرۆ نییە</p>
            </div>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th style="text-align:center;">بڕ</th>
                        <th style="text-align:center;">جۆر</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($todayPayments as $py): ?>
                <?php
                $typeLabels = ['cash'=>'کاش','credit'=>'قەرز','installment'=>'قیست'];
                $typeColors = ['cash'=>'#dff6dd;color:#107c10','credit'=>'#fde7e9;color:#c50f1f','installment'=>'#f4f0fa;color:#8764b8'];
                $typeKey = $py['payment_type'];
                $typeStyle = $typeColors[$typeKey] ?? '#f2f2f2;color:#666';
                ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($py['patient_name']) ?></td>
                    <td style="text-align:center;" class="amount-positive"><?= number_format($py['amount']) ?></td>
                    <td style="text-align:center;">
                        <span class="type-badge" style="background:<?= $typeStyle ?>;">
                            <?= $typeLabels[$typeKey] ?? $typeKey ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /.tables-grid -->

</div><!-- /.report-container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
