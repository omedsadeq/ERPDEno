<?php
/**
 * Doctors General Financial Report — PDF
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$isAccountant = $auth->isAccountant();
$hasFullAccess = $isAdmin || $isReceptionist || $isAccountant;
$enforcedDoctor = $hasFullAccess ? null : ($auth->isDoctor() ? $currentUser['id'] : 0);

$today = date('Y-m-d');

// Same filter resolution
$mode       = $_GET['mode']          ?? 'month';
$fromIn     = $_GET['from']          ?? '';
$toIn       = $_GET['to']            ?? '';
$doctorIn   = $_GET['doctor_id']     ?? '';
$folderType = $_GET['folder_type']   ?? 'all';
$folderStat = $_GET['folder_status'] ?? 'all';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = '';
switch ($mode) {
    case 'today':     $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'quarter':
        $rangeFrom = date('Y-m-d', strtotime('-3 months +1 day')); $rangeTo = $today; $rangeLabel='سێ مانگی ڕابردوو'; break;
    case 'half_year':
        $rangeFrom = date('Y-m-d', strtotime('-6 months +1 day')); $rangeTo = $today; $rangeLabel='شەش مانگی ڕابردوو'; break;
    case 'year':
        $rangeFrom = date('Y-01-01'); $rangeTo = date('Y-12-31'); $rangeLabel='ئەمساڵ'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        } else { $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel='ئەم مانگە'; }
        break;
    case 'month':
    default: $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel='ئەم مانگە';
}
$activeDoctor = $enforcedDoctor !== null && $enforcedDoctor > 0 ? $enforcedDoctor : (is_numeric($doctorIn) ? intval($doctorIn) : null);

$validFolderTypes  = ['all','general','cosmetic','orthodontic','surgery','other'];
$validFolderStatus = ['all','active','archived'];
$folderType = in_array($folderType, $validFolderTypes, true) ? $folderType : 'all';
$folderStat = in_array($folderStat, $validFolderStatus, true) ? $folderStat : 'all';

$where  = "DATE(pf.created_at) BETWEEN :from AND :to";
$params = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($activeDoctor) { $where .= " AND pf.doctor_id = :doc"; $params['doc'] = $activeDoctor; }
if ($folderType !== 'all') { $where .= " AND pf.folder_type = :ftype"; $params['ftype'] = $folderType; }
if ($folderStat !== 'all') { $where .= " AND pf.status = :fstat"; $params['fstat'] = $folderStat; }

$sql = "SELECT u.id AS doctor_id,
               u.full_name AS doctor_name,
               u.room_number,
               COALESCE(u.commission_rate, 0) AS commission_rate,
               COUNT(DISTINCT pf.id) AS folder_count,
               COALESCE(SUM(pf.total_amount), 0)    AS total_amount,
               COALESCE(SUM(pf.discount_amount), 0) AS discount_amount,
               COALESCE(SUM(pf.paid_amount), 0)     AS paid_amount,
               COALESCE(SUM(pp.prior_paid), 0)      AS prior_paid,
               COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0) AS remaining
        FROM users u
        LEFT JOIN patient_folders pf ON u.id = pf.doctor_id AND {$where}
        LEFT JOIN (
            SELECT c.folder_id,
                   SUM(LEAST(GREATEST(0, ci.total_amount - ci.discount_amount), GREATEST(0, COALESCE(ci.prior_paid_amount, 0)))) AS prior_paid
            FROM case_invoices ci
            JOIN cases c ON c.id = ci.case_id
            WHERE ci.accounting_status = 'approved' AND ci.prior_paid_amount > 0
            GROUP BY c.folder_id
        ) pp ON pp.folder_id = pf.id
        WHERE u.role = 'doctor' AND u.is_active = 1";
if ($activeDoctor) $sql .= " AND u.id = :doc2";
$sql .= " GROUP BY u.id, u.full_name, u.room_number, u.commission_rate
          ORDER BY total_amount DESC, u.full_name";
$docParams = $params;
if ($activeDoctor) $docParams['doc2'] = $activeDoctor;
$rows = $db->fetchAll($sql, $docParams);

$totals = ['folders'=>0,'total'=>0.0,'discount'=>0.0,'paid'=>0.0,'remaining'=>0.0,'commission'=>0.0];
foreach ($rows as $r) {
    $totals['folders']    += (int)$r['folder_count'];
    $totals['total']      += (float)$r['total_amount'];
    $totals['discount']   += (float)$r['discount_amount'];
    $totals['paid']       += (float)$r['paid_amount'];
    $totals['remaining']  += (float)$r['remaining'];
    // دراوی پێشوو (historical cases) counts as paid but earns no commission.
    $totals['commission'] += max(0, (float)$r['paid_amount'] - (float)$r['prior_paid']) * ((float)$r['commission_rate'] / 100);
}
$collectionRate = $totals['total'] > 0 ? round($totals['paid'] / $totals['total'] * 100, 1) : 0;
$reportDate = date('Y/m/d'); $reportTime = date('H:i');

$ftypeLabels = ['all'=>'هەموو','general'=>'گشتی','cosmetic'=>'جوانکاری','orthodontic'=>'پەلکەزێرینە','surgery'=>'نەشتەرگەری','other'=>'تر'];
$fstatLabels = ['all'=>'هەموو','active'=>'کراوە','archived'=>'داخراو'];

$doctorFilterName = '';
if ($activeDoctor) {
    $dd = $db->fetch("SELECT full_name FROM users WHERE id = ?", [$activeDoctor]);
    $doctorFilterName = $dd['full_name'] ?? '';
}

$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 3px; font-size: 15px; }
    .meta { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
    .stats { margin: 0 auto 10px; border-collapse: collapse; width: 92%; }
    .stats td { border: 1px solid #ddd; text-align: center; padding: 5px; }
    .stats .v { font-size: 13px; font-weight: bold; }
    .stats .l { font-size: 9px; color: #666; margin-top: 2px; }
    table.t { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 8px; }
    table.t th { background: #eef2f7; padding: 6px 4px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 10px; }
    table.t td { padding: 5px 4px; border: 1px solid #e5e7eb; text-align: center; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .t-total    { color: #0f172a; font-weight: bold; }
    .t-discount { color: #ea580c; font-weight: bold; }
    .t-paid     { color: #15803d; font-weight: bold; }
    .t-remain   { color: #c62828; font-weight: bold; }
    .totals-row td { background: #eef2f7 !important; font-weight: bold; }
    .footer-note { text-align: center; font-size: 9px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی گشتی دکتۆرەکان — '. htmlspecialchars($rangeLabel) .'</h2>
<div class="meta">
    ماوە: '. htmlspecialchars($rangeFrom) .' بۆ '. htmlspecialchars($rangeTo) .'
    '. ($doctorFilterName ? ' &nbsp;|&nbsp; دکتۆر: د. ' . htmlspecialchars($doctorFilterName) : '') .'
    '. ($folderType !== 'all' ? ' &nbsp;|&nbsp; جۆری فایل: ' . htmlspecialchars($ftypeLabels[$folderType] ?? $folderType) : '') .'
    '. ($folderStat !== 'all' ? ' &nbsp;|&nbsp; دۆخ: ' . htmlspecialchars($fstatLabels[$folderStat] ?? $folderStat) : '') .'
    &nbsp;|&nbsp; بەرواری چاپ: '. $reportDate .' '. $reportTime .'
    &nbsp;|&nbsp; ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats">
    <tr>
        <td>
            <div class="v">'. number_format($totals['total']) .'</div>
            <div class="l">کۆی گشتی · '. $totals['folders'] .' فایل</div>
        </td>
        <td>
            <div class="v" style="color:#ea580c;">'. number_format($totals['discount']) .'</div>
            <div class="l">داشکاندن · '. ($totals['total'] > 0 ? round($totals['discount']/$totals['total']*100, 1) : 0) .'%</div>
        </td>
        <td>
            <div class="v" style="color:#15803d;">'. number_format($totals['paid']) .'</div>
            <div class="l">پارەی وەرگیراو · '. $collectionRate .'%</div>
        </td>
        <td>
            <div class="v" style="color:#c62828;">'. number_format($totals['remaining']) .'</div>
            <div class="l">ماوە (قەرز)</div>
        </td>
        <td>
            <div class="v" style="color:#7c3aed;">'. number_format($totals['commission']) .'</div>
            <div class="l">کۆمیسیۆن</div>
        </td>
    </tr>
</table>

<table class="t">
    <thead>
        <tr>
            <th style="width:3%">#</th>
            <th style="width:22%; text-align:start;">ناوی دکتۆر</th>
            <th style="width:6%">ژوور</th>
            <th style="width:8%">فایلەکان</th>
            <th style="width:12%">کۆی گشتی</th>
            <th style="width:12%">داشکاندن</th>
            <th style="width:12%">وەرگیراو</th>
            <th style="width:12%">ماوە</th>
            <th style="width:6%">ڕێژە</th>
            <th style="width:9%">کۆمیسیۆن</th>
        </tr>
    </thead>
    <tbody>';

if (empty($rows)) {
    $html .= '<tr><td colspan="10" style="text-align:center;padding:20px;color:#999;">هیچ تۆمارێک نەدۆزرایەوە</td></tr>';
} else {
    foreach ($rows as $i => $r) {
        $rate = (float)$r['total_amount'] > 0 ? round((float)$r['paid_amount'] / (float)$r['total_amount'] * 100, 1) : 0;
        $commission = max(0, (float)$r['paid_amount'] - (float)$r['prior_paid']) * ((float)$r['commission_rate'] / 100);
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>د. '. htmlspecialchars($r['doctor_name']) .'</strong></td>
            <td>'. htmlspecialchars($r['room_number'] ?: '—') .'</td>
            <td>'. (int)$r['folder_count'] .'</td>
            <td class="t-total">'. number_format($r['total_amount']) .'</td>
            <td class="t-discount">'. number_format($r['discount_amount']) .'</td>
            <td class="t-paid">'. number_format($r['paid_amount']) .'</td>
            <td class="t-remain">'. number_format($r['remaining']) .'</td>
            <td>'. $rate .'%</td>
            <td>'. number_format($commission) . ($r['commission_rate'] > 0 ? ' <small>('. $r['commission_rate'] .'%)</small>' : '') .'</td>
        </tr>';
    }
    $html .= '<tr class="totals-row">
        <td colspan="3" style="text-align:start; padding-inline-start:10px;">کۆی گشتی</td>
        <td>'. $totals['folders'] .'</td>
        <td class="t-total">'. number_format($totals['total']) .'</td>
        <td class="t-discount">'. number_format($totals['discount']) .'</td>
        <td class="t-paid">'. number_format($totals['paid']) .'</td>
        <td class="t-remain">'. number_format($totals['remaining']) .'</td>
        <td>'. $collectionRate .'%</td>
        <td>'. number_format($totals['commission']) .'</td>
    </tr>';
}

$html .= '</tbody></table>';
$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە سیستەمی '. APP_NAME .' دروستکراوە</div>';

$pdf = new InvoicePdfService([
    'orientation'      => 'L',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm'   => 10,
    'margin_right_mm'  => 10,
]);
$pdf->setContent($html);

$output = $_GET['output'] ?? 'I';
$filename = 'doctors-general-report-' . $rangeFrom . '_' . $rangeTo . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
