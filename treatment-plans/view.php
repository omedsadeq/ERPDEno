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
// Every plan holds both kinds of work, as every case does: teeth on the
// chart, whole-arch work from «ئیشی ئەرک» on its jaw. The scope an old ئەرک
// plan was made with only names the case its work is moved into.
$planScope = ($plan['scope_type'] ?? 'tooth') === 'arch' ? 'arch' : 'tooth';

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
        if (CaseNamePreset::scope($n['scope_type'] ?? '') === $planScope) {
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

$pageTitle = $plan['plan_number'];
// The plan header carries the number and the way back to the patient.
$hidePageHeader = true;
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => $plan['patient_name'], 'url' => APP_URL . '/patients/view.php?id=' . (int)$plan['patient_id'] . '&tab=plans'],
    ['title' => $plan['plan_number']],
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/case-button.php';
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
            <div class="ch-icon"><i class="fas fa-clipboard-list"></i></div>
            <div class="ch-id-text">
                <div class="ch-title-row">
                    <h2 class="ch-number"><?= htmlspecialchars($plan['plan_number']) ?></h2>
                    <span class="ch-pill <?= $statusPill ?>" id="pvStatusBadge"><?= $statusLabel ?></span>
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
                <?php if (case_button_allowed($auth, (int)$plan['patient_id'])): ?>
                <?= case_button((int)$plan['patient_id'], ['doctor' => (int)($treatingId ?? 0)]) ?>
                <?php endif; ?>
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
                <button type="button" class="btn ch-btn ch-btn--glass" onclick="pvOpenDiscount()" title="داشکاندن لە هەموو پلانەکە — بڕی پارە یان ڕێژە">
                    <i class="fas fa-percent"></i> داشکاندن
                </button>
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
                <div class="ch-stat<?= $isEditable ? ' ch-stat--action' : '' ?>"<?= $isEditable ? ' onclick="pvOpenDiscount()" title="داشکاندنی پلان"' : '' ?>>
                    <span class="ch-stat-label"><i class="fas fa-percent"></i> داشکاندن <span id="pvDiscountRate"><?= ($plan['discount_mode'] ?? null) === 'percent' ? '(' . rtrim(rtrim(number_format((float)$plan['discount_value'], 2, '.', ''), '0'), '.') . '٪)' : '' ?></span></span>
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
        <?php // چارت و چارەسەر: one tab, as «چارت و وەسڵ» on a case ?>
        <button type="button" class="tab tab--chart active" id="pvTab-chart" role="tab" aria-controls="tab-chart" aria-selected="true" onclick="showTab('chart', this)">
            <i class="fas fa-tooth" aria-hidden="true"></i>
            <span class="tab-label">چارت و چارەسەر</span>
            <span class="tab-count" id="pvItemCount"><?= count($items) ?></span>
        </button>
        <?php if ($perm->can('erp2.prescriptions.create') || $perm->can('erp2.prescriptions.view')): ?>
        <?php // دەرمان: the prescription sheet as a dialog (includes/rx-dialog.php) — an
              // examined patient may need only medicine, with no work in the plan ?>
        <button type="button" class="tab tab--rx" id="pvTab-rx" onclick="openMedicineDialog()" title="دەرمان بۆ نەخۆش بنووسە">
            <i class="fas fa-pills" aria-hidden="true"></i>
            <span class="tab-label">دەرمان</span>
            <span class="tab-count" id="rxTabCount">0</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- ═══ Chart tab ═══ -->
    <div class="tab-content active" id="tab-chart" role="tabpanel" aria-labelledby="pvTab-chart" style="display: block;">
    <?php // the chart on the right, the treatments it fills on the left (an RTL grid puts
          // its first column on the right). ⤢ gives the treatments the whole width;
          // the choice is kept on this computer. ?>
    <div class="cw-split" id="chartWorkspace">
    <script>try { if (localStorage.getItem('planReceiptWide') === '1') document.getElementById('chartWorkspace').classList.add('is-wide'); } catch (e) {}</script>
    <div class="cw-chart">
        <div class="panel-body" style="padding: 12px;">
            <div class="ph-bar" id="pvChartToolbar">
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
                <?php if ($isEditable): // tooth work from the chart (or «زیادکردن»), whole-arch work from «ئیشی ئەرک» — as on a case ?>
                <div class="pv-chart-adds">
                <button type="button" class="chart-arch-btn" onclick="pvOpenArchDialog()" title="ئیشی هەموو ئەرکێک: زنجیر، تاخم …">
                    <span class="chart-arch-ico"></span> ئیشی ئەرک <small>زنجیر · تاخم</small>
                </button>
                <button class="btn btn-primary btn-sm" onclick="pvOpenItemDialog({})" title="یان ددان هەڵبژێرە (کلیک یان ڕاکێشان) و کلیکی ڕاست بکە">
                    <i class="fas fa-plus"></i> زیادکردن
                </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Whole-arch / toothless jobs strip (part of the chart) -->
            <div id="chartServiceWork" class="chart-service-work" aria-live="polite"></div>

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
                        'upper' => ['label' => 'فەکی سەرەوە', 'toothClass' => '', 'groups' => ['upper_left' => 'UL', 'upper_right' => 'UR']],
                        'lower' => ['label' => 'فەکی خوارەوە', 'toothClass' => ' lower', 'groups' => ['lower_left' => 'LL', 'lower_right' => 'LR']],
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
        </div>
    </div><!-- /cw-chart -->

    <!-- The estimate as a receipt: it was the «چارەسەرەکان» tab, and keeps that id for its scripts -->
    <div class="cw-receipt" id="tab-items">
        <button type="button" class="cw-wide-btn" id="cwWideBtn" onclick="toggleReceiptWide()" title="چارەسەرەکان بە پانی تەواو" aria-pressed="false"><i class="fas fa-expand"></i></button>
        <div class="panel-body tp-table-body">
            <div class="vt-pane tp-estimate-receipt">
                <div id="pvItemsTable"></div>
                <div class="vt-foot" id="pvTotals"></div>
            </div>
        </div>
    </div>
    </div><!-- /cw-split -->
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
.pv-chart-adds { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
/* ── چارت و چارەسەر: the chart on the right, the treatments on the left, as
   «چارت و وەسڵ» on a case (cases/view.php .cw-*). The chart stays in view
   while a long list scrolls; with less than ~1100px of room the two stack. */
#tab-chart { container: cw / inline-size; }
.cw-split { display: grid; grid-template-columns: minmax(0, 1.08fr) minmax(0, 1fr); align-items: start; }
.cw-chart { min-width: 0; }
.cw-chart.is-sticky { position: sticky; top: 0; }
.cw-receipt { position: relative; min-width: 0; border-inline-start: 1px solid #e3eaf2; }
.cw-receipt .tp-table-body { padding-top: 54px; }
.cw-wide-btn {
    position: absolute; top: 14px; inset-inline-end: 12px; z-index: 3;
    width: 32px; height: 32px; padding: 0; border-radius: 9px;
    border: 1px solid #d6e0ea; background: #fff; color: #475569; cursor: pointer;
    display: grid; place-items: center; font-size: 13px;
}
.cw-wide-btn:hover { background: #eef5fc; color: #0f6cbd; border-color: #b9d3ec; }
.cw-split.is-wide { grid-template-columns: minmax(0, 1fr); }
.cw-split.is-wide .cw-chart { display: none; }
.cw-split.is-wide .cw-receipt { border-inline-start: 0; }
/* the treatments beside list whole-arch and toothless work, so the chart does
   not repeat it as chips over the jaws */
#chartWorkspace .chart-service-work { display: none !important; }
/* Side by side, the list is narrower: no English under the headings, tighter columns */
@container cw (min-width: 1100px) {
    .cw-split:not(.is-wide) .table.tp-items { min-width: 0; }
    .cw-split:not(.is-wide) .table.tp-items thead .en { display: none; }
    .cw-split:not(.is-wide) .table.tp-items th,
    .cw-split:not(.is-wide) .table.tp-items td { padding-inline: 4px; }
    .cw-split:not(.is-wide) .table.tp-items td { font-size: 11.5px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-no    { width: 22px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-desc  { min-width: 84px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-teeth { width: 76px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-qty   { width: 34px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-unit  { width: 62px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-disc  { width: 50px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-total { width: 66px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-act   { width: 32px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-teeth .tooth-cross { max-width: 76px; margin-inline: auto; }
    .cw-split:not(.is-wide) .tp-items input { min-width: 0; max-width: 100%; }
    /* the chart is right beside: no «pick from the chart» button, and the
       «بێ ژمارەی ددان» badge may break onto two lines */
    .cw-split:not(.is-wide) .tp-items .rt-teeth-btn { display: none; }
    .cw-split:not(.is-wide) .tp-items .rt-location-badge { white-space: normal; padding: 3px 6px; line-height: 1.3; }
    .cw-split:not(.is-wide) .tp-items .rt-row-actions { gap: 2px; flex-direction: column; align-items: center; }
    .cw-split:not(.is-wide) .tp-items .rt-add-row .rt-c-act { display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .cw-split:not(.is-wide) .tp-items .rt-add-row .rt-act-btn.is-clear { margin: 0; }
    .cw-split:not(.is-wide) .tp-items .rt-row-action { width: 24px; height: 24px; min-width: 0; padding: 0; font-size: 11px; }
    .cw-split:not(.is-wide) .tp-items input.rt-in-disc { width: 100%; padding-inline: 2px; }
    .cw-split:not(.is-wide) .tp-items .rt-field { padding-inline: 4px; font-size: 12px; height: 30px; }
    .cw-split:not(.is-wide) .tp-items .rt-act-btn { width: 24px; height: 26px; }
    .cw-split:not(.is-wide) .tp-items .rt-act-btn.is-clear { margin-right: 2px; }
    .cw-split:not(.is-wide) .cw-receipt .tp-table-body { padding-inline: 6px; }
    .cw-split:not(.is-wide) .table.tp-items thead th { padding-inline: 3px; font-size: 12px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-desc { min-width: 70px; }
    .cw-split:not(.is-wide) .tp-items .tooth-cross { max-width: 64px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-disc input { width: 44px; }
    .cw-split:not(.is-wide) .tp-items .rt-c-unit,
    .cw-split:not(.is-wide) .tp-items .rt-c-total { font-size: 11px; }
}
@container cw (max-width: 1099.98px) {
    .cw-split { grid-template-columns: minmax(0, 1fr); }
    .cw-chart.is-sticky { position: static; }
    .cw-receipt { border-inline-start: 0; border-top: 1px solid #e3eaf2; }
    .cw-receipt .tp-table-body { padding-top: 12px; }
    .cw-wide-btn { display: none; }
}
.ch-stat--action { cursor: pointer; }
.ch-stat--action:hover { background: rgba(255, 255, 255, .12); border-radius: 10px; }
/* داشکاندنی پلان (pvOpenDiscount) */
.pd-modes { display: flex; gap: 8px; margin-bottom: 12px; }
.pd-modes .tp-radio { flex: 1; justify-content: center; }
.pd-sum { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
.pd-sum div { display: flex; justify-content: space-between; padding: 3px 0; font-size: 13px; color: #475569; }
.pd-sum b { direction: ltr; font-variant-numeric: tabular-nums; color: #0f172a; }
.pd-sum .is-cut b { color: #dc2626; }
.pd-sum .is-final { margin-top: 4px; padding-top: 7px; border-top: 1px dashed #cbd5e1; font-weight: 800; }
.pd-sum .is-final b { color: #15803d; font-size: 15px; }
.pd-note { margin: 10px 0 0; font-size: 12px; color: #64748b; line-height: 1.6; }
</style>
<?php require __DIR__ . '/_view_script.php'; ?>
<?php require __DIR__ . '/_view_routing.php'; ?>
<script>
// ⤢ beside the treatments, and the chart kept in view, as on a case (cases/view.php)
window.toggleReceiptWide = function (force) {
    const split = document.getElementById('chartWorkspace');
    const btn = document.getElementById('cwWideBtn');
    if (!split || !btn) return;
    const wide = typeof force === 'boolean' ? force : !split.classList.contains('is-wide');
    split.classList.toggle('is-wide', wide);
    btn.setAttribute('aria-pressed', wide ? 'true' : 'false');
    btn.title = wide ? 'چارت و چارەسەر پێکەوە' : 'چارەسەرەکان بە پانی تەواو';
    btn.innerHTML = '<i class="fas ' + (wide ? 'fa-compress' : 'fa-expand') + '"></i>';
    if (typeof force !== 'boolean') {
        try { localStorage.setItem('planReceiptWide', wide ? '1' : '0'); } catch (e) { /* private mode */ }
    }
};
document.addEventListener('DOMContentLoaded', function () {
    const split = document.getElementById('chartWorkspace');
    if (split && split.classList.contains('is-wide')) toggleReceiptWide(true);
    // The chart stays in view beside a long list only while all of it fits the
    // screen: a taller one (adult + child) would hide its lower jaw.
    const chartCol = split && split.querySelector('.cw-chart');
    if (!chartCol) return;
    const scroller = document.querySelector('.page-content') || document.documentElement;
    const syncSticky = function () {
        chartCol.classList.toggle('is-sticky', chartCol.offsetHeight + 16 <= scroller.clientHeight);
    };
    if (window.ResizeObserver) new ResizeObserver(syncSticky).observe(chartCol);
    window.addEventListener('resize', syncSticky);
    syncSticky();
});
</script>
<?php
// دەرمانی نەخۆش for the plan's patient: no case — the prescription stands on
// the patient; the examiner is its doctor when someone else writes it.
$rxCtx = [
    'patient_id'   => (int)$plan['patient_id'],
    'case_id'      => 0,
    'patient_name' => (string)($plan['patient_name'] ?? ''),
    'doctor_id'    => (int)($plan['doctor_id'] ?? 0),
    'doctor_name'  => (string)($plan['doctor_name'] ?? ''),
];
require __DIR__ . '/../includes/rx-dialog.php';
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
