<?php
/**
 * پلانی چارەسەر API — backs the "پلانی چارەسەر" tab on the patient page.
 *
 * Permission split (see 2026_09_26_treatment_plan_permissions.sql):
 *   treatment_plan.view      read
 *   treatment_plan.write     create/edit/cancel — the examination itself, and
 *                            the examiner's «کێ چارەسەر دەکات؟» decision
 *   treatment_plan.transfer  turn the estimate into real case work
 *   treatment_plan.assign    reception picks the doctor for «چەند دکتۆرێک»
 *                            (2026_09_28_treatment_plan_designation.sql)
 *
 * On top of the keys, a doctor only ever touches a plan he examined or was
 * sent to treat, edits only what he examined, and books only as the treating
 * doctor himself — no doctor books on another doctor's behalf.
 *
 * Nothing here touches an invoice, a payment or a total. The single action that
 * reaches the money model is `transfer`, and it does so only through
 * CaseManager::addTreatment(). See classes/TreatmentPlan.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/AccessGuard.php';
require_once __DIR__ . '/../classes/TreatmentPlan.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$perm = Permission::getInstance();
$db = Database::getInstance();
$plans = new TreatmentPlan();
$currentUser = $auth->getCurrentUser();
$userId = (int)$currentUser['id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function tp_fail(string $msg, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function tp_ok(array $extra = []): void
{
    echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function tp_require(string $key): void
{
    if (!Permission::getInstance()->can($key)) {
        tp_fail('Forbidden', 403);
    }
}

/**
 * A restricted doctor may only work inside their own patients and their own
 * plans — the same isolation cases and x-rays use. Returns the doctor user id
 * to scope reads by, or null for staff who see everything.
 */
function tp_scope_doctor(): ?int
{
    return AccessGuard::isRestrictedDoctor() ? AccessGuard::doctorId() : null;
}

/**
 * Loads a plan and refuses it if the viewer is not allowed to see it: a
 * restricted doctor sees plans he examined or was sent to treat.
 */
function tp_plan_or_fail(TreatmentPlan $plans, int $planId): array
{
    $plan = $plans->getById($planId);
    if (!$plan) {
        tp_fail('پلان نەدۆزرایەوە');
    }
    $scope = tp_scope_doctor();
    if ($scope !== null && !TreatmentPlan::doctorMaySee($plan, $scope)) {
        tp_fail('Forbidden', 403);
    }
    return $plan;
}

/**
 * The plan's content is the examiner's clinical note: a doctor who was only
 * sent the patient reads it but does not rewrite it.
 */
function tp_require_examiner(array $plan): void
{
    $scope = tp_scope_doctor();
    if ($scope !== null && (int)$plan['doctor_id'] !== $scope) {
        tp_fail('تەنها دکتۆری پشکنەر دەتوانێت ئەم پلانە بگۆڕێت', 403);
    }
}

// ---------------------------------------------------------------- reads

if ($action === 'list') {
    tp_require('treatment_plan.view');
    $patientId = (int)($_GET['patient_id'] ?? 0);
    if ($patientId <= 0) {
        tp_fail('نەخۆش دیاری نەکراوە');
    }
    if (AccessGuard::isRestrictedDoctor()) {
        AccessGuard::requirePatientGeneral($patientId, true);
    }
    $rows = $plans->getByPatient($patientId, tp_scope_doctor());
    tp_ok(['plans' => $rows]);
}

if ($action === 'detail') {
    tp_require('treatment_plan.view');
    $planId = (int)($_GET['plan_id'] ?? 0);
    $plan = tp_plan_or_fail($plans, $planId);
    $treatingId = TreatmentPlan::treatingDoctorId($plan);
    tp_ok([
        'plan'    => $plan,
        'items'   => $plans->getItems($planId),
        // Only the treating doctor's own cases — and none while reception
        // has not picked the doctor yet.
        'targets' => $treatingId !== null ? $plans->transferTargets((int)$plan['patient_id'], $treatingId) : [],
    ]);
}

if ($action === 'doctor_day') {
    // The booking doctor's own appointments on a day, so he can see what the
    // day already holds before picking a time. Always the caller's own list.
    tp_require('treatment_plan.view');
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tp_fail('بەروار نادروستە');
    }
    $rows = $db->fetchAll(
        "SELECT a.appointment_time, a.duration, a.status, p.full_name AS patient_name
         FROM appointments a
         JOIN patients p ON p.id = a.patient_id
         WHERE a.doctor_id = :uid AND a.appointment_date = :d
           AND a.status NOT IN ('cancelled','no_show')
         ORDER BY a.appointment_time",
        ['uid' => $userId, 'd' => $date]
    );
    foreach ($rows as &$r) {
        $r['appointment_time'] = substr((string)$r['appointment_time'], 0, 5);
    }
    unset($r);
    tp_ok(['appointments' => $rows]);
}

