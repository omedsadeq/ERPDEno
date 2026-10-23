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
<!-- ═══ Plan header — same card as the case header ═══ -->
<div class="panel mb-3 tp-plan-header">
    <div class="panel-body">
        <div class="d-flex justify-between align-center flex-wrap gap-2">
            <div class="d-flex align-center gap-3">
                <div class="tp-plan-avatar">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <h2 style="margin: 0 0 4px;"><?= htmlspecialchars($plan['plan_number']) ?>
                        <span class="badge <?= $statusBadge ?>" id="pvStatusBadge" style="font-size: 12px; padding: 4px 12px; vertical-align: middle;">
                            <?= $statusLabel ?>
                        </span>
                        <?php if ($isArch): ?>
                        <span class="badge" style="font-size: 12px; padding: 4px 12px; vertical-align: middle; background:#f3e5f5; color:#7b1fa2;">
                            <i class="fas fa-teeth"></i> <span id="pvArchLabel"><?= $archLabel ?></span>
                        </span>
                        <?php else: ?>
                        <span class="badge" style="font-size: 12px; padding: 4px 12px; vertical-align: middle; background:#e8f5e9; color:#2e7d32;">
                            <i class="fas fa-tooth"></i> ددان
                        </span>
                        <?php endif; ?>
                        <span class="badge" style="font-size: 12px; padding: 4px 12px; vertical-align: middle; background:#ede7f6; color:#4527a0;"
                              title="هیچ پارەیەک نەچووەتە ناو حسابات">
                            <i class="fas fa-calculator"></i> خەمڵاندن
                        </span>
                        <span class="badge tp-route-badge <?= $routeClass ?>" style="font-size: 12px; padding: 4px 12px; vertical-align: middle;"
                              title="کێ چارەسەر دەکات؟">
                            <i class="fas <?= $routeIcon ?>"></i> <?= htmlspecialchars($routeText) ?>
                        </span>
                    </h2>
                    <p class="text-secondary" style="margin: 0;">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$plan['patient_id'] ?>&tab=plans">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($plan['patient_name']) ?>
                        </a>
                        <?php if (!empty($plan['doctor_name'])): ?>
                        &nbsp;&bull;&nbsp; <i class="fas fa-user-md"></i> د. <?= htmlspecialchars($plan['doctor_name']) ?>
                        <?php endif; ?>
                        &nbsp;&bull;&nbsp; <i class="fas fa-calendar"></i> <?= date('Y-m-d', strtotime($plan['created_at'])) ?>
                        <?php if (!empty($plan['chief_complaint'])): ?>
                        &nbsp;&bull;&nbsp; <span id="pvComplaintInline"><?= htmlspecialchars($plan['chief_complaint']) ?></span>
                        <?php endif; ?>
                    </p>
                    <div class="case-total-chip">
                        <i class="fas fa-calculator"></i>
                        <span class="ctc-label">کۆی خەمڵێنراو</span>
                        <span class="ctc-value" data-plan-bind="total_estimated"><?= number_format((float)$plan['total_estimated']) ?></span>
                        <span class="ctc-sep"></span>
                        <span class="ctc-label">داشکاندن</span>
                        <span class="ctc-value is-due" data-plan-bind="total_discount"><?= number_format((float)$plan['total_discount']) ?></span>
                        <span class="ctc-sep"></span>
                        <span class="ctc-label">ماوە دوای داشکاندن</span>
                        <span class="ctc-value is-paid" data-plan-bind="total_final"><?= number_format((float)$plan['total_final']) ?></span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 align-center flex-wrap">
                <?php if ($canTransfer): ?>
                <button type="button" class="btn btn-success" id="pvTransferBtn" style="padding: 8px 16px; <?= $pendingCount ? '' : 'display:none;' ?>" onclick="pvOpenTransfer()">
                    <i class="fas fa-exchange-alt"></i> گواستنەوە بۆ کەیس (<span id="pvPendingCount"><?= $pendingCount ?></span>)
                </button>
                <?php endif; ?>
                <a class="btn btn-primary" style="padding: 8px 16px;" target="_blank" href="<?= APP_URL ?>/treatment-plans/print.php?id=<?= $planId ?>">
                    <i class="fas fa-print"></i> چاپکردن (٢ کۆپی)
                </a>
                <?php if ($isEditable): ?>
                <button type="button" class="btn btn-outline" style="padding: 8px 16px;" onclick="pvOpenHeaderEdit()">
                    <i class="fas fa-edit"></i> دەستکاری
                </button>
                <button type="button" class="btn btn-secondary" style="padding: 8px 16px;" onclick="pvCancelPlan()">
                    <i class="fas fa-ban"></i> هەڵوەشاندنەوە
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="tp-notice">
    <i class="fas fa-info-circle"></i>
    <div>
        <strong>خەمڵاندنی چارەسەر</strong> · بڕەکان تەنها دوای <b>گواستنەوە بۆ کەیس</b> دەچنە ناو حسابات.
    </div>
