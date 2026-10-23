<?php
/**
 * Doctor Profile Page
 * پرۆفایلی دکتۆر
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../includes/search_helpers.php';

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

// Get doctor identity (not date-scoped — this is "who", not "how much")
$doctor = $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => $doctorId]);

if (!$doctor || $doctor['role'] !== 'doctor') {
    header('Location: ' . APP_URL . '/doctors/index.php');
    exit;
}

$pageTitle = 'د. ' . $doctor['full_name'];
$currentPage = 'doctors';

// ── Date range ───────────────────────────────────────────────────────
// Every stat below is scoped to [dateFrom, dateTo] — this profile used to be
// an all-time dashboard with no way to ask "how did this doctor do THIS
// month". Mirrors doctors/daily-summary.php's money definitions (same
// tables: case_invoices, payments, installment_payments), just summed over
// a range instead of one day.
function profileValidDate($v)
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$allTimeFloor = '2000-01-01';

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
if (!profileValidDate($dateFrom)) {
    $dateFrom = $monthStart;
}
if (!profileValidDate($dateTo)) {
    $dateTo = $today;
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$rangeEnd = $dateTo . ' 23:59:59';

$presets = [
    'today' => ['label' => 'ئەمڕۆ', 'from' => $today, 'to' => $today],
    'week'  => ['label' => 'ئەم هەفتەیە', 'from' => $weekStart, 'to' => $today],
    'month' => ['label' => 'ئەم مانگە', 'from' => $monthStart, 'to' => $today],
    'all'   => ['label' => 'هەموو کات', 'from' => $allTimeFloor, 'to' => $today],
];
$activePreset = null;
foreach ($presets as $key => $p) {
    if ($p['from'] === $dateFrom && $p['to'] === $dateTo) {
        $activePreset = $key;
        break;
    }
}

// ── Folders / cases / patients created in range ─────────────────────
$folderStats = $db->fetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS archived_cnt,
            COUNT(DISTINCT patient_id) AS patients
     FROM patient_folders
     WHERE doctor_id = :uid AND created_at BETWEEN :from AND :to",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]
);
$folderStats = $folderStats ?: ['total' => 0, 'active_cnt' => 0, 'archived_cnt' => 0, 'patients' => 0];

$caseStats = $db->fetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN c.status = 'open' THEN 1 ELSE 0 END) AS open_cnt,
            SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed_cnt
     FROM cases c
     JOIN patient_folders pf ON pf.id = c.folder_id
     WHERE pf.doctor_id = :uid AND c.created_at BETWEEN :from AND :to",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]
);
$caseStats = $caseStats ?: ['total' => 0, 'open_cnt' => 0, 'closed_cnt' => 0];

// ── Money — same source tables/definitions as doctors/daily-summary.php ──
$invoiceRow = $db->fetch(
    "SELECT COALESCE(SUM(ci.total_amount), 0) AS gross,
            COALESCE(SUM(ci.discount_amount), 0) AS discount,
            COALESCE(SUM(ci.total_amount - ci.discount_amount), 0) AS net,
            COALESCE(SUM(ci.draft_received_amount), 0) AS chair_received
     FROM case_invoices ci
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     WHERE ci.work_date BETWEEN :from AND :to
       AND COALESCE(ci.doctor_id, pf.doctor_id) = :uid",
    ['from' => $dateFrom, 'to' => $dateTo, 'uid' => $doctorId]
);
$grossBilled   = (float)($invoiceRow['gross'] ?? 0);
$invoiceDisc   = (float)($invoiceRow['discount'] ?? 0);
$invoiceNet    = (float)($invoiceRow['net'] ?? 0);
$chairReceived = (float)($invoiceRow['chair_received'] ?? 0);

// Approved payments landing in the range, split: against a period invoice vs
// clearing an older balance (pre-existing debt collected during this range).
$payRows = $db->fetchAll(
    "SELECT p.id, p.amount,
            COALESCE(SUM(CASE WHEN ci.work_date BETWEEN :from2 AND :to2 THEN pa.allocated_amount ELSE 0 END), 0) AS period_part
     FROM payments p
     JOIN cases c ON c.id = p.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
     LEFT JOIN case_invoices ci ON ci.id = pa.invoice_id
     WHERE p.status = 'approved'
       AND DATE(p.payment_date) BETWEEN :from AND :to
       AND pf.doctor_id = :uid
     GROUP BY p.id, p.amount",
    ['from' => $dateFrom, 'to' => $dateTo, 'from2' => $dateFrom, 'to2' => $dateTo, 'uid' => $doctorId]
);
$paidPeriodInvoice = 0.0;
$paidOldBalance = 0.0;
foreach ($payRows as $r) {
    $amount = (float)$r['amount'];
    $period = (float)$r['period_part'];
    $paidPeriodInvoice += $period;
    $paidOldBalance += max(0, $amount - $period);
}

$instRow = $db->fetch(
    "SELECT COALESCE(SUM(ip.amount), 0) AS total
     FROM installment_payments ip
     JOIN installment_plans pl ON pl.id = ip.plan_id
     JOIN cases c ON c.id = pl.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     WHERE DATE(ip.paid_at) BETWEEN :from AND :to AND pf.doctor_id = :uid",
    ['from' => $dateFrom, 'to' => $dateTo, 'uid' => $doctorId]
);
$installmentPaid = (float)($instRow['total'] ?? 0);

// Old debt (or installment) collected during the range — kept separate from
// "بڕی وەرگیراو" below, which is only money against THIS period's own billed
// work.
$oldDebtCollected = $paidOldBalance + $installmentPaid;

// True current remaining balance of THIS period's billed work (uses
// all-time allocations against those invoices, not just same-period ones —
// this needs to still be correct next month, unlike a same-period-only
// figure would be).
$remainingRow = $db->fetch(
    "SELECT COALESCE(SUM(GREATEST(0, inv.net - COALESCE(alloc.paid, 0))), 0) AS remaining
     FROM (
        SELECT ci.id, (ci.total_amount - ci.discount_amount) AS net
        FROM case_invoices ci
        JOIN cases c ON c.id = ci.case_id
        JOIN patient_folders pf ON pf.id = c.folder_id
        WHERE ci.accounting_status = 'approved'
          AND COALESCE(ci.doctor_id, pf.doctor_id) = :uid
          AND ci.work_date BETWEEN :from AND :to
     ) inv
     LEFT JOIN (
        SELECT pa.invoice_id, SUM(pa.allocated_amount) AS paid
        FROM payment_allocations pa
        JOIN payments p ON p.id = pa.payment_id
        WHERE p.status = 'approved'
        GROUP BY pa.invoice_id
     ) alloc ON alloc.invoice_id = inv.id",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$remainingPeriod = (float)($remainingRow['remaining'] ?? 0);
$collectedAgainstPeriod = max(0, $invoiceNet - $remainingPeriod); // self-consistent with gross/discount/net above
$paymentPercentage = $invoiceNet > 0 ? round(($collectedAgainstPeriod / $invoiceNet) * 100) : 0;

$labExpenseRow = $db->fetch(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses_lab WHERE doctor_id = :uid AND expense_date BETWEEN :from AND :to",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$labExpense = (float)($labExpenseRow['total'] ?? 0);
$storeExpenseRow = $db->fetch(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses_store WHERE doctor_id = :uid AND expense_date BETWEEN :from AND :to",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$storeExpense = (float)($storeExpenseRow['total'] ?? 0);

// ── Debt patients: who still owes money from work billed in this range ──
$debtRows = $db->fetchAll(
    "SELECT p.id AS patient_id, p.full_name, pf.folder_name,
            SUM(GREATEST(0, (ci.total_amount - ci.discount_amount) - COALESCE(alloc.paid, 0))) AS debt_amount
     FROM case_invoices ci
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     JOIN patients p ON p.id = pf.patient_id
     LEFT JOIN (
        SELECT pa.invoice_id, SUM(pa.allocated_amount) AS paid
        FROM payment_allocations pa
        JOIN payments pay ON pay.id = pa.payment_id
        WHERE pay.status = 'approved'
        GROUP BY pa.invoice_id
     ) alloc ON alloc.invoice_id = ci.id
     WHERE pf.doctor_id = :uid AND ci.accounting_status = 'approved'
       AND ci.work_date BETWEEN :from AND :to
     GROUP BY p.id, p.full_name, pf.id, pf.folder_name
     HAVING debt_amount > 0.009
     ORDER BY debt_amount DESC",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$totalDebtPatients = count($debtRows);
$debtPatients = array_slice($debtRows, 0, 10);

// Folder types distribution, created in range
$folderTypesResult = $db->fetchAll("SELECT folder_type, COUNT(*) as count
    FROM patient_folders
    WHERE doctor_id = :doctor_id AND created_at BETWEEN :from AND :to
    GROUP BY folder_type
    ORDER BY count DESC", ['doctor_id' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]);
$folderTypes = is_array($folderTypesResult) ? $folderTypesResult : [];

$typeLabels = [
    'general' => 'گشتی',
    'cosmetic' => 'جوانکاری',
    'orthodontic' => 'ڕاستکردنەوە',
    'surgery' => 'نەشتەرگەری',
    'other' => 'هی تر'
];

// Get active folders list, created in range
$activeFoldersResult = $db->fetchAll("SELECT pf.*, p.full_name as patient_name, p.patient_code,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id AND c.status = 'open') as open_case_count
    FROM patient_folders pf
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND pf.status = 'active' AND pf.created_at BETWEEN :from AND :to
    ORDER BY pf.updated_at DESC
    LIMIT 10", ['doctor_id' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]);
$activeFolders = is_array($activeFoldersResult) ? $activeFoldersResult : [];

// Get archived folders list (for modal), created in range
$archivedFoldersResult = $db->fetchAll("SELECT pf.*, p.full_name as patient_name, p.patient_code,
    (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count
    FROM patient_folders pf
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND pf.status = 'archived' AND pf.created_at BETWEEN :from AND :to
    ORDER BY pf.updated_at DESC
    LIMIT 50", ['doctor_id' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]);
$archivedFolders = is_array($archivedFoldersResult) ? $archivedFoldersResult : [];

// Get open cases list, created in range
$openCasesResult = $db->fetchAll("SELECT c.*, pf.folder_name, p.full_name as patient_name, p.patient_code
    FROM cases c
    INNER JOIN patient_folders pf ON c.folder_id = pf.id
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND c.status = 'open' AND c.created_at BETWEEN :from AND :to
    ORDER BY c.updated_at DESC
    LIMIT 10", ['doctor_id' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]);
$openCases = is_array($openCasesResult) ? $openCasesResult : [];

// Get closed cases list (for modal), created in range
$closedCasesResult = $db->fetchAll("SELECT c.*, pf.folder_name, p.full_name as patient_name, p.patient_code
    FROM cases c
    INNER JOIN patient_folders pf ON c.folder_id = pf.id
    LEFT JOIN patients p ON pf.patient_id = p.id
    WHERE pf.doctor_id = :doctor_id AND c.status = 'closed' AND c.created_at BETWEEN :from AND :to
    ORDER BY c.updated_at DESC
    LIMIT 50", ['doctor_id' => $doctorId, 'from' => $dateFrom, 'to' => $rangeEnd]);
$closedCases = is_array($closedCasesResult) ? $closedCasesResult : [];

// ── پوختەی دکتۆر — same money/visit/attendance model as daily-summary.php,
// summed over [dateFrom, dateTo] instead of one day. ────────────────────

// visits.* points at doctors.id, not users.id — bridge here (see the same
// note in doctors/daily-summary.php).
$bridge = $db->fetch("SELECT id FROM doctors WHERE user_id = :uid LIMIT 1", ['uid' => $doctorId]);
$docRowId = (int)($bridge['id'] ?? 0);
$visitsLinkBroken = ($docRowId <= 0);

$hasVisitDoctorCols = false;
try {
    $hasVisitDoctorCols = (bool)$db->fetch("SHOW COLUMNS FROM visits LIKE 'checkup_doctor_id'");
} catch (Exception $e) {
}

// جۆری ئیشەکان — same case_treatments rows used for the top-treatments idea,
// just the top 3 by count (not a fixed 3-keyword guess: that showed 0/0/0 on
// most real days because this clinic's actual mix is dominated by other
// treatment names).
$topTreatmentsRange = $db->fetchAll(
    "SELECT ct.treatment_name, COUNT(*) AS cnt, COALESCE(SUM(ct.final_price), 0) AS amt
     FROM case_treatments ct
     JOIN cases c ON c.id = ct.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     WHERE pf.doctor_id = :uid
       AND DATE(COALESCE(ct.performed_date, ct.created_at)) BETWEEN :from AND :to
       AND (ct.status IS NULL OR ct.status <> 'cancelled')
     GROUP BY ct.treatment_name
     ORDER BY cnt DESC, amt DESC",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$topWorkTypes = array_slice($topTreatmentsRange, 0, 3);

// کۆی گشتی سەردانیکەران / ئیش / پشکنین
$visitWork = ['total' => 0, 'treatment_visits' => 0, 'checkup_visits' => 0];
// پشکنینی داواکراو/پێدراو (the `treated` flag) و نوێ/کۆن (patient_status)
$checkupBreakdown = ['given' => 0, 'pending' => 0, 'new_exam' => 0, 'old_exam' => 0];
if (!$visitsLinkBroken) {
    $vp = ['from' => $dateFrom, 'to' => $dateTo];
    $vCond = visits_doctor_condition($docRowId, '', $vp, 'own', $hasVisitDoctorCols);
    $row = $db->fetch(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN v.visit_type = 'treatment' THEN 1 ELSE 0 END) AS treatment_visits,
                SUM(CASE WHEN v.visit_type = 'treatment' THEN 0 ELSE 1 END) AS checkup_visits
         FROM visits v
         WHERE v.visit_date BETWEEN :from AND :to AND $vCond",
        $vp
    );
    foreach ($visitWork as $k => $_) {
        $visitWork[$k] = (int)($row[$k] ?? 0);
    }

    $ckp = ['from' => $dateFrom, 'to' => $dateTo];
    $ckCond = visits_doctor_condition($docRowId, '', $ckp, 'chk', $hasVisitDoctorCols);
    $row2 = $db->fetch(
        "SELECT
            SUM(CASE WHEN v.treated = 'yes' THEN 1 ELSE 0 END) AS given,
            SUM(CASE WHEN v.treated = 'yes' THEN 0 ELSE 1 END) AS pending,
            SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END) AS new_exam,
            SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END) AS old_exam
         FROM visits v
         WHERE v.visit_date BETWEEN :from AND :to
           AND (v.visit_type <> 'treatment' OR v.visit_type IS NULL)
           AND $ckCond",
        $ckp
    );
    foreach ($checkupBreakdown as $k => $_) {
        $checkupBreakdown[$k] = (int)($row2[$k] ?? 0);
    }
}

// کاتژمێری هاتن / ڕۆژی هاتن — real doctor_attendance rows across the range.
$attendedRow = $db->fetch(
    "SELECT COUNT(*) AS days, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)), 0) AS minutes
     FROM doctor_attendance
     WHERE doctor_id = :uid AND attendance_date BETWEEN :from AND :to
       AND check_in_time IS NOT NULL AND check_out_time IS NOT NULL",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$attendedDays = (int)($attendedRow['days'] ?? 0);
$attendanceMinutes = (int)($attendedRow['minutes'] ?? 0);

// کۆی کاتی ئیشکردن / پشکنین — the clinic's own rule: a patient's time only
// counts as the doctor's working time once they are CALLED IN for treatment,
// not from when they merely sat down in the waiting room.
$busyRow = $db->fetch(
    "SELECT
        COALESCE(SUM(CASE WHEN wr.visit_type = 'treatment' THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END), 0) AS work_minutes,
        COALESCE(SUM(CASE WHEN wr.visit_type <> 'treatment' THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END), 0) AS exam_minutes
     FROM waiting_room wr
     WHERE wr.doctor_id = :uid
       AND DATE(wr.check_in_time) BETWEEN :from AND :to
       AND wr.called_time IS NOT NULL
       AND wr.check_out_time IS NOT NULL
       AND wr.check_out_time >= wr.called_time",
    ['uid' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$workMinutes = max(0, (int)($busyRow['work_minutes'] ?? 0));
$examMinutes = max(0, (int)($busyRow['exam_minutes'] ?? 0));

// کۆی کاتی بەتال — per attended day: that day's clocked span minus that
// day's called-in busy time (days never clocked in contribute no idle time,
// since there is no presence window to measure against).
$idleRows = $db->fetchAll(
    "SELECT da.attendance_date,
            TIMESTAMPDIFF(MINUTE, da.check_in_time, da.check_out_time) AS att_minutes,
            COALESCE(wrday.day_busy, 0) AS day_busy
     FROM doctor_attendance da
     LEFT JOIN (
        SELECT DATE(wr.check_in_time) AS d,
               SUM(CASE WHEN wr.called_time IS NOT NULL AND wr.check_out_time IS NOT NULL
                             AND wr.check_out_time >= wr.called_time
                        THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END) AS day_busy
        FROM waiting_room wr
        WHERE wr.doctor_id = :uid2
        GROUP BY DATE(wr.check_in_time)
     ) wrday ON wrday.d = da.attendance_date
     WHERE da.doctor_id = :uid AND da.attendance_date BETWEEN :from AND :to
       AND da.check_in_time IS NOT NULL AND da.check_out_time IS NOT NULL",
    ['uid' => $doctorId, 'uid2' => $doctorId, 'from' => $dateFrom, 'to' => $dateTo]
);
$idleMinutes = 0;
foreach ($idleRows as $ir) {
    $idleMinutes += max(0, (int)$ir['att_minutes'] - (int)$ir['day_busy']);
}

// بڕی وەرگیراو / ماوەی دوای خەرجی — kept self-consistent with گشتی/داشکاندن/
// ماوە above: $collectedAgainstPeriod = invoiceNet - remainingPeriod, so
// گشتی - داشکاندن - وەرگیراو = ماوە exactly. Old debt collected during the
// range is tracked separately as $oldDebtCollected (same "two cash totals on
// purpose" pattern as doctors/daily-summary.php), not folded in here.
$netAfterExpenses = $collectedAgainstPeriod - $labExpense - $storeExpense;

function profileHours($minutes)
{
    $minutes = (int)round($minutes);
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

require_once __DIR__ . '/../includes/header.php';

function profileEsc($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
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

/* Date range filter */
.profile-filter {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    padding: 12px 16px;
    margin-bottom: 12px;
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 10px;
}