if ($action === 'catalogue') {
    // The treatment catalogue, for the picker. Prices are the DEFAULT the
    // doctor may then change per tooth — a quote is not a price list.
    tp_require('treatment_plan.view');
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        $rows = $db->fetchAll(
            "SELECT id, treatment_code, treatment_name, category, scope,
                    requires_tooth_number, default_price
             FROM treatments WHERE is_active = 1
             ORDER BY treatment_name LIMIT 100"
        );
    } else {
        $rows = $db->fetchAll(
            "SELECT id, treatment_code, treatment_name, category, scope,
                    requires_tooth_number, default_price
             FROM treatments
             WHERE is_active = 1 AND (treatment_name LIKE :q OR COALESCE(treatment_name_en,'') LIKE :q2)
             ORDER BY treatment_name LIMIT 100",
            ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%']
        );
    }
    tp_ok(['treatments' => $rows]);
}

// ---------------------------------------------------------------- writes

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tp_fail('POST required', 405);
}
Csrf::require();

if ($action === 'create_plan') {
    tp_require('treatment_plan.write');
    $patientId = (int)($_POST['patient_id'] ?? 0);
    if ($patientId <= 0) {
        tp_fail('نەخۆش دیاری نەکراوە');
    }
    if (AccessGuard::isRestrictedDoctor()) {
        AccessGuard::requirePatientGeneral($patientId, true);
    }

    // The plan belongs to the examining doctor. A doctor writes their own;
    // an admin (or any non-doctor staff with write permission) names whose
    // examination it was — exactly as the new-case dialog does.
    $doctorId = $userId;
    if (!$auth->isAdmin() && !$auth->isDoctor() && empty($_POST['doctor_id'])) {
        tp_fail('تکایە دکتۆر هەڵبژێرە');
    }
    if (($auth->isAdmin() || !$auth->isDoctor()) && !empty($_POST['doctor_id'])) {
        $posted = (int)$_POST['doctor_id'];
        $isDoctor = $db->fetch(
            "SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
            ['id' => $posted]
        );
        if (!$isDoctor) {
            tp_fail('دکتۆر دروست نییە');
        }
        $doctorId = $posted;
    }

    $res = $plans->create([
        'patient_id'      => $patientId,
        'doctor_id'       => $doctorId,
        'dentition'       => $_POST['dentition'] ?? 'permanent',
        // ددان / ئەرک — same choice as the new-case dialog.
        'scope_type'      => $_POST['scope_type'] ?? 'tooth',
        'selected_arch'   => $_POST['selected_arch'] ?? null,
        'chief_complaint' => $_POST['chief_complaint'] ?? '',
        'notes'           => $_POST['notes'] ?? '',
        'duration_text'   => $_POST['duration_text'] ?? '',
        'created_by'      => $userId,
    ]);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['plan_id' => $res['plan_id']]);
}

if ($action === 'update_plan') {
    tp_require('treatment_plan.write');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_require_examiner(tp_plan_or_fail($plans, $planId));
    $res = $plans->updatePlan($planId, $_POST);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['plan' => $plans->getById($planId)]);
}

if ($action === 'add_item') {
    tp_require('treatment_plan.write');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_require_examiner(tp_plan_or_fail($plans, $planId));
    $arch = $_POST['arch_type'] ?? 'none';
    if (in_array($arch, ['upper', 'lower', 'both'], true)) {
        // Whole-arch job(s): 'both' is two rows, one per arch, each with its
        // own price — the same shape cases/view.php add_arch_treatment writes.
        $res = $plans->addArchItems($planId, $_POST, $arch);
        $itemId = $res['item_ids'][0] ?? null;
    } else {
        $res = $plans->addItem($planId, $_POST);
        $itemId = $res['item_id'] ?? null;
    }
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    $plan = $plans->getById($planId);
    tp_ok(['item_id' => $itemId, 'plan' => $plan, 'items' => $plans->getItems($planId)]);
}

if ($action === 'update_item') {
    tp_require('treatment_plan.write');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $item = $db->fetch("SELECT plan_id FROM treatment_plan_items WHERE id = :id", ['id' => $itemId]);
    if (!$item) {
        tp_fail('ئایتم نەدۆزرایەوە');
    }
    $planId = (int)$item['plan_id'];
    tp_require_examiner(tp_plan_or_fail($plans, $planId));
    $res = $plans->updateItem($itemId, $_POST);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['plan' => $plans->getById($planId), 'items' => $plans->getItems($planId)]);
}