</div>

<?php if ($plan['status'] === 'cancelled'): ?>
<div class="alert alert-warning"><i class="fas fa-ban alert-icon"></i>
    ئەم پلانە هەڵوەشێنراوەتەوە<?= !empty($plan['cancel_reason']) ? ': ' . htmlspecialchars($plan['cancel_reason']) : '' ?>
</div>
<?php endif; ?>

<div class="tp-plan-grid">
    <div class="tp-plan-main">
        <div class="panel tp-clinical-panel">
            <div class="tabs-container tp-plan-tabs" role="tablist" aria-label="پیشاندانی پلان">
                <button type="button" class="tab active" id="pvTab-chart" role="tab" aria-controls="tab-chart" aria-selected="true" onclick="showTab('chart', this)">
                    <i class="fas <?= $isArch ? 'fa-teeth' : 'fa-tooth' ?>" aria-hidden="true"></i>
                    <span class="tab-label"><?= $isArch ? 'چارتی ئەرک' : 'چارتی ددان' ?></span>
                </button>
                <button type="button" class="tab" id="pvTab-items" role="tab" aria-controls="tab-items" aria-selected="false" onclick="showTab('items', this)">
                    <i class="fas fa-tasks" aria-hidden="true"></i>
                    <span class="tab-label">چارەسەرەکان</span>
                    <span class="tab-count" id="pvItemCount"><?= count($items) ?></span>
                </button>
            </div>

            <!-- ═══ Chart tab ═══ -->
            <div class="tab-content active" id="tab-chart" role="tabpanel" aria-labelledby="pvTab-chart" style="display: block;">
                <div class="panel-body" style="padding: 12px;">
                    <div class="d-flex justify-between align-center mb-2" id="pvChartToolbar">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <h5 style="margin: 0; font-size: 14px;">
                                <?php if ($isArch): ?>
                                <i class="fas fa-teeth" style="color: var(--win-accent, #0078d4);"></i> چارتی ئەرک
                                <span class="badge badge-blue" style="font-size: 10px; padding: 3px 8px; margin-right: 8px;" id="pvArchChip"><?= $archLabel ?></span>
                                <?php else: ?>
                                چارتی ددان
                                <?php endif; ?>
                            </h5>
                            <?php if (!$isArch): ?>
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
                            <span style="font-size: 11px; color: #8a97a5;"><i class="fas fa-circle-info"></i> هەردوو چارت کاراییە</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isEditable): ?>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <button class="btn btn-primary btn-sm" onclick="<?= $isArch ? 'pvOpenArchDialog()' : 'pvOpenItemDialog({})' ?>">
                                <i class="fas fa-plus"></i> زیادکردن
                            </button>
                        </div>
                        <?php else: ?>
                        <button class="btn btn-secondary btn-sm" disabled title="ئەم پلانە داخراوە"><i class="fas fa-plus"></i> زیادکردن</button>
                        <?php endif; ?>
                    </div>

                    <?php if ($isEditable && !$isArch): ?>
                    <div style="margin: 0 0 10px; padding: 7px 12px; background: #f8fafc; border: 1px solid #e6ebf1; border-radius: 8px; color: #64748b; font-size: 11.5px;">
                        <i class="fas fa-lightbulb" style="color:#0d9488;"></i>
                        کلیک لەسەر ددانێک بکە بۆ بینینی ئیشەکانی و زیادکردنی ئیشی نوێ لەسەری.
                    </div>
                    <?php endif; ?>

                    <!-- Whole-arch / toothless jobs strip (part of the chart) -->
                    <div id="chartServiceWork" class="chart-service-work" aria-live="polite"></div>

                    <?php if ($isArch): ?>
                    <div class="arch-view-chart">
                        <div class="arch-occ-topbar">
                            <span class="arch-occ-topbar-label"><i class="fas fa-th-large"></i> چارتی ئەرک</span>
                            <span class="arch-occ-chip" id="pvArchChip2"><?= $archLabel ?></span>
                        </div>
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
                                            <div class="tooth-tooltip"><?= htmlspecialchars($toothNames[$num] ?? "ددان $num") ?></div>
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

            <!-- ═══ Items tab ═══ -->
            <div class="tab-content" id="tab-items" role="tabpanel" aria-labelledby="pvTab-items" style="display: none;">
                <div class="panel-body tp-table-body">
                    <div class="tp-table-toolbar">
                        <h4>چارەسەرەکان <span>وەسڵی خەمڵاندن</span></h4>
                        <?php if ($isEditable): ?>
                        <button class="btn btn-primary btn-sm" onclick="<?= $isArch ? 'pvOpenArchDialog()' : 'pvOpenItemDialog({})' ?>">
                            <i class="fas fa-plus"></i> زیادکردن
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="vt-pane tp-estimate-receipt">
                        <div class="vt-head">
                            <div class="vt-head-main">
                                <span class="vt-head-no" dir="ltr"><?= htmlspecialchars($plan['plan_number']) ?></span>
                                <span class="vt-head-date"><i class="fas fa-calendar-day"></i> <?= htmlspecialchars(substr($plan['created_at'],0,10)) ?></span>
                                <span class="vt-head-badge is-draft"><i class="fas fa-clipboard-list"></i> خەمڵاندن</span>
                            </div>
                            <div class="vt-head-actions"><span class="vt-head-total" data-plan-bind="total_final"><?= number_format((float)$plan['total_final']) ?></span></div>
                        </div>
                        <div id="pvItemsTable"></div>
                        <div class="vt-foot" id="pvTotals"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Sidebar summary ═══ -->
    <div class="tp-plan-sidebar">
        <!-- کێ چارەسەر دەکات؟ — the examiner routes, reception picks for «چەند دکتۆرێک» -->
        <div class="panel mb-3 tp-route-panel" id="pvRoutePanel">
            <div class="panel-header"><h4 style="margin:0;"><i class="fas fa-route"></i> کێ چارەسەر دەکات؟</h4></div>
            <div class="panel-body">
                <div class="pv-summary-row"><span>دکتۆری دەستنیشانکەر</span><b><?= htmlspecialchars($plan['doctor_name'] ?? '—') ?></b></div>
                <div class="tp-route-state <?= $routeClass ?>">
                    <i class="fas <?= $routeIcon ?>"></i>
                    <div>
                        <b><?= htmlspecialchars($routeText) ?></b>
                        <?php if ($designState === 'undecided'): ?>
                        <small>دکتۆری پشکنەر هێشتا بڕیاری نەداوە کێ چارەسەری ئەم نەخۆشە دەکات.</small>
                        <?php elseif ($designState === 'awaiting_reception' || $designState === 'chosen'): ?>
                        <small>
                            <?php if ($candidateIds): ?>
                            لە نێوان: <?= htmlspecialchars(implode('، ', array_map(function ($id) use ($doctorChoices) {
                                return $doctorChoices[$id] ?? ('#' . $id);
                            }, $candidateIds))) ?>
                            <?php else: ?>
                            هەر دکتۆرێک — پێشوازی بڕیار دەدات
                            <?php endif; ?>
                        </small>
                        <?php endif; ?>
                        <?php if ($designState === 'chosen' && !empty($plan['chosen_by_name'])): ?>
                        <small>هەڵبژاردنی: <?= htmlspecialchars($plan['chosen_by_name']) ?> · <?= htmlspecialchars(substr((string)$plan['chosen_at'], 0, 16)) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canDesignate && !$routeLock): ?>
                    <?php if ($designState === 'undecided'): ?>
                    <div class="tp-route-quick">
                        <button type="button" class="btn btn-outline btn-sm" onclick="pvRouteSelf()"><i class="fas fa-user-md"></i> خۆم</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="pvRouteOpen('doctor')"><i class="fas fa-share"></i> دکتۆرێکی تر</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="pvRouteOpen('doctors')"><i class="fas fa-users"></i> چەند دکتۆرێک</button>
                    </div>
                    <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" style="width:100%; margin-top:10px;" onclick="pvRouteOpen('<?= htmlspecialchars($plan['treating_mode']) ?>')">
                        <i class="fas fa-exchange-alt"></i> گۆڕینی دکتۆری چارەسەرکەر
                    </button>
                    <?php endif; ?>
                <?php elseif ($canDesignate && $routeLock): ?>
                <div class="tp-route-note"><i class="fas fa-lock"></i> <?= htmlspecialchars($routeLock) ?></div>
                <?php endif; ?>

                <?php if ($canChoose): ?>
                <div class="tp-route-choose">
                    <label class="form-label" for="pvChooseDoctor">پێشوازی: نەخۆشەکە بنێرە بۆ</label>
                    <?php if ($routeLock && $designState === 'chosen'): ?>
                    <div class="tp-route-note"><i class="fas fa-lock"></i> <?= htmlspecialchars($routeLock) ?></div>
                    <?php else: ?>
                    <select id="pvChooseDoctor" class="form-control">
                        <option value="">— دکتۆرێک هەڵبژێرە —</option>
                        <?php foreach (($candidateIds ?: array_keys($doctorChoices)) as $cid): ?>
                        <?php if (!isset($doctorChoices[$cid])) continue; ?>
                        <option value="<?= (int)$cid ?>" <?= (int)$cid === (int)($plan['treating_doctor_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($doctorChoices[$cid]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm" style="width:100%; margin-top:8px;" id="pvChooseBtn" onclick="pvChooseDoctor()">
                        <i class="fas fa-paper-plane"></i> <?= $designState === 'chosen' ? 'گۆڕینی دکتۆر' : 'ناردن بۆ ئەم دکتۆرە' ?>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- مەوعیدی چارەسەر — booked by the treating doctor himself, nobody else -->
        <div class="panel mb-3" id="pvApptPanel">
            <div class="panel-header"><h4 style="margin:0;"><i class="fas fa-calendar-check"></i> مەوعیدی چارەسەر</h4></div>
            <div class="panel-body">
                <?php if ($liveAppointment):
                    $apptTs = strtotime($liveAppointment['appointment_date'] . ' ' . $liveAppointment['appointment_time']);
                    $apptDays = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
                ?>
                <div class="tp-appt-card">
                    <div class="tp-appt-day"><?= $apptDays[(int)date('w', $apptTs)] ?></div>
                    <div class="tp-appt-date"><bdi><?= date('Y/m/d', $apptTs) ?></bdi> · <bdi><?= date('H:i', $apptTs) ?></bdi></div>
                    <div class="tp-appt-doc"><i class="fas fa-user-md"></i> <?= htmlspecialchars($liveAppointment['doctor_name'] ?? '') ?>
                        <?php if (!empty($liveAppointment['duration'])): ?> · <?= (int)$liveAppointment['duration'] ?> خولەک<?php endif; ?>
                    </div>
                </div>
                <?php if ($canBook && (int)$liveAppointment['doctor_id'] === $viewerId): ?>
                <div class="tp-route-quick">
                    <button type="button" class="btn btn-outline btn-sm" onclick="pvBookOpen()"><i class="fas fa-clock"></i> گۆڕینی کات</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="pvBookCancel()"><i class="fas fa-calendar-times"></i> هەڵوەشاندنەوە</button>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="tp-route-note"><i class="fas fa-calendar"></i> هێشتا مەوعید دانەنراوە.</div>
                <?php if ($canBook): ?>
                <button type="button" class="btn btn-primary btn-sm" style="width:100%; margin-top:10px;" onclick="pvBookOpen()">
                    <i class="fas fa-calendar-plus"></i> دانانی مەوعید
                </button>
                <?php elseif ($treatingId !== null): ?>
                <div class="tp-route-note is-soft">تەنها <?= htmlspecialchars($treatingName ?: 'دکتۆری چارەسەرکەر') ?> دەتوانێت مەوعیدی ئەم پلانە دابنێت.</div>
                <?php elseif ($plan['status'] !== 'cancelled'): ?>
                <div class="tp-route-note is-soft">کاتێک پێشوازی دکتۆرەکە هەڵدەبژێرێت، ئەو دکتۆرە خۆی مەوعید دادەنێت.</div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($planVisit): ?>
                <div class="pv-summary-row" style="border-bottom:none; margin-top:8px;">
                    <span>سەردانی پشکنین</span>
                    <b>
                        <?php if (!$restrictedDoctor && $perm->can('page.visits')): ?>
                        <a href="<?= APP_URL ?>/edit_visit.php?id=<?= (int)$planVisit['id'] ?>">#<?= (int)$planVisit['id'] ?> · <?= htmlspecialchars($planVisit['visit_date']) ?></a>
                        <?php else: ?>
                        #<?= (int)$planVisit['id'] ?> · <?= htmlspecialchars($planVisit['visit_date']) ?>
                        <?php endif; ?>
                    </b>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header"><h4 style="margin:0;"><i class="fas fa-info-circle"></i> پوختەی پلان</h4></div>
            <div class="panel-body">
                <div class="pv-summary-row"><span>ژمارەی پلان</span><b style="direction:ltr;"><?= htmlspecialchars($plan['plan_number']) ?></b></div>
                <div class="pv-summary-row"><span>دکتۆر</span><b><?= htmlspecialchars($plan['doctor_name'] ?? '—') ?></b></div>
                <div class="pv-summary-row"><span>جۆری بوار</span><b><?= $isArch ? 'ئەرک (جاو)' : 'ددان' ?></b></div>
                <div class="pv-summary-row"><span>ژمارەی ئیش</span><b id="pvSideCount"><?= count($items) ?></b></div>
                <div class="pv-summary-row"><span>کاتی خەمڵێنراو</span><b id="pvSideMinutes">—</b></div>
                <div class="pv-summary-row"><span>ماوەی چارەسەر</span><b id="pvSideDuration"><?= htmlspecialchars($plan['duration_text'] ?: '—') ?></b></div>
                <div class="pv-summary-row"><span>گلەیی</span><b id="pvSideComplaint"><?= htmlspecialchars($plan['chief_complaint'] ?: '—') ?></b></div>
                <div class="pv-summary-row" style="border-bottom:none;"><span>تێبینی</span><b id="pvSideNotes"><?= htmlspecialchars($plan['notes'] ?: '—') ?></b></div>
                <?php if ($isEditable): ?>
                <button type="button" class="btn btn-outline btn-sm" style="width:100%; margin-top:10px;" onclick="pvOpenHeaderEdit()">
                    <i class="fas fa-edit"></i> دەستکاری زانیارییەکان
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div><!-- .tp-workspace -->

<?php require __DIR__ . '/_view_dialogs.php'; ?>
<?php require __DIR__ . '/_view_styles.php'; ?>
<?php require __DIR__ . '/_view_script.php'; ?>
<?php require __DIR__ . '/_view_routing.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
