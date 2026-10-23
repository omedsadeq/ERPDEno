<?php
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../includes/VisitReportStats.php';
require_once __DIR__ . '/../includes/VisitReferralReport.php';
require_once __DIR__ . '/../includes/VisitReportFilters.php';
$t = new TestCase();
$doctors = [1 => 'د.هێرش', 2 => 'د.شوان', 3 => 'د.دانا', 4 => 'د.ڕێناس', 5 => 'د.کاژین', 6 => 'لێزان'];
$users = [10 => 'نورالدین', 20 => 'چرا', 30 => 'نیمان'];
$visits = [];
$add = static function (string $designation, int $doctor, int $count, int $staff = 0) use (&$visits): void {
    $visits[] = ['referring_doctor_id' => 1, 'referring_doctor_designation' => $designation,
        'referring_doctor_choice_id' => $doctor, 'appointment_added_by' => $staff, 'count' => $count];
};
foreach ([4 => 4, 5 => 1] as $doctor => $count) { $add((string) $doctor, 0, $count); }
$add('DOCTORS', 2, 2, 10);
foreach ([2 => 3, 4 => 1, 5 => 1] as $doctor => $count) { $add('DOCTORS', $doctor, $count, 20); }
foreach ([2 => 6, 4 => 3, 5 => 1] as $doctor => $count) { $add('DOCTORS', $doctor, $count, 30); }
foreach ([2 => 1, 3 => 3, 4 => 1] as $doctor => $count) { $add('PATIENT', $doctor, $count); }
$report = dh_visit_referral_matrix($visits, 1, $doctors, $users);
$t->assertSame(27, $report['total'], 'Image grand total');
$t->assertSame([2 => 12, 3 => 3, 4 => 9, 5 => 3], $report['column_totals'], 'Image destination totals');
$t->assertSame(5, $report['rows']['doctor']['total'], 'Examiner distributed five');
$t->assertSame(2, $report['rows']['staff_10']['total'], 'First receptionist distributed two');
$t->assertSame(5, $report['rows']['staff_20']['total'], 'Second receptionist distributed five');
$t->assertSame(10, $report['rows']['staff_30']['total'], 'Third receptionist distributed ten');
$t->assertSame(5, $report['rows']['patient']['total'], 'Patients selected five');
$t->assertSame([2, 3, 4, 5], array_keys($report['doctors']), 'Examiner and clinic are absent from destination columns');
$checkTotals = static function (array $report) use ($t): void {
    $t->assertSame($report['total'], array_sum($report['column_totals']), 'Summary columns balance');
    $t->assertSame($report['total'], array_sum(array_column($report['rows'], 'total')), 'Chooser rows balance');
    foreach ($report['rows'] as $row) {
        $t->assertSame($row['total'], array_sum($row['doctors']), 'Chooser row cells balance');
    }
    foreach ($report['doctors'] as $id => $_name) {
        $sum = 0;
        foreach ($report['rows'] as $row) { $sum += $row['doctors'][$id] ?? 0; }
        $t->assertSame($report['column_totals'][$id], $sum, 'Chooser column matches summary');
    }
};
$checkTotals($report);
$edge = dh_visit_referral_matrix([
    ['id' => 1, 'referring_doctor_id' => 1, 'referring_doctor_designation' => 'DOCTORS'],
    ['id' => 2, 'referring_doctor_id' => 1, 'referring_doctor_designation' => '1'],
    ['id' => 3, 'referring_doctor_id' => 1, 'referring_doctor_designation' => '6'],
    ['id' => 4, 'referring_doctor_id' => 2, 'referring_doctor_designation' => '3'],
    ['id' => 5, 'referring_doctor_id' => 1, 'referring_doctor_designation' => 'DOCTORS', 'referring_doctor_choice_id' => 2],
    ['id' => 5, 'referring_doctor_id' => 1, 'referring_doctor_designation' => 'DOCTORS', 'referring_doctor_choice_id' => 2],
    ['id' => 6, 'referring_doctor_id' => 1, 'referring_doctor_designation' => 'PATIENT', 'appointment_doctor_id' => 3],
], 1, $doctors, $users);
$t->assertSame(2, $edge['total'], 'Each resolved referral counted once');
$t->assertSame(1, $edge['pending'], 'Unresolved receiving doctor remains pending');
$t->assertSame(1, $edge['self'], 'Examiner treating own patient stays separate');
$t->assertSame(1, $edge['excluded'], 'Clinic is not a treating-doctor destination');
$t->assertSame(1, $edge['rows']['staff_0']['total'], 'Missing receptionist remains visible');
$t->assertSame(1, $edge['rows']['patient']['total'], 'Explicit patient selection can use appointment doctor');
$checkTotals($edge);
$checkTotals(dh_visit_referral_matrix([], 1, $doctors, $users));
$zeroDoctor = dh_visit_referral_matrix([
    ['referring_doctor_id' => 1, 'referring_doctor_designation' => '2'],
], 1, $doctors, $users, [1, 2, 3, 6]);
$t->assertSame([2, 3], array_keys($zeroDoctor['doctors']), 'Active zero-count doctor retained; examiner and clinic excluded');
$t->assertSame(0, $zeroDoctor['column_totals'][3], 'Dana has an explicit zero without referrals');
$t->assertSame(1, $zeroDoctor['total'], 'Showing zero-count doctors does not change the grand total');
$t->assertTrue(strpos(dh_visit_referral_render($zeroDoctor, '2026-10', 'Reception'), '>د.دانا</th>') !== false, 'Dana is rendered in the table headers');
$historical = dh_visit_referral_matrix([
    ['referring_doctor_id' => 1, 'referring_doctor_designation' => '4'],
], 1, $doctors, $users, [1, 2, 3]);
$t->assertSame([2, 3, 4], array_keys($historical['doctors']), 'Inactive doctor with historical referrals retained');
$checkTotals($zeroDoctor);
$checkTotals($historical);
$allVisits = [
    ['id' => 10, 'referring_doctor_id' => 1, 'referring_doctor_designation' => '5', 'count' => 2],
    ['id' => 11, 'referring_doctor_id' => 3, 'referring_doctor_designation' => '7'],
    ['id' => 12, 'referring_doctor_id' => 3, 'referring_doctor_designation' => '7'],
    ['id' => 12, 'referring_doctor_id' => 3, 'referring_doctor_designation' => '7'],
    ['id' => 13, 'referring_doctor_id' => 3, 'referring_doctor_designation' => 'DOCTORS', 'referring_doctor_choice_id' => 7, 'appointment_added_by' => 10],
    ['id' => 14, 'referring_doctor_id' => 3, 'referring_doctor_designation' => '3'],
    ['id' => 15, 'referring_doctor_id' => 1, 'referring_doctor_designation' => '1'],
    ['id' => 16, 'referring_doctor_id' => 1, 'referring_doctor_designation' => 'DOCTORS'],
    ['id' => 17, 'referring_doctor_id' => 3, 'referring_doctor_designation' => 'PATIENT', 'referring_doctor_choice_id' => 2],
    ['id' => 18, 'referring_doctor_id' => 999, 'referring_doctor_designation' => '2'],
    ['id' => 19, 'referring_doctor_id' => 6, 'referring_doctor_designation' => '2'],
];
$allDoctors = $doctors + [7 => 'د.ڕدوان'];
$all = dh_visit_referral_matrix($allVisits, 0, $allDoctors, $users, [1, 2, 3, 5, 6, 7]);
$t->assertSame(6, $all['total'], 'All examiners include external referrals once');
$t->assertSame(2, $all['rows']['doctor_1']['doctors'][5], 'Hersh outgoing choices retain their own row');
$t->assertSame(2, $all['rows']['doctor_3']['doctors'][7], 'Dana outgoing two appear under Radwan');
$t->assertSame('د.دانا', $all['rows']['doctor_3']['name'], 'All-examiner doctor rows have the actual source name');
$t->assertSame(0, $all['column_totals'][3], 'Dana incoming zero is separate from her outgoing two');
$t->assertSame(3, $all['column_totals'][7], 'Radwan receives doctor choices and reception choices');
$t->assertSame(1, $all['rows']['staff_10']['total'], 'Reception choices remain reception choices across examiners');
$t->assertSame(1, $all['rows']['patient']['total'], 'Patient choices remain visible across examiners');
$t->assertSame(2, $all['self'], 'Self-treatment is excluded per visit examiner in all mode');
$t->assertSame(1, $all['pending'], 'Pending referrals remain separate in all mode');
$t->assertSame(2, $all['excluded'], 'Unknown and clinic examiners cannot become clinical referral rows');
$t->assertSame([1, 2, 3, 5, 7], array_keys($all['doctors']), 'All mode retains all active clinical receiving columns');
$checkTotals($all);
$checkTotals(dh_visit_referral_matrix([], 0, $allDoctors, $users));
$danaOnly = dh_visit_referral_matrix($allVisits, 3, $allDoctors, $users);
$t->assertSame(4, $danaOnly['total'], 'Single-examiner filtering still limits the referrals');
$t->assertSame(2, $danaOnly['rows']['doctor']['doctors'][7], 'Dana-only direct choices agree with all mode');
$t->assertSame(1, $danaOnly['self'], 'Single-examiner self-treatment stays separate');
$checkTotals($danaOnly);
$allHtml = dh_visit_referral_render($all, '2026-10', 'Reception');
$t->assertTrue(strpos($allHtml, 'هەموو دکتۆرە پشکنەرەکان') !== false, 'All-examiner scope is visible in the report and PDF title');
$t->assertTrue(strpos($allHtml, 'ڕیزەکان: کەسی دیاریکەر / ناردەر. ستوونەکان: دکتۆری وەرگر.') !== false, 'Row and column meanings are visible in the shared output');
$t->assertSame(4, dh_visit_referral_destination(['referring_doctor_designation' => 'د.ڕێناس'], $doctors), 'Historical exact doctor name resolves');
$t->assertSame(0, dh_visit_referral_destination(['referring_doctor_designation' => 'د.ڕێناس additional text'], $doctors), 'Partial doctor name does not misattribute');
$html = dh_visit_referral_render($report, '2025-10', '<Prepared by>');
$t->assertTrue(strpos($html, '&lt;Prepared by&gt;') !== false, 'Prepared-by name escaped');
$t->assertTrue(strpos($html, '>لێزان<') === false, 'Clinic column hidden');
$t->assertTrue(strpos($html, '<td>0</td>') !== false, 'Zero cells displayed');
$t->assertTrue(strpos($html, '12 + 3 + 9 + 3 = 27') !== false, 'Image calculation shown');
$filters = dh_visit_report_filters(['tab' => 'referrals', 'examiner_id' => '7', 'mode' => 'month', 'month' => '2025-10'], '2026-10-20');
$t->assertSame('referrals', $filters['tab'], 'Referral tab accepted');
$t->assertSame(7, $filters['examiner_id'], 'Examiner accepted');
$t->assertSame('2025-10-01', $filters['from'], 'Historical period preserved');
$t->assertSame('visits', dh_visit_report_filters(['tab' => ['referrals']])['tab'], 'Malformed tab falls back safely');
$t->assertSame(0, dh_visit_report_filters(['examiner_id' => '-7'])['examiner_id'], 'Invalid examiner rejected');

