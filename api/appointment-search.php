<?php
/**
 * گەڕانی مەوعید — for reception on the phone: a patient's name, mobile or
 * code → every appointment they have, upcoming first (any day, not just the
 * one on screen), then the last few past ones.
 *
 * GET q (2+ characters). Name matching folds Arabic and Kurdish letters
 * (ي/ی، ك/ک، ه/ە، ر/ڕ …) so «فیصل» finds «فيصل»; each word must match. A
 * query that is mostly digits matches the mobile (0750… or 964750…) and the
 * patient code. A doctor (anyone without full access) sees only their own
 * appointments, as on appointments.php.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$hasFullAccess = $auth->isAdmin() || $auth->isReceptionist();
$onlyDoctor = $hasFullAccess ? 0 : (int)($_SESSION['user_id'] ?? 0);

/** Letters folded to one form, on both sides of the match. */
const APT_SEARCH_FOLD = [
    'ي' => 'ی', 'ى' => 'ی', 'ێ' => 'ی', 'ك' => 'ک', 'ة' => 'ە', 'ه' => 'ە', 'ھ' => 'ە',
    'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ڕ' => 'ر', 'ڵ' => 'ل', 'ۆ' => 'و', "\u{200C}" => '', 'ـ' => '',
];

function apt_search_fold(string $s): string
{
    return strtr(mb_strtolower($s, 'UTF-8'), APT_SEARCH_FOLD);
}

/** The same folding in SQL, on a column. */
function apt_search_fold_sql(string $col): string
{
    $sql = "LOWER($col)";
    foreach (APT_SEARCH_FOLD as $from => $to) {
        $sql = "REPLACE($sql, '" . $from . "', '" . $to . "')";
    }
    return $sql;
}

