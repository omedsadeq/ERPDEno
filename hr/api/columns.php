<?php
/**
 * سەرچاوە مرۆییەکان — the monthly sheet's columns, saved from the sheet.
 *
 *   POST action=order     ids=3,1,7…    the columns as they now stand
 *   POST action=hide      id, hidden=1|0
 *   POST action=show_all  everyone back on the sheet
 *
 * order: the sheet may show only part of the people (a filter, hidden ones),
 * so the posted ids take the places those ids held in the full order and
 * everyone else keeps theirs; then everyone is renumbered 1…n, so no two
 * share a place (the same display_order that hr/sort-order.php writes).
 *
 * Needs hr.employees.manage. Answers {success, message}.
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function hr_cols_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hr_cols_out(['success' => false, 'message' => 'داواکاری نادروست'], 405);
}
Csrf::require();
if (!hr_can('hr.employees.manage')) {
    hr_cols_out(['success' => false, 'message' => 'ڕێگەپێدراو نیت'], 403);
}

$db = Database::getInstance();
$action = (string)($_POST['action'] ?? '');

if ($action === 'order') {
    $posted = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
    $all = array_map('intval', array_column(
        $db->fetchAll('SELECT id FROM hr_employees ORDER BY COALESCE(display_order, 9999), id'), 'id'));
    $posted = array_values(array_intersect($posted, $all));
    if (count($posted) < 2) {
        hr_cols_out(['success' => false, 'message' => 'ڕیزبەندی نادروست'], 422);
    }
    // The places the posted people held, in order, refilled in the new order.
    $slots = array_keys(array_intersect($all, $posted));
    foreach ($slots as $i => $slot) {
        $all[$slot] = $posted[$i];
    }
    $db->beginTransaction();
    try {
        foreach ($all as $n => $id) {
            $db->query('UPDATE hr_employees SET display_order = ? WHERE id = ?', [$n + 1, $id]);
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    AuditLog::log('hr_sort_order', 'hr_employees', null, null, ['order' => $posted, 'from' => 'monthly sheet']);
    hr_cols_out(['success' => true, 'message' => 'ڕیزبەندی پاشەکەوت کرا']);
}

if ($action === 'hide') {
    $id = (int)($_POST['id'] ?? 0);
    $hidden = !empty($_POST['hidden']) ? 1 : 0;
    $emp = $db->fetch('SELECT id, full_name FROM hr_employees WHERE id = ?', [$id]);
    if (!$emp) {
        hr_cols_out(['success' => false, 'message' => 'کارمەند نەدۆزرایەوە'], 404);
    }
    $db->update('hr_employees', ['monthly_hidden' => $hidden], 'id = ?', [$id]);
    AuditLog::log('hr_monthly_hidden', 'hr_employees', $id, null, ['monthly_hidden' => $hidden]);
    hr_cols_out(['success' => true, 'message' => '«' . $emp['full_name'] . '» ' . ($hidden ? 'شاردرایەوە' : 'پیشان دەدرێتەوە')]);
}

if ($action === 'show_all') {
    $n = $db->query('UPDATE hr_employees SET monthly_hidden = 0 WHERE monthly_hidden = 1')->rowCount();
    AuditLog::log('hr_monthly_hidden', 'hr_employees', null, null, ['show_all' => $n]);
    hr_cols_out(['success' => true, 'message' => $n ? $n . ' کەس پیشان دەدرێنەوە' : 'کەس شاراوە نەبوو']);
}

hr_cols_out(['success' => false, 'message' => 'داواکاری نادروست'], 422);
