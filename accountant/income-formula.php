<?php
/**
 * فۆرمولی تایبەتی پشکی دکتۆر — what the income sheet's «fx» dialog talks to
 * (accountant/income.php, classes/IncomeFormula.php). Admin only, POST only:
 *
 *   action=preview  doctor_id, formula, from, to, xs + x[] (the sheet's chips)
 *                   → the share the formula gives on that period's figures,
 *                     the centre's, and what the sheet's own rule would give
 *   action=save     doctor_id, formula ('' takes it away)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Csrf.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/IncomeFormula.php';
require_once __DIR__ . '/../doctors/_daily_summary_lib.php';

header('Content-Type: application/json; charset=utf-8');
$out = static function (array $o, int $code = 200): void {
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
};

$auth = new Auth();
$auth->requireLogin();
if (!$auth->isAdmin()) {
    $out(['ok' => false, 'error' => 'تەنها بەڕێوەبەر فۆرمول دادەنێت'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(['ok' => false, 'error' => 'POST'], 405);
}
Csrf::require();

$db = Database::getInstance();
$doctorId = (int)($_POST['doctor_id'] ?? 0);
$doctor = $doctorId > 0 ? $db->fetch("SELECT id, full_name FROM users WHERE id = ? AND role = 'doctor'", [$doctorId]) : null;
if (!$doctor) {
    $out(['ok' => false, 'error' => 'دکتۆر نەدۆزرایەوە'], 404);
}
$formula = trim(IncomeFormula::normalize((string)($_POST['formula'] ?? '')));
$action = (string)($_POST['action'] ?? '');

if ($action === 'save') {
    $before = IncomeFormula::forDoctor($doctorId);
    try {
        IncomeFormula::save($doctorId, $formula);
    } catch (InvalidArgumentException $e) {
        $out(['ok' => false, 'error' => $e->getMessage()]);
    }
    if (($before ?? '') !== $formula) {
        AuditLog::log('income_formula_changed', 'users', $doctorId,
            ['formula' => $before], ['formula' => $formula === '' ? null : $formula]);
    }
    $out(['ok' => true, 'formula' => $formula]);
}

if ($action === 'preview') {
    $today = date('Y-m-d');
    [$from, $to] = ds_range_from_request($_POST, $today, $today);
    $kinds = ['lab', 'store', 'general'];
    $expSel = isset($_POST['xs']) ? array_values(array_intersect($kinds, (array)($_POST['x'] ?? []))) : $kinds;
    $ds = ds_compute_range($db, $doctorId, $from, $to, false);
    $f = ds_share_figures($ds, $expSel);
    $default = ds_doctor_share_of(null, $f);
    $share = $default;
    if ($formula !== '') {
        try {
            $share = round(IncomeFormula::evaluate($formula, $f));
        } catch (InvalidArgumentException $e) {
            $out(['ok' => false, 'error' => $e->getMessage(), 'vars' => $f]);
        }
    }
    $out(['ok' => true, 'doctor' => $share, 'center' => $f['net'] - $share, 'default' => $default, 'net' => $f['net'], 'vars' => $f]);
}

$out(['ok' => false, 'error' => 'کردار نەناسراوە'], 400);