.profile-filter label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 11px;
    color: #666;
}

.profile-filter input {
    height: 34px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fff;
    padding: 4px 10px;
    font: inherit;
    font-size: 12px;
    color: #333;
}

.profile-filter button {
    height: 34px;
    border: none;
    border-radius: 8px;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: #fff;
    padding: 0 18px;
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.preset-links {
    display: flex;
    gap: 4px;
    margin-inline-start: auto;
    flex-wrap: wrap;
}

.preset-links a {
    border: 1px solid #e0d0e6;
    background: #faf5fb;
    color: #7b1fa2;
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
}

.preset-links a.active {
    background: #9c27b0;
    border-color: #9c27b0;
    color: #fff;
}

.profile-range-note {
    font-size: 11px;
    color: #888;
    margin: -4px 0 16px;
}

@media (max-width: 768px) {
    .profile-filter { flex-direction: column; align-items: stretch; }
    .preset-links { margin-inline-start: 0; width: 100%; }
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

/* پوختەی دکتۆر */
.doctor-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.ds-subtitle {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 700;
    color: #7b1fa2;
}

.doctor-summary .mini-table td {
    padding: 7px 4px;
}

.doctor-summary .mini-table .num {
    text-align: left;
    direction: ltr;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.doctor-summary .mini-table .pos { color: #2e7d32; }
.doctor-summary .mini-table .neg { color: #c62828; }

.doctor-summary .mini-table tr.ds-total td {
    border-top: 2px solid #e0e0e0;
    font-weight: 700;
    color: #1a1a1a;
}

.ds-note {
    font-size: 11px;
    color: #999;
    line-height: 1.8;
    margin: 10px 0 0;
}

@media (max-width: 1024px) {
    .doctor-summary-grid { grid-template-columns: 1fr; }
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
            <?php if ($permission->can('page.cashboxes') && ($isAdmin || ($auth->isDoctor() && $doctorId === (int)$currentUser['id']))): ?>
            <a class="btn-back" href="<?= APP_URL ?><?= $isAdmin ? '/admin/cashboxes.php?box=doctor:' . $doctorId : '/doctors/cashbox.php' ?>"><i class="fas fa-vault"></i> سندوق</a>
            <?php endif; ?>
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

    <!-- Date range filter -->
    <form method="get" class="profile-filter no-print">
        <input type="hidden" name="id" value="<?= (int)$doctor['id'] ?>">
        <label>
            لە بەرواری
            <input type="date" name="date_from" value="<?= profileEsc($dateFrom) ?>">
        </label>
        <label>
            بۆ بەرواری
            <input type="date" name="date_to" value="<?= profileEsc($dateTo) ?>">
        </label>
        <button type="submit">پیشاندان</button>
        <div class="preset-links">
            <?php foreach ($presets as $key => $p): ?>
            <a href="?id=<?= (int)$doctor['id'] ?>&date_from=<?= profileEsc($p['from']) ?>&date_to=<?= profileEsc($p['to']) ?>"
               class="<?= $activePreset === $key ? 'active' : '' ?>"><?= $p['label'] ?></a>
            <?php endforeach; ?>
        </div>
    </form>
    <div class="profile-range-note no-print">
        دراوی خوارەوە هەمووی سنووردارکراوە بە ئەم ماوەیە: <?= profileEsc($dateFrom) ?> → <?= profileEsc($dateTo) ?>
    </div>

    <!-- پوختەی دکتۆر -->
    <div class="panel doctor-summary">
        <div class="panel-header">
            <h3 class="panel-title">
                <i class="fas fa-user-md"></i>
                پوختەی دکتۆر
            </h3>
            <span class="panel-link" style="pointer-events:none;color:#999"><?= profileEsc($dateFrom) ?> → <?= profileEsc($dateTo) ?></span>
        </div>
        <div class="panel-body">
            <div class="doctor-summary-grid">

                <!-- پارە -->
                <div>
                    <h4 class="ds-subtitle">پارە</h4>
                    <table class="mini-table">
                        <tbody>
                            <tr><td>کۆی گشتی پارە</td><td class="num"><?= number_format($grossBilled) ?></td></tr>
                            <tr><td>بڕی داشکێنراو</td><td class="num neg">(<?= number_format($invoiceDisc) ?>)</td></tr>
                            <tr class="ds-total"><td>بڕی ماوەی دوای داشکێنراو</td><td class="num"><?= number_format($invoiceNet) ?></td></tr>
                            <tr><td>بڕی وەرگیراو</td><td class="num pos"><?= number_format($collectedAgainstPeriod) ?></td></tr>
                            <tr><td>بڕی ماوە</td><td class="num neg"><?= number_format($remainingPeriod) ?></td></tr>
                            <tr><td>خەرجی تاقیگە</td><td class="num neg">(<?= number_format($labExpense) ?>)</td></tr>
                            <tr><td>خەرجی کۆگا</td><td class="num neg">(<?= number_format($storeExpense) ?>)</td></tr>
                            <tr class="ds-total">
                                <td>ماوەی دوای خەرجی</td>
                                <td class="num <?= $netAfterExpenses < 0 ? 'neg' : 'pos' ?>">
                                    <?= $netAfterExpenses < 0 ? '(' . number_format(abs($netAfterExpenses)) . ')' : number_format($netAfterExpenses) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="progress-section">
                        <div class="progress-header">
                            <span class="progress-label">پێشکەوتنی پارەدان</span>
                            <span class="progress-value"><?= $paymentPercentage ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $paymentPercentage ?>%;"></div>
                        </div>
                    </div>
                    <p class="ds-note">
                        پارەی گەراوەی قەرزی کۆن لەم ماوەیەدا: <strong><?= number_format($oldDebtCollected) ?></strong> —
                        قەرز یان قیستی کۆن کە لەم ماوەیەدا گەڕاوەتەوە، جیایە لە «بڕی وەرگیراو» سەرەوە (ئەوە تەنها بۆ ئیشی ئەم ماوەیەیە،
                        قەرزی کۆنی گەڕاوە لەخۆناگرێت).
                    </p>
                </div>

                <!-- سەردان و پشکنین -->
                <div>
                    <h4 class="ds-subtitle">سەردان و پشکنین</h4>
                    <table class="mini-table">
                        <tbody>
                            <tr><td>کۆی گشتی سەردانیکەران</td><td class="num"><?= number_format($visitWork['total']) ?></td></tr>
                            <tr><td>کۆی گشتی ئیش</td><td class="num"><?= number_format($visitWork['treatment_visits']) ?></td></tr>
                            <tr><td>کۆی گشتی پشکنین</td><td class="num"><?= number_format($visitWork['checkup_visits']) ?></td></tr>
                            <tr><td>کۆی گشتی پشکنینی داواکراو</td><td class="num neg"><?= number_format($checkupBreakdown['pending']) ?></td></tr>
                            <tr><td>کۆی گشتی پشکنینی پێدراو</td><td class="num pos"><?= number_format($checkupBreakdown['given']) ?></td></tr>
                            <tr><td>پشکنینی نوێ</td><td class="num"><?= number_format($checkupBreakdown['new_exam']) ?></td></tr>
                            <tr><td>پشکنینی کۆن</td><td class="num"><?= number_format($checkupBreakdown['old_exam']) ?></td></tr>
                        </tbody>
                    </table>
                    <?php if ($visitsLinkBroken): ?>
                    <p class="ds-note">ئەم هەژمارە بە تۆماری کلینیکی (doctors) نەبەستراوەتەوە، بۆیە ئەم بەشە بە سفر پیشان دەدرێت.</p>
                    <?php endif; ?>

                    <h4 class="ds-subtitle" style="margin-top:16px">جۆری ئیشەکان</h4>
                    <table class="mini-table">
                        <tbody>
                            <?php if (empty($topWorkTypes)): ?>
                            <tr><td colspan="3" style="text-align:center;color:#999">هیچ چارەسەرێک لەم ماوەیەدا تۆمار نەکراوە</td></tr>
                            <?php else: foreach ($topWorkTypes as $t): ?>
                            <tr>
                                <td><?= profileEsc($t['treatment_name']) ?></td>
                                <td class="num"><?= number_format($t['cnt']) ?></td>
                                <td class="num"><?= number_format($t['amt']) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- کات -->
                <div>
                    <h4 class="ds-subtitle">کاتژمێری هاتن</h4>
                    <table class="mini-table">
                        <tbody>
                            <tr><td>کاتژمێری هاتن</td><td class="num"><?= profileHours($attendanceMinutes) ?></td></tr>
                            <tr><td>ڕۆژی هاتن</td><td class="num"><?= number_format($attendedDays) ?></td></tr>
                            <tr><td>کۆی کاتی ئیشکردن</td><td class="num"><?= profileHours($workMinutes) ?></td></tr>
                            <tr><td>کۆی کاتی پشکنین</td><td class="num"><?= profileHours($examMinutes) ?></td></tr>
                            <tr><td>کۆی کاتی بەتال</td><td class="num"><?= profileHours($idleMinutes) ?></td></tr>
                        </tbody>
                    </table>
                    <p class="ds-note">
                        کاتژمێری هاتن و ڕۆژی هاتن لەسەر بنەمای دوگمەی چوونە ژوورەوە/دەرەوەی
                        <a href="<?= APP_URL ?>/doctors/daily-summary.php?doctor=<?= (int)$doctor['id'] ?>">پوختەی ڕۆژانە</a>
                        وەردەگیرێت. کاتی ئیشکردن و پشکنین لە کاتی «بانگکردن» بۆ چارەسەر تاوەکو تەواوبوون دەژمێردرێت،
                        نەک لە کاتی دانیشتنی نەخۆش لە ژووری چاوەڕوانیدا — چونکە چاوەڕوانی خۆی کاری دکتۆر نییە.
                        کاتی بەتال ئەوەیە کە لە کاتژمێری هاتندا مایەوە و بۆ هیچ نەخۆشێک نەخەرجرا.
                    </p>
                </div>

            </div>
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
                        <span class="panel-badge" style="background: #4caf50;"><?= (int)$folderStats['active_cnt'] ?></span>
                    </h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if ($folderStats['archived_cnt'] > 0): ?>
                        <button class="btn-show-closed" onclick="openModal('archivedFoldersModal')">
                            <i class="fas fa-archive"></i>
                            داخراو
                            <span class="count"><?= (int)$folderStats['archived_cnt'] ?></span>
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
                        <span class="panel-badge" style="background: #ff9800;"><?= (int)$caseStats['open_cnt'] ?></span>
                    </h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if ($caseStats['closed_cnt'] > 0): ?>
                        <button class="btn-show-closed" onclick="openModal('closedCasesModal')">
                            <i class="fas fa-check-circle"></i>
                            داخراو
                            <span class="count"><?= (int)$caseStats['closed_cnt'] ?></span>
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
