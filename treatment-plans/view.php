<?php
/**
 * پلانی چارەسەر — the plan page, built like cases/view.php.
 *
 * Same shape as a case: header card, odontogram (ددان) or occlusal arch chart
 * (ئەرک), the same treatment dialogs and the same tooth-detail panel — but every
 * figure here is a خەمڵاندن. Nothing on this page touches an invoice, a payment
 * or a ledger; the only door to the money model is گواستنەوە بۆ کەیس, handled by
 * TreatmentPlan::transferItems() through CaseManager::addTreatment().
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/AccessGuard.php';
require_once __DIR__ . '/../classes/TreatmentPlan.php';
require_once __DIR__ . '/../classes/DentalChart.php';
require_once __DIR__ . '/../classes/Treatment.php';
require_once __DIR__ . '/../classes/CaseNamePreset.php';

$auth = new Auth();
$auth->requireLogin();

$perm = Permission::getInstance();
if (!$perm->can('treatment_plan.view')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$planId = (int)($_GET['id'] ?? 0);
$plans = new TreatmentPlan();
$plan = $planId > 0 ? $plans->getById($planId) : null;
if (!$plan) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}
// A restricted doctor sees a plan he examined or one he was sent to treat —
// nothing else, the same isolation cases follow.
$restrictedDoctor = AccessGuard::isRestrictedDoctor();
$viewerId = (int)(($auth->getCurrentUser())['id'] ?? 0);
if ($restrictedDoctor && !TreatmentPlan::doctorMaySee($plan, $viewerId)) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// کێ چارەسەر دەکات؟ — who examined (دەستنیشانکەر), who treats (بینراو).
$treatingId       = TreatmentPlan::treatingDoctorId($plan);
$designState      = TreatmentPlan::designationState($plan);
$isExaminer       = (int)$plan['doctor_id'] === $viewerId;
$isTreatingDoctor = $treatingId !== null && $treatingId === $viewerId;
$planIsOpen       = in_array($plan['status'], TreatmentPlan::EDITABLE_STATUSES, true);

// The plan's content is the examiner's note; a doctor it was sent to reads it.
$canWrite    = $perm->can('treatment_plan.write') && (!$restrictedDoctor || $isExaminer);
// Work moves only into the treating doctor's own case — never before
// reception has picked the doctor, never by the examiner for a colleague.
$canTransfer = $perm->can('treatment_plan.transfer') && $treatingId !== null
    && (!$restrictedDoctor || $isTreatingDoctor);
$isEditable  = $canWrite && $planIsOpen;
$canDesignate = $canWrite && $planIsOpen;
$canChoose    = $perm->can('treatment_plan.assign') && $planIsOpen && $designState !== 'undecided'
    && ($plan['treating_mode'] ?? null) === TreatmentPlan::MODE_DOCTORS;
// Only the treating doctor books, for himself.
$canBook      = $isTreatingDoctor && $plan['status'] !== 'cancelled';
$liveAppointment = $plans->liveAppointment($plan);
$routeLock    = $plans->designationLockReason($plan);
$doctorChoices = $plans->activeDoctors();
$candidateIds  = TreatmentPlan::candidateIds($plan);
$planVisit = null;
if (!empty($plan['visit_id'])) {
    $planVisit = Database::getInstance()->fetch(
        "SELECT id, visit_date FROM visits WHERE id = :id",
        ['id' => (int)$plan['visit_id']]
    ) ?: null;
}
$isArch      = ($plan['scope_type'] ?? 'tooth') === 'arch';
$selectedArch = $plan['selected_arch'] ?: null;

$items = $plans->getItems($planId);
$pendingCount = 0;
foreach ($items as $it) {
    if ($it['status'] === 'planned') { $pendingCount++; }
}

$dentalChart = new DentalChart();
$teethSets = [
    'permanent' => $dentalChart->getAllTeeth(false),
    'primary'   => $dentalChart->getAllTeeth(true),
];
$toothNames = [];
$palmerNumbers = [];
foreach ($dentalChart->getAllToothNumbers() as $num) {
    $toothNames[$num] = $dentalChart->getToothName($num);
    $palmerNumbers[$num] = $dentalChart->getPalmerNumber($num);
}

$treatmentManager = new Treatment();
$toothTreatments = $treatmentManager->getByScope('tooth');
$archTreatments  = $treatmentManager->getByScope('arch');

// Transfer targets: names for a new case (matching this plan's scope) and the
// patient's open cases.
$caseNames = [];
$caseTargets = [];
if ($canTransfer) {
    foreach ((new CaseNamePreset())->all() as $n) {
        if (CaseNamePreset::scope($n['scope_type'] ?? '') === ($isArch ? 'arch' : 'tooth')) {
            $caseNames[] = $n['name'];
        }
    }
    $caseTargets = $plans->transferTargets((int)$plan['patient_id'], $treatingId);
}

// One line that says where the patient goes, used in the header and panel.
$treatingName = $treatingId !== null
    ? ($treatingId === (int)$plan['doctor_id'] ? ($plan['doctor_name'] ?? '') : ($plan['treating_doctor_name'] ?? ''))
    : '';
$routeMeta = [
    'undecided'          => ['fa-question-circle', 'دیاری نەکراوە', 'is-undecided'],
    'self'               => ['fa-user-md', 'خۆی چارەسەری دەکات', 'is-self'],
    'doctor'             => ['fa-share', 'نێردراوە بۆ ' . $treatingName, 'is-doctor'],
    'awaiting_reception' => ['fa-users', 'چەند دکتۆرێک — چاوەڕوانی بڕیاری پێشوازی', 'is-waiting'],
    'chosen'             => ['fa-share', 'پێشوازی ناردی بۆ ' . $treatingName, 'is-doctor'],
];
[$routeIcon, $routeText, $routeClass] = $routeMeta[$designState];

$statusMeta = [
    'open'        => ['چاوەڕوانی بڕیاری نەخۆش', 'badge-blue'],
    'partial'     => ['بەشێکی گوازراوەتەوە',      'badge-orange'],
    'transferred' => ['تەواو گوازراوەتەوە',        'badge-green'],
    'cancelled'   => ['هەڵوەشێنراوەتەوە',          'badge-secondary'],
];
$statusLabel = $statusMeta[$plan['status']][0] ?? $plan['status'];
$statusBadge = $statusMeta[$plan['status']][1] ?? 'badge-secondary';
$archLabel = $selectedArch === 'both' ? 'هەردوو ئەرک' : ($selectedArch === 'upper' ? 'ئەرکی سەرەوە' : ($selectedArch === 'lower' ? 'ئەرکی خوارەوە' : 'ئەرک'));

$pageTitle = $plan['plan_number'];
// The plan header carries the number and the way back to the patient.
$hidePageHeader = true;
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => $plan['patient_name'], 'url' => APP_URL . '/patients/view.php?id=' . (int)$plan['patient_id'] . '&tab=plans'],
    ['title' => $plan['plan_number']],
];

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/invoice-pane.css?v=<?= (int)filemtime(__DIR__ . '/../assets/css/invoice-pane.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/treatment-plan-layout.css?v=<?= (int)filemtime(__DIR__ . '/../assets/css/treatment-plan-layout.css') ?>">

<div class="tp-workspace">
<?php
// One place for everything about the plan: the dark header (as on a case).
// Shown once each — the number, the state, who treats, the appointment, the
// figures; the notes, the estimated time and the duration are under the
// work table, so they are not repeated here.
$statusPill = ['open' => 'ch-pill--blue', 'partial' => 'ch-pill--pending', 'transferred' => 'ch-pill--open', 'cancelled' => 'ch-pill--closed'][$plan['status']] ?? 'ch-pill--closed';
$apptDays = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
$apptTs = $liveAppointment ? strtotime($liveAppointment['appointment_date'] . ' ' . $liveAppointment['appointment_time']) : null;
$ownAppt = $liveAppointment && $canBook && (int)$liveAppointment['doctor_id'] === $viewerId;
?>
<div class="case-hero tp-hero">
    <div class="ch-top">
        <div class="ch-id">
            <div class="ch-icon"><i class="fas <?= $isArch ? 'fa-teeth' : 'fa-clipboard-list' ?>"></i></div>
            <div class="ch-id-text">
                <div class="ch-title-row">
                    <h2 class="ch-number"><?= htmlspecialchars($plan['plan_number']) ?></h2>
                    <span class="ch-pill <?= $statusPill ?>" id="pvStatusBadge"><?= $statusLabel ?></span>
                    <?php if ($isArch): ?>
                    <span class="ch-pill"><i class="fas fa-teeth"></i> <span id="pvArchLabel"><?= $archLabel ?></span></span>
                    <?php endif; ?>
                </div>
                <div class="ch-meta">
                    <a class="ch-meta-item ch-patient" href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$plan['patient_id'] ?>&tab=plans">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($plan['patient_name']) ?>
                    </a>
                    <?php if (!empty($plan['doctor_name'])): ?>
                    <span class="ch-meta-item" title="دکتۆری دەستنیشانکەر"><i class="fas fa-stethoscope"></i> د. <?= htmlspecialchars($plan['doctor_name']) ?></span>
                    <?php endif; ?>
                    <span class="ch-meta-item"><i class="fas fa-calendar"></i> <?= date('Y-m-d', strtotime($plan['created_at'])) ?></span>
                    <?php if ($planVisit): ?>
                    <span class="ch-meta-item" title="سەردانی پشکنین"><i class="fas fa-door-open"></i>
                        <?php if (!$restrictedDoctor && $perm->can('page.visits')): ?>
                        <a href="<?= APP_URL ?>/edit_visit.php?id=<?= (int)$planVisit['id'] ?>">سەردان #<?= (int)$planVisit['id'] ?></a>
                        <?php else: ?>سەردان #<?= (int)$planVisit['id'] ?><?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($liveAppointment): ?>
                    <span class="ch-meta-item ch-appt" title="مەوعیدی چارەسەر">
                        <i class="fas fa-calendar-check"></i>
                        <?= $apptDays[(int)date('w', $apptTs)] ?> <bdi><?= date('Y/m/d', $apptTs) ?></bdi> · <bdi><?= date('H:i', $apptTs) ?></bdi>
                        <?php if (!empty($liveAppointment['doctor_name'])): ?> · <?= htmlspecialchars($liveAppointment['doctor_name']) ?><?php endif; ?>
                    </span>
                    <?php if ($ownAppt): ?>
                    <button type="button" class="ph-link" onclick="pvBookOpen()">گۆڕینی کات</button>
                    <button type="button" class="ph-link" onclick="pvBookCancel()">لابردن</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($plan['chief_complaint'])): ?>
                <div class="ch-note"><i class="fas fa-notes-medical"></i> <b>گلەیی:</b> <span id="pvComplaintInline"><?= htmlspecialchars($plan['chief_complaint']) ?></span></div>
                <?php endif; ?>

            </div>
        </div>
        <div class="ch-side">
            <div class="ch-actions">
                <?php if ($canTransfer): ?>
                <button type="button" class="btn ch-btn ch-btn--gold" id="pvTransferBtn" style="<?= $pendingCount ? '' : 'display:none;' ?>" onclick="pvOpenTransfer()">
                    <i class="fas fa-exchange-alt"></i> گواستنەوە بۆ کەیس (<span id="pvPendingCount"><?= $pendingCount ?></span>)
                </button>
                <?php endif; ?>
                <?php if ($canBook && !$liveAppointment): ?>
                <button type="button" class="btn ch-btn ch-btn--white" onclick="pvBookOpen()"><i class="fas fa-calendar-plus"></i> دانانی مەوعید</button>
                <?php endif; ?>
                <a class="btn ch-btn ch-btn--glass" target="_blank" href="<?= APP_URL ?>/treatment-plans/print.php?id=<?= $planId ?>" title="چاپکردن (٢ کۆپی)">
                    <i class="fas fa-print"></i> چاپ
                </a>
                <?php if ($isEditable): ?>
                <button type="button" class="btn ch-btn ch-btn--glass" onclick="pvOpenHeaderEdit()" title="گلەیی، تێبینی، ماوەی چارەسەر">
                    <i class="fas fa-edit"></i> دەستکاری
                </button>
                <button type="button" class="btn ch-btn ch-btn--glass" onclick="pvCancelPlan()">
                    <i class="fas fa-ban"></i> هەڵوەشاندنەوە
                </button>
                <?php endif; ?>
            </div>
            <?php // A plan is an estimate: nothing here reaches the accounts until گواستنەوە بۆ کەیس. ?>
            <div class="ch-money" title="خەمڵاندن — بڕەکان تەنها دوای گواستنەوە بۆ کەیس دەچنە ناو حسابات">
                <div class="ch-stat">
                    <span class="ch-stat-label"><i class="fas fa-calculator"></i> کۆی خەمڵێنراو</span>
                    <span class="ch-stat-value" data-plan-bind="total_estimated"><?= number_format((float)$plan['total_estimated']) ?></span>
                </div>
                <div class="ch-stat">
                    <span class="ch-stat-label"><i class="fas fa-percent"></i> داشکاندن</span>
                    <span class="ch-stat-value" data-plan-bind="total_discount"><?= number_format((float)$plan['total_discount']) ?></span>
                </div>
                <div class="ch-stat ch-stat--paid">
                    <span class="ch-stat-label"><i class="fas fa-check-circle"></i> دوای داشکاندن</span>
                    <span class="ch-stat-value" data-plan-bind="total_final"><?= number_format((float)$plan['total_final']) ?></span>
                </div>
            </div>
        </div>
    </div>
        <?php // کێ چارەسەر دەکات؟ — the examiner routes, reception picks for «چەند دکتۆرێک» ?>
        <div class="ph-route <?= $routeClass ?>" id="pvRoutePanel">
            <span class="ph-route-label"><i class="fas fa-route"></i> چارەسەرکەر</span>
            <span class="ph-route-now"><i class="fas <?= $routeIcon ?>"></i> <?= htmlspecialchars($routeText) ?></span>
            <?php if (($designState === 'awaiting_reception' || $designState === 'chosen') && $candidateIds): ?>
            <span class="ph-route-sub">لە نێوان: <?= htmlspecialchars(implode('، ', array_map(fn($id) => $doctorChoices[$id] ?? ('#' . $id), $candidateIds))) ?></span>
            <?php endif; ?>
            <?php if ($canDesignate && !$routeLock): ?>
                <?php if ($designState === 'undecided'): ?>
                <button type="button" class="ph-route-btn" onclick="pvRouteSelf()"><i class="fas fa-user-md"></i> خۆم</button>
                <button type="button" class="ph-route-btn" onclick="pvRouteOpen('doctor')"><i class="fas fa-share"></i> دکتۆرێکی تر</button>
                <button type="button" class="ph-route-btn" onclick="pvRouteOpen('doctors')"><i class="fas fa-users"></i> چەند دکتۆرێک</button>
                <?php else: ?>
                <button type="button" class="ph-route-btn" onclick="pvRouteOpen('<?= htmlspecialchars($plan['treating_mode']) ?>')"><i class="fas fa-exchange-alt"></i> گۆڕین</button>
                <?php endif; ?>
            <?php elseif ($canDesignate && $routeLock): ?>
            <span class="ph-route-sub" title="<?= htmlspecialchars($routeLock) ?>"><i class="fas fa-lock"></i> <?= htmlspecialchars($routeLock) ?></span>
            <?php endif; ?>
            <?php if ($canChoose): ?>
                <?php if ($routeLock && $designState === 'chosen'): ?>
                <span class="ph-route-sub"><i class="fas fa-lock"></i> <?= htmlspecialchars($routeLock) ?></span>
                <?php else: ?>
                <span class="ph-route-choose">
                    <select id="pvChooseDoctor" aria-label="پێشوازی: نەخۆشەکە بنێرە بۆ">
                        <option value="">— پێشوازی: دکتۆرێک هەڵبژێرە —</option>
                        <?php foreach (($candidateIds ?: array_keys($doctorChoices)) as $cid): ?>
                        <?php if (!isset($doctorChoices[$cid])) continue; ?>
                        <option value="<?= (int)$cid ?>" <?= (int)$cid === (int)($plan['treating_doctor_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($doctorChoices[$cid]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="ph-route-btn is-strong" id="pvChooseBtn" onclick="pvChooseDoctor()">
                        <i class="fas fa-paper-plane"></i> <?= $designState === 'chosen' ? 'گۆڕین' : 'ناردن' ?>
                    </button>
                </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
</div>

<?php if ($plan['status'] === 'cancelled'): ?>
<div class="alert alert-warning"><i class="fas fa-ban alert-icon"></i>
    ئەم پلانە هەڵوەشێنراوەتەوە<?= !empty($plan['cancel_reason']) ? ': ' . htmlspecialchars($plan['cancel_reason']) : '' ?>
</div>
<?php endif; ?>

<div class="panel tp-clinical-panel">
    <div class="tabs-container" role="tablist" aria-label="پیشاندانی پلان">
        <button type="button" class="tab tab--chart active" id="pvTab-chart" role="tab" aria-controls="tab-chart" aria-selected="true" onclick="showTab('chart', this)">
            <i class="fas <?= $isArch ? 'fa-teeth' : 'fa-tooth' ?>" aria-hidden="true"></i>
            <span class="tab-label"><?= $isArch ? 'چارتی ئەرک' : 'چارتی ددان' ?></span>
        </button>
        <button type="button" class="tab tab--treat" id="pvTab-items" role="tab" aria-controls="tab-items" aria-selected="false" onclick="showTab('items', this)">
            <i class="fas fa-tasks" aria-hidden="true"></i>
            <span class="tab-label">چارەسەرەکان</span>
            <span class="tab-count" id="pvItemCount"><?= count($items) ?></span>
        </button>
    </div>

    <!-- ═══ Chart tab ═══ -->
    <div class="tab-content active" id="tab-chart" role="tabpanel" aria-labelledby="pvTab-chart" style="display: block;">
        <div class="panel-body" style="padding: 12px;">
            <div class="ph-bar" id="pvChartToolbar">
                <?php if (!$isArch): ?>
                <div class="pv-chart-tools">
                <div id="dentitionForm" class="dentition-switch" role="group" aria-label="پیشاندانی چارتەکان">
                    <button type="button" data-dentition="permanent" class="dentition-opt is-active" aria-pressed="true"
                            onclick="toggleDentitionPane('permanent')" title="ددانە هەمیشەییەکان — ٣٢ ددان (پیشاندان/شاردنەوە)">
                        <i class="fas fa-tooth"></i> گەورە
                    </button>
                    <button type="button" data-dentition="primary" class="dentition-opt is-active" aria-pressed="true"
                            onclick="toggleDentitionPane('primary')" title="ددانی شیری — ٢٠ ددان (پیشاندان/شاردنەوە)">
                        <i class="fas fa-child"></i> منداڵ
                    </button>
                </div>
                    <button type="button" id="toothSoundBtn" class="tooth-sound-btn" aria-pressed="true"
                            onclick="toggleToothSound()"
                            title="دەنگی ددانەکان کاتێک ماوس دەبەیتە سەریان (کردنەوە/کوژاندنەوە)">
                        <i class="fas fa-volume-up"></i>
                    </button>
                </div>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($isEditable): ?>
                <button class="btn btn-primary btn-sm" onclick="<?= $isArch ? 'pvOpenArchDialog()' : 'pvOpenItemDialog({})' ?>" title="<?= $isArch ? '' : 'یان ددان هەڵبژێرە (کلیک یان ڕاکێشان) و کلیکی ڕاست بکە' ?>">
                    <i class="fas fa-plus"></i> زیادکردن
                </button>
                <?php endif; ?>
            </div>

            <!-- Whole-arch / toothless jobs strip (part of the chart) -->
            <div id="chartServiceWork" class="chart-service-work" aria-live="polite"></div>

            <?php if ($isArch): ?>
            <div class="arch-view-chart">
                <div class="arch-occ-panel" id="archPanel-upper" <?= in_array($selectedArch, ['upper', 'both', null], true) ? '' : 'hidden' ?>>
                    <div class="arch-occ-chart" data-arch="upper"></div>
                    <div class="arch-occ-info">
                        <div class="arch-occ-title">
                            <span class="arch-occ-mouth arch-occ-mouth--upper"></span>
                            <div><h4>ئەرکی سەرەوە (Maxilla)</h4><small>قەوسی سەرەوە — 16 ددان</small></div>
                        </div>
                        <div class="arch-occ-stats" data-arch-stats="upper"></div>
                        <div class="arch-occ-statusbar" data-arch-status="upper"></div>
                    </div>
                </div>
                <div class="arch-occ-panel" id="archPanel-lower" <?= in_array($selectedArch, ['lower', 'both', null], true) ? '' : 'hidden' ?>>
                    <div class="arch-occ-chart" data-arch="lower"></div>
                    <div class="arch-occ-info">
                        <div class="arch-occ-title">
                            <span class="arch-occ-mouth arch-occ-mouth--lower"></span>
                            <div><h4>ئەرکی خوارەوە (Mandible)</h4><small>قەوسی خوارەوە — 16 ددان</small></div>
                        </div>
                        <div class="arch-occ-stats" data-arch-stats="lower"></div>
                        <div class="arch-occ-statusbar" data-arch-status="lower"></div>
                    </div>
                </div>
                <div class="arch-occ-legend" id="archOccLegend"></div>
            </div>
            <?php else: ?>
            <div class="dental-chart-container smart-dental-chart" id="smartDentalChart">
                <?php
                $dentitionPaneMeta = [
                    'permanent' => ['label' => 'ددانی هەمیشەیی (گەورە)', 'icon' => 'fa-tooth'],
                    'primary'   => ['label' => 'ددانی شیری (منداڵ)', 'icon' => 'fa-child'],
                ];
                foreach ($teethSets as $setName => $set):
                ?>
                <div class="dentition-pane dentition-pane--<?= $setName ?>" data-dentition="<?= $setName ?>">
                    <div class="dentition-pane-title">
                        <i class="fas <?= $dentitionPaneMeta[$setName]['icon'] ?>"></i>
                        <span><?= $dentitionPaneMeta[$setName]['label'] ?></span>
                        <span class="dentition-pane-count" data-dentition-count="<?= $setName ?>"></span>
                    </div>
                    <?php
                    $jaws = [
                        'upper' => ['label' => 'سەرەوە', 'toothClass' => '', 'groups' => ['upper_left' => 'UL', 'upper_right' => 'UR']],
                        'lower' => ['label' => 'خوارەوە', 'toothClass' => ' lower', 'groups' => ['lower_left' => 'LL', 'lower_right' => 'LR']],
                    ];
                    foreach ($jaws as $jawKey => $jaw):
                    ?>
                    <div class="jaw-section jaw-section--<?= $jawKey ?>">
                        <div class="jaw-label"><?= $jaw['label'] ?></div>
                        <div class="teeth-row">
                            <?php $gi = 0; foreach ($jaw['groups'] as $groupKey => $quadrant): ?>
                            <?php if ($gi++ === 1): ?><div class="quadrant-divider"></div><?php endif; ?>
                            <div class="quadrant">
                                <?php foreach ($set[$groupKey] as $num): ?>
                                <div class="tooth<?= $jaw['toothClass'] ?>" data-tooth="<?= $num ?>" data-palmer="<?= $palmerNumbers[$num] ?>"
                                     data-quadrant="<?= $quadrant ?>" onclick="showToothDetail(<?= $num ?>)">
                                    <span class="tooth-number"><?= $palmerNumbers[$num] ?></span>
                                    <span class="tooth-svg"></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <div class="chart-legend" id="smartChartLegend"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Items tab — the estimate as a receipt: the header above already
         names the plan and its totals, so the table starts straight away ═══ -->
    <div class="tab-content" id="tab-items" role="tabpanel" aria-labelledby="pvTab-items" style="display: none;">
        <div class="panel-body tp-table-body">
            <?php if ($isEditable): ?>
            <div class="ph-bar">
                <span></span>
                <button class="btn btn-primary btn-sm" onclick="<?= $isArch ? 'pvOpenArchDialog()' : 'pvOpenItemDialog({})' ?>">
                    <i class="fas fa-plus"></i> زیادکردن
                </button>
            </div>
            <?php endif; ?>
            <div class="vt-pane tp-estimate-receipt">
                <div id="pvItemsTable"></div>
                <div class="vt-foot" id="pvTotals"></div>
            </div>
        </div>
    </div>
</div>
</div><!-- .tp-workspace -->

<?php require __DIR__ . '/_view_dialogs.php'; ?>
<?php require __DIR__ . '/_view_styles.php'; ?>
<?php // the case look (tabs, the compact mouth-shaped chart, the dark header), after the plan's own chart rules ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/case-look.css') ?>">
<?php // the case's treatment picker: icon cards, ★ favourites ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/treatment-picker.css') ?>">
<style>
/* a tooth picked on the chart — after the case look, as on the case page */
.smart-dental-chart .tooth.bulk-selected {
    border-color: #0d9488;
    background: #f0fdfa;
    box-shadow: 0 0 0 3px rgba(13,148,136,.22), 0 10px 22px rgba(13,148,136,.14);
}
.smart-dental-chart .tooth.bulk-selected .tooth-number { background: #0d9488 !important; color: #fff !important; }
</style>
<?php require __DIR__ . '/_view_script.php'; ?>
<?php require __DIR__ . '/_view_routing.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
