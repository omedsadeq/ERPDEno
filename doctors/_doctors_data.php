<?php
/**
 * دکتۆرەکان — access, filters and every doctor's figures for the chosen period.
 *
 * Shared by doctors/index.php (cards on screen) and doctors/index-pdf.php
 * (the same cards as an mPDF report), so the PDF is always exactly what the
 * page shows. Include it BEFORE any output: it checks login and permission and
 * redirects on failure.
 *
 * Each card's figures come from ds_compute_range() — the same function behind
 * doctors/daily-summary.php — so for any period a doctor's card equals their
 * daily summary opened for the same period.
 *
 * The outstanding debt line uses the accountant's own definition
 * (v_case_financial_summary, open cases with a positive balance — the same
 * query accountant/financial-summary.php totals for the whole clinic).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/_daily_summary_lib.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$permission->can('page.doctors')) {
    // A doctor without the list goes to their own profile; anyone else is refused.
    header('Location: ' . APP_URL . ($auth->isDoctor()
        ? '/doctors/profile.php?id=' . (int)$currentUser['id']
        : '/403.php'));
    exit;
}

// ── Filters ──────────────────────────────────────────────────────────
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = in_array($_GET['status'] ?? '', ['0', '1'], true) ? $_GET['status'] : '';
// The period: لە (from) … بۆ (to), this whole month by default. Both ends are
// validated dates (the old month value used to be pasted straight into the
// SQL), and old ?month= links still open that month.
[$from, $to] = ds_range_from_request($_GET, date('Y-m-01'), date('Y-m-t'));
[$prevFrom, $prevTo] = ds_shift_range($from, $to, -1);
[$nextFrom, $nextTo] = ds_shift_range($from, $to, +1);
$isDefaultRange = ($from === date('Y-m-01') && $to === date('Y-m-t'));
$rangeLabel = ds_range_label($from, $to);
$rangePresets = ds_range_presets();

// ── Doctors ──────────────────────────────────────────────────────────
// appointments.doctor_id → users.id (fk_appointments_doctor_id_user).
$sql = "SELECT u.id, u.full_name, u.specialization, u.is_active, u.profile_image,
               (SELECT COUNT(*) FROM appointments a
                 WHERE a.doctor_id = u.id AND a.appointment_date = CURDATE()
                   AND a.status IN ('scheduled', 'confirmed')) AS today_appointments
        FROM users u
        WHERE u.role = 'doctor'";
$params = [];
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE :s1 OR u.phone LIKE :s2 OR u.specialization LIKE :s3)";
    $params += ['s1' => "%{$search}%", 's2' => "%{$search}%", 's3' => "%{$search}%"];
}
if ($statusFilter !== '') {
    $sql .= " AND u.is_active = :st";
    $params['st'] = (int)$statusFilter;
}
$doctors = $db->fetchAll($sql, $params);

// Outstanding treatment debt per doctor, all time — the accountant's figure.
$debtByDoctor = [];
foreach ($db->fetchAll(
    "SELECT pf.doctor_id, COUNT(DISTINCT pf.patient_id) AS patients, COALESCE(SUM(fs.balance), 0) AS total
     FROM v_case_financial_summary fs
     JOIN cases c ON c.id = fs.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     WHERE fs.balance > 0 AND c.status <> 'closed'
     GROUP BY pf.doctor_id"
) as $r) {
    $debtByDoctor[(int)$r['doctor_id']] = $r;
}

// The period for each doctor, by the daily summary's rules.
$cards = [];
foreach ($doctors as $d) {
    $m = ds_compute_range($db, (int)$d['id'], $from, $to, false);
    $debt = $debtByDoctor[(int)$d['id']] ?? ['patients' => 0, 'total' => 0];
    $cards[] = $d + [
        'm'             => $m,
        'debt_patients' => (int)$debt['patients'],
        'debt_total'    => (float)$debt['total'],
        'paid_pct'      => $m['invoiceNet'] > 0 ? min(100, (int)round($m['treatmentCash'] / $m['invoiceNet'] * 100)) : 0,
    ];
}
// Active first, then the busiest period.
usort($cards, function ($a, $b) {
    return [(int)$b['is_active'], $b['m']['invoiceNet'], $a['full_name']]
       <=> [(int)$a['is_active'], $a['m']['invoiceNet'], $b['full_name']];
});

$totals = ['visits' => 0, 'work' => 0, 'exam' => 0, 'gross' => 0.0, 'disc' => 0.0, 'net' => 0.0,
           'received' => 0.0, 'left' => 0.0, 'oldDebt' => 0.0, 'debt' => 0.0];
foreach ($cards as $c) {
    $totals['visits']   += $c['m']['work']['total'];
    $totals['work']     += $c['m']['work']['treatment_visits'];
    $totals['exam']     += $c['m']['work']['checkup_visits'];
    $totals['gross']    += $c['m']['grossBilled'];
    $totals['disc']     += $c['m']['invoiceDisc'];
    $totals['net']      += $c['m']['invoiceNet'];
    $totals['received'] += $c['m']['treatmentCash'];
    $totals['left']     += $c['m']['newDebtToday'];
    $totals['oldDebt']  += $c['m']['oldDebtCollected'];
    $totals['debt']     += $c['debt_total'];
}
$clinicPct = $totals['net'] > 0 ? min(100, (int)round($totals['received'] / $totals['net'] * 100)) : 0;

/**
 * A card's money lines, top to bottom in the daily summary's order:
 * [label, value, icon, style] — style '' | net | pos | neg | debt.
 * The page and the PDF both print exactly this list.
 */
