<?php
/**
 * Treatments Analytics — PDF
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/services/InvoicePdfService.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$isAccountant = $auth->isAccountant();
$isDoctor = $auth->isDoctor();
$hasFullAccess = $isAdmin || $isReceptionist || $isAccountant;
$enforcedDoctor = $hasFullAccess ? null : ($isDoctor ? $currentUser['id'] : 0);

$today = date('Y-m-d');
$mode        = $_GET['mode']         ?? 'today';
$fromIn      = $_GET['from']         ?? '';
$toIn        = $_GET['to']           ?? '';
$doctorIn    = $_GET['doctor_id']    ?? '';
$patientIn   = $_GET['patient_id']   ?? '';
$categoryIn  = $_GET['category']     ?? 'all';
$treatmentIn = $_GET['treatment_id'] ?? '';
$statusIn    = $_GET['status']       ?? 'all';

$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = 'ئەمڕۆ';
switch ($mode) {
    case 'today':     $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'yesterday': $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day')); $rangeLabel = 'دوێنێ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'month':     $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel = 'ئەم مانگە'; break;
    case 'year':      $rangeFrom = date('Y-01-01'); $rangeTo = date('Y-12-31'); $rangeLabel = 'ئەمساڵ'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        }
        break;
}

$validCategories = ['all','examination','cleaning','filling','extraction','root_canal','crown','bridge','implant','orthodontic','whitening','cosmetic','surgery','other'];
$validStatuses   = ['all','pending','approved','completed','cancelled'];
$category = in_array($categoryIn, $validCategories, true) ? $categoryIn : 'all';
$status   = in_array($statusIn, $validStatuses, true) ? $statusIn : 'all';

$activeDoctor    = $enforcedDoctor ?: (is_numeric($doctorIn) ? intval($doctorIn) : null);
$activePatient   = is_numeric($patientIn) ? intval($patientIn) : null;
$activeTreatment = is_numeric($treatmentIn) ? intval($treatmentIn) : null;

$start = $rangeFrom . ' 00:00:00'; $end = $rangeTo . ' 23:59:59';
$where  = "ct.created_at BETWEEN :start AND :end";
$params = ['start' => $start, 'end' => $end];
if ($activeDoctor)    { $where .= " AND pf.doctor_id = :doc";    $params['doc']  = $activeDoctor; }
if ($activePatient)   { $where .= " AND p.id = :pat";             $params['pat']  = $activePatient; }
if ($activeTreatment) { $where .= " AND ct.treatment_id = :tid";  $params['tid']  = $activeTreatment; }
if ($category !== 'all') { $where .= " AND t.category = :cat";    $params['cat']  = $category; }
if ($status !== 'all')   { $where .= " AND ct.status = :st";      $params['st']   = $status; }

$joins = "
    FROM case_treatments ct
    INNER JOIN cases c              ON ct.case_id    = c.id
    INNER JOIN patient_folders pf   ON c.folder_id   = pf.id
    INNER JOIN patients p           ON pf.patient_id = p.id
    LEFT  JOIN users u              ON pf.doctor_id  = u.id
    LEFT  JOIN treatments t         ON ct.treatment_id = t.id
";

$summary = $db->fetch(
    "SELECT COUNT(*) total, COALESCE(SUM(ct.final_price),0) revenue,
            COALESCE(SUM(ct.discount),0) discount,
            COUNT(DISTINCT p.id) patients, COUNT(DISTINCT pf.doctor_id) doctors,
            COUNT(DISTINCT ct.treatment_id) distinct_tx
     $joins WHERE $where", $params);

$total = (int)$summary['total']; $revenue = (float)$summary['revenue']; $discount = (float)$summary['discount'];
$pct = fn($n) => $total > 0 ? round($n / $total * 100, 1) : 0;

$byTreatment = $db->fetchAll(
    "SELECT COALESCE(t.treatment_name, ct.treatment_name) name, t.category,
            COUNT(*) cnt, COALESCE(SUM(ct.final_price),0) rev, COALESCE(AVG(ct.final_price),0) avg_p
     $joins WHERE $where
     GROUP BY ct.treatment_id, name, t.category ORDER BY cnt DESC, rev DESC", $params);

$byCategory = $db->fetchAll(
    "SELECT COALESCE(t.category,'other') cat, COUNT(*) cnt, COALESCE(SUM(ct.final_price),0) rev
     $joins WHERE $where GROUP BY cat ORDER BY cnt DESC", $params);

$byDoctor = $db->fetchAll(
    "SELECT u.full_name dname, COUNT(*) cnt, COUNT(DISTINCT p.id) pts, COALESCE(SUM(ct.final_price),0) rev
     $joins WHERE $where GROUP BY pf.doctor_id, u.full_name ORDER BY cnt DESC", $params);

$byPatient = $db->fetchAll(
    "SELECT p.full_name, p.patient_code, COUNT(*) cnt, COALESCE(SUM(ct.final_price),0) rev
     $joins WHERE $where GROUP BY p.id, p.full_name, p.patient_code
     ORDER BY cnt DESC, rev DESC LIMIT 15", $params);

$categoryLabels = [
    'examination'=>'پشکنین','cleaning'=>'پاککردنەوە','filling'=>'پڕکردنەوە',
    'extraction'=>'دەرهێنان','root_canal'=>'گەڕی ڕەگ','crown'=>'تاج','bridge'=>'پرد',
    'implant'=>'چاندن','orthodontic'=>'پەلکەزێرینە','whitening'=>'سپیکردنەوە',
    'cosmetic'=>'جوانکاری','surgery'=>'نەشتەرگەری','other'=>'تر',
];
$categoryColors = [
    'examination'=>'#0ea5e9','cleaning'=>'#06b6d4','filling'=>'#16a34a',
    'extraction'=>'#dc2626','root_canal'=>'#f59e0b','crown'=>'#7c3aed',
    'bridge'=>'#0891b2','implant'=>'#ea580c','orthodontic'=>'#be185d',
    'whitening'=>'#84cc16','cosmetic'=>'#e11d48','surgery'=>'#991b1b','other'=>'#64748b',
];
$statusLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەند','completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە'];

// Meta labels
$metaParts = [];
if ($activeDoctor) { $dd = $db->fetch("SELECT full_name FROM users WHERE id=?", [$activeDoctor]); if ($dd) $metaParts[] = 'دکتۆر: د. ' . $dd['full_name']; }
if ($activePatient) { $pp = $db->fetch("SELECT full_name FROM patients WHERE id=?", [$activePatient]); if ($pp) $metaParts[] = 'نەخۆش: ' . $pp['full_name']; }
if ($category !== 'all') $metaParts[] = 'جۆر: ' . ($categoryLabels[$category] ?? $category);
if ($activeTreatment) { $tt = $db->fetch("SELECT treatment_name FROM treatments WHERE id=?", [$activeTreatment]); if ($tt) $metaParts[] = 'چارەسەر: ' . $tt['treatment_name']; }
if ($status !== 'all') $metaParts[] = 'دۆخ: ' . ($statusLabels[$status] ?? $status);

$reportGenDate = date('Y/m/d'); $reportGenTime = date('H:i');

$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 3px; font-size: 15px; }
    h3 { color: #0f172a; margin: 10px 0 4px; font-size: 12px; border-bottom: 2px solid #16a34a; padding-bottom: 3px; }
    .meta { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
    .filters-meta { text-align: center; font-size: 9px; color: #0078d4; margin-bottom: 6px; background: #f0f7ff; padding: 4px; border-radius: 4px; }
    .stats { margin: 0 auto 8px; border-collapse: collapse; width: 100%; }
    .stats td { border: 1px solid #ddd; text-align: center; padding: 5px; }
    .stats .v { font-size: 13px; font-weight: bold; }
    .stats .l { font-size: 9px; color: #666; margin-top: 2px; }
    .two-col { width: 100%; border-collapse: collapse; }
    .two-col > tbody > tr > td { padding: 0 4px; vertical-align: top; width: 50%; }
    table.t { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 5px; }
    table.t th { background: #eef2f7; padding: 4px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 9px; }
    table.t td { padding: 3px 4px; border: 1px solid #e5e7eb; text-align: center; vertical-align: middle; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .cb { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; color: #fff; }
    .v-rev { color: #15803d; font-weight: bold; }
    .v-disc { color: #ea580c; font-weight: bold; }
    .totals-row td { background: #eef2f7 !important; font-weight: bold; }
    .empty { text-align: center; color: #999; font-size: 9px; font-style: italic; padding: 6px; }
    .footer-note { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی شیکاری چارەسەرەکان — '. htmlspecialchars($rangeLabel) .'</h2>
<div class="meta">
    ماوە: '. htmlspecialchars($rangeFrom) .' بۆ '. htmlspecialchars($rangeTo) .'
    &nbsp;|&nbsp; بەرواری چاپ: '. $reportGenDate .' '. $reportGenTime .'
    &nbsp;|&nbsp; ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>';

if (!empty($metaParts)) {
    $html .= '<div class="filters-meta"><strong>فلتەر:</strong> ' . htmlspecialchars(implode(' · ', $metaParts)) . '</div>';
}

$html .= '<table class="stats">
    <tr>
        <td><div class="v">'. number_format($total) .'</div><div class="l">کۆی چارەسەرەکان · '. (int)$summary['distinct_tx'] .' جۆر</div></td>
        <td><div class="v v-rev">'. number_format($revenue) .'</div><div class="l">کۆی داهات</div></td>
        <td><div class="v">'. number_format($total > 0 ? $revenue/$total : 0) .'</div><div class="l">ناوەندی نرخ</div></td>
        <td><div class="v">'. number_format((int)$summary['patients']) .'</div><div class="l">نەخۆشی جیاواز</div></td>
        <td><div class="v">'. number_format((int)$summary['doctors']) .'</div><div class="l">دکتۆر</div></td>
        <td><div class="v v-disc">'. number_format($discount) .'</div><div class="l">داشکاندن</div></td>
    </tr>
</table>';

// Two-column: categories + doctors
$html .= '<table class="two-col"><tr><td>';
$html .= '<h3>بەپێی جۆر ('. count($byCategory) .')</h3>';
if (empty($byCategory)) { $html .= '<div class="empty">هیچ تۆمارێک نییە</div>'; }
else {
    $html .= '<table class="t"><thead><tr><th>#</th><th>جۆر</th><th>ژمارە</th><th>داهات</th><th>ڕێژە</th></tr></thead><tbody>';
    foreach ($byCategory as $i => $r) {
        $color = $categoryColors[$r['cat']] ?? '#64748b';
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><span class="cb" style="background:'. $color .'">'. ($categoryLabels[$r['cat']] ?? $r['cat']) .'</span></td>
            <td><strong>'. $r['cnt'] .'</strong></td>
            <td class="v-rev">'. number_format($r['rev']) .'</td>
            <td>'. $pct($r['cnt']) .'%</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}
$html .= '</td><td>';
$html .= '<h3>دکتۆرەکان ('. count($byDoctor) .')</h3>';
if (empty($byDoctor)) { $html .= '<div class="empty">هیچ تۆمارێک نییە</div>'; }
else {
    $html .= '<table class="t"><thead><tr><th>#</th><th>دکتۆر</th><th>چارەسەر</th><th>نەخۆش</th><th>داهات</th></tr></thead><tbody>';
    foreach ($byDoctor as $i => $r) {
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>د. '. htmlspecialchars($r['dname'] ?? '—') .'</strong></td>
            <td>'. $r['cnt'] .'</td>
            <td>'. $r['pts'] .'</td>
            <td class="v-rev">'. number_format($r['rev']) .'</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}
$html .= '</td></tr></table>';

// Two-column: treatments + top patients
$html .= '<table class="two-col"><tr><td>';
$html .= '<h3>بەپێی ناوی چارەسەر ('. count($byTreatment) .')</h3>';
if (empty($byTreatment)) { $html .= '<div class="empty">هیچ تۆمارێک نییە</div>'; }
else {
    $html .= '<table class="t"><thead><tr><th>#</th><th>چارەسەر</th><th>ژمارە</th><th>داهات</th><th>ناوەند</th></tr></thead><tbody>';
    $sumT = 0; $sumR = 0;
    foreach (array_slice($byTreatment, 0, 30) as $i => $r) {
        $sumT += $r['cnt']; $sumR += $r['rev'];
        $color = $r['category'] ? ($categoryColors[$r['category']] ?? '#64748b') : '#64748b';
        $cat = $r['category'] ? '<span class="cb" style="background:'. $color .'; font-size:7px;">'. ($categoryLabels[$r['category']] ?? $r['category']) .'</span> ' : '';
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;">'. $cat . htmlspecialchars($r['name']) .'</td>
            <td><strong>'. $r['cnt'] .'</strong></td>
            <td class="v-rev">'. number_format($r['rev']) .'</td>
            <td>'. number_format($r['avg_p']) .'</td>
        </tr>';
    }
    $html .= '<tr class="totals-row"><td colspan="2">کۆی گشتی</td><td>'. $sumT .'</td><td class="v-rev">'. number_format($sumR) .'</td><td></td></tr>';
    $html .= '</tbody></table>';
}
$html .= '</td><td>';
$html .= '<h3>نەخۆشە بەرزەکان ('. count($byPatient) .')</h3>';
if (empty($byPatient)) { $html .= '<div class="empty">هیچ تۆمارێک نییە</div>'; }
else {
    $html .= '<table class="t"><thead><tr><th>#</th><th>نەخۆش</th><th>چارەسەر</th><th>داهات</th></tr></thead><tbody>';
    foreach ($byPatient as $i => $r) {
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($r['full_name']) .'</strong> <small style="color:#888;">'. htmlspecialchars($r['patient_code']) .'</small></td>
            <td><strong>'. $r['cnt'] .'</strong></td>
            <td class="v-rev">'. number_format($r['rev']) .'</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}
$html .= '</td></tr></table>';

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
$filename = 'treatments-report-' . $rangeFrom . ($rangeFrom !== $rangeTo ? '_' . $rangeTo : '') . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
