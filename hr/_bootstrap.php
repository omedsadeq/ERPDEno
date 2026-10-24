<?php
/**
 * سەرچاوە مرۆییەکان — shared start of every hr/ page.
 *
 * Uses the clinic's own login and permission tables: there is no second login
 * for HR. Every hr/ page is gated on an hr.* key (database/migrations/
 * 2026_10_24_hr_02_permissions.sql) and only hr.payroll.view shows money.
 *
 * Usage, before including includes/header.php:
 *
 *     require_once __DIR__ . '/_bootstrap.php';
 *     hr_require('hr.reports.view');
 *
 * hr/ reads users (to link a login) and nothing else from the dental tables,
 * and no dental page depends on it: deleting hr/ and the hr_* tables removes
 * the module.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/HrAttendance.php';
require_once __DIR__ . '/_day_editor.php';

$hrAuth = new Auth();
$hrAuth->requireLogin();
$hrUserId = (int)$hrAuth->getCurrentUserId();

$pageModuleNav = __DIR__ . '/_nav.php';

/** Stop here unless the user holds one of $keys. */
function hr_require(string ...$keys): void
{
    foreach ($keys as $key) {
        if (Permission::getInstance()->can($key)) {
            return;
        }
    }
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

function hr_can(string $key): bool
{
    return Permission::getInstance()->can($key);
}

function hr_esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** IQD for display: whole dinars, no symbol, like the rest of the clinic. */
function hr_money($v): string
{
    return number_format((float)$v, 0);
}

/** A valid Y-m-d, or null. */
function hr_date(?string $v): ?string
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** A valid Y-m, else this month's. */
function hr_month(?string $v): string
{
    return (is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) ? $v : date('Y-m');
}

/** A one-shot message for the next page load (post → redirect → get). */
function hr_flash(string $type, string $message): void
{
    $_SESSION['hr_flash'] = ['type' => $type, 'message' => $message];
}

/** Print and clear the pending flash message, if any. */
function hr_flash_render(): void
{
    $f = $_SESSION['hr_flash'] ?? null;
    unset($_SESSION['hr_flash']);
    if (!$f) {
        return;
    }
    $ok = $f['type'] === 'success';
    echo '<div class="alert ' . ($ok ? 'alert-success' : 'alert-error') . '"' . ($ok ? ' data-auto-hide' : '') . '>'
        . '<i class="fas ' . ($ok ? 'fa-check-circle' : 'fa-exclamation-circle') . ' alert-icon"></i> '
        . hr_esc($f['message']) . '</div>';
}

/** Back to the same page (keeping its query string) after a POST. */
function hr_redirect(?string $url = null): void
{
    header('Location: ' . ($url ?? ($_SERVER['REQUEST_URI'] ?? APP_URL . '/hr/index.php')));
    exit;
}

/** Kurdish month heading, e.g. "10 / 2026". */
function hr_month_label(string $month): string
{
    return (int)substr($month, 5, 2) . ' / ' . substr($month, 0, 4);
}

/**
 * A fingerprint device's state from its hr_devices row (written once a
 * minute by fingerprint-sync/zk_sync.py): ['cls' => ok|warn|down, 'label'].
 * A heartbeat older than 3 minutes means the service is not running,
 * whatever is_connected last said.
 */
function hr_device_state(array $dv): array
{
    $age = $dv['last_seen_at'] ? time() - strtotime($dv['last_seen_at']) : null;
    if ($age === null || $age > 180) {
        return ['cls' => 'down', 'label' => 'خزمەتگوزاری پەنجەمۆر کار ناکات'];
    }
    if (!(int)$dv['is_connected']) {
        return ['cls' => 'warn', 'label' => 'ئامێرەکە وەڵام ناداتەوە'];
    }
    return ['cls' => 'ok', 'label' => 'پەیوەستە — ڕاستەوخۆ'];
}

/** Every device row, or [] before migration hr_05 has run. */
function hr_devices(): array
{
    try {
        // The one the service reported most recently first: the live board shows
        // only the first, and an address no longer used must not stand for it.
        return Database::getInstance()->fetchAll(
            'SELECT * FROM hr_devices ORDER BY last_seen_at IS NULL, last_seen_at DESC, id');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The PIN a person uses on the fingerprint device: hr_employees.device_pin,
 * or their id when it is empty (as for everyone before device_pin existed).
 */
function hr_pin(array $e): string
{
    return (string)(($e['device_pin'] ?? '') !== '' && $e['device_pin'] !== null ? $e['device_pin'] : $e['id']);
}

/** A device PIN as typed: trimmed, 1–20 characters, or an error in Kurdish. */
function hr_clean_pin(?string $pin): string
{
    $pin = trim((string)$pin);
    if ($pin === '' || mb_strlen($pin) > 20 || preg_match('/\s/u', $pin)) {
        throw new InvalidArgumentException('PIN ی ئامێر پێویستە (ژمارەکەی سەر ئامێرەکە، بێ بۆشایی)');
    }
    return $pin;
}

/**
 * Add an employee whose device PIN is $pin. The record's id is the PIN when
 * that is a free whole number (as for everyone so far), else the next free id;
 * device_pin always holds the PIN. Returns the new id.
 */
function hr_employee_create(array $row, string $pin, int $userId): int
{
    require_once __DIR__ . '/../classes/HrPunch.php';
    $db = Database::getInstance();
    $pin = hr_clean_pin($pin);
    if ($taken = HrPunch::byPin($pin)) {
        throw new InvalidArgumentException('PIN ی ' . $pin . ' پێشتر هی «' . $taken['full_name'] . '» ـە');
    }
    $id = (ctype_digit($pin) && (int)$pin > 0 && (int)$pin < 2147483647
        && !$db->fetch('SELECT id FROM hr_employees WHERE id = ?', [(int)$pin]))
        ? (int)$pin
        : (int)$db->fetch('SELECT COALESCE(MAX(id), 0) + 1 AS n FROM hr_employees')['n'];
    $row['id'] = $id;
    $row['device_pin'] = $pin;
    $row['created_by'] = $userId ?: null;
    $row['display_order'] = (int)($db->fetch('SELECT COALESCE(MAX(display_order), 0) + 1 AS n FROM hr_employees')['n'] ?? 1);
    $db->insert('hr_employees', $row);
    AuditLog::log('hr_employee_create', 'hr_employees', $id, null, $row);
    return $id;
}

/**
 * Give employee $employeeId the device PIN $pin — "this person on the device
 * is this employee". Someone else holding that PIN loses it. Past punches stay
 * where they are; new punches with this PIN go to $employeeId.
 */
function hr_employee_link_pin(int $employeeId, string $pin): void
{
    $db = Database::getInstance();
    $pin = hr_clean_pin($pin);
    $emp = $db->fetch('SELECT id, full_name, device_pin FROM hr_employees WHERE id = ?', [$employeeId]);
    if (!$emp) {
        throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
    }
    if ((string)$emp['device_pin'] === $pin) {
        return;
    }
    $db->beginTransaction();
    try {
        foreach ($db->fetchAll('SELECT id, device_pin FROM hr_employees WHERE device_pin = ? AND id <> ?', [$pin, $employeeId]) as $other) {
            $db->update('hr_employees', ['device_pin' => null], 'id = ?', [(int)$other['id']]);
            AuditLog::log('hr_employee_pin_released', 'hr_employees', (int)$other['id'], ['device_pin' => $pin], ['device_pin' => null]);
        }
        $db->update('hr_employees', ['device_pin' => $pin], 'id = ?', [$employeeId]);
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    AuditLog::log('hr_employee_pin_linked', 'hr_employees', $employeeId, ['device_pin' => $emp['device_pin']], ['device_pin' => $pin]);
}

/** <option>s for an employee picker. */
function hr_employee_options(array $employees, $selected = null, bool $withCode = true): string
{
    $html = '';
    foreach ($employees as $e) {
        $sel = ((string)$selected !== '' && (int)$selected === (int)$e['id']) ? ' selected' : '';
        $label = ($withCode ? hr_pin($e) . ' — ' : '') . $e['full_name'];
        $html .= '<option value="' . (int)$e['id'] . '"' . $sel . '>' . hr_esc($label) . '</option>';
    }
    return $html;
}

/** Styles shared by the hr/ screens. */
$hrBaseCss = <<<CSS
.hr-sec,.erp-sec{font-size:12px;font-weight:700;color:#6b6b6b;margin:0 0 10px}
.hr-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:18px}
.hr-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;border-inline-start:4px solid var(--c,#0f766e)}
.hr-card .lbl{font-size:12px;color:#6b7280}
.hr-card .val{font-size:22px;font-weight:800;color:var(--c,#0f766e);margin-top:2px;font-variant-numeric:tabular-nums;direction:ltr;text-align:right;unicode-bidi:isolate}
.hr-card .sub{font-size:11.5px;color:#9ca3af;margin-top:2px}
.hr-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-bottom:14px}
.hr-filter .form-group{margin:0;min-width:150px}
.hr-filter label{font-size:12px;color:#6b7280;margin-bottom:4px;display:block}
.hr-table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto}
.hr-table{width:100%;border-collapse:collapse;font-size:13px}
.hr-table th{background:#f8fafc;color:#475569;font-weight:700;font-size:12px;padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;position:sticky;top:0;z-index:1}
.hr-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.hr-table tbody tr:hover{background:#f8fafc}
.hr-table .num{direction:ltr;text-align:left;font-variant-numeric:tabular-nums;white-space:nowrap}
.hr-table tfoot td{background:#f1f5f9;font-weight:800;border-top:2px solid #cbd5e1}
.hr-empty{text-align:center;color:#9ca3af;padding:36px 12px}
.hr-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}
.hr-badge.doctor{background:#ede9fe;color:#6d28d9}
.hr-badge.staff{background:#e0f2fe;color:#0369a1}
.hr-badge.off{background:#f1f5f9;color:#64748b}
.hr-badge.in{background:#dcfce7;color:#15803d}
.hr-badge.out{background:#fee2e2;color:#b91c1c}
.hr-badge.warn{background:#fef3c7;color:#b45309}
.hr-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:7px;font-size:12px;direction:ltr;font-variant-numeric:tabular-nums;border:1px solid transparent}
.hr-chip.in{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.hr-chip.out{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.hr-chip.miss{background:#fffbeb;color:#b45309;border-color:#fde68a;font-family:inherit}
.hr-chip.manual{border-style:dashed}
.hr-muted{color:#9ca3af}
tr.is-off td{background:#fafafa}
.hr-formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px}
.hr-formsec{font-size:12.5px;font-weight:800;color:#0f766e;margin:14px 0 8px;padding-bottom:4px;border-bottom:1px solid #e5e7eb}
@media print{.hr-filter,.hr-noprint,.page-actions{display:none!important}.hr-table-wrap{border:0;overflow:visible}}
CSS;