if (in_array('--database', $argv, true)) {
    define('MIGRATIONS_RUNNING', true);
    require_once __DIR__ . '/../config/config.php';
    $db = Database::getInstance();
    foreach ([0, 7, 9, 999999] as $id) {
        foreach (['visit_date', 'appointment_date'] as $dateFilter) {
            $query = dh_visit_report_filters(['mode' => 'month', 'month' => '2026-10', 'tab' => 'referrals', 'examiner_id' => (string) $id, 'date_filter' => $dateFilter]);
            $report = dh_visit_referral_report($db, $query);
            if ($report['examiner_id'] === 7) {
                $t->assertTrue(isset($report['doctors'][9]), 'Active Dana column appears in Hersh report');
            }
            $t->assertSame($id === 999999 ? 0 : $id, $report['examiner_id'], 'Examiner selection preserves valid ids and defaults to all');
            $t->assertSame('هەموو دکتۆرە پشکنەرەکان', $report['examiners'][0], 'All-examiner option is available');
            $checkTotals($report);
            $dateColumn = $dateFilter === 'appointment_date' ? 'appointment_date' : 'visit_date';
            $parameters = ['from' => $query['from'], 'to' => $query['to']];
            $condition = "TRIM(referring_doctor_id) REGEXP '^[0-9]+$' AND CAST(referring_doctor_id AS UNSIGNED)>0";
            if ($report['examiner_id'] > 0) {
                $condition = 'referring_doctor_id=:examiner';
                $parameters['examiner'] = (string) $report['examiner_id'];
            }
            $raw = $db->fetch("SELECT COUNT(*) count FROM visits WHERE visit_type='checkup' AND {$condition} AND {$dateColumn} BETWEEN :from AND :to", $parameters);
            $t->assertSame((int) $raw['count'], $report['total'] + $report['pending'] + $report['self'] + $report['excluded'], 'Every selected examination accounted for');
        }
    }
}
echo "{$t->assertions} assertions, {$t->failures} failures\n";
exit($t->failures ? 1 : 0);
