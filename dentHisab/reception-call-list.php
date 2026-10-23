<?php
/**
 * Reception Call List
 * لیستی پەیوەندیکردنی ڕیسێپشن — لیستی مەوعیدی ڕۆژێک کە دکتۆر ناردوویەتی
 *
 * Reception calls each patient to remind them of the appointment and how much
 * money to bring (promised amount). Debt is intentionally NOT shown here.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$hasFullAccess = $auth->isAdmin() || $auth->isReceptionist();

// Params
$listDoctorId = intval($_GET['doctor'] ?? 0);
$listDate = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
    $listDate = date('Y-m-d', strtotime('+1 day'));
}

// Access: reception/admin, or the doctor viewing their own list
if (!$hasFullAccess && $listDoctorId !== (int)$currentUser['id']) {
    header('Location: ' . APP_URL . '/appointments.php');
    exit;
}

// Doctor must be a real doctor account
$listDoctor = $listDoctorId > 0
    ? $db->fetch("SELECT id, full_name FROM users WHERE id = :id AND role = 'doctor'", ['id' => $listDoctorId])
    : null;
if (!$listDoctor) {
    header('Location: ' . APP_URL . '/appointments.php');
    exit;
}

// Optional subset: the doctor may have sent only specific patients
$onlyIds = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))),
    fn($v) => $v > 0
)));

// The list — NO debt query on purpose (reception must not see debt)
$params = [$listDoctorId, $listDate];
$idsSql = '';
if ($onlyIds) {
    $idsSql = ' AND a.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
    $params = array_merge($params, $onlyIds);
}
$rows = $db->fetchAll(
    "SELECT a.id, a.appointment_time, a.duration, a.status, a.promised_amount, a.notes,
            p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
            wt.work_name
     FROM appointments a
     LEFT JOIN patients p ON a.patient_id = p.id
     LEFT JOIN work_types wt ON a.work_type_id = wt.id
     WHERE a.doctor_id = ? AND a.appointment_date = ?
       AND a.status NOT IN ('cancelled', 'no_show'){$idsSql}
     ORDER BY a.appointment_time",
    $params
);

$totalPromised = 0;
foreach ($rows as $r) {
    $totalPromised += (float)($r['promised_amount'] ?? 0);
}

$days = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
$dayName = $days[(int)date('w', strtotime($listDate))];

$statusLabels = [
    'scheduled'   => ['داڕێژراو', '#ff8c00'],
    'confirmed'   => ['پشتڕاستکراو', '#0078d4'],
    'waiting'     => ['چاوەڕوان', '#ff9800'],
    'in_progress' => ['لە چارەسەردا', '#0078d4'],
    'completed'   => ['تەواوبوو', '#4caf50'],
    'rescheduled' => ['دواخراو', '#ff9800'],
];

$pageTitle = 'لیستی پەیوەندی ڕیسێپشن';
$breadcrumb = [
    ['title' => 'مەوعیدەکان', 'url' => APP_URL . '/appointments.php'],
    ['title' => 'لیستی پەیوەندی ڕیسێپشن']
];
require_once __DIR__ . '/includes/header.php';
?>

<style>
.rcl-head {
    background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
    color: #fff; border-radius: 10px; padding: 16px 20px; margin-bottom: 14px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
}
.rcl-head h2 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
.rcl-head .sub { font-size: 13px; opacity: .9; margin-top: 4px; }
.rcl-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
.rcl-stat { background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; padding: 12px; text-align: center; }
.rcl-stat .label { font-size: 12px; color: #607d8b; margin-bottom: 4px; }
.rcl-stat .value { font-size: 20px; font-weight: 700; color: #0f172a; }
.rcl-stat.promise .value { color: #15803d; }
.rcl-table-wrap { width: 100%; overflow-x: auto; border-radius: 8px; }
.rcl-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; overflow: hidden; min-width: 760px; }
.rcl-table th, .rcl-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f5; vertical-align: middle; text-align: center; font-size: 13px; }
.rcl-table th { background: #f0fdfa; color: #0f766e; font-weight: 600; }
.rcl-table tr:last-child td { border-bottom: none; }
.rcl-table tr:hover td { background: #fafcff; }
.rcl-time { font-weight: 700; color: #0078d4; font-size: 14px; }
.rcl-phone { direction: ltr; color: #0078d4; text-decoration: none; }
.rcl-phone:hover { text-decoration: underline; }
.rcl-promise { color: #15803d; font-weight: 700; }
.rcl-call-btn, .rcl-wa-btn {
    display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px;
    border-radius: 6px; text-decoration: none; font-size: 12px; color: #fff;
}
.rcl-call-btn { background: #0078d4; border: 1px solid #005a9e; }
.rcl-call-btn:hover { background: #005a9e; color: #fff; }
.rcl-wa-btn { background: #22c55e; border: 1px solid #16a34a; margin-right: 4px; }
.rcl-wa-btn:hover { background: #16a34a; color: #fff; }
.rcl-empty { text-align: center; padding: 40px 20px; color: #78909c; background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; }
.rcl-empty i { font-size: 40px; color: #cbd5e1; margin-bottom: 10px; display: block; }
@media (max-width: 768px) {
    .rcl-stats { grid-template-columns: 1fr; }
}
</style>

<div class="rcl-head">
    <div>
        <h2><i class="fas fa-phone-volume"></i> لیستی پەیوەندیکردن — د. <?= htmlspecialchars($listDoctor['full_name']) ?></h2>
        <div class="sub">
            <i class="fas fa-calendar"></i> <?= $dayName ?> — <?= htmlspecialchars($listDate) ?>
            <?php if ($onlyIds): ?>
            <span style="background: rgba(255,255,255,.2); border-radius: 20px; padding: 2px 10px; font-size: 12px; margin-right: 8px;">
                <i class="fas fa-check-square"></i> نەخۆشە هەڵبژێردراوەکان (<?= count($rows) ?>)
            </span>
            <?php endif; ?>
        </div>
    </div>
    <a href="<?= APP_URL ?>/appointments.php?date=<?= urlencode($listDate) ?>" class="btn" style="background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.35); color: #fff; padding: 8px 14px; border-radius: 8px; font-size: 13px;">
        <i class="fas fa-calendar-alt"></i> پەڕەی مەوعیدەکان
    </a>
</div>

<div class="rcl-stats">
    <div class="rcl-stat">
        <div class="label">ژمارەی نەخۆش</div>
        <div class="value"><?= count($rows) ?></div>
    </div>
    <div class="rcl-stat promise">
        <div class="label">کۆی پارەی هێنراو</div>
        <div class="value"><?= number_format($totalPromised) ?></div>
    </div>
    <div class="rcl-stat">
        <div class="label">بەروار</div>
        <div class="value" style="font-size: 16px;"><?= htmlspecialchars($listDate) ?></div>
    </div>
</div>

<?php if (empty($rows)): ?>
<div class="rcl-empty">
    <i class="fas fa-calendar-check"></i>
    <h3 style="margin: 0 0 6px;">هیچ مەوعیدێک نییە</h3>
    <p style="margin: 0;">بۆ ئەم ڕۆژە هیچ مەوعیدێک نییە بۆ ئەم دکتۆرە</p>
</div>
<?php else: ?>
<div class="rcl-table-wrap">
    <table class="rcl-table">
        <thead>
            <tr>
                <th>کات</th>
                <th>نەخۆش</th>
                <th>ژ. مۆبایل</th>
                <th>ماوە</th>
                <th>ئیش</th>
                <th>پارەی هێنراو</th>
                <th>تێبینی</th>
                <th>دۆخ</th>
                <th>پەیوەندی</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $apt):
                $st = $statusLabels[$apt['status']] ?? [$apt['status'], '#666'];
                $phone = trim((string)($apt['patient_phone'] ?? ''));
                $waPhone = $phone !== '' ? preg_replace('/^0/', '964', preg_replace('/\D/', '', $phone)) : '';
                $promised = (float)($apt['promised_amount'] ?? 0);
                $aptTime = date('H:i', strtotime($apt['appointment_time']));
                $waText = "سڵاو " . ($apt['patient_name'] ?? '') . "،\n"
                        . "ئاگادارت دەکەینەوە کە بەرواری " . $listDate . " کاتژمێر " . $aptTime
                        . " مەوعیدت هەیە لای د. " . $listDoctor['full_name'] . ".";
                if ($promised > 0) {
                    $waText .= "\nتکایە بڕی " . number_format($promised) . " لەگەڵ خۆت بهێنە.";
                }
                $waText .= "\nسوپاس.";
            ?>
            <tr>
                <td><span class="rcl-time"><?= $aptTime ?></span></td>
                <td>
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$apt['patient_id'] ?>" style="color: #0078d4; text-decoration: none; font-weight: 600;">
                        <?= htmlspecialchars($apt['patient_name'] ?? '-') ?>
                    </a>
                </td>
                <td>
                    <?php if ($phone !== ''): ?>
                    <a class="rcl-phone" href="tel:<?= htmlspecialchars($phone) ?>"><?= htmlspecialchars($phone) ?></a>
                    <?php else: ?>
                    <span style="color: #9aa7b3;">-</span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$apt['duration'] ?> خولەک</td>
                <td><?= !empty($apt['work_name']) ? htmlspecialchars($apt['work_name']) : '<span style="color:#9aa7b3;">-</span>' ?></td>
                <td>
                    <?php if ($promised > 0): ?>
                    <span class="rcl-promise"><?= number_format($promised) ?></span>
                    <?php else: ?>
                    <span style="color: #9aa7b3;">-</span>
                    <?php endif; ?>
                </td>
                <td style="max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: #757575;">
                    <?= !empty($apt['notes']) ? htmlspecialchars($apt['notes']) : '-' ?>
                </td>
                <td><span style="color: <?= $st[1] ?>; font-weight: 600; font-size: 12px;"><?= htmlspecialchars($st[0]) ?></span></td>
                <td>
                    <?php if ($phone !== ''): ?>
                    <a class="rcl-wa-btn" target="_blank" rel="noopener"
                       href="https://wa.me/<?= htmlspecialchars($waPhone) ?>?text=<?= rawurlencode($waText) ?>">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <a class="rcl-call-btn" href="tel:<?= htmlspecialchars($phone) ?>">
                        <i class="fas fa-phone"></i>
                    </a>
                    <?php else: ?>
                    <span style="color: #9aa7b3;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
