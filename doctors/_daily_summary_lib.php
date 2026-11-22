<?php
/**
 * One doctor's work and money over a period — the single source of truth.
 *
 * Used by:
 *   doctors/daily-summary.php (+ -pdf)  one doctor, one day, in detail
 *   doctors/index.php                   every doctor's card, for a month
 * Both call ds_compute_range() (a day is just a one-day range), so a doctor's
 * month card is always the sum of the same rules as their daily summary.
 *
 * Money definitions are deliberately the accountant report's
 * (accountant/financial-summary.php): payment_allocations, installment_payments.
 * If these sheets and that report ever disagreed about one day's cash, the
 * clinic would be worse off than with no report at all.
 *
 * X-ray and the pharmacy are departments of their own and their income is the
 * centre's: a doctor only SENDS patients there. The sheets count who he sent
 * and, beside it, what those requests brought in — information only, never
 * part of his money or his share.
 *
 * ── The doctor foreign keys are NOT consistent (checked against the schema) ──
 *   patient_folders.doctor_id   -> users.id
 *   case_invoices.doctor_id     -> users.id
 *   xray_requests.requested_by  -> users.id
 *   visits.*_doctor_id          -> doctors.id   (bridge via doctors.user_id)
 * Getting this wrong silently reports another doctor's money, so the two id
 * spaces are kept apart throughout: $docUserId vs $docRowId.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/VisitReportStats.php';
require_once __DIR__ . '/../classes/IncomeColumn.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/IncomeFormula.php';
require_once __DIR__ . '/../classes/XrayManager.php';

/** X-ray types as the clinic names them (the income sheet uses the same). */
const DS_XRAY_TYPES = ['panoramic' => 'OPG', 'cbct' => 'CBCT', 'cephalometric' => 'Ceph', 'periapical' => 'P.A'];

function dsValidDate($v)
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

function dsMoney($v)
{
    return number_format((float)$v, 0);
}

function dsEsc($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function dsHours($minutes)
{
    if ($minutes === null) {
        return '—';
    }
    $minutes = (int)round($minutes);
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

// ── Date ranges (the لە … بۆ filter on both pages) ─────────────────────

const DS_DAYS_KU = ['شەممە', 'یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی'];
const DS_MONTHS_KU = ['01' => 'کانوونی دووەم', '02' => 'شوبات', '03' => 'ئازار', '04' => 'نیسان',
    '05' => 'ئایار', '06' => 'حوزەیران', '07' => 'تەمموز', '08' => 'ئاب', '09' => 'ئەیلوول',
    '10' => 'تشرینی یەکەم', '11' => 'تشرینی دووەم', '12' => 'کانوونی یەکەم'];

/**
 * ?from=&to= → a valid [from, to]. Older links keep working: ?date= becomes a
 * one-day range and ?month= a whole month. One missing end copies the other,
 * and a reversed pair is swapped rather than rejected.
 */
function ds_range_from_request(array $q, string $defaultFrom, string $defaultTo): array
{
    $from = (string)($q['from'] ?? '');
    $to = (string)($q['to'] ?? '');
    if (!dsValidDate($from) && !dsValidDate($to)) {
        if (dsValidDate((string)($q['date'] ?? ''))) {
            $from = $to = $q['date'];
        } elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($q['month'] ?? ''))) {
            $from = $q['month'] . '-01';
            $to = date('Y-m-t', strtotime($from));
        } else {
            [$from, $to] = [$defaultFrom, $defaultTo];
        }
    }
    if (!dsValidDate($from)) {
        $from = $to;
    }
    if (!dsValidDate($to)) {
        $to = $from;
    }
    return $from <= $to ? [$from, $to] : [$to, $from];
}

function ds_is_whole_month(string $from, string $to): bool
{
    return substr($from, 8, 2) === '01' && $to === date('Y-m-t', strtotime($from));
}

/** The range before (-1) or after (+1): whole months step by month, anything else by its own length. */
function ds_shift_range(string $from, string $to, int $dir): array
{
    if (ds_is_whole_month($from, $to)) {
        $f = date('Y-m-01', strtotime($from . ($dir < 0 ? ' -1 month' : ' +1 month')));
        return [$f, date('Y-m-t', strtotime($f))];
    }
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $step = ($dir < 0 ? '-' : '+') . $days . ' day';
    return [date('Y-m-d', strtotime($from . ' ' . $step)), date('Y-m-d', strtotime($to . ' ' . $step))];
}

/**
 * "چوارشەممە 2026-10-14" / "ئەیلوول 2026" / "2026-10-01 بۆ 2026-10-14".
 * The Kurdish بۆ between the dates is what fixes the reading order: two dates
 * joined by a dash have no direction of their own, and browsers then laid the
 * same range out left-to-right on one page and right-to-left on another.
 */
function ds_range_label(string $from, string $to): string
{
    // A date after a Kurdish word gets laid out back to front ("14-10-2026");
    // an invisible left-to-right mark on each side keeps it in reading order.
    $ltr = fn(string $d) => "\u{200E}" . $d . "\u{200E}";
    if ($from === $to) {
        return DS_DAYS_KU[((int)date('w', strtotime($from)) + 1) % 7] . ' ' . $ltr($from);
    }
    if (ds_is_whole_month($from, $to)) {
        return DS_MONTHS_KU[substr($from, 5, 2)] . ' ' . substr($from, 0, 4);
    }
    return $ltr($from) . ' بۆ ' . $ltr($to);
}

/**
 * Quick picks: [label, from, to]. The week starts on Saturday, as the clinic's
 * does (Friday is the weekend).
 */
