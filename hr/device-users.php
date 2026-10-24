<?php
/**
 * بەکارهێنەرانی ئامێر — the fingerprint device's people next to Lezan's.
 *
 * fingerprint-sync/zk_sync.py copies the device's user list (PIN + the name
 * typed on the device) into hr_device_users. Here it is matched with the
 * employees by device PIN (hr_pin()):
 *
 *   ناناسراو   on the device, no employee with that PIN: add them in one click
 *              with the device's name, or link the PIN to an existing employee
 *              (a name match is suggested). Their punches waiting in the
 *              service's queue then go in by themselves.
 *   بەستراو    on the device and in Lezan: shown with both names.
 *   لەسەر ئامێر نین  active employees the device does not know: they still need
 *              enrolling on the device, under the PIN shown.
 *
 * A PIN that sent punches but is no longer on the device (deleted there) is
 * listed as unknown too, without a name.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.employees.view');

$db = Database::getInstance();
$canManage = hr_can('hr.employees.manage');

/** "Dr. Hersh" / "dr  hersh" / "د.هێرش" → comparable text. */
function hr_norm_name(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/^(dra?|doctor|دکتۆر|دکتور|د)[\s.]+/u', '', $s);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
}

/** The employee whose name is closest to $name (at least 60% alike), or null. */
function hr_suggest(string $name, array $candidates): ?array
{
    $n = hr_norm_name($name);
    if ($n === '') {
        return null;
    }
    $best = null;
    $bestPct = 0;
    foreach ($candidates as $e) {
        similar_text($n, hr_norm_name($e['full_name']), $pct);
        if ($pct > $bestPct) {
            [$best, $bestPct] = [$e, $pct];
        }
    }
    return $bestPct >= 60 ? ['employee' => $best, 'pct' => (int)round($bestPct)] : null;
}

$employees = $db->fetchAll(
    'SELECT id, device_pin, full_name, status, employee_type FROM hr_employees ORDER BY COALESCE(display_order, 9999), id');
// Device PIN → employee: an explicit device_pin wins over an id that matches.
$byPin = [];
foreach ($employees as $e) {
    if ((string)$e['device_pin'] === '') {
        $byPin[(string)$e['id']] = $e;
    }
}
foreach ($employees as $e) {
    if ((string)$e['device_pin'] !== '') {
        $byPin[(string)$e['device_pin']] = $e;
    }
}

