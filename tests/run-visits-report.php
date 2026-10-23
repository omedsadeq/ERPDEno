<?php
// Pure report checks. --database adds read-only checks against the configured DB.
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../includes/VisitReportStats.php';
require_once __DIR__ . '/../includes/VisitReportFilters.php';
$t = new TestCase();

$doctors = [1 => 'د.هێرش', 2 => 'د.شوان', 3 => 'د.دانا', 4 => 'د.ڕێناس', 5 => 'د.کازین'];
$requested = [174, 22, 110, 68, 21];
$reception = [[1, 0, 1, 2, 1], [0, 1, 9, 6, 7], [1, 3, 8, 15, 4]];
$new = [96, 15, 76, 48, 18];
$groups = [];
foreach ($doctors as $id => $_name) {
    $remainingNew = $new[$id - 1];
    // Split the image's counts into disjoint new/old groups.
    $counts = [0 => $requested[$id - 1], 10 => $reception[0][$id - 1], 20 => $reception[1][$id - 1], 30 => $reception[2][$id - 1]];
    foreach ($counts as $staffId => $count) {
        $newCount = min($remainingNew, $count);
        $remainingNew -= $newCount;
        foreach (['new' => $newCount, 'old' => $count - $newCount] as $status => $amount) {
            if ($amount > 0) {
                $groups[] = ['doctor_id' => $id, 'requested_doctor_id' => $staffId === 0 ? $id : ($id % count($doctors)) + 1, 'assigned_doctor_id' => $id, 'patient_status' => $status, 'receptionist_id' => $staffId, 'count' => $amount];
            }
        }
    }
}
$matrix = dh_visit_report_build_matrix($groups, $doctors, [10 => 'نورالدین', 20 => 'چرا', 30 => 'نیمان']);
$t->assertSame(395, $matrix['doctor_totals']['requested'], 'Image requested total');
$t->assertSame(59, $matrix['doctor_totals']['reception'], 'Image reception total');
$t->assertSame(454, $matrix['overall']['total'], 'Image grand total');
$t->assertSame(253, $matrix['overall']['new_total'], 'Image new total');
$t->assertSame(201, $matrix['overall']['old_total'], 'Image returning total');
$t->assertSame([176, 26, 128, 91, 33], array_values(array_column($matrix['doctor_stats'], 'assigned')), 'Image doctor totals');
$t->assertSame([5, 23, 31], array_map(static function ($id) use ($matrix) { return $matrix['receptionist_redirections'][$id]['total']; }, [10, 20, 30]), 'Image staff totals');
$t->assertSame([2, 4, 18, 23, 12], array_values($matrix['receptionist_redirection_column_totals']), 'Image staff columns');

$checkBalance = static function (array $stats) use ($t): void {
    $total = 0;
    foreach ($stats['doctor_stats'] as $id => $doctor) {
        $t->assertSame($doctor['assigned'], $doctor['requested'] + $doctor['reception'] + $doctor['other'] + $doctor['unassigned'], 'Doctor allocation balance');
        $t->assertSame($doctor['assigned'], $doctor['new'] + $doctor['old'] + $doctor['unknown'], 'Doctor status balance');
        $t->assertSame($doctor['reception'], $stats['receptionist_redirection_column_totals'][$id], 'Staff column matches reception');
        $total += $doctor['assigned'];
    }
    $t->assertSame($stats['overall']['total'], $total, 'Columns match grand total');
    $t->assertSame($stats['doctor_totals']['reception'], array_sum(array_column($stats['receptionist_redirections'], 'total')), 'Staff rows match reception');
    foreach ($stats['receptionist_redirections'] as $row) {
        $t->assertSame($row['total'], array_sum($row['doctors']), 'Staff row balances');
    }
};
$checkBalance($matrix);
$edge = dh_visit_report_build_matrix([
    ['doctor_id' => 2, 'requested_doctor_id' => 1, 'assigned_doctor_id' => 2, 'patient_status' => 'old', 'receptionist_id' => 10, 'count' => 2],
    ['doctor_id' => 1, 'requested_doctor_id' => 1, 'patient_status' => 'new', 'receptionist_id' => 10, 'count' => 3],
    ['doctor_id' => 0, 'requested_doctor_id' => null, 'patient_status' => null, 'receptionist_id' => null, 'count' => 1],
    ['doctor_id' => 99, 'requested_doctor_id' => null, 'patient_status' => 'new', 'receptionist_id' => 999, 'count' => 1],
    ['doctor_id' => 2, 'requested_doctor_id' => 1, 'assigned_doctor_id' => 2, 'patient_status' => 'new', 'receptionist_id' => null, 'count' => 1],
    ['doctor_id' => 2, 'requested_doctor_id' => null, 'assigned_doctor_id' => 2, 'patient_status' => 'new', 'receptionist_id' => 10, 'count' => 1],
], [1 => '<script>alert(1)</script>', 2 => 'Doctor B'], []);
$t->assertSame(0, $edge['doctor_stats'][1]['old'], 'Transferred visit leaves original doctor');
$t->assertSame(2, $edge['doctor_stats'][2]['old'], 'Transferred visit belongs to destination');
$t->assertSame(2, $edge['receptionist_redirections'][10]['total'], 'Transfer credited to recorded receptionist');
$t->assertSame(2, $edge['doctor_totals']['reception'], 'Only recorded receptionist transfers count');
$t->assertSame(3, $edge['doctor_totals']['other'], 'Missing request, clinical-only and missing staff stay separate');
$t->assertSame(1, $edge['doctor_totals']['unassigned'], 'Unassigned visit remains counted');
$t->assertSame(1, $edge['doctor_totals']['unknown'], 'Unknown status is not returning');
$checkBalance($edge);
$rendered = dh_visit_report_render_matrix($edge, '<month>', 'Checkup');
$t->assertTrue(strpos($rendered, '<script>') === false && strpos($rendered, '&lt;script&gt;') !== false, 'Names are HTML escaped');
$t->assertTrue(strpos($rendered, '&lt;month&gt;') !== false, 'Period is HTML escaped');
$t->assertTrue(strpos($rendered, '<td>0</td>') !== false, 'Zero cells remain explicit');
$t->assertTrue(strpos($rendered, 'گواستنەوەی پێشوازی') !== false, 'Reception row uses established transfer label');
$checkBalance(dh_visit_report_build_matrix([], $doctors, []));