function ds_range_presets(): array
{
    $today = date('Y-m-d');
    $sinceSat = ((int)date('w') + 1) % 7;                      // Sat = 0 … Fri = 6
    $weekStart = date('Y-m-d', strtotime("-{$sinceSat} day"));
    $monthStart = date('Y-m-01');
    $lastMonth = date('Y-m-01', strtotime($monthStart . ' -1 month'));
    return [
        ['ئەمڕۆ',        $today, $today],
        ['دوێنێ',        date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        ['ئەم هەفتەیە',  $weekStart, $today],
        ['ئەم مانگە',    $monthStart, date('Y-m-t')],
        ['مانگی پێشوو',  $lastMonth, date('Y-m-t', strtotime($lastMonth))],
    ];
}

/** One day — the daily summary's unit. */
function ds_compute(Database $db, int $docUserId, string $day): array
{
    return ds_compute_range($db, $docUserId, $day, $day);
}

/**
 * Everything one doctor did and earned from $from to $to, both inclusive.
 *
 * Over a range the daily rules simply widen: "received" is money that came in
 * during the range against work billed in the range, and money that came in
 * during the range against older work is old debt cleared.
 *
 * So a month equals the sum of its days for every count, for billed/discount/
 * net, for x-rays, and for the TOTAL money in (received + old debt). Only the
 * split between those two moves, and correctly: a payment on the 20th for the
 * 5th's work is old debt on the 20th's daily sheet, but this month's own money
 * on the month sheet.
 *
 * @param int    $docUserId    users.id of the doctor
 * @param string $from         Y-m-d (already validated)
 * @param string $to           Y-m-d (already validated)
 * @param bool   $withPatients build the per-case patient list (detail pages only)
 */
function ds_compute_range(Database $db, int $docUserId, string $from, string $to, bool $withPatients = true): array
{
    $docRow = $db->fetch("SELECT id, full_name, commission_rate, store_share_normal, store_share_implant FROM users WHERE id = :id", ['id' => $docUserId]);

    // visits.* point at doctors.id, everything else at users.id — bridge here.
    $bridge = $db->fetch("SELECT id FROM doctors WHERE user_id = :uid LIMIT 1", ['uid' => $docUserId]);
    $docRowId = (int)($bridge['id'] ?? 0);
    // Not every `doctors` row carries a user_id. An unlinked account must show
    // ZERO visits, never another doctor's — and the sheet says so out loud.
    $visitsLinkBroken = ($docRowId <= 0);

    // ── Visits ───────────────────────────────────────────────────────
    // The visits report's own rules (visits-report.php), so the two sheets
    // always agree: each visit counts ONCE, for the doctor who saw it —
    // assigned, else the checkup/treatment doctor, else the requested one
    // (dh_visit_report_doctor_sql). Matching any doctor column instead
    // counted one visit for every doctor named on it.
    // پشکنین is split the way the report's matrix splits it: داواکراو (the
    // patient asked for this doctor, key `pending`) / پێدراو (reception gave
    // the patient to them, key `given`) / دیاریکردنی تر, and نوێ / کۆن.
    $work = ['total' => 0, 'treatment_visits' => 0, 'checkup_visits' => 0];
    $checkupBreakdown = ['given' => 0, 'pending' => 0, 'other' => 0, 'new_exam' => 0, 'old_exam' => 0];
    if (!$visitsLinkBroken) {
        $byType = [];
        foreach (['checkup', 'treatment'] as $type) {
            $doctorSql = dh_visit_report_doctor_sql($type);
            $groups = $db->fetchAll(
                "SELECT {$doctorSql} AS doctor_id,
                        requested_doctor_id, assigned_doctor_id, doctor_assignment_source, patient_status, receptionist_id, COUNT(*) AS count
                 FROM visits v
                 WHERE v.visit_date BETWEEN :f AND :t AND v.visit_type = :vt AND {$doctorSql} = :doc
                 GROUP BY doctor_id, requested_doctor_id, assigned_doctor_id, doctor_assignment_source, patient_status, receptionist_id",
                ['f' => $from, 't' => $to, 'vt' => $type, 'doc' => $docRowId]
            );
            $byType[$type] = dh_visit_report_build_matrix($groups, [$docRowId => ''], [])['doctor_stats'][$docRowId];
        }
        $work = [
            'total'            => $byType['checkup']['assigned'] + $byType['treatment']['assigned'],
            'treatment_visits' => $byType['treatment']['assigned'],
            'checkup_visits'   => $byType['checkup']['assigned'],
        ];
        $checkupBreakdown = [
            'given'    => $byType['checkup']['reception'],
            'pending'  => $byType['checkup']['requested'],
            'other'    => $byType['checkup']['other'],
            'new_exam' => $byType['checkup']['new'],
            'old_exam' => $byType['checkup']['old'],
        ];
    }

    // ── Invoices for this work day ───────────────────────────────────
    // doctor_id can be NULL on older rows; the folder's doctor is the fallback
    // and lives in the same id space (users.id).
    // A cancelled (deleted) invoice never happened. The chair money counts as
    // received only once the accountant has APPROVED the invoice — the moment
    // it enters the doctor's cashbox (CashboxLedger) and the same rule as
    // accountant/financial-summary.php; until then it is chair_pending, which
    // is not cash in and is not shown as debt either.
    $invoiceRow = $db->fetch(
        "SELECT COALESCE(SUM(ci.total_amount - ci.discount_amount), 0) AS net,
                COALESCE(SUM(ci.discount_amount), 0) AS discount,
                COALESCE(SUM(CASE WHEN ci.accounting_status = 'approved' THEN ci.draft_received_amount ELSE 0 END), 0) AS chair_received,
                COALESCE(SUM(CASE WHEN ci.accounting_status <> 'approved' THEN ci.draft_received_amount ELSE 0 END), 0) AS chair_pending
         FROM case_invoices ci
         JOIN cases c ON c.id = ci.case_id
         JOIN patient_folders pf ON pf.id = c.folder_id
         WHERE ci.work_date BETWEEN :f AND :t
           AND ci.accounting_status <> 'cancelled'
           AND COALESCE(ci.doctor_id, pf.doctor_id) = :uid",
        ['f' => $from, 't' => $to, 'uid' => $docUserId]
    );
    $invoiceNet    = (float)($invoiceRow['net'] ?? 0);
    $invoiceDisc   = (float)($invoiceRow['discount'] ?? 0);
    $chairReceived = (float)($invoiceRow['chair_received'] ?? 0);
    $chairPending  = (float)($invoiceRow['chair_pending'] ?? 0);

    // ── Money received ───────────────────────────────────────────────
    // Approved payments only. An allocation landing on an invoice whose
    // work_date is today is money for today's work; anything else is an older
    // balance being cleared. A payment with no allocation counts as old money.
    $payRows = $db->fetchAll(
        "SELECT p.id, p.amount,
                COALESCE(SUM(CASE WHEN ci.work_date BETWEEN :f2 AND :t2 THEN pa.allocated_amount ELSE 0 END), 0) AS today_part
         FROM payments p
         JOIN cases c ON c.id = p.case_id
         JOIN patient_folders pf ON pf.id = c.folder_id
         LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
         LEFT JOIN case_invoices ci ON ci.id = pa.invoice_id
         WHERE p.status = 'approved'
           AND DATE(p.payment_date) BETWEEN :f AND :t
           AND pf.doctor_id = :uid
         GROUP BY p.id, p.amount",
        ['f' => $from, 't' => $to, 'f2' => $from, 't2' => $to, 'uid' => $docUserId]
    );
    $paidTodayInvoice = 0.0;
    $paidOldBalance = 0.0;
    foreach ($payRows as $r) {
        $today = (float)$r['today_part'];
        $paidTodayInvoice += $today;
        $paidOldBalance += max(0, (float)$r['amount'] - $today);
    }

    // Installment collections — same table the accountant report reads. A row
    // linked to a payments row (payment_id, every collection since the ledger
    // rework — Installment::makeFlexiblePayment) is already counted above as
    // that approved payment; only the old standalone rows are added here, the
    // same rule CashboxLedger books by.
    $instRow = $db->fetch(
        "SELECT COALESCE(SUM(ip.amount), 0) AS total
         FROM installment_payments ip
         JOIN installment_plans pl ON pl.id = ip.plan_id
         JOIN cases c ON c.id = pl.case_id
         JOIN patient_folders pf ON pf.id = c.folder_id
         WHERE DATE(ip.paid_at) BETWEEN :f AND :t AND pf.doctor_id = :uid
           AND ip.payment_id IS NULL",
        ['f' => $from, 't' => $to, 'uid' => $docUserId]
    );
    $installmentPaid = (float)($instRow['total'] ?? 0);

    // Counter custody is shown separately, without affecting income or shares.
    // Applied custody is already counted above as a canonical approved payment.
    $advanceCollected = 0.0;
    if ($db->tableExists('patient_advances')) {
        $advanceCollected = (float)($db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM patient_advances
             WHERE doctor_id = :uid AND DATE(received_at) BETWEEN :f AND :t",
            ['uid' => $docUserId, 'f' => $from, 't' => $to]
        )['total'] ?? 0) - (float)($db->fetch(
            "SELECT COALESCE(SUM(refunded_amount), 0) AS total FROM patient_advances
             WHERE doctor_id = :uid AND refunded_at IS NOT NULL AND DATE(refunded_at) BETWEEN :f AND :t",
            ['uid' => $docUserId, 'f' => $from, 't' => $to]
        )['total'] ?? 0);
    }

    $treatmentCash = $chairReceived + $paidTodayInvoice;          // against today's work
    // Chair money still waiting for approval is neither cash in nor debt.
    $newDebtToday = max(0, $invoiceNet - $treatmentCash - $chairPending); // today's work still unpaid
    $oldDebtCollected = $paidOldBalance + $installmentPaid; // custody is informational until an approved payment
    // All the cash that really came in: today's work + old debt paid back.
    // The income sheet (accountant/_income_data.php) counts the same two.
    $cashIn = $treatmentCash + $oldDebtCollected;

    // ── Expenses charged to this doctor today ────────────────────────
    $labExpense = (float)($db->fetch(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses_lab WHERE doctor_id = :uid AND expense_date BETWEEN :f AND :t",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    )['total'] ?? 0);
    $storeByKind = [];
    foreach ($db->fetchAll(
        "SELECT expense_kind, COALESCE(SUM(amount), 0) AS total FROM expenses_store
         WHERE doctor_id = :uid AND expense_date BETWEEN :f AND :t GROUP BY expense_kind",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    ) as $row) {
        $storeByKind[$row['expense_kind']] = (float)$row['total'];
    }
    $storeExpense = (float)array_sum($storeByKind);
    // A general expense written in his name; one with no doctor is the centre's.
    $generalExpense = (float)($db->fetch(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses_general WHERE doctor_id = :uid AND expense_date BETWEEN :f AND :t",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    )['total'] ?? 0);
    // کۆڵۆمی تایبەت: what the accountant put on him on the income sheet.
    $columns = IncomeColumn::forDoctor($db, $docUserId, $from, $to);
    $doctorExpenses = $labExpense + $storeExpense + $generalExpense + $columns['total'];
    $netAfterExpenses = $cashIn - $doctorExpenses;

    // That net split by the doctor's percentage (users.commission_rate, set on
    // his account) — the same rule as the income sheet. null until it is set.
    $doctorRate = (float)($docRow['commission_rate'] ?? 0) > 0 ? min(100.0, (float)$docRow['commission_rate']) : null;
    // The owner's sheet (Book1.xlsx): only the lab and the general expense come
    // off before the split, so the doctor carries them by his income
    // percentage. The store is not in that pool at all: each kind goes by its
    // own percentage (users.store_share_normal / store_share_implant, his
    // income percentage until it is set) and his part of both is taken off
    // his share after the split.
    $storeOnDoctor = 0.0;
    $storeOnDoctorByKind = [];
    foreach ($storeByKind as $kind => $amount) {
        $storeRate = $docRow[$kind === 'implant' ? 'store_share_implant' : 'store_share_normal'] ?? $doctorRate;
        $storeOnDoctorByKind[$kind] = $amount * (float)$storeRate / 100;
        $storeOnDoctor += $storeOnDoctorByKind[$kind];
    }
    // A column marked «هەمووی لەسەر دکتۆر» is wholly his. A doctor with a
    // formula of his own (IncomeFormula, the «fx» on the income sheet) is
    // split by it instead — percentage or not.
    $storeRates = [];
    foreach (['normal', 'implant'] as $kind) {
        $storeRates[$kind] = (float)($docRow[$kind === 'implant' ? 'store_share_implant' : 'store_share_normal'] ?? $doctorRate ?? 0);
    }
    $doctorFormula = IncomeFormula::forDoctor($docUserId);
    $doctorFormulaError = null;
    $doctorShare = null;
    if ($doctorRate !== null || $doctorFormula !== null) {
        $doctorShare = ds_doctor_share_of($doctorFormula, ds_share_figures([
            'cashIn' => $cashIn, 'generalExpense' => $generalExpense, 'labExpense' => $labExpense,
            'storeByKind' => $storeByKind, 'storeOnDoctorByKind' => $storeOnDoctorByKind,
            'columnTotal' => $columns['total'], 'columnOnDoctor' => $columns['onDoctor'],
            'doctorRate' => $doctorRate, 'storeRates' => $storeRates,
        ]), $doctorFormulaError);
    }

    // ── Patients sent to x-ray (statistics only) ─────────────────────
    // X-ray is its own department and its money is the centre's, so no fee
    // appears here — only how many patients this doctor sent, and for what.
    // Every request he made counts, free ones too; cancelled ones never went.
    // Beside the count, what those requests really brought in (ds_xray_received_sql):
    // the centre's money, shown for information only.
    $xrayRows = $db->fetchAll(
        "SELECT xr.xray_type, COUNT(*) AS cnt, COALESCE(SUM(" . ds_xray_received_sql() . "), 0) AS received
         FROM xray_requests xr " . ds_xray_debt_join_sql() . "
         WHERE xr.requested_by = :uid
           AND DATE(xr.created_at) BETWEEN :f AND :t
           AND COALESCE(xr.request_status, '') <> 'cancelled'
           AND COALESCE(xr.status, '') <> 'cancelled'
         GROUP BY xr.xray_type",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    $xrayCount = 0;
    $xrayReceived = 0.0;
    $xrayByType = array_fill_keys(array_values(DS_XRAY_TYPES), 0);
    $xrayReceivedByType = array_fill_keys(array_values(DS_XRAY_TYPES), 0.0);
    foreach ($xrayRows as $r) {
        $xrayCount += (int)$r['cnt'];
        $xrayReceived += (float)$r['received'];
        $label = DS_XRAY_TYPES[$r['xray_type']] ?? (string)$r['xray_type'];
        $xrayByType[$label] = ($xrayByType[$label] ?? 0) + (int)$r['cnt'];
        $xrayReceivedByType[$label] = ($xrayReceivedByType[$label] ?? 0) + (float)$r['received'];
    }

    // ── Patients sent to the pharmacy (statistics only) ─────────────
    // The prescriptions he wrote (erp2_prescriptions.doctor_id is users.id)
    // and what the pharmacy took for the ones it sold — its own money.
    $pharmacy = ['count' => 0, 'sold' => 0, 'billed' => 0.0, 'received' => 0.0];
    if ($withPatients && $db->tableExists('erp2_prescriptions')) {
        $rx = $db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(rx.status = 'sold'), 0) AS sold,
                    COALESCE(SUM(CASE WHEN rx.status = 'sold' THEN si.grand_total ELSE 0 END), 0) AS billed,
                    COALESCE(SUM(CASE WHEN rx.status = 'sold' THEN si.paid_amount ELSE 0 END), 0) AS received
             FROM erp2_prescriptions rx
             LEFT JOIN erp2_sales_invoices si ON si.id = rx.invoice_id AND COALESCE(si.status, '') <> 'cancelled'
             WHERE rx.doctor_id = :uid AND DATE(rx.created_at) BETWEEN :f AND :t
               AND COALESCE(rx.status, '') <> 'cancelled'",
            ['uid' => $docUserId, 'f' => $from, 't' => $to]
        );
        $pharmacy = ['count' => (int)($rx['cnt'] ?? 0), 'sold' => (int)($rx['sold'] ?? 0),
                     'billed' => (float)($rx['billed'] ?? 0), 'received' => (float)($rx['received'] ?? 0)];
    }
    // A patient sent for two kinds of image is still one patient.
    $xrayPatients = $xrayCount === 0 ? 0 : (int)($db->fetch(
        "SELECT COUNT(DISTINCT xr.patient_id) AS n
         FROM xray_requests xr
         WHERE xr.requested_by = :uid
           AND DATE(xr.created_at) BETWEEN :f AND :t
           AND COALESCE(xr.request_status, '') <> 'cancelled'
           AND COALESCE(xr.status, '') <> 'cancelled'",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    )['n'] ?? 0);

    // ── Work types: treatments performed today ───────────────────────
    $topTreatments = $db->fetchAll(
        "SELECT ct.treatment_name, COUNT(*) AS cnt,
                COALESCE(SUM(ct.final_price), 0) AS amt
         FROM case_treatments ct
         JOIN cases c ON c.id = ct.case_id
         JOIN patient_folders pf ON pf.id = c.folder_id
         WHERE pf.doctor_id = :uid
           AND DATE(COALESCE(ct.performed_date, ct.created_at)) BETWEEN :f AND :t
           AND (ct.status IS NULL OR ct.status <> 'cancelled')
         GROUP BY ct.treatment_name
         ORDER BY cnt DESC, amt DESC",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    $treatmentCount = 0;
    $treatmentAmount = 0.0;
    foreach ($topTreatments as $t) {
        $treatmentCount += (int)$t['cnt'];
        $treatmentAmount += (float)$t['amt'];
    }
    // وەرگیراو for each work: its receipt's money so far, shared over the
    // receipt's lines by their price (ds_treatment_received).
    $treatmentReceived = 0.0;
    if ($withPatients && $topTreatments) {
        $received = ds_treatment_received($db, $docUserId, $from, $to);
        foreach ($topTreatments as $i => $t) {
            $topTreatments[$i]['received'] = $received[$t['treatment_name']] ?? 0.0;
            $treatmentReceived += $topTreatments[$i]['received'];
        }
    }

    // ── Attendance and time ──────────────────────────────────────────
    // Real clock-in/out (doctor_attendance), never backdated. One row per
    // doctor per day: over a range the minutes add up and every day with a
    // clock-in counts once. null minutes = no completed day in the range.
    $attRows = $db->fetchAll(
        "SELECT attendance_date, check_in_time, check_out_time FROM doctor_attendance
         WHERE doctor_id = :uid AND attendance_date BETWEEN :f AND :t",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    // One entry a day. The fingerprint device (the HR section's punches, for
    // the hr_employees row linked to this account) is what the clinic really
    // records, so where it has the day it is used; the doctor's own
    // «هاتنەژوورەوە» buttons cover the days it has not.
    $attendanceDays = [];
    foreach ($attRows as $a) {
        if (empty($a['check_in_time'])) {
            continue;
        }
        $attendanceDays[$a['attendance_date']] = [
            'in' => $a['check_in_time'], 'out' => $a['check_out_time'] ?: null, 'source' => 'manual',
            'minutes' => !empty($a['check_out_time'])
                ? max(0, (strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60) : null,
        ];
    }
    if ($withPatients) {
        foreach (ds_device_attendance($db, $docUserId, $from, $to) as $date => $day) {
            $attendanceDays[$date] = $day;
        }
        ksort($attendanceDays);
    }
    $attendanceMinutes = null;
    $attendedDays = count($attendanceDays);
    foreach ($attendanceDays as $day) {
        if ($day['minutes'] !== null) {
            $attendanceMinutes = ($attendanceMinutes ?? 0) + $day['minutes'];
        }
    }
    // The day's own row, for the daily summary's clock-in/out widget.
    $attendance = ($from === $to && $attRows) ? $attRows[0] : [];

    // Time with patients, per the clinic's rule: it counts from when the
    // patient is CALLED IN, not from when they sat down in the waiting room.
    // That also drops the stale rows an overnight cleanup closes hours later —
    // every one of those has called_time IS NULL.
    $busyRow = $db->fetch(
        "SELECT
            COALESCE(SUM(CASE WHEN wr.visit_type = 'treatment' THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END), 0) AS work_minutes,
            COALESCE(SUM(CASE WHEN wr.visit_type <> 'treatment' THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END), 0) AS exam_minutes
         FROM waiting_room wr
         WHERE wr.doctor_id = :uid
           AND DATE(wr.check_in_time) BETWEEN :f AND :t
           AND wr.called_time IS NOT NULL
           AND wr.check_out_time IS NOT NULL
           AND wr.check_out_time >= wr.called_time",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    $workMinutes = max(0, (int)($busyRow['work_minutes'] ?? 0));
    $examMinutes = max(0, (int)($busyRow['exam_minutes'] ?? 0));

    // ── Patients with work or money in the period ────────────────────
    // One row per case touched: billed, received, left — for this period
    // only, not the patient's whole balance (the account statement's job).
    // last_at (بەروار / کات) is the case's latest moment in the period: the
    // invoice approved (or sent/written), a payment taken, a treatment entered.
    // Newest first, like the cash book in accountant/financial-summary.php.
    // Skipped for overview pages that only need the totals.
    $patientRows = !$withPatients ? [] : $db->fetchAll(
        "SELECT p.full_name, c.id AS case_id,
                COALESCE(inv.net, 0)   AS billed,
                COALESCE(inv.chair, 0) AS chair,
                COALESCE(pay.paid, 0)  AS paid,
                COALESCE(tr.cnt, 0)    AS treatments,
                NULLIF(GREATEST(COALESCE(inv.at, '1000-01-01'), COALESCE(pay.at, '1000-01-01'),
                                COALESCE(tr.at, '1000-01-01')), '1000-01-01') AS last_at
         FROM cases c
         JOIN patient_folders pf ON pf.id = c.folder_id
         JOIN patients p ON p.id = pf.patient_id
         LEFT JOIN (
            SELECT ci.case_id,
                   SUM(ci.total_amount - ci.discount_amount) AS net,
                   SUM(CASE WHEN ci.accounting_status = 'approved' THEN ci.draft_received_amount ELSE 0 END) AS chair,
                   MAX(COALESCE(ci.approved_at, ci.sent_at, ci.created_at)) AS at
            FROM case_invoices ci
            WHERE ci.work_date BETWEEN :f1 AND :t1 AND ci.accounting_status <> 'cancelled'
            GROUP BY ci.case_id
         ) inv ON inv.case_id = c.id
         LEFT JOIN (
            SELECT p2.case_id, SUM(p2.amount) AS paid, MAX(p2.payment_date) AS at
            FROM payments p2
            WHERE p2.status = 'approved' AND DATE(p2.payment_date) BETWEEN :f2 AND :t2
            GROUP BY p2.case_id
         ) pay ON pay.case_id = c.id
         LEFT JOIN (
            SELECT ct.case_id, COUNT(*) AS cnt, MAX(ct.created_at) AS at
            FROM case_treatments ct
            WHERE DATE(COALESCE(ct.performed_date, ct.created_at)) BETWEEN :f3 AND :t3
              AND (ct.status IS NULL OR ct.status <> 'cancelled')
            GROUP BY ct.case_id
         ) tr ON tr.case_id = c.id
         WHERE pf.doctor_id = :uid
           AND (inv.net IS NOT NULL OR pay.paid IS NOT NULL OR tr.cnt IS NOT NULL)
         ORDER BY last_at DESC, p.full_name",
        ['f1' => $from, 't1' => $to, 'f2' => $from, 't2' => $to, 'f3' => $from, 't3' => $to, 'uid' => $docUserId]
    );
    $patientTotals = ['billed' => 0.0, 'paid' => 0.0];
    foreach ($patientRows as &$r) {
        $r['received'] = (float)$r['chair'] + (float)$r['paid'];
        $r['left'] = max(0, (float)$r['billed'] - $r['received']);
        $patientTotals['billed'] += (float)$r['billed'];
        $patientTotals['paid'] += $r['received'];
    }
    unset($r);
    $patientTotals['left'] = max(0, $patientTotals['billed'] - $patientTotals['paid']);

    return [
        'docUserId'         => $docUserId,
        'docName'           => $docRow['full_name'] ?? '—',
        'docRowId'          => $docRowId,
        'visitsLinkBroken'  => $visitsLinkBroken,
        'work'              => $work,
        'checkupBreakdown'  => $checkupBreakdown,
        'invoiceNet'        => $invoiceNet,
        'invoiceDisc'       => $invoiceDisc,
        'grossBilled'       => $invoiceNet + $invoiceDisc,
        'treatmentCash'     => $treatmentCash,
        'chairPending'      => $chairPending,
        'newDebtToday'      => $newDebtToday,
        'oldDebtCollected'  => $oldDebtCollected,
        'advanceCollected'  => $advanceCollected,
        'labExpense'        => $labExpense,
        'storeExpense'      => $storeExpense,
        'storeOnDoctor'     => $storeOnDoctor,
        'storeByKind'       => $storeByKind,
        'storeOnDoctorByKind' => $storeOnDoctorByKind,
        'generalExpense'    => $generalExpense,
        'doctorExpenses'    => $doctorExpenses,
        'columnCharges'     => $columns['charges'],
        'columnTotal'       => $columns['total'],
        'columnOnDoctor'    => $columns['onDoctor'],
        'columnCashOut'     => $columns['cashOut'],
        'cashIn'            => $cashIn,
        'netAfterExpenses'  => $netAfterExpenses,
        // The sheet's «کۆی ماوە دوای خەرجی»: the cash less the general expense and
        // the lab (and the columns) — before the store, which is split by its
        // own percentages
        'netBeforeStore'    => $netAfterExpenses + $storeExpense,
        'doctorRate'        => $doctorRate,
        'doctorFormula'     => $doctorFormula,
        'doctorFormulaError' => $doctorFormulaError,
        'storeRates'        => $storeRates,
        'doctorShare'       => $doctorShare,
        'centerShare'       => $doctorShare !== null ? $netAfterExpenses - $doctorShare : null,
        'xrayCount'         => $xrayCount,
        'xrayPatients'      => $xrayPatients,
        'xrayByType'        => $xrayByType,
        'xrayReceived'      => $xrayReceived,
        'xrayReceivedByType' => $xrayReceivedByType,
        'pharmacy'          => $pharmacy,
        'topTreatments'     => $topTreatments,
        'treatmentCount'    => $treatmentCount,
        'treatmentAmount'   => $treatmentAmount,
        'treatmentReceived' => $treatmentReceived,
        'attendance'        => $attendance,
        'attendanceDays'    => $attendanceDays,
        'attendanceMinutes' => $attendanceMinutes,
        'attendedDay'       => $attendedDays,
        'workMinutes'       => $workMinutes,
        'examMinutes'       => $examMinutes,
        'idleMinutes'       => $attendanceMinutes !== null ? max(0, $attendanceMinutes - $workMinutes - $examMinutes) : null,
        'patientRows'       => $patientRows,
        'patientTotals'     => $patientTotals,
    ];
}

/**
 * What an x-ray request really brought in: a request in the debt channel
 * (patient_xray_debts) its amount less what is still owed; else its amount
 * once paid; a free or unpaid one nothing. Needs ds_xray_debt_join_sql() on
 * xray_requests xr.
 */
function ds_xray_received_sql(): string
{
    return Database::getInstance()->tableExists('patient_xray_debts')
        ? "CASE WHEN pxd.request_id IS NOT NULL THEN GREATEST(0, xr.final_amount - pxd.rem)
                WHEN xr.payment_status = 'paid' THEN xr.final_amount ELSE 0 END"
        : "CASE WHEN xr.payment_status = 'paid' THEN xr.final_amount ELSE 0 END";
}

function ds_xray_debt_join_sql(): string
{
    return Database::getInstance()->tableExists('patient_xray_debts') ? XrayManager::remainingAmountJoinSql('xr', 'pxd') : '';
}

/**
 * The money that came in on each receipt so far (a receipt cancelled counts
 * nothing): taken at the chair once approved, paid before the system, an
 * advance, and every approved payment allocated to it — never more than its
 * net. [invoice_id => ['covered' => …, 'total' => total before discount]]
 */
function ds_invoice_coverage(Database $db, array $invoiceIds): array
{
    $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (!$invoiceIds) {
        return [];
    }
    $in = implode(',', $invoiceIds);
    $out = [];
    foreach ($db->fetchAll(
        "SELECT ci.id, ci.total_amount, ci.discount_amount,
                COALESCE(ci.prior_paid_amount, 0) + COALESCE(ci.advance_paid_amount, 0)
                + CASE WHEN ci.accounting_status = 'approved' THEN COALESCE(ci.draft_received_amount, 0) ELSE 0 END
                + COALESCE((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa
                            JOIN payments p ON p.id = pa.payment_id AND p.status = 'approved'
                            WHERE pa.invoice_id = ci.id), 0) AS covered
         FROM case_invoices ci
         WHERE ci.id IN ({$in}) AND ci.accounting_status <> 'cancelled'"
    ) as $r) {
        $net = max(0, (float)$r['total_amount'] - (float)$r['discount_amount']);
        $out[(int)$r['id']] = ['covered' => min($net, (float)$r['covered']), 'total' => (float)$r['total_amount']];
    }
    return $out;
}

/** A treatment line's part of its receipt's money: by its price, out of the receipt's total. */
function ds_line_received(float $price, ?array $coverage): float
{
    if (!$coverage || $coverage['total'] <= 0) {
        return 0.0;
    }
    return $coverage['covered'] * min(1, max(0, $price) / $coverage['total']);
}

/** وەرگیراو for each work type of the period: [treatment_name => amount]. */
function ds_treatment_received(Database $db, int $docUserId, string $from, string $to): array
{
    $lines = $db->fetchAll(
        "SELECT ct.treatment_name, ct.final_price, ct.invoice_id
         FROM case_treatments ct
         JOIN cases c ON c.id = ct.case_id
         JOIN patient_folders pf ON pf.id = c.folder_id
         WHERE pf.doctor_id = :uid
           AND DATE(COALESCE(ct.performed_date, ct.created_at)) BETWEEN :f AND :t
           AND (ct.status IS NULL OR ct.status <> 'cancelled')",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    $coverage = ds_invoice_coverage($db, array_column($lines, 'invoice_id'));
    $out = [];
    foreach ($lines as $l) {
        $out[$l['treatment_name']] = ($out[$l['treatment_name']] ?? 0.0)
            + ds_line_received((float)$l['final_price'], $coverage[(int)$l['invoice_id']] ?? null);
    }
    return $out;
}

/**
 * The fingerprint device's days for a doctor (HR section, HrAttendance): the
 * first in, the last out and the hours of the complete in/out pairs — today,
 * while still in, the time since the first in. [date => [in, out, minutes, source]]
 */
function ds_device_attendance(Database $db, int $docUserId, string $from, string $to): array
{
    if (!$db->tableExists('hr_employees') || !is_file(__DIR__ . '/../classes/HrAttendance.php')) {
        return [];
    }
    $emp = $db->fetch(
        "SELECT id FROM hr_employees WHERE user_id = :uid ORDER BY (status = 'active') DESC, id LIMIT 1",
        ['uid' => $docUserId]
    );
    if (!$emp) {
        return [];
    }
    require_once __DIR__ . '/../classes/HrAttendance.php';
    $hr = new HrAttendance();
    $emps = $hr->employees(['ids' => [(int)$emp['id']], 'status' => '']);
    if (!$emps) {
        return [];
    }
    $today = HrAttendance::currentWorkDate();
    $out = [];
    foreach ($hr->period($emps, $from, $to)[(int)$emp['id']]['days'] ?? [] as $date => $d) {
        if (empty($d['punches']) || empty($d['first_in'])) {
            continue;
        }
        $minutes = $d['hours'] > 0 ? $d['hours'] * 60 : null;
        $stillIn = $date === $today && ($d['status'] ?? '') === 'missing_out';
        if ($stillIn) {
            // in now: the time since the first in, on top of any closed pairs
            $last = end($d['pairs']);
            $minutes = ($minutes ?? 0) + max(0, (time() - strtotime($last['in']['time'] ?? $d['first_in'])) / 60);
        }
        $out[$date] = [
            'in' => $d['first_in'], 'out' => $stillIn ? null : $d['last_out'],
            'minutes' => $minutes, 'source' => 'device',
        ];
    }
    return $out;
}

/** Did anything happen this day? Quiet doctors are left off the overview. */
function ds_has_activity(array $ds): bool
{
    return $ds['work']['total'] > 0
        || $ds['grossBilled'] > 0
        || $ds['treatmentCash'] > 0
        || $ds['oldDebtCollected'] > 0
        || abs($ds['advanceCollected'] ?? 0) > 0
        || $ds['xrayCount'] > 0
        || $ds['treatmentCount'] > 0;
}

/**
 * The doctor's share of a net, the owner's way (Book1.xlsx):
 *   کۆی ماوە دوای خەرجی = بڕی − خەرجی گشتی − تاقیگە (− columns)
 *   پشکی دکتۆر        = that × his percentage − his part of the ئاسایی store
 *                       − his part of the Implant store
 *   پشکی لێزان        = the rest of the net: that × the centre's percentage
 *                       − the centre's part of ئاسایی and of Implant
 * $net is the net after EVERY expense (the whole store too); $backForSplit is
 * what is added back for the split — the whole store — and $offShare (his part
 * of ئاسایی and of Implant, by store_share_normal / store_share_implant) is
 * taken off his share after it. $whole is what is his alone (a column marked
 * so): added back for the split, taken off him whole. The centre's share is
 * the net less this. Used by ds_compute_range and the income sheet.
 */
function ds_doctor_share(float $net, float $rate, float $whole, float $backForSplit, float $offShare): float
{
    return round(($net + $whole + $backForSplit) * $rate / 100 - $offShare) - $whole;
}

/**
 * The figures a share is worked out from (ds_doctor_share_of), the income
 * sheet's way: only the expense kinds in $expSel (lab / store / general)
 * count, so the sheet's chips and a formula agree. $ds is ds_compute_range's
 * result, or the same keys. The names are IncomeFormula::vars()'s.
 */
function ds_share_figures(array $ds, array $expSel = ['lab', 'store', 'general']): array
{
    $lab = in_array('lab', $expSel, true) ? (float)$ds['labExpense'] : 0.0;
    $general = in_array('general', $expSel, true) ? (float)$ds['generalExpense'] : 0.0;
    $store = in_array('store', $expSel, true);
    $normal = $store ? (float)($ds['storeByKind']['normal'] ?? 0) : 0.0;
    $implant = $store ? (float)($ds['storeByKind']['implant'] ?? 0) : 0.0;
    $columns = (float)($ds['columnTotal'] ?? 0);
    return [
        'amount'       => (float)$ds['cashIn'],
        'general'      => $general,
        'lab'          => $lab,
        'normal'       => $normal,
        'implant'      => $implant,
        'store'        => $normal + $implant,
        'columns'      => $columns,
        'on_doctor'    => (float)($ds['columnOnDoctor'] ?? 0),
        'net'          => (float)$ds['cashIn'] - $general - $lab - $normal - $implant - $columns,
        'rate'         => (float)($ds['doctorRate'] ?? 0),
        'normal_rate'  => (float)($ds['storeRates']['normal'] ?? 0),
        'implant_rate' => (float)($ds['storeRates']['implant'] ?? 0),
        // his part of each store kind by its percentage — the sheet's rule needs them
        'normal_doc'   => $store ? (float)($ds['storeOnDoctorByKind']['normal'] ?? 0) : 0.0,
        'implant_doc'  => $store ? (float)($ds['storeOnDoctorByKind']['implant'] ?? 0) : 0.0,
    ];
}

/**
 * The doctor's share of those figures: his own formula (IncomeFormula) when
 * he has one, else the owner's sheet (ds_doctor_share). A formula that cannot
 * be worked out on them (a division by zero) falls back to the sheet and
 * says why in $error.
 */
function ds_doctor_share_of(?string $formula, array $f, ?string &$error = null): float
{
    $error = null;
    if ($formula !== null && $formula !== '') {
        try {
            return round(IncomeFormula::evaluate($formula, $f));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
    return ds_doctor_share($f['net'], $f['rate'], $f['on_doctor'], $f['normal'] + $f['implant'], $f['normal_doc'] + $f['implant_doc']);
}

/**
 * The daily summary's printed rows: [label, value, style], style being
 * '' | 'pos' | 'neg' | 'total'. Both the page and its PDF walk these lists.
 */
function ds_row_lists(array $ds): array
{
    $signed = fn(float $v) => $v < 0 ? '(' . dsMoney(abs($v)) . ')' : dsMoney($v);
    // The work billed, then the cash: what came in for this period's work and
    // old debt paid back are both real cash, so they add up to کۆی نەقدی
    // وەرگیراو, and the expenses come off that.
    $money = [
        ['کۆی گشتی پارە',           dsMoney($ds['grossBilled']), ''],
        ['بڕی داشکێنراو',           '(' . dsMoney($ds['invoiceDisc']) . ')', 'neg'],
        ['ماوەی دوای داشکێنراو',    dsMoney($ds['invoiceNet']), 'total'],
        ['بڕی ماوە',                dsMoney($ds['newDebtToday']), 'neg'],
        ['بڕی وەرگیراو',            dsMoney($ds['treatmentCash']), 'pos'],
    ];
    // Chair money on invoices the accountant has not approved yet: not cash in
    // until then (see ds_compute_range), so shown apart and only when there is any.
    if (($ds['chairPending'] ?? 0) > 0.5) {
        $money[] = ['وەرگیراوی چاوەڕوانی پەسەندکردن', dsMoney($ds['chairPending']), ''];
    }
    $money = array_merge($money, [
        ['پارەی گەڕاوەی قەرزی کۆن', dsMoney($ds['oldDebtCollected']), 'pos'],
        ['کۆی نەقدی وەرگیراو',      dsMoney($ds['cashIn']), 'total'],
        ['خەرجی تاقیگە',            '(' . dsMoney($ds['labExpense']) . ')', 'neg'],
        ['خەرجی کۆگا',              '(' . dsMoney($ds['storeExpense']) . ')', 'neg'],
    ]);
    // The store as the income sheet lists it: ئاسایی and Implant, each by its
    // own percentage — the doctor's part comes off his share, the rest off
    // the centre's.
    foreach (['normal' => 'ئاسایی', 'implant' => 'Implant'] as $kind => $label) {
        $amount = (float)($ds['storeByKind'][$kind] ?? 0);
        if ($amount > 0.5) {
            $onDoctor = (float)($ds['storeOnDoctorByKind'][$kind] ?? 0);
            // under a formula of his own the division by percentage says nothing
            $money[] = ['— ' . $label . (empty($ds['doctorFormula']) ? ': دکتۆر ' . dsMoney($onDoctor) . ' · لێزان ' . dsMoney($amount - $onDoctor) : ''),
                '(' . dsMoney($amount) . ')', ''];
        }
    }
    if ($ds['generalExpense'] > 0.5) {
        $money[] = ['خەرجی گشتی', '(' . dsMoney($ds['generalExpense']) . ')', 'neg'];
    }
    foreach ($ds['columnCharges'] ?? [] as $charge) {
        $money[] = [$charge['name'], '(' . dsMoney($charge['amount']) . ')', 'neg'];
    }
    $money[] = ['ماوەی دوای خەرجی', $signed($ds['netAfterExpenses']), 'total'];
    if ($ds['doctorShare'] !== null) {
        $how = !empty($ds['doctorFormula'])
            ? (empty($ds['doctorFormulaError']) ? 'فۆرمولی تایبەت' : 'فۆرمولەکە کار ناکات، بە ڕێژە')
            : rtrim(rtrim(number_format((float)$ds['doctorRate'], 2, '.', ''), '0'), '.') . '%';
        $money[] = ['پشکی دکتۆر (' . $how . ')', $signed($ds['doctorShare']), 'pos'];
        $money[] = ['پشکی سەنتەر',               $signed($ds['centerShare']), ''];
    }
    $money[] = ['ئامانەتی وەرگیراو (تەنها زانیاری؛ ناچێتە داهات)', dsMoney($ds['advanceCollected'] ?? 0), ''];
    return [
        'money' => $money,
        // ناردن بۆ ئێکسڕەی — who he sent, and what it brought in (the centre's).
        'xray' => array_merge(
            [
                ['نەخۆشی نێردراو',  dsMoney($ds['xrayPatients']), 'total'],
                ['کۆی داواکاری',    dsMoney($ds['xrayCount']), ''],
                ['پارەی وەرگیراوی ئێکسڕەی', dsMoney($ds['xrayReceived'] ?? 0), 'pos'],
            ],
            array_map(
                fn($label, $n) => [$label, dsMoney($n), '', 'وەرگیراو: ' . dsMoney($ds['xrayReceivedByType'][$label] ?? 0)],
                array_keys(array_filter($ds['xrayByType'])),
                array_filter($ds['xrayByType'])
            )
        ),
        // ناردن بۆ دەرمانخانە — the same for the pharmacy.
        'pharmacy' => [
            ['ڕەچەتەی نووسراو',          dsMoney($ds['pharmacy']['count'] ?? 0), 'total'],
            ['ڕەچەتەی فرۆشراو',          dsMoney($ds['pharmacy']['sold'] ?? 0), ''],
            ['پارەی وەرگیراوی دەرمانخانە', dsMoney($ds['pharmacy']['received'] ?? 0), 'pos'],
        ],
        // Same rows as the visits report's matrix for this one doctor.
        'visit' => array_merge(
            [
                ['کۆی گشتی سەردانیکەران', dsMoney($ds['work']['total']), 'total'],
                ['کۆی گشتی ئیش',          dsMoney($ds['work']['treatment_visits']), ''],
                ['کۆی گشتی پشکنین',       dsMoney($ds['work']['checkup_visits']), ''],
                ['پشکنینی داواکراو',      dsMoney($ds['checkupBreakdown']['pending']), ''],
                ['پشکنینی پێدراو (گواستنەوەی پێشوازی)', dsMoney($ds['checkupBreakdown']['given']), ''],
            ],
            ($ds['checkupBreakdown']['other'] ?? 0) > 0
                ? [['پشکنینی دیاریکردنی تر', dsMoney($ds['checkupBreakdown']['other']), '']]
                : [],
            [
                ['پشکنینی نوێ',           dsMoney($ds['checkupBreakdown']['new_exam']), ''],
                ['پشکنینی کۆن',           dsMoney($ds['checkupBreakdown']['old_exam']), ''],
            ]
        ),
        'time' => [
            ['کاتژمێری هاتن',    dsHours($ds['attendanceMinutes']), 'total', ds_attendance_note($ds['attendanceDays'] ?? [])],
            ['ڕۆژی هاتن',        dsMoney($ds['attendedDay']), ''],
            ['کۆی کاتی ئیشکردن', dsHours($ds['workMinutes']), ''],
            ['کۆی کاتی پشکنین',  dsHours($ds['examMinutes']), ''],
            ['کۆی کاتی بەتال',   dsHours($ds['idleMinutes']), ''],
        ],
    ];
}

/**
 * The list behind a row (daily-summary-detail.php ?kind=), by its label; null
 * for a row that is a sum of others (the shares, the net).
 */
function ds_detail_kind(string $label): ?string
{
    static $map = [
        'کۆی گشتی پارە' => 'invoices', 'بڕی داشکێنراو' => 'invoices', 'ماوەی دوای داشکێنراو' => 'invoices',
        'بڕی ماوە' => 'invoices', 'بڕی وەرگیراو' => 'invoices', 'وەرگیراوی چاوەڕوانی پەسەندکردن' => 'invoices',
        'پارەی گەڕاوەی قەرزی کۆن' => 'payments', 'کۆی نەقدی وەرگیراو' => 'payments',
        'خەرجی تاقیگە' => 'lab', 'خەرجی کۆگا' => 'store', 'خەرجی گشتی' => 'general',
        'کۆی گشتی سەردانیکەران' => 'visits', 'کۆی گشتی ئیش' => 'visits:treatment', 'کۆی گشتی پشکنین' => 'visits:checkup',
        'پشکنینی داواکراو' => 'visits:checkup', 'پشکنینی دیاریکردنی تر' => 'visits:checkup',
        'پشکنینی نوێ' => 'visits:checkup', 'پشکنینی کۆن' => 'visits:checkup',
        'نەخۆشی نێردراو' => 'xray', 'کۆی داواکاری' => 'xray', 'پارەی وەرگیراوی ئێکسڕەی' => 'xray',
        'ڕەچەتەی نووسراو' => 'pharmacy', 'ڕەچەتەی فرۆشراو' => 'pharmacy', 'پارەی وەرگیراوی دەرمانخانە' => 'pharmacy',
        'کاتژمێری هاتن' => 'attendance', 'ڕۆژی هاتن' => 'attendance',
        'کۆی کاتی ئیشکردن' => 'sessions', 'کۆی کاتی پشکنین' => 'sessions', 'کۆی کاتی بەتال' => 'sessions',
    ];
    if (isset($map[$label])) {
        return $map[$label];
    }
    if (in_array($label, DS_XRAY_TYPES, true)) {
        return 'xray:' . $label;
    }
    if (strpos($label, 'پشکنینی پێدراو') === 0) {
        return 'visits:checkup';
    }
    if (strpos($label, 'ئامانەتی وەرگیراو') === 0) {
        return 'advances';
    }
    return null;
}

/**
 * Under «کاتژمێری هاتن»: one day's in → out, and where it came from (the
 * fingerprint device or the doctor's own button); over a range, the source.
 */
function ds_attendance_note(array $days): string
{
    if (!$days) {
        return '';
    }
    $device = count(array_filter($days, fn($d) => $d['source'] === 'device'));
    $from = $device === count($days) ? 'پەنجەمۆر' : ($device ? 'پەنجەمۆر و دەستی' : 'دەستی');
    if (count($days) === 1) {
        $d = reset($days);
        return date('H:i', strtotime($d['in'])) . ' ← ' . (!empty($d['out']) ? date('H:i', strtotime($d['out'])) : 'هێشتا لێرەیە')
            . ' · ' . $from;
    }
    return 'لە ' . $from;
}

// ── ڕێژەی پیشاندان — what a doctor is shown of their own money ──────────
// The admin may have a doctor see only a part of their money on this sheet
// (20% …): for the tax, and so that whoever learns the doctor's password does
// not learn what they earn. Display only — nothing stored changes, and the
// admin and the accountant always see the whole. A doctor's own percentage,
// else the one for all doctors, else 100.
const DS_VIEW_PCT_KEY = 'daily_summary_view_pct';

/** The percentage of their money a doctor is shown (1 … 100). */
function ds_view_percent(int $doctorUserId): float
{
    $v = Settings::get(DS_VIEW_PCT_KEY . '_d' . $doctorUserId);
    if ($v === null || $v === '') {
        $v = Settings::get(DS_VIEW_PCT_KEY);
    }
    return ($v === null || $v === '') ? 100.0 : max(1.0, min(100.0, (float)$v));
}

/** Is a doctor's percentage their own (not the one for all doctors)? */
function ds_view_percent_is_own(int $doctorUserId): bool
{
    $v = Settings::get(DS_VIEW_PCT_KEY . '_d' . $doctorUserId);
    return $v !== null && $v !== '';
}

/**
 * Every amount of money in ds_compute_range()'s result times $factor; counts
 * and times stay as they are. All of it scales alike, so every total still
 * adds up.
 */
function ds_scale_money(array $ds, float $factor): array
{
    foreach (['invoiceNet', 'invoiceDisc', 'grossBilled', 'treatmentCash', 'chairPending', 'newDebtToday',
              'oldDebtCollected', 'advanceCollected', 'labExpense', 'storeExpense', 'storeOnDoctor',
              'generalExpense', 'doctorExpenses', 'columnTotal', 'columnOnDoctor', 'columnCashOut', 'cashIn',
              'netAfterExpenses', 'netBeforeStore', 'doctorShare', 'centerShare', 'treatmentAmount',
              'treatmentReceived', 'xrayReceived'] as $k) {
        if (isset($ds[$k]) && is_numeric($ds[$k])) {
            $ds[$k] = (float)$ds[$k] * $factor;
        }
    }
    foreach (['storeByKind', 'storeOnDoctorByKind', 'xrayReceivedByType'] as $k) {
        foreach ($ds[$k] ?? [] as $kind => $v) {
            $ds[$k][$kind] = (float)$v * $factor;
        }
    }
    foreach ($ds['columnCharges'] ?? [] as $i => $c) {
        $ds['columnCharges'][$i]['amount'] = (float)($c['amount'] ?? 0) * $factor;
    }
    foreach ($ds['topTreatments'] ?? [] as $i => $t) {
        $ds['topTreatments'][$i]['amt'] = (float)$t['amt'] * $factor;
        if (isset($t['received'])) {
            $ds['topTreatments'][$i]['received'] = (float)$t['received'] * $factor;
        }
    }
    foreach (['billed', 'received'] as $k) {
        if (isset($ds['pharmacy'][$k])) {
            $ds['pharmacy'][$k] = (float)$ds['pharmacy'][$k] * $factor;
        }
    }
    foreach ($ds['patientRows'] ?? [] as $i => $r) {
        foreach (['billed', 'chair', 'paid', 'received', 'left'] as $k) {
            if (isset($r[$k])) {
                $ds['patientRows'][$i][$k] = (float)$r[$k] * $factor;
            }
        }
    }
    foreach (['billed', 'paid', 'left'] as $k) {
        if (isset($ds['patientTotals'][$k])) {
            $ds['patientTotals'][$k] = (float)$ds['patientTotals'][$k] * $factor;
        }
    }
    return $ds;
}
