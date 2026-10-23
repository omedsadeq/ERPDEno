<?php
/**
 * داهات — the cashier's daily income sheet (تۆماری ڕۆژی), from the system.
 *
 * Replaces the paper sheet: one row per doctor, then the x-ray types, the
 * pharmacy and the examination fee; per row the money received, the lab and
 * store expenses and the net; at the bottom the total, the day's expenses and
 * what is left.
 *
 * Shared by accountant/income.php (screen) and income-pdf.php (mPDF), so the
 * printout is always exactly the screen. Include BEFORE any output.
 *
 * Every figure reuses an existing definition so this sheet can never disagree
 * with the other reports:
 *   doctor rows   ds_compute_range() — the daily summary's rules: money taken
 *                 in the chair on APPROVED invoices + approved payments +
 *                 installments, including custody applied as approved payments.
 *                 Unapplied counter receipts are excluded from income.
 *   x-ray rows    XrayManager::getIncome() split by type: requests paid in cash
 *                 (paid date) + later debt payments (xray_debt_payments)
 *   pharmacy      what the pharmacy (erp2) sold: confirmed sales invoices on
 *                 their sale date (cash or credit), minus confirmed returns
 *   examinations  the visit fee entered at reception (visits.price)
 *   + manual      both rows also add the amounts the accountant typed in by
 *                 hand for that period (classes/IncomeManualEntry.php)
 *   expenses      expenses_lab / _store / _general (+ the legacy table)
 *
 * Each row's کۆی گشتی is then split: پشکی دکتۆر = net × the doctor's
 * percentage (users.commission_rate, set on the doctor's account in
 * admin/users.php — the same rate doctors/general-report.php uses), and the
 * rest is پشکی سەنتەر. Rows with no doctor (x-ray, pharmacy, examinations)
 * belong to the centre whole.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../doctors/_daily_summary_lib.php';
require_once __DIR__ . '/../classes/IncomeManualEntry.php';

$auth = new Auth();
$auth->requireLogin();
if (!Permission::getInstance()->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$db = Database::getInstance();
$incViewer = $auth->getCurrentUser();

// ── Period: today by default, لە … بۆ like the other report pages ──
$today = date('Y-m-d');
[$from, $to] = ds_range_from_request($_GET, $today, $today);
[$prevFrom, $prevTo] = ds_shift_range($from, $to, -1);
[$nextFrom, $nextTo] = ds_shift_range($from, $to, +1);
$rangeLabel = ds_range_label($from, $to);
$rangePresets = ds_range_presets();
// ── Which expenses: تاقیگە / کۆگا / گشتی — any of the three ──
// Lab and store are table COLUMNS (per doctor) and are subtracted in each
// row's net. General has no column — it is mostly clinic-wide cost (rent,
// electricity) that belongs to no doctor row — so it only counts in the
// bottom خەرجیات. Only ticked kinds are subtracted anywhere. The choice comes
// from the form (xs=1 marks "a selection was sent", so ticking nothing is a
// real choice), else from the remembered cookie, else all three.
$expenseKinds = ['lab' => 'خەرجی تاقیگە', 'store' => 'خەرجی کۆگا', 'general' => 'خەرجی گشتی'];
if (isset($_GET['xs'])) {
    $expSel = array_values(array_intersect(array_keys($expenseKinds), (array)($_GET['x'] ?? [])));
    setcookie('inc_x', $expSel ? implode(',', $expSel) : 'none', [
        'expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax',
    ]);
} elseif (isset($_COOKIE['inc_x'])) {
    $expSel = $_COOKIE['inc_x'] === 'none'
        ? []
        : array_values(array_intersect(array_keys($expenseKinds), explode(',', (string)$_COOKIE['inc_x'])));
} else {
    $expSel = array_keys($expenseKinds);
}
$expQs = '&xs=1' . implode('', array_map(fn($k) => '&x[]=' . $k, $expSel));
// The expense columns actually drawn in the table: lab / store only.
$colSel = array_values(array_intersect($expSel, ['lab', 'store']));

$rangeQs = fn(string $f, string $t) => '?from=' . urlencode($f) . '&to=' . urlencode($t) . $expQs;

// ── Doctor rows ──────────────────────────────────────────────────────
// Every active doctor (an empty row says "nothing taken", as on paper), plus
// any inactive doctor who still had money come in. Ordered by their clinic
// record, which is the order the paper sheet lists them in.
$doctorRows = [];
foreach ($db->fetchAll(
    "SELECT u.id, u.full_name, u.is_active, u.commission_rate
     FROM users u
     LEFT JOIN doctors d ON d.user_id = u.id
     WHERE u.role = 'doctor'
     ORDER BY d.id IS NULL, d.id, u.full_name"
) as $u) {
    $uid = (int)$u['id'];
    $ds = ds_compute_range($db, $uid, $from, $to, false);
    $amount = $ds['treatmentCash'] + $ds['oldDebtCollected'];

    // ژمارە: how many cases paid — chair cash on an approved invoice, an
    // approved payment or an installment, including payments funded by custody.
    $count = (int)($db->fetch(
        "SELECT COUNT(DISTINCT k) AS n FROM (
            SELECT CONCAT('c', ci.case_id) AS k
            FROM case_invoices ci
            JOIN cases c ON c.id = ci.case_id
            JOIN patient_folders pf ON pf.id = c.folder_id
            WHERE ci.work_date BETWEEN :f1 AND :t1 AND ci.draft_received_amount > 0
              AND ci.accounting_status = 'approved'
              AND COALESCE(ci.doctor_id, pf.doctor_id) = :u1
            UNION
            SELECT CONCAT('c', p.case_id)
            FROM payments p
            JOIN cases c ON c.id = p.case_id
            JOIN patient_folders pf ON pf.id = c.folder_id
            WHERE p.status = 'approved' AND DATE(p.payment_date) BETWEEN :f2 AND :t2
              AND pf.doctor_id = :u2
            UNION
            SELECT CONCAT('c', pl.case_id)
            FROM installment_payments ip
            JOIN installment_plans pl ON pl.id = ip.plan_id
            JOIN cases c ON c.id = pl.case_id
            JOIN patient_folders pf ON pf.id = c.folder_id
            WHERE DATE(ip.paid_at) BETWEEN :f3 AND :t3 AND pf.doctor_id = :u3
              AND ip.payment_id IS NULL
         ) x",
        ['f1' => $from, 't1' => $to, 'u1' => $uid, 'f2' => $from, 't2' => $to, 'u2' => $uid,
         'f3' => $from, 't3' => $to, 'u3' => $uid]
    )['n'] ?? 0);

    if (!$u['is_active'] && $amount <= 0 && $ds['labExpense'] <= 0 && $ds['storeExpense'] <= 0) {
        continue;
    }
    $doctorRows[] = [
        'kind'   => 'doctor',
        'id'     => $uid,
        'label'  => $u['full_name'],
        'count'  => $count,
        'amount' => $amount,
        'lab'    => $ds['labExpense'],
        'store'  => $ds['storeExpense'],
        // ڕێژەی دکتۆر — null until it is set on the doctor's account.
        'rate'   => (float)$u['commission_rate'] > 0 ? min(100.0, (float)$u['commission_rate']) : null,
    ];
}

// ── X-ray rows, by type ──────────────────────────────────────────────
$xrayTypes = ['panoramic' => 'OPG', 'cbct' => 'CBCT', 'cephalometric' => 'Ceph', 'periapical' => 'P.A'];
$xray = array_fill_keys(array_keys($xrayTypes), ['count' => 0, 'amount' => 0.0]);
$hasDebtTables = $db->tableExists('patient_xray_debts');
// Cash-settled requests — XrayManager::getCashIncome(), grouped.
foreach ($db->fetchAll(
    "SELECT xr.xray_type, COUNT(*) AS n, COALESCE(SUM(xr.final_amount), 0) AS amt
     FROM xray_requests xr
     WHERE xr.payment_status = 'paid'
       " . ($hasDebtTables ? "AND NOT EXISTS (SELECT 1 FROM patient_xray_debts pxd WHERE pxd.request_id = xr.id)" : '') . "
       AND DATE(COALESCE(xr.paid_at, xr.created_at)) BETWEEN :f AND :t
     GROUP BY xr.xray_type",
    ['f' => $from, 't' => $to]
) as $r) {
    if (isset($xray[$r['xray_type']])) {
        $xray[$r['xray_type']]['count'] += (int)$r['n'];
        $xray[$r['xray_type']]['amount'] += (float)$r['amt'];
    }
}
// Debt collections — XrayManager::getDebtCollected(), grouped by the request's type.
if ($hasDebtTables && $db->tableExists('xray_debt_payments')) {
    foreach ($db->fetchAll(
        "SELECT xr.xray_type, COUNT(DISTINCT xr.id) AS n, COALESCE(SUM(xdp.amount), 0) AS amt
         FROM xray_debt_payments xdp
         JOIN patient_xray_debts pxd ON pxd.id = xdp.debt_id
         JOIN xray_requests xr ON xr.id = pxd.request_id
         WHERE DATE(xdp.created_at) BETWEEN :f AND :t
         GROUP BY xr.xray_type",
        ['f' => $from, 't' => $to]
    ) as $r) {
        if (isset($xray[$r['xray_type']])) {
            $xray[$r['xray_type']]['count'] += (int)$r['n'];
            $xray[$r['xray_type']]['amount'] += (float)$r['amt'];
        }
    }
}
$deptRows = [];
foreach ($xrayTypes as $type => $label) {
    $deptRows[] = ['kind' => 'xray', 'label' => $label, 'count' => $xray[$type]['count'],
                   'amount' => $xray[$type]['amount'], 'lab' => 0.0, 'store' => 0.0];
}

// ── Pharmacy: what the pharmacy (erp2) SOLD ──────────────────────────
// Every confirmed sale on its sale date, whether paid in cash or on credit —
// drafts are not sales yet and cancelled ones never happened. Medicine brought
// back is subtracted on the day it was returned. ژمارە = number of sales.
$pharmacy = ['count' => 0, 'amount' => 0.0];
if ($db->tableExists('erp2_sales_invoices')) {
    $p = $db->fetch(
        "SELECT COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS amt
         FROM erp2_sales_invoices
         WHERE status = 'confirmed' AND invoice_date BETWEEN :f AND :t",
        ['f' => $from, 't' => $to]
    );
    $pharmacy = ['count' => (int)($p['n'] ?? 0), 'amount' => (float)($p['amt'] ?? 0)];
    if ($db->tableExists('erp2_sales_returns')) {
        $pharmacy['amount'] -= (float)($db->fetch(
            "SELECT COALESCE(SUM(grand_total), 0) AS t
             FROM erp2_sales_returns
             WHERE status = 'confirmed' AND return_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        )['t'] ?? 0);
    }
}
// ── Examinations: the 5,000 visit fee ────────────────────────────────
$exam = $db->fetch(
    "SELECT COUNT(*) AS n, COALESCE(SUM(price), 0) AS amt
     FROM visits WHERE visit_date BETWEEN :f AND :t AND price > 0",
    ['f' => $from, 't' => $to]
);

// ── داهاتی دەستی: typed in by the accountant, ADDED to both rows ─────
// Money the system never saw (a sale not rung up, a fee not on a visit). Each
// row keeps its two parts (sys* / manual*) so the sheet can show both.
$manualEntries = new IncomeManualEntry();
$manualTotals = $manualEntries->totals($from, $to);
$manualList = $manualEntries->listRange($from, $to);
$canManual = $manualEntries->available() && Permission::getInstance()->can(IncomeManualEntry::PERMISSION);

foreach ([
    'pharmacy' => ['Pharmacy', $pharmacy['count'], $pharmacy['amount']],
    'exam'     => ['Examinations', (int)($exam['n'] ?? 0), (float)($exam['amt'] ?? 0)],
] as $kind => [$label, $sysCount, $sysAmount]) {
    $m = $manualTotals[$kind];
    $deptRows[] = ['kind' => $kind, 'label' => $label,
                   'count' => $sysCount + $m['qty'], 'amount' => $sysAmount + $m['amount'],
                   'lab' => 0.0, 'store' => 0.0,
                   'sysAmount' => $sysAmount, 'sysCount' => $sysCount,
                   'manualAmount' => $m['amount'], 'manualQty' => $m['qty'], 'manualEntries' => $m['entries']];
}

// ── Totals ───────────────────────────────────────────────────────────
// A row's net subtracts only the expense columns that are switched on; the
// net is then split between the doctor (his percentage) and the centre. A
// doctor's lab/store costs are therefore shared in the same proportion. A
// doctor with no percentage set yet gets nothing here — his whole net shows
// as the centre's, and the sheet names him so it can be set.
$rows = array_merge($doctorRows, $deptRows);
$doctorsWithoutRate = [];
foreach ($rows as &$r) {
    $r['net'] = $r['amount'];
    foreach ($colSel as $k) {
        $r['net'] -= $r[$k];
    }
    $r['rate'] = $r['rate'] ?? null;
    $r['doctorShare'] = $r['rate'] !== null ? round($r['net'] * $r['rate'] / 100) : 0.0;
    $r['centerShare'] = $r['net'] - $r['doctorShare'];
    if ($r['kind'] === 'doctor' && $r['rate'] === null && abs($r['net']) >= 0.5) {
        $doctorsWithoutRate[] = $r['label'];
    }
}
unset($r);

$totals = ['count' => 0, 'amount' => 0.0, 'lab' => 0.0, 'store' => 0.0, 'net' => 0.0,
           'doctorShare' => 0.0, 'centerShare' => 0.0];
foreach ($rows as $r) {
    foreach ($totals as $k => $_) {
        $totals[$k] += $r[$k];
    }
}

// The period's expenses by kind (the legacy `expenses` table counts as general).
$expenseParts = ['lab' => 0.0, 'store' => 0.0, 'general' => 0.0, 'other' => 0.0];
foreach (['lab' => 'expenses_lab', 'store' => 'expenses_store', 'general' => 'expenses_general'] as $k => $t) {
    if ($db->tableExists($t)) {
        $expenseParts[$k] = (float)($db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM `$t` WHERE expense_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        )['t'] ?? 0);
    }
}
if ($db->tableExists('expenses')) {
    $expenseParts['other'] = (float)($db->fetch(
        "SELECT COALESCE(SUM(amount), 0) AS t FROM expenses
         WHERE expense_date BETWEEN :f AND :t AND COALESCE(status, '') NOT IN ('rejected', 'cancelled')",
        ['f' => $from, 't' => $to]
    )['t'] ?? 0);
}
$expenseParts['general'] += $expenseParts['other'];
unset($expenseParts['other']);

// ── The bottom of the sheet, as on the paper ─────────────────────────
//   کۆی گشتی = the total of the کۆی گشتی column (income after lab/store)
//   خەرجیات  = the GENERAL expenses (the خەرجی گشتی page) — plus any
//              lab/store expense not tied to a doctor row: it has no row to be
//              subtracted in, so it is taken here rather than silently lost
//   ماوە     = کۆی گشتی − خەرجیات
// Lab/store with a doctor are already taken in that doctor's row, so they are
// not counted again here. Only switched-on kinds count anywhere.
$expenseLines = [];   // label => amount, shown under خەرجیات
if (in_array('general', $expSel, true)) {
    $expenseLines['گشتی'] = $expenseParts['general'];
}
foreach (['lab' => 'تاقیگەی بێ دکتۆر', 'store' => 'کۆگای بێ دکتۆر'] as $k => $label) {
    $unassigned = $expenseParts[$k] - $totals[$k];
    if (in_array($k, $colSel, true) && $unassigned > 0.5) {
        $expenseLines[$label] = $unassigned;
    }
}
$sheetTotal = $totals['net'];
$expensesAll = array_sum($expenseLines);
$remaining = $sheetTotal - $expensesAll;
// The centre carries the clinic-wide expenses, so what it really keeps is its
// share less خەرجیات (= ماوە − the doctors' shares).
$centerAfterExpenses = $totals['centerShare'] - $expensesAll;

$canDoctorDetail = Permission::getInstance()->can('page.doctor_report');
// The percentages live on the doctors' accounts, which only an admin edits.
$canSetRates = $auth->isAdmin();