$month = dh_visit_report_filters(['mode' => 'month', 'month' => '2025-10'], '2026-10-20');
$t->assertSame('2025-10-01', $month['from'], 'Historical month start');
$t->assertSame('2025-10-31', $month['to'], 'Historical month end');
$t->assertSame('2024-02-29', dh_visit_report_filters(['mode' => 'month', 'month' => '2024-02'], '2026-10-20')['to'], 'Leap month');
$range = dh_visit_report_filters(['mode' => 'range', 'from' => '2025-11-03', 'to' => '2025-10-01'], '2026-10-20');
$t->assertSame('2025-10-01', $range['from'], 'Reversed range start');
$t->assertSame('2025-11-03', $range['to'], 'Reversed range end');
$t->assertSame('today', dh_visit_report_filters(['mode' => 'range', 'from' => '2025-02-31', 'to' => '2025-03-01'], '2026-10-20')['mode'], 'Impossible dates rejected');
$t->assertSame('2026-10', dh_visit_report_filters(['mode' => 'month', 'month' => '2025-13'], '2026-10-20')['month'], 'Invalid month fallback');
$week = dh_visit_report_filters(['mode' => 'week'], '2026-10-20');
$t->assertSame('2026-10-17', $week['from'], 'Week starts Saturday');
$t->assertSame('2026-10-23', $week['to'], 'Week ends Friday');
$t->assertSame('2026-10-19', dh_visit_report_filters(['mode' => 'yesterday'], '2026-10-20')['from'], 'Yesterday');
$t->assertSame('checkup', dh_visit_report_filters(['visit_type' => ['treatment']], '2026-10-20')['visit_type'], 'Array input handled');

if (in_array('--database', $argv, true)) {
    define('MIGRATIONS_RUNNING', true);
    require_once __DIR__ . '/../config/config.php';
    $db = Database::getInstance();
    foreach ([['2025-10-01', '2025-10-31'], ['2026-10-01', '2026-10-31'], ['2099-01-01', '2099-01-31']] as [$from, $to]) {
        foreach (['checkup', 'treatment'] as $type) {
            foreach (['visit_date', 'appointment_date'] as $dateFilter) {
                $stats = dh_visit_report_stats($db, $from, $to, $dateFilter, $type);
                $clinical = $type === 'treatment' ? 'treatment_doctor_id' : 'checkup_doctor_id';
                $raw = $db->fetch("SELECT COUNT(*) total, COALESCE(SUM(patient_status='new'), 0) new_total, COALESCE(SUM(patient_status='old'), 0) old_total, COALESCE(SUM(receptionist_id > 0 AND ((doctor_assignment_source='reception' AND COALESCE(assigned_doctor_id, {$clinical}, requested_doctor_id, 0) > 0) OR (requested_doctor_id > 0 AND assigned_doctor_id > 0 AND requested_doctor_id <> assigned_doctor_id))), 0) reception_total FROM visits WHERE visit_date BETWEEN :from AND :to AND visit_type=:visit_type", ['from' => $from, 'to' => $to, 'visit_type' => $type]);
                foreach (['total', 'new_total', 'old_total'] as $key) {
                    $t->assertSame((int) $raw[$key], $stats['overall'][$key], 'Database ' . $key);
                }
                $t->assertSame((int) $raw['reception_total'], $stats['doctor_totals']['reception'], 'Reception matches existing transfer query');
                $checkBalance($stats);
                $t->assertSame($stats['appointment_redirection_total'], array_sum(array_column($stats['appointment_redirections'], 'total')), 'Appointment rows balance');
            }
        }
    }
}
echo "{$t->assertions} assertions, {$t->failures} failures\n";
exit($t->failures ? 1 : 0);
