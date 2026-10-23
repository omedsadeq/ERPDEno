<?php
/**
 * Daily Doctor Report — PDF
 * ڕاپۆرتی ڕۆژانەی دکتۆر بە PDF
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

$db   = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$currentUser    = $auth->getCurrentUser();
$isAdmin        = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$isAccountant   = $auth->isAccountant();
$isDoctor       = $auth->isDoctor();
$hasFullAccess  = $isAdmin || $isReceptionist || $isAccountant;

$reportDate   = $_GET['date']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) $reportDate = date('Y-m-d');
$doctorFilter = $_GET['doctor'] ?? '';
if ($isDoctor && !$hasFullAccess) { $doctorFilter = $currentUser['id']; }

$dateStart = $reportDate . ' 00:00:00';
$dateEnd   = $reportDate . ' 23:59:59';

// Resolve doctor list
if ($doctorFilter) {
    $doctors = $db->fetchAll(
        "SELECT id, full_name, specialization, room_number FROM users WHERE id = :id AND role='doctor'",
        ['id' => intval($doctorFilter)]
    );
} else {
    $doctors = $db->fetchAll(
        "SELECT id, full_name, specialization, room_number FROM users WHERE role='doctor' AND is_active=1 ORDER BY full_name"
    );
}

$reportData = [];
$grand = ['folders'=>0,'cases'=>0,'treatments_count'=>0,'treatments_sum'=>0.0,'payments_sum'=>0.0,'patients'=>0,'total_amount'=>0.0,'discount'=>0.0];

foreach ($doctors as $doc) {
    $docId = $doc['id'];
    $p = ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd];

    $foldersCreated  = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) total, COALESCE(SUM(discount_amount),0) disc FROM patient_folders WHERE doctor_id=:doctor_id AND created_at BETWEEN :start AND :end", $p);
    $casesCreated    = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(c.total_amount),0) total, COALESCE(SUM(c.discount_amount),0) disc FROM cases c INNER JOIN patient_folders pf ON c.folder_id=pf.id WHERE pf.doctor_id=:doctor_id AND c.created_at BETWEEN :start AND :end", $p);
    $casesClosed     = $db->fetch("SELECT COUNT(*) c FROM cases c INNER JOIN patient_folders pf ON c.folder_id=pf.id WHERE pf.doctor_id=:doctor_id AND c.closed_at BETWEEN :start AND :end", $p);
    $payments        = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(pay.amount),0) total FROM payments pay INNER JOIN cases c ON pay.case_id=c.id INNER JOIN patient_folders pf ON c.folder_id=pf.id WHERE pf.doctor_id=:doctor_id AND pay.created_at BETWEEN :start AND :end AND pay.status='completed'", $p);
    $patientsSeen    = $db->fetch("SELECT COUNT(DISTINCT pf.patient_id) c FROM patient_folders pf WHERE pf.doctor_id=:doctor_id AND (pf.updated_at BETWEEN :start AND :end OR EXISTS (SELECT 1 FROM cases c WHERE c.folder_id=pf.id AND c.updated_at BETWEEN :start2 AND :end2))", array_merge($p, ['start2'=>$dateStart,'end2'=>$dateEnd]));
    $treatCount      = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(ct.final_price),0) total FROM case_treatments ct INNER JOIN cases c ON ct.case_id=c.id INNER JOIN patient_folders pf ON c.folder_id=pf.id WHERE pf.doctor_id=:doctor_id AND ct.created_at BETWEEN :start AND :end", $p);

    $treatments = $db->fetchAll(
        "SELECT ct.id, ct.final_price, ct.quantity, ct.created_at, ct.tooth_number,
                t.treatment_name, c.case_number, p.full_name AS patient_name
         FROM case_treatments ct
         INNER JOIN treatments t ON ct.treatment_id=t.id
         INNER JOIN cases c ON ct.case_id=c.id
         INNER JOIN patient_folders pf ON c.folder_id=pf.id
         INNER JOIN patients p ON pf.patient_id=p.id
         WHERE pf.doctor_id=:doctor_id AND ct.created_at BETWEEN :start AND :end
         ORDER BY ct.created_at DESC", $p);

    $dailyPayments = $db->fetchAll(
        "SELECT pay.id, pay.amount, pay.payment_method, pay.status, pay.created_at,
                c.case_number, p.full_name AS patient_name
         FROM payments pay
         INNER JOIN cases c ON pay.case_id=c.id
         INNER JOIN patient_folders pf ON c.folder_id=pf.id
         INNER JOIN patients p ON pf.patient_id=p.id
         WHERE pf.doctor_id=:doctor_id AND pay.created_at BETWEEN :start AND :end
         ORDER BY pay.created_at DESC", $p);

    $procedureLogs = $db->fetchAll(
        "SELECT apl.id, apl.custom_procedure_name, apl.arch_affected, apl.procedure_time, apl.price,
                apt.type_name, c.case_number, p.full_name AS patient_name
         FROM arch_procedure_logs apl
         LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id=apt.id
         INNER JOIN cases c ON apl.case_id=c.id
         INNER JOIN patient_folders pf ON c.folder_id=pf.id
         INNER JOIN patients p ON pf.patient_id=p.id
         WHERE pf.doctor_id=:doctor_id AND apl.procedure_date=:target_date
         ORDER BY apl.procedure_time DESC",
        ['doctor_id' => $docId, 'target_date' => $reportDate]);

    // Skip doctors with zero activity if reporting "all doctors"
    $hasActivity = (int)$foldersCreated['c'] + (int)$casesCreated['c'] + (int)$casesClosed['c']
                 + (int)$payments['c'] + (int)$patientsSeen['c'] + (int)$treatCount['c']
                 + count($procedureLogs);
    if (!$doctorFilter && $hasActivity === 0) continue;

    $reportData[] = [
        'doctor'        => $doc,
        'folders'       => $foldersCreated,
        'cases_created' => $casesCreated,
        'cases_closed'  => $casesClosed,
        'payments'      => $payments,
        'patients_seen' => $patientsSeen,
        'treat_count'   => $treatCount,
        'treatments'    => $treatments,
        'daily_payments'=> $dailyPayments,
        'procedure_logs'=> $procedureLogs,
    ];

    $grand['folders']          += (int)$foldersCreated['c'];
    $grand['cases']            += (int)$casesCreated['c'];
    $grand['treatments_count'] += (int)$treatCount['c'];
    $grand['treatments_sum']   += (float)$treatCount['total'];
    $grand['payments_sum']     += (float)$payments['total'];
    $grand['patients']         += (int)$patientsSeen['c'];
    $grand['total_amount']     += (float)$foldersCreated['total'];
    $grand['discount']         += (float)$foldersCreated['disc'];
}

// Kurdish day name
$daysKu = ['شەممە','یەکشەممە','دووشەممە','سێشەممە','چوارشەممە','پێنجشەممە','هەینی'];
$dayOfWeek = (int)date('w', strtotime($reportDate));
$dayOfWeek = ($dayOfWeek + 1) % 7; // shift so Sat=0
$dayName = $daysKu[$dayOfWeek];

$reportGenDate = date('Y/m/d'); $reportGenTime = date('H:i');
$currency = '';

$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 3px; font-size: 15px; }
    h3 { color: #0f172a; margin: 10px 0 4px; font-size: 12px; border-bottom: 2px solid #0078d4; padding-bottom: 3px; display: flex; justify-content: space-between; }
    h3 .room { background:rgba(56,189,248,.2); color:#0078d4; padding:1px 8px; border-radius:10px; font-size:10px; }
    .meta { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
    .stats { margin: 0 auto 8px; border-collapse: collapse; width: 100%; }
    .stats td { border: 1px solid #ddd; text-align: center; padding: 4px 5px; }
    .stats .v { font-size: 12px; font-weight: bold; }
    .stats .l { font-size: 8px; color: #666; margin-top: 2px; }
    .sub-h { color: #37474f; margin: 6px 0 3px; font-size: 10px; font-weight: 700; }
    table.t { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 5px; }
    table.t th { background: #eef2f7; padding: 4px 3px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 9px; }
    table.t td { padding: 3px; border: 1px solid #e5e7eb; text-align: center; vertical-align: middle; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .amt-green { color: #15803d; font-weight: bold; }
    .amt-red   { color: #c62828; font-weight: bold; }
    .empty { text-align: center; color: #999; font-size: 9px; font-style: italic; padding: 6px; }
    .doctor-block { page-break-inside: avoid; margin-bottom: 10px; }
    .footer-note { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی ڕۆژانەی دکتۆر — '. htmlspecialchars($dayName) .' '. htmlspecialchars($reportDate) .'</h2>
<div class="meta">
    '. ($doctorFilter && !empty($doctors[0]) ? 'دکتۆر: د. ' . htmlspecialchars($doctors[0]['full_name']) . ' &nbsp;|&nbsp; ' : '') .'
    بەرواری چاپ: '. $reportGenDate .' '. $reportGenTime .'
    &nbsp;|&nbsp; ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats">
    <tr>
        <td><div class="v">'. count($reportData) .'</div><div class="l">دکتۆری چالاک</div></td>
        <td><div class="v">'. (int)$grand['folders'] .'</div><div class="l">فایلی نوێ</div></td>
        <td><div class="v">'. (int)$grand['cases'] .'</div><div class="l">کەیسی نوێ</div></td>
        <td><div class="v">'. (int)$grand['patients'] .'</div><div class="l">نەخۆش</div></td>
        <td><div class="v">'. (int)$grand['treatments_count'] .'</div><div class="l">چارەسەرەکان</div></td>
        <td><div class="v amt-green">'. number_format($grand['payments_sum']) .' '. $currency .'</div><div class="l">پارەی وەرگیراو</div></td>
    </tr>
</table>';

if (empty($reportData)) {
    $html .= '<div class="empty">هیچ چالاکییەک بۆ ئەم ڕۆژە نەدۆزرایەوە</div>';
}

foreach ($reportData as $rd) {
    $doc = $rd['doctor'];
    $treatTotal = 0; $payTotal = 0; $procTotal = 0;
    foreach ($rd['treatments'] as $t) $treatTotal += (float)$t['final_price'];
    foreach ($rd['daily_payments'] as $p) $payTotal += (float)$p['amount'];
    foreach ($rd['procedure_logs'] as $pr) $procTotal += (float)$pr['price'];

    $html .= '<div class="doctor-block">';
    $html .= '<h3>
        <span><i class="fas fa-user-md"></i> د. '. htmlspecialchars($doc['full_name']) . ($doc['specialization'] ? ' — <small style="color:#64748b;font-weight:400;">'. htmlspecialchars($doc['specialization']) .'</small>' : '') .'</span>
        '. (!empty($doc['room_number']) ? '<span class="room">ژوور '. htmlspecialchars($doc['room_number']) .'</span>' : '') .'
    </h3>';

    // Per-doctor stat strip
    $html .= '<table class="stats" style="width:100%; margin-bottom:4px;">
        <tr>
            <td><div class="v">'. (int)$rd['folders']['c'] .'</div><div class="l">فایلی نوێ</div></td>
            <td><div class="v">'. (int)$rd['cases_created']['c'] .'</div><div class="l">کەیسی نوێ</div></td>
            <td><div class="v">'. (int)$rd['cases_closed']['c'] .'</div><div class="l">کەیسی داخراو</div></td>
            <td><div class="v">'. (int)$rd['patients_seen']['c'] .'</div><div class="l">نەخۆش</div></td>
            <td><div class="v">'. (int)$rd['treat_count']['c'] .'</div><div class="l">چارەسەر</div></td>
            <td><div class="v amt-green">'. number_format($rd['treat_count']['total']) .'</div><div class="l">نرخی چارەسەرەکان</div></td>
            <td><div class="v amt-green">'. number_format($rd['payments']['total']) .'</div><div class="l">وەرگیراو</div></td>
        </tr>
    </table>';

    // Treatments table
    $html .= '<div class="sub-h"><i class="fas fa-briefcase-medical"></i> چارەسەرەکان ('. count($rd['treatments']) .')</div>';
    if (empty($rd['treatments'])) {
        $html .= '<div class="empty">هیچ چارەسەرێک ئەمڕۆ تۆمار نەکراوە</div>';
    } else {
        $html .= '<table class="t"><thead><tr>
            <th style="width:3%">#</th>
            <th style="width:22%">نەخۆش</th>
            <th style="width:10%">کەیس</th>
            <th style="width:8%">ددان</th>
            <th style="width:25%">چارەسەر</th>
            <th style="width:7%">ژمارە</th>
            <th style="width:12%">نرخ</th>
            <th style="width:13%">کات</th>
        </tr></thead><tbody>';
        foreach ($rd['treatments'] as $i => $t) {
            $html .= '<tr>
                <td>'. ($i+1) .'</td>
                <td style="text-align:start;">'. htmlspecialchars($t['patient_name']) .'</td>
                <td>'. htmlspecialchars($t['case_number'] ?? '—') .'</td>
                <td>'. ($t['tooth_number'] ? htmlspecialchars($t['tooth_number']) : '—') .'</td>
                <td style="text-align:start;">'. htmlspecialchars($t['treatment_name']) .'</td>
                <td>'. (int)$t['quantity'] .'</td>
                <td class="amt-green">'. number_format($t['final_price']) .'</td>
                <td>'. date('H:i', strtotime($t['created_at'])) .'</td>
            </tr>';
        }
        $html .= '<tr style="background:#eef2f7;font-weight:bold;">
            <td colspan="6" style="text-align:center;">کۆی گشتی</td>
            <td class="amt-green">'. number_format($treatTotal) .'</td>
            <td></td>
        </tr>';
        $html .= '</tbody></table>';
    }

    // Procedure logs
    if (!empty($rd['procedure_logs'])) {
        $html .= '<div class="sub-h"><i class="fas fa-clipboard-list"></i> کارە تۆمارکراوەکان ('. count($rd['procedure_logs']) .')</div>';
        $html .= '<table class="t"><thead><tr>
            <th style="width:3%">#</th>
            <th style="width:22%">نەخۆش</th>
            <th style="width:10%">کەیس</th>
            <th style="width:25%">کار</th>
            <th style="width:15%">ناوچە</th>
            <th style="width:10%">کات</th>
            <th style="width:15%">نرخ</th>
        </tr></thead><tbody>';
        foreach ($rd['procedure_logs'] as $i => $pr) {
            $procName = $pr['custom_procedure_name'] ?: $pr['type_name'];
            $html .= '<tr>
                <td>'. ($i+1) .'</td>
                <td style="text-align:start;">'. htmlspecialchars($pr['patient_name']) .'</td>
                <td>'. htmlspecialchars($pr['case_number'] ?? '—') .'</td>
                <td style="text-align:start;">'. htmlspecialchars($procName ?? '—') .'</td>
                <td>'. htmlspecialchars($pr['arch_affected'] ?? '—') .'</td>
                <td>'. ($pr['procedure_time'] ? date('H:i', strtotime($pr['procedure_time'])) : '—') .'</td>
                <td class="amt-green">'. number_format($pr['price']) .'</td>
            </tr>';
        }
        $html .= '<tr style="background:#eef2f7;font-weight:bold;">
            <td colspan="6" style="text-align:center;">کۆی گشتی</td>
            <td class="amt-green">'. number_format($procTotal) .'</td>
        </tr>';
        $html .= '</tbody></table>';
    }

    // Payments table
    $html .= '<div class="sub-h"><i class="fas fa-money-bill-wave"></i> پارەدانەکان ('. count($rd['daily_payments']) .')</div>';
    if (empty($rd['daily_payments'])) {
        $html .= '<div class="empty">هیچ پارەیەک ئەمڕۆ وەرنەگیراوە</div>';
    } else {
        $payMethodLabels = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'تر'];
        $stLabels = ['completed' => 'تەواوبوو', 'pending' => 'چاوەڕوان', 'rejected' => 'ڕەتکراوە', 'cancelled' => 'هەڵوەشاوە'];
        $html .= '<table class="t"><thead><tr>
            <th style="width:3%">#</th>
            <th style="width:26%">نەخۆش</th>
            <th style="width:12%">کەیس</th>
            <th style="width:13%">جۆر</th>
            <th style="width:13%">دۆخ</th>
            <th style="width:15%">بڕ</th>
            <th style="width:18%">کات</th>
        </tr></thead><tbody>';
        foreach ($rd['daily_payments'] as $i => $p) {
            $html .= '<tr>
                <td>'. ($i+1) .'</td>
                <td style="text-align:start;">'. htmlspecialchars($p['patient_name']) .'</td>
                <td>'. htmlspecialchars($p['case_number'] ?? '—') .'</td>
                <td>'. htmlspecialchars($payMethodLabels[$p['payment_method']] ?? $p['payment_method']) .'</td>
                <td>'. htmlspecialchars($stLabels[$p['status']] ?? $p['status']) .'</td>
                <td class="amt-green">'. number_format($p['amount']) .'</td>
                <td>'. date('Y/m/d H:i', strtotime($p['created_at'])) .'</td>
            </tr>';
        }
        $html .= '<tr style="background:#eef2f7;font-weight:bold;">
            <td colspan="5" style="text-align:center;">کۆی گشتی</td>
            <td class="amt-green">'. number_format($payTotal) .'</td>
            <td></td>
        </tr>';
        $html .= '</tbody></table>';
    }

    $html .= '</div>'; // doctor-block
}

$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە سیستەمی '. APP_NAME .' دروستکراوە</div>';

$pdf = new InvoicePdfService([
    'orientation'      => 'L',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm'   => 8,
    'margin_right_mm'  => 8,
]);
$pdf->setContent($html);

$output = $_GET['output'] ?? 'I';
$filename = 'doctor-daily-report-' . $reportDate . ($doctorFilter ? '-' . $doctorFilter : '') . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
