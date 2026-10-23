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
require_once __DIR__ . '/classes/Appointment.php';

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
$listDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
    $listDate = date('Y-m-d');
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

// Optional subset: the doctor may have sent only specific patients.
// Preferred source is the notification row (?n=), whose `data` column holds the
// full id list. The legacy ?ids= form is still honoured for links sent before
// this change, and for a doctor opening their own list.
$onlyIds = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))),
    fn($v) => $v > 0
)));

$notificationId = intval($_GET['n'] ?? 0);
if ($notificationId > 0) {
    require_once __DIR__ . '/classes/Notification.php';
    // getById scopes to user_id, so nobody can read another user's list
    $notificationManager = new Notification();
    $note = $notificationManager->getById($notificationId, (int)$currentUser['id']);
    if ($note && $note['type'] === Notification::TYPE_RECEPTION_LIST) {
        $noteData = json_decode((string)$note['data'], true) ?: [];
        if (!empty($noteData['ids']) && is_array($noteData['ids'])) {
            $onlyIds = array_values(array_unique(array_filter(
                array_map('intval', $noteData['ids']),
                fn($v) => $v > 0
            )));
        }
        // Opening the actual work list completes this recipient's inbox item and
        // immediately removes its "new" badge from the sidebar.
        $notificationManager->markAsRead($notificationId, (int)$currentUser['id']);
    }
}

// Reception can finish the call directly from this work list. The appointment
// must belong to this exact doctor/date and, for a selected batch, to its ids.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_called_to_center') {
    Csrf::require();
    $appointmentId = max(0, (int)($_POST['appointment_id'] ?? 0));
    $appointmentManager = new Appointment();
    $oldAppointment = $appointmentId > 0 ? $appointmentManager->getById($appointmentId) : null;
    $allowed = $oldAppointment
        && (int)$oldAppointment['doctor_id'] === $listDoctorId
        && ($oldAppointment['appointment_date'] ?? '') === $listDate
        && (!$onlyIds || in_array($appointmentId, $onlyIds, true))
        && in_array($oldAppointment['status'] ?? '', ['scheduled', 'confirmed', 'rescheduled'], true);

    $result = ['success' => false];
    if ($allowed) {
        $result = $appointmentManager->markCalledToCenter($appointmentId, (int)$currentUser['id']);
        if (!empty($result['success'])) {
            $newAppointment = $appointmentManager->getById($appointmentId);
            AuditLog::logChange(
                'appointment_called_to_center',
                'appointments',
                $appointmentId,
                [
                    'called_to_center_at' => $oldAppointment['called_to_center_at'] ?? null,
                    'called_to_center_by' => $oldAppointment['called_to_center_by'] ?? null,
                ],
                [
                    'called_to_center_at' => $newAppointment['called_to_center_at'] ?? null,
                    'called_to_center_by' => $newAppointment['called_to_center_by'] ?? null,
                ],
                (int)$currentUser['id']
            );
        }
    }

    $returnParams = ['doctor' => $listDoctorId, 'date' => $listDate];
    if ($notificationId > 0) {
        $returnParams['n'] = $notificationId;
    } elseif ($onlyIds) {
        $returnParams['ids'] = implode(',', $onlyIds);
    }
    $returnParams[!empty($result['success']) ? 'call_marked' : 'call_error'] = 1;
    header('Location: ' . APP_URL . '/reception-call-list.php?' . http_build_query($returnParams));
    exit;
}

// The list — NO debt query on purpose (reception must not see debt)
$params = [$listDoctorId, $listDate];
$idsSql = '';
if ($onlyIds) {
    $idsSql = ' AND a.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
    $params = array_merge($params, $onlyIds);
}
$rows = $db->fetchAll(
    "SELECT a.id, a.appointment_time, a.duration, a.status, a.promised_amount, a.notes,
            a.called_to_center_at,
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
$calledCount = 0;
foreach ($rows as $r) {
    $totalPromised += (float)($r['promised_amount'] ?? 0);
    if (!empty($r['called_to_center_at'])) {
        $calledCount++;
    }
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
.rcl-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
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
.rcl-time { font-weight: 700; color: var(--win-accent, #0078d4); font-size: 14px; }
.rcl-phone { direction: ltr; color: var(--win-accent, #0078d4); text-decoration: none; }
.rcl-phone:hover { text-decoration: underline; }
.rcl-promise { color: #15803d; font-weight: 700; }
.rcl-call-btn, .rcl-wa-btn {
    display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px;
    border-radius: 6px; text-decoration: none; font-size: 12px; color: #fff;
}
.rcl-call-btn { background: var(--win-accent, #0078d4); border: 1px solid var(--win-accent-dark, #005a9e); }
.rcl-call-btn:hover { background: var(--win-accent-dark, #005a9e); color: #fff; }
.rcl-wa-btn { background: #22c55e; border: 1px solid #16a34a; margin-right: 4px; }
.rcl-wa-btn:hover { background: #16a34a; color: #fff; }
.rcl-mark-btn {
    display: inline-flex; align-items: center; gap: 5px; padding: 5px 9px;
    border-radius: 6px; border: 1px solid #0f766e; background: #fff;
    color: #0f766e; font: inherit; font-size: 11px; cursor: pointer; margin-right: 4px;
}
.rcl-mark-btn:hover { background: #f0fdfa; }
.rcl-call-state { display: inline-flex; flex-direction: column; align-items: center; gap: 2px; font-size: 11px; }
.rcl-call-state.called { color: #15803d; font-weight: 700; }
.rcl-call-state.not-called { color: #9a5b12; }
.rcl-call-state small { color: #65788b; font-weight: 500; direction: ltr; }
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

<?php if (isset($_GET['call_marked'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle"></i> پەیوەندیکردنەکە وەک بانگکراو تۆمارکرا.</div>
<?php elseif (isset($_GET['call_error'])): ?>
<div class="alert alert-danger" data-auto-hide><i class="fas fa-exclamation-circle"></i> نەتوانرا پەیوەندیکردنەکە تۆمار بکرێت.</div>
<?php endif; ?>

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
        <div class="label">بانگکراو / ماوە</div>
        <div class="value" style="font-size:16px;"><span style="color:#15803d;"><?= $calledCount ?></span> / <span style="color:#b45309;"><?= count($rows) - $calledCount ?></span></div>
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
                <th>بانگکراو</th>
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
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$apt['patient_id'] ?>" style="color: var(--win-accent, #0078d4); text-decoration: none; font-weight: 600;">
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
                <td>
                    <?php if (!empty($apt['called_to_center_at'])): ?>
                    <span class="rcl-call-state called">
                        <span><i class="fas fa-phone-alt"></i> بانگکراوە</span>
                        <small><?= htmlspecialchars(date('Y-m-d H:i', strtotime($apt['called_to_center_at']))) ?></small>
                    </span>
                    <?php else: ?>
                    <span class="rcl-call-state not-called"><i class="fas fa-phone-slash"></i> بانگ‌نەکراوە</span>
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
                    <?php if (in_array($apt['status'], ['scheduled', 'confirmed', 'rescheduled'], true)): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="mark_called_to_center">
                        <input type="hidden" name="appointment_id" value="<?= (int)$apt['id'] ?>">
                        <button class="rcl-mark-btn" type="submit" title="<?= !empty($apt['called_to_center_at']) ? 'دووبارە بانگکردن و نوێکردنەوەی کات' : 'نیشانکردن وەک بانگکراو' ?>">
                            <i class="fas fa-check"></i>
                        </button>
                    </form>
                    <?php endif; ?>
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