function dcMoneyList(array $c): array
{
    $m = $c['m'];
    return [
        ['کۆی گشتی پارە',        dsMoney($m['grossBilled']),      'fa-coins',                ''],
        ['داشکێنراو',             dsMoney($m['invoiceDisc']),      'fa-tags',                 'neg'],
        ['ماوەی دوای داشکێنراو', dsMoney($m['invoiceNet']),       'fa-wallet',               'net'],
        ['وەرگیراو',              dsMoney($m['treatmentCash']),    'fa-arrow-down',           'pos'],
        ['ماوە',                  dsMoney($m['newDebtToday']),     'fa-hourglass-half',       'neg'],
        ['گەڕاوەی قەرزی کۆن',     dsMoney($m['oldDebtCollected']), 'fa-undo-alt',             'pos'],
        ['قەرزی ماوە (' . dsMoney($c['debt_patients']) . ' نەخۆش)',
                                  dsMoney($c['debt_total']),       'fa-exclamation-triangle', 'debt'],
    ];
}

/** "د.هێرش حسن" → "ه" for the avatar. */
function dcInitial(string $name): string
{
    $clean = trim((string)preg_replace('/^\s*د\s*\.?\s*/u', '', $name));
    return mb_substr($clean !== '' ? $clean : $name, 0, 1);
}

// Each doctor keeps the same colour in every month, under every filter and in
// the PDF: the palette slot comes from their place among ALL doctor accounts,
// not from where they happen to land in today's sorted list.
$dcPalette = [
    ['#4f46e5', '#7c3aed', '#eef2ff'],   // indigo → violet
    ['#0284c7', '#06b6d4', '#e0f2fe'],   // sky → cyan
    ['#059669', '#10b981', '#ecfdf5'],   // emerald
    ['#db2777', '#f43f5e', '#fdf2f8'],   // pink → rose
    ['#ea580c', '#f59e0b', '#fff7ed'],   // orange → amber
    ['#7c3aed', '#c026d3', '#f5f3ff'],   // violet → fuchsia
    ['#0d9488', '#22c55e', '#f0fdfa'],   // teal → green
    ['#2563eb', '#4f46e5', '#eff6ff'],   // blue → indigo
];
$dcSlot = [];
foreach ($db->fetchAll("SELECT id FROM users WHERE role = 'doctor' ORDER BY id") as $i => $r) {
    $dcSlot[(int)$r['id']] = $i % count($dcPalette);
}
/** [colour 1, colour 2, soft tint] for a card; grey for an inactive doctor. */
function dcPaletteFor(array $c, array $palette, array $slots): array
{
    return !$c['is_active']
        ? ['#64748b', '#94a3b8', '#f1f5f9']
        : $palette[$slots[(int)$c['id']] ?? 0];
}
function dcColors(array $c, array $palette, array $slots): string
{
    [$c1, $c2, $soft] = dcPaletteFor($c, $palette, $slots);
    return "--c1:$c1;--c2:$c2;--soft:$soft";
}

$canDaily = $permission->can('page.doctor_report');
$qsBase = '&search=' . urlencode($search) . '&status=' . urlencode($statusFilter);
/** This page for another period, keeping the search/status filters. */
$rangeQs = fn(string $f, string $t) => '?from=' . urlencode($f) . '&to=' . urlencode($t) . $qsBase;
