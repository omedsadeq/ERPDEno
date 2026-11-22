<?php
/**
 * پوختەی ڕۆژانە — the list behind a card or a row (a click on the sheet).
 *
 * GET kind (ds_detail_kind), from, to, doctor, as_doctor — the sheet's own
 * query. The same gate as the sheet (_daily_summary_data.php: login, the
 * permission, a doctor pinned to themselves, the password lock — answered in
 * JSON here) and the same ڕێژەی پیشاندان on every amount.
 *
 * Answers {success, title, cols: [{t, n (a number), sum}], rows: [[cell…]], note}
 * where a cell is text or {t, href}.
 */

$dsLockJson = true;
require_once __DIR__ . '/_daily_summary_data.php';

header('Content-Type: application/json; charset=utf-8');

$kind = (string)($_GET['kind'] ?? '');
[$kindBase, $kindArg] = array_pad(explode(':', $kind, 2), 2, '');
$scale = ((!$isAdminView || $dsAsDoctor) && $dsViewPct < 100) ? $dsViewPct / 100 : 1.0;
$m = fn($v) => dsMoney((float)$v * $scale);
$caseLink = fn($name, $caseId) => $caseId ? ['t' => (string)$name, 'href' => APP_URL . '/cases/view.php?id=' . (int)$caseId] : (string)$name;
$time = fn($dt) => $dt ? date('H:i', strtotime($dt)) : '';
$date = fn($dt) => $dt ? date('Y-m-d', strtotime($dt)) : '';
$p = ['uid' => $docUserId, 'f' => $from, 't' => $to];

$title = '';
$cols = [];
$rows = [];
$note = '';