$q = trim((string)($_GET['q'] ?? ''));
// Kurdish / Arabic digits → 0-9
$q = strtr($q, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
$q = preg_replace('/\s+/u', ' ', $q);
if (mb_strlen($q) < 2 || mb_strlen($q) > 80) {
    echo json_encode(['success' => true, 'patients' => [], 'short' => true]);
    exit;
}

$digits = preg_replace('/\D/', '', $q);
$isNumber = strlen($digits) >= 3 && strlen($digits) >= mb_strlen(preg_replace('/[\s+\-()]/u', '', $q)) - 1;

$where = [];
$params = [];
if ($isNumber) {
    // 964750… / 00964750… / 750… all mean 0750…
    $phone = preg_replace('/^(00)?964/', '0', $digits);
    $phoneCol = "REPLACE(REPLACE(REPLACE(p.phone, ' ', ''), '-', ''), '+964', '0')";
    $where[] = "($phoneCol LIKE ? OR p.phone LIKE ? OR p.patient_code LIKE ?)";
    array_push($params, '%' . ltrim($phone, '0') . '%', '%' . $digits . '%', '%' . $digits . '%');
} else {
    $words = array_values(array_filter(explode(' ', apt_search_fold($q)), fn($w) => $w !== ''));
    $nameCol = apt_search_fold_sql('p.full_name');
    $nameParts = [];
    foreach (array_slice($words, 0, 5) as $w) {
        $nameParts[] = "$nameCol LIKE ?";
        $params[] = '%' . $w . '%';
    }
    $where[] = '((' . implode(' AND ', $nameParts) . ') OR p.patient_code LIKE ?)';
    $params[] = '%' . $q . '%';
}
if ($onlyDoctor) {
    $where[] = 'EXISTS (SELECT 1 FROM appointments ax WHERE ax.patient_id = p.id AND ax.doctor_id = ?)';
    $params[] = $onlyDoctor;
}

$today = date('Y-m-d');
$doctorSql = $onlyDoctor ? ' AND a.doctor_id = ' . $onlyDoctor : '';

// The patients: those with an appointment to come first, then by their latest one
$patients = $db->fetchAll(
    "SELECT p.id, p.full_name, p.patient_code, p.phone,
            (SELECT MIN(a.appointment_date) FROM appointments a
              WHERE a.patient_id = p.id AND a.appointment_date >= ? AND a.status NOT IN ('cancelled', 'rescheduled', 'no_show')$doctorSql) AS next_date,
            (SELECT MAX(a.appointment_date) FROM appointments a WHERE a.patient_id = p.id$doctorSql) AS last_date
     FROM patients p
     WHERE " . implode(' AND ', $where) . "
     ORDER BY next_date IS NULL, next_date, last_date IS NULL, last_date DESC, p.full_name
     LIMIT 15",
    array_merge([$today], $params)
);

$days = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
$statuses = [
    'scheduled' => ['داڕێژراو', '#ff8c00'], 'confirmed' => ['پشتڕاستکراو', '#0078d4'],
    'waiting' => ['چاوەڕوان', '#ff9800'], 'in_progress' => ['لە چارەسەردا', '#0078d4'],
    'completed' => ['تەواوبوو', '#4caf50'], 'cancelled' => ['هەڵوەشاوە', '#f44336'],
    'no_show' => ['نەهات', '#f44336'], 'rescheduled' => ['دواخراو', '#ff9800'],
];

/** «ئەمڕۆ», «سبەینێ», «٣ ڕۆژی تر», «دوێنێ», «١٢ ڕۆژ لەمەوبەر». */
$relative = static function (string $date) use ($today): string {
    $d = (int)round((strtotime($date) - strtotime($today)) / 86400);
    if ($d === 0) return 'ئەمڕۆ';
    if ($d === 1) return 'سبەینێ';
    if ($d === -1) return 'دوێنێ';
    return $d > 0 ? $d . ' ڕۆژی تر' : abs($d) . ' ڕۆژ لەمەوبەر';
};

$out = [];
foreach ($patients as $p) {
    $pid = (int)$p['id'];
    $rows = $db->fetchAll(
        "SELECT a.id, a.appointment_date, a.appointment_time, a.duration, a.status, a.notes, a.doctor_id,
                a.promised_amount, u.full_name AS doctor_name, wt.work_name
         FROM appointments a
         LEFT JOIN users u ON u.id = a.doctor_id
         LEFT JOIN work_types wt ON wt.id = a.work_type_id
         WHERE a.patient_id = ?$doctorSql
         ORDER BY a.appointment_date DESC, a.appointment_time DESC
         LIMIT 60",
        [$pid]
    );

    $phoneDigits = preg_replace('/\D/', '', (string)$p['phone']);
    $wa = $phoneDigits !== '' ? preg_replace('/^0/', '964', $phoneDigits) : '';

    $upcoming = [];
    $past = [];
    foreach ($rows as $r) {
        $ts = strtotime($r['appointment_date']);
        $time = date('H:i', strtotime((string)$r['appointment_time']));
        [$label, $colour] = $statuses[$r['status']] ?? [$r['status'], '#64748b'];
        $item = [
            'id' => (int)$r['id'],
            'date' => $r['appointment_date'],
            'day' => $days[(int)date('w', $ts)],
            'time' => $time,
            'when' => $relative($r['appointment_date']),
            'doctor' => $r['doctor_name'] ?? '',
            'doctorId' => (int)$r['doctor_id'],
            'work' => $r['work_name'] ?? '',
            'duration' => (int)$r['duration'],
            'notes' => (string)($r['notes'] ?? ''),
            'status' => $r['status'],
            'statusLabel' => $label,
            'statusColour' => $colour,
            'off' => in_array($r['status'], ['cancelled', 'rescheduled', 'no_show'], true),
        ];
        if ($r['appointment_date'] >= $today) {
            if ($wa !== '' && !$item['off']) {
                $text = 'سڵاو ' . $p['full_name'] . "،\n"
                    . 'ئاگادارت دەکەینەوە کە ڕۆژی ' . $item['day'] . ' ' . $r['appointment_date'] . ' کاتژمێر ' . $time
                    . ' مەوعیدت هەیە لای د. ' . $item['doctor'] . '.';
                if ((float)($r['promised_amount'] ?? 0) > 0) {
                    $text .= "\nتکایە بڕی " . number_format((float)$r['promised_amount']) . ' لەگەڵ خۆت بهێنە.';
                }
                $item['wa'] = 'https://wa.me/' . $wa . '?text=' . rawurlencode($text . "\nسوپاس.");
            }
            $upcoming[] = $item;
        } else {
            $past[] = $item;
        }
    }
    // the soonest first
    usort($upcoming, fn($a, $b) => [$a['date'], $a['time']] <=> [$b['date'], $b['time']]);

    $out[] = [
        'id' => $pid,
        'name' => $p['full_name'],
        'code' => $p['patient_code'] ?? '',
        'phone' => $p['phone'] ?? '',
        'upcoming' => $upcoming,
        'past' => array_slice($past, 0, 6),
        'pastCount' => count($past),
    ];
}

echo json_encode(['success' => true, 'patients' => $out, 'today' => $today], JSON_UNESCAPED_UNICODE);
