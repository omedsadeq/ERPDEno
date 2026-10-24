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
 * X-ray is its own department and its income is the centre's: a doctor only
 * SENDS patients there. So the doctor's sheets count the patients he sent and
 * never show x-ray money.
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
    $docRow = $db->fetch("SELECT id, full_name, commission_rate FROM users WHERE id = :id", ['id' => $docUserId]);

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
    $storeExpense = (float)($db->fetch(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses_store WHERE doctor_id = :uid AND expense_date BETWEEN :f AND :t",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    )['total'] ?? 0);
    $netAfterExpenses = $cashIn - $labExpense - $storeExpense;

    // That net split by the doctor's percentage (users.commission_rate, set on
    // his account) — the same rule as the income sheet. null until it is set.
    $doctorRate = (float)($docRow['commission_rate'] ?? 0) > 0 ? min(100.0, (float)$docRow['commission_rate']) : null;
    $doctorShare = $doctorRate !== null ? round($netAfterExpenses * $doctorRate / 100) : null;

    // ── Patients sent to x-ray (statistics only) ─────────────────────
    // X-ray is its own department and its money is the centre's, so no fee
    // appears here — only how many patients this doctor sent, and for what.
    // Every request he made counts, free ones too; cancelled ones never went.
    $xrayRows = $db->fetchAll(
        "SELECT xr.xray_type, COUNT(*) AS cnt
         FROM xray_requests xr
         WHERE xr.requested_by = :uid
           AND DATE(xr.created_at) BETWEEN :f AND :t
           AND COALESCE(xr.request_status, '') <> 'cancelled'
           AND COALESCE(xr.status, '') <> 'cancelled'
         GROUP BY xr.xray_type",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    $xrayCount = 0;
    $xrayByType = array_fill_keys(array_values(DS_XRAY_TYPES), 0);
    foreach ($xrayRows as $r) {
        $xrayCount += (int)$r['cnt'];
        $label = DS_XRAY_TYPES[$r['xray_type']] ?? (string)$r['xray_type'];
        $xrayByType[$label] = ($xrayByType[$label] ?? 0) + (int)$r['cnt'];
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

    // ── Attendance and time ──────────────────────────────────────────
    // Real clock-in/out (doctor_attendance), never backdated. One row per
    // doctor per day: over a range the minutes add up and every day with a
    // clock-in counts once. null minutes = no completed day in the range.
    $attRows = $db->fetchAll(
        "SELECT check_in_time, check_out_time FROM doctor_attendance
         WHERE doctor_id = :uid AND attendance_date BETWEEN :f AND :t",
        ['uid' => $docUserId, 'f' => $from, 't' => $to]
    );
    $attendanceMinutes = null;
    $attendedDays = 0;
    foreach ($attRows as $a) {
        if (!empty($a['check_in_time'])) {
            $attendedDays++;
        }
        if (!empty($a['check_in_time']) && !empty($a['check_out_time'])) {
            $attendanceMinutes = ($attendanceMinutes ?? 0)
                + max(0, (strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60);
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
        'cashIn'            => $cashIn,
        'netAfterExpenses'  => $netAfterExpenses,
        'doctorRate'        => $doctorRate,
        'doctorShare'       => $doctorShare,
        'centerShare'       => $doctorShare !== null ? $netAfterExpenses - $doctorShare : null,
        'xrayCount'         => $xrayCount,
        'xrayPatients'      => $xrayPatients,
        'xrayByType'        => $xrayByType,
        'topTreatments'     => $topTreatments,
        'treatmentCount'    => $treatmentCount,
        'treatmentAmount'   => $treatmentAmount,
        'attendance'        => $attendance,
        'attendanceMinutes' => $attendanceMinutes,
        'attendedDay'       => $attendedDays,
        'workMinutes'       => $workMinutes,
        'examMinutes'       => $examMinutes,
        'idleMinutes'       => $attendanceMinutes !== null ? max(0, $attendanceMinutes - $workMinutes - $examMinutes) : null,
        'patientRows'       => $patientRows,
        'patientTotals'     => $patientTotals,
    ];
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
        ['ماوەی دوای خەرجی',        $signed($ds['netAfterExpenses']), 'total'],
    ]);
    if ($ds['doctorRate'] !== null) {
        $rate = rtrim(rtrim(number_format($ds['doctorRate'], 2, '.', ''), '0'), '.');
        $money[] = ['پشکی دکتۆر (' . $rate . '%)', $signed($ds['doctorShare']), 'pos'];
        $money[] = ['پشکی سەنتەر',               $signed($ds['centerShare']), ''];
    }
    $money[] = ['ئامانەتی وەرگیراو (تەنها زانیاری؛ ناچێتە داهات)', dsMoney($ds['advanceCollected'] ?? 0), ''];
    return [
        'money' => $money,
        // ناردن بۆ ئێکسڕەی — counts only; the income is the centre's.
        'xray' => array_merge(
            [
                ['نەخۆشی نێردراو',  dsMoney($ds['xrayPatients']), 'total'],
                ['کۆی داواکاری',    dsMoney($ds['xrayCount']), ''],
            ],
            array_map(
                fn($label, $n) => [$label, dsMoney($n), ''],
                array_keys(array_filter($ds['xrayByType'])),
                array_filter($ds['xrayByType'])
            )
        ),
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
            ['کاتژمێری هاتن',    dsHours($ds['attendanceMinutes']), 'total'],
            ['ڕۆژی هاتن',        dsMoney($ds['attendedDay']), ''],
            ['کۆی کاتی ئیشکردن', dsHours($ds['workMinutes']), ''],
            ['کۆی کاتی پشکنین',  dsHours($ds['examMinutes']), ''],
            ['کۆی کاتی بەتال',   dsHours($ds['idleMinutes']), ''],
        ],
    ];
}