$deviceUsers = [];
try {
    $deviceUsers = $db->fetchAll(
        'SELECT du.*, d.name AS device_name FROM hr_device_users du LEFT JOIN hr_devices d ON d.ip = du.device_ip
         ORDER BY du.device_ip, LENGTH(du.pin), du.pin');
} catch (Throwable $e) {
    // hr_device_users arrives with migration hr_06.
}
$onDevice = [];
foreach ($deviceUsers as $u) {
    $onDevice[$u['pin']] = $u;
}

// Unknown: on the device without an employee, plus PINs that sent punches but
// are no longer on the device (the service reports those in hr_devices).
$unknown = [];
foreach ($deviceUsers as $u) {
    if (!isset($byPin[$u['pin']])) {
        $unknown[$u['pin']] = ['pin' => $u['pin'], 'name' => $u['name'], 'device' => $u['device_name'] ?: $u['device_ip'], 'on_device' => true];
    }
}
foreach (hr_devices() as $dv) {
    foreach (array_filter(explode(',', (string)$dv['unknown_pins'])) as $pin) {
        if (!isset($byPin[$pin]) && !isset($unknown[$pin])) {
            $unknown[$pin] = ['pin' => $pin, 'name' => null, 'device' => $dv['name'] ?: $dv['ip'], 'on_device' => false];
        }
    }
}

// Employees the device does not know: candidates for a link, and to enrol.
$notOnDevice = array_values(array_filter($employees, fn($e) => !isset($onDevice[hr_pin($e)])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    hr_require('hr.employees.manage');
    $action = (string)($_POST['action'] ?? '');
    // New people go on the shift most employees have.
    $shift = $db->fetch("SELECT shift_id FROM hr_employees WHERE status = 'active' AND shift_id IS NOT NULL
                         GROUP BY shift_id ORDER BY COUNT(*) DESC LIMIT 1")['shift_id'] ?? null;
    $newRow = fn(string $name, string $type) => [
        'full_name' => mb_substr(trim($name), 0, 150), 'employee_type' => $type === 'doctor' ? 'doctor' : 'staff',
        'shift_id' => $shift, 'status' => 'active',
    ];
    try {
        if ($action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('ناوی کارمەند بنووسە');
            }
            hr_employee_create($newRow($name, (string)($_POST['type'] ?? 'staff')), (string)($_POST['pin'] ?? ''), $hrUserId);
            hr_flash('success', '«' . $name . '» بە PIN ی ' . trim((string)$_POST['pin']) . ' زیادکرا. پەنجەمۆرەکانی خۆیان دێن.');
        } elseif ($action === 'add_all') {
            $added = 0;
            foreach ($unknown as $u) {
                if ($u['on_device'] && trim((string)$u['name']) !== '') {
                    hr_employee_create($newRow($u['name'], 'staff'), $u['pin'], $hrUserId);
                    $added++;
                }
            }
            hr_flash('success', $added . ' کەس لە ئامێرەکەوە زیادکران.');
        } elseif ($action === 'link') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $pin = (string)($_POST['pin'] ?? '');
            hr_employee_link_pin($empId, $pin);
            $name = $db->fetch('SELECT full_name FROM hr_employees WHERE id = ?', [$empId])['full_name'] ?? '';
            hr_flash('success', 'PIN ی ' . trim($pin) . ' بەسترایەوە بە «' . $name . '». پەنجەمۆرەکانی نوێی ئەو PIN ـە دەچنە سەر ئەو.');
        }
    } catch (InvalidArgumentException $ex) {
        hr_flash('error', $ex->getMessage());
    }
    hr_redirect();
}

$linked = array_values(array_filter($deviceUsers, fn($u) => isset($byPin[$u['pin']])));
$activeNotOnDevice = array_values(array_filter($notOnDevice, fn($e) => $e['status'] === 'active'));

$pageTitle = 'بەکارهێنەرانی ئامێر';
$customCSS = $hrBaseCss . <<<CSS
.du-row-forms{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.du-row-forms form{display:flex;gap:4px;align-items:center;margin:0}
.du-row-forms .form-control{height:32px;padding:2px 8px;font-size:13px;width:auto}
.du-or{color:#94a3b8;font-size:12px;padding:0 4px}
.du-sug{display:inline-block;margin-top:3px;font-size:11.5px;color:#7c3aed}
.du-chips{display:flex;flex-wrap:wrap;gap:6px;padding:12px}
.du-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:8px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px}
.du-chip b{direction:ltr;color:#0f766e}
.du-diff{font-size:11px;color:#b45309;background:#fef3c7;border-radius:6px;padding:1px 6px;margin-inline-start:4px}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<div class="hr-cards">
    <div class="hr-card" style="--c:#0f766e"><div class="lbl stat-label">لەسەر ئامێر</div><div class="val stat-value"><?= count($deviceUsers) ?></div><div class="sub text-muted">کەس تۆمارکراوە</div></div>
    <div class="hr-card" style="--c:#15803d"><div class="lbl stat-label">بەستراو بە لێزان</div><div class="val stat-value"><?= count($linked) ?></div><div class="sub text-muted">PIN ـەکەیان یەک دەگرێتەوە</div></div>
    <div class="hr-card" style="--c:#b45309"><div class="lbl stat-label">ناناسراو</div><div class="val stat-value"><?= count($unknown) ?></div><div class="sub text-muted">لەسەر ئامێرە، لە لێزان نییە</div></div>
    <div class="hr-card" style="--c:#64748b"><div class="lbl stat-label">لەسەر ئامێر نین</div><div class="val stat-value"><?= count($activeNotOnDevice) ?></div><div class="sub text-muted">کارمەندی چالاک</div></div>
</div>

<?php if (!$deviceUsers && !$unknown): ?>
<div class="hr-table-wrap"><div class="hr-empty">
    <i class="fas fa-fingerprint" style="font-size:28px;display:block;margin-bottom:8px;color:#cbd5e1"></i>
    هێشتا لیستی ئامێرەکە نەگەیشتووە. خزمەتگوزاری پەنجەمۆر (fingerprint-sync) کاتێک بە ئامێرەکەوە دەبەسترێت، ناو و PIN ـەکان دەنێرێت.
</div></div>
<?php endif; ?>

<?php if ($unknown): ?>
<div class="hr-sec" style="display:flex;align-items:center;gap:10px;margin-top:4px">
    <span><i class="fas fa-user-plus" style="color:#b45309"></i> لەسەر ئامێرن، لە لێزان نین</span>
    <?php if ($canManage && count(array_filter($unknown, fn($u) => $u['on_device'] && trim((string)$u['name']) !== '')) > 1): ?>
    <form method="POST" style="margin-inline-start:auto" onsubmit="return confirm('هەموویان بە ناوەکانی سەر ئامێرەکە وەک کارمەندی نوێ زیاد بکرێن؟')">
        <input type="hidden" name="action" value="add_all">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-users"></i> زیادکردنی هەمووی</button>
    </form>
    <?php endif; ?>
</div>
<div class="hr-table-wrap" style="margin-bottom:18px">
    <table class="hr-table">
        <thead><tr><th>PIN</th><th>ناو لەسەر ئامێر</th><th>ئامێر</th><?php if ($canManage): ?><th>زیادکردن یان بەستنەوە</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($unknown as $u):
            $sug = $u['name'] ? hr_suggest($u['name'], $notOnDevice) : null; ?>
            <tr>
                <td class="num"><b><?= hr_esc($u['pin']) ?></b></td>
                <td>
                    <?= $u['name'] ? '<b>' . hr_esc($u['name']) . '</b>' : '<span class="hr-muted">ناوی نییە' . ($u['on_device'] ? '' : ' — لەسەر ئامێر سڕاوەتەوە، بەڵام پەنجەمۆری هەیە') . '</span>' ?>
                    <?php if ($sug): ?><br><span class="du-sug"><i class="fas fa-wand-magic-sparkles"></i> لەوانەیە «<?= hr_esc($sug['employee']['full_name']) ?>» بێت (<?= $sug['pct'] ?>%)</span><?php endif; ?>
                </td>
                <td class="hr-muted" style="font-size:12px"><?= hr_esc($u['device']) ?></td>
                <?php if ($canManage): ?>
                <td>
                    <div class="du-row-forms">
                        <form method="POST">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="pin" value="<?= hr_esc($u['pin']) ?>">
                            <input type="text" name="name" class="form-control" value="<?= hr_esc($u['name']) ?>" placeholder="ناو" required maxlength="150" style="width:150px">
                            <select name="type" class="form-control">
                                <option value="staff">کارمەند</option>
                                <option value="doctor" <?= preg_match('/^\s*dra?[\s.]/iu', (string)$u['name']) ? 'selected' : '' ?>>دکتۆر</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary" title="وەک کارمەندی نوێ"><i class="fas fa-user-plus"></i> زیادکردن</button>
                        </form>
                        <span class="du-or">یان</span>
                        <form method="POST" onsubmit="return confirm('ئەم PIN ـە ببەسترێتەوە بەو کارمەندە؟ پەنجەمۆرە نوێکانی ئەم PIN ـە دەچنە سەر ئەو.')">
                            <input type="hidden" name="action" value="link">
                            <input type="hidden" name="pin" value="<?= hr_esc($u['pin']) ?>">
                            <select name="employee_id" class="form-control" required style="max-width:200px">
                                <option value="">— کارمەندێکی هەبوو —</option>
                                <?php foreach ($notOnDevice as $e): ?>
                                <option value="<?= (int)$e['id'] ?>" <?= $sug && (int)$sug['employee']['id'] === (int)$e['id'] ? 'selected' : '' ?>>
                                    <?= hr_esc(hr_pin($e) . ' — ' . $e['full_name'] . ($e['status'] === 'active' ? '' : ' (ناچالاک)')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-link"></i> بەستنەوە</button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($linked): ?>
<div class="hr-sec"><i class="fas fa-link" style="color:#15803d"></i> بەستراو — لەسەر ئامێر و لە لێزان</div>
<div class="hr-table-wrap" style="margin-bottom:18px">
    <table class="hr-table">
        <thead><tr><th>PIN</th><th>ناو لەسەر ئامێر</th><th>کارمەند لە لێزان</th><th>ئامێر</th></tr></thead>
        <tbody>
        <?php foreach ($linked as $u): $emp = $byPin[$u['pin']];
            $differs = $u['name'] && hr_norm_name($u['name']) !== hr_norm_name($emp['full_name']); ?>
            <tr>
                <td class="num"><b><?= hr_esc($u['pin']) ?></b></td>
                <td><?= $u['name'] ? hr_esc($u['name']) : '<span class="hr-muted">—</span>' ?></td>
                <td><b><?= hr_esc($emp['full_name']) ?></b>
                    <?php if ($differs): ?><span class="du-diff" title="ناوەکان جیاوازن، بەڵام PIN یەکە">ناوی جیاواز</span><?php endif; ?>
                    <?php if ($emp['status'] !== 'active'): ?><span class="hr-badge off">ناچالاک</span><?php endif; ?></td>
                <td class="hr-muted" style="font-size:12px"><?= hr_esc($u['device_name'] ?: $u['device_ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($deviceUsers && $activeNotOnDevice): ?>
<div class="hr-sec"><i class="fas fa-fingerprint" style="color:#64748b"></i> کارمەندی چالاک کە لەسەر ئامێرەکە نین — پێویستە بەم PIN ـانە لەسەر ئامێرەکە تۆمار بکرێن</div>
<div class="hr-table-wrap">
    <div class="du-chips">
        <?php foreach ($activeNotOnDevice as $e): ?>
        <span class="du-chip"><b><?= hr_esc(hr_pin($e)) ?></b> <?= hr_esc($e['full_name']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