if ($action === 'remove_item') {
    tp_require('treatment_plan.write');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $item = $db->fetch("SELECT plan_id FROM treatment_plan_items WHERE id = :id", ['id' => $itemId]);
    if (!$item) {
        tp_fail('ئایتم نەدۆزرایەوە');
    }
    $planId = (int)$item['plan_id'];
    tp_require_examiner(tp_plan_or_fail($plans, $planId));
    $res = $plans->removeItem($itemId);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['plan' => $plans->getById($planId), 'items' => $plans->getItems($planId)]);
}

if ($action === 'cancel_plan') {
    tp_require('treatment_plan.write');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_require_examiner(tp_plan_or_fail($plans, $planId));
    $res = $plans->cancelPlan($planId, $_POST['reason'] ?? null, $userId);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok();
}

if ($action === 'designate') {
    // کێ چارەسەر دەکات؟ — the examiner's decision (self / one doctor / several).
    tp_require('treatment_plan.write');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_require_examiner(tp_plan_or_fail($plans, $planId));
    $candidates = $_POST['candidate_ids'] ?? [];
    if (is_string($candidates)) {
        $candidates = array_filter(explode(',', $candidates), 'strlen');
    }
    $res = $plans->designate(
        $planId,
        (string)($_POST['mode'] ?? ''),
        isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null,
        is_array($candidates) ? $candidates : [],
        $userId
    );
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['changed' => !empty($res['changed']), 'plan' => $plans->getById($planId)]);
}

if ($action === 'choose_doctor') {
    // Reception decides which doctor a «چەند دکتۆرێک» patient goes to.
    tp_require('treatment_plan.assign');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_plan_or_fail($plans, $planId);
    $res = $plans->chooseDoctor($planId, (int)($_POST['doctor_id'] ?? 0), $userId);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['changed' => !empty($res['changed']), 'plan' => $plans->getById($planId)]);
}

if ($action === 'book_appointment') {
    // Only the treating doctor, for himself — the class refuses anyone else.
    tp_require('treatment_plan.view');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_plan_or_fail($plans, $planId);
    $res = $plans->bookAppointment($planId, [
        'appointment_date' => $_POST['appointment_date'] ?? '',
        'appointment_time' => $_POST['appointment_time'] ?? '',
        'duration'         => $_POST['duration'] ?? 0,
        'notes'            => $_POST['notes'] ?? '',
    ], $userId);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok($res + ['plan' => $plans->getById($planId)]);
}

if ($action === 'cancel_appointment') {
    tp_require('treatment_plan.view');
    $planId = (int)($_POST['plan_id'] ?? 0);
    tp_plan_or_fail($plans, $planId);
    $res = $plans->cancelAppointment($planId, $userId, (string)($_POST['reason'] ?? ''));
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە');
    }
    tp_ok(['plan' => $plans->getById($planId)]);
}

if ($action === 'transfer') {
    // The only action that puts money into the books.
    tp_require('treatment_plan.transfer');
    $planId = (int)($_POST['plan_id'] ?? 0);
    $plan = tp_plan_or_fail($plans, $planId);
    // A doctor moves work only into his own case: the examiner who sent the
    // patient to a colleague no longer transfers it.
    $scope = tp_scope_doctor();
    if ($scope !== null && TreatmentPlan::treatingDoctorId($plan) !== $scope) {
        tp_fail('تەنها دکتۆری چارەسەرکەر دەتوانێت ئەم پلانە بگوازێتەوە بۆ کەیسی خۆی', 403);
    }

    $itemIds = $_POST['item_ids'] ?? [];
    if (is_string($itemIds)) {
        $itemIds = array_filter(explode(',', $itemIds), 'strlen');
    }
    if (!is_array($itemIds) || !$itemIds) {
        tp_fail('هیچ ئایتمێک هەڵنەبژێردراوە');
    }

    $target = [];
    if (!empty($_POST['case_id'])) {
        $target['case_id'] = (int)$_POST['case_id'];
    } else {
        // A new case's name must come from the admin-managed list, exactly as
        // patients/view.php enforces for a hand-created case.
        require_once __DIR__ . '/../classes/CaseNamePreset.php';
        $caseName = trim((string)($_POST['case_name'] ?? ''));
        if (!(new CaseNamePreset())->exists($caseName)) {
            tp_fail('تکایە ناوی کەیس لە لیستەکە هەڵبژێرە');
        }
        $target['case_name'] = $caseName;
    }

    $res = $plans->transferItems($planId, $itemIds, $target, $userId);
    if (empty($res['success'])) {
        tp_fail($res['message'] ?? 'هەڵە لە گواستنەوە');
    }
    tp_ok([
        'case_id' => $res['case_id'],
        'moved'   => $res['moved'],
        'plan'    => $plans->getById($planId),
        'items'   => $plans->getItems($planId),
    ]);
}

tp_fail('Unknown action', 400);