switch ($kindBase) {
    case 'invoices':
        // the period's receipts: billed, the discount, what came in so far, what is left
        $title = 'پسووڵەکانی ئەم ماوەیە';
        $cols = [['t' => 'نەخۆش'], ['t' => 'پسووڵە'], ['t' => 'بەروار', 'n' => true], ['t' => 'کۆ', 'n' => true, 'sum' => true],
                 ['t' => 'داشکاندن', 'n' => true, 'sum' => true], ['t' => 'دوای داشکاندن', 'n' => true, 'sum' => true],
                 ['t' => 'وەرگیراو', 'n' => true, 'sum' => true], ['t' => 'ماوە', 'n' => true, 'sum' => true], ['t' => 'دۆخ']];
        $list = $db->fetchAll(
            "SELECT ci.id, ci.invoice_no, ci.work_date, ci.case_id, ci.total_amount, ci.discount_amount, ci.accounting_status, p.full_name
             FROM case_invoices ci
             JOIN cases c ON c.id = ci.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE ci.work_date BETWEEN :f AND :t AND ci.accounting_status <> 'cancelled'
               AND COALESCE(ci.doctor_id, pf.doctor_id) = :uid
             ORDER BY ci.work_date DESC, ci.id DESC", $p);
        $cover = ds_invoice_coverage($db, array_column($list, 'id'));
        $states = ['approved' => 'پەسەندکراو', 'sent' => 'نێردراو', 'draft' => 'ڕەشنووس', 'returned' => 'گەڕێنراوە', 'pending' => 'چاوەڕوان'];
        foreach ($list as $r) {
            $net = max(0, (float)$r['total_amount'] - (float)$r['discount_amount']);
            $got = $cover[(int)$r['id']]['covered'] ?? 0;
            $rows[] = [$caseLink($r['full_name'], $r['case_id']), (string)$r['invoice_no'], (string)$r['work_date'],
                $m($r['total_amount']), $m($r['discount_amount']), $m($net), $m($got), $m(max(0, $net - $got)),
                $states[(string)$r['accounting_status']] ?? (string)$r['accounting_status']];
        }
        $note = 'وەرگیراو: هەموو ئەو پارەیەی تا ئێستا بۆ ئەو پسووڵەیە هاتووە.';
        break;

    case 'payments':
        // the cash: payments taken in the period, and the chair money of approved receipts
        $title = 'پارەی وەرگیراو';
        $cols = [['t' => 'نەخۆش'], ['t' => 'ژمارە'], ['t' => 'بەروار', 'n' => true], ['t' => 'کات', 'n' => true],
                 ['t' => 'بۆ'], ['t' => 'بڕ', 'n' => true, 'sum' => true]];
        foreach ($db->fetchAll(
            "SELECT p.payment_number, p.payment_date, p.amount, p.case_id, pt.full_name,
                    COALESCE(SUM(CASE WHEN ci.work_date BETWEEN :f2 AND :t2 THEN pa.allocated_amount ELSE 0 END), 0) AS this_part
             FROM payments p
             JOIN cases c ON c.id = p.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients pt ON pt.id = pf.patient_id
             LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
             LEFT JOIN case_invoices ci ON ci.id = pa.invoice_id
             WHERE p.status = 'approved' AND DATE(p.payment_date) BETWEEN :f AND :t AND pf.doctor_id = :uid
             GROUP BY p.id, p.payment_number, p.payment_date, p.amount, p.case_id, pt.full_name
             ORDER BY p.payment_date DESC", $p + ['f2' => $from, 't2' => $to]) as $r) {
            $old = (float)$r['amount'] - (float)$r['this_part'];
            $for = $old <= 0.5 ? 'کاری ئەم ماوەیە' : ((float)$r['this_part'] <= 0.5 ? 'قەرزی کۆن' : 'هەردووکیان');
            $rows[] = [$caseLink($r['full_name'], $r['case_id']), (string)$r['payment_number'], $date($r['payment_date']),
                $time($r['payment_date']), $for, $m($r['amount'])];
        }
        foreach ($db->fetchAll(
            "SELECT ci.invoice_no, COALESCE(ci.approved_at, ci.work_date) AS at, ci.draft_received_amount, ci.case_id, pt.full_name
             FROM case_invoices ci
             JOIN cases c ON c.id = ci.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients pt ON pt.id = pf.patient_id
             WHERE ci.work_date BETWEEN :f AND :t AND ci.accounting_status = 'approved' AND ci.draft_received_amount > 0
               AND COALESCE(ci.doctor_id, pf.doctor_id) = :uid
             ORDER BY at DESC", $p) as $r) {
            $rows[] = [$caseLink($r['full_name'], $r['case_id']), (string)$r['invoice_no'], $date($r['at']), $time($r['at']),
                'لەسەر کورسی', $m($r['draft_received_amount'])];
        }
        break;

    case 'lab':
        $title = 'خەرجی تاقیگە';
        $cols = [['t' => 'بەروار', 'n' => true], ['t' => 'نەخۆش'], ['t' => 'تاقیگە'], ['t' => 'ئیش'], ['t' => 'ددان'],
                 ['t' => 'تێبینی'], ['t' => 'بڕ', 'n' => true, 'sum' => true]];
        foreach ($db->fetchAll(
            "SELECT e.expense_date, e.lab_name, e.work_type, e.tooth_number, e.description, e.amount, e.case_id, p.full_name
             FROM expenses_lab e LEFT JOIN patients p ON p.id = e.patient_id
             WHERE e.doctor_id = :uid AND e.expense_date BETWEEN :f AND :t ORDER BY e.expense_date DESC, e.id DESC", $p) as $r) {
            $rows[] = [(string)$r['expense_date'], $caseLink($r['full_name'] ?? '', $r['case_id']), (string)$r['lab_name'],
                (string)$r['work_type'], (string)$r['tooth_number'], (string)$r['description'], $m($r['amount'])];
        }
        break;

    case 'store':
        $title = 'خەرجی کۆگا';
        $cols = [['t' => 'بەروار', 'n' => true], ['t' => 'جۆر'], ['t' => 'کاڵا / وەسف'], ['t' => 'دابینکەر'], ['t' => 'ژمارە', 'n' => true],
                 ['t' => 'بڕ', 'n' => true, 'sum' => true]];
        foreach ($db->fetchAll(
            "SELECT expense_date, expense_kind, description, supplier, quantity, unit, amount FROM expenses_store
             WHERE doctor_id = :uid AND expense_date BETWEEN :f AND :t ORDER BY expense_date DESC, id DESC", $p) as $r) {
            $rows[] = [(string)$r['expense_date'], $r['expense_kind'] === 'implant' ? 'Implant' : 'ئاسایی', (string)$r['description'],
                (string)$r['supplier'], trim(dsMoney($r['quantity']) . ' ' . $r['unit']), $m($r['amount'])];
        }
        break;

    case 'general':
        $title = 'خەرجی گشتی';
        $cols = [['t' => 'بەروار', 'n' => true], ['t' => 'وەسف'], ['t' => 'لایەن'], ['t' => 'تێبینی'], ['t' => 'بڕ', 'n' => true, 'sum' => true]];
        foreach ($db->fetchAll(
            "SELECT expense_date, description, vendor, notes, amount FROM expenses_general
             WHERE doctor_id = :uid AND expense_date BETWEEN :f AND :t ORDER BY expense_date DESC, id DESC", $p) as $r) {
            $rows[] = [(string)$r['expense_date'], (string)$r['description'], (string)$r['vendor'], (string)$r['notes'], $m($r['amount'])];
        }
        break;

    case 'advances':
        $title = 'ئامانەتی وەرگیراو';
        $cols = [['t' => 'بەروار', 'n' => true], ['t' => 'نەخۆش'], ['t' => 'ژمارە'], ['t' => 'بڕ', 'n' => true, 'sum' => true],
                 ['t' => 'گەڕێنراوە', 'n' => true, 'sum' => true]];
        if ($db->tableExists('patient_advances')) {
            foreach ($db->fetchAll(
                "SELECT a.received_at, a.advance_no, a.amount, a.refunded_amount, p.full_name
                 FROM patient_advances a LEFT JOIN patients p ON p.id = a.patient_id
                 WHERE a.doctor_id = :uid AND DATE(a.received_at) BETWEEN :f AND :t ORDER BY a.received_at DESC", $p) as $r) {
                $rows[] = [$date($r['received_at']), (string)$r['full_name'], (string)$r['advance_no'], $m($r['amount']), $m($r['refunded_amount'] ?? 0)];
            }
        }
        break;

    case 'visits':
        // each visit once, for the doctor who saw it (the visits report's rule)
        $type = in_array($kindArg, ['checkup', 'treatment'], true) ? $kindArg : 'both';
        $title = ['checkup' => 'پشکنینەکان', 'treatment' => 'سەردانی ئیش', 'both' => 'هەموو سەردانەکان'][$type];
        $cols = [['t' => 'نەخۆش'], ['t' => 'بەروار', 'n' => true], ['t' => 'کات', 'n' => true], ['t' => 'جۆر'], ['t' => 'نوێ / کۆن'],
                 ['t' => 'چۆن هات'], ['t' => 'ئیش']];
        if (!$visitsLinkBroken) {
            [$typeWhere, $typeParams] = dh_visit_report_type_where($type);
            $doctorSql = dh_visit_report_doctor_sql($type);
            foreach ($db->fetchAll(
                "SELECT COALESCE(p.full_name, v.patient_name) AS name, v.visit_date, COALESCE(v.checkin_time, v.arrival_time, v.created_at) AS at,
                        v.visit_type, v.patient_status, v.doctor_assignment_source, wt.work_name
                 FROM visits v
                 LEFT JOIN patients p ON p.id = v.patient_id
                 LEFT JOIN work_types wt ON wt.id = v.work_type_id
                 WHERE v.visit_date BETWEEN :f AND :t AND {$typeWhere} AND {$doctorSql} = :doc
                 ORDER BY v.visit_date DESC, at DESC",
                ['f' => $from, 't' => $to, 'doc' => $docRowId] + $typeParams) as $r) {
                $rows[] = [(string)$r['name'], (string)$r['visit_date'], $time($r['at']),
                    $r['visit_type'] === 'treatment' ? 'ئیش' : 'پشکنین',
                    ['new' => 'نوێ', 'old' => 'کۆن'][(string)$r['patient_status']] ?? '',
                    ['requested' => 'داواکراو', 'reception' => 'پێدراو (پێشوازی)'][(string)$r['doctor_assignment_source']] ?? '',
                    (string)($r['work_name'] ?? '')];
            }
        }
        break;

    case 'xray':
        $typeKey = $kindArg !== '' ? array_search($kindArg, DS_XRAY_TYPES, true) : null;
        $title = 'ناردن بۆ ئێکسڕەی' . ($kindArg !== '' ? ' — ' . $kindArg : '');
        $cols = [['t' => 'نەخۆش'], ['t' => 'بەروار', 'n' => true], ['t' => 'جۆر'], ['t' => 'ژمارە'], ['t' => 'نرخ', 'n' => true, 'sum' => true],
                 ['t' => 'پارەدان'], ['t' => 'وەرگیراو', 'n' => true, 'sum' => true]];
        $pay = ['paid' => 'دراوە', 'not_required' => 'بێ پارە', 'pending_payment' => 'چاوەڕوانی پارە',
                'debt_approved' => 'قەرز', 'debt_pending' => 'قەرز (چاوەڕوان)', 'debt' => 'قەرز'];
        foreach ($db->fetchAll(
            "SELECT p.full_name, xr.created_at, xr.xray_type, xr.request_number, xr.final_amount, xr.payment_status, xr.case_id,
                    " . ds_xray_received_sql() . " AS received
             FROM xray_requests xr " . ds_xray_debt_join_sql() . "
             LEFT JOIN patients p ON p.id = xr.patient_id
             WHERE xr.requested_by = :uid AND DATE(xr.created_at) BETWEEN :f AND :t
               AND COALESCE(xr.request_status, '') <> 'cancelled' AND COALESCE(xr.status, '') <> 'cancelled'"
               . ($typeKey ? ' AND xr.xray_type = :xt' : '') . "
             ORDER BY xr.created_at DESC", $p + ($typeKey ? ['xt' => $typeKey] : [])) as $r) {
            $rows[] = [$caseLink($r['full_name'] ?? '', $r['case_id']), $date($r['created_at']), DS_XRAY_TYPES[(string)$r['xray_type']] ?? (string)$r['xray_type'],
                (string)$r['request_number'], $m($r['final_amount']), $pay[(string)$r['payment_status']] ?? (string)$r['payment_status'], $m($r['received'])];
        }
        $note = 'داهاتی ئێکسڕەی هی سەنتەرە؛ تەنها بۆ زانیارییە.';
        break;

    case 'pharmacy':
        $title = 'ناردن بۆ دەرمانخانە';
        $cols = [['t' => 'نەخۆش'], ['t' => 'بەروار', 'n' => true], ['t' => 'دەرمان'], ['t' => 'دۆخ'], ['t' => 'پسووڵە', 'n' => true, 'sum' => true],
                 ['t' => 'وەرگیراو', 'n' => true, 'sum' => true]];
        if ($db->tableExists('erp2_prescriptions')) {
            $st = ['sold' => 'فرۆشراو', 'pending' => 'چاوەڕوان', 'outside' => 'لە دەرەوە'];
            foreach ($db->fetchAll(
                "SELECT p.full_name, rx.created_at, rx.status, rx.case_id,
                        (SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR '، ') FROM erp2_prescription_items i WHERE i.prescription_id = rx.id) AS items,
                        CASE WHEN rx.status = 'sold' THEN si.grand_total ELSE 0 END AS billed,
                        CASE WHEN rx.status = 'sold' THEN si.paid_amount ELSE 0 END AS received
                 FROM erp2_prescriptions rx
                 LEFT JOIN patients p ON p.id = rx.patient_id
                 LEFT JOIN erp2_sales_invoices si ON si.id = rx.invoice_id AND COALESCE(si.status, '') <> 'cancelled'
                 WHERE rx.doctor_id = :uid AND DATE(rx.created_at) BETWEEN :f AND :t AND COALESCE(rx.status, '') <> 'cancelled'
                 ORDER BY rx.created_at DESC", $p) as $r) {
                $rows[] = [$caseLink($r['full_name'] ?? '', $r['case_id']), $date($r['created_at']), (string)$r['items'],
                    $st[(string)$r['status']] ?? (string)$r['status'], $m($r['billed'] ?? 0), $m($r['received'] ?? 0)];
            }
        }
        $note = 'داهاتی دەرمانخانە هی سەنتەرە؛ تەنها بۆ زانیارییە.';
        break;

    case 'treatment':
        $title = 'جۆری ئیش — ' . $kindArg;
        $cols = [['t' => 'نەخۆش'], ['t' => 'بەروار', 'n' => true], ['t' => 'ددان'], ['t' => 'نرخ', 'n' => true, 'sum' => true],
                 ['t' => 'وەرگیراو', 'n' => true, 'sum' => true]];
        $lines = $db->fetchAll(
            "SELECT p.full_name, ct.case_id, ct.tooth_number, ct.final_price, ct.invoice_id, COALESCE(ct.performed_date, ct.created_at) AS at
             FROM case_treatments ct
             JOIN cases c ON c.id = ct.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE pf.doctor_id = :uid AND ct.treatment_name = :n
               AND DATE(COALESCE(ct.performed_date, ct.created_at)) BETWEEN :f AND :t
               AND (ct.status IS NULL OR ct.status <> 'cancelled')
             ORDER BY at DESC", $p + ['n' => $kindArg]);
        $cover = ds_invoice_coverage($db, array_column($lines, 'invoice_id'));
        foreach ($lines as $r) {
            $rows[] = [$caseLink($r['full_name'], $r['case_id']), $date($r['at']), (string)$r['tooth_number'], $m($r['final_price']),
                $m(ds_line_received((float)$r['final_price'], $cover[(int)$r['invoice_id']] ?? null))];
        }
        $note = 'وەرگیراو: بەشی ئەم ئیشە لەو پارەیەی تا ئێستا بۆ پسووڵەکەی هاتووە، بەپێی نرخەکەی.';
        break;

    case 'attendance':
        $title = 'هاتن و ڕۆیشتن';
        $cols = [['t' => 'بەروار', 'n' => true], ['t' => 'ڕۆژ'], ['t' => 'هاتن', 'n' => true], ['t' => 'ڕۆیشتن', 'n' => true],
                 ['t' => 'کات', 'n' => true], ['t' => 'سەرچاوە']];
        $days = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
        foreach (array_reverse($attendanceDays, true) as $d => $a) {
            $rows[] = [$d, $days[(int)date('w', strtotime($d))], $time($a['in']), $a['out'] ? $time($a['out']) : 'هێشتا لێرەیە',
                $a['minutes'] !== null ? dsHours($a['minutes']) : '—', $a['source'] === 'device' ? 'پەنجەمۆر' : 'دەستی (دوگمە)'];
        }
        break;

    case 'sessions':
        // time with patients: from the call in to the check out (the sheet's rule)
        $title = 'کاتی ئیش و پشکنین';
        $cols = [['t' => 'نەخۆش'], ['t' => 'بەروار', 'n' => true], ['t' => 'جۆر'], ['t' => 'بانگکرا', 'n' => true], ['t' => 'دەرچوو', 'n' => true],
                 ['t' => 'خولەک', 'n' => true, 'sum' => true]];
        foreach ($db->fetchAll(
            "SELECT p.full_name, wr.check_in_time, wr.visit_type, wr.called_time, wr.check_out_time,
                    TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) AS minutes
             FROM waiting_room wr LEFT JOIN patients p ON p.id = wr.patient_id
             WHERE wr.doctor_id = :uid AND DATE(wr.check_in_time) BETWEEN :f AND :t
               AND wr.called_time IS NOT NULL AND wr.check_out_time IS NOT NULL AND wr.check_out_time >= wr.called_time
             ORDER BY wr.called_time DESC", $p) as $r) {
            $rows[] = [(string)$r['full_name'], $date($r['check_in_time']), $r['visit_type'] === 'treatment' ? 'ئیش' : 'پشکنین',
                $time($r['called_time']), $time($r['check_out_time']), dsMoney($r['minutes'])];
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown list'], JSON_UNESCAPED_UNICODE);
        exit;
}

echo json_encode([
    'success' => true,
    'title' => $title,
    'period' => $rangeLabel,
    'cols' => $cols,
    'rows' => $rows,
    'note' => $note,
], JSON_UNESCAPED_UNICODE);
