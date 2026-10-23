<?php
/**
 * پلانەکانی چارەسەر — who is waiting for whom.
 *
 *   Doctor:     «ئاراستەکراو بۆ من» — plans a colleague (or reception, for
 *               «چەند دکتۆرێک») sent to me to treat; I book the visit from the
 *               plan page. «پلانەکانی پشکنینی من» — plans I examined, and
 *               where each patient went.
 *   Reception:  «چاوەڕوانی دیاریکردنی دکتۆر» — «چەند دکتۆرێک» plans; pick the
 *               doctor right here. «پلانە ئاراستەکراوەکان» — every routed plan.
 *
 * The decisions themselves are made and enforced in TreatmentPlan
 * (designate / chooseDoctor / bookAppointment) via ajax/treatment_plan.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/AccessGuard.php';
require_once __DIR__ . '/../classes/TreatmentPlan.php';

$auth = new Auth();
$auth->requireLogin();

$perm = Permission::getInstance();
if (!$perm->can('treatment_plan.view')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$plans = new TreatmentPlan();
$me = (int)(($auth->getCurrentUser())['id'] ?? 0);
$isDoctorView = AccessGuard::isRestrictedDoctor();
$canAssign = $perm->can('treatment_plan.assign');

if ($isDoctorView) {
    $tabs = [
        'mine'     => ['ئاراستەکراو بۆ من', 'fa-inbox'],
        'examined' => ['پلانەکانی پشکنینی من', 'fa-stethoscope'],
    ];
} else {
    $tabs = [];
    if ($canAssign) {
        $tabs['awaiting'] = ['چاوەڕوانی دیاریکردنی دکتۆر', 'fa-user-clock'];
    }
    $tabs['routed'] = ['پلانە ئاراستەکراوەکان', 'fa-route'];
}
$tab = $_GET['tab'] ?? '';
if (!isset($tabs[$tab])) {
    $tab = array_key_first($tabs);
}

switch ($tab) {
    case 'mine':     $rows = $plans->directedTo($me, true); break;
    case 'examined': $rows = $plans->examinedBy($me); break;
    case 'awaiting': $rows = $plans->awaitingReception(); break;
    default:         $rows = $plans->routedPlans(); break;
}
$counts = [];
if ($isDoctorView) {
    $counts['mine'] = $plans->countDirectedUnbooked($me);
} elseif ($canAssign) {
    $counts['awaiting'] = $plans->countAwaitingReception();
}
$doctorNames = $plans->activeDoctors();

$stateText = function (array $p) {
    $treating = TreatmentPlan::treatingDoctorId($p);
    $name = $treating !== null && $treating === (int)$p['doctor_id'] ? ($p['doctor_name'] ?? '') : ($p['treating_doctor_name'] ?? '');
    switch (TreatmentPlan::designationState($p)) {
        case 'self':               return ['خۆی', 'is-self'];
        case 'doctor':             return [$name, 'is-doctor'];
        case 'chosen':             return [$name . ' (پێشوازی)', 'is-doctor'];
        case 'awaiting_reception': return ['چەند دکتۆرێک — چاوەڕوان', 'is-waiting'];
        default:                   return ['دیاری نەکراوە', 'is-undecided'];
    }
};
$weekdays = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];

$pageTitle = 'پلانەکانی چارەسەر';
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => 'پلانەکانی چارەسەر'],
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <h4 style="margin:0;"><i class="fas fa-clipboard-list"></i> پلانەکانی چارەسەر — کێ چارەسەر دەکات؟</h4>
        <small style="color:#6b7280;">
            <?= $isDoctorView
                ? 'ئەو پلانانەی بۆ تۆ نێردراون: پلانەکە بکەرەوە و خۆت مەوعید دابنێ.'
                : 'پلانی «چەند دکتۆرێک»: پێشوازی بڕیار دەدات نەخۆشەکە بۆ کام دکتۆر بچێت. مەوعید تەنها دکتۆری چارەسەرکەر خۆی دایدەنێت.' ?>
        </small>
    </div>
    <nav class="tpl-tabs" aria-label="پلانەکان">
        <?php foreach ($tabs as $key => [$label, $icon]): ?>
        <a class="tpl-tab <?= $key === $tab ? 'active' : '' ?>" href="?tab=<?= $key ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>>
            <i class="fas <?= $icon ?>" aria-hidden="true"></i>
            <span><?= $label ?></span>
            <?php if (!empty($counts[$key])): ?><span class="tpl-tab-count"><?= (int)$counts[$key] ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="panel-body" style="padding:0;">
        <?php if (!$rows): ?>
        <div class="tpl-empty">
            <i class="fas fa-clipboard-check"></i>
            <p>
                <?php if ($tab === 'awaiting'): ?>هیچ پلانێک چاوەڕوانی دیاریکردنی دکتۆر نییە.
                <?php elseif ($tab === 'mine'): ?>هیچ پلانێک بۆ تۆ نەنێردراوە.
                <?php else: ?>هیچ پلانێک نییە.<?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="tpl-scroll">
            <table class="data-grid tpl-table">
                <thead>
                    <tr>
                        <th>پلان</th>
                        <th>نەخۆش</th>
                        <th>دکتۆری دەستنیشانکەر</th>
                        <th>کێ چارەسەر دەکات</th>
                        <th>مەوعید</th>
                        <th>بەروار</th>
                        <?php if ($tab === 'awaiting' && $canAssign): ?><th style="min-width:230px;">ناردن بۆ دکتۆر</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $p):
                        [$stText, $stClass] = $stateText($p);
                        $cands = TreatmentPlan::candidateIds($p);
                        $planUrl = APP_URL . '/treatment-plans/view.php?id=' . (int)$p['id'];
                    ?>
                    <tr id="tplRow<?= (int)$p['id'] ?>">
                        <td>
                            <a href="<?= $planUrl ?>" style="font-weight:700; direction:ltr; display:inline-block;"><?= htmlspecialchars($p['plan_number']) ?></a>
                            <div class="tpl-sub"><?= (int)$p['pending_count'] ?> / <?= (int)$p['item_count'] ?> ئیش چاوەڕوان</div>
                        </td>
                        <td>
                            <b><?= htmlspecialchars($p['patient_name']) ?></b>
                            <div class="tpl-sub"><bdi><?= htmlspecialchars($p['patient_code']) ?></bdi> · <bdi><?= htmlspecialchars($p['patient_phone'] ?? '') ?></bdi></div>
                        </td>
                        <td><?= htmlspecialchars($p['doctor_name'] ?? '—') ?></td>
                        <td>
                            <span class="tpl-state <?= $stClass ?>"><?= htmlspecialchars($stText) ?></span>
                            <?php if ($cands && ($p['treating_mode'] ?? '') === 'doctors'): ?>
                            <div class="tpl-sub">لە نێوان: <?= htmlspecialchars(implode('، ', array_map(function ($id) use ($doctorNames) {
                                return $doctorNames[$id] ?? ('#' . $id);
                            }, $cands))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['has_live_appointment'])):
                                $ts = strtotime($p['appointment_date'] . ' ' . $p['appointment_time']); ?>
                            <span class="tpl-appt"><?= $weekdays[(int)date('w', $ts)] ?> <bdi><?= date('Y/m/d H:i', $ts) ?></bdi></span>
                            <?php else: ?>
                            <span class="tpl-noappt">دانەنراوە</span>
                            <?php endif; ?>
                        </td>
                        <td><bdi><?= htmlspecialchars(substr((string)($p['designated_at'] ?: $p['created_at']), 0, 10)) ?></bdi></td>
                        <?php if ($tab === 'awaiting' && $canAssign): ?>
                        <td>
                            <div class="tpl-choose">
                                <select class="form-control tpl-choose-sel" aria-label="دکتۆر">
                                    <option value="">— دکتۆر —</option>
                                    <?php foreach (($cands ?: array_keys($doctorNames)) as $cid): ?>
                                    <?php if (!isset($doctorNames[$cid])) continue; ?>
                                    <option value="<?= (int)$cid ?>"><?= htmlspecialchars($doctorNames[$cid]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-primary btn-sm" onclick="tplChoose(<?= (int)$p['id'] ?>, this)">
                                    <i class="fas fa-paper-plane"></i> ناردن
                                </button>
                            </div>
                            <div class="tpl-msg"></div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.tpl-scroll { overflow-x: auto; }
.tpl-table td { vertical-align: top; font-size: 13px; }
.tpl-sub { font-size: 11.5px; color: #6b7280; margin-top: 3px; }
.tpl-state { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #eef2ff; color: #3730a3; }
.tpl-state.is-self { background: #ecfdf5; color: #047857; }
.tpl-state.is-doctor { background: #eff6ff; color: #1d4ed8; }
.tpl-state.is-waiting { background: #fef3c7; color: #92400e; }
.tpl-state.is-undecided { background: #fff7ed; color: #9a3412; }
.tpl-appt { font-weight: 600; color: #0c4a6e; }
.tpl-noappt { color: #9ca3af; }
.tpl-choose { display: flex; gap: 6px; align-items: center; }
.tpl-choose .form-control { min-width: 0; flex: 1; padding: 5px 8px; font-size: 12.5px; }
.tpl-msg { font-size: 12px; font-weight: 600; margin-top: 4px; }
.tpl-msg.is-error { color: #c62828; }
.tpl-msg.is-ok { color: #2e7d32; }
.tpl-empty { text-align: center; padding: 48px 16px; color: #6b7280; }
.tpl-empty i { font-size: 34px; color: #c4b5fd; margin-bottom: 10px; }
.tpl-tabs { display: flex; gap: 4px; padding: 0 12px; border-bottom: 1px solid #e8ecf1; overflow-x: auto; }
.tpl-tab { display: inline-flex; align-items: center; gap: 7px; padding: 11px 14px; color: #6b7684; font-size: 13px; font-weight: 600; text-decoration: none; border-bottom: 2px solid transparent; white-space: nowrap; }
.tpl-tab:hover { color: #1f2a37; background: #f7fafc; text-decoration: none; }
.tpl-tab.active { color: #111827; border-bottom-color: #5e35b1; background: #f5f3ff; }
.tpl-tab i { color: #9aa5b1; }
.tpl-tab.active i { color: #5e35b1; }
.tpl-tab-count { direction: ltr; unicode-bidi: isolate; padding: 1px 7px; border-radius: 999px; background: #ede9fe; color: #4c1d95; font-size: 11px; }
</style>

<?php if ($tab === 'awaiting' && $canAssign): ?>
<script>
function tplChoose(planId, btn) {
    const cell = btn.closest('td');
    const sel = cell.querySelector('.tpl-choose-sel');
    const msg = cell.querySelector('.tpl-msg');
    msg.className = 'tpl-msg';
    msg.textContent = '';
    if (!sel.value) { msg.textContent = 'تکایە دکتۆرێک هەڵبژێرە'; msg.classList.add('is-error'); return; }
    const fd = new FormData();
    fd.append('action', 'choose_doctor');
    fd.append('plan_id', planId);
    fd.append('doctor_id', sel.value);
    btn.disabled = true;
    fetch('<?= APP_URL ?>/ajax/treatment_plan.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            if (!res || !res.success) {
                msg.textContent = (res && (res.error || res.message)) || 'هەڵە';
                msg.classList.add('is-error');
                return;
            }
            msg.textContent = 'نێردرا بۆ ' + sel.options[sel.selectedIndex].text.trim();
            msg.classList.add('is-ok');
            sel.disabled = true;
            btn.hidden = true;
            const row = document.getElementById('tplRow' + planId);
            if (row) row.style.opacity = '.6';
        })
        .catch(function () { btn.disabled = false; msg.textContent = 'هەڵە لە پەیوەندی'; msg.classList.add('is-error'); });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
