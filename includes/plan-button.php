<?php
/**
 * «پلانی چارەسەر» on a patient row (patients/index.php, ajax_search.php):
 * shown only for a patient who has a treatment plan, it says so and opens it —
 * the plan itself, or the patient's plans tab when there are several. The rows'
 * plans come from TreatmentPlan::summaryForPatients(), one query a page; the
 * look is .plan-go-btn in assets/css/case-button.css.
 */
require_once __DIR__ . '/../classes/TreatmentPlan.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/AccessGuard.php';

/** A page's plans, as the viewer may see them ([] without the plan permission). */
function plan_button_summaries(Auth $auth, array $patientIds) {
    if (!Permission::getInstance()->can('treatment_plan.view')) {
        return [];
    }
    $doctorScope = AccessGuard::isRestrictedDoctor() ? AccessGuard::doctorId() : null;
    return (new TreatmentPlan())->summaryForPatients($patientIds, $doctorScope);
}

/** The button for one patient, or '' when they have no plan. */
function plan_button($patientId, array $summaries) {
    $s = $summaries[(int)$patientId] ?? null;
    if (!$s) {
        return '';
    }
    $states = ['open' => 'چاوەڕوانی بڕیاری نەخۆش', 'partial' => 'بەشێکی گوازراوەتەوە', 'transferred' => 'گوازراوەتەوە بۆ کەیس'];
    $latest = $s['latest'];
    $url = $s['count'] > 1
        ? APP_URL . '/patients/view.php?id=' . (int)$patientId . '&tab=plans'
        : APP_URL . '/treatment-plans/view.php?id=' . (int)$latest['id'];
    $title = 'پلانی چارەسەری بۆ کراوە — ' . $latest['plan_number'] . ' · ' . ($states[$latest['status']] ?? $latest['status'])
        . ($s['count'] > 1 ? ' (' . $s['count'] . ' پلان)' : '');
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="plan-go-btn' . ($latest['status'] === 'open' ? ' is-open' : '') . '"'
        . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" onclick="event.stopPropagation()">'
        . '<i class="fas fa-clipboard-list"></i><span>پلانی چارەسەر</span>'
        . ($s['count'] > 1 ? '<b>' . (int)$s['count'] . '</b>' : '') . '</a>';
}
