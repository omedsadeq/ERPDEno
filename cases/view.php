<?php
/**
 * View Case Page with Dental Chart
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/DentalChart.php';
require_once __DIR__ . '/../classes/Treatment.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/Installment.php';
require_once __DIR__ . '/../classes/Appointment.php';
require_once __DIR__ . '/../classes/ArchProcedureLog.php';
require_once __DIR__ . '/../classes/CaseInvoice.php';
require_once __DIR__ . '/../classes/InvoiceDeletion.php';
require_once __DIR__ . '/../classes/InvoiceCorrection.php';
require_once __DIR__ . '/../classes/AccountingLedger.php';
require_once __DIR__ . '/../classes/Settings.php';

$auth = new Auth();
$auth->requireLogin();

$caseId = intval($_GET['id'] ?? 0);
if (!$caseId) {
    header('Location: ' . APP_URL . '/cases/index.php');
    exit;
}

$caseManager = new CaseManager();
$dentalChart = new DentalChart();
$treatmentManager = new Treatment();
$paymentManager = new Payment();
$installmentManager = new Installment();
$appointmentManager = new Appointment();
$archProcedureLog = new ArchProcedureLog();
$caseInvoice = new CaseInvoice();
$accountingLedger = new AccountingLedger();

$case = $caseManager->getById($caseId);
if (!$case) {
    header('Location: ' . APP_URL . '/cases/index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$isDoctor = $auth->isDoctor();
$canEditApprovedTreatments = ($isAdmin || $isAccountant) && Settings::allowEditApprovedInvoice();
$canDeleteInvoices = $isAdmin;
// Who books money without waiting for approval. A doctor writing وەرگیراو on
// their own draft files a REQUEST instead — see the set_invoice_paid handler.
$canReceiveMoney = $isAdmin || $isAccountant;
$canRecordDownPayment = true;
$db = Database::getInstance();
// Anyone but a doctor opening a receipt here picks whose work it is — the
// doctor's cashbox and income go by it (CaseInvoice::createDraft/setDoctor).
$invoiceDoctors = $isDoctor ? [] : $db->fetchAll(
    "SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
);

// دەرمان tab — writing a prescription is a doctor's act, reading one is the
// sales counter's. Permission::getInstance() rather than the $permission that
// header.php sets, because these are needed above the header include.
require_once __DIR__ . '/../classes/Permission.php';
$canPrescribe = Permission::getInstance()->can('erp2.prescriptions.create');
$canSeePrescriptions = Permission::getInstance()->can('erp2.prescriptions.view');
// cases.edit_sent_invoice (admin/settings.php?tab=access): the doctor may still
// correct the WORK on his invoice after sending it — treatments and prices, and
// the discount while it is with the accountant. Money that was received never:
// the received figure, دراوی پێشوو and ئامانەت stay the accountant's, and the
// total can never drop below what was paid (finish_case_treatment_edit).
$doctorCanEditSent = $isDoctor && Permission::getInstance()->can('cases.edit_sent_invoice');

// Accounting staff can open the full case editor from the approval center.
if (!$isAdmin && !$isAccountant && $case['doctor_id'] != $currentUser['id']) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Get related data
$chartEntries = $dentalChart->getByCase($caseId);
$caseTreatments = $caseManager->getTreatments($caseId);
$payments = $paymentManager->getByCase($caseId);
$installmentPlan = $installmentManager->getPlanByCase($caseId);
// getPlanByCase() is active-only by design (Installment.php:414-424) — a
// doctor-proposed plan is otherwise invisible on this page while it awaits
// accountant approval. Fetched separately, mirroring
// PatientFinance::currentPlanSummary() (classes/PatientFinance.php:224-236).
// Only queried when there's no active plan: Installment::hasBlockingPlan()
// guarantees at most one active-OR-pending plan exists per case at a time,
// so a pending proposal can never coexist with an active one. 'rejected' is
// included too (the case-page dismissible reject-reason note) — a rejected
// plan is terminal and never blocks a new proposal, so this only surfaces
// the newest one, i.e. the case's current decision history, not stale noise.
$pendingOrRejectedPlan = $installmentPlan ? null : $db->fetch(
    "SELECT * FROM installment_plans WHERE case_id = :case_id AND status IN ('pending','rejected')
     ORDER BY created_at DESC LIMIT 1",
    ['case_id' => $caseId]
);

// Get arch procedure logs (for arch cases)
$isArchCase = ($case['scope_type'] ?? 'tooth') === 'arch';
$procedureLogs = [];
$procedureTypes = [];
$procedureStats = [];
$archWorkReceipts = [];
if ($isArchCase) {
    $procedureLogs = $archProcedureLog->getLogsByCase($caseId);
    $procedureTypes = $archProcedureLog->getProcedureTypes();
    $procedureStats = $archProcedureLog->getCaseStats($caseId);
    // وەسڵی کارە ئەنجامدراوەکان — what has already gone to the accountant.
    require_once __DIR__ . '/../classes/ArchWorkReceipt.php';
    $archWorkReceipts = (new ArchWorkReceipt())->getByCase($caseId);
}

// Get treatments filtered by scope
$caseScope = $case['scope_type'] ?? 'tooth';
$treatments = $treatmentManager->getAll(); // All treatments for backward compatibility
$toothTreatments = $treatmentManager->getByScope('tooth'); // For individual tooth treatments
$archTreatments = $treatmentManager->getByScope('arch'); // For arch treatments
// Both tooth sets are live at the same time. A patient can be in mixed
// dentition, so every case charts the 32 permanent teeth (1-8 per quadrant) AND
// the 20 primary/baby teeth (A-E per quadrant), and work can be recorded on
// either without switching modes. FDI keeps the two apart: permanent is
// quadrants 1-4, primary is quadrants 5-8, so no tooth number is ambiguous.
// `cases.dentition` is left in place for older rows but no longer restricts
// anything — which pane is expanded is a per-user view preference only.
$teethSets = [
    'permanent' => $dentalChart->getAllTeeth(false),
    'primary'   => $dentalChart->getAllTeeth(true),
];
// Merged per-quadrant lists for everything that needs "every tooth this case may
// carry work on" (arch totals, the add-treatment dropdown).
$teeth = [];
foreach (['upper_right', 'upper_left', 'lower_left', 'lower_right'] as $groupKey) {
    $teeth[$groupKey] = array_merge($teethSets['permanent'][$groupKey], $teethSets['primary'][$groupKey]);
}
// Max teeth one bulk action may touch: both dentitions together (32 + 20).
define('MAX_BULK_TEETH', 52);
$toothConditions = $dentalChart->getConditions();

// Build tooth treatment map for chart coloring (shared with the AJAX
// responses so the client can redraw the chart without a reload)
function build_tooth_treatment_map(array $caseTreatments) {
    $map = [];
    foreach ($caseTreatments as $ct) {
        if (empty($ct['tooth_number'])) {
            continue;
        }
        $toothNum = $ct['tooth_number'];
        if (!isset($map[$toothNum])) {
            $map[$toothNum] = [
                'treatments' => [],
                'total_price' => 0,
                'category' => null,
                'categories' => []
            ];
        }
        $map[$toothNum]['treatments'][] = $ct;
        $map[$toothNum]['total_price'] += $ct['final_price'];
        // Keep the last category for backward compatibility, but also collect
        // every distinct category so multiple treatments can render together
        $category = $ct['category'] ?? 'treated';
        $map[$toothNum]['category'] = $category;
        if (!in_array($category, $map[$toothNum]['categories'], true)) {
            $map[$toothNum]['categories'][] = $category;
        }
    }
    return $map;
}
$toothTreatmentMap = build_tooth_treatment_map($caseTreatments);

// Work that belongs to a service or to a whole arch has no tooth to paint on
// the odontogram. Keep it as its own chart feed so it remains visible without
// inventing tooth numbers or charging once for every tooth in the arch.
function build_service_treatment_rows(array $caseTreatments) {
    return array_values(array_map(function ($ct) {
        return [
            'id' => (int)($ct['id'] ?? 0),
            'invoice_id' => (int)($ct['invoice_id'] ?? 0),
            'treatment_id' => (int)($ct['treatment_id'] ?? 0),
            'treatment_name' => $ct['treatment_name'] ?? '',
            'arch_type' => in_array(($ct['arch_type'] ?? 'none'), ['upper', 'lower'], true)
                ? $ct['arch_type'] : 'none',
            'quantity' => (float)($ct['quantity'] ?? 1),
            'unit_price' => (float)($ct['unit_price'] ?? 0),
            'final_price' => (float)($ct['final_price'] ?? 0),
            'category' => $ct['category'] ?? 'other',
        ];
    }, array_filter($caseTreatments, function ($ct) {
        return empty($ct['tooth_number']);
    })));
}
$serviceTreatmentRows = build_service_treatment_rows($caseTreatments);

// Get tooth names from DentalChart class.
// Built over BOTH dentitions on purpose: the chart only draws the active set,
// but the treatments table, receipt and tooltips must still resolve a tooth
// recorded under the other set (e.g. a case switched to the child chart that
// still carries permanent-tooth work).
$toothNames = [];
$palmerNumbers = [];
foreach ($dentalChart->getAllToothNumbers() as $num) {
    $toothNames[$num] = $dentalChart->getToothName($num);
    $palmerNumbers[$num] = $dentalChart->getPalmerNumber($num);
}

$pageTitle = $case['case_number'];
// Auto-Folder: navigation goes Patient → Case (the folder level is hidden)
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => $case['patient_name'], 'url' => APP_URL . '/patients/view.php?id=' . $case['patient_id']],
    ['title' => $case['case_number']]
];

function normalize_localized_digits($value) {
    return strtr((string) $value, [
        '٠' => '0', '۰' => '0',
        '١' => '1', '۱' => '1',
        '٢' => '2', '۲' => '2',
        '٣' => '3', '۳' => '3',
        '٤' => '4', '۴' => '4',
        '٥' => '5', '۵' => '5',
        '٦' => '6', '۶' => '6',
        '٧' => '7', '۷' => '7',
        '٨' => '8', '۸' => '8',
        '٩' => '9', '۹' => '9',
    ]);
}

/**
 * The doctor's payment-intent declaration from the send-invoice dialog
 * (نەقد/قەرز/قیست, plus قەرز's optional promise) — whitelisted the same way
 * CaseInvoice::send() whitelists it, so a tampered value fails here with a
 * friendly case_view_fail() instead of an uncaught exception from inside
 * send(). See cases/partials send-invoice dialog markup for the radios.
 */
function read_invoice_intent_from_post() {
    $raw = trim((string)($_POST['payment_intent'] ?? ''));
    $intent = ['payment_intent' => in_array($raw, ['cash', 'debt', 'installment'], true) ? $raw : null];
    if ($intent['payment_intent'] === 'debt') {
        $pd = trim((string)($_POST['promised_date'] ?? ''));
        $intent['promised_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) ? $pd : null;
        $pa = normalize_localized_digits($_POST['promised_amount'] ?? '');
        $intent['promised_amount'] = ($pa !== '' && is_numeric($pa)) ? max(0, round((float)$pa, 2)) : null;
    }
    // Private remark for the accountant. Separate from the invoice's own
    // `notes`: this one is internal to the doctor/accounting pair and is
    // never rendered on a receipt, voucher or patient PDF.
    $intent['doctor_note'] = mb_substr(trim((string)($_POST['doctor_note'] ?? '')), 0, 2000);
    return $intent;
}

/**
 * The doctor's OWN still-pending payment request against this CASE — the
 * historical `$invoiceId` parameter is kept ONLY so set_invoice_paid's
 * existing call site doesn't need to change; the lookup itself is CASE-
 * scoped, not invoice-scoped (delegates to find_doctor_case_pending_request(),
 * defined below).
 *
 * Review fix (2026-08-26): this used to JOIN payment_allocations and filter
 * on `pa.invoice_id = :inv`. That broke on the NORMAL shape of a case — more
 * than one invoice (one per work day): a pending request FIFO-allocated by
 * the case-level داواکاری پارەدان dialog (request_payment handler) onto
 * invoice #2 was invisible here when the doctor next opened invoice #3's
 * وەرگیراو box, because #2's allocation never touched invoice #3 — so a
 * SECOND pending row got created instead of the single request being
 * adjusted. Both entry points must always find and adjust the exact SAME
 * row for a given doctor+case, so this now shares
 * find_doctor_case_pending_request()'s query instead of re-deriving its own.
 * set_invoice_paid's caller compensates for the invoice-vs-case granularity
 * mismatch itself — see its own comment where it converts $plan['amount']
 * (a per-invoice delta) into a delta against this now-shared row's
 * case-wide total, using pending_request_invoice_allocation() below.
 *
 * @return array|null ['id' => int, 'amount' => float] or null
 */
function find_doctor_pending_request($db, $caseId, $invoiceId, $doctorId) {
    // Prefer the declaration already attached to this exact receipt. This is
    // essential while it is still a draft: the case-level payment dialog must
    // not redistribute or overwrite a chair-side amount on another tab.
    $row = $db->fetch(
        "SELECT p.id, p.amount FROM payments p
         JOIN payment_allocations pa ON pa.payment_id = p.id
         WHERE p.case_id = :cid AND p.status = 'pending' AND p.received_by = :doc
           AND p.payment_type = :ptype AND pa.invoice_id = :inv
         ORDER BY p.id DESC LIMIT 1",
        [
            'cid' => (int)$caseId, 'doc' => (int)$doctorId,
            'ptype' => PAYMENT_CASH, 'inv' => (int)$invoiceId,
        ]
    );
    if ($row) {
        return ['id' => (int)$row['id'], 'amount' => (float)$row['amount']];
    }
    return find_doctor_case_pending_request($db, $caseId, $doctorId);
}

/**
 * How much of a doctor's unified case-level pending request (see
 * find_doctor_pending_request()/find_doctor_case_pending_request()) is
 * currently allocated to ONE specific invoice. Used by set_invoice_paid's
 * doctor branch to convert a per-invoice typed target into a delta against
 * the shared row's case-wide total, without disturbing what the row already
 * reserves against the case's OTHER invoices.
 */
function pending_request_invoice_allocation($db, $paymentId, $invoiceId) {
    return (float)($db->fetch(
        'SELECT COALESCE(SUM(allocated_amount),0) AS amt FROM payment_allocations WHERE payment_id = :pid AND invoice_id = :inv',
        ['pid' => (int)$paymentId, 'inv' => (int)$invoiceId]
    )['amt'] ?? 0);
}

/**
 * A session-scoped idempotency key for a doctor's NEW pending-payment
 * request (set_invoice_paid's وەرگیراو → Payment::createRequest, only on
 * the branch where find_doctor_pending_request() found nothing), keyed per
 * invoice and — defense in depth — per amount: a resubmit of the identical
 * target reuses the same key, but a genuinely different target (a real
 * business change, not a retry) never gets coalesced into it even if the
 * old row's decision hasn't landed yet. The actual "same target vs a new
 * one" business logic lives in the caller (find_doctor_pending_request() +
 * Payment::updatePendingAmount()) — this key is only a last-resort guard
 * against a true double-submit race on the CREATE path itself, since
 * Payment::findIdempotent() (classes/Payment.php:131-136) matches purely on
 * idempotency_key with no status filter, so a bare deterministic key (e.g.
 * invoice+user+target) would also keep matching a REJECTED row forever,
 * silently turning a legitimate re-request after a rejection into a no-op
 * "duplicate" of the dead payment. So: reused across resubmits of the SAME
 * still-pending, same-amount request (double-click, a slow network retry,
 * two open tabs — PHP's own session-file locking already serializes these
 * onto one another, so the second one sees the first's row by the time it
 * runs) but rotated the instant the amount differs OR the payment it last
 * pointed at is no longer 'pending' (approved, rejected, reversed).
 *
 * KEEP THIS IN SYNC with tests/cases/DoctorFlowTest.php's
 * test_doctor_idempotency_key() — that test can't require() this whole
 * (page, not library) file, so it reproduces this function's algorithm
 * verbatim to exercise it against the real Payment class.
 */
function doctor_request_idempotency_key($db, $invoiceId, $amount) {
    $sessionKey = 'invoice_paid_request_token_' . (int)$invoiceId;
    $token = $_SESSION[$sessionKey] ?? '';
    if ($token !== '') {
        $prior = $db->fetch('SELECT status, amount FROM payments WHERE idempotency_key = :k', ['k' => $token]);
        if (!$prior || $prior['status'] !== 'pending' || abs((float)$prior['amount'] - (float)$amount) > 0.009) {
            $token = '';
        }
    }
    if ($token === '') {
        $token = 'ivp-' . (int)$invoiceId . '-' . (int)round((float)$amount * 100) . '-' . bin2hex(random_bytes(12));
        $_SESSION[$sessionKey] = $token;
    }
    return $token;
}

/**
 * The doctor's OWN still-pending payment request reserved against this CASE
 * as a whole — the CANONICAL lookup both entry points share:
 * find_doctor_pending_request() (set_invoice_paid, the per-invoice
 * وەرگیراو box) now simply delegates here, and this is also called directly
 * by the request_payment handler (the case-page داواکاری پارەدان
 * button/dialog) and at render time for the dialog's own amount cap/prefill.
 * No invoice join needed: every payment row already carries its own case_id
 * regardless of which entry point wrote it (Payment::insertPayment() always
 * sets it from $data['case_id']), so a single WHERE on case_id + doctor +
 * status finds the SAME row no matter which entry point created or last
 * adjusted it — the two entry points deliberately share ONE adjustable
 * pending request per doctor per case, never two parallel ones. "Newest"
 * (ORDER BY id DESC) picks the one that gets adjusted if, in some edge
 * case, more than one pending row exists for this doctor on this case.
 *
 * @return array|null ['id' => int, 'amount' => float] or null
 */
function find_doctor_case_pending_request($db, $caseId, $doctorId) {
    // payment_type = cash: without this filter, a doctor's pending
    // installment-plan collection request (payment_type 'installment', see
    // find_doctor_installment_pending_request() below) would cross-match
    // here too — this lookup would silently treat it as the SAME adjustable
    // row as the case-level داواکاری پارەدان request, corrupting either one
    // via updatePendingAmount(). The two request kinds are deliberately
    // separate bookings (different booking semantics — an installment
    // approval also posts to installment_payments/the plan; a cash approval
    // does not), so they must never be found/adjusted by the same lookup.
    $row = $db->fetch(
        "SELECT p.id, p.amount FROM payments p
         WHERE p.case_id = :cid AND p.status = 'pending' AND p.received_by = :doc AND p.payment_type = :ptype
           AND NOT EXISTS (
               SELECT 1 FROM payment_allocations pa
               JOIN case_invoices ci ON ci.id = pa.invoice_id
               WHERE pa.payment_id = p.id AND ci.accounting_status IN ('draft','returned')
           )
         ORDER BY p.id DESC LIMIT 1",
        ['cid' => (int)$caseId, 'doc' => (int)$doctorId, 'ptype' => PAYMENT_CASH]
    );
    return $row ? ['id' => (int)$row['id'], 'amount' => (float)$row['amount']] : null;
}

/**
 * The doctor's OWN still-pending INSTALLMENT-type collection request on this
 * case — the installment twin of find_doctor_case_pending_request() above,
 * used by the flexible_installment_payment handler's doctor branch (monthly
 * قیست collection requests) so a double-submit of the same amount adjusts
 * one row instead of stacking a second pending request. payment_type =
 * installment keeps this independent of the case-level 'cash' request the
 * function above tracks — see that function's docblock for why the split
 * matters.
 *
 * KEEP THIS IN SYNC with tests/cases/InstallmentDoctorFlowTest.php's
 * idft_find_doctor_installment_pending_request() — that file can't
 * require() this whole (page, not library) file, so it reproduces this
 * function's algorithm verbatim to exercise it against the real classes.
 *
 * @return array|null ['id' => int, 'amount' => float] or null
 */
function find_doctor_installment_pending_request($db, $caseId, $doctorId) {
    $row = $db->fetch(
        "SELECT id, amount FROM payments
         WHERE case_id = :cid AND status = 'pending' AND received_by = :doc AND payment_type = :ptype
         ORDER BY id DESC LIMIT 1",
        ['cid' => (int)$caseId, 'doc' => (int)$doctorId, 'ptype' => PAYMENT_INSTALLMENT]
    );
    return $row ? ['id' => (int)$row['id'], 'amount' => (float)$row['amount']] : null;
}

/**
 * Case-level twin of doctor_request_idempotency_key() — identical
 * amount-aware rotation rules (reused while the request it last pointed at
 * is still pending AND the same amount, rotated the instant either changes),
 * keyed per case instead of per invoice, for request_payment's create
 * branch.
 *
 * KEEP THIS IN SYNC with tests/cases/DoctorCasePaymentRequestTest.php's
 * test_case_doctor_idempotency_key(), which reproduces this algorithm
 * verbatim (that file can't require() this page).
 */
function case_doctor_request_idempotency_key($db, $caseId, $amount) {
    $sessionKey = 'case_payment_request_token_' . (int)$caseId;
    $token = $_SESSION[$sessionKey] ?? '';
    if ($token !== '') {
        $prior = $db->fetch('SELECT status, amount FROM payments WHERE idempotency_key = :k', ['k' => $token]);
        if (!$prior || $prior['status'] !== 'pending' || abs((float)$prior['amount'] - (float)$amount) > 0.009) {
            $token = '';
        }
    }
    if ($token === '') {
        $token = 'cvp-' . (int)$caseId . '-' . (int)round((float)$amount * 100) . '-' . bin2hex(random_bytes(12));
        $_SESSION[$sessionKey] = $token;
    }
    return $token;
}

function get_latest_case_work_at($db, $caseId) {
    $row = $db->fetch("
        SELECT MAX(work_at) AS latest_work_at
        FROM (
            SELECT MAX(COALESCE(performed_date, created_at)) AS work_at
            FROM case_treatments
            WHERE case_id = :cid_treatments
            UNION ALL
            SELECT MAX(created_at) AS work_at
            FROM arch_procedure_logs
            WHERE case_id = :cid_logs
        ) work_dates
    ", [
        'cid_treatments' => $caseId,
        'cid_logs' => $caseId
    ]);

    return $row['latest_work_at'] ?? null;
}

function can_send_case_to_accounting($accountingReport, $latestCaseWorkAt) {
    if (empty($accountingReport)) {
        return true;
    }

    if (($accountingReport['status'] ?? '') !== 'processed' || empty($latestCaseWorkAt)) {
        return false;
    }

    $processedAt = $accountingReport['processed_at'] ?? $accountingReport['sent_at'] ?? null;
    return !empty($processedAt) && strtotime($latestCaseWorkAt) > strtotime($processedAt);
}

function build_procedure_log_accounting_notes($archProcedureLog, $logData) {
    $procedureName = trim($logData['custom_procedure_name'] ?? '');
    if ($procedureName === '' && !empty($logData['procedure_type_id'])) {
        $procedureType = $archProcedureLog->getProcedureTypeById($logData['procedure_type_id']);
        $procedureName = $procedureType['type_name'] ?? '';
    }
    if ($procedureName === '') {
        $procedureName = 'زیادکردنی کار';
    }

    $archLabels = [
        'both' => 'هەردووک',
        'upper' => 'سەرەوە',
        'lower' => 'خوارەوە'
    ];
    $arch = $archLabels[$logData['arch_affected'] ?? 'both'] ?? ($logData['arch_affected'] ?? '');

    $lines = [
        'زیادکردنی کار',
        'جۆری کار: ' . $procedureName,
        'ئەرکی کاریلێکراو: ' . $arch,
        'بەروار: ' . ($logData['procedure_date'] ?? '')
    ];

    if (!empty($logData['procedure_time'])) {
        $lines[] = 'کات: ' . $logData['procedure_time'];
    }

    $lines[] = 'نرخ: ' . number_format((float) ($logData['price'] ?? 0));

    if (!empty($logData['notes'])) {
        $lines[] = 'تێبینی: ' . $logData['notes'];
    }

    if (!empty($logData['next_appointment_date'])) {
        $nextAppointmentLine = 'جاری داهاتوو: ' . $logData['next_appointment_date'];
        if (!empty($logData['next_appointment_time'])) {
            $nextAppointmentLine .= ' ' . $logData['next_appointment_time'];
        }
        $lines[] = $nextAppointmentLine;
        if (!empty($logData['next_appointment_duration'])) {
            $lines[] = 'ماوەی مەوعید: ' . intval($logData['next_appointment_duration']) . ' خولەک';
        }
    }

    if (!empty($logData['next_appointment_notes'])) {
        $lines[] = 'تێبینی جاری داهاتوو: ' . $logData['next_appointment_notes'];
    }

    return implode("\n", $lines);
}

function create_procedure_next_appointment($appointmentManager, $case, $caseId, $currentUser, $logData) {
    if (empty($logData['next_appointment_date']) || empty($logData['next_appointment_time'])) {
        return ['created' => false, 'success' => true];
    }

    $notes = trim($logData['next_appointment_notes'] ?? '');
    $appointmentNotes = 'جاری داهاتوو - زیادکردنی کار';
    if ($notes !== '') {
        $appointmentNotes .= "\n" . $notes;
    }

    $duration = max(15, intval($logData['next_appointment_duration'] ?? 30));
    $appointmentTime = strlen($logData['next_appointment_time']) === 5
        ? $logData['next_appointment_time'] . ':00'
        : $logData['next_appointment_time'];

    $result = $appointmentManager->create([
        'patient_id' => intval($case['patient_id']),
        'doctor_id' => intval($case['doctor_id'] ?? $currentUser['id']),
        'folder_id' => intval($case['folder_id']),
        'case_id' => intval($caseId),
        'appointment_date' => $logData['next_appointment_date'],
        'appointment_time' => $appointmentTime,
        'duration' => $duration,
        'type' => 'followup',
        'notes' => $appointmentNotes,
        'status' => 'scheduled',
        'created_by' => intval($currentUser['id'])
    ]);

    return [
        'created' => !empty($result['success']),
        'success' => !empty($result['success']),
        'message' => $result['message'] ?? '',
        'appointment_id' => $result['appointment_id'] ?? null
    ];
}

/**
 * PRG for failures: stash the error (and the submitted values so the dialog
 * reopens pre-filled) in the session, then redirect. Without this, a failed
 * action silently re-rendered the page with stale data and a refresh
 * re-submitted the POST.
 */
function case_view_fail($caseId, $message, $dialog = null) {
    // A treatment correction and its invoice totals must succeed together.
    $db = Database::getInstance();
    while ($db->inTransaction()) {
        $db->rollback();
    }
    // AJAX callers get a JSON error instead of the PRG redirect
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message !== '' ? $message : 'هەڵەیەک ڕوویدا، تکایە دووبارە هەوڵبدەرەوە'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['case_flash_error'] = $message !== '' ? $message : 'هەڵەیەک ڕوویدا، تکایە دووبارە هەوڵبدەرەوە';
    if ($dialog) {
        $values = [];
        foreach ($_POST as $k => $v) {
            if (is_string($v) && $k !== 'csrf_token' && $k !== 'action') {
                $values[$k] = $v;
            }
        }
        $_SESSION['case_form_restore'] = ['dialog' => $dialog, 'values' => $values];
    }
    header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId);
    exit;
}

// Valid FDI tooth numbers:
//   permanent — 11-18, 21-28, 31-38, 41-48 (quadrants 1-4, positions 1-8)
//   primary   — 51-55, 61-65, 71-75, 81-85 (quadrants 5-8, positions 1-5)
function is_valid_fdi_tooth($num) {
    $num = intval($num);
    $q = intdiv($num, 10);
    $p = $num % 10;
    if ($q >= 1 && $q <= 4) {
        return $p >= 1 && $p <= 8;
    }
    if ($q >= 5 && $q <= 8) {
        return $p >= 1 && $p <= 5;
    }
    return false;
}

// Normalize a comma-separated surface list against the canonical surface
// names; returns NULL when nothing valid remains (= whole tooth).
function sanitize_tooth_surface($csv) {
    $valid = ['mesial', 'distal', 'occlusal', 'incisal', 'buccal', 'lingual', 'root'];
    $parts = array_values(array_unique(array_filter(
        array_map('trim', explode(',', (string)$csv)),
        function ($s) use ($valid) { return in_array($s, $valid, true); }
    )));
    return $parts ? implode(',', $parts) : null;
}

// Catalog rule used by every add/edit entry point. Missing/custom catalog
// rows keep the historical safe default: they require a tooth selection.
function catalog_treatment_row($db, $treatmentId) {
    static $memo = [];
    $treatmentId = (int)$treatmentId;
    if ($treatmentId <= 0) return null;
    if (!array_key_exists($treatmentId, $memo)) {
        $memo[$treatmentId] = $db->fetch(
            'SELECT id, scope, requires_tooth_number FROM treatments WHERE id = :id AND is_active = 1',
            ['id' => $treatmentId]
        ) ?: null;
    }
    return $memo[$treatmentId];
}

function catalog_treatment_requires_tooth($db, $treatmentId) {
    $row = catalog_treatment_row($db, $treatmentId);
    return $row === null || (int)($row['requires_tooth_number'] ?? 1) === 1;
}

function case_view_live_revision($caseId, array $treatments, array $financial, array $caseRow) {
    $db = Database::getInstance();
    $invoices = $db->fetchAll(
        'SELECT id, invoice_no, work_date, notes, accounting_notes, status, accounting_status,
                total_amount, discount_amount, discount_mode, discount_value, draft_received_amount,
                prior_paid_amount, advance_paid_amount, payment_intent, updated_at
         FROM case_invoices WHERE case_id = :case_id ORDER BY id',
        ['case_id' => (int)$caseId]
    );
    $payments = $db->fetchAll(
        'SELECT id, status, amount, payment_type, payment_method, payment_date, invoice_id, approved_by, rejected_by
         FROM payments WHERE case_id = :case_id ORDER BY id',
        ['case_id' => (int)$caseId]
    );
    $plans = $db->fetchAll(
        'SELECT id, invoice_id, status, total_amount, proposed_down_payment, number_of_installments, start_date
         FROM installment_plans WHERE case_id = :case_id ORDER BY id',
        ['case_id' => (int)$caseId]
    );
    return hash('sha256', json_encode([
        $treatments, $financial, $caseRow['updated_at'] ?? null, $invoices, $payments, $plans
    ]));
}

// Fresh chart + totals snapshot for AJAX mutations, so the client can update
// the page in place. Success responses always exit here.
function case_view_ajax_success($caseManager, $caseId, $extra = []) {
    global $pendingOrRejectedPlan, $caseInvoice;
    $freshCase = $caseManager->getById($caseId);
    $freshTreatments = $caseManager->getTreatments($caseId);
    $financial = (new AccountingLedger())->getSummary($caseId);
    $remaining = (float)$financial['balance'];
    $pendingOrRejectedPlan = Database::getInstance()->fetch(
        "SELECT * FROM installment_plans WHERE case_id = :case_id AND status IN ('pending','rejected')
         ORDER BY created_at DESC LIMIT 1",
        ['case_id' => (int)$caseId]
    );

    $freshTabs = case_view_sort_invoice_tabs($caseInvoice->getByCase($caseId));

    // No tab asked for (the page is still showing the "no receipt yet"
    // placeholder, or the caller doesn't track one): fall back to the same tab
    // a fresh page load would open — newest draft, else newest receipt.
    if (empty($extra['invoice_id']) && $freshTabs) {
        $fallback = $freshTabs[0];
        foreach ($freshTabs as $t) {
            if ($t['status'] === 'draft') { $fallback = $t; break; }
        }
        $extra['invoice_id'] = (int)$fallback['id'];
    }

    // When the mutation touched one visit tab, ship that pane's fresh HTML back
    // so the client swaps a single block instead of reloading the whole page.
    // Rendered by the SAME partial the page uses, so the two can never drift.
    if (!empty($extra['invoice_id'])) {
        $paneHtml = render_invoice_pane($caseId, (int)$extra['invoice_id'], $freshCase, $freshTreatments);
        if ($paneHtml !== null) {
            $extra['pane_html'] = $paneHtml['html'];
            $extra['invoice']   = $paneHtml['invoice'];
        }
    }

    // The strip always travels with the response: a receipt created by this very
    // mutation needs its button, and a tab sent/paid elsewhere needs its icon.
    $extra['tabs_html'] = render_visit_tab_bar($freshTabs, (int)($extra['invoice_id'] ?? 0), $freshCase['status']);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => true,
        'revision' => case_view_live_revision($caseId, $freshTreatments, $financial, $freshCase),
        'case_totals' => [
            'total_amount' => (float)$financial['total_invoiced'],
            'paid_amount' => (float)$financial['paid'],
            'discount_amount' => (float)$financial['total_discount'],
            'remaining' => max(0, $remaining)
        ],
        'treatments' => $freshTreatments,
        'invoice_count' => count($freshTabs),
        'tooth_map' => build_tooth_treatment_map($freshTreatments),
        'service_treatments' => build_service_treatment_rows($freshTreatments)
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Render one visit-tab pane exactly as the page does, for the AJAX response.
 * Returns ['html' => ..., 'invoice' => row] or null when the invoice is gone.
 */
function render_invoice_pane($caseId, $invoiceId, $case, $allTreatments) {
    global $dentalChart, $palmerNumbers, $toothTreatments, $isAdmin, $isDoctor, $caseInvoice, $canReceiveMoney, $pendingOrRejectedPlan, $canDeleteInvoices, $canEditApprovedTreatments, $invoiceDoctors, $doctorCanEditSent;

    $tab = $caseInvoice->getById($invoiceId);
    if (!$tab || (int)$tab['case_id'] !== (int)$caseId) {
        return null;
    }
    $tab['treatment_count'] = 0;
    $rows = [];
    foreach ($allTreatments as $ct) {
        if ((int)($ct['invoice_id'] ?? 0) === (int)$invoiceId) {
            $rows[] = $ct;
            $tab['treatment_count']++;
        }
    }
    // The partial reads these from scope; make the visibility explicit.
    $activeInvoiceId  = (int)$invoiceId;
    $paneAlwaysVisible = true;

    ob_start();
    include __DIR__ . '/partials/invoice_pane.php';
    return ['html' => ob_get_clean(), 'invoice' => $tab];
}

/**
 * Tabs newest-first, drafts before sent ones on the same date.
 * One ordering for the page and for the AJAX response, so a tab never moves
 * when the strip is re-rendered.
 */
function case_view_sort_invoice_tabs(array $tabs) {
    usort($tabs, function ($a, $b) {
        if ($a['work_date'] !== $b['work_date']) {
            return strcmp($b['work_date'], $a['work_date']);
        }
        $rank = function ($s) { return $s === 'draft' ? 0 : 1; };
        $r = $rank($a['status']) <=> $rank($b['status']);
        return $r !== 0 ? $r : ((int)$b['invoice_seq'] <=> (int)$a['invoice_seq']);
    });
    return $tabs;
}

/**
 * The visit-tab strip. Rendered on page load AND shipped back with every AJAX
 * mutation: the first treatment of a day creates its receipt server-side, so
 * without a fresh strip the page would keep showing "no receipt yet" while the
 * work sits on a tab that has no button.
 */
function render_visit_tab_bar(array $invoiceTabs, $activeInvoiceId, $caseStatus) {
    ob_start();
    foreach ($invoiceTabs as $tab): $tid = (int)$tab['id']; ?>
        <button type="button"
                class="vt-tab vt-tab--<?= $tab['status'] ?><?= $tid === (int)$activeInvoiceId ? ' is-active' : '' ?>"
                data-invoice-tab="<?= $tid ?>" onclick="switchVisitTab(<?= $tid ?>)">
            <span class="vt-tab-date">
                <?php if ($tab['status'] === 'draft'): ?><i class="fas fa-pen-ruler" title="نەنێردراو"></i>
                <?php elseif ($tab['status'] === 'processed'): ?><i class="fas fa-check-double" title="پارە وەرگیراوە"></i>
                <?php else: ?><i class="fas fa-paper-plane" title="لای ژمێریار"></i><?php endif; ?>
                <?= htmlspecialchars(date('Y/n/j', strtotime($tab['work_date']))) ?>
            </span>
            <span class="vt-tab-no" dir="ltr"><?= htmlspecialchars($tab['invoice_no']) ?></span>
        </button>
    <?php endforeach;
    if ($caseStatus === 'open'): ?>
        <button type="button" class="vt-add" onclick="openNewVisitTab()" title="وەسڵێکی نوێ بۆ ڕۆژێکی تر">
            <i class="fas fa-plus"></i> تابی نوێ
        </button>
    <?php endif;
    return ob_get_clean();
}

// Small live snapshot for an already-open case page.
if (($_GET['ajax'] ?? '') === 'case_snapshot') {
    $requestedInvoiceId = max(0, intval($_GET['invoice_id'] ?? 0));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    case_view_ajax_success($caseManager, $caseId, ['invoice_id' => $requestedInvoiceId]);
}

/**
 * Billed-work guard: a treatment that already sits on a visit invoice was sent
 * to the accountant — the printed receipt must stay truthful. Only an admin may
 * touch sent rows. Accounting staff may correct approved rows when the approved
 * edit setting is enabled. Returns affected invoices for atomic recomputation.
 */
function guard_billed_treatments($db, $caseId, array $treatmentIds, $isAdmin, $dialog = null) {
    global $canEditApprovedTreatments, $doctorCanEditSent;
    $ids = array_values(array_filter(array_map('intval', $treatmentIds)));
    if (!$ids) {
        return [];
    }
    $idList = implode(',', $ids);
    // Only invoices that have LEFT the doctor lock their rows. A draft is still
    // the doctor's own tab and stays fully editable.
    $rows = $db->fetchAll(
        "SELECT DISTINCT ct.invoice_id,ci.accounting_status
         FROM case_treatments ct
         JOIN case_invoices ci ON ci.id = ct.invoice_id
         WHERE ct.case_id = :cid AND ct.id IN ({$idList}) AND ci.status <> 'draft'",
        ['cid' => $caseId]
    );
    $sentInvoiceIds = [];
    foreach ($rows as $lockedInvoice) {
        if (($lockedInvoice['accounting_status'] ?? '') === 'approved' && !$canEditApprovedTreatments && empty($doctorCanEditSent)) {
            case_view_fail($caseId, 'پسووڵەی پەسەندکراو سندێکی ژمێریارییە و چارەسەرەکانی ناگۆڕدرێن؛ تەنها خانە ژمێریارییە ڕێپێدراوەکان بگۆڕە.', $dialog);
        }
        if (($lockedInvoice['accounting_status'] ?? '') !== 'approved') {
            $sentInvoiceIds[] = (int)$lockedInvoice['invoice_id'];
        }
    }
    if ($sentInvoiceIds && !invoice_edit_allowed($isAdmin)) {
        case_view_fail($caseId, invoice_locked_message(), $dialog);
    }

    // Drafts touched by this action still need their totals recomputed.
    $all = $db->fetchAll(
        "SELECT DISTINCT invoice_id FROM case_treatments
         WHERE case_id = :cid AND invoice_id IS NOT NULL AND id IN ({$idList})",
        ['cid' => $caseId]
    );
    return array_map(function ($r) { return (int)$r['invoice_id']; }, $all);
}

/** Validate corrected totals before committing any treatment changes. */
function finish_case_treatment_edit(array $invoiceIds, $caseId) {
    global $db, $caseInvoice, $caseManager;
    try {
        foreach (array_unique(array_map('intval', $invoiceIds)) as $invoiceId) {
            $invoice = $caseInvoice->getById($invoiceId);
            // Sent or approved: whoever corrects the work, the money already
            // on the invoice stays covered — the total never drops below it.
            if (in_array($invoice['accounting_status'] ?? '', ['approved', 'sent'], true)) {
                $row = $db->fetch("SELECT COALESCE(SUM(final_price),0) AS total FROM case_treatments WHERE invoice_id = ? AND status <> 'cancelled'", [$invoiceId]);
                $total = (float)$row['total'];
                $discount = ($invoice['discount_mode'] ?? 'amount') === 'percent'
                    ? $total * (float)$invoice['discount_value'] / 100
                    : min((float)$invoice['discount_amount'], $total);
                $discount = round(max(0, $discount), 2);
                $moneyChanged = abs($total - (float)$invoice['total_amount']) > 0.009
                    || abs($discount - (float)$invoice['discount_amount']) > 0.009;
                if ($moneyChanged && $db->fetch("SELECT id FROM installment_plans WHERE invoice_id = ? AND status = 'active' LIMIT 1", [$invoiceId])) {
                    throw new RuntimeException('پلانی قیستی چالاک لەسەر ئەم وەسڵە هەیە — نرخەکەی ناگۆڕدرێت');
                }
                $allocated = $db->fetch("SELECT COALESCE(SUM(pa.allocated_amount),0) AS amount FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE pa.invoice_id = ? AND p.status IN ('approved','pending')", [$invoiceId]);
                $paid = max(0, (float)($invoice['draft_received_amount'] ?? 0))
                    + max(0, (float)($invoice['prior_paid_amount'] ?? 0))
                    + max(0, (float)($invoice['advance_paid_amount'] ?? 0))
                    + (float)$allocated['amount'];
                if ($moneyChanged && $paid > $total - $discount + 0.009) {
                    // Say the two figures: the doctor sees exactly how far the
                    // work can come down, and that the paid money is fixed.
                    throw new RuntimeException('ئەم گۆڕانکارییە ناکرێت: کۆی وەسڵ دەبێتە ' . number_format(max(0, $total - $discount))
                        . '، بەڵام ' . number_format($paid) . ' پارەی دراوی لەسەرە و پارەی دراو ناگۆڕدرێت. '
                        . 'دەتوانیت کۆی وەسڵ تەنها تا ' . number_format(max(0, (float)$invoice['total_amount'] - (float)$invoice['discount_amount'] - $paid))
                        . ' کەم بکەیتەوە (زیادکردن هەمیشە دەکرێت).');
                }
            }
            $caseInvoice->recomputeTotal($invoiceId);
        }
        $caseManager->updateCaseTotals($caseId);
        $db->commit();
    } catch (Throwable $e) {
        case_view_fail($caseId, $e->getMessage());
    }
}

/**
 * A receipt handed to the accountant is frozen — its treatments and its
 * discount cannot be touched, by anyone. The admin-only escape hatch exists
 * for corrections and is OFF unless switched on in
 * admin/settings.php?tab=access.
 */
function invoice_edit_allowed($isAdmin) {
    global $doctorCanEditSent;
    // The doctor's own correction right (cases.edit_sent_invoice) covers the
    // work and a sent invoice's discount — never its money fields, which the
    // set_invoice_paid / prior / advance handlers keep to drafts.
    return ($isAdmin && Settings::allowEditSentInvoice()) || !empty($doctorCanEditSent);
}

function invoice_locked_message() {
    return Settings::allowEditSentInvoice()
        ? 'ئەم وەسڵە نێردراوە بۆ ژمێریار — تەنها بەڕێوەبەر دەتوانێت بیگۆڕێت'
        : 'ئەم وەسڵە نێردراوە بۆ ژمێریار و قوفڵدراوە — ناتوانرێت بگۆڕدرێت';
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];
    $caseRemaining = (float)$case['total_amount'] - (float)$case['paid_amount'] - (float)$case['discount_amount'];

    if ($action === 'admin_correct_invoice') {
        if (!$isAdmin) case_view_fail($caseId, 'دەستکاریکردن تەنها بۆ ئەدمینە');
        try {
            $correction = json_decode($_POST['correction'] ?? '', true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($correction)) throw new InvalidArgumentException('داتای نادروست');
        } catch (Throwable $e) {
            case_view_fail($caseId, 'داتای دەستکاریکردن نادروستە');
        }
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $result = (new InvoiceCorrection())->save($caseId, $invoiceId, (int)$currentUser['id'], $correction);
        if (empty($result['success'])) case_view_fail($caseId, $result['message']);
        case_view_ajax_success($caseManager, $caseId, ['invoice_id'=>$invoiceId, 'message'=>'گۆڕانکارییەکان پاشەکەوت کران']);
    }

    // Closed cases allow reopening and explicit admin invoice removal only.
    if (($case['status'] ?? '') === 'closed' && $action !== 'reopen_case'
        && !($isAdmin && in_array($action, ['delete_invoice', 'delete_invoice_tab'], true))) {
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&error=case_closed');
        exit;
    }

    if (in_array($action, ['delete_treatment', 'delete_grouped_treatments', 'update_treatment', 'update_grouped_treatment'], true)) {
        $db->beginTransaction();
        // Use the same invoice locks as payment allocation before changing work.
        $db->fetchAll('SELECT id FROM case_invoices WHERE case_id = ? ORDER BY id FOR UPDATE', [$caseId]);
    }

    /**
     * Which visit tab new work belongs to. The client posts invoice_id (the
     * open tab); anything unknown or already sent falls back to the newest
     * draft — or a fresh one — so work is never filed onto a sent receipt.
     * Returns [invoiceId, performedDate] — a back-dated tab back-dates its work.
     */
    $resolve_target_draft = function () use ($caseInvoice, $caseId, $currentUser) {
        $posted = intval($_POST['invoice_id'] ?? 0);
        $draft = $posted > 0 ? $caseInvoice->getEditableDraft($caseId, $posted) : null;
        if (!$draft) {
            $id = $caseInvoice->getOrCreateActiveDraft($caseId, $currentUser['id']);
            $draft = $id ? $caseInvoice->getEditableDraft($caseId, $id) : null;
        }
        if (!$draft) {
            return [null, null];
        }
        // Keep the clock but move the date onto the tab's work day.
        $performed = $draft['work_date'] === date('Y-m-d')
            ? date('Y-m-d H:i:s')
            : $draft['work_date'] . ' ' . date('H:i:s');
        return [(int)$draft['id'], $performed];
    };

    if ($action === 'add_treatment') {
        $catalogTreatmentId = intval($_POST['treatment_id'] ?? 0) ?: null;
        // Every treatment works both ways: with a tooth number it is charted
        // on that tooth, without one it is saved as a single job.
        $toothNumber = intval($_POST['tooth_number'] ?? 0) ?: null;
        if ($toothNumber !== null && !is_valid_fdi_tooth($toothNumber)) {
            case_view_fail($caseId, 'ژمارەی ددان نادروستە', 'addTreatmentDialog');
        }
        $data = [
            'treatment_id' => $catalogTreatmentId,
            'tooth_number' => $toothNumber,
            'tooth_surface' => $toothNumber !== null ? sanitize_tooth_surface($_POST['tooth_surface'] ?? '') : null,
            'arch_type' => 'none',
            'treatment_name' => trim($_POST['treatment_name']),
            'quantity' => intval($_POST['quantity']) ?: 1,
            'unit_price' => floatval($_POST['unit_price']),
            'discount' => floatval($_POST['discount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];
        list($targetInvoiceId, $targetPerformed) = $resolve_target_draft();
        if ($targetInvoiceId) {
            $data['invoice_id'] = $targetInvoiceId;
            $data['performed_date'] = $targetPerformed;
        }
        $result = $caseManager->addTreatment($caseId, $data);
        if ($result['success']) {
            if ($targetInvoiceId) { $caseInvoice->recomputeTotal($targetInvoiceId); }
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId, ['treatment_id' => $result['treatment_id'], 'invoice_id' => $targetInvoiceId]);
            }
            // "Save & add another" keeps the doctor in the flow: reopen the
            // dialog immediately instead of popping the send-to-accounting one.
            $saveAndNew = !empty($_POST['save_and_new']);
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatment_added=1'
                . ($saveAndNew ? '&reopen=addTreatmentDialog' : '&work_added=1'));
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'addTreatmentDialog');
    } elseif ($action === 'add_treatment_bulk') {
        $catalogTreatmentId = intval($_POST['treatment_id'] ?? 0) ?: null;
        // Add treatment for multiple teeth at once — all-or-nothing
        $toothNumbers = json_decode($_POST['tooth_numbers'] ?? '[]', true);
        if (!is_array($toothNumbers) || empty($toothNumbers) || count($toothNumbers) > MAX_BULK_TEETH) {
            case_view_fail($caseId, 'تکایە لانیکەم ددانێک هەڵبژێرە');
        }
        foreach ($toothNumbers as $toothNumber) {
            if (!is_valid_fdi_tooth($toothNumber)) {
                case_view_fail($caseId, 'ژمارەی ددان نادروستە');
            }
        }
        $treatmentData = [
            'treatment_id' => $catalogTreatmentId,
            'treatment_name' => trim($_POST['treatment_name']),
            // Quantity belongs to the WHOLE visible grouped row, not to every
            // selected tooth. It is split below so saving 5 teeth at qty 5
            // remains qty 5 (not 25) and price remains 5 × unit price.
            'quantity' => max(1, intval($_POST['quantity']) ?: 1),
            'unit_price' => floatval($_POST['unit_price']),
            'discount' => max(0, floatval($_POST['discount'] ?? 0)),
            'notes' => trim($_POST['notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];
        $toothNumbers = array_values(array_unique(array_map('intval', $toothNumbers)));
        $toothCount = count($toothNumbers);
        if ($treatmentData['quantity'] < $toothCount) {
            case_view_fail($caseId, 'ژمارە نابێت لە ژمارەی ددانە هەڵبژێردراوەکان کەمتر بێت');
        }
        $groupGross = $treatmentData['quantity'] * max(0, (float)$treatmentData['unit_price']);
        if ($treatmentData['discount'] > $groupGross + 0.009) {
            case_view_fail($caseId, 'داشکاندن ناتوانێت لە کۆی نرخ زیاتر بێت');
        }
        $rowQuantities = CaseManager::splitBulkQuantity($treatmentData['quantity'], $toothCount);
        $remainingDiscount = round((float)$treatmentData['discount'], 2);
        list($targetInvoiceId, $targetPerformed) = $resolve_target_draft();
        if ($targetInvoiceId) {
            $treatmentData['invoice_id'] = $targetInvoiceId;
            $treatmentData['performed_date'] = $targetPerformed;
        }

        $db->beginTransaction();
        $successCount = 0;
        $failMessage = '';
        foreach ($toothNumbers as $toothIndex => $toothNumber) {
            $rowQuantity = $rowQuantities[$toothIndex];
            // Discount is also a group total. Allocate it by quantity and let
            // the last row absorb decimal rounding so the saved sum is exact.
            $rowDiscount = $toothIndex === $toothCount - 1
                ? $remainingDiscount
                : round($treatmentData['discount'] * $rowQuantity / $treatmentData['quantity'], 2);
            $rowDiscount = min($remainingDiscount, $rowDiscount);
            $remainingDiscount = round($remainingDiscount - $rowDiscount, 2);
            $data = array_merge($treatmentData, [
                'tooth_number' => $toothNumber,
                'quantity' => $rowQuantity,
                'discount' => $rowDiscount,
            ]);
            $result = $caseManager->addTreatment($caseId, $data);
            if ($result['success']) {
                $successCount++;
            } else {
                $failMessage = $result['message'] ?? '';
                break;
            }
        }

        if ($successCount === count($toothNumbers)) {
            $db->commit();
            if ($targetInvoiceId) { $caseInvoice->recomputeTotal($targetInvoiceId); }
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId, ['added' => $successCount, 'invoice_id' => $targetInvoiceId]);
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatments_added=' . $successCount . '&work_added=1');
            exit;
        }
        $db->rollback();
        case_view_fail($caseId, $failMessage);
    } elseif ($action === 'add_arch_treatment') {
        // Whole-arch work: Maxilla/Mandible are billable jobs, not 16 teeth.
        $archType = $_POST['arch_type'] ?? '';
        if (empty($archType)) {
            // Redirect back with error
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&error=no_arch_selected');
            exit;
        }

        $pricingMode = $_POST['pricing_mode'] ?? 'per_arch';

        $treatmentData = [
            'treatment_id' => intval($_POST['treatment_id']) ?: null,
            'treatment_name' => trim($_POST['treatment_name']),
            'quantity' => 1,
            'discount' => 0,
            'notes' => trim($_POST['notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];

        $archCount = $archType === 'both' ? 2 : 1;
        // Legacy per_tooth is accepted as an alias so an already-open browser
        // tab cannot fall back to multiplying by sixteen after deployment.
        if ($pricingMode === 'per_arch' || $pricingMode === 'per_tooth') {
            $treatmentData['unit_price'] = max(0, floatval($_POST['unit_price']));
        } elseif ($pricingMode === 'total') {
            $wholeTotal = max(0, floatval($_POST['total_price'] ?? 0));
            $perArch = $archCount > 0 ? round($wholeTotal / $archCount, 2) : 0;
            $treatmentData['unit_price'] = $perArch;
            $treatmentData['upper_unit_price'] = $perArch;
            $treatmentData['lower_unit_price'] = $perArch;
        } elseif ($pricingMode === 'separate') {
            $treatmentData['upper_unit_price'] = max(0, floatval($_POST['upper_unit_price'] ?: 0));
            $treatmentData['lower_unit_price'] = max(0, floatval($_POST['lower_unit_price'] ?: 0));
            $treatmentData['unit_price'] = 0;
        } else {
            $treatmentData['unit_price'] = floatval($_POST['unit_price']);
        }

        if (!in_array($archType, ['upper', 'lower', 'both'], true)) {
            case_view_fail($caseId, 'هەڵبژاردنی ئەرک نادروستە', 'archTreatmentDialog');
        }
        if (trim($treatmentData['treatment_name']) === '') {
            case_view_fail($caseId, 'ناوی چارەسەر پێویستە', 'archTreatmentDialog');
        }
        list($targetInvoiceId, $targetPerformed) = $resolve_target_draft();
        if ($targetInvoiceId) {
            $treatmentData['invoice_id'] = $targetInvoiceId;
            $treatmentData['performed_date'] = $targetPerformed;
        }
        $result = $caseManager->addArchTreatment($caseId, $treatmentData, $archType);
        if ($result['success']) {
            if ($targetInvoiceId) { $caseInvoice->recomputeTotal($targetInvoiceId); }
            // Update the case's selected_arch if it's an arch-type case
            if ($case['scope_type'] === 'arch') {
                $currentArch = $case['selected_arch'];
                // Merge with existing arch selection
                if ($currentArch === 'both' || $archType === 'both') {
                    $newArch = 'both';
                } elseif (empty($currentArch)) {
                    $newArch = $archType;
                } elseif ($currentArch !== $archType) {
                    $newArch = 'both';
                } else {
                    $newArch = $archType;
                }
                // Update case with new arch selection
                $db->update('cases', ['selected_arch' => $newArch], 'id = :id', ['id' => $caseId]);
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&arch_treatments_added=' . $result['count'] . '&work_added=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'archTreatmentDialog');
    } elseif ($action === 'direct_payment') {
        if (!$canReceiveMoney) { case_view_fail($caseId, 'تەنها بەڕێوەبەر یان ژمێریار پارە وەردەگرێت', 'paymentDialog'); }
        try { $amount = PaymentDiscount::number($_POST['amount'] ?? 0); }
        catch (InvalidArgumentException $e) { case_view_fail($caseId, $e->getMessage(), 'paymentDialog'); }
        if ($amount <= 0) {
            case_view_fail($caseId, 'بڕی پارە دەبێت گەورەتر بێت لە سفر', 'paymentDialog');
        }
        // Check if this is an installment payment
        if (!empty($_POST['installment_id'])) {
            if ((float)($_POST['discount_value'] ?? 0) != 0) {
                case_view_fail($caseId, 'سەرەتا ژمێریار پلانی قیست ڕێک بخاتەوە', 'paymentDialog');
            }
            $scheduleId = intval($_POST['installment_id']);
            // Ownership: the schedule row must belong to a plan on THIS case
            $schedOwner = $db->fetch(
                "SELECT ip.case_id FROM installment_schedule s
                 JOIN installment_plans ip ON s.plan_id = ip.id
                 WHERE s.id = :id",
                ['id' => $scheduleId]
            );
            if (!$schedOwner || (int)$schedOwner['case_id'] !== (int)$caseId) {
                case_view_fail($caseId, 'ئەم قیستە سەر بەم کەیسە نییە');
            }
            $result = $installmentManager->payInstallment($scheduleId, $amount, $currentUser['id']);
        } else {
            // UNIFIED PAYMENT ENGINE: directPayment handles payment + auto-allocation internally
            $data = [
                'case_id' => $caseId,
                'payment_type' => $_POST['payment_type'],
                'amount' => $amount,
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'notes' => trim($_POST['notes'] ?? ''),
                'discount_mode' => $_POST['discount_mode'] ?? 'amount',
                'discount_value' => $_POST['discount_value'] ?? '0',
                'discount_reason' => trim($_POST['discount_reason'] ?? ''),
                'idempotency_key' => mb_substr(trim((string)($_POST['idempotency_key'] ?? '')), 0, 80),
            ];
            $result = $paymentManager->directPayment($data, $currentUser['id']);

            // Store allocation details for display if returned
            if ($result['success'] && isset($result['allocation'])) {
                $_SESSION['last_allocation'] = $result['allocation'];
            }
        }
        if ($result['success']) {
            // Money landed — see whether it just settled any visit invoices
            // (flips them to processed + notifies the sending doctor).
            try { $caseInvoice->syncStatuses($caseId, (int)$currentUser['id']); } catch (Exception $e) { /* never block the payment */ }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&payment_added=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'paymentDialog');
    } elseif (in_array($action, ['save_payment_draft', 'request_payment', 'send_payment_request'], true)) {
        // Send from the dialog, or keep an optional unsent draft.
        if (!$isDoctor) {
            case_view_fail($caseId, 'تەنها دکتۆر دەتوانێت داواکاری پارەدان دروست بکات', 'paymentDialog');
        }
        try { $amount = PaymentDiscount::number($_POST['amount'] ?? 0); }
        catch (InvalidArgumentException $e) { case_view_fail($caseId, $e->getMessage(), 'paymentDialog'); }
        if ($amount <= 0) {
            case_view_fail($caseId, 'بڕی پارە دەبێت گەورەتر بێت لە سفر', 'paymentDialog');
        }
        $sendNow = $action === 'send_payment_request' && empty($_POST['save_only']);
        $paymentData = [
            'case_id' => $caseId,
            'amount' => $amount,
            'payment_type' => 'cash',
            'payment_method' => 'cash',
            'notes' => trim($_POST['notes'] ?? ''),
            'received_by' => (int)$currentUser['id'],
            'idempotency_key' => mb_substr(trim((string)($_POST['idempotency_key'] ?? '')), 0, 80),
            'discount_mode' => $_POST['discount_mode'] ?? 'amount',
            'discount_value' => $_POST['discount_value'] ?? '0',
            'discount_reason' => trim($_POST['discount_reason'] ?? ''),
        ];
        $result = $sendNow
            ? $paymentManager->saveAndSendDraft($paymentData, (int)$currentUser['id'], intval($_POST['draft_id'] ?? 0))
            : $paymentManager->saveDraft($paymentData, (int)$currentUser['id'], intval($_POST['draft_id'] ?? 0));
        if (empty($result['success'])) {
            case_view_fail($caseId, $result['message'] ?? ($sendNow ? 'ناردنی داواکاری سەرنەکەوت' : 'خەزنکردنی قەبزی ڕەشنووس سەرنەکەوت'), 'paymentDialog');
        }
        $flag = $sendNow ? 'payment_draft_sent' : (!empty($result['updated']) ? 'payment_draft_updated' : 'payment_draft_saved');
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=payments&' . $flag . '=1');
        exit;
    } elseif ($action === 'send_payment_draft') {
        if (!$isDoctor) {
            case_view_fail($caseId, 'تەنها دکتۆر دەتوانێت قەبزی ڕەشنووس بنێرێت');
        }
        $paymentId = intval($_POST['payment_id'] ?? 0);
        $draft = $db->fetch(
            "SELECT id FROM payments
             WHERE id=:id AND case_id=:case_id AND received_by=:actor AND status='draft'",
            ['id' => $paymentId, 'case_id' => $caseId, 'actor' => (int)$currentUser['id']]
        );
        if (!$draft) {
            case_view_fail($caseId, 'قەبزی ڕەشنووس نەدۆزرایەوە');
        }
        $result = $paymentManager->sendDraft($paymentId, (int)$currentUser['id']);
        if (empty($result['success'])) {
            case_view_fail($caseId, $result['message'] ?? 'ناردنی قەبز سەرنەکەوت');
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=payments&payment_draft_sent=1');
        exit;
    } elseif ($action === 'create_installment') {
        // Money roles book the plan (and any down payment) outright, the same
        // engine as every other direct booking. A doctor only PROPOSES —
        // the whole plan, down payment included, is a pending request the
        // accountant must activate (Installment::proposePlan); nothing is
        // scheduled or paid until then.
        $totalAmount = max(0, floatval(normalize_localized_digits($_POST['total_amount'] ?? 0)));
        $downPayment = min(max(0, floatval(normalize_localized_digits($_POST['down_payment'] ?? 0))), $totalAmount);
        $planNotes = trim($_POST['notes'] ?? '');
        $numberOfInstallments = max(1, intval(normalize_localized_digits($_POST['number_of_installments'] ?? 1)));
        $data = [
            'case_id' => $caseId,
            'total_amount' => $totalAmount,
            'down_payment' => $downPayment,
            'number_of_installments' => $numberOfInstallments,
            'start_date' => $_POST['start_date'],
            'notes' => $planNotes,
            'created_by' => $currentUser['id'],
        ];
        if ($canReceiveMoney) {
            $result = $installmentManager->createPlan($data);
            if ($result['success']) {
                header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&installment_created=1');
                exit;
            }
            case_view_fail($caseId, $result['message'] ?? '', 'installmentDialog');
        } else {
            try {
                $installmentManager->proposePlan($data, (int)$currentUser['id']);
            } catch (Exception $e) {
                case_view_fail($caseId, $e->getMessage(), 'installmentDialog');
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&installment_proposed=1');
            exit;
        }
    } elseif ($action === 'flexible_installment_payment') {
        // Flexible installment payment - any amount at any time
        $planId = intval($_POST['plan_id']);
        // Ownership: the plan must belong to THIS case (prevents cross-case payment)
        $planOwner = $db->fetch("SELECT case_id FROM installment_plans WHERE id = :id", ['id' => $planId]);
        if (!$planOwner || (int)$planOwner['case_id'] !== (int)$caseId) {
            case_view_fail($caseId, 'ئەم پلانە سەر بەم کەیسە نییە');
        }
        $flexAmount = floatval(normalize_localized_digits($_POST['amount'] ?? 0));
        if ($flexAmount <= 0) {
            case_view_fail($caseId, 'بڕی پارە دەبێت گەورەتر بێت لە سفر');
        }
        $data = [
            'amount' => $flexAmount,
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes' => trim($_POST['notes'] ?? '')
        ];
        if ($isAdmin) {
            // Admin collects the installment payment directly (auto-approved).
            $result = $installmentManager->makeFlexiblePayment($planId, $data, $currentUser['id']);
            if ($result['success']) {
                header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&installment_paid=1');
                exit;
            }
            case_view_fail($caseId, $result['message'] ?? '');
        } else {
            // Doctor files a REQUEST — the accountant must approve it before it
            // counts, exactly like a regular payment. Same Task-12 dedupe
            // semantic as the case-level request_payment handler above, but
            // keyed to THIS doctor's own pending INSTALLMENT-type request
            // (find_doctor_installment_pending_request(), payment_type-
            // filtered so it never cross-matches a pending case-level 'cash'
            // request on the same case — see that function's docblock):
            // resubmitting the same monthly amount adjusts nothing (still
            // one row); a genuinely different amount updates the SAME row
            // in place; nothing pending yet falls through to
            // requestFlexiblePayment()'s own fresh create (which caps the
            // amount at the plan's remaining balance minus already-pending
            // installment requests) — self-notifies, no manual report here.
            $instPendingReq = find_doctor_installment_pending_request($db, $caseId, (int)$currentUser['id']);
            $instRequestOutcome = null;
            if ($instPendingReq && abs($flexAmount - $instPendingReq['amount']) <= 0.009) {
                $result = ['success' => true, 'payment_id' => $instPendingReq['id']];
                $instRequestOutcome = 'unchanged';
            } elseif ($instPendingReq) {
                try {
                    $paymentManager->updatePendingAmount($instPendingReq['id'], $flexAmount, (int)$currentUser['id'], 'گۆڕینی بڕی داواکاری قیست لەلایەن دکتۆر');
                    $result = ['success' => true, 'payment_id' => $instPendingReq['id']];
                    $instRequestOutcome = 'updated';
                } catch (Exception $e) {
                    case_view_fail($caseId, $e->getMessage());
                }
            } else {
                $result = $installmentManager->requestFlexiblePayment($planId, $data, $currentUser['id']);
                $instRequestOutcome = 'created';
            }
            if (!empty($result['success'])) {
                $instRedirectFlag = $instRequestOutcome === 'unchanged' ? 'installment_request_unchanged'
                    : ($instRequestOutcome === 'updated' ? 'installment_request_updated' : 'payment_requested');
                header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&' . $instRedirectFlag . '=1');
                exit;
            }
            case_view_fail($caseId, $result['message'] ?? '');
        }
    } elseif ($action === 'skip_installment_month') {
        // Skip month and move remaining to next month
        $scheduleId = intval($_POST['schedule_id']);
        // Ownership: the schedule row must belong to a plan on THIS case
        $schedOwner = $db->fetch(
            "SELECT ip.case_id FROM installment_schedule s
             JOIN installment_plans ip ON s.plan_id = ip.id
             WHERE s.id = :id",
            ['id' => $scheduleId]
        );
        if (!$schedOwner || (int)$schedOwner['case_id'] !== (int)$caseId) {
            case_view_fail($caseId, 'ئەم قیستە سەر بەم کەیسە نییە');
        }
        $result = $installmentManager->skipMonth($scheduleId);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&month_skipped=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'close_case') {
        $closingComment = trim($_POST['closing_comment'] ?? '');
        $result = $caseManager->close($caseId, $closingComment ?: null);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&case_closed_ok=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'closeCaseDialog');
    } elseif ($action === 'reopen_case') {
        $result = $caseManager->reopen($caseId);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&case_reopened=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'create_invoice_tab') {
        // Open a new visit tab (draft invoice) for the chosen day.
        $workDate = trim($_POST['work_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $workDate = date('Y-m-d');
        }
        if ($workDate > date('Y-m-d')) {
            case_view_fail($caseId, 'ناتوانرێت وەسڵ بۆ ڕۆژێکی داهاتوو دروست بکرێت');
        }
        // A doctor's own receipt is his; anyone else must say whose it is.
        $tabDoctorId = $isDoctor ? null : intval($_POST['doctor_id'] ?? 0);
        if (!$isDoctor && $tabDoctorId <= 0) {
            case_view_fail($caseId, 'دکتۆری ئەم وەسڵە هەڵبژێرە');
        }
        $res = $caseInvoice->createDraft($caseId, $workDate, $currentUser['id'], $tabDoctorId);
        if (empty($res['success'])) {
            case_view_fail($caseId, $res['message'] ?? 'دروستکردنی وەسڵ سەرنەکەوت');
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . (int)$res['invoice_id']);
        exit;

    } elseif ($action === 'set_invoice_doctor') {
        // Whose work a receipt is, changed by the admin/accountant who filed it
        // — until it is approved (CaseInvoice::setDoctor).
        if ($isDoctor) {
            case_view_fail($caseId, 'دەسەڵاتت نییە');
        }
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $res = $caseInvoice->setDoctor($caseId, $invoiceId, intval($_POST['doctor_id'] ?? 0), (int)$currentUser['id']);
        if (empty($res['success'])) {
            case_view_fail($caseId, $res['message'] ?? 'گۆڕینی دکتۆر سەرنەکەوت');
        }
        if (($_POST['ajax'] ?? '') === '1') {
            case_view_ajax_success($caseManager, $caseId, ['invoice_id' => $invoiceId]);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId);
        exit;

    } elseif ($action === 'set_invoice_discount') {
        // Discounts are negotiated per receipt, so they live on the invoice —
        // never on the case. Doctors may discount their own open draft; once it
        // is with the accountant only an admin may touch it.
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $target = $caseInvoice->getById($invoiceId);
        if (!$target || (int)$target['case_id'] !== $caseId) {
            case_view_fail($caseId, 'وەسڵ نەدۆزرایەوە');
        }
        if ($target['status'] !== 'draft' && !invoice_edit_allowed($isAdmin)) {
            case_view_fail($caseId, invoice_locked_message());
        }
        // The mode is stored too, so a percentage keeps reading as a percentage.
        $mode  = ($_POST['discount_mode'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
        $value = max(0, floatval(normalize_localized_digits($_POST['discount_value'] ?? 0)));
        if (($target['accounting_status'] ?? '') === 'approved') {
            // An approved invoice's discount: the doctor's own correction right
            // (cases.edit_sent_invoice), or the money roles' approved-edit
            // switch. editApproved never lets it cut below the money paid.
            if (empty($doctorCanEditSent) && !(($isAdmin || $isAccountant) && Settings::allowEditApprovedInvoice())) {
                case_view_fail($caseId, 'وەسڵی پەسەندکراو دەستکاری ناکرێت');
            }
            $res = $caseInvoice->editApproved($invoiceId, ['discount_mode' => $mode, 'discount_value' => $value],
                (int)$currentUser['id'], !empty($doctorCanEditSent));
        } else {
            $res = $caseInvoice->setDiscount($caseId, $invoiceId, $mode, $value, (int)$currentUser['id']);
        }
        if (empty($res['success'])) {
            case_view_fail($caseId, $res['message'] ?? 'داشکاندن سەرنەکەوت');
        }
        if (($_POST['ajax'] ?? '') === '1') {
            case_view_ajax_success($caseManager, $caseId, ['invoice_id' => $invoiceId, 'discounted' => 1]);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId . '&discount_applied=1');
        exit;

    } elseif ($action === 'set_case_historical') {
        // Mark (or unmark) a case as کۆن — work done before this system. Only
        // while no receipt of it has reached the accountant: after that the
        // money on it is accounting's document.
        $makeHistorical = ($_POST['is_historical'] ?? '0') === '1';
        $historicalDate = trim((string)($_POST['historical_date'] ?? ''));
        if ($makeHistorical) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historicalDate) || strtotime($historicalDate) === false) {
                case_view_fail($caseId, 'بەرواری کەیسی کۆن دیاری بکە');
            }
            if ($historicalDate > date('Y-m-d')) {
                case_view_fail($caseId, 'بەرواری کەیسی کۆن ناتوانێت لە داهاتوودا بێت');
            }
        }
        $lockedInvoice = $db->fetch(
            "SELECT id FROM case_invoices WHERE case_id = :cid AND accounting_status NOT IN ('draft','returned','cancelled') LIMIT 1",
            ['cid' => $caseId]
        );
        if ($lockedInvoice) {
            case_view_fail($caseId, 'وەسڵی ئەم کەیسە نێردراوە بۆ ژمێریار — ناتوانرێت جۆری کەیس بگۆڕدرێت');
        }
        $caseChanges = [
            'is_historical'   => $makeHistorical ? 1 : 0,
            'historical_date' => $makeHistorical ? $historicalDate : null,
        ];
        $db->beginTransaction();
        try {
            $db->update('cases', $caseChanges, 'id = :id', ['id' => $caseId]);
            if ($makeHistorical) {
                // The open receipts are the old work: date them on the old day
                // so they stay out of today's production figures.
                $db->query(
                    "UPDATE case_invoices SET work_date = :d WHERE case_id = :cid AND accounting_status IN ('draft','returned')",
                    ['d' => $historicalDate, 'cid' => $caseId]
                );
                $db->query(
                    "UPDATE case_treatments ct JOIN case_invoices ci ON ci.id = ct.invoice_id
                        SET ct.performed_date = CONCAT(:d, ' ', TIME(COALESCE(ct.performed_date, ct.created_at)))
                      WHERE ci.case_id = :cid AND ci.accounting_status IN ('draft','returned')",
                    ['d' => $historicalDate, 'cid' => $caseId]
                );
            } else {
                // A case that is no longer historical cannot keep pre-system money.
                $db->query(
                    "UPDATE case_invoices SET prior_paid_amount = 0 WHERE case_id = :cid AND accounting_status IN ('draft','returned')",
                    ['cid' => $caseId]
                );
            }
            AuditLog::logChange('case_historical_changed', 'cases', $caseId, $case, $caseChanges, (int)$currentUser['id']);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            case_view_fail($caseId, $e->getMessage());
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments');
        exit;

    } elseif ($action === 'set_invoice_prior_paid') {
        // دراوی پێشوو typed into a historical case's receipt. Saved on the draft
        // only (CaseInvoice::setPriorPaid) — no payment row, no cash report.
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $target = $invoiceId ? $caseInvoice->getById($invoiceId) : null;
        if (!$target || (int)$target['case_id'] !== $caseId) {
            case_view_fail($caseId, 'وەسڵ نەدۆزرایەوە');
        }
        if (empty($case['is_historical'])) {
            case_view_fail($caseId, 'دراوی پێشوو تەنها بۆ کەیسی کۆن تۆمار دەکرێت');
        }
        $caseInvoice->recomputeTotal($invoiceId);
        $amount = max(0, (float)normalize_localized_digits($_POST['prior_paid_amount'] ?? 0));
        $saved = $caseInvoice->setPriorPaid($caseId, $invoiceId, $amount, (int)$currentUser['id']);
        if (empty($saved['success'])) {
            case_view_fail($caseId, $saved['message'] ?? 'خەزنکردنی دراوی پێشوو سەرنەکەوت');
        }
        try { $caseManager->updateCaseTotals($caseId); } catch (Exception $e) { /* cached totals only */ }
        if (($_POST['ajax'] ?? '') === '1') {
            case_view_ajax_success($caseManager, $caseId, [
                'invoice_id' => $invoiceId,
                'prior_paid_saved' => true,
            ]);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId);
        exit;

    } elseif ($action === 'use_invoice_advance' || $action === 'clear_invoice_advance') {
        if (!in_array($currentUser['role'], ['admin','accountant'], true)) {
            case_view_fail($caseId, 'بەکارهێنانی ئامانەت تەنها بۆ ژمێریار و ئەدمینە');
        }
        // ئامانەت — money reception already took from this patient at the
        // counter, before this case existed. The doctor decides which invoice
        // it settles; no money moves here, so nothing is written to any cash
        // report (PatientAdvance::allocate). Works on any case, historical or
        // new — the patient paid at this counter either way.
        require_once __DIR__ . '/../classes/PatientAdvance.php';
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $target = $invoiceId ? $caseInvoice->getById($invoiceId) : null;
        if (!$target || (int)$target['case_id'] !== $caseId) {
            case_view_fail($caseId, 'وەسڵ نەدۆزرایەوە');
        }
        $advanceManager = new PatientAdvance();
        if ($action === 'clear_invoice_advance') {
            $saved = $advanceManager->reverseForInvoice($invoiceId, (int)$currentUser['id']);
        } else {
            $caseInvoice->recomputeTotal($invoiceId);
            $amount = max(0, (float)normalize_localized_digits($_POST['advance_amount'] ?? 0));
            $saved = $advanceManager->allocate($invoiceId, $amount, (int)$currentUser['id']);
        }
        if (empty($saved['success'])) {
            case_view_fail($caseId, $saved['message'] ?? 'بەکارهێنانی ئامانەت سەرنەکەوت');
        }
        try { $caseManager->updateCaseTotals($caseId); } catch (Exception $e) { /* cached totals only */ }
        if (($_POST['ajax'] ?? '') === '1') {
            case_view_ajax_success($caseManager, $caseId, [
                'invoice_id' => $invoiceId,
                'advance_saved' => true,
            ]);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId);
        exit;

    } elseif ($action === 'set_invoice_paid') {
        // وەرگیراو typed straight into the receipt's totals block.
        //
        // The number is what that box must READ afterwards, not the payment
        // amount: money pours over a case's receipts oldest-first, so the sum
        // behind it is worked out by CaseInvoice::planPaidTarget().
        //
        //   doctor  → files a REQUEST (Payment::createRequest) — a still-draft
        //             target is sent to the accountant first, in the same
        //             commit; nothing moves on the case until an accountant
        //             approves it. Page access already guarantees this is the
        //             doctor's own case (see the top-of-file redirect).
        //   admin/accountant → books it outright (Payment::directPayment),
        //             same engine the وەرگرتنی پارە dialog uses.
        //
        // Nothing commits until confirm=1: the first POST returns the plan so
        // the client can show what will actually happen.
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $target = $invoiceId ? $caseInvoice->getById($invoiceId) : null;
        if (!$target || (int)$target['case_id'] !== $caseId) {
            case_view_fail($caseId, 'وەسڵ نەدۆزرایەوە');
        }
        if ($case['status'] !== 'open') {
            case_view_fail($caseId, 'ئەم کەیسە داخراوە');
        }
        $isDraftTarget = $target['status'] === 'draft'; // covers 'returned' too — see CaseInvoice::returnForCorrection
        $isSentTarget = ($target['accounting_status'] ?? '') === 'sent';
        // Once a treatment invoice leaves draft state it is immutable for the
        // doctor. Any later collection is a new payment-receipt draft.
        if (!$canReceiveMoney && !$isDraftTarget) {
            case_view_fail($caseId, 'بۆ پارەدانی دواتر قەبزی پارەدانی ڕەشنووس دروست بکە؛ وەسڵی چارەسەر ناگۆڕدرێت');
        }

        // Sync a draft's total with its rows BEFORE planning. send() does this
        // too, and a plan built on a stale total would book a figure the user
        // never saw. A sent receipt's total is the accountant's document — it
        // is never rewritten here.
        if ($isDraftTarget) {
            $caseInvoice->recomputeTotal($invoiceId);
            $target = $caseInvoice->getById($invoiceId);
        }

        $typed = floatval(normalize_localized_digits($_POST['paid_amount'] ?? 0));

        // Draft means draft: save only on case_invoices. Do not create a
        // payments row, allocation, notification, or accountant-queue item.
        // The send_invoice action below is the sole promotion point.
        if ($isDraftTarget) {
            $net = max(0, (float)($target['net_amount'] ?? ((float)$target['total_amount'] - (float)$target['discount_amount'])));
            $approvedOnInvoice = max(0, (float)($target['paid_amount'] ?? 0));
            // دراوی پێشوو (historical cases) already covers part of the net.
            $typed = min(max(0, $typed), max(0, $net - (float)($target['prior_paid_amount'] ?? 0)));
            if ($typed < $approvedOnInvoice - 0.009) {
                case_view_fail($caseId, 'بڕی وەرگیراو ناتوانێت لە پارەی پەسەندکراو کەمتر بێت');
            }
            $saved = $caseInvoice->setDraftReceived(
                $caseId, $invoiceId, max(0, $typed - $approvedOnInvoice), (int)$currentUser['id']
            );
            if (empty($saved['success'])) {
                case_view_fail($caseId, $saved['message'] ?? 'خەزنکردنی وەرگیراوی درافت سەرنەکەوت');
            }
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId, [
                    'invoice_id' => $invoiceId,
                    'draft_received_saved' => true,
                    'redirect_flag' => 'draft_received_saved',
                ]);
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId . '&draft_received_saved=1');
            exit;
        }

        // Once the invoice is already sent, a doctor still files a request;
        // money roles can book it directly.
        $isRequest = !$canReceiveMoney;

        if ($isRequest) {
            // For a doctor the box means approved + still-pending received
            // money. Drafts stay drafts: entering a figure creates/updates the
            // payment request only and never sends or locks the invoice.
            $net = max(0, (float)($target['net_amount'] ?? ((float)$target['total_amount'] - (float)$target['discount_amount'])));
            $approvedOnInvoice = max(0, (float)($target['paid_amount'] ?? 0));
            $typed = min(max(0, $typed), $net);
            if ($typed < $approvedOnInvoice - 0.009) {
                case_view_fail($caseId, 'بۆ کەمکردنەوەی پارەی پەسەندکراو پێویستە ژمێریار گەڕاندنەوە تۆمار بکات');
            }
            $desiredPending = round(max(0, $typed - $approvedOnInvoice), 2);
            $plan = [
                'success' => true,
                'invoice_no' => $target['invoice_no'],
                'was_draft' => $isDraftTarget,
                'net' => $net,
                'target' => $typed,
                'amount' => $desiredPending,
                'prior' => [],
                'prior_total' => 0,
            ];
        } else {
            $plan = $caseInvoice->planPaidTarget($caseId, $invoiceId, $typed);
            if (empty($plan['success'])) {
                case_view_fail($caseId, $plan['message'] ?? 'سەرنەکەوت');
            }
        }

        if (($_POST['confirm'] ?? '') !== '1') {
            // Dry run — show the client exactly what committing would do.
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'plan' => [
                    'invoice_no'    => $plan['invoice_no'],
                    'target'        => $plan['target'],
                    'amount'        => $plan['amount'],
                    'prior'         => $plan['prior'],
                    'prior_total'   => $plan['prior_total'],
                    'sends_invoice' => $plan['was_draft'] && !$isRequest,
                    'is_request'    => $isRequest,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $noteLine = 'وەسڵی ' . $plan['invoice_no'];

        // Money roles still send a draft before booking real money. A doctor's
        // entry is only a pending declaration, so their invoice stays editable.
        if ($plan['was_draft'] && !$isRequest) {
            $sendRes = $caseInvoice->send($caseId, $invoiceId, $currentUser['id'], '');
            if (empty($sendRes['success'])) {
                case_view_fail($caseId, $sendRes['message'] ?? 'ناردنی وەسڵ سەرنەکەوت');
            }
        }

        // 'created' | 'unchanged' | 'updated' — which of the three doctor-
        // request outcomes this POST landed on, only meaningful when
        // $isRequest; picks the flash message below (see $successFlags).
        $requestOutcome = null;

        if ($isRequest) {
            // Single ADJUSTABLE pending request per doctor per CASE, not per
            // invoice: find_doctor_pending_request() is now case-scoped (see
            // its docblock — review fix, 2026-08-26), so it finds the exact
            // same row the case-level داواکاری پارەدان dialog
            // (request_payment handler) would create or adjust. A
            // multi-invoice case (the normal shape: one invoice per work
            // day) means that shared row can already carry allocations on
            // OTHER invoices — so typing a target on THIS invoice must never
            // simply overwrite the row's total with $plan['amount']
            // (planPaidTarget()'s delta against this invoice's APPROVED
            // coverage only); that would silently erase money already
            // requested against every other invoice the row covers.
            // Instead: turn $plan['amount'] into a DELTA against what the
            // row already has pending on THIS invoice specifically
            // (pending_request_invoice_allocation()), then apply that delta
            // to the row's case-wide total. updatePendingAmount() re-runs
            // FIFO across the whole case for the new total — its own
            // capacity validation is what actually governs success.
            $pendingReq = find_doctor_pending_request($db, $caseId, $invoiceId, (int)$currentUser['id']);
            if (!$pendingReq && $plan['amount'] > 0.009) {
                $res = $paymentManager->createRequest([
                    'case_id'          => $caseId,
                    'invoice_id'       => $invoiceId,
                    'payment_type'     => 'cash',
                    'amount'           => $plan['amount'],
                    'payment_method'   => 'cash',
                    'notes'            => $noteLine . ' — وەرگیراو ' . number_format($plan['target']),
                    'received_by'      => $currentUser['id'],
                    'allow_draft'      => $isDraftTarget,
                    // A doctor's double-click/network-retry/two-tabs must land
                    // ONE pending request, not two — defense in depth behind
                    // the find_doctor_pending_request() check above; see
                    // doctor_request_idempotency_key().
                    'idempotency_key'  => doctor_request_idempotency_key($db, $invoiceId, $plan['amount']),
                ]);
                $requestOutcome = 'created';
            } elseif ($pendingReq) {
                $pendingOnThisInvoice = pending_request_invoice_allocation($db, $pendingReq['id'], $invoiceId);
                if (abs((float)$plan['amount'] - $pendingOnThisInvoice) <= 0.009) {
                    // Identical resubmit of the same still-undecided request
                    // (double-click, retry, a stale tab), or a target that —
                    // once floored — nets out to no real change: nothing to
                    // change; say so plainly rather than falsely claiming a
                    // fresh send.
                    $res = ['success' => true, 'payment_id' => $pendingReq['id']];
                    $requestOutcome = 'unchanged';
                } elseif ($plan['amount'] <= 0.009) {
                    try {
                        $paymentManager->removePendingInvoiceAllocation(
                            $pendingReq['id'], $invoiceId, (int)$currentUser['id'],
                            'لابردنی بڕی وەرگیراو لە وەسڵی ' . $plan['invoice_no']
                        );
                        $res = ['success' => true, 'payment_id' => $pendingReq['id']];
                        $requestOutcome = 'updated';
                    } catch (Exception $e) {
                        case_view_fail($caseId, $e->getMessage());
                    }
                } else {
                    $allocations = [];
                    foreach ($db->fetchAll(
                        'SELECT invoice_id, allocated_amount FROM payment_allocations WHERE payment_id = :payment',
                        ['payment' => (int)$pendingReq['id']]
                    ) as $allocation) {
                        $allocations[(int)$allocation['invoice_id']] = (float)$allocation['allocated_amount'];
                    }
                    $allocations[$invoiceId] = (float)$plan['amount'];
                    try {
                        $paymentManager->updatePendingAllocations(
                            $pendingReq['id'], $allocations, (int)$currentUser['id'],
                            'گۆڕینی بڕی وەرگیراوی وەسڵی ' . $plan['invoice_no'], true
                        );
                        $res = ['success' => true, 'payment_id' => $pendingReq['id']];
                        $requestOutcome = 'updated';
                    } catch (Exception $e) {
                        case_view_fail($caseId, $e->getMessage());
                    }
                }
            } else {
                $res = ['success' => true, 'payment_id' => 0];
                $requestOutcome = 'unchanged';
            }
        } else {
            $res = $paymentManager->directPayment([
                'case_id'        => $caseId,
                'invoice_id'     => $invoiceId,
                'payment_type'   => 'cash',
                'amount'         => $plan['amount'],
                'payment_method' => 'cash',
                'notes'          => $noteLine . ' — وەرگیراو ' . number_format($plan['target']),
            ], $currentUser['id']);
        }
        if (empty($res['success'])) {
            // createRequest()'s anti-stacking refusal is the useful message
            // here — surface it rather than a generic failure.
            case_view_fail($caseId, $res['message'] ?? 'تۆمارکردنی پارە سەرنەکەوت');
        }
        // No manual report/notification here — createRequest()/directPayment()
        // already self-notify (notifyPaymentRequested / notifyPaymentReceived).
        // updatePendingAmount() doesn't notify on its own — it's an amount
        // edit on a request accounting already knows about, not a new one.

        // Money may have just covered receipts — flip them and notify senders.
        try { $caseInvoice->syncStatuses($caseId, (int)$currentUser['id']); } catch (Exception $e) { /* never block the payment */ }

        // The patient's copy — the client reloads onto it so a قەبز can be
        // printed at the chair, which is the whole point of taking the money there.
        $voucherId = (int)($res['payment_id'] ?? 0);
        $redirectFlag = !$isRequest ? 'payment_added'
            : ($requestOutcome === 'unchanged' ? 'payment_request_unchanged'
                : ($requestOutcome === 'updated' ? 'payment_request_updated' : 'payment_requested'));
        if (($_POST['ajax'] ?? '') === '1') {
            case_view_ajax_success($caseManager, $caseId, [
                'invoice_id' => $invoiceId,
                'voucher_id' => $isRequest ? 0 : $voucherId,
                'payment_id' => $voucherId,
                'payment_request' => $isRequest,
                'redirect_flag' => $redirectFlag,
            ]);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId
            . ($voucherId ? '&voucher=' . $voucherId : '')
            . '&' . $redirectFlag . '=1');
        exit;

    } elseif ($action === 'delete_invoice' || $action === 'delete_invoice_tab') {
        // Authorize on the server as well as hiding the controls from other roles.
        if (!$isAdmin) {
            case_view_fail($caseId, 'تەنها بەڕێوەبەر دەتوانێت پسووڵە بسڕێتەوە');
        }
        $res = (new InvoiceDeletion())->delete((int)$caseId, intval($_POST['invoice_id'] ?? 0), (string)($_POST['deletion_reason'] ?? ''));
        if (empty($res['success'])) {
            case_view_fail($caseId, $res['message'] ?? 'ناتوانرێت بسڕدرێتەوە');
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&invoice_deleted=1');
        exit;

    } elseif ($action === 'recall_invoice') {
        // The doctor takes back a receipt the accountant has not approved yet,
        // to correct it and send it again. CaseInvoice::recall() refuses once
        // accounting has acted on it.
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $res = $caseInvoice->recall($caseId, $invoiceId, (int)$currentUser['id']);
        if (empty($res['success'])) {
            case_view_fail($caseId, $res['message'] ?? 'کانسڵکردنەوە سەرنەکەوت');
        }
        try {
            require_once __DIR__ . '/../classes/Notification.php';
            (new Notification())->notifyInvoiceRecalled($caseId, $res['invoice']['invoice_no'], (int)$currentUser['id']);
        } catch (Exception $e) {
            error_log('recall_invoice notify failed: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . $invoiceId . '&invoice_recalled=1');
        exit;

    } elseif ($action === 'send_invoice') {
        // Hand ONE visit tab to the accountant — the rest of the case stays
        // the doctor's to keep working on. The doctor also declares how they
        // expect this receipt to be settled (نەقد/قەرز/قیست) — a hint stored
        // on the invoice for accounting, not money; see CaseInvoice::send().
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $note = trim($_POST['notes'] ?? '');
        $intent = read_invoice_intent_from_post();
        $invoiceBefore = $caseInvoice->getById($invoiceId);
        if (!$invoiceBefore || (int)$invoiceBefore['case_id'] !== $caseId) {
            case_view_fail($caseId, 'وەسڵ نەدۆزرایەوە', 'sendInvoiceDialog');
        }
        if (!in_array(($invoiceBefore['accounting_status'] ?? ''), ['draft', 'returned'], true)) {
            case_view_fail($caseId, 'ئەم وەسڵە پێشتر نێردراوە بۆ ژمێریار', 'sendInvoiceDialog');
        }
        $caseInvoice->recomputeTotal($invoiceId);
        $invoiceBefore = $caseInvoice->getById($invoiceId);

        // The send dialog owns the last doctor-side discount edit. Preview and
        // validate it before saving so a discount can never push the receipt
        // below money that is already approved or being declared as received.
        $discountMode = ($_POST['discount_mode'] ?? ($invoiceBefore['discount_mode'] ?? 'amount')) === 'percent'
            ? 'percent' : 'amount';
        $discountValue = max(0, (float)normalize_localized_digits(
            $_POST['discount_value'] ?? ($invoiceBefore['discount_value'] ?? $invoiceBefore['discount_amount'] ?? 0)
        ));
        $grossBefore = max(0, (float)$invoiceBefore['total_amount']);
        if (($discountMode === 'percent' && $discountValue > 100.009)
            || ($discountMode === 'amount' && $discountValue > $grossBefore + 0.009)) {
            case_view_fail($caseId, 'داشکاندن لە کۆی وەسڵ زیاترە', 'sendInvoiceDialog');
        }
        $discountPreview = $discountMode === 'percent'
            ? round($grossBefore * $discountValue / 100, 2)
            : round($discountValue, 2);
        $previewNet = max(0, $grossBefore - $discountPreview);
        $approvedBefore = max(0, (float)($invoiceBefore['paid_amount'] ?? 0));
        $pendingBefore = max(0, (float)($invoiceBefore['pending_amount'] ?? 0));
        $enteredReceived = array_key_exists('received_amount', $_POST)
            ? max(0, (float)normalize_localized_digits($_POST['received_amount']))
            : $approvedBefore + $pendingBefore;
        // "قەرز" now means "money is still owed on this invoice", NOT "nothing
        // was paid". The old reading is what produced the reported problem:
        // picking قەرز silently zeroed the received figure, and it was refused
        // outright once any money had been taken. A doctor who collected part
        // of the fee therefore could not report it truthfully — so they either
        // chose نەقد (leaving accounting an invoice that claims cash with a
        // balance) or chose قەرز and lost the amount they had entered, then
        // wrote "بەشێک دراوە" in the note instead.
        //
        // Partial payment plus a remaining balance is now the normal case:
        // the amount is kept, and CaseInvoice::deriveIntent() settles the flag
        // from the money.
        if ($enteredReceived + 0.009 < $approvedBefore) {
            case_view_fail($caseId, 'بڕی وەرگیراو ناتوانێت لە پارەی پەسەندکراو کەمتر بێت', 'sendInvoiceDialog');
        }
        // دراوی پێشوو (historical cases) is part of what already covers the net.
        $priorBefore = !empty($case['is_historical']) ? max(0, (float)($invoiceBefore['prior_paid_amount'] ?? 0)) : 0.0;
        if ($enteredReceived + $priorBefore > $previewNet + 0.009) {
            case_view_fail($caseId, $priorBefore > 0.009
                ? 'بڕی وەرگیراو و دراوی پێشوو پێکەوە لە دوای داشکاندنی وەسڵ زیاترن'
                : 'بڕی وەرگیراو لە دوای داشکاندنی وەسڵ زیاترە', 'sendInvoiceDialog');
        }
        $discountResult = $caseInvoice->setDiscount(
            $caseId, $invoiceId, $discountMode, $discountValue, (int)$currentUser['id']
        );
        if (empty($discountResult['success'])) {
            case_view_fail($caseId, $discountResult['message'] ?? 'داشکاندن سەرنەکەوت', 'sendInvoiceDialog');
        }
        $invoiceBefore = $caseInvoice->getById($invoiceId);
        $invoiceNet = max(0, (float)$invoiceBefore['total_amount'] - (float)$invoiceBefore['discount_amount']);

        // "قەرز" carries what is STILL OWED, not the whole invoice. With a
        // partial payment of 30,000 against 100,000 the promise is 70,000;
        // recording the full 100,000 here would overstate the debt on every
        // partially settled invoice.
        if (($intent['payment_intent'] ?? null) === 'debt') {
            $intent['promised_amount'] = max(0, $invoiceNet - $enteredReceived - $priorBefore);
        }

        // The chair-side amount is a field of THIS treatment invoice. Sending
        // the invoice must never create a row in payments or in its approval
        // queue; accounting reviews gross, discount and received together.
        $receivedResult = $caseInvoice->setDraftReceived(
            $caseId, $invoiceId, $enteredReceived, (int)$currentUser['id']
        );
        if (empty($receivedResult['success'])) {
            case_view_fail($caseId, $receivedResult['message'] ?? 'خەزنکردنی بڕی وەرگیراو سەرنەکەوت', 'sendInvoiceDialog');
        }

        $installmentData = null;
        $existingInstallmentPlanId = 0;
        if (($intent['payment_intent'] ?? null) === 'installment') {
            $blockingPlan = $db->fetch(
                "SELECT id, status FROM installment_plans WHERE case_id = :case_id AND status IN ('active','pending') LIMIT 1",
                ['case_id' => $caseId]
            );
            if ($blockingPlan) {
                // Reuse the case's active/pending plan; never create an
                // ambiguous second schedule. The invoice still carries the
                // installment intent so accounting sees the relationship.
                $existingInstallmentPlanId = (int)$blockingPlan['id'];
            } else {
                $installmentCount = max(2, min(60, intval(normalize_localized_digits($_POST['installment_count'] ?? 0))));
                $installmentStart = trim((string)($_POST['installment_start_date'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $installmentStart)) {
                    case_view_fail($caseId, 'بەرواری دەستپێکی قیست دیاری بکە', 'sendInvoiceDialog');
                }
                $planTotal = $invoiceNet;
                $planDown = min($planTotal, $enteredReceived + $priorBefore);
                if ($planTotal - $planDown <= 0.009) {
                    case_view_fail($caseId, 'هەموو بڕەکە وەک وەرگیراو تۆمارکراوە؛ نەقد هەڵبژێرە', 'sendInvoiceDialog');
                }
                $installmentData = [
                    'case_id' => $caseId,
                    'invoice_id' => $invoiceId,
                    'total_amount' => $planTotal,
                    'down_payment' => $planDown,
                    'number_of_installments' => $installmentCount,
                    'start_date' => $installmentStart,
                    'notes' => trim($note . ($planDown > 0 ? "\nپێشەکی لە خانەی وەرگیراو: " . number_format($planDown) : '')),
                    'created_by' => (int)$currentUser['id'],
                ];
            }
        }
        try {
            $res = $caseInvoice->send($caseId, $invoiceId, $currentUser['id'], $note, $intent);
        } catch (InvalidArgumentException $e) {
            case_view_fail($caseId, $e->getMessage());
        }
        if (empty($res['success'])) {
            case_view_fail($caseId, $res['message'] ?? 'ناردن سەرنەکەوت');
        }
        $inv = $res['invoice'];

        $planId = $existingInstallmentPlanId;
        if ($installmentData) {
            try {
                if ($canReceiveMoney) {
                    $planResult = $installmentManager->createPlan($installmentData);
                    if (empty($planResult['success'])) {
                        throw new RuntimeException($planResult['message'] ?? 'دروستکردنی پلانی قیست سەرنەکەوت');
                    }
                    $planId = (int)($planResult['plan_id'] ?? 0);
                } else {
                    $planId = $installmentManager->proposePlan($installmentData, (int)$currentUser['id']);
                }
            } catch (Exception $e) {
                case_view_fail($caseId, $e->getMessage(), 'sendInvoiceDialog');
            }
        }

        require_once __DIR__ . '/../classes/Notification.php';
        (new Notification())->notifyInvoiceSent($caseId, $inv['invoice_no'], (int)$currentUser['id']);

        if (($_POST['ajax'] ?? '') === '1') {
            case_view_ajax_success($caseManager, $caseId, [
                'invoice_id' => (int)$inv['id'],
                'invoice_sent' => true,
                'payment_intent' => $intent['payment_intent'] ?? null,
                'installment_plan_id' => $planId,
                'installment_plan_created' => $installmentData !== null,
            ]);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=treatments&inv=' . (int)$inv['id'] . '&invoice_sent=1');
        exit;

    } elseif ($action === 'set_dentition') {
        // Legacy no-op. The chart used to be adult OR child and this switched
        // between them; both sets are drawn together now, so nothing is stored
        // and nothing is refused. Kept only so a POST from a stale tab does not
        // fall through as an unknown action.
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId);
        exit;
    } elseif ($action === 'delete_treatment') {
        $treatmentId = intval($_POST['treatment_id']);
        $ownerInvoice = (int)($db->fetch(
            'SELECT invoice_id FROM case_treatments WHERE case_id = :cid AND id = :tid LIMIT 1',
            ['cid' => $caseId, 'tid' => $treatmentId]
        )['invoice_id'] ?? 0);
        $billedInvoices = guard_billed_treatments($db, $caseId, [$treatmentId], $isAdmin);
        $result = $caseManager->deleteTreatment($caseId, $treatmentId);
        if ($result['success']) {
            finish_case_treatment_edit($billedInvoices, $caseId);
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId, ['invoice_id' => $ownerInvoice]);
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatment_deleted=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'delete_grouped_treatments') {
        // Delete multiple treatments at once — all-or-nothing
        $treatmentIds = $_POST['treatment_ids'] ?? [];
        if (!is_array($treatmentIds) || empty($treatmentIds)) {
            case_view_fail($caseId, 'هیچ چارەسەرێک هەڵنەبژێردراوە');
        }
        $billedInvoices = guard_billed_treatments($db, $caseId, $treatmentIds, $isAdmin);
        // Which visit tab these rows sat on — so the client can refresh just it.
        $idList = implode(',', array_map('intval', array_filter(array_map('intval', $treatmentIds))));
        $ownerInvoice = $idList !== ''
            ? (int)($db->fetch("SELECT invoice_id FROM case_treatments WHERE case_id = :cid AND id IN ({$idList}) LIMIT 1", ['cid' => $caseId])['invoice_id'] ?? 0)
            : 0;
        $db->beginTransaction();
        $successCount = 0;
        $failMessage = '';
        foreach ($treatmentIds as $tid) {
            $result = $caseManager->deleteTreatment($caseId, intval($tid));
            if ($result['success']) {
                $successCount++;
            } else {
                $failMessage = $result['message'] ?? '';
                break;
            }
        }
        if ($successCount === count($treatmentIds)) {
            $db->commit();
            finish_case_treatment_edit($billedInvoices, $caseId);
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId, ['deleted' => $successCount, 'invoice_id' => $ownerInvoice]);
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatments_deleted=' . $successCount);
            exit;
        }
        $db->rollback();
        case_view_fail($caseId, $failMessage);
    } elseif ($action === 'update_treatment') {
        $treatmentId = intval($_POST['treatment_id']);
        $data = [
            'treatment_name' => trim($_POST['treatment_name']),
            'quantity' => max(1, intval($_POST['quantity']) ?: 1),
            'unit_price' => max(0, floatval($_POST['unit_price'])),
            'discount' => max(0, floatval($_POST['discount'] ?? 0)),
            'notes' => trim($_POST['notes'] ?? '')
        ];
        if ($data['treatment_name'] === '') {
            case_view_fail($caseId, 'ناوی چارەسەر پێویستە', 'editTreatmentDialog');
        }
        $data['total_price'] = $data['unit_price'] * $data['quantity'];
        if ($data['discount'] > $data['total_price']) {
            case_view_fail($caseId, 'داشکاندن ناتوانێت لە کۆی نرخ زیاتر بێت', 'editTreatmentDialog');
        }
        $data['final_price'] = $data['total_price'] - $data['discount'];
        $billedInvoices = guard_billed_treatments($db, $caseId, [$treatmentId], $isAdmin, 'editTreatmentDialog');
        $result = $caseManager->updateTreatment($caseId, $treatmentId, $data);
        if ($result['success']) {
            finish_case_treatment_edit($billedInvoices, $caseId);
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatment_updated=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'update_grouped_treatment') {
        // Replace one visible grouped row atomically. Tooth-based work keeps
        // its tooth list; a service/arch row remains a single toothless job.
        $oldTreatmentIds = $_POST['old_treatment_ids'] ?? [];
        $catalogTreatmentId = intval($_POST['treatment_id']) ?: null;
        $treatmentName = trim($_POST['treatment_name']);
        $newQuantity = max(1, intval($_POST['new_quantity']) ?: 1);
        $newUnitPrice = max(0, floatval($_POST['new_unit_price']) ?: 0);
        $newToothNumbers = json_decode($_POST['new_tooth_numbers'] ?? '[]', true);

        if ($treatmentName === '') {
            case_view_fail($caseId, 'ناوی چارەسەر پێویستە');
        }
        if (!is_array($oldTreatmentIds) || empty($oldTreatmentIds)) {
            case_view_fail($caseId, 'هیچ چارەسەرێک هەڵنەبژێردراوە');
        }
        $cleanOldIds = array_values(array_unique(array_filter(array_map('intval', $oldTreatmentIds))));
        $idList = implode(',', $cleanOldIds);
        $original = $idList !== '' ? $db->fetch(
            "SELECT invoice_id, arch_type, notes, performed_date
             FROM case_treatments WHERE case_id = :cid AND id IN ({$idList}) ORDER BY id LIMIT 1",
            ['cid' => $caseId]
        ) : null;
        if (!$original) {
            case_view_fail($caseId, 'چارەسەرەکە نەدۆزرایەوە');
        }
        $archType = in_array(($original['arch_type'] ?? 'none'), ['upper', 'lower'], true)
            ? $original['arch_type'] : 'none';
        // Teeth are optional for every treatment: picked teeth → one row per
        // tooth, no teeth → one job row without a tooth number.
        $requiresTooth = $archType === 'none'
            && is_array($newToothNumbers) && !empty($newToothNumbers);

        if ($requiresTooth) {
            if (count($newToothNumbers) > MAX_BULK_TEETH) {
                case_view_fail($caseId, 'تکایە لانیکەم ددانێک هەڵبژێرە');
            }
            $newToothNumbers = array_values(array_unique(array_map('intval', $newToothNumbers)));
            foreach ($newToothNumbers as $toothNumber) {
                if (!is_valid_fdi_tooth($toothNumber)) {
                    case_view_fail($caseId, 'ژمارەی ددان نادروستە');
                }
            }
            if ($newQuantity < count($newToothNumbers)) {
                case_view_fail($caseId, 'ژمارە نابێت لە ژمارەی ددانە هەڵبژێردراوەکان کەمتر بێت');
            }
        } else {
            $newToothNumbers = [];
        }
        $billedInvoices = guard_billed_treatments($db, $caseId, $cleanOldIds, $isAdmin);

        $treatmentData = [
            'treatment_id' => $catalogTreatmentId,
            'treatment_name' => $treatmentName,
            'unit_price' => $newUnitPrice,
            'discount' => 0,
            'notes' => $original['notes'] ?? '',
            'performed_by' => $currentUser['id'],
            'invoice_id' => $original['invoice_id'] ?? null,
            'performed_date' => $original['performed_date'] ?? null,
            'arch_type' => $archType,
        ];

        $db->beginTransaction();
        $failMessage = '';
        $ok = true;

        foreach ($cleanOldIds as $tid) {
            $result = $caseManager->deleteTreatment($caseId, intval($tid));
            if (!$result['success']) {
                $failMessage = $result['message'] ?? '';
                $ok = false;
                break;
            }
        }

        $createCount = 0;
        if ($ok) {
            if ($requiresTooth) {
                $rowQuantities = CaseManager::splitBulkQuantity($newQuantity, count($newToothNumbers));
                $createRows = [];
                foreach ($newToothNumbers as $i => $toothNumber) {
                    $createRows[] = ['tooth_number' => $toothNumber, 'quantity' => $rowQuantities[$i]];
                }
            } else {
                $createRows = [[
                    'tooth_number' => null,
                    'tooth_surface' => null,
                    'quantity' => $newQuantity,
                ]];
            }
            foreach ($createRows as $createRow) {
                $data = array_merge($treatmentData, $createRow);
                $result = $caseManager->addTreatment($caseId, $data);
                if ($result['success']) {
                    $createCount++;
                } else {
                    $failMessage = $result['message'] ?? '';
                    $ok = false;
                    break;
                }
            }
        }

        if ($ok && $createCount > 0) {
            $db->commit();
            finish_case_treatment_edit($billedInvoices, $caseId);
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatments_updated=' . $createCount . '&work_added=1');
            exit;
        }
        $db->rollback();
        case_view_fail($caseId, $failMessage);
    } elseif ($action === 'add_procedure_log') {
        // Add procedure log for arch case
        $logData = [
            'case_id' => $caseId,
            'procedure_type_id' => !empty($_POST['procedure_type_id']) ? intval($_POST['procedure_type_id']) : null,
            'custom_procedure_name' => trim($_POST['custom_procedure_name'] ?? ''),
            'arch_affected' => $_POST['arch_affected'] ?? 'both',
            'procedure_date' => $_POST['procedure_date'],
            'procedure_time' => $_POST['procedure_time'] ?? null,
            'notes' => trim($_POST['notes'] ?? ''),
            'price' => floatval($_POST['price'] ?? 0),
            'next_appointment_date' => !empty($_POST['next_appointment_date']) ? $_POST['next_appointment_date'] : null,
            'next_appointment_time' => !empty($_POST['next_appointment_time']) ? $_POST['next_appointment_time'] : null,
            'next_appointment_duration' => intval($_POST['next_appointment_duration'] ?? 30) ?: 30,
            'next_appointment_notes' => trim($_POST['next_appointment_notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];
        $result = $archProcedureLog->addLog($logData);
        if ($result['success']) {
            $appointmentResult = create_procedure_next_appointment($appointmentManager, $case, $caseId, $currentUser, $logData);
            // An arch visit carries no new charge — the job was priced once when
            // the case was opened. So the send raises a وەسڵی کارە ئەنجامدراوەکان:
            // a priced-nothing document listing the work, which the accountant
            // opens in the approval center and settles against the patient's
            // existing debt. The bell below still fires, as it always did.
            require_once __DIR__ . '/../classes/ArchWorkReceipt.php';
            $workReceipt = (new ArchWorkReceipt())->sendUnsent(
                $caseId,
                (int)$currentUser['id'],
                trim($_POST['notes'] ?? '')
            );
            $accountingNotes = build_procedure_log_accounting_notes($archProcedureLog, $logData);
            if (!empty($workReceipt['success'])) {
                $accountingNotes .= "\nوەسڵی کارەکان: " . $workReceipt['receipt_no'];
            }
            if (!empty($appointmentResult['created'])) {
                $accountingNotes .= "\nمەوعید زیادکرا: " . $logData['next_appointment_date'] . ' ' . $logData['next_appointment_time'];
            } elseif (!empty($logData['next_appointment_date']) && !empty($logData['next_appointment_time'])) {
                $accountingNotes .= "\nمەوعید زیادنەکرا: " . ($appointmentResult['message'] ?: 'کێشەیەک ڕوویدا');
            }
            require_once __DIR__ . '/../classes/Notification.php';
            (new Notification())->notifyCaseCompleted(
                $case['patient_name'] ?? '',
                $case['doctor_name'] ?? '',
                $caseId,
                (int)($case['patient_id'] ?? 0),
                $accountingNotes,
                null,
                (int)$currentUser['id']
            );
            $appointmentFlag = !empty($appointmentResult['created']) ? '&appointment_created=1' : ((!empty($logData['next_appointment_date']) && !empty($logData['next_appointment_time'])) ? '&appointment_conflict=1' : '');
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&log_added=1&sent_to_accounting=1' . $appointmentFlag . '#tab-logs');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'procedureLogDialog');
    } elseif ($action === 'update_procedure_log') {
        // Update procedure log
        $logId = intval($_POST['log_id']);
        $logData = [
            'procedure_type_id' => !empty($_POST['procedure_type_id']) ? intval($_POST['procedure_type_id']) : null,
            'custom_procedure_name' => trim($_POST['custom_procedure_name'] ?? ''),
            'arch_affected' => $_POST['arch_affected'] ?? 'both',
            'procedure_date' => $_POST['procedure_date'],
            'procedure_time' => $_POST['procedure_time'] ?? null,
            'notes' => trim($_POST['notes'] ?? ''),
            'price' => floatval($_POST['price'] ?? 0),
            'next_appointment_date' => !empty($_POST['next_appointment_date']) ? $_POST['next_appointment_date'] : null,
            'next_appointment_time' => !empty($_POST['next_appointment_time']) ? $_POST['next_appointment_time'] : null,
            'next_appointment_duration' => intval($_POST['next_appointment_duration'] ?? 30) ?: 30,
            'next_appointment_notes' => trim($_POST['next_appointment_notes'] ?? '')
        ];
        $result = $archProcedureLog->updateLog($logId, $logData, $caseId);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&log_updated=1#tab-logs');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'procedureLogDialog');
    } elseif ($action === 'delete_procedure_log') {
        // Delete procedure log
        $logId = intval($_POST['log_id']);
        $result = $archProcedureLog->deleteLog($logId, $caseId);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&log_deleted=1#tab-logs');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'upload_case_image') {
        // Upload case image to a specific slot (1-6)
        $slotNumber = intval($_POST['slot_number']);
        // NOTE: the form field is image_description (the old code read
        // $_POST['description'], so descriptions were silently dropped)
        $description = trim($_POST['image_description'] ?? $_POST['description'] ?? '');
        if (!isset($_FILES['case_image']) || $_FILES['case_image']['error'] === UPLOAD_ERR_NO_FILE) {
            case_view_fail($caseId, 'تکایە فایلێک هەڵبژێرە');
        }
        if ($_FILES['case_image']['error'] !== UPLOAD_ERR_OK) {
            $uploadErr = (int)$_FILES['case_image']['error'];
            $msg = in_array($uploadErr, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'قەبارەی فایل زۆر گەورەیە'
                : 'هەڵە لە بارکردنی فایل (کۆد ' . $uploadErr . ')';
            case_view_fail($caseId, $msg);
        }
        $result = $caseManager->uploadImage($caseId, $slotNumber, $_FILES['case_image'], $currentUser['id'], $description);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&image_uploaded=1#tab-images');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'delete_case_image') {
        // Delete case image from a specific slot
        $slotNumber = intval($_POST['slot_number']);
        $result = $caseManager->deleteImage($caseId, $slotNumber);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&image_deleted=1#tab-images');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'send_to_accounting') {
        // Legacy case-wide send — retired from the UI ($showLegacyCaseAccountingActions
        // is hardcoded false; the visible workflow is invoice-by-invoice via
        // send_invoice), kept only so an old bookmarked POST doesn't 404.
        $existing = $db->fetch("SELECT id, status, sent_at, processed_at FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
        $latestCaseWorkAt = get_latest_case_work_at($db, $caseId);
        // Un-invoiced work always justifies a send, whatever the last report's
        // state — that work has no receipt with the accountant yet.
        $hasUninvoiced = $caseInvoice->getUninvoiced($caseId)['count'] > 0;
        if (!$hasUninvoiced && !can_send_case_to_accounting($existing, $latestCaseWorkAt)) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&accounting_already=1');
            exit;
        }

        $note = trim($_POST['notes'] ?? '');

        // Turn this visit's (and any older un-invoiced) work into per-day
        // receipts and hand each one to the accountant (CaseInvoice::send()
        // per draft) — no case-level accounting_reports row any more.
        $made = $caseInvoice->createForUninvoiced($caseId, $currentUser['id'], $note);

        require_once __DIR__ . '/../classes/Notification.php';
        $notifier = new Notification();
        foreach ($made['created'] as $iv) {
            $notifier->notifyInvoiceSent($caseId, $iv['invoice_no'], (int)$currentUser['id']);
        }

        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&sent_to_accounting=1');
        exit;
    } elseif ($action === 'resend_to_accounting') {
        // Follow-up note / resend to the accountant AFTER a case was already
        // sent. Requires a comment (a resend with nothing to add is pointless).
        // Same retirement note as send_to_accounting above — kept for
        // backward-compatible POSTs only.
        $note = trim($_POST['notes'] ?? '');
        if ($note === '') {
            case_view_fail($caseId, 'تکایە تێبینیەک بنووسە بۆ ناردن بۆ ژمێریار', 'resendAccountingDialog');
        }
        // A resend may carry new work — give it its own per-day receipt + send.
        $made = $caseInvoice->createForUninvoiced($caseId, $currentUser['id']);

        require_once __DIR__ . '/../classes/Notification.php';
        $notifier = new Notification();
        foreach ($made['created'] as $iv) {
            $notifier->notifyInvoiceSent($caseId, $iv['invoice_no'], (int)$currentUser['id']);
        }

        // The follow-up note itself belongs on the invoice already with the
        // accountant — appended to that invoice's own notes (no case-level
        // report row exists any more), then re-notified.
        $target = $db->fetch(
            "SELECT id, invoice_no, notes FROM case_invoices
             WHERE case_id = :cid AND accounting_status = 'sent'
             ORDER BY invoice_seq DESC LIMIT 1",
            ['cid' => $caseId]
        );
        if ($target) {
            $combinedNotes = trim((string)$target['notes']) === ''
                ? $note
                : trim((string)$target['notes']) . "\n\n" . $note;
            $db->update('case_invoices', [
                'notes' => $combinedNotes,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int)$target['id']]);
            $notifier->notifyInvoiceSent($caseId, $target['invoice_no'], (int)$currentUser['id']);
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&note_sent=1');
        exit;
    } elseif ($action === 'create_appointment') {
        // Standalone appointment for this patient — a dedicated booking action
        // for the doctor, independent of the send-to-accounting flow.
        $apptDate = trim($_POST['appointment_date'] ?? '');
        $apptTime = trim($_POST['appointment_time'] ?? '');
        if (!DateTime::createFromFormat('Y-m-d', $apptDate) || $apptDate < date('Y-m-d')) {
            case_view_fail($caseId, 'بەرواری مەوعید نادروستە یان ڕابردووە', 'appointmentDialog');
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $apptTime)) {
            case_view_fail($caseId, 'تکایە کاتێک هەڵبژێرە', 'appointmentDialog');
        }
        $apptTime = strlen($apptTime) === 5 ? $apptTime . ':00' : $apptTime;
        $validTypes = ['general', 'checkup', 'treatment', 'followup', 'emergency', 'consultation'];
        $apptType = in_array($_POST['type'] ?? '', $validTypes, true) ? $_POST['type'] : 'followup';
        $result = $appointmentManager->create([
            'patient_id'       => intval($case['patient_id']),
            'doctor_id'        => intval($case['doctor_id'] ?? 0) ?: intval($currentUser['id']),
            'folder_id'        => intval($case['folder_id']),
            'case_id'          => $caseId,
            'appointment_date' => $apptDate,
            'appointment_time' => $apptTime,
            'duration'         => max(15, intval($_POST['duration'] ?? 30) ?: 30),
            'type'             => $apptType,
            'notes'            => trim($_POST['notes'] ?? ''),
            'status'           => 'scheduled',
            'created_by'       => intval($currentUser['id'])
        ]);
        if (!empty($result['success'])) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&appointment_created=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? 'دروستکردنی مەوعید سەرنەکەوت', 'appointmentDialog');
    } else {
        // Unmatched action (e.g. a doctor's POST tripping an admin-only
        // `&& $isAdmin`-style guard, like direct_payment's) used to fall
        // through this whole chain silently and just reload the page with no
        // feedback. Closing that: any $action that reaches here unhandled is
        // now a proper Kurdish flash error instead of a silent no-op.
        case_view_fail($caseId, 'کردارێکی نەناسراو');
    }
}

// Check latest accounting report and whether newer billable work needs a fresh send.
$accountingReport = $db->fetch("SELECT id, status, sent_at, processed_at FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
$latestCaseWorkAt = get_latest_case_work_at($db, $caseId);

// Visit invoices (وەسڵی سەردان): lazy-sync first so statuses reflect any money
// collected through paths that don't hook the sync (installments, admin panel).
// Legacy work recorded before invoices existed gets bucketed into per-day
// drafts, so every treatment lives on a tab.
if ($case['status'] === 'open') {
    try { $caseInvoice->backfillDrafts($caseId, $currentUser['id']); } catch (Exception $e) { /* non-fatal */ }
}
try { $caseInvoice->syncStatuses($caseId); } catch (Exception $e) { /* display-only */ }
$caseInvoices = $caseInvoice->getByCase($caseId);
$uninvoicedWork = $caseInvoice->getUninvoiced($caseId);

// Chair-side cash recorded directly on a receipt (case_invoices.draft_received_amount)
// never becomes a `payments` row — CaseInvoice::setDraftReceived() does that on
// purpose (an earlier version DID auto-create one, and a FIFO bug misallocated it
// onto a different, older invoice; see the 2026-08-28 y/y2 repair migrations).
// That's the right call, but the Payments tab (and its count badge) only ever
// see real `payments` rows, so this is computed once here and folded into both.
$chairSideReceipts = array_filter($caseInvoices, function ($inv) {
    return (float)($inv['declared_received_amount'] ?? 0) > 0.009;
});
// invoice_id → meta, for labeling tabs in the treatments table (JS too)
$invoiceMetaById = [];
$hasDraftInvoice = false;
foreach ($caseInvoices as $ivRow) {
    $invoiceMetaById[(int)$ivRow['id']] = [
        'no' => $ivRow['invoice_no'],
        'status' => $ivRow['status'],
        'date' => $ivRow['work_date'],
    ];
    if ($ivRow['status'] === 'draft') {
        $hasDraftInvoice = true;
    }
}

// Treatments bucketed per visit tab, ready for rendering.
$invoiceTreatments = [];
$looseTreatments = [];   // safety net: rows that somehow have no tab
foreach ($caseTreatments as $ct) {
    $iid = (int)($ct['invoice_id'] ?? 0);
    if ($iid && isset($invoiceMetaById[$iid])) {
        $invoiceTreatments[$iid][] = $ct;
    } else {
        $looseTreatments[] = $ct;
    }
}

// Tabs newest-first, drafts before sent ones on the same date.
$invoiceTabs = case_view_sort_invoice_tabs($caseInvoices);

// Which tab opens first: ?inv=, else the newest draft, else the newest tab.
$activeInvoiceId = intval($_GET['inv'] ?? 0);
if (!isset($invoiceMetaById[$activeInvoiceId])) {
    $activeInvoiceId = 0;
    foreach ($invoiceTabs as $t) {
        if ($t['status'] === 'draft') { $activeInvoiceId = (int)$t['id']; break; }
    }
    if (!$activeInvoiceId && !empty($invoiceTabs)) {
        $activeInvoiceId = (int)$invoiceTabs[0]['id'];
    }
}

// An open draft always justifies a send — that visit has no receipt with the
// accountant yet, whatever the last report's state.
$canSendToAccounting = $hasDraftInvoice || $uninvoicedWork['count'] > 0
    || can_send_case_to_accounting($accountingReport, $latestCaseWorkAt);
// Kept only for backward-compatible POST handlers. The visible workflow is now
// invoice-by-invoice, so the old case-wide send/resend controls stay retired.
$showLegacyCaseAccountingActions = false;

// Get case images
$caseImages = $caseManager->getImages($caseId);

// Next upcoming appointment for this case (shown in the header)
$nextAppointment = $db->fetch(
    "SELECT appointment_date, appointment_time, duration, status
     FROM appointments
     WHERE case_id = :cid
       AND status IN ('scheduled', 'confirmed')
       AND (appointment_date > CURDATE() OR (appointment_date = CURDATE() AND appointment_time >= CURTIME()))
     ORDER BY appointment_date, appointment_time
     LIMIT 1",
    ['cid' => $caseId]
);

// Flash error + form-restore left by a failed POST (see case_view_fail)
$flashError = $_SESSION['case_flash_error'] ?? null;
unset($_SESSION['case_flash_error']);
$formRestore = $_SESSION['case_form_restore'] ?? null;
unset($_SESSION['case_form_restore']);

require_once __DIR__ . '/../includes/header.php';
?>
<!-- Receipt pane look + behaviour, shared with accountant/report-detail.php so
     a receipt reads the same on both sides of the desk. -->
<?php
$invoicePaneCssPath = __DIR__ . '/../assets/css/invoice-pane.css';
$invoicePaneCssVersion = is_file($invoicePaneCssPath) ? (string)filemtime($invoicePaneCssPath) : '1';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/invoice-pane.css?v=<?= rawurlencode($invoicePaneCssVersion) ?>">
<?php

// Doctor-facing financial figures come from the same invoice/allocation ledger
// as accounting. Cached case totals are retained only for legacy pages.
$doctorFinancialSummary = $accountingLedger->getSummary($caseId);
$remaining = $doctorFinancialSummary['balance'];

// Pending payment requests awaiting approval. These do NOT count as paid, but
// they DO reserve part of the balance — so a doctor can't stack requests past
// the total, and the "collect payment" button hides once the outstanding
// balance is fully covered by pending requests.
$pendingPaymentsTotal = 0.0;
$doctorPaymentDraft = null;
foreach ($payments as $pay) {
    if (($pay['status'] ?? '') === 'pending') {
        $pendingPaymentsTotal += (float)$pay['amount'] + (float)($pay['discount_amount'] ?? 0);
    }
    if ($isDoctor && ($pay['status'] ?? '') === 'draft'
        && (int)($pay['received_by'] ?? 0) === (int)$currentUser['id']) {
        $doctorPaymentDraft = $pay;
    }
}
$unrequestedBalance = max(0, $remaining - $pendingPaymentsTotal); // still needs a request
// Admin/accountant collect outright (direct_payment) — hidden once nothing is
// left to request. The case's own doctor (page access already 403s a
// non-owner doctor) instead SENDS A REQUEST (request_payment) for the
// accountant to approve — gated on the raw remaining balance, not
// unrequestedBalance, so the button stays available to ADJUST an
// already-fully-reserved pending request (Task-12's single-adjustable-
// pending-request model), not just to file a brand new one.
$canRequestPayment = $case['status'] === 'open' && !$installmentPlan && (
    ($canReceiveMoney && $unrequestedBalance > 0)
    || ($isDoctor && ($unrequestedBalance > 0.009 || $doctorPaymentDraft))
);

// Review fix (2026-08-26): $unrequestedBalance (remaining minus ALL pending,
// including the doctor's own) is the right gate for whether an admin can
// request MORE, but it is the WRONG cap for the doctor's dialog input — it
// counts the doctor's own already-pending amount as "used up", so once a
// doctor's single request fully reserves the remaining balance the field
// would show max=1 and open empty, even though the server (exclusion-aware,
// see request_payment's own getCaseBalance() call) would happily accept an
// ADJUSTMENT up to the full remaining. $doctorCap instead only reserves
// capacity for pending money that ISN'T the doctor's own single adjustable
// row, so the field always allows editing that row up to what the case can
// actually still take.
$doctorOwnPendingRequest = $isDoctor ? find_doctor_case_pending_request($db, $caseId, (int)$currentUser['id']) : null;
$doctorOwnPendingAmount = $doctorOwnPendingRequest ? (float)$doctorOwnPendingRequest['amount'] : 0.0;
$doctorCap = max(0, $unrequestedBalance);
?>

<style>
/* Windows 10 Style Case View */

/* Flat panels — no shadow, no hover lift, no transition. The page swaps one
   invoice pane at a time, and any movement there reads as flicker. */
.panel {
    border-radius: 8px;
    box-shadow: none;
    border: 1px solid #e3e8ee;
    overflow: hidden;
    margin-bottom: 16px;
    background: white;
    transition: none;
}
.panel:hover {
    box-shadow: none;
    transform: none;
}
.panel-header {
    background: linear-gradient(135deg, #0078d4 0%, #0063b1 100%);
    padding: 12px 16px;
    border-bottom: none;
}
.panel-title {
    font-size: 14px;
    font-weight: 700;
    color: white;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.panel-title i {
    color: white;
    font-size: 15px;
}
.panel-body {
    padding: 16px;
    background: white;
}

/* Windows 10 Style Badges */
.badge {
    padding: 5px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    cursor: default;
}
.badge:hover {
    transform: translateY(-1px);
}
.badge-green {
    background: linear-gradient(135deg, #c8e6c9, #a5d6a7);
    color: #2e7d32;
    border: 1px solid #81c784;
}
.badge-orange {
    background: linear-gradient(135deg, #ffe0b2, #ffcc80);
    color: #e65100;
    border: 1px solid #ffb74d;
}
.badge-red {
    background: linear-gradient(135deg, #ffcdd2, #ef9a9a);
    color: #c62828;
    border: 1px solid #e57373;
}
.badge-blue {
    background: linear-gradient(135deg, #bbdefb, #90caf9);
    color: #1565c0;
    border: 1px solid #64b5f6;
}
.badge-secondary {
    background: linear-gradient(135deg, #e0e0e0, #bdbdbd);
    color: #616161;
    border: 1px solid #9e9e9e;
}

/* Windows 10 Style Buttons */
.btn {
    padding: 10px 20px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 10px;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn i {
    font-size: 13px;
}
.btn-primary {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.25);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.35);
    background: linear-gradient(135deg, #0067c0, #005a9e);
}
.btn-secondary {
    background: linear-gradient(135deg, #e0e0e0, #bdbdbd);
    color: #616161;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    background: linear-gradient(135deg, #d5d5d5, #b0b0b0);
}
.btn-success {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.25);
}
.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
    background: linear-gradient(135deg, #43a047, #2e7d32);
}
.btn-warning {
    background: linear-gradient(135deg, #ff9800, #f57c00);
    color: white;
    box-shadow: 0 2px 8px rgba(255, 152, 0, 0.25);
}
.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 152, 0, 0.35);
    background: linear-gradient(135deg, #fb8c00, #ef6c00);
}
.btn-danger {
    background: linear-gradient(135deg, #f44336, #e53935);
    color: white;
    box-shadow: 0 2px 8px rgba(244, 67, 54, 0.25);
}
.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.35);
    background: linear-gradient(135deg, #e53935, #d32f2f);
}
.btn-outline {
    background: white;
    color: #0078d4;
    border: 2px solid #0078d4;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.15);
}
.btn-outline:hover {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.25);
}
.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}
.w-100 {
    width: 100%;
}
.mb-2 {
    margin-bottom: 8px;
}

/* ── Case tabs ────────────────────────────────────────────────────────
   The tab row is not a control panel bolted onto the case — it is the
   top edge of the chart itself. So: no grey trough, no raised pill, no
   shadow. One hairline runs the width of the panel and the open tab
   breaks it with an accent rule.

   Hierarchy is carried by colour and that rule rather than by weight:
   NRT ships Regular only (see assets/css/fonts.css), so a 600 here just
   asks the browser to smear the Kurdish letterforms. */
.tabs-container {
    --tab-accent: var(--win-accent, #0078D4);
    --tab-ink: #1F2A37;
    --tab-ink-quiet: #6B7684;
    --tab-rule: #E8ECF1;
    --tab-icon: #9AA5B1;
    --tab-chip: #F0F3F7;
    --tab-chip-ink: #6B7684;
    --tab-chip-on: #E4F0FB;

    display: flex;
    align-items: stretch;
    gap: 2px;
    padding: 0 8px;
    background: transparent;
    border-radius: 0;
    border-bottom: 1px solid var(--tab-rule);
    overflow-x: auto;
    scrollbar-width: none;
}
.tabs-container::-webkit-scrollbar { display: none; }

.tab {
    position: relative;
    /* Share the whole strip between the available tabs. min-width keeps
       longer Kurdish labels readable and lets the existing horizontal
       scroller take over only when the viewport is genuinely too narrow. */
    flex: 1 1 0;
    min-width: max-content;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 13px 14px 12px;
    background: #fff;
    border: none;
    border-radius: 0;
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 400;
    line-height: 1.35;
    white-space: nowrap;
    color: #000;
    cursor: pointer;
    transition: color 0.16s ease, background-color 0.16s ease;
}
/* The open tab's marker: a short accent capsule resting on the hairline. */
.tab::after {
    content: "";
    position: absolute;
    left: 10px;
    right: 10px;
    bottom: 0;
    height: 2.5px;
    border-radius: 3px;
    background: var(--tab-accent);
    transform: scaleX(0);
    transform-origin: center;
    transition: transform 0.18s cubic-bezier(0.4, 0, 0.2, 1);
}
.tab.active::after { transform: scaleX(1); }
.tab:hover { color: #000; background: #f7fafc; }
.tab.active { color: #000; background: #eef6fc; font-weight: 700; }
.tab i {
    font-size: 13px;
    color: var(--tab-icon);
    transition: color 0.16s ease;
}
.tab:hover i { color: var(--tab-ink); }
.tab.active i { color: var(--tab-accent); }
.tab:focus-visible {
    outline: 2px solid var(--tab-accent);
    outline-offset: -4px;
    border-radius: 6px;
}

/* A count is a reading, not a parenthetical. Tabular figures in a quiet
   chip, LTR-isolated so RTL never flips "0/6" round into "6/0". */
.tab-count {
    direction: ltr;
    unicode-bidi: isolate;
    padding: 1px 6px;
    border-radius: 5px;
    background: var(--tab-chip);
    color: #000;
    font-size: 11px;
    line-height: 1.6;
    font-variant-numeric: tabular-nums;
    transition: background 0.16s ease, color 0.16s ease;
}
.tab.active .tab-count { background: var(--tab-chip-on); color: #000; }
/* Images are six fixed slots, not an open list — read it as a capacity. */
.tab-count .cap-max { opacity: 1; }

@media (prefers-reduced-motion: reduce) {
    .tab,
    .tab i,
    .tab::after,
    .tab-count { transition: none; }
}

/* Windows 11 Style Tables */
.table-container {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e5e5;
}
.table {
    margin: 0;
    width: 100%;
    border-collapse: collapse;
}
.table thead th {
    background: linear-gradient(135deg, #E8EAF6, #C5CAE9);
    font-weight: 600;
    font-size: 13px;
    color: #3F51B5;
    padding: 12px 10px;
    border: 1px solid #d1d1d1;
    text-align: center;
    box-shadow: 0 2px 4px rgba(63, 81, 181, 0.1);
}
.table tbody td {
    padding: 10px 8px;
    font-size: 13px;
    vertical-align: middle;
    background: white;
    border: 1px solid #f0f0f0;
}
.table tbody tr {
    transition: all 0.15s ease;
}
.table tbody tr:hover {
    background: #F5F5F5;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
}
.table tfoot tr {
    background: linear-gradient(180deg, #f9f9f9, #f3f3f3);
    font-weight: 700;
}
.table tfoot td {
    border: 1px solid #e5e5e5;
    padding: 12px 10px;
    border-top: 2px solid #0078D4;
}

/* Windows 10 Style Alerts */
.alert {
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    animation: fadeIn 0.3s ease;
    border: none;
}
.alert-success {
    background: linear-gradient(135deg, #4caf50, #66bb6a);
    color: white;
}
.alert-error {
    background: linear-gradient(135deg, #f44336, #e57373);
    color: white;
}
.alert-info {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
}
.alert-warning {
    background: linear-gradient(135deg, #ff9800, #ffb74d);
    color: white;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}
.empty-state-icon {
    font-size: 56px;
    color: #bdbdbd;
    opacity: 0.5;
    margin-bottom: 12px;
}
.empty-state-title {
    font-size: 16px;
    font-weight: 700;
    color: #757575;
    margin: 0;
}

/* Windows 10 Style Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Flat panels, no entrance animation: this page re-renders one invoice pane at
   a time, and a fade on every swap reads as the page flickering. */
.panel {
    animation: none;
    box-shadow: none;
    border: 1px solid var(--border-light, #e3e8ee);
}
.vt-pane,
.vt-head,
.rcpt-table,
.vt-foot { animation: none; }
/* No hover lift / colour fades inside a receipt pane either */
.vt-tab,
.vt-btn,
.vt-add,
.rt-add-row .form-control,
.vt-disc-mode { transition: none; }

/* Compact Dental Chart Styles */
.dental-chart-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid var(--border-light);
}
.jaw-section {
    background: white;
    border-radius: 6px;
    padding: 8px 12px;
    margin: 4px 0;
    width: 100%;
}
.jaw-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
    text-align: center;
}
.teeth-row {
    display: flex;
    gap: 4px;
    justify-content: center;
    align-items: center;
}
.quadrant {
    display: flex;
    gap: 4px;
    padding: 6px;
    background: var(--bg-main);
    border-radius: 6px;
}
.quadrant-divider {
    width: 3px;
    height: 50px;
    background: var(--primary-color);
    border-radius: 2px;
    margin: 0 8px;
}

/* Mobile: a full jaw row (16 teeth) is ~700px wide — scroll it inside the
   jaw section instead of overflowing the page. Teeth keep their 38px touch size. */
@media (max-width: 767px) {
    .dental-chart-container { max-width: 100%; }
    .jaw-section {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .teeth-row {
        justify-content: flex-start;
        width: max-content;
        min-width: 100%;
    }
}

/* Tooth Styles — anatomical SVG teeth (smart chart) */
.tooth {
    width: 46px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    font-size: 10px;
    transition: all 0.15s ease;
    background: transparent;
    border: 2px solid transparent;
    border-radius: 8px;
    padding: 2px 1px 3px;
    position: relative;
}
.tooth:hover {
    transform: scale(1.1);
    background: var(--bg-color, #fff);
    border-color: var(--border-color, #e3e6ea);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 100;
}
.tooth.selected {
    border-color: var(--primary-color);
    background: rgba(0, 120, 212, 0.06);
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.25);
}

.tooth-svg { display: block; width: 38px; height: 50px; }
.tooth-svg svg { display: block; width: 100%; height: 100%; }

.tooth-number {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    padding: 1px 5px;
    border-radius: 3px;
    line-height: 1.2;
}
.tooth:hover .tooth-number {
    background: var(--primary-color);
    color: white;
}
/* Teeth that have treatments get a bold number, like the mockup */
.tooth.has-treatments .tooth-number {
    color: var(--text-color, #222);
}

/* Treatment Count Badge */
.treatment-count {
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 18px;
    height: 18px;
    background: #e53935;
    color: white;
    border-radius: 50%;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
}

/* Tooltip - Compact */
/* display:none rather than visibility:hidden on purpose. A hidden-but-laid-out
   tooltip is ~250px of nowrap text inside a ~40px tooth, and that counts toward
   every ancestor's scrollable overflow — it alone made the chart card, the panel
   and the page report hundreds of pixels of phantom horizontal scroll. */
.tooth-tooltip {
    display: none;
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 1000;
    margin-bottom: 4px;
    direction: rtl; /* quadrant is LTR — keep Kurdish tooth names right-to-left */
}
.tooth-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-top-color: #333;
}
.tooth:hover .tooth-tooltip { display: block; }
/* Hover tooltips are dead weight on touch — and 52 of them is a lot of nowrap
   text for a phone to lay out. */
@media (hover: none), (max-width: 767px) {
    .tooth:hover .tooth-tooltip { display: none; }
}

/* Chart Legend */
.chart-legend {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
    justify-content: center;
    padding: 10px;
    background: white;
    border-radius: 8px;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 12px;
    background: var(--bg-main);
}
.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
    border: 1px solid rgba(0,0,0,0.1);
}

/* Premium smart-chart overrides. They are scoped to the case chart so the
   older generic tooth and legend styles used elsewhere keep their behaviour. */
.smart-dental-chart {
    --chart-blue: #1677ff;
    --chart-ink: #25354a;
    /* Every tooth dimension is a multiple of this. fitChartScale() shrinks it
       just enough for the widest arch to fit the column, so the chart fills the
       card at any window size instead of scrolling sideways. */
    --chart-scale: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    box-sizing: border-box;
    padding: 18px 22px 16px;
    background: #fff;
    border: 1px solid #dfe8f1;
    border-radius: 10px;
    box-shadow: none;
}
.smart-dental-chart .jaw-section {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 2px 13px;
    margin: 0;
    background: transparent;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    scrollbar-width: none;
}
.smart-dental-chart .jaw-section::-webkit-scrollbar { display: none; }
.smart-dental-chart .jaw-section + .jaw-section {
    border-top: 1px solid #e7edf4;
    padding-top: 17px;
}
.smart-dental-chart .jaw-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0 0 9px;
    color: var(--chart-ink);
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.02em;
    text-align: center;
}
.smart-dental-chart .jaw-label::before,
.smart-dental-chart .jaw-label::after {
    content: '';
    display: block;
    width: min(8vw, 78px);
    height: 1px;
    background: linear-gradient(90deg, transparent, #ccd8e6);
}
.smart-dental-chart .jaw-label::after { transform: scaleX(-1); }
/* min-content, not a hardcoded 964px: the row is exactly as wide as the teeth
   inside it, so the adult (32) and child (20) charts each reserve only what they
   need and both shrink with --chart-scale instead of forcing a fixed width. */
.smart-dental-chart .teeth-row {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-width: min-content;
}
.smart-dental-chart .quadrant {
    display: flex;
    align-items: flex-start;
    gap: calc(4px * var(--chart-scale));
    padding: 3px 0 5px;
    background: transparent;
    border-radius: 0;
    /* Page is RTL; force LTR inside a quadrant so teeth read 8→1 | 1→8
       (clinician view: patient's right on the left of the chart). */
    direction: ltr;
}
/* Adult / child chart visibility filters — segmented pill above the odontogram.
   Both charts are always active; these only fold a pane away. */
.dentition-switch {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    background: #eef2f7;
    border: 1px solid #dbe3ec;
    border-radius: 999px;
}
.dentition-opt {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border: none;
    border-radius: 999px;
    background: transparent;
    color: var(--text-secondary, #5a6b7d);
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s, color .15s, box-shadow .15s;
}
.dentition-opt i { font-size: 11px; }
.dentition-opt:hover:not(.is-active):not(:disabled) { background: #e2e9f2; color: #33475b; }
.dentition-opt.is-active {
    background: #fff;
    color: #0078d4;
    box-shadow: 0 1px 3px rgba(16, 42, 67, 0.14);
}
.dentition-opt:disabled { opacity: .55; cursor: not-allowed; }
/* Both tooth sets are charted at once (mixed dentition). A pane can still be
   folded away for room, but the teeth inside it stay fully usable everywhere
   else — the dropdowns, the treatments table and the totals never filter by set. */
.dentition-pane[hidden] { display: none; }
.smart-dental-chart .dentition-pane {
    width: 100%;
    box-sizing: border-box;
    /* Flex item: without this its automatic minimum is the 964px min-content of
       .teeth-row, which pushes the whole page wider than the viewport. */
    min-width: 0;
}
.smart-dental-chart .dentition-pane + .dentition-pane {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px dashed #d8e2ee;
}
.dentition-pane-title {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 0 0 4px;
    color: #7a8b9d;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.dentition-pane-title i { font-size: 10px; color: #0078d4; }
.dentition-pane-title .dentition-pane-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: #e8f3fc;
    color: #0a6cb8;
    font-size: 10px;
    font-weight: 700;
}
.dentition-pane-title .dentition-pane-count:empty { display: none; }

.smart-dental-chart .quadrant-divider {
    flex: 0 0 auto;
    width: 1px;
    height: calc(102px * var(--chart-scale));
    margin: 0 calc(clamp(10px, 1.15vw, 18px) * var(--chart-scale));
    background: repeating-linear-gradient(to bottom, #cfd9e5 0 5px, transparent 5px 10px);
    border-radius: 1px;
    opacity: 0.96;
}

/* Tooth proportions intentionally vary by anatomy instead of making every
   tooth look like the same icon. */
.smart-dental-chart .tooth {
    --tooth-base-w: 56px;
    --tooth-base-art: 48px;
    --tooth-width: calc(var(--tooth-base-w) * var(--chart-scale));
    --tooth-art-width: calc(var(--tooth-base-art) * var(--chart-scale));
    --tooth-art-height: calc(82px * var(--chart-scale));
    width: var(--tooth-width);
    min-width: var(--tooth-width);
    min-height: calc(112px * var(--chart-scale));
    box-sizing: border-box;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    position: relative;
    padding: 4px 2px 6px;
    font-size: 10px;
    background: transparent;
    border: 2px solid transparent;
    border-radius: 12px;
    transition: none;
    -webkit-tap-highlight-color: transparent;
}
.smart-dental-chart .tooth--incisor { --tooth-base-w: 45px; --tooth-base-art: 42px; }
.smart-dental-chart .tooth--canine { --tooth-base-w: 50px; --tooth-base-art: 44px; }
.smart-dental-chart .tooth--premolar { --tooth-base-w: 54px; --tooth-base-art: 47px; }
.smart-dental-chart .tooth--molar { --tooth-base-w: 59px; --tooth-base-art: 51px; }
.smart-dental-chart .tooth:hover {
    background: #f5f9ff;
    border-color: #c9dbf1;
    box-shadow: none;
    z-index: 100;
}
.smart-dental-chart .tooth.selected {
    border-color: var(--chart-blue);
    background: #f3f8ff;
    box-shadow: 0 0 0 3px rgba(22, 119, 255, 0.14), 0 10px 22px rgba(22, 119, 255, 0.12);
}
.smart-dental-chart .tooth-svg {
    display: block;
    width: var(--tooth-art-width);
    height: var(--tooth-art-height, 82px);
    overflow: visible;
}
.smart-dental-chart .tooth-svg svg {
    display: block;
    width: 100%;
    height: 100%;
    overflow: visible;
    filter: drop-shadow(0 3px 3px rgba(49, 76, 104, 0.10));
}
.smart-dental-chart .tooth-number {
    display: inline-grid;
    place-items: center;
    min-width: 22px;
    height: 22px;
    padding: 0 5px;
    border-radius: 7px;
    color: #2c3d54;
    font-size: 13px;
    font-weight: 800;
    line-height: 1;
    transition: background 160ms ease, color 160ms ease, box-shadow 160ms ease;
}
.smart-dental-chart .tooth:hover .tooth-number,
.smart-dental-chart .tooth.selected .tooth-number {
    background: var(--chart-blue);
    color: #fff;
    box-shadow: 0 3px 8px rgba(22, 119, 255, 0.28);
}
.smart-dental-chart .tooth.has-treatments .tooth-number { color: #1b2d43; }
.smart-dental-chart .tooth.has-treatments.selected .tooth-number,
.smart-dental-chart .tooth.has-treatments:hover .tooth-number { color: #fff; }

/* A quiet blue count marker keeps multiple treatments discoverable without
   competing with the tooth number or treatment overlay. */
.smart-dental-chart .treatment-count {
    position: absolute;
    top: 26px;
    right: auto;
    left: auto;
    inset-inline-end: 1px;
    z-index: 3;
    min-width: 14px;
    height: 14px;
    padding: 0 2px;
    box-sizing: border-box;
    background: #2d7ff9;
    color: #fff;
    border: 1.5px solid #fff;
    border-radius: 999px;
    box-shadow: 0 2px 6px rgba(23, 89, 175, 0.28);
    font-size: 8px;
    font-weight: 800;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}
.smart-dental-chart .chart-legend {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 7px 9px;
    flex-wrap: wrap;
    width: 100%;
    box-sizing: border-box;
    margin-top: 8px;
    padding: 10px 12px;
    background: rgba(255, 255, 255, 0.86);
    border: 1px solid #e0e8f0;
    border-radius: 12px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
}
.smart-dental-chart .smart-chart-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 7px;
    color: #4a5b70;
    font-size: 10.5px;
    font-weight: 650;
    line-height: 1.35;
    white-space: nowrap;
}
.smart-dental-chart .smart-chart-legend-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 24px;
    flex: 0 0 auto;
}
.smart-dental-chart .legend-color {
    width: 13px;
    height: 13px;
    border-radius: 4px;
    border: 1px solid rgba(0,0,0,0.12);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.45);
}
@media (max-width: 1180px) {
    .smart-dental-chart { padding-inline: 15px; }
    .smart-dental-chart .jaw-section {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-color: #c8d6e6 transparent;
        scrollbar-width: thin;
    }
    .smart-dental-chart .jaw-section::-webkit-scrollbar { display: block; height: 5px; }
    .smart-dental-chart .teeth-row { justify-content: flex-start; }
}
/* Narrow columns: a 16-tooth jaw row cannot be read or tapped once the column
   drops under ~500px, and stretching it would push the page sideways. Stack the
   two quadrants of each jaw instead — every row is then at most 8 teeth (5 for
   primary), and quadrant integrity is kept. fitChartScale() then shrinks the
   teeth the last few percent so the arch fills the card exactly. */
@media (max-width: 900px) {
    .smart-dental-chart .teeth-row,
    .smart-dental-chart .dentition-pane--primary .teeth-row {
        flex-wrap: wrap;
        justify-content: center;
        row-gap: 4px;
    }
    .smart-dental-chart .quadrant {
        flex: 1 0 100%;
        justify-content: center;
    }
    /* Markup order is left-group then right-group; stacked, the patient's right
       side should still come first so it reads like the wide chart. */
    .smart-dental-chart .teeth-row > .quadrant:first-child { order: 2; }
    .smart-dental-chart .quadrant-divider {
        flex: 1 0 100%;
        order: 1;
        width: auto;
        height: 1px;
        margin: 3px 0;
        background: repeating-linear-gradient(to right, #cfd9e5 0 5px, transparent 5px 10px);
    }
}
@media (max-width: 767px) {
    .smart-dental-chart { padding: 13px 10px 12px; border-radius: 13px; }
    .smart-dental-chart .jaw-section { padding-inline: 2px; overflow-x: visible; }
    .smart-dental-chart .jaw-label { font-size: 12px; }
    .smart-dental-chart .jaw-label::before,
    .smart-dental-chart .jaw-label::after { width: 34px; }
    .smart-dental-chart .tooth { min-height: 0; gap: 2px; padding: 3px 1px 4px; }
    .smart-dental-chart .tooth-number { min-width: 18px; height: 18px; font-size: 11px; }
    .smart-dental-chart .treatment-count { transform: scale(0.85); }
}

/* Windows 10 Style Tooth Detail Panel */
.tooth-detail-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 2500;
    display: none;
    align-items: center;
    justify-content: center;
}
.tooth-detail-overlay.open {
    display: flex;
}
.tooth-detail-panel {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) scale(0.9);
    width: 340px;
    max-width: 95vw;
    max-height: 70vh;
    background: white;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    z-index: 2500;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.tooth-detail-panel.open {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, -50%) scale(1);
}
.tooth-detail-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    padding: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.tooth-detail-info {
    display: flex;
    align-items: center;
    gap: 10px;
}
.tooth-big-icon {
    width: 52px;
    height: 64px;
    background: rgba(255,255,255,0.92);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    padding: 3px;
}
.tooth-big-icon svg { width: 100%; height: 100%; display: block; }
.treatment-surface-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 5px;
}
.treatment-surface-tag {
    font-size: 10px;
    background: rgba(0, 120, 212, 0.08);
    color: #0067b8;
    border: 1px solid rgba(0, 120, 212, 0.25);
    padding: 1px 7px;
    border-radius: 10px;
}
.treatment-del-btn {
    background: transparent;
    border: none;
    color: #c50f1f;
    cursor: pointer;
    font-size: 12px;
    padding: 3px 6px;
    border-radius: 4px;
    float: left;
}
.treatment-del-btn:hover { background: rgba(197, 15, 31, 0.1); }
.tooth-detail-title h3 {
    margin: 0;
    font-size: 14px;
}
.tooth-detail-title p {
    margin: 0;
    font-size: 11px;
    opacity: 0.9;
}
.close-detail-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.close-detail-btn:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}
.tooth-detail-body {
    padding: 12px;
    overflow-y: auto;
    flex: 1;
}
.tooth-detail-section {
    margin-bottom: 12px;
}
.tooth-detail-section h4 {
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--border-light);
}
.treatment-history-item {
    background: var(--bg-main);
    border-radius: 6px;
    padding: 8px 10px;
    margin-bottom: 6px;
    border-right: 3px solid var(--primary-color);
}
.treatment-history-item.filling { border-right-color: #00BCD4; }
.treatment-history-item.crown { border-right-color: #FFC107; }
.treatment-history-item.root_canal { border-right-color: #9C27B0; }
.treatment-history-item.extraction { border-right-color: #F44336; }
.treatment-history-item.implant { border-right-color: #FF9800; }
.treatment-history-item.cleaning { border-right-color: #4CAF50; }
.treatment-history-item .treatment-name {
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 4px;
    font-size: 12px;
}
.treatment-history-item .treatment-meta {
    font-size: 10px;
    color: var(--text-secondary);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.treatment-history-item .treatment-meta span {
    display: flex;
    align-items: center;
    gap: 3px;
}
.treatment-history-item .treatment-price {
    color: var(--success-color);
    font-weight: 600;
}
.no-treatments {
    text-align: center;
    padding: 20px 10px;
    color: var(--text-secondary);
}
.no-treatments i {
    font-size: 32px;
    margin-bottom: 8px;
    opacity: 0.3;
    display: block;
}
.no-treatments p { font-size: 11px; margin: 0; }
.tooth-detail-footer {
    padding: 10px 12px;
    background: var(--bg-main);
    border-top: 1px solid var(--border-light);
}
.add-treatment-btn-panel {
    width: 100%;
    padding: 8px 12px;
    font-size: 12px;
}

/* Quick Stats - Compact */
.tooth-quick-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-bottom: 12px;
}
.quick-stat {
    background: var(--bg-main);
    padding: 8px;
    border-radius: 6px;
    text-align: center;
}
.quick-stat-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--primary-color);
}
.quick-stat-label {
    font-size: 9px;
    color: var(--text-secondary);
    margin-top: 2px;
}

/* Dialog Override - Ensure dialogs appear above tooth detail panel */
#addTreatmentDialog,
#editTreatmentDialog,
#paymentDialog,
#installmentDialog {
    z-index: 3000 !important;
}
#addTreatmentDialog.open,
#editTreatmentDialog.open,
#paymentDialog.open,
#installmentDialog.open {
    z-index: 3000 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
#addTreatmentDialog.open .dialog,
#editTreatmentDialog.open .dialog,
#paymentDialog.open .dialog,
#installmentDialog.open .dialog {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    transform: none !important;
}

/* Flexible Pricing Styles */
.pricing-section {
    margin-bottom: 16px;
}
.pricing-box {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    padding: 12px;
    transition: all 0.3s ease;
}
.pricing-box:hover {
    border-color: #0078d4;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.15);
}
.price-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
}
.price-row:first-child {
    border-bottom: 1px dashed #d0d0d0;
}
.price-label {
    flex: 1;
    font-size: 13px;
    color: #546e7a;
    font-weight: 500;
}
.price-value {
    font-size: 16px;
    font-weight: 700;
    color: #757575;
    min-width: 80px;
    text-align: right;
}
.price-currency {
    font-size: 12px;
    color: #9e9e9e;
    font-weight: 600;
}
.default-price-row {
    opacity: 0.85;
}
.agreement-price-row .price-label {
    color: #0078d4;
    font-weight: 600;
}
.agreement-price-input {
    width: 120px;
    padding: 8px 12px;
    border: 2px solid #0078d4;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 700;
    color: #2e7d32;
    text-align: right;
    transition: all 0.2s ease;
    background: white;
}
.agreement-price-input:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.2);
    border-color: #4caf50;
}
.agreement-price-input::placeholder {
    color: #bdbdbd;
    font-weight: 400;
    font-size: 12px;
}
.use-default-btn {
    width: 32px;
    height: 32px;
    border: none;
    background: linear-gradient(135deg, #ff9800, #f57c00);
    color: white;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.2s ease;
}
.use-default-btn:hover {
    transform: rotate(180deg) scale(1.1);
    background: linear-gradient(135deg, #4caf50, #388e3c);
}

/* Total Display */
.total-display {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    border: 2px solid #4caf50;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 16px;
}
.total-display span:first-child {
    font-size: 14px;
    color: #2e7d32;
    font-weight: 600;
}
.total-display span:nth-child(2) {
    font-size: 22px;
    font-weight: 700;
    color: #1b5e20;
}
.total-display span:last-child {
    font-size: 14px;
    color: #388e3c;
    font-weight: 600;
}

/* Arch View Chart Styles */
.arch-view-chart {
    padding: 16px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
}
.arch-view-panel {
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}
.arch-view-panel:hover {
    border-color: #0078d4;
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.15);
    transform: translateY(-2px);
}
.arch-view-panel.has-treatments {
    border-color: #4caf50;
    background: linear-gradient(135deg, #ffffff, #e8f5e9);
}
.arch-view-visual {
    display: flex;
    justify-content: center;
    padding: 10px;
}
.arch-view-svg {
    width: 100%;
    max-width: 400px;
    height: 100px;
}
.arch-tooth-indicator {
    fill: #e0e0e0;
    transition: all 0.2s ease;
}
.arch-tooth-indicator.treated {
    fill: #4caf50;
}
.arch-tooth-indicator:hover {
    fill: #0078d4;
    transform: scale(1.1);
}
.arch-view-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;
    margin-top: 12px;
}
.arch-view-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 15px;
}
.arch-view-title i {
    color: #0078d4;
    font-size: 18px;
}
.arch-view-stats {
    display: flex;
    gap: 20px;
}
.arch-stat {
    text-align: center;
    padding: 8px 16px;
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    border-radius: 10px;
}
.arch-stat .stat-value {
    font-size: 18px;
    font-weight: 700;
    color: #0078d4;
    display: block;
}
.arch-stat .stat-label {
    font-size: 11px;
    color: #757575;
}
.arch-summary {
    display: flex;
    justify-content: center;
    gap: 16px;
    padding: 12px;
    background: white;
    border-radius: 10px;
    flex-wrap: wrap;
}
.arch-summary-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #f5f5f5;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    color: #546e7a;
}
.arch-summary-item i {
    color: #0078d4;
}
.arch-summary-item.total {
    background: linear-gradient(135deg, #4caf50, #66bb6a);
    color: white;
}
.arch-summary-item.total i {
    color: white;
}

/* Arch Selection Chart in Dialog */
.arch-select-chart {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.arch-select-panel {
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #fafafa;
}
.arch-select-panel:hover {
    border-color: #90caf9;
    background: #f5f9ff;
    transform: translateY(-1px);
}
.arch-select-panel.selected {
    border-color: #4caf50;
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    box-shadow: 0 3px 10px rgba(76, 175, 80, 0.2);
}
.arch-select-panel.selected .arch-select-outline {
    stroke: #4caf50;
    stroke-width: 3;
}
.arch-select-panel.selected .arch-teeth-indicators rect {
    fill: #4caf50;
}
.arch-select-visual {
    display: flex;
    justify-content: center;
}
.arch-select-svg {
    width: 100%;
    max-width: 280px;
    height: 70px;
}
.arch-teeth-indicators rect {
    fill: #90caf9;
    transition: all 0.2s ease;
}
.arch-select-panel:hover .arch-teeth-indicators rect {
    fill: #64b5f6;
}
.arch-select-label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e8e8e8;
}
.arch-select-label span:first-of-type {
    flex: 1;
    font-weight: 600;
    color: #2c3e50;
    font-size: 13px;
}
.arch-select-count {
    font-size: 11px;
    color: #757575;
    background: #f0f0f0;
    padding: 2px 8px;
    border-radius: 10px;
}
.arch-select-check {
    color: #bdbdbd;
    font-size: 16px;
    transition: all 0.3s ease;
}
.arch-select-panel.selected .arch-select-check {
    color: #4caf50;
}
.arch-select-hint {
    font-size: 11px;
    color: #757575;
    margin: 8px 0 0;
    display: flex;
    align-items: center;
    gap: 5px;
}
.arch-select-hint i {
    color: #9c27b0;
}

/* Pricing Mode Selector */
.pricing-mode-selector {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.pricing-mode-option {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: 2px solid #e0e0e0;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #fafafa;
    font-size: 12px;
}
.pricing-mode-option:hover {
    border-color: #90caf9;
    background: #f5f9ff;
}
.pricing-mode-option.selected {
    border-color: #4caf50;
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    color: #2e7d32;
}
.pricing-mode-option input[type="radio"] {
    display: none;
}
.pricing-mode-option i {
    font-size: 14px;
    color: #757575;
}
.pricing-mode-option.selected i {
    color: #4caf50;
}
.separate-prices-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Windows 10 Style Dialog */
.dialog-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 3000;
}
.dialog-overlay.open {
    display: flex;
}
.dialog {
    background: white;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    width: 90%;
    max-width: 450px;
}
.dialog-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 10px 10px 0 0;
    flex-shrink: 0;
}
.dialog-title {
    font-size: 14px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.dialog-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}
.dialog-close:hover {
    background: rgba(255, 255, 255, 0.35);
    transform: rotate(90deg) scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}
.dialog-body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
    max-height: calc(85vh - 110px);
}
.dialog-body::-webkit-scrollbar {
    width: 8px;
}
.dialog-body::-webkit-scrollbar-track {
    background: #f0f0f0;
    border-radius: 10px;
}
.dialog-body::-webkit-scrollbar-thumb {
    background: #c0c0c0;
    border-radius: 10px;
}
.dialog-body::-webkit-scrollbar-thumb:hover {
    background: #a0a0a0;
}
.dialog-footer {
    padding: 12px 16px;
    border-top: 1px solid #e8e8e8;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    background: #f8f9fa;
    flex-shrink: 0;
}

/* Windows 10 Style Form Elements */
.form-group {
    margin-bottom: 12px;
}
.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 6px;
}
.form-label.required::after {
    content: ' *';
    color: #f44336;
    margin-left: 4px;
}
.form-control {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    transition: all 0.2s ease;
    background: white;
}
.form-control:focus {
    border-color: #0078d4;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
}
textarea.form-control {
    resize: vertical;
    min-height: 60px;
}
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

/* Case Images Grid */
.case-images-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
@media (max-width: 768px) {
    .case-images-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .case-images-grid {
        grid-template-columns: 1fr;
    }
}
.case-image-slot {
    border: 2px dashed #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    background: #fafafa;
    transition: all 0.3s ease;
    min-height: 180px;
    display: flex;
    flex-direction: column;
}
.case-image-slot.has-image {
    border: 2px solid #e0e0e0;
    background: white;
}
.case-image-slot:hover {
    border-color: #0078d4;
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.15);
}
.slot-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #e0e0e0;
}
.slot-number {
    width: 24px;
    height: 24px;
    background: #0078d4;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}
.slot-info {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
}
.image-preview {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    min-height: 120px;
    background: #f5f5f5;
}
.image-preview img {
    max-width: 100%;
    max-height: 150px;
    object-fit: contain;
}
.image-preview .pdf-icon {
    font-size: 48px;
    color: #dc3545;
}
.image-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
}
.image-overlay i {
    color: white;
    font-size: 24px;
}
.image-preview:hover .image-overlay {
    opacity: 1;
}
.image-actions {
    display: flex;
    gap: 6px;
    padding: 10px;
    justify-content: center;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
}
.empty-slot {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #999;
    transition: all 0.3s;
    min-height: 140px;
}
.empty-slot:hover {
    color: #0078d4;
    background: rgba(0, 120, 212, 0.05);
}
.empty-slot i {
    font-size: 36px;
    margin-bottom: 8px;
}
.empty-slot span {
    font-size: 13px;
    font-weight: 500;
}
</style>

<?php
// ── Unified feedback: every redirect flag gets a visible message ──
$successFlags = [
    'treatment_added'      => 'چارەسەر زیادکرا',
    'treatments_added'     => intval($_GET['treatments_added'] ?? 0) . ' چارەسەر زیادکرا',
    'arch_treatments_added'=> intval($_GET['arch_treatments_added'] ?? 0) . ' چارەسەری ئەرک زیادکرا',
    'treatment_updated'    => 'چارەسەر نوێکرایەوە',
    'treatments_updated'   => 'چارەسەرەکان نوێکرانەوە',
    'treatment_deleted'    => 'چارەسەر سڕایەوە',
    'invoice_deleted'      => 'پسووڵە و هەموو تۆمار و پارەدانە پەیوەندیدارەکانی سڕانەوە',
    'treatments_deleted'   => intval($_GET['treatments_deleted'] ?? 0) . ' چارەسەر سڕایەوە',
    'payment_added'        => 'پارەدان تۆمارکرا',
    'payment_draft_saved'  => 'قەبزی پارەدان وەک ڕەشنووس خەزنکرا؛ هێشتا بۆ ژمێریار نەنێردراوە',
    'payment_draft_updated'=> 'قەبزی پارەدانی ڕەشنووس نوێکرایەوە',
    'payment_draft_sent'   => 'قەبزی پارەدان نێردرا بۆ ژمێریار',
    'payment_requested'    => 'داواکاری پارەدان نێردرا بۆ پەسەندکردن',
    'payment_request_unchanged' => 'داواکارییەکە پێشتر نێردراوە و چاوەڕوانی پەسەندکردنە',
    'payment_request_updated'   => 'داواکاری پارەدان نوێکرایەوە',
    // request_payment (case-level داواکاری پارەدان button/dialog) — distinct
    // flags from the وەرگیراو-per-invoice ones above (same wording, no
    // target invoice) so the two entry points' redirects never collide.
    'case_payment_requested'          => 'داواکاری پارەدان نێردرا بۆ پەسەندکردن',
    'case_payment_request_unchanged'  => 'داواکارییەکە پێشتر نێردراوە و چاوەڕوانی پەسەندکردنە',
    'case_payment_request_updated'    => 'داواکاری پارەدان نوێکرایەوە',
    'installment_created'  => 'پلانی قیست دروستکرا',
    'installment_proposed' => 'داواکاری قیست نێردرا بۆ ژمێریاری',
    'installment_paid'     => 'پارەدانی قیست تۆمارکرا',
    // flexible_installment_payment (doctor branch) — Task-12 dedupe outcomes,
    // distinct from the generic 'payment_requested' flag used for a fresh create.
    'installment_request_unchanged' => 'پێشتر نێردراوە',
    'installment_request_updated'   => 'داواکاری قیست نوێکرایەوە',
    'month_skipped'        => 'مانگ ڕاپەڕێندرا - ماوە گوازرایەوە بۆ مانگی داهاتوو',
    'log_added'            => 'کارەکە تۆمارکرا',
    'log_updated'          => 'کارەکە نوێکرایەوە',
    'log_deleted'          => 'کارەکە سڕایەوە',
    'appointment_created'  => 'مەوعیدی داهاتوو زیادکرا',
    'discount_applied'     => 'داشکاندن جێبەجێکرا',
    'case_closed_ok'       => 'کەیسەکە داخرا',
    'case_reopened'        => 'کەیسەکە کرایەوە',
    'note_sent'            => 'تێبینی بۆ ژمێریار نێردرا',
    'invoice_recalled'     => 'ناردنی وەسڵەکە کانسڵکرایەوە — ئێستا دەتوانیت دەستکاری بکەیت و دووبارە بینێریتەوە',
];
foreach ($successFlags as $flag => $message):
    if (isset($_GET[$flag])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> <?= $message ?></div>
<?php endif; endforeach; ?>

<?php
// Money was just taken — the patient is still in the chair, so put their قەبز
// one click away. Deliberately NOT auto-hidden and NOT auto-opened: a popup
// fired from the reload would be blocked, and this must not scroll past unseen.
$voucherJustMade = isset($_GET['voucher']) ? intval($_GET['voucher']) : 0;
if ($voucherJustMade > 0): ?>
<div class="alert alert-success" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <i class="fas fa-receipt alert-icon"></i>
    <span style="flex:1 1 auto;">قەبزی وەرگرتنی پارە ئامادەیە بۆ نەخۆش</span>
    <a class="btn btn-sm btn-primary" target="_blank" rel="noopener"
       href="<?= APP_URL ?>/cases/voucher.php?payment=<?= $voucherJustMade ?>">
        <i class="fas fa-print"></i> چاپی قەبز
    </a>
</div>
<?php endif; ?>

<?php if (isset($_GET['appointment_conflict'])): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle alert-icon"></i> کارەکە تۆمارکرا بەڵام مەوعیدەکە زیادنەکرا — کاتەکە گیراوە، تکایە لە پەڕەی مەوعیدەکان کاتێکی تر هەڵبژێرە</div>
<?php endif; ?>

<?php
// Error flags from guard redirects
$errorMessages = [
    'case_closed'        => 'کەیسەکە داخراوە — بۆ گۆڕانکاری سەرەتا بیکەرەوە',
    'not_allowed'        => 'ئەم کردارە ڕێگەپێدراو نییە',
    'no_arch_selected'   => 'تکایە ئەرکێک هەڵبژێرە',
    'invalid_percentage' => 'ڕێژەی سەدی دەبێت لە نێوان 0 و 100 دا بێت',
    'invalid_discount'   => 'بڕی داشکاندن نادروستە',
];
$errorKey = $_GET['error'] ?? '';
if ($errorKey !== '' && isset($errorMessages[$errorKey])): ?>
<div class="alert alert-error"><i class="fas fa-times-circle alert-icon"></i> <?= $errorMessages[$errorKey] ?></div>
<?php endif; ?>

<?php if (!empty($flashError)): ?>
<div class="alert alert-error"><i class="fas fa-times-circle alert-icon"></i> <?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<?php
// Show auto-allocation details if available
if ((isset($_GET['payment_added']) || isset($_GET['payment_requested']))
    && isset($_SESSION['last_allocation']) && $_SESSION['last_allocation']['success']):
    $allocation = $_SESSION['last_allocation'];
    unset($_SESSION['last_allocation']); // Clear it after displaying
?>
<div class="alert alert-info" data-auto-hide>
    <i class="fas fa-info-circle alert-icon"></i>
    <strong>دابەشکردنی خۆکار:</strong>
    پارەکە بەسەر <?= $allocation['installments_affected'] ?> قیست دابەشکرا
    <?php if ($allocation['remaining_unallocated'] > 0): ?>
        (ماوە: <?= number_format($allocation['remaining_unallocated']) ?>)
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Case Header -->
<div class="panel mb-3">
    <div class="panel-body">
        <div class="d-flex justify-between align-center flex-wrap gap-2">
            <div class="d-flex align-center gap-3">
                <div style="width: 60px; height: 60px; border-radius: 12px; background: <?= $case['status'] === 'open' ? 'var(--success-light)' : 'var(--secondary-light)' ?>; color: <?= $case['status'] === 'open' ? 'var(--success-color)' : 'var(--text-secondary)' ?>; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    <i class="fas fa-briefcase-medical"></i>
                </div>
                <div>
                    <h2 style="margin: 0 0 4px;"><?= htmlspecialchars($case['case_number']) ?>
                        <span class="badge <?= $case['status'] === 'open' ? 'badge-green' : ($case['status'] === 'pending' ? 'badge-orange' : 'badge-secondary') ?>" style="font-size: 12px; padding: 4px 12px; vertical-align: middle;">
                            <?= $case['status'] === 'open' ? 'کراوە' : ($case['status'] === 'pending' ? 'چاوەڕوان' : 'داخراو') ?>
                        </span>
                        <?php
                        // نوێ / کۆن — switchable only while no receipt has reached the accountant.
                        $caseTypeLocked = (bool)$db->fetch(
                            "SELECT id FROM case_invoices WHERE case_id = :cid AND accounting_status NOT IN ('draft','returned','cancelled') LIMIT 1",
                            ['cid' => $caseId]
                        );
                        $canToggleHistorical = $case['status'] === 'open' && !$caseTypeLocked;
                        ?>
                        <?php if (!empty($case['is_historical'])): ?>
                        <span class="badge" style="font-size: 12px; padding: 4px 12px; vertical-align: middle; background:#fff1dc; color:#7a4a12;"
                              title="کاری پێش سیستەم — دراوی پێشوو ناچێتە ناو داهاتی سندوق">
                            <i class="fas fa-history"></i> کەیسی کۆن<?= !empty($case['historical_date']) ? ' · ' . htmlspecialchars($case['historical_date']) : '' ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($canToggleHistorical): ?>
                        <button type="button" class="btn btn-sm" onclick="openDialog('caseHistoricalDialog')"
                                style="font-size: 11px; padding: 3px 10px; vertical-align: middle; background:#fff8ee; border:1px dashed #d9a35b; color:#7a4a12;"
                                title="گۆڕینی جۆری کەیس (نوێ / کۆن)">
                            <i class="fas fa-exchange-alt"></i> <?= !empty($case['is_historical']) ? 'گۆڕین بۆ نوێ' : 'نیشانکردن وەک کەیسی کۆن' ?>
                        </button>
                        <?php endif; ?>
                        <?php
                        // ئامانەت — the whole point of the feature: the patient
                        // paid at the counter this morning, hours before the
                        // doctor opened this case. Without this the doctor has
                        // no way to know, and asks them for the money again.
                        require_once __DIR__ . '/../classes/PatientAdvance.php';
                        $caseAdvanceAvailable = (new PatientAdvance())->balanceOf((int)($case['patient_id'] ?? 0));
                        if ($caseAdvanceAvailable > 0.009):
                        ?>
                        <span class="badge" style="font-size: 12px; padding: 4px 12px; vertical-align: middle; background:#e8f5e9; color:#1b5e20;"
                              title="لە پێشوازی وەرگیراوە. لە خانەی «ئامانەت»ی وەسڵەکەوە دایبنێ لەسەر ئەم کەیسە.">
                            <i class="fas fa-hand-holding-usd"></i> ئامانەتی بەردەست: <?= number_format($caseAdvanceAvailable) ?>
                        </span>
                        <?php endif; ?>
                    </h2>
                    <p class="text-secondary" style="margin: 0;">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $case['patient_id'] ?>">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($case['patient_name']) ?>
                        </a>
                        <?php if (!empty($case['doctor_name'])): ?>
                        &nbsp;&bull;&nbsp; <i class="fas fa-user-md"></i> د. <?= htmlspecialchars($case['doctor_name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($case['case_name'])): ?>
                        &nbsp;&bull;&nbsp; <?= htmlspecialchars($case['case_name']) ?>
                        <?php endif; ?>
                        <?php if ($nextAppointment): ?>
                        <!-- Next appointment, moved out of the removed stats row -->
                        <span class="next-appt-chip" title="مەوعیدی داهاتوو">
                            <i class="fas fa-calendar-alt"></i>
                            <?= date('Y/m/d', strtotime($nextAppointment['appointment_date'])) ?>
                            — <?= date('H:i', strtotime($nextAppointment['appointment_time'])) ?>
                        </span>
                        <?php endif; ?>
                    </p>
                    <?php // The case-wide figures live here — the sidebar
                          // "پوختەی دارایی" panel was removed. Work is entered
                          // receipt by receipt, but the case total still has to be
                          // readable at a glance, so all three ride in the chip
                          // and update live through data-case-bind. Discounts are
                          // negotiated per receipt now (invoice پەنجەری داشکاندن) —
                          // there is no case-level discount edit here any more. ?>
                    <div class="case-total-chip">
                        <i class="fas fa-calculator"></i>
                        <span class="ctc-label">کۆی گشتی</span>
                        <span class="ctc-value" data-case-bind="total_amount"><?= number_format($doctorFinancialSummary['total_invoiced']) ?></span>
                        <span class="ctc-sep"></span>
                        <span class="ctc-label">پارەی دراو</span>
                        <span class="ctc-value is-paid" data-case-bind="paid_amount"><?= number_format($doctorFinancialSummary['paid']) ?></span>
                        <span class="ctc-sep"></span>
                        <span class="ctc-label">ماوە</span>
                        <span class="ctc-value<?= $remaining > 0.009 ? ' is-due' : '' ?>" data-case-bind="remaining"><?= number_format($remaining) ?></span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 align-center flex-wrap">
                <?php if ($canRequestPayment): ?>
                <button class="btn btn-success" style="padding: 8px 16px;" onclick="openDialog('paymentDialog')" title="<?= $canReceiveMoney ? 'وەرگرتنی پارە (Alt+P)' : 'داواکاری پارەدان (Alt+P)' ?>">
                    <i class="fas fa-money-bill"></i> <?= $canReceiveMoney ? 'پارەدان' : ($doctorPaymentDraft ? 'دەستکاری / ناردنی قەبز' : 'داواکاری پارەدانی نوێ') ?>
                </button>
                <?php elseif ($case['status'] === 'open' && $pendingPaymentsTotal > 0 && $remaining > 0): ?>
                <span class="btn btn-warning" style="padding: 8px 16px; cursor:default;" title="داواکاری پارەدان چاوەڕوانی پەسەندکردنە">
                    <i class="fas fa-hourglass-half"></i> پارەدان چاوەڕوانە (<?= number_format($pendingPaymentsTotal) ?>)
                </span>
                <?php endif; ?>
                <?php if (($isDoctor || $isAdmin) && $case['status'] === 'open'): ?>
                <button class="btn btn-outline" style="padding: 8px 16px;" onclick="openAppointmentDialog()" title="دانانی مەوعید بۆ نەخۆش (Alt+A)">
                    <i class="fas fa-calendar-plus"></i> مەوعید
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" style="padding: 8px 16px;" onclick="openReceiptDialog()" title="چاپکردنی وەسڵ">
                    <i class="fas fa-print"></i> چاپکردنی وەسڵ
                </button>
                <?php // Accounting control — ONE per role. Doctors use the green
                      // "complete case" floating button (bottom); admins send here. ?>
                <?php if ($isAdmin && $showLegacyCaseAccountingActions): ?>
                    <?php if ($canSendToAccounting): ?>
                    <button class="btn btn-warning" style="padding: 8px 16px;" onclick="openDialog('sendAccountingDialog')" title="ناردن بۆ ژمێریار">
                        <i class="fas fa-paper-plane"></i> ناردن بۆ ژمێریار
                    </button>
                    <?php elseif (!empty($accountingReport) && $accountingReport['status'] === 'processed'): ?>
                    <span class="btn btn-success" style="padding: 8px 16px; cursor:default;" title="ژمێریار کاری تێکردووە">
                        <i class="fas fa-check-circle"></i> جێبەجێکراوە
                    </span>
                    <?php elseif (!empty($accountingReport)): ?>
                    <?php // Already sent, still waiting — allow a follow-up note/resend ?>
                    <button class="btn btn-warning" style="padding: 8px 16px;" onclick="openDialog('resendAccountingDialog')" title="ناردنی تێبینی نوێ بۆ ژمێریار">
                        <i class="fas fa-comment-dots"></i> ناردنی تێبینی بۆ ژمێریار
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($auth->canRequestXray()): ?>
                <a href="<?= APP_URL ?>/xray/request.php?patient_id=<?= $case['patient_id'] ?>&case_id=<?= $caseId ?>" class="btn btn-secondary" style="padding: 8px 16px;" title="داواکاری ئێکسڕەی">
                    <i class="fas fa-x-ray"></i> ئێکسڕەی
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php // The case-level money cards used to sit here. Money is settled
              // per receipt now (see the وەسڵەکان panel + the visit tabs), so a
              // case-wide total at the top of the page was misleading. ?>
    </div>
</div>

<style>
/* Next appointment as a small chip on the patient line (the old money
   stat cards above it are gone — money is per receipt now). */
.next-appt-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-right: 8px;
    padding: 2px 9px;
    border: 1px solid #cfe0f0;
    border-radius: 999px;
    background: #f2f8fd;
    color: #0f6cbd;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
    vertical-align: middle;
}
.next-appt-chip i { font-size: 10px; }
/* Case total — the one number kept from the old پوختەی دارایی panel. */
.case-total-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 7px;
    padding: 4px 12px;
    border: 1px solid var(--border-color, #e1dfdd);
    border-radius: 8px;
    background: var(--bg-secondary, #faf9f8);
    white-space: nowrap;
}
.case-total-chip > i { font-size: 11px; color: var(--text-muted, #8a8886); }
.ctc-label { font-size: 11.5px; color: var(--text-secondary, #605e5c); }
.ctc-value { font-size: 15px; font-weight: 700; color: var(--text-primary, #201f1e); }
.ctc-value.is-paid { color: #14713b; }
.ctc-value.is-due  { color: #c62026; }
/* Hairline between the three figures so they do not read as one number */
.ctc-sep {
    width: 1px;
    align-self: stretch;
    min-height: 16px;
    margin: 0 2px;
    background: var(--border-color, #e1dfdd);
}
.ctc-edit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    border-radius: 5px;
    background: transparent;
    color: var(--status-warning, #d83b01);
    font-size: 11px;
    cursor: pointer;
}
.ctc-edit:hover { background: var(--border-light, #edebe9); }
/* Visit-tab + receipt-pane rules live in assets/css/invoice-pane.css,
   shared with the accountant screen. Page-specific rules only below. */

/* The shared rule collapses the summary column at 900px, but this page also
   carries the ~320px nav pane and a ~964px odontogram: with the 320px summary
   column still in place, anything under ~1400px leaves the chart well under
   half the window. Give the chart the full width below that instead. */
@media (max-width: 1400px) {
    .collapsible-sidebar-grid { grid-template-columns: 1fr !important; gap: 16px !important; }
}
@media (max-width: 767px) {
    .next-appt-chip { margin: 4px 0 0; }
    .tabs-container { -webkit-overflow-scrolling: touch; scroll-snap-type: x proximity; }
    .tabs-container .tab { scroll-snap-align: center; padding: 14px 12px 13px; }
    /* Chart toolbar: title, dentition filters and action buttons on their own
       lines instead of one row that runs off the screen. */
    #tab-chart .panel-body > .d-flex.justify-between { flex-direction: column; align-items: stretch; gap: 10px; }
    #tab-chart .panel-body > .d-flex.justify-between > div { justify-content: flex-start; }
}

/* دەرمان tab */
.rx-search-wrap { position: relative; margin-bottom: 14px; }
.rx-label { display:block; font-size:12px; color:#666; margin-bottom:5px; font-weight:600; }
.rx-input { width:100%; border:1.5px solid #e0e0e0; border-radius:8px; padding:9px 11px; font:13px inherit; background:#fff; color:#222; }
.rx-input:focus { outline:none; border-color:#0078d4; box-shadow:0 0 0 3px rgba(0,120,212,.1); }
.rx-ac { position:absolute; z-index:60; left:0; right:0; background:#fff; border:1px solid #ccc; border-radius:8px;
         box-shadow:0 8px 22px rgba(0,0,0,.16); max-height:280px; overflow:auto; margin-top:4px; }
.rx-ac-item { padding:9px 12px; cursor:pointer; border-bottom:1px solid #f2f2f2; display:flex; justify-content:space-between; gap:10px; }
.rx-ac-item:hover, .rx-ac-item.on { background:#e8f4fd; }
.rx-ac-item .nm { font-size:13px; font-weight:600; }
.rx-ac-item .mt { font-size:11px; color:#888; }
.rx-ac-item .st { font-size:12px; color:#2e7d32; font-weight:700; direction:ltr; white-space:nowrap; }
.rx-table-wrap { border:1px solid #e6e6e6; border-radius:8px; overflow:hidden; }
.rx-table { width:100%; border-collapse:collapse; font-size:13px; }
.rx-table th { background:#f8f9fa; padding:9px 10px; text-align:right; font-size:12px; font-weight:600; color:#444; border-bottom:1px solid #e0e0e0; }
.rx-table td { padding:6px 10px; border-bottom:1px solid #f2f2f2; }
.rx-table tr:last-child td { border-bottom:0; }
.rx-table input { width:100%; border:1px solid #e2e2e2; border-radius:6px; padding:6px 8px; font:13px inherit; }
.rx-table input:focus { outline:none; border-color:#0078d4; }
.rx-del { color:#c62828; cursor:pointer; text-align:center; }
.rx-stock { direction:ltr; text-align:left; font-variant-numeric:tabular-nums; color:#2e7d32; font-weight:600; }
.rx-empty { text-align:center; color:#999; padding:22px 12px; font-size:13px; }
.rx-foot { display:flex; gap:10px; align-items:center; margin-top:12px; }
.rx-foot .rx-input { flex:1; }
.rx-msg { margin-top:8px; font-size:12px; }
.rx-msg.ok { color:#2e7d32; }
.rx-msg.err { color:#c62828; }
.rx-msg.warn { color:#b45309; font-weight:600; }
.rx-out { color:#c62828; font-weight:700; }
.rx-preset { display:block; width:100%; margin-top:4px; border:1px solid #c7d2fe; border-radius:6px; padding:4px 6px; font:12px inherit; background:#f5f7ff; color:#3730a3; }
.rx-form { display:inline-block; margin-inline-start:6px; font-size:11px; font-weight:700; padding:1px 7px; border-radius:999px; background:#e0f2fe; color:#0369a1; }

/* the paper prescription sheet — laid out left to right exactly like the
   printed one: Antibiotic, Analgesic, Toothpaste, Brush, Mouth Wash, Other */
.rxs { border:1px solid #cfe3ef; border-radius:12px; padding:14px; background:#fff; margin-bottom:14px; }
.rxs-top { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding-bottom:10px; margin-bottom:12px; border-bottom:2px solid #2b7bb0; }
.rxs-pt { font-size:16px; font-weight:800; color:#0f172a; direction:ltr; }
.rxs-pt bdi { font-weight:700; color:#0369a1; }
.rxs-find { position:relative; margin-inline-start:auto; width:min(260px, 100%); }
.rxs-find i { position:absolute; top:50%; inset-inline-start:10px; transform:translateY(-50%); color:#94a3b8; font-size:12px; }
.rxs-find input { width:100%; height:34px; border:1.5px solid #dbe3ea; border-radius:9px; padding-inline:30px 10px; font:13px inherit; }
.rxs-find input:focus { outline:none; border-color:#2b7bb0; }
.rxs-legend { font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; }
.rxs-dot { width:8px; height:8px; border-radius:50%; background:#f59e0b; display:inline-block; }
/* six across like the paper when the tab is wide; otherwise an even 3 + 3 */
.rxs { container-type:inline-size; }
.rxs-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:6px; direction:ltr; text-align:left; }
@container (min-width: 1050px) { .rxs-grid { grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); } }
@container (max-width: 560px)  { .rxs-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
.rxs-col { display:flex; flex-direction:column; min-width:0; }
.rxs-head { background:#bde0f2; padding:10px 12px 9px; min-height:122px; }
.rxs-head h5 { margin:0 0 8px; font-size:22px; font-weight:800; color:#0b0b0b; text-align:center; letter-spacing:.2px; }
.rxs-types { display:grid; grid-template-columns:1fr 1fr; gap:3px 8px; }
.rxs-t { display:flex; align-items:center; gap:6px; border:0; background:none; padding:1px 0; font:14px inherit; color:#111; cursor:pointer; text-align:left; }
.rxs-t::before { content:""; width:15px; height:15px; flex:0 0 auto; border-radius:50%; border:1.5px solid #2e7d32; background:#fff; box-sizing:border-box; }
.rxs-t.on { font-weight:800; }
.rxs-t.on::before { background:#2e7d32; box-shadow:inset 0 0 0 2.5px #fff; }
.rxs-body { background:#dff0f8; padding:8px 8px 10px; margin-top:6px; flex:1; }
.rxs-it { position:relative; display:flex; align-items:center; gap:7px; padding:4px 5px; border-radius:6px; cursor:pointer; font-size:13.5px; color:#111; line-height:1.35; user-select:none; }
.rxs-it:hover { background:#cbe6f3; }
.rxs-it input { position:absolute; opacity:0; pointer-events:none; }
.rxs-it .bx { width:18px; height:18px; flex:0 0 auto; border:1.5px solid #2e7d32; background:#fff; display:grid; place-items:center; color:#fff; font-size:11px; }
.rxs-it.on { background:#c8e6c9; font-weight:700; }
.rxs-it.on .bx { background:#2e7d32; }
.rxs-it input:focus-visible + .bx { outline:2px solid #2b7bb0; outline-offset:2px; }
.rxs-it .nm { min-width:0; overflow:hidden; text-overflow:ellipsis; }
.rxs-it .tp { margin-left:auto; font-size:10.5px; font-weight:700; color:#0369a1; white-space:nowrap; }
/* stock on the pharmacy shelf, beside every name: green with the count, amber 0 when out */
.rxs-q { flex:0 0 auto; min-width:22px; height:18px; padding:0 6px; border-radius:9px; display:inline-grid; place-items:center;
    background:#dcfce7; color:#166534; font-size:11px; font-weight:800; line-height:1; direction:ltr; font-variant-numeric:tabular-nums; }
.rxs-q.zero { background:#fef3c7; color:#b45309; }
.rxs-legend .rxs-q { margin-inline-start:4px; }
.rxs-it.dim { opacity:.32; }
/* a ticked item: its dose under the name, and the dose button beside it */
.rxs-it .nmw { display:flex; flex-direction:column; min-width:0; flex:1; }
.rxs-it .nmw .nm { overflow:visible; white-space:normal; }
.rxs-it.on .rxs-dose { margin-left:auto; }
.rxs-it .ds { font-size:11px; font-weight:600; color:#0f766e; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; direction:rtl; text-align:left; }
.rxs-it .ds.none { color:#b45309; }
.rxs-dose { flex:0 0 auto; width:24px; height:24px; padding:0; border-radius:7px; border:1px solid #99f6e4; background:#fff; color:#0f766e;
    display:grid; place-items:center; font-size:11px; cursor:pointer; transition:background .12s, color .12s; }
.rxs-dose:not(.set) { border-color:#fcd34d; color:#b45309; background:#fffbeb; }
.rxs-dose:hover, .rxs-dose:focus-visible { background:#0f766e; border-color:#0f766e; color:#fff; outline:none; }
/* the dose panel */
.rxs-pop { position:fixed; z-index:1300; width:330px; max-width:calc(100vw - 16px); padding:10px; direction:rtl; background:#fff;
    border:1px solid #cfe3ef; border-radius:14px; box-shadow:0 22px 48px -16px rgba(15,23,42,.45); }
.rxs-pop[hidden] { display:none; }
.rxs-pop .hd { display:flex; align-items:center; gap:7px; padding:0 2px 8px; margin-bottom:8px; border-bottom:1px solid #eef2f6; }
.rxs-pop .hd > i { color:#0f766e; }
.rxs-pop .hd bdi { font-size:14px; font-weight:800; color:#0f172a; }
.rxs-pop .hd .tp { font-size:10.5px; font-weight:800; padding:1px 7px; border-radius:999px; background:#e0f2fe; color:#0369a1; }
.rxs-pop .hd .x { margin-inline-start:auto; width:26px; height:26px; border:0; border-radius:8px; background:#f1f5f9; color:#64748b; font-size:16px; line-height:1; cursor:pointer; }
.rxs-pop .ps { display:grid; gap:5px; margin-bottom:8px; }
.rxs-pop .pr { display:flex; align-items:center; gap:7px; width:100%; padding:8px 9px; border:1.5px solid #e2e8f0; border-radius:10px;
    background:#f8fafc; text-align:start; font:12.5px inherit; color:#1e293b; cursor:pointer; }
.rxs-pop .pr:hover, .rxs-pop .pr:focus-visible { border-color:#0f766e; background:#f0fdfa; outline:none; }
.rxs-pop .pr.on { border-color:#0f766e; background:#ccfbf1; font-weight:700; }
.rxs-pop .pr .tx { flex:1; min-width:0; }
.rxs-pop .pr > i { color:#0f766e; }
.rxs-pop .pr .lb { flex:0 0 auto; padding:1px 7px; border-radius:999px; background:#ede9fe; color:#5b21b6; font-size:10.5px; font-weight:800; }
.rxs-pop .nops { margin-bottom:8px; font-size:12px; color:#94a3b8; }
.rxs-pop .cu { display:flex; gap:6px; }
.rxs-pop .cu input { flex:1; min-width:0; height:36px; padding:0 9px; border:1.5px solid #dbe3ea; border-radius:9px; font:13px inherit; }
.rxs-pop .cu input:focus { outline:none; border-color:#0f766e; box-shadow:0 0 0 3px rgba(15,118,110,.12); }
.rxs-pop .cu .ok { width:36px; height:36px; border:0; border-radius:9px; background:#0f766e; color:#fff; cursor:pointer; }
.rxs-pop .qt { display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12.5px; font-weight:700; color:#475569; }
.rxs-pop .qt button { width:30px; height:30px; border:1px solid #dbe3ea; border-radius:8px; background:#fff; font-size:16px; font-weight:800; cursor:pointer; }
.rxs-pop .qt button:hover { border-color:#0f766e; color:#0f766e; }
.rxs-pop .qt b { min-width:24px; text-align:center; font-size:15px; color:#0f172a; direction:ltr; }
.rxs-pop .qt small { color:#94a3b8; font-weight:600; }
.rxs-it.gone { display:none; }
.rxs-doc { margin-top:16px; text-align:center; direction:rtl; }
.rxs-doc h5 { margin:0 0 4px; font-size:20px; font-weight:800; color:#0b0b0b; direction:ltr; }
.rxs-doc div { font-size:13px; font-weight:700; color:#0369a1; border-bottom:1px dotted #94a3b8; padding-bottom:6px; }
.rx-history .rx-card { border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; margin-bottom:8px; }
.rx-history .rx-card .top { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.rx-history .rx-card .items { font-size:12px; color:#666; margin-top:6px; line-height:1.8; }
.rx-badge { border-radius:10px; padding:2px 9px; font-size:11px; font-weight:700; }
.rx-badge.pending { background:#fff4e5; color:#8a5a00; }
.rx-badge.sold { background:#e8f5e9; color:#2e7d32; }
.rx-badge.cancelled { background:#f5f5f5; color:#777; }
.rx-badge.outside { background:#ede9fe; color:#5b21b6; }
.rx-card-print { width:28px; height:28px; border:1px solid #cfe3ef; border-radius:7px; background:#fff; color:#0369a1; display:inline-grid; place-items:center; font-size:12px; text-decoration:none; }
.rx-card-print:hover { background:#e0f2fe; }
.rx-card-del { width:28px; height:28px; border:1px solid #f1c4c4; border-radius:7px; background:#fff; color:#c62828; cursor:pointer; font-size:12px; }
.rx-card-del:hover { background:#fff1f2; }
@media (max-width: 767px) {
    .rx-foot { flex-direction: column; align-items: stretch; }
}
</style>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 1fr 320px; gap: 24px;">
    <div>
        <!-- Tabs -->
        <div class="panel">
            <div class="tabs-container">
                <button type="button" class="tab active" onclick="showTab('chart', this)">
                    <i class="fas fa-tooth" aria-hidden="true"></i>
                    <span class="tab-label">چارتی ددان</span>
                </button>
                <?php // Treatments are the record of work done, not an injection. ?>
                <button type="button" class="tab" data-case-tab="treatments" onclick="showTab('treatments', this)">
                    <i class="fas fa-tasks" aria-hidden="true"></i>
                    <span class="tab-label">چارەسەرەکان</span>
                    <span class="tab-count" data-invoice-count><?= count($invoiceTabs) ?></span>
                </button>
                <button type="button" class="tab" data-case-tab="payments" onclick="showTab('payments', this, true)">
                    <i class="fas fa-money-bill" aria-hidden="true"></i>
                    <span class="tab-label">پارەدان</span>
                    <span class="tab-count"><?= count($payments) + count($chairSideReceipts) ?></span>
                </button>
                <button type="button" class="tab" onclick="showTab('images', this)">
                    <i class="fas fa-images" aria-hidden="true"></i>
                    <span class="tab-label">وێنەکان</span>
                    <span class="tab-count"><?= count($caseImages) ?><span class="cap-max">/6</span></span>
                </button>
                <?php if ($canPrescribe || $canSeePrescriptions): ?>
                <button type="button" class="tab" onclick="showTab('medicine', this)">
                    <i class="fas fa-pills" aria-hidden="true"></i>
                    <span class="tab-label">دەرمان</span>
                    <span class="tab-count" id="rxTabCount">0</span>
                </button>
                <?php endif; ?>
                <?php if ($isArchCase): ?>
                <button type="button" class="tab" onclick="showTab('logs', this)">
                    <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                    <span class="tab-label">کارە ئەنجامدراوەکان</span>
                    <span class="tab-count"><?= count($procedureLogs) ?></span>
                </button>
                <?php endif; ?>
            </div>

            <!-- Dental Chart Tab -->
            <div class="tab-content active" id="tab-chart" style="display: block;">
                <div class="panel-body" style="padding: 12px;">
                    <div class="d-flex justify-between align-center mb-2">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <h5 style="margin: 0; font-size: 14px;">
                                <?php if (($case['scope_type'] ?? 'tooth') === 'arch'): ?>
                                <i class="fas fa-teeth" style="color: #0078d4;"></i> چارتی ئەرک
                                <span class="badge badge-blue" style="font-size: 10px; padding: 3px 8px; margin-right: 8px;">
                                    <?= $case['selected_arch'] === 'both' ? 'هەردوو ئەرک' : ($case['selected_arch'] === 'upper' ? 'سەرەوە' : 'خوارەوە') ?>
                                </span>
                                <?php else: ?>
                                چارتی ددان
                                <?php endif; ?>
                            </h5>

                            <?php if (($case['scope_type'] ?? 'tooth') !== 'arch'): ?>
                            <!-- Both charts are always active: permanent (32 teeth, 1-8) and
                                 primary (20 teeth, A-E) render together for mixed dentition.
                                 These buttons only fold a pane away on screen. -->
                            <div id="dentitionForm" class="dentition-switch" role="group" aria-label="پیشاندانی چارتەکان">
                                <button type="button" data-dentition="permanent"
                                        class="dentition-opt is-active" aria-pressed="true"
                                        onclick="toggleDentitionPane('permanent')"
                                        title="ددانە هەمیشەییەکان — ٣٢ ددان (پیشاندان/شاردنەوە)">
                                    <i class="fas fa-tooth"></i> گەورە
                                </button>
                                <button type="button" data-dentition="primary"
                                        class="dentition-opt is-active" aria-pressed="true"
                                        onclick="toggleDentitionPane('primary')"
                                        title="ددانی شیری — ٢٠ ددان (پیشاندان/شاردنەوە)">
                                    <i class="fas fa-child"></i> منداڵ
                                </button>
                            </div>
                            <span style="font-size: 11px; color: #8a97a5;">
                                <i class="fas fa-circle-info"></i> هەردوو چارت کاراییە
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($case['status'] === 'open'): ?>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <?php if (($case['scope_type'] ?? 'tooth') !== 'arch'): ?>
                            <button type="button" id="bulkModeBtn" class="btn btn-sm" onclick="toggleBulkMode()"
                                    style="background: #0d9488; border-color: #0d9488; color: #fff;"
                                    title="چەند ددان هەڵبژێرە و یەک چارەسەریان بۆ زیاد بکە">
                                <i class="fas fa-object-group"></i> هەڵبژاردنی چەند ددان
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm" onclick="openManageTreatmentsDialog()"
                                    style="background: #6d28d9; border-color: #6d28d9; color: #fff;"
                                    title="زیادکردن، دەستکاری و سڕینەوەی جۆرەکانی چارەسەر">
                                <i class="fas fa-list-check"></i> جۆری چارەسەر
                            </button>
                            <button class="btn btn-primary btn-sm" onclick="<?= ($case['scope_type'] ?? 'tooth') === 'arch' ? 'openArchTreatmentDialog()' : 'openDialog(\'addTreatmentDialog\')' ?>">
                                <i class="fas fa-plus"></i> زیادکردن
                            </button>
                        </div>
                        <?php else: ?>
                        <button class="btn btn-secondary btn-sm" disabled title="کەیسەکە داخراوە">
                            <i class="fas fa-plus"></i> زیادکردن
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($case['status'] === 'open' && ($case['scope_type'] ?? 'tooth') !== 'arch'): ?>
                    <!-- Always-on tip: how to multi-select + right-click -->
                    <div style="margin: 0 0 10px; padding: 7px 12px; background: #f8fafc; border: 1px solid #e6ebf1; border-radius: 8px; color: #64748b; font-size: 11.5px;">
                        <i class="fas fa-lightbulb" style="color:#0d9488;"></i>
                        <strong>Ctrl+کلیک</strong> یان <strong>Shift+کلیک</strong> بۆ هەڵبژاردنی چەند ددان،
                        پاشان <strong>کلیکی ڕاست</strong> بکە بۆ لیستی کردارەکان. کلیکی ئاسایی وردەکاری دەکاتەوە.
                    </div>
                    <!-- Bulk-select hint banner (shown while touch multi-select mode is ON) -->
                    <div id="bulkModeHint" style="display: none; margin: 0 0 10px; padding: 8px 12px; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; color: #0f766e; font-size: 12px;">
                        <i class="fas fa-hand-pointer"></i> کلیک لەسەر ددانەکان بکە بۆ هەڵبژاردن، پاشان کلیکی ڕاست بکە یان دوگمەی سەوز لە خوارەوە دابگرە.
                    </div>
                    <?php endif; ?>

                    <!-- Whole-arch and toothless service work cannot be painted
                         on one tooth. This compact strip is part of the chart,
                         so those jobs stay visible without fake tooth numbers. -->
                    <div id="chartServiceWork" class="chart-service-work" aria-live="polite"></div>

                    <?php if (($case['scope_type'] ?? 'tooth') === 'arch'): ?>
                    <!-- Arch View Chart -->
                    <div class="arch-view-chart">
                        <?php
                        // Calculate treatments for each arch
                        $upperTeeth = array_merge($teeth['upper_right'], $teeth['upper_left']);
                        $lowerTeeth = array_merge($teeth['lower_right'], $teeth['lower_left']);

                        $upperTreatmentCount = 0;
                        $lowerTreatmentCount = 0;
                        $upperTotalPrice = 0;
                        $lowerTotalPrice = 0;

                        foreach ($caseTreatments as $ct) {
                            if (in_array($ct['tooth_number'], $upperTeeth)) {
                                $upperTreatmentCount++;
                                $upperTotalPrice += $ct['final_price'];
                            } elseif (in_array($ct['tooth_number'], $lowerTeeth)) {
                                $lowerTreatmentCount++;
                                $lowerTotalPrice += $ct['final_price'];
                            }
                        }

                        $showUpper = in_array($case['selected_arch'], ['upper', 'both']);
                        $showLower = in_array($case['selected_arch'], ['lower', 'both']);
                        ?>

                        <!-- Occlusal (top-view) arch chart: gums + anatomical teeth, drawn by JS -->
                        <div class="arch-occ-topbar">
                            <span class="arch-occ-topbar-label"><i class="fas fa-th-large"></i> چارتی ئەرک</span>
                            <span class="arch-occ-chip">
                                <?= $case['selected_arch'] === 'upper' ? 'ئەرکی سەرەوە' : ($case['selected_arch'] === 'lower' ? 'ئەرکی خوارەوە' : 'هەردوو ئەرک') ?>
                            </span>
                        </div>

                        <?php if ($showUpper): ?>
                        <div class="arch-occ-panel" id="archPanel-upper">
                            <div class="arch-occ-chart" data-arch="upper"></div>
                            <div class="arch-occ-info">
                                <div class="arch-occ-title">
                                    <span class="arch-occ-mouth arch-occ-mouth--upper"></span>
                                    <div>
                                        <h4>ئەرکی سەرەوە (Maxilla)</h4>
                                        <small>قەوسی سەرەوە — 16 ددان</small>
                                    </div>
                                </div>
                                <div class="arch-occ-stats" data-arch-stats="upper"></div>
                                <div class="arch-occ-statusbar" data-arch-status="upper"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($showLower): ?>
                        <div class="arch-occ-panel" id="archPanel-lower">
                            <div class="arch-occ-chart" data-arch="lower"></div>
                            <div class="arch-occ-info">
                                <div class="arch-occ-title">
                                    <span class="arch-occ-mouth arch-occ-mouth--lower"></span>
                                    <div>
                                        <h4>ئەرکی خوارەوە (Mandible)</h4>
                                        <small>قەوسی خوارەوە — 16 ددان</small>
                                    </div>
                                </div>
                                <div class="arch-occ-stats" data-arch-stats="lower"></div>
                                <div class="arch-occ-statusbar" data-arch-status="lower"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Legend (rendered by JS so it matches the arch renderer) -->
                        <div class="arch-occ-legend" id="archOccLegend"></div>
                    </div>
                    <?php else: ?>
                    <!-- Standard Tooth Chart -->
                    <div class="dental-chart-container smart-dental-chart" id="smartDentalChart">
                        <?php
                        // Both tooth sets are drawn together — a patient may be in mixed
                        // dentition, so adult and child teeth are treatable in the same case.
                        // renderSmartChart() paints every .tooth[data-quadrant] across both
                        // panes, so a folded-away pane still stays in sync.
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
                            // Left group first, then right: the RTL row puts the first group on
                            // the right, which gives the clinician's view.
                            $jaws = [
                                'upper' => ['label' => 'سەرەوە', 'toothClass' => '',
                                            'groups' => ['upper_left' => 'UL', 'upper_right' => 'UR']],
                                'lower' => ['label' => 'خوارەوە', 'toothClass' => ' lower',
                                            'groups' => ['lower_left' => 'LL', 'lower_right' => 'LR']],
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
                                        <div class="tooth<?= $jaw['toothClass'] ?>"
                                             data-tooth="<?= $num ?>"
                                             data-palmer="<?= $palmerNumbers[$num] ?>"
                                             data-quadrant="<?= $quadrant ?>"
                                             onclick="showToothDetail(<?= $num ?>)">
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

                        <!-- Legend (rendered by JS so it always matches the tooth renderer) -->
                        <div class="chart-legend" id="smartChartLegend"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Treatments Tab -->
            <div class="tab-content" id="tab-treatments" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center" style="margin-bottom: 16px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <h4 style="margin:0;">چارەسەرەکان</h4>
                            <!-- View toggle -->
                            <div style="display:flex; border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                                <button id="btnTreatTable" onclick="switchTreatView('table')" style="padding:5px 12px; font-size:12px; border:none; cursor:pointer; background:#0078d4; color:#fff; font-family:inherit;">
                                    <i class="fas fa-table"></i> جدول
                                </button>
                                <button id="btnTreatChart" onclick="switchTreatView('chart')" style="padding:5px 12px; font-size:12px; border:none; cursor:pointer; background:var(--bg-color); color:var(--text-secondary); font-family:inherit;">
                                    <i class="fas fa-chart-bar"></i> چارت
                                </button>
                            </div>
                        </div>
                        <?php // No "add treatment" button here: each visit tab
                              // carries its own quick-entry row, so work always
                              // lands on the receipt the doctor is looking at. ?>
                    </div>

                    <?php if (empty($caseTreatments) && empty($invoiceTabs) && $case['status'] !== 'open'): ?>
                    <div class="empty-state">
                        <i class="fas fa-syringe empty-state-icon"></i>
                        <p class="empty-state-title">هیچ چارەسەرێک نییە</p>
                    </div>
                    <?php else: ?>
                    <div id="treatmentTableView">
                    <?php
                    // ── Visit tabs ────────────────────────────────────────
                    // Every tab IS an invoice: a draft the doctor is filling,
                    // or one already handed to the accountant. The table below
                    // shows only the active tab's work and carries that tab's
                    // own print + send buttons, so a visit is entered, printed
                    // and sent as one unit.
                    $quadKeys = ['UR' => 'upper_right', 'UL' => 'upper_left', 'LR' => 'lower_right', 'LL' => 'lower_left'];
                    ?>
                    <div class="vt-bar"><?= render_visit_tab_bar($invoiceTabs, $activeInvoiceId, $case['status']) ?></div>

                    <?php if (empty($invoiceTabs)): ?>
                    <div class="vt-empty">
                        <i class="fas fa-receipt"></i>
                        <p>هێشتا هیچ وەسڵێک نییە. تابێکی نوێ دروست بکە و چارەسەرەکان زیاد بکە.</p>
                        <?php if ($case['status'] === 'open'): ?>
                        <button type="button" class="btn btn-primary" onclick="openNewVisitTab()">
                            <i class="fas fa-plus"></i> دروستکردنی یەکەم وەسڵ
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php
                    // One pane per visit tab. The markup lives in a partial so
                    // the AJAX add/delete response can re-render exactly the same
                    // HTML for a single pane instead of reloading the page.
                    foreach ($invoiceTabs as $tab):
                        $rows = $invoiceTreatments[(int)$tab['id']] ?? [];
                        include __DIR__ . '/partials/invoice_pane.php';
                    endforeach;
                    ?>

                    <?php if (!empty($looseTreatments)): ?>
                    <div class="vt-loose">
                        <i class="fas fa-triangle-exclamation"></i>
                        <?= count($looseTreatments) ?> چارەسەر هیچ وەسڵێکی نییە — پەڕەکە نوێ بکەرەوە یان پەیوەندی بە بەڕێوەبەر بکە.
                    </div>
                    <?php endif; ?>
                    </div><!-- /treatmentTableView -->

                    <!-- Chart View -->
                    <div id="treatmentChartView" style="display:none;">
                        <div style="background:var(--bg-color);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                                <i class="fas fa-chart-bar" style="color:#0078d4;font-size:18px;"></i>
                                <strong style="font-size:15px;">تەوەری چارەسەرەکان بە بڕی پارە</strong>
                                <span style="margin-right:auto;font-size:12px;color:var(--text-secondary);">کۆی گشتی: <?= number_format($case['total_amount'], 2) ?></span>
                            </div>
                            <?php
                            $totalForChart = max((float)$case['total_amount'], 0.01);
                            $chartColors   = ['#0078d4','#107c10','#8764b8','#ff8c00','#c50f1f','#038387','#d13438','#00b7c3','#69797e','#e3008c'];
                            $colorIdx      = 0;
                            // re-build grouped (already built above but we need it here)
                            $chartGroups   = [];
                            foreach ($caseTreatments as $ct) {
                                $k = $ct['treatment_name'] . '_' . $ct['unit_price'];
                                if (!isset($chartGroups[$k])) {
                                    $chartGroups[$k] = ['name' => $ct['treatment_name'], 'total' => 0, 'qty' => 0];
                                }
                                $chartGroups[$k]['total'] += $ct['final_price'];
                                $chartGroups[$k]['qty']   += $ct['quantity'];
                            }
                            usort($chartGroups, fn($a,$b) => $b['total'] <=> $a['total']);
                            foreach ($chartGroups as $cg):
                                $pct   = min(100, round(($cg['total'] / $totalForChart) * 100, 1));
                                $color = $chartColors[$colorIdx % count($chartColors)];
                                $colorIdx++;
                            ?>
                            <div style="margin-bottom:14px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="width:12px;height:12px;border-radius:3px;background:<?= $color ?>;display:inline-block;flex-shrink:0;"></span>
                                        <span style="font-size:13px;font-weight:600;"><?= htmlspecialchars($cg['name']) ?></span>
                                        <span style="font-size:11px;color:var(--text-secondary);">× <?= number_format($cg['qty'], 2) ?></span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <span style="font-size:12px;font-weight:700;color:<?= $color ?>;"><?= number_format($cg['total'], 2) ?></span>
                                        <span style="font-size:11px;color:var(--text-secondary);width:38px;text-align:left;"><?= $pct ?>%</span>
                                    </div>
                                </div>
                                <div style="height:10px;background:var(--bg-secondary);border-radius:6px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:6px;transition:width 0.6s ease;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payments Tab -->
            <div class="tab-content" id="tab-payments" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center" style="margin-bottom: 24px;">
                        <h4>پارەدانەکان</h4>
                        <?php if ($canRequestPayment): ?>
                        <button class="btn btn-success" onclick="openDialog('paymentDialog')">
                            <i class="fas fa-money-bill"></i> <?= $canReceiveMoney ? 'وەرگرتنی پارە' : ($doctorPaymentDraft ? 'دەستکاری / ناردنی قەبز' : 'داواکاری پارەدانی نوێ') ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($pendingPaymentsTotal > 0): ?>
                    <div class="alert alert-warning" style="margin-bottom: 16px;">
                        <i class="fas fa-hourglass-half"></i>
                        <strong>داواکاری پارەدانی چاوەڕوان:</strong>
                        <?= number_format($pendingPaymentsTotal) ?> — چاوەڕوانی پەسەندکردنی ژمێریار/ئادمینە.
                        <?php if ($unrequestedBalance <= 0 && $remaining > 0): ?>
                        ئەم بڕە هەموو ماوەی کەیس دادەپۆشێت، بۆیە داواکاری نوێ ناکرێت.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($chairSideReceipts)): ?>
                    <div class="alert alert-info" style="margin-bottom: 16px; color:#fff;">
                        <i class="fas fa-file-invoice-dollar" style="color:#fff;"></i>
                        <strong style="color:#fff;">نەقدی وەرگیراو لەسەر وەسڵ:</strong>
                        <?php $csrParts = []; foreach ($chairSideReceipts as $inv):
                            $csrParts[] = number_format($inv['declared_received_amount']) . ' — '
                                . '<a href="#tab-treatments" style="color:#fff; text-decoration:underline;" onclick="switchTab(\'treatments\'); switchVisitTab(' . (int)$inv['id'] . '); return false;">'
                                . htmlspecialchars($inv['invoice_no']) . '</a>';
                        endforeach;
                        echo implode('&nbsp;&nbsp;•&nbsp;&nbsp;', $csrParts);
                        ?>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-money-bill empty-state-icon"></i>
                        <p class="empty-state-title">هیچ پارەدانێک نییە</p>
                    </div>
                    <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ژمارە</th>
                                    <th>جۆر</th>
                                    <th>بڕ</th>
                                    <th>شێواز</th>
                                    <th>دۆخ</th>
                                    <th>بەروار</th>
                                    <th>قەبز</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $pay): ?>
                                <tr>
                                    <td><?= $pay['payment_number'] ?></td>
                                    <td>
                                        <?php
                                        $types = ['cash' => 'نەقد', 'credit' => 'قەرز', 'installment' => 'قیست'];
                                        echo $types[$pay['payment_type']] ?? $pay['payment_type'];
                                        ?>
                                    </td>
                                    <td><strong><?= number_format($pay['amount']) ?></strong>
                                        <?php if ((float)($pay['discount_amount'] ?? 0) > 0): ?>
                                        <small style="display:block;color:#9b6000">داشکاندن: <?= number_format((float)$pay['discount_amount'], 2) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $pay['payment_method'] === 'cash' ? 'نەقد' : 'کارت' ?></td>
                                    <td>
                                        <?php
                                        $statuses = [
                                            'draft' => ['badge-secondary', 'ڕەشنووس'],
                                            'pending' => ['badge-orange', 'چاوەڕوان'],
                                            'approved' => ['badge-green', 'پەسەندکراو'],
                                            'rejected' => ['badge-red', 'ڕەتکراوە']
                                        ];
                                        $st = $statuses[$pay['status']] ?? ['', $pay['status']];
                                        ?>
                                        <span class="badge <?= $st[0] ?>"><?= $st[1] ?></span>
                                    </td>
                                    <td><?= date('Y-m-d H:i', strtotime($pay['payment_date'])) ?></td>
                                    <td>
                                        <?php if (($pay['status'] ?? '') === 'draft' && $isDoctor && (int)$pay['received_by'] === (int)$currentUser['id']): ?>
                                        <form method="POST" style="display:inline;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="send_payment_draft">
                                            <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success" style="padding:3px 8px;"
                                                    title="ناردنی قەبزی ڕەشنووس بۆ ژمێریار">
                                                <i class="fas fa-paper-plane"></i> ناردن
                                            </button>
                                        </form>
                                        <?php elseif ($pay['status'] !== 'rejected'): ?>
                                        <a class="btn btn-sm btn-outline-primary" style="padding:3px 8px;"
                                           href="<?= APP_URL ?>/cases/voucher.php?payment=<?= (int)$pay['id'] ?>&autoprint=0"
                                           target="_blank" rel="noopener" title="قەبزی وەرگرتنی پارە بۆ نەخۆش">
                                            <i class="fas fa-receipt"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if ($pendingOrRejectedPlan && $pendingOrRejectedPlan['status'] === 'pending'): ?>
                    <h5 class="mt-4"><i class="fas fa-credit-card"></i> پلانی قیست</h5>
                    <div class="installment-finance-note" style="border-color:#f0ad4e;">
                        <div class="installment-finance-note-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="installment-finance-note-content">
                            <strong><span class="badge badge-orange">چاوەڕوانی پەسەندکردنی ژمێریار</span></strong>
                            <span>
                                کۆی گشتی: <strong><?= number_format($pendingOrRejectedPlan['total_amount']) ?></strong>
                                — <?= (int)$pendingOrRejectedPlan['number_of_installments'] ?> قیست
                                — پێشەکی پێشنیارکراو: <strong><?= number_format($pendingOrRejectedPlan['proposed_down_payment']) ?></strong>
                                — دەستپێک: <?= htmlspecialchars($pendingOrRejectedPlan['start_date']) ?>
                                <br>هێشتا هیچ خشتەیەک دروست نەکراوە — دوای پەسەندکردن دروست دەبێت.
                            </span>
                        </div>
                    </div>
                    <?php elseif ($pendingOrRejectedPlan && $pendingOrRejectedPlan['status'] === 'rejected'): ?>
                    <div class="alert alert-error" id="installmentRejectedNote" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                        <span><i class="fas fa-times-circle alert-icon"></i> قیستەکە ڕەتکرایەوە: <?= htmlspecialchars($pendingOrRejectedPlan['reject_reason'] ?? '') ?></span>
                        <button type="button" onclick="document.getElementById('installmentRejectedNote').remove()" style="background:none;border:none;cursor:pointer;font-size:16px;line-height:1;color:inherit;" title="داخستن"><i class="fas fa-times"></i></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($installmentPlan):
                        // Get flexible installment summary
                        $installmentSummary = $installmentManager->getPlanSummary($installmentPlan['id']);
                        $installmentPayments = $installmentManager->getPlanPayments($installmentPlan['id']);
                        $installmentCollectedAfterPlan = max(0, ($installmentSummary['total_paid'] ?? 0) - ($installmentSummary['down_payment'] ?? 0));
                        // Doctor collection-request dialog inputs — mirrors
                        // Installment::requestFlexiblePayment()'s own cap formula
                        // exactly (classes/Installment.php:769-785: plan remaining
                        // minus already-pending installment-type requests on this
                        // case) so the dialog never previews an amount the server
                        // would then reject. $payments was already loaded for the
                        // page (case-scoped), so no extra query is needed.
                        $installmentPendingReqTotal = 0.0;
                        foreach ($payments as $payRow) {
                            if (($payRow['status'] ?? '') === 'pending' && ($payRow['payment_type'] ?? '') === 'installment') {
                                $installmentPendingReqTotal += (float)$payRow['amount'];
                            }
                        }
                        $installmentAvailableToRequest = max(0, $installmentSummary['remaining_balance'] - $installmentPendingReqTotal);
                        $installmentCollectionPrefill = min((float)$installmentPlan['installment_amount'], $installmentAvailableToRequest);
                        $installmentNextDue = null;
                        foreach ($installmentSummary['schedule'] as $schRow) {
                            if ($schRow['dynamic_remaining'] > 0) { $installmentNextDue = $schRow['due_date']; break; }
                        }
                    ?>
                    <h5 class="mt-4"><i class="fas fa-credit-card"></i> پلانی قیست</h5>
                    <div class="installment-finance-note">
                        <div class="installment-finance-note-icon"><i class="fas fa-wallet"></i></div>
                        <div class="installment-finance-note-content">
                            <strong>پێشەکی</strong>
                            <span>
                                <?php if (($installmentSummary['down_payment'] ?? 0) > 0): ?>
                                    <?= number_format($installmentSummary['down_payment']) ?> تۆمارکراوە.
                                <?php else: ?>
                                    هێشتا هیچ پێشەکییەک تۆمارنەکراوە.
                                <?php endif; ?>
                                تۆمارکردنی پێشەکی تەنها لەلایەن ئادمین/هەژمار جێبەجێدەکرێت.
                            </span>
                        </div>
                    </div>

                    <div class="installment-overview-grid">
                        <div class="installment-overview-card accent-primary">
                            <span class="installment-overview-label">کۆی گشتی</span>
                            <strong class="installment-overview-value"><?= number_format($installmentSummary['total_amount']) ?></strong>
                            <span class="installment-overview-meta"></span>
                        </div>
                        <div class="installment-overview-card accent-warning">
                            <span class="installment-overview-label">پێشەکی</span>
                            <strong class="installment-overview-value"><?= number_format($installmentSummary['down_payment'] ?? 0) ?></strong>
                            <span class="installment-overview-meta">تەنها بۆ بینین</span>
                        </div>
                        <div class="installment-overview-card accent-success">
                            <span class="installment-overview-label">دراوە دوای پلان</span>
                            <strong class="installment-overview-value"><?= number_format($installmentCollectedAfterPlan) ?></strong>
                            <span class="installment-overview-meta"></span>
                        </div>
                        <div class="installment-overview-card <?= $installmentSummary['remaining_balance'] > 0 ? 'accent-danger' : 'accent-success' ?>">
                            <span class="installment-overview-label">ماوە</span>
                            <strong class="installment-overview-value"><?= number_format($installmentSummary['remaining_balance']) ?></strong>
                            <span class="installment-overview-meta"></span>
                        </div>
                        <div class="installment-overview-card accent-neutral">
                            <span class="installment-overview-label">پێشکەوتن</span>
                            <strong class="installment-overview-value"><?= $installmentSummary['progress_percent'] ?>%</strong>
                            <span class="installment-overview-meta"><?= $installmentPlan['number_of_installments'] ?> قیست</span>
                        </div>
                    </div>
                    <h5 class="mt-4"><i class="fas fa-credit-card"></i> پلانی قیست (نەرم)</h5>

                    <!-- Installment Summary Cards -->
                    <div style="display: none !important; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 16px;">
                        <div style="background: var(--primary-light); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: bold; color: var(--primary-color);">
                                <?= number_format($installmentSummary['total_amount']) ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-secondary);">کۆی گشتی</div>
                        </div>
                        <div style="background: var(--success-light); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: bold; color: var(--success-color);">
                                <?= number_format($installmentSummary['total_paid']) ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-secondary);">دراوە (+ پێشەکی)</div>
                        </div>
                        <div style="background: <?= $installmentSummary['remaining_balance'] > 0 ? 'var(--error-light)' : 'var(--success-light)' ?>; padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: bold; color: <?= $installmentSummary['remaining_balance'] > 0 ? 'var(--error-color)' : 'var(--success-color)' ?>;">
                                <?= number_format($installmentSummary['remaining_balance']) ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-secondary);">ماوە</div>
                        </div>
                        <div style="background: var(--secondary-light); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: bold; color: var(--text-primary);">
                                <?= $installmentSummary['progress_percent'] ?>%
                            </div>
                            <div style="font-size: 12px; color: var(--text-secondary);">پێشکەوتن</div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div style="background: var(--border-color); border-radius: 8px; height: 8px; margin-bottom: 16px; overflow: hidden;">
                        <div style="background: var(--success-color); height: 100%; width: <?= $installmentSummary['progress_percent'] ?>%; transition: width 0.3s;"></div>
                    </div>

                    <?php if ($installmentSummary['overdue_amount'] > 0): ?>
                    <div class="alert alert-warning" style="margin-bottom: 16px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>دواکەوتوو:</strong> <?= number_format($installmentSummary['overdue_amount']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($installmentSummary['credit_balance'] > 0): ?>
                    <div class="alert alert-success" style="margin-bottom: 16px;">
                        <i class="fas fa-plus-circle"></i>
                        <strong>باڵانسی زیادە:</strong> <?= number_format($installmentSummary['credit_balance']) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Flexible Payment Form (admin) / Collection Request (doctor) -->
                    <?php if ($case['status'] === 'open' && $installmentSummary['remaining_balance'] > 0): ?>
                    <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                        <?php if ($isAdmin): ?>
                        <h6 style="margin: 0 0 12px;"><i class="fas fa-money-bill-wave"></i> پارەدانی نەرم</h6>
                        <form method="POST" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                            <input type="hidden" name="action" value="flexible_installment_payment">
                            <input type="hidden" name="plan_id" value="<?= $installmentPlan['id'] ?>">
                            <div style="flex: 1; min-width: 120px;">
                                <label style="display: block; font-size: 12px; margin-bottom: 4px;">بڕ</label>
                                <input type="number" name="amount" class="form-control" required min="1"
                                       placeholder="هەر بڕێک" style="width: 100%;">
                            </div>
                            <div style="flex: 1; min-width: 100px;">
                                <label style="display: block; font-size: 12px; margin-bottom: 4px;">شێواز</label>
                                <select name="payment_method" class="form-control" style="width: 100%;">
                                    <option value="cash">نەقد</option>
                                    <option value="card">کارت</option>
                                    <option value="transfer">گواستنەوە</option>
                                </select>
                            </div>
                            <div style="flex: 2; min-width: 150px;">
                                <label style="display: block; font-size: 12px; margin-bottom: 4px;">تێبینی</label>
                                <input type="text" name="notes" class="form-control" placeholder="تێبینی (ئارەزوومەندانە)" style="width: 100%;">
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check"></i> پارەدان
                            </button>
                        </form>
                        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 8px;">
                            <i class="fas fa-info-circle"></i>
                            دەتوانیت هەر بڕێک بدەیت - سیستەم بەشێوەی خۆکار دابەشی دەکات بەسەر قیستەکان
                        </div>
                        <?php else: ?>
                        <h6 style="margin: 0 0 12px;"><i class="fas fa-money-bill-wave"></i> داواکاری وەرگرتنی قیست</h6>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-bottom:12px;color:var(--text-secondary);">
                            <span><i class="fas fa-wallet"></i> ماوەی پلان: <strong style="color:var(--text-primary);"><?= number_format($installmentSummary['remaining_balance']) ?></strong></span>
                            <?php if ($installmentNextDue): ?>
                            <span><i class="fas fa-calendar-alt"></i> بەرواری داهاتوو: <strong style="color:var(--text-primary);"><?= date('Y-m-d', strtotime($installmentNextDue)) ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-success" onclick="openDialog('installmentCollectionDialog')">
                            <i class="fas fa-money-bill-wave"></i> داواکاری وەرگرتنی قیست
                        </button>
                        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 8px;">
                            <i class="fas fa-info-circle"></i>
                            داواکارییەکە دەچێتە لای ژمێریار بۆ پەسەندکردن — دوای پەسەندکردن دابەش دەکرێت بەسەر قیستەکان
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Schedule Table with Dynamic Status -->
                    <?php if (!empty($installmentSummary['schedule'])): ?>
                    <h6 style="margin: 16px 0 8px;"><i class="fas fa-calendar-alt"></i> خشتەی قیستەکان</h6>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>بەروار</th>
                                    <th>پلان</th>
                                    <th>دابەشکراو</th>
                                    <th>ماوە</th>
                                    <th>دۆخ</th>
                                    <?php if ($case['status'] === 'open'): ?>
                                    <th>کردار</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($installmentSummary['schedule'] as $sch): ?>
                                <tr class="<?= $sch['is_overdue'] ? 'row-warning' : '' ?>">
                                    <td><strong><?= $sch['installment_number'] ?></strong></td>
                                    <td>
                                        <span class="<?= $sch['is_overdue'] ? 'text-error' : '' ?>">
                                            <?= date('Y-m-d', strtotime($sch['due_date'])) ?>
                                        </span>
                                        <?php if ($sch['is_overdue']): ?>
                                            <i class="fas fa-exclamation-circle text-error" style="font-size: 10px;"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($sch['amount']) ?></td>
                                    <td class="text-success"><strong><?= number_format($sch['dynamic_paid']) ?></strong></td>
                                    <td class="<?= $sch['dynamic_remaining'] > 0 ? 'text-error' : 'text-success' ?>">
                                        <strong><?= number_format($sch['dynamic_remaining']) ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        $iStatuses = [
                                            'pending' => ['badge-orange', 'چاوەڕوان'],
                                            'paid' => ['badge-green', 'دراوە'],
                                            'partial' => ['badge-blue', 'بەشێک'],
                                            'overdue' => ['badge-red', 'دواکەوتوو'],
                                            'skipped' => ['badge-secondary', 'ڕاپەڕێندراو']
                                        ];
                                        $ist = $iStatuses[$sch['dynamic_status']] ?? ['', $sch['dynamic_status']];
                                        ?>
                                        <span class="badge <?= $ist[0] ?>"><?= $ist[1] ?></span>
                                    </td>
                                    <?php if ($case['status'] === 'open'): ?>
                                    <td>
                                        <?php if ($sch['dynamic_remaining'] > 0 && $sch['dynamic_status'] !== 'paid'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت دەتەوێت <?= number_format($sch['dynamic_remaining']) ?> بگوازیتەوە بۆ مانگی داهاتوو؟');">
                                            <input type="hidden" name="action" value="skip_installment_month">
                                            <input type="hidden" name="schedule_id" value="<?= $sch['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="ڕاپەڕاندن - گواستنەوەی ماوە بۆ مانگی داهاتوو">
                                                <i class="fas fa-forward"></i>
                                            </button>
                                        </form>
                                        <?php elseif ($sch['dynamic_status'] === 'paid'): ?>
                                        <span class="text-success"><i class="fas fa-check"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Payment History -->
                    <?php if (!empty($installmentPayments)): ?>
                    <h6 style="margin: 20px 0 8px;"><i class="fas fa-history"></i> مێژووی پارەدانەکان</h6>
                    <div class="table-container">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>بەروار</th>
                                    <th>بڕ</th>
                                    <th>شێواز</th>
                                    <th>تێبینی</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($installmentPayments as $ipay):
                                    $methods = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'تر', 'advance' => 'لە ئامانەت'];
                                ?>
                                <tr>
                                    <td><?= date('Y-m-d H:i', strtotime($ipay['paid_at'])) ?></td>
                                    <td class="text-success"><strong><?= number_format($ipay['amount']) ?></strong></td>
                                    <td><?= $methods[$ipay['payment_method']] ?? $ipay['payment_method'] ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars($ipay['notes'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($installmentSummary['down_payment'] > 0): ?>
                    <div style="text-align: center; color: var(--text-secondary); padding: 16px;">
                        <i class="fas fa-info-circle"></i> تەنها پارەی پێشەکی: <?= number_format($installmentSummary['down_payment']) ?>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isArchCase): ?>
            <!-- Logs Tab - Procedure Logs for Arch Cases -->
            <div class="tab-content" id="tab-logs" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center" style="margin-bottom: 24px;">
                        <h4><i class="fas fa-clipboard-list"></i> کارە ئەنجامدراوەکان (Log)</h4>
                        <?php if ($case['status'] === 'open'): ?>
                        <button class="btn btn-primary" onclick="openProcedureLogDialog()">
                            <i class="fas fa-plus"></i> زیادکردنی کار
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Procedure Stats Summary -->
                    <?php if (!empty($procedureStats) && $procedureStats['total_visits'] > 0): ?>
                    <div class="procedure-stats-row">
                        <div class="procedure-stat-card">
                            <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?= $procedureStats['total_visits'] ?></span>
                                <span class="stat-label">کۆی سەردانەکان</span>
                            </div>
                        </div>
                        <div class="procedure-stat-card">
                            <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?= number_format($procedureStats['total_charged'] ?? 0) ?></span>
                                <span class="stat-label">کۆی نرخەکان</span>
                            </div>
                        </div>
                        <div class="procedure-stat-card">
                            <div class="stat-icon <?= $remaining > 0 ? 'red' : 'green' ?>"><i class="fas fa-wallet"></i></div>
                            <div class="stat-info">
                                <span class="stat-value" data-case-bind="remaining"><?= number_format($remaining) ?></span>
                                <span class="stat-label">ماوە</span>
                            </div>
                        </div>
                        <?php if ($procedureStats['next_appointment']): ?>
                        <div class="procedure-stat-card">
                            <div class="stat-icon purple"><i class="fas fa-clock"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?= date('Y/m/d', strtotime($procedureStats['next_appointment'])) ?></span>
                                <span class="stat-label">جاری داهاتوو</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($remaining > 0 && $case['status'] === 'open'): ?>
                    <div style="text-align: center; margin-top: 10px;">
                        <a href="#tab-payments" class="btn btn-sm btn-outline-primary" onclick="switchTab('payments'); return false;">
                            <i class="fas fa-credit-card"></i> پارەدان
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($archWorkReceipts)): ?>
                    <!-- وەسڵەکانی کارە ئەنجامدراوەکان — each one is a document the
                         accountant received. They carry no prices: an arch visit
                         adds no charge, the money on them is the patient's
                         existing debt that the accountant collected against. -->
                    <div class="awr-strip">
                        <?php foreach ($archWorkReceipts as $awr): ?>
                        <a class="awr-chip is-<?= htmlspecialchars($awr['status']) ?>"
                           href="<?= APP_URL ?>/cases/arch-work-receipt.php?receipt=<?= (int)$awr['id'] ?>&autoprint=0"
                           target="_blank" rel="noopener"
                           title="کردنەوەی وەسڵ">
                            <?php if ($awr['status'] === 'approved'): ?><i class="fas fa-check-double"></i>
                            <?php elseif ($awr['status'] === 'returned'): ?><i class="fas fa-undo"></i>
                            <?php else: ?><i class="fas fa-paper-plane"></i><?php endif; ?>
                            <span dir="ltr"><?= htmlspecialchars($awr['receipt_no']) ?></span>
                            <small><?= (int)$awr['work_count'] ?> کار</small>
                            <?php if ((float)$awr['paid_amount'] > 0.009): ?>
                            <small class="awr-paid">دراو <?= number_format((float)$awr['paid_amount']) ?></small>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($procedureLogs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list empty-state-icon"></i>
                        <p class="empty-state-title">هیچ تۆماری کار نییە</p>
                        <p class="empty-state-subtitle">دەتوانیت کارە ئەنجامدراوەکانی نەخۆش تۆمار بکەیت</p>
                        <?php if ($case['status'] === 'open'): ?>
                        <button class="btn btn-primary mt-2" onclick="openProcedureLogDialog()">
                            <i class="fas fa-plus"></i> زیادکردنی کاری یەکەم
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="procedure-logs-list">
                        <?php foreach ($procedureLogs as $log): ?>
                        <div class="procedure-log-card" data-log-id="<?= $log['id'] ?>">
                            <div class="log-header">
                                <div class="log-type">
                                    <span class="log-type-badge <?= $log['type_code'] ?? 'other' ?>">
                                        <?php
                                        $typeIcons = [
                                            'monthly_adjustment' => 'fa-sync-alt',
                                            'wire_change' => 'fa-exchange-alt',
                                            'bracket_repair' => 'fa-wrench',
                                            'emergency_visit' => 'fa-exclamation-triangle',
                                            'elastic_change' => 'fa-circle-notch',
                                            'retainer_check' => 'fa-search',
                                            'other' => 'fa-ellipsis-h'
                                        ];
                                        $icon = $typeIcons[$log['type_code'] ?? 'other'] ?? 'fa-ellipsis-h';
                                        ?>
                                        <i class="fas <?= $icon ?>"></i>
                                        <?= htmlspecialchars($log['display_name']) ?>
                                    </span>
                                    <span class="log-arch-badge <?= $log['arch_affected'] ?>">
                                        <i class="fas fa-teeth"></i>
                                        <?= $archProcedureLog->getArchLabel($log['arch_affected']) ?>
                                    </span>
                                </div>
                                <div class="log-date">
                                    <i class="fas fa-calendar"></i>
                                    <?= date('Y/m/d', strtotime($log['procedure_date'])) ?>
                                    <?php if ($log['procedure_time']): ?>
                                    <span class="log-time"><i class="fas fa-clock"></i> <?= date('H:i', strtotime($log['procedure_time'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($log['notes']): ?>
                            <div class="log-notes">
                                <i class="fas fa-comment"></i>
                                <?= nl2br(htmlspecialchars($log['notes'])) ?>
                            </div>
                            <?php endif; ?>

                            <div class="log-footer">
                                <div class="log-meta">
                                    <?php if ($log['price'] > 0): ?>
                                    <span class="log-price">
                                        <i class="fas fa-coins"></i>
                                        <?= number_format($log['price']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($log['next_appointment_date']): ?>
                                    <span class="log-next-appointment">
                                        <i class="fas fa-calendar-plus"></i>
                                        جاری داهاتوو: <?= date('Y/m/d', strtotime($log['next_appointment_date'])) ?>
                                        <?php if ($log['next_appointment_notes']): ?>
                                        <small>(<?= htmlspecialchars($log['next_appointment_notes']) ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($log['performed_by_name']): ?>
                                    <span class="log-performed-by">
                                        <i class="fas fa-user-md"></i>
                                        د. <?= htmlspecialchars($log['performed_by_name']) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="log-actions">
                                    <?php if ($case['status'] === 'open'): ?>
                                    <button class="btn-icon btn-edit" onclick="editProcedureLog(<?= htmlspecialchars(json_encode($log)) ?>)" title="دەستکاری">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('ئایا دڵنیایت لە سڕینەوەی ئەم تۆمارە؟')">
                                        <input type="hidden" name="action" value="delete_procedure_log">
                                        <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete" title="سڕینەوە">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($canPrescribe || $canSeePrescriptions): ?>
            <!-- دەرمان Tab — prescribe from دەرمانخانە stock, send to the till -->
            <div class="tab-content" id="tab-medicine" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center" style="margin-bottom: 14px;">
                        <h4 style="margin:0;"><i class="fas fa-pills"></i> دەرمانی نەخۆش</h4>
                        <span class="badge badge-blue">لە کۆگای سەرەکیی دەرمانخانە</span>
                    </div>

                    <?php if ($canPrescribe): ?>
                    <!-- The clinic's paper prescription sheet: tick the items, circle the
                         form (Tab, Syrup, …) or brand in a group's header. -->
                    <div class="rxs" id="rxSheet">
                        <div class="rxs-top">
                            <div class="rxs-pt">Patient Name : <bdi><?= htmlspecialchars($case['patient_name'] ?? '') ?></bdi></div>
                            <div class="rxs-find">
                                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                                <input type="search" id="rxFind" autocomplete="off" placeholder="گەڕان لە پەڕەکەدا…" aria-label="گەڕان لە پەڕەکەدا">
                            </div>
                            <div class="rxs-legend"><span class="rxs-q">12</span> بەردەست لە کۆگا <span class="rxs-q zero">0</span> لە کۆگا نییە</div>
                        </div>
                        <div class="rxs-grid" id="rxGrid"><div class="rx-empty">بارکردن…</div></div>
                    </div>

                    <div class="rx-table-wrap">
                        <table class="rx-table" id="rxTable">
                            <thead>
                                <tr>
                                    <th style="width:36px">#</th>
                                    <th>دەرمان</th>
                                    <th style="width:110px">بڕ</th>
                                    <th style="width:360px">دۆز / ڕێنمایی</th>
                                    <th style="width:110px">لە کۆگا</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="rxRows"></tbody>
                        </table>
                        <div id="rxEmpty" class="rx-empty">هێشتا هیچ دەرمانێک دیاری نەکراوە — لە پەڕەکەی سەرەوە کاڵاکان دیاری بکە.</div>
                    </div>

                    <div class="rx-foot">
                        <input type="text" id="rxNotes" class="rx-input" placeholder="تێبینی بۆ دەرمانخانە (ئیختیاری)…">
                        <button type="button" class="btn btn-primary" id="rxSendBtn" onclick="rxSend()" disabled>
                            <i class="fas fa-paper-plane"></i> ناردن بۆ دەرمانخانە
                        </button>
                    </div>
                    <div id="rxMsg" class="rx-msg"></div>
                    <?php endif; ?>

                    <h4 style="margin:22px 0 10px;font-size:14px;">
                        <i class="fas fa-clock-rotate-left"></i> داواکاریە پێشووەکان
                    </h4>
                    <div id="rxHistory" class="rx-history">
                        <div class="rx-empty">بارکردن…</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Images Tab - 6 Image Slots -->
            <div class="tab-content" id="tab-images" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center" style="margin-bottom: 16px;">
                        <h4 style="margin: 0;"><i class="fas fa-images"></i> وێنەکانی کەیس</h4>
                        <span class="badge badge-blue"><?= count($caseImages) ?>/6</span>
                    </div>

                    <?php if (isset($_GET['image_uploaded'])): ?>
                    <div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle"></i> وێنە سەرکەوتوانە بارکرا</div>
                    <?php endif; ?>
                    <?php if (isset($_GET['image_deleted'])): ?>
                    <div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle"></i> وێنە سڕایەوە</div>
                    <?php endif; ?>

                    <div class="case-images-grid">
                        <?php for ($slot = 1; $slot <= 6; $slot++):
                            $image = $caseImages[$slot] ?? null;
                            $imageUrl = $image ? $caseManager->getImageUrl($image) : null;
                        ?>
                        <div class="case-image-slot <?= $image ? 'has-image' : 'empty' ?>">
                            <div class="slot-header">
                                <span class="slot-number"><?= $slot ?></span>
                                <?php if ($image): ?>
                                <span class="slot-info" title="<?= htmlspecialchars($image['original_filename'] ?? '') ?>">
                                    <?= $image['image_type'] ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($image && $imageUrl): ?>
                            <!-- Image Preview -->
                            <div class="image-preview" onclick="window.open('<?= htmlspecialchars($imageUrl) ?>', '_blank')">
                                <?php if ($image['image_type'] === 'pdf'): ?>
                                <i class="fas fa-file-pdf pdf-icon"></i>
                                <?php else: ?>
                                <img src="<?= htmlspecialchars($imageUrl) ?>" alt="Case Image <?= $slot ?>">
                                <?php endif; ?>
                                <div class="image-overlay">
                                    <i class="fas fa-search-plus"></i>
                                </div>
                            </div>

                            <!-- Image Actions -->
                            <div class="image-actions">
                                <a href="<?= htmlspecialchars($imageUrl) ?>" class="btn btn-sm btn-secondary" download title="داگرتن">
                                    <i class="fas fa-download"></i>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('ئایا دڵنیایت لە سڕینەوەی وێنە؟')">
                                    <input type="hidden" name="action" value="delete_case_image">
                                    <input type="hidden" name="slot_number" value="<?= $slot ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="سڕینەوە">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-primary" onclick="openImageUploadDialog(<?= $slot ?>)" title="گۆڕین">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            </div>

                            <?php else: ?>
                            <!-- Empty Slot - Upload Button -->
                            <div class="empty-slot" onclick="openImageUploadDialog(<?= $slot ?>)">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>بارکردنی وێنە</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <p class="text-muted mt-3" style="font-size: 12px; text-align: center;">
                        <i class="fas fa-info-circle"></i>
                        دەتوانیت هەر 6 وێنە بارکەیت. جۆرەکان: JPG, PNG, GIF, PDF (زۆرترین قەبارە: 10MB)
                    </p>
                </div>
            </div>

        </div>
    </div>

    <!-- Sidebar -->
    <div>
        <!-- Visit invoices (وەسڵی سەردان) — one receipt per work day -->
        <?php if (!empty($invoiceTabs)): ?>
        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-receipt"></i> وەسڵەکان</h4>
            </div>
            <div class="panel-body" style="padding: 8px 10px;">
                <?php foreach ($invoiceTabs as $inv): ?>
                <div class="ci-row<?= $inv['status'] === 'processed' ? ' ci-row--done' : ($inv['status'] === 'draft' ? ' ci-row--draft' : '') ?>">
                    <div class="ci-main">
                        <span class="ci-no"><?= htmlspecialchars($inv['invoice_no']) ?></span>
                        <span class="ci-date"><?= htmlspecialchars($inv['work_date']) ?> — <span data-invoice-count="<?= (int)$inv['id'] ?>"><?= (int)$inv['treatment_count'] ?> چارەسەر</span></span>
                    </div>
                    <span class="ci-amount" data-invoice-amount="<?= (int)$inv['id'] ?>"><?= number_format($inv['total_amount']) ?></span>
                    <?php if ($inv['status'] === 'draft'): ?>
                    <span class="ci-badge ci-badge--drafted" title="هێشتا نەنێردراوە بۆ ژمێریار"><i class="fas fa-pen-ruler"></i> نەنێردراو</span>
                    <?php elseif ($inv['status'] === 'processed'): ?>
                    <span class="ci-badge ci-badge--done" title="پارەکەی بە تەواوی وەرگیراوە"><i class="fas fa-check-double"></i> تەواو</span>
                    <?php elseif ($inv['fifo_covered'] > 0.009): ?>
                    <span class="ci-badge ci-badge--part" title="بەشێکی دراوە — ماوە: <?= number_format($inv['fifo_remaining']) ?>"><i class="fas fa-hourglass-half"></i> بەشی</span>
                    <?php else: ?>
                    <span class="ci-badge ci-badge--sent" title="لای ژمێریارە"><i class="fas fa-paper-plane"></i> نێردراو</span>
                    <?php endif; ?>
                    <button type="button" class="ci-print" onclick="openReceiptDialog(<?= (int)$inv['id'] ?>)" title="چاپکردنی ئەم وەسڵە">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
        .ci-row {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 6px;
            border-bottom: 1px solid var(--border-light, #eee);
            font-size: 12px;
        }
        .ci-row:last-child { border-bottom: 0; }
        .ci-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
        .ci-no { font-weight: 700; color: var(--text-primary, #263544); direction: ltr; text-align: right; }
        .ci-date { font-size: 10.5px; color: var(--text-secondary, #7a8794); }
        .ci-amount { font-weight: 700; white-space: nowrap; color: #0f6cbd; }
        .ci-badge {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap;
        }
        .ci-badge--sent { background: #fff4e5; color: #b25e09; }
        .ci-badge--drafted { background: #f1f3f6; color: #5a6672; }
        .ci-badge--part { background: #e8f0fe; color: #1a56b0; }
        .ci-badge--done { background: #e6f6ea; color: #137a37; }
        .ci-row--done .ci-amount { color: #137a37; }
        .ci-row--draft { background: #fbfcfe; border-radius: 8px; }
        .ci-row--draft .ci-no { color: #8a5a00; }
        .ci-print {
            flex: 0 0 auto; width: 26px; height: 26px;
            display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d5dee8; border-radius: 7px; background: #fff;
            color: #0f6cbd; cursor: pointer; font-size: 11px;
        }
        .ci-print:hover { background: #eef6fd; }
        </style>
        <?php endif; ?>

        <!-- Actions -->
        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-cogs"></i> کردارەکان</h4>
            </div>
            <div class="panel-body">
                <?php if (($isDoctor || $isAdmin) && $case['status'] === 'open'): ?>
                <button class="btn btn-primary w-100 mb-2" onclick="openAppointmentDialog()">
                    <i class="fas fa-calendar-plus"></i> دانانی مەوعید
                </button>
                <?php endif; ?>

                <?php if ($case['status'] === 'open' && $remaining > 0 && !$installmentPlan): ?>
                <button class="btn btn-warning w-100 mb-2" onclick="openDialog('installmentDialog')">
                    <i class="fas fa-credit-card"></i> پلانی قیست
                </button>
                <?php endif; ?>

                <?php if ($case['status'] === 'open'): ?>
                <button type="button" class="btn btn-secondary w-100 mb-2" onclick="openDialog('closeCaseDialog')">
                    <i class="fas fa-check-circle"></i> داخستنی کەیس
                </button>
                <?php else: ?>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="reopen_case">
                    <button type="submit" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-folder-open"></i> کردنەوە
                    </button>
                </form>
                <?php endif; ?>

                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $case['patient_id'] ?>" class="btn btn-outline w-100">
                    <i class="fas fa-user"></i> گەڕانەوە بۆ نەخۆش
                </a>
            </div>
        </div>

        <!-- Chief Complaint -->
        <?php if (!empty($case['chief_complaint'])): ?>
        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-notes-medical"></i> سکاڵا</h4>
            </div>
            <div class="panel-body">
                <p><?= nl2br(htmlspecialchars($case['chief_complaint'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Closing Comment (for closed cases) -->
        <?php if ($case['status'] === 'closed' && !empty($case['closing_comment'])): ?>
        <div class="panel">
            <div class="panel-header" style="background: var(--secondary-light);">
                <h4 class="panel-title"><i class="fas fa-comment-dots"></i> تێبینی کۆتایی</h4>
            </div>
            <div class="panel-body">
                <p style="color: var(--text-secondary);"><?= nl2br(htmlspecialchars($case['closing_comment'])) ?></p>
                <?php if (!empty($case['closed_at'])): ?>
                <small style="color: var(--text-muted); display: block; margin-top: 8px;">
                    <i class="fas fa-clock"></i> داخرا لە: <?= date('Y/m/d H:i', strtotime($case['closed_at'])) ?>
                </small>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Send to Accounting Dialog -->
<div class="dialog-overlay" id="sendAccountingDialog">
    <div class="dialog" style="max-width: 480px;">
        <div class="dialog-header installment-dialog-header">
            <h3 class="dialog-title"><i class="fas fa-paper-plane" style="color:#ff8c00;"></i> ناردن بۆ ژمێریار</h3>
            <button class="dialog-close" onclick="closeDialog('sendAccountingDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="send_to_accounting">
            <div class="dialog-body">
                <div style="background:var(--warning-light,#fff4ce);border:1px solid #ff8c00;border-radius:8px;padding:12px;margin-bottom:16px;">
                    <p style="margin:0;font-size:14px;"><i class="fas fa-info-circle" style="color:#ff8c00;"></i>
                        ئەم کەیسە بە تەواوی بۆ ژمێریار دەنێردرێت بۆ وەرگرتنی پارە، داشکاندن و بەڕێوەبردنی قیست.
                    </p>
                </div>
                <div style="margin-bottom:12px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
                        <div style="background:var(--bg-secondary,#f5f5f5);padding:10px;border-radius:6px;text-align:center;">
                            <div style="font-size:11px;color:var(--text-secondary);">کۆی گشتی</div>
                            <div style="font-weight:700;color:#0078d4;"><?= number_format($case['total_amount']) ?></div>
                        </div>
                        <div style="background:var(--bg-secondary,#f5f5f5);padding:10px;border-radius:6px;text-align:center;">
                            <div style="font-size:11px;color:var(--text-secondary);">ماوەی پارە</div>
                            <div style="font-weight:700;color:<?= $remaining > 0 ? '#c50f1f' : '#107c10' ?>;"><?= number_format($remaining) ?></div>
                        </div>
                    </div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">تێبینی بۆ ژمێریار (ئارەزووی)</label>
                    <textarea name="notes" rows="3" style="width:100%;border:1px solid var(--border-color);border-radius:6px;padding:8px;font-family:inherit;font-size:13px;resize:vertical;" placeholder="ئەگەر تێبینیت هەیە بیخە ئێرەدا..."></textarea>
                </div>
            </div>
            <div class="dialog-footer installment-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('sendAccountingDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-paper-plane"></i> ناردن بۆ ژمێریار</button>
            </div>
        </form>
    </div>
</div>

<!-- Resend / follow-up note to Accounting (only relevant after already sent) -->
<div class="dialog-overlay" id="resendAccountingDialog">
    <div class="dialog" style="max-width: 480px;">
        <div class="dialog-header installment-dialog-header">
            <h3 class="dialog-title"><i class="fas fa-comment-dots" style="color:#ff8c00;"></i> ناردنی تێبینی بۆ ژمێریار</h3>
            <button class="dialog-close" onclick="closeDialog('resendAccountingDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="resend_to_accounting">
            <div class="dialog-body">
                <div style="background:#e8f4fd;border:1px solid #90caf9;border-radius:8px;padding:12px;margin-bottom:16px;">
                    <p style="margin:0;font-size:13px;color:#1565c0;"><i class="fas fa-info-circle"></i>
                        ئەم کەیسە پێشتر نێردراوە بۆ ژمێریار. لێرەوە دەتوانیت تێبینی/نوێکردنەوەیەکی نوێ بنێریت و ئاگاداری بکەیتەوە.
                    </p>
                </div>
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">تێبینی بۆ ژمێریار <span style="color:#f44336;">*</span></label>
                <textarea name="notes" rows="4" required style="width:100%;border:1px solid var(--border-color);border-radius:6px;padding:8px;font-family:inherit;font-size:13px;resize:vertical;" placeholder="نموونە: نرخەکە نوێکرایەوە / تکایە قیستی مانگانە سازبکە / داشکاندنی زیاتر..."></textarea>
            </div>
            <div class="dialog-footer installment-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('resendAccountingDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-paper-plane"></i> ناردن بۆ ژمێریار</button>
            </div>
        </form>
    </div>
</div>

<?php if (($isDoctor || $isAdmin) && $case['status'] === 'open'): ?>
<!-- Standalone Appointment Dialog (book an appointment for this patient) -->
<div class="dialog-overlay" id="appointmentDialog">
    <div class="dialog" style="max-width: 560px;">
        <div class="dialog-header" style="background: linear-gradient(135deg, #0078d4, #0063b1);">
            <h3 class="dialog-title" style="color:#fff;"><i class="fas fa-calendar-plus"></i> مەوعیدی نوێ بۆ <?= htmlspecialchars($case['patient_name'] ?? 'نەخۆش') ?></h3>
            <button type="button" class="dialog-close" onclick="closeDialog('appointmentDialog')" style="color:#fff;"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="appointmentForm">
            <input type="hidden" name="action" value="create_appointment">
            <input type="hidden" name="appointment_time" id="aptSelectedTime" value="">
            <div class="dialog-body">
                <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label required">بەروار</label>
                        <input type="date" name="appointment_date" id="aptDate" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required onchange="loadAptSlots()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ماوە</label>
                        <select name="duration" id="aptDuration" class="form-control" onchange="loadAptSlots()">
                            <option value="15">١٥ خولەک</option>
                            <option value="30" selected>٣٠ خولەک</option>
                            <option value="45">٤٥ خولەک</option>
                            <option value="60">١ کاتژمێر</option>
                            <option value="90">١.٥ کاتژمێر</option>
                            <option value="120">٢ کاتژمێر</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">جۆر</label>
                        <select name="type" class="form-control">
                            <option value="followup">بەدواداچوون</option>
                            <option value="treatment">چارەسەر</option>
                            <option value="checkup">پشکنین</option>
                            <option value="consultation">ڕاوێژ</option>
                            <option value="general">گشتی</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required"><i class="fas fa-clock"></i> کات</label>
                    <div id="aptSlotsBox" style="background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px;max-height:180px;overflow-y:auto;">
                        <div style="text-align:center;color:#888;font-size:12px;padding:10px;"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    <div style="display:flex;gap:12px;margin-top:6px;font-size:11px;color:#78909c;">
                        <span><i class="fas fa-circle" style="color:#4caf50;font-size:7px;"></i> بەردەست</span>
                        <span><i class="fas fa-circle" style="color:#f44336;font-size:7px;"></i> گیراو</span>
                        <span><i class="fas fa-circle" style="color:#ff9800;font-size:7px;"></i> پشوو</span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">تێبینی</label>
                    <input type="text" name="notes" class="form-control" placeholder="تێبینی (ئارەزوومەندانە)...">
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('appointmentDialog')">داخستن</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> دانانی مەوعید</button>
            </div>
        </form>
    </div>
</div>

<style>
.apt-slot { display:inline-block; padding:7px 4px; min-width:64px; text-align:center; border:1.5px solid #e0e0e0; border-radius:6px; font-size:12px; font-weight:600; background:#fff; color:#37474f; cursor:pointer; transition:all .15s; margin:2px; }
.apt-slot:hover:not(.busy):not(.break) { border-color:#0078d4; background:#e3f2fd; color:#0078d4; }
.apt-slot.selected { background:#0078d4; color:#fff; border-color:#0078d4; }
.apt-slot.busy { background:#ffebee; border-color:#ffcdd2; color:#c62828; cursor:not-allowed; opacity:.7; }
/* Booked slots stay selectable (overlapping bookings allowed) */
.apt-slot.booked { background:#ffebee; border-color:#ffcdd2; color:#c62828; cursor:pointer; }
.apt-slot.booked.selected { background:#0078d4; color:white; border-color:#0078d4; }
.apt-slot.break { background:#fff3e0; border-color:#ffe0b2; color:#e65100; cursor:not-allowed; opacity:.7; }
</style>

<script>
(function() {
    var APT_DOCTOR_ID = <?= (int)($case['doctor_id'] ?? 0) ?: (int)$currentUser['id'] ?>;
    var SCHEDULE_API = '<?= APP_URL ?>/api/schedule.php';

    window.openAppointmentDialog = function() {
        document.getElementById('appointmentForm').reset();
        document.getElementById('aptSelectedTime').value = '';
        var d = document.getElementById('aptDate');
        d.value = d.value || '<?= date('Y-m-d') ?>';
        openDialog('appointmentDialog');
        loadAptSlots();
    };

    window.loadAptSlots = async function() {
        var box = document.getElementById('aptSlotsBox');
        var date = document.getElementById('aptDate').value;
        var duration = document.getElementById('aptDuration').value || 30;
        document.getElementById('aptSelectedTime').value = '';
        if (!date) { box.innerHTML = '<div style="text-align:center;color:#c62828;padding:10px;">تکایە بەروار هەڵبژێرە</div>'; return; }
        box.innerHTML = '<div style="text-align:center;color:#888;padding:10px;"><i class="fas fa-spinner fa-spin"></i></div>';
        try {
            var res = await fetch(SCHEDULE_API + '?action=get_all_slots&doctor_id=' + APT_DOCTOR_ID + '&date=' + date + '&duration=' + duration);
            var data = await res.json();
            if (!data.success) { box.innerHTML = '<div style="text-align:center;color:#c62828;padding:10px;">' + (escapeHtml(data.message) || 'هەڵەیەک ڕوویدا') + '</div>'; return; }
            var d = data.data;
            if (!d.working) { box.innerHTML = '<div style="text-align:center;color:#e65100;padding:10px;"><i class="fas fa-bed"></i> ئەم ڕۆژە پشووە</div>'; return; }
            if (!d.slots || !d.slots.length) { box.innerHTML = '<div style="text-align:center;color:#888;padding:10px;">هیچ کاتێک بەردەست نییە</div>'; return; }
            var html = '';
            d.slots.forEach(function(s) {
                var cls = s.status === 'available' ? '' : s.status;
                var click = (s.status === 'available' || s.status === 'booked') ? ' onclick="selectAptSlot(\'' + escapeHtml(s.time) + '\', this)"' : '';
                var t = (s.info || s.reason) ? ' title="' + escapeHtml(s.info || s.reason) + '"' : '';
                html += '<span class="apt-slot ' + cls + '"' + click + t + '>' + escapeHtml(s.time) + '</span>';
            });
            box.innerHTML = html;
        } catch (e) {
            box.innerHTML = '<div style="text-align:center;color:#c62828;padding:10px;">هەڵە لە بارکردنی کاتەکان</div>';
        }
    };

    window.selectAptSlot = function(time, el) {
        document.getElementById('aptSelectedTime').value = time;
        document.querySelectorAll('#aptSlotsBox .apt-slot').forEach(function(s) { s.classList.remove('selected'); });
        if (el) el.classList.add('selected');
    };

    document.getElementById('appointmentForm').addEventListener('submit', function(e) {
        if (!document.getElementById('aptSelectedTime').value) {
            e.preventDefault();
            if (typeof showToast === 'function') showToast('warning', 'تکایە کاتێک هەڵبژێرە');
            else alert('تکایە کاتێک هەڵبژێرە');
        }
    });
})();
</script>
<?php endif; ?>

<?php if (isset($_GET['sent_to_accounting'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof showToast === 'function') {
        showToast('success', 'بەسەرکەوتوویی ناردرا بۆ ژمێریار');
    }
});
</script>
<?php elseif (isset($_GET['accounting_already'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof showToast === 'function') {
        showToast('warning', 'ئەم کەیسە پێشتر ناردراوە بۆ ژمێریار');
    }
});
</script>
<?php endif; ?>
<?php /*
   Per user request (2026-07-20): adding a treatment must NOT auto-open the
   send-to-accountant / complete-case dialog. The doctor sends manually when
   the case is finished (the «ناردن بۆ ژمێریار» / «تەواوکردنی کەیس» buttons)
   or fires a notification for extra work — nothing pops up on add.
*/ ?>

<!-- Add Treatment Dialog -->
<div class="dialog-overlay" id="addTreatmentDialog">
    <div class="dialog treatment-dialog-modern">
        <div class="treatment-dialog-header">
            <h4><i class="fas fa-plus-circle"></i> زیادکردنی چارەسەر</h4>
            <button type="button" class="treatment-dialog-close" onclick="closeDialog('addTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="addTreatmentForm">
            <input type="hidden" name="action" value="add_treatment">
            <input type="hidden" name="save_and_new" id="addTreatmentSaveAndNew" value="">
            <div class="treatment-dialog-body">
                <?php if (empty($treatments)): ?>
                <div class="treatment-alert treatment-alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>هیچ چارەسەرێک نییە. <a href="<?= APP_URL ?>/admin/settings.php?tab=treatments">زیادکردن</a></span>
                </div>
                <?php endif; ?>

              <div class="pl-grid">
                <div class="pl-col">
                <div class="treatment-form-grid">
                    <div class="treatment-form-field" id="treatmentToothField">
                        <label><i class="fas fa-tooth"></i> ددان</label>
                        <!-- Every tooth of both dentitions is listed: a case may mix adult
                             and child teeth, so nothing here is filtered by tooth set. -->
                        <select name="tooth_number" id="selectedToothNumber" onchange="onDialogToothChange()">
                            <option value="">هەڵبژێرە...</option>
                            <?php
                            $toothSelectGroups = [
                                'upper_right' => 'سەرەوەی ڕاست (UR)',
                                'upper_left'  => 'سەرەوەی چەپ (UL)',
                                'lower_left'  => 'خوارەوەی چەپ (LL)',
                                'lower_right' => 'خوارەوەی ڕاست (LR)',
                            ];
                            $toothSelectSets = ['permanent' => 'گەورە', 'primary' => 'منداڵ'];
                            foreach ($toothSelectGroups as $groupKey => $groupLabel):
                                foreach ($toothSelectSets as $setName => $setLabel):
                            ?>
                            <optgroup label="<?= $groupLabel ?> — <?= $setLabel ?>">
                                <?php foreach ($teethSets[$setName][$groupKey] as $num): ?>
                                <option value="<?= $num ?>"><?= $palmerNumbers[$num] ?> - <?= $toothNames[$num] ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; endforeach; ?>
                        </select>
                        <small id="treatmentToothRuleHint" style="display:none;color:#0f766e;font-weight:600;margin-top:5px;">
                            <i class="fas fa-briefcase-medical"></i> ئەم کارە بێ ژمارەی ددان تۆمار دەکرێت
                        </small>
                    </div>
                    <div class="treatment-form-field treatment-field-full">
                        <label><i class="fas fa-briefcase-medical"></i> چارەسەر <span class="required">*</span></label>
                        <div style="display: flex; gap: 6px;">
                            <!-- Hidden select keeps the existing form wiring; the picker drives it -->
                            <select name="treatment_id" id="treatmentSelect" onchange="updateTreatmentPrice()" style="display: none;">
                                <option value="">هەڵبژێرە...</option>
                                <?php foreach ($toothTreatments as $t): ?>
                                <option value="<?= $t['id'] ?>" data-price="<?= $t['default_price'] ?>" data-category="<?= htmlspecialchars($t['category'] ?? 'other') ?>" data-name="<?= htmlspecialchars($t['treatment_name']) ?>" data-requires-tooth="<?= (int)($t['requires_tooth_number'] ?? 1) ?>">
                                    <?= htmlspecialchars($t['treatment_name']) ?> (<?= number_format($t['default_price']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="treatmentPickerBtn" class="tp-trigger" onclick="openTreatmentPicker()">
                                <i class="fas fa-hand-pointer"></i> هەڵبژاردنی چارەسەر...
                            </button>
                            <button type="button" onclick="openAddTreatmentDialog()" title="زیادکردنی چارەسەری نوێ"
                                    style="padding: 0 14px; background: #0078d4; color: #fff; border: 1px solid #0078d4; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tooth surface picker: shown only for surface-level treatments -->
                <div class="treatment-form-field treatment-field-full" id="surfaceSection" style="display: none;">
                    <label><i class="fas fa-bullseye"></i> ڕووی ددان <small style="color: var(--text-secondary); font-weight: 400;">(کلیک لەسەر بەشەکانی ددانەکە بکە)</small></label>
                    <div class="surface-picker">
                        <div class="surface-tooth-holder" id="surfaceToothPreview"></div>
                        <div class="surface-chips" id="surfaceChips"></div>
                    </div>
                    <input type="hidden" name="tooth_surface" id="toothSurfaceInput" value="">
                </div>

                <div class="treatment-form-field treatment-field-full">
                    <label><i class="fas fa-pen"></i> ناوی چارەسەر <span class="required">*</span></label>
                    <input type="text" name="treatment_name" id="treatmentName" required placeholder="ناوی چارەسەر...">
                </div>

                <div class="treatment-form-field treatment-field-full">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <input type="text" name="notes" placeholder="تێبینی ئارەزوومەندانە...">
                </div>
                </div><!-- /pl-col left -->

                <div class="pl-col">
                <!-- Flexible Pricing Section -->
                <div class="treatment-pricing-section">
                    <div class="pricing-header">
                        <i class="fas fa-coins"></i> نرخ
                    </div>
                    <div class="pricing-content">
                        <div class="price-row default-price">
                            <span class="price-label">نرخی پێشنیارکراو:</span>
                            <span class="price-value" id="defaultPriceDisplay">0</span>
                            <span class="price-currency"></span>
                        </div>
                        <div class="price-row agreement-price">
                            <span class="price-label">نرخی ڕێککەوتن:</span>
                            <input type="number" name="unit_price" id="unitPrice" required
                                   onchange="calculateTotal()" placeholder="نرخ بنووسە...">
                            <span class="price-currency"></span>
                            <button type="button" class="use-default-btn" onclick="useDefaultPrice()" title="بەکارهێنانی نرخی پێشنیارکراو">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="treatment-form-grid">
                    <div class="treatment-form-field">
                        <label><i class="fas fa-hashtag"></i> ژمارە</label>
                        <input type="number" name="quantity" id="treatmentQuantity" value="1" min="1" step="1" onchange="calculateTotal()">
                    </div>
                    <div class="treatment-form-field">
                        <label><i class="fas fa-percent"></i> داشکاندن</label>
                        <input type="number" name="discount" id="treatmentDiscount" value="0" onchange="calculateTotal()">
                    </div>
                </div>

                <!-- Total Display -->
                <div class="treatment-total-display">
                    <span class="total-label">کۆی گشتی:</span>
                    <span class="total-value" id="totalPriceDisplay">0</span>
                    <span class="total-currency"></span>
                </div>
                </div><!-- /pl-col right -->
              </div><!-- /pl-grid -->
            </div>
            <div class="treatment-dialog-footer">
                <button type="submit" class="treatment-btn-primary" onclick="document.getElementById('addTreatmentSaveAndNew').value=''"><i class="fas fa-plus-circle"></i> زیادکردن</button>
                <button type="submit" class="treatment-btn-primary" style="background: linear-gradient(135deg, #107c10, #0e6b0e);"
                        onclick="document.getElementById('addTreatmentSaveAndNew').value='1'"
                        title="پاشەکەوت دەکات و دیالۆگەکە دەکاتەوە بۆ چارەسەری دواتر">
                    <i class="fas fa-plus"></i> پاشەکەوت و زیادکردنی تر
                </button>
                <button type="button" class="treatment-btn-secondary" onclick="closeDialog('addTreatmentDialog')"><i class="fas fa-times"></i> داخستن</button>
            </div>
        </form>
    </div>
</div>

<?php if ($case['status'] === 'open' && ($case['scope_type'] ?? 'tooth') !== 'arch'): ?>
<!-- ── Bulk (multi-tooth) treatment: floating bar + dialog ── -->
<div id="bulkActionBar" style="display: none;">
    <div class="bulk-bar-inner">
        <span class="bulk-bar-count"><i class="fas fa-tooth"></i> <span id="bulkCount">0</span> ددان هەڵبژێردراوە</span>
        <span class="bulk-bar-teeth" id="bulkTeethList"></span>
        <div class="bulk-bar-actions">
            <button type="button" class="btn btn-sm" onclick="clearBulkSelection()" style="background:#eef2f5;color:#37474f;border:1px solid #cfd8dc;">
                <i class="fas fa-eraser"></i> پاککردنەوە
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn" onclick="openBulkTreatmentDialog()">
                <i class="fas fa-plus-circle"></i> زیادکردنی چارەسەر بۆ هەموو
            </button>
        </div>
    </div>
</div>

<!-- Right-click context menu for selected teeth -->
<div id="toothContextMenu" class="tooth-ctx-menu"></div>
<style>
.tooth-ctx-menu {
    display: none; position: fixed; z-index: 4000; min-width: 210px;
    background: #fff; border: 1px solid #e0e6ed; border-radius: 10px;
    box-shadow: 0 12px 32px rgba(0,0,0,.18); padding: 6px; overflow: hidden;
}
.tooth-ctx-menu .ctx-title {
    padding: 6px 12px 8px; font-size: 11px; color: #78909c; font-weight: 700;
    border-bottom: 1px solid #eef2f5; margin-bottom: 4px;
}
.tooth-ctx-item {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 9px 12px; border: none; background: transparent; cursor: pointer;
    font-size: 13px; color: #37474f; border-radius: 6px; text-align: right;
}
.tooth-ctx-item:hover { background: #e6fffa; color: #0f766e; }
.tooth-ctx-item.danger:hover { background: #fef2f2; color: #dc2626; }
.tooth-ctx-item i { width: 16px; text-align: center; color: #0d9488; }
.tooth-ctx-item.danger i { color: #dc2626; }
.tooth-ctx-item .ctx-sub { margin-right: auto; font-size: 11px; color: #9aa7b3; }
.tooth-ctx-sep { height: 1px; background: #eef2f5; margin: 4px 0; }
</style>

<div class="dialog-overlay" id="bulkTreatmentDialog">
    <div class="dialog treatment-dialog-modern" style="max-width: 560px;">
        <div class="treatment-dialog-header" style="background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);">
            <h4><i class="fas fa-object-group"></i> چارەسەر بۆ چەند ددان</h4>
            <button type="button" class="treatment-dialog-close" onclick="closeDialog('bulkTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="bulkTreatmentForm">
            <input type="hidden" name="action" value="add_treatment_bulk">
            <input type="hidden" name="tooth_numbers" id="bulkToothNumbers" value="">
            <input type="hidden" name="treatment_id" id="bulkTreatmentId" value="">
            <input type="hidden" name="treatment_name" id="bulkTreatmentName" value="">
            <div class="treatment-dialog-body">
                <div class="treatment-form-field treatment-field-full" style="margin-bottom: 12px;">
                    <label><i class="fas fa-tooth"></i> ددانە هەڵبژێردراوەکان</label>
                    <div id="bulkDialogTeeth" style="display:flex;flex-wrap:wrap;gap:6px;padding:8px;background:var(--bg-main,#f7f9fc);border-radius:8px;min-height:38px;"></div>
                </div>

                <div class="treatment-form-field treatment-field-full">
                    <label><i class="fas fa-briefcase-medical"></i> چارەسەر <span class="required">*</span></label>
                    <div class="city-combo">
                        <input type="text" id="bulkTrInput" placeholder="گەڕان بۆ چارەسەر..." autocomplete="off"
                               oninput="bulkTrFilter()" onfocus="bulkTrOpen()">
                        <div id="bulkTrDropdown" class="city-combo-dropdown"></div>
                    </div>
                </div>

                <div class="treatment-form-grid" style="margin-top: 12px;">
                    <div class="treatment-form-field">
                        <label><i class="fas fa-hashtag"></i> ژمارە (بۆ هەر ددانێک)</label>
                        <input type="number" name="quantity" id="bulkQuantity" value="1" min="1" step="1" onchange="bulkCalcTotal()">
                    </div>
                    <div class="treatment-form-field">
                        <label><i class="fas fa-coins"></i> نرخ (بۆ هەر ددانێک) <span class="required">*</span></label>
                        <input type="number" name="unit_price" id="bulkUnitPrice" value="0" min="0" step="0.01" required onchange="bulkCalcTotal()">
                    </div>
                </div>

                <div class="treatment-form-field treatment-field-full" style="margin-top: 10px;">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <input type="text" name="notes" placeholder="تێبینی ئارەزوومەندانە...">
                </div>

                <div class="treatment-total-display" style="margin-top: 12px;">
                    <span class="total-label">کۆی گشتی (<span id="bulkTeethCountLabel">0</span> ددان):</span>
                    <span class="total-value" id="bulkTotalDisplay">0</span>
                </div>
            </div>
            <div class="treatment-dialog-footer">
                <button type="submit" class="treatment-btn-primary" style="background: linear-gradient(135deg, #0d9488, #0f766e);" id="bulkSubmitBtn">
                    <i class="fas fa-check"></i> زیادکردن بۆ هەموو
                </button>
                <button type="button" class="treatment-btn-secondary" onclick="closeDialog('bulkTreatmentDialog')"><i class="fas fa-times"></i> داخستن</button>
            </div>
        </form>
    </div>
</div>

<style>
#bulkActionBar {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 2500;
    background: #fff; border-top: 2px solid #0d9488;
    box-shadow: 0 -6px 24px rgba(0,0,0,.12); padding: 10px 16px;
}
.bulk-bar-inner { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; max-width: 1100px; margin: 0 auto; }
.bulk-bar-count { font-weight: 700; color: #0f766e; white-space: nowrap; }
.bulk-bar-teeth { flex: 1; display: flex; flex-wrap: wrap; gap: 5px; min-width: 0; }
.bulk-bar-teeth .bt-chip {
    display: inline-flex; align-items: center; gap: 5px; background: #f0fdfa;
    border: 1px solid #99f6e4; color: #0f766e; border-radius: 14px;
    padding: 2px 9px; font-size: 12px; font-weight: 700;
}
.bulk-bar-teeth .bt-chip .bt-x { cursor: pointer; color: #ef4444; font-weight: 700; }
.bulk-bar-actions { display: flex; gap: 8px; }
.smart-dental-chart .tooth.bulk-selected {
    border-color: #0d9488;
    background: #f0fdfa;
    box-shadow: 0 0 0 3px rgba(13,148,136,.22), 0 10px 22px rgba(13,148,136,.14);
}
.smart-dental-chart .tooth.bulk-selected .tooth-number {
    background: #0d9488 !important; color: #fff !important;
}
#bulkDialogTeeth .bd-chip {
    display: inline-flex; align-items: center; background: #f0fdfa; border: 1px solid #99f6e4;
    color: #0f766e; border-radius: 14px; padding: 3px 10px; font-size: 12px; font-weight: 700;
}
body.bulk-mode-on .smart-dental-chart .tooth { cursor: cell; }

/* Searchable treatment combo inside the bulk dialog */
#bulkTreatmentDialog .city-combo { position: relative; }
#bulkTreatmentDialog .city-combo input[type="text"] { width: 100%; }
.city-combo-dropdown {
    display: none; position: absolute; top: 100%; right: 0; left: 0; z-index: 3300;
    background: var(--bg-color, #fff); border: 1px solid #cfd8dc; border-radius: 6px; margin-top: 2px;
    max-height: 240px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.14);
}
.city-combo-item { padding: 8px 14px; cursor: pointer; font-size: 13px; color: var(--text-primary, #37474f); }
.city-combo-item:hover { background: #e6fffa; color: #0f766e; }
.city-combo-empty { padding: 12px 14px; color: #9aa7b3; font-size: 12px; text-align: center; }
</style>
<?php endif; ?>

<?php if ($case['status'] === 'open'): ?>
<!-- ── Manage Treatment Types dialog (add / edit) ── -->
<div class="dialog-overlay" id="manageTreatmentsDialog">
    <div class="dialog treatment-dialog-modern mt-dialog">
        <div class="treatment-dialog-header" style="background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);">
            <h4><i class="fas fa-list-check"></i> بەڕێوەبردنی جۆرەکانی چارەسەر</h4>
            <button type="button" class="treatment-dialog-close" onclick="closeManageTreatmentsDialog()"><i class="fas fa-times"></i></button>
        </div>
        <div class="treatment-dialog-body">
            <!-- Add new type -->
            <div class="mt-add-card">
                <div class="mt-add-title"><i class="fas fa-plus-circle"></i> زیادکردنی جۆری نوێ</div>
                <div class="mt-add-fields">
                    <input type="text" id="mtNewName" class="mt-in-name" placeholder="ناوی چارەسەر...">
                    <input type="number" id="mtNewPrice" class="mt-in-price" placeholder="نرخ" min="0" step="0.01" value="0">
                    <select id="mtNewCategory" class="mt-in-cat"></select>
                    <select id="mtNewScope" class="mt-in-scope" onchange="mtSyncScopeRule(null)">
                        <option value="tooth">ددان</option>
                        <option value="arch">ئەرک</option>
                        <option value="both">هەردوو</option>
                    </select>
                    <button type="button" class="mt-add-btn" onclick="mtAdd()" id="mtAddBtn">
                        <i class="fas fa-plus"></i> زیادکردن
                    </button>
                </div>
                <div id="mtAddErr" class="mt-err" style="display:none;"></div>
            </div>

            <div class="mt-search-row">
                <i class="fas fa-search"></i>
                <input type="text" id="mtSearch" placeholder="گەڕان بۆ چارەسەر..." oninput="mtRender()">
                <span class="mt-count" id="mtCount"></span>
            </div>

            <div class="mt-list" id="mtList"></div>
        </div>
        <div class="treatment-dialog-footer">
            <button type="button" class="treatment-btn-secondary" onclick="closeManageTreatmentsDialog()"><i class="fas fa-check"></i> تەواو</button>
        </div>
    </div>
</div>

<style>
/* Wider dialog + no horizontal scroll */
.mt-dialog { max-width: 760px; width: 96%; }
.mt-dialog .treatment-dialog-body { overflow-x: hidden; }

.mt-add-card { background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 10px; padding: 12px; margin-bottom: 12px; }
.mt-add-title { font-size: 12px; font-weight: 700; color: #6d28d9; margin-bottom: 8px; }
.mt-add-fields { display: flex; flex-wrap: wrap; gap: 6px; }
.mt-add-fields input, .mt-add-fields select { padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; background: #fff; min-width: 0; }
.mt-in-name  { flex: 1 1 200px; }
.mt-in-price { flex: 0 1 110px; text-align: left; direction: ltr; }
.mt-in-cat   { flex: 0 1 140px; }
.mt-in-scope { flex: 0 1 90px; }
.mt-in-rule  { flex: 0 1 155px; }
.mt-add-btn  { flex: 0 0 auto; padding: 8px 16px; background: #6d28d9; color: #fff; border: 1px solid #6d28d9;
               border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; white-space: nowrap; }
.mt-add-btn:hover { background: #5b21b6; }
.mt-err { color: #dc2626; font-size: 12px; margin-top: 6px; }

.mt-search-row { display: flex; align-items: center; gap: 8px; background: var(--bg-main,#f7f9fc); border: 1px solid #e6ebf1; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; }
.mt-search-row input { flex: 1; border: none; background: transparent; outline: none; font-size: 13px; }
.mt-search-row .mt-count { font-size: 11px; color: #9aa7b3; white-space: nowrap; }

.mt-list { max-height: 46vh; overflow-y: auto; overflow-x: hidden; display: flex; flex-direction: column; gap: 6px; padding-left: 2px; }

/* Display row: icon | name | category | price | edit */
.mt-row { display: flex; align-items: center; gap: 10px;
          padding: 9px 12px; border: 1px solid #eef2f5; border-radius: 9px; background: #fff; }
.mt-row:hover { border-color: #d8c5f5; background: #fcfaff; }
.mt-row .mt-icon { flex: 0 0 auto; width: 24px; display: flex; align-items: center; justify-content: center; }
.mt-row .mt-icon .legend-color { width: 14px; height: 14px; border-radius: 4px; display: inline-block; border: 1px solid rgba(0,0,0,.08); }
.mt-row .mt-name { flex: 1 1 auto; min-width: 0; font-weight: 600; font-size: 13px; color: #37474f;
                   white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mt-row .mt-cat { flex: 0 0 auto; font-size: 10px; color: #6d28d9; background: #f3ebff; border-radius: 10px; padding: 3px 9px; white-space: nowrap; }
.mt-row .mt-price { flex: 0 0 auto; font-weight: 700; font-size: 13px; color: #0d9488; white-space: nowrap; direction: ltr; }
.mt-row .mt-edit { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 7px; border: 1px solid #e0e6ed; background: #fff; cursor: pointer; font-size: 12px; color: #0078d4; }
.mt-row .mt-edit:hover { background: #e3f2fd; border-color: #a9d3f5; }
.mt-row .mt-del { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 7px; border: 1px solid #f0d4d4; background: #fff; cursor: pointer; font-size: 12px; color: #c62026; }
.mt-row .mt-del:hover { background: #fdecec; border-color: #f0a9a9; }

/* Editing row: name on its own line, then price/cat/scope + save/cancel */
.mt-row.editing { flex-direction: column; align-items: stretch; gap: 8px; background: #f0fdfa; border-color: #99f6e4; }
.mt-edit-fields { display: flex; flex-wrap: wrap; gap: 6px; }
.mt-edit-fields input, .mt-edit-fields select { padding: 8px 10px; border: 1.5px solid #cfd8dc; border-radius: 6px; font-size: 12px; min-width: 0; }
.mt-edit-fields .mt-en-name  { flex: 1 1 100%; }
.mt-edit-fields .mt-en-price { flex: 1 1 110px; text-align: left; direction: ltr; }
.mt-edit-fields .mt-en-cat   { flex: 1 1 140px; }
.mt-edit-fields .mt-en-scope { flex: 0 1 90px; }
.mt-edit-fields .mt-en-rule  { flex: 1 1 155px; }
.mt-edit-acts { display: flex; gap: 6px; justify-content: flex-end; }
.mt-edit-acts button { padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid; white-space: nowrap; }
.mt-edit-acts .mt-save { background: #0d9488; border-color: #0d9488; color: #fff; }
.mt-edit-acts .mt-cancel { background: #fff; border-color: #cfd8dc; color: #546e7a; }
.mt-empty { text-align: center; color: #9aa7b3; padding: 20px; font-size: 13px; }
</style>
<?php endif; ?>

<!-- Searchable treatment picker (custom overlay: must stack ABOVE the add dialog,
     so it deliberately does not use .dialog-overlay / the dialog manager) -->
<div class="tp-overlay" id="treatmentPicker">
    <div class="tp-sheet">
        <div class="tp-head">
            <h4><i class="fas fa-briefcase-medical"></i> هەڵبژاردنی چارەسەر</h4>
            <button type="button" class="tp-close" onclick="closeTreatmentPicker()"><i class="fas fa-times"></i></button>
        </div>
        <div class="tp-search-row">
            <i class="fas fa-search"></i>
            <input type="text" id="tpSearch" placeholder="گەڕان بۆ چارەسەر...">
        </div>
        <div class="tp-cats" id="tpCats"></div>
        <div class="tp-list" id="tpList"></div>
    </div>
</div>

<style>
/* ── Treatment picker ── */
.tp-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 3200; /* above .dialog-overlay */
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.tp-overlay.open { display: flex; }
.tp-sheet {
    background: var(--bg-color, #fff);
    width: 560px;
    max-width: 100%;
    max-height: 82vh;
    border-radius: 14px;
    box-shadow: 0 16px 48px rgba(0,0,0,0.3);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
@media (max-width: 600px) {
    .tp-overlay { align-items: flex-end; padding: 0; }
    .tp-sheet { width: 100%; max-height: 88vh; border-radius: 14px 14px 0 0; }
}
.tp-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color, #e3e6ea);
}
.tp-head h4 { margin: 0; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.tp-close {
    background: var(--bg-main, #f2f4f6);
    border: none;
    width: 30px; height: 30px;
    border-radius: 50%;
    cursor: pointer;
    color: var(--text-secondary);
}
.tp-search-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 12px 16px 0;
    padding: 8px 12px;
    border: 1px solid var(--border-color, #e3e6ea);
    border-radius: 10px;
    background: var(--bg-main, #f8f9fa);
}
.tp-search-row i { color: var(--text-secondary); font-size: 13px; }
.tp-search-row input {
    border: none;
    outline: none;
    background: transparent;
    flex: 1;
    font-family: inherit;
    font-size: 13px;
}
.tp-cats {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding: 12px 16px 8px;
}
.tp-cat {
    border: 1px solid var(--border-color, #e3e6ea);
    background: var(--bg-color, #fff);
    color: var(--text-secondary);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    cursor: pointer;
    font-family: inherit;
}
.tp-cat.on {
    background: #0078d4;
    border-color: #0078d4;
    color: #fff;
    font-weight: 600;
}
.tp-list {
    overflow-y: auto;
    padding: 4px 12px 14px;
    flex: 1;
}
.tp-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    margin-top: 6px;
    border: 1px solid var(--border-color, #e3e6ea);
    border-radius: 10px;
    background: var(--bg-color, #fff);
    cursor: pointer;
    font-family: inherit;
    text-align: right;
    transition: all 0.12s;
}
.tp-item:hover {
    border-color: #0078d4;
    background: rgba(0, 120, 212, 0.05);
}
.tp-item-icon { flex-shrink: 0; display: flex; align-items: center; }
.tp-item-name { flex: 1; font-size: 13px; font-weight: 600; color: var(--text-color, #222); }
.tp-no-tooth { display: block; margin-top: 2px; color: #0f766e; font-size: 10px; font-weight: 600; }
.tp-item-price { font-size: 12.5px; font-weight: 700; color: #107c10; }
.tp-empty { text-align: center; padding: 30px 0; color: var(--text-secondary); font-size: 13px; }

/* Picker trigger button inside the add dialog */
.tp-trigger {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border: 1px solid var(--border-color, #d5dbe1);
    border-radius: 6px;
    background: var(--bg-color, #fff);
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
    color: var(--text-secondary);
    min-height: 38px;
    text-align: right;
}
.tp-trigger:hover { border-color: #0078d4; }
.tp-btn-icon { display: flex; align-items: center; flex-shrink: 0; }
.tp-btn-name { flex: 1; color: var(--text-color, #222); font-weight: 600; }
.tp-btn-price { font-weight: 700; color: #107c10; font-size: 12.5px; }

/* ── Surface picker ── */
.surface-picker {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px;
    border: 1px solid var(--border-color, #e3e6ea);
    border-radius: 10px;
    background: var(--bg-main, #f8f9fa);
}
.surface-tooth-holder { width: 74px; height: 100px; flex-shrink: 0; }
.surface-tooth-holder svg { width: 100%; height: 100%; }
.surface-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.surface-chip {
    border: 1px solid var(--border-color, #d5dbe1);
    background: var(--bg-color, #fff);
    color: var(--text-secondary);
    padding: 5px 11px;
    border-radius: 16px;
    font-size: 12px;
    cursor: pointer;
    font-family: inherit;
}
.surface-chip.on {
    background: #0078d4;
    border-color: #0078d4;
    color: #fff;
    font-weight: 600;
}

/* ── Occlusal arch chart (top view) ── */
.arch-occ-topbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}
.arch-occ-topbar-label {
    font-weight: 700;
    font-size: 14px;
    color: var(--text-color, #222);
    display: flex;
    align-items: center;
    gap: 8px;
}
.arch-occ-topbar-label i { color: #0078d4; }
.arch-occ-chip {
    background: #e7f1fb;
    color: #0067b8;
    border: 1px solid #c4ddf3;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 14px;
    border-radius: 16px;
}
.arch-occ-panel {
    display: flex;
    gap: 16px;
    align-items: stretch;
    border: 1.5px solid #74c69d;
    background: var(--bg-color, #fff);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.arch-occ-chart {
    flex: 1;
    min-width: 300px;
    max-width: 560px;
    margin: 0 auto;
}
.arch-occ-chart svg { width: 100%; height: auto; display: block; }
.arch-occ-info {
    width: 300px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
@media (max-width: 860px) {
    .arch-occ-info { width: 100%; }
}
.arch-occ-title {
    display: flex;
    align-items: center;
    gap: 10px;
}
.arch-occ-title h4 { margin: 0; font-size: 15px; }
.arch-occ-title small { color: var(--text-secondary); font-size: 11px; }
.arch-occ-mouth {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: #d8f3e3;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.arch-occ-mouth::after {
    content: '';
    width: 26px;
    height: 15px;
    border: 3px solid #2f9e44;
    background: #fff;
    display: block;
}
.arch-occ-mouth--upper::after { border-radius: 13px 13px 5px 5px; border-bottom-width: 4px; }
.arch-occ-mouth--lower::after { border-radius: 5px 5px 13px 13px; border-top-width: 4px; }
.arch-occ-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.arch-occ-stat {
    background: var(--bg-main, #f7f8fa);
    border: 1px solid var(--border-light, #eceff2);
    border-radius: 10px;
    padding: 8px 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.arch-occ-stat b { font-size: 16px; color: var(--text-color, #222); }
.arch-occ-stat small { color: var(--text-secondary); font-size: 11px; }
.arch-occ-stat-icon { display: flex; align-items: center; flex-shrink: 0; }
.arch-occ-statusbar {
    background: #e8f7ee;
    border: 1px solid #bfe6cd;
    color: #217a3c;
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: auto;
}
.arch-occ-statusbar.is-empty {
    background: var(--bg-main, #f4f5f7);
    border-color: var(--border-light, #e3e6ea);
    color: var(--text-secondary);
}
.arch-occ-legend {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px 26px;
    background: var(--bg-color, #fff);
    border-radius: 12px;
    padding: 12px 16px;
    border: 1px solid var(--border-light, #eceff2);
}
.arch-occ-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-color, #333);
}
.arch-tooth { cursor: pointer; }
.arch-tooth:hover .at-body { stroke: #0078d4; stroke-width: 2.6px; }
.arch-tooth.selected .at-body { stroke: #0078d4; stroke-width: 3px; }
.arch-num:hover rect { stroke: #0078d4; }

/* Modern Treatment Dialog Styles */
.treatment-dialog-modern {
    max-width: 780px;
    width: 96%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
    background: #fff;
}

.treatment-dialog-header {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.treatment-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.treatment-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.treatment-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.treatment-dialog-body {
    padding: 16px 20px;
    max-height: calc(100vh - 130px);
    overflow-y: auto;
}

.treatment-alert {
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.treatment-alert-warning {
    background: #fff3e0;
    border: 1px solid #ffe0b2;
}

.treatment-alert-warning i {
    color: #f57c00;
    font-size: 18px;
}

.treatment-alert-warning span {
    color: #e65100;
    font-size: 13px;
}

.treatment-alert-warning a {
    color: #0078d4;
    text-decoration: underline;
}

.treatment-form-grid {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 12px;
    margin-bottom: 12px;
}

.treatment-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
}

.treatment-form-field.treatment-field-full {
    grid-column: 1 / -1;
}

.treatment-form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 6px;
}

.treatment-form-field label i {
    color: #0078d4;
    font-size: 11px;
}

.treatment-form-field label .required {
    color: #f44336;
}

.treatment-form-field input,
.treatment-form-field select {
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fff;
}

.treatment-form-field input:focus,
.treatment-form-field select:focus {
    border-color: #0078d4;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}

/* Pricing Section */
.treatment-pricing-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 12px;
}

.treatment-pricing-section .pricing-header {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.treatment-pricing-section .pricing-content {
    padding: 14px;
}

.treatment-pricing-section .price-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.treatment-pricing-section .price-row:last-child {
    margin-bottom: 0;
}

.treatment-pricing-section .price-label {
    font-size: 12px;
    color: #546e7a;
    min-width: 100px;
}

.treatment-pricing-section .price-value {
    font-size: 14px;
    font-weight: 700;
    color: #2e7d32;
}

.treatment-pricing-section .price-currency {
    font-size: 12px;
    color: #78909c;
}

.treatment-pricing-section .agreement-price input {
    flex: 1;
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    max-width: 120px;
}

.treatment-pricing-section .agreement-price input:focus {
    border-color: #ff9800;
    box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.1);
    outline: none;
}

.treatment-pricing-section .use-default-btn {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.treatment-pricing-section .use-default-btn:hover {
    transform: rotate(180deg);
}

/* Total Display */
.treatment-total-display {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border: 1px solid #a5d6a7;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 12px;
}

.treatment-total-display .total-label {
    font-size: 13px;
    font-weight: 600;
    color: #2e7d32;
}

.treatment-total-display .total-value {
    font-size: 18px;
    font-weight: 700;
    color: #1b5e20;
}

.treatment-total-display .total-currency {
    font-size: 13px;
    color: #388e3c;
}

/* Dialog Footer */
.treatment-dialog-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    flex-direction: row-reverse;
}

.treatment-btn-primary,
.treatment-btn-secondary {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.treatment-btn-primary {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    color: white;
}

.treatment-btn-primary:hover {
    background: linear-gradient(135deg, #005a9e 0%, #004578 100%);
    transform: translateY(-1px);
}

.treatment-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.treatment-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}

@media (max-width: 480px) {
    .treatment-form-grid {
        grid-template-columns: 1fr;
    }
    .treatment-form-field.treatment-field-full {
        grid-column: 1;
    }
}
</style>

<!-- Add New Treatment (to treatments table) Dialog -->
<div class="dialog-overlay" id="addNewTreatmentDialog">
    <div class="dialog" style="max-width: 420px;">
        <div class="dialog-header" style="background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%); color: #fff;">
            <h4 class="dialog-title" style="color: #fff;"><i class="fas fa-briefcase-medical"></i> زیادکردنی چارەسەری نوێ</h4>
            <button class="dialog-close" onclick="closeDialog('addNewTreatmentDialog')" style="color:#fff;"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body" style="padding: 16px;">
            <div style="margin-bottom: 12px;">
                <label style="display:block; margin-bottom:4px; font-weight:600; font-size:13px;"><i class="fas fa-tag"></i> ناوی چارەسەر <span style="color:#f44336;">*</span></label>
                <input type="text" id="newTreatmentName" placeholder="بۆ نموونە: پڕکردنەوەی مۆرەکان" style="width:100%; padding:8px 10px; border:1.5px solid #cfd8dc; border-radius:6px; font-size:13px;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display:block; margin-bottom:4px; font-weight:600; font-size:13px;"><i class="fas fa-coins"></i> نرخی پێشنیارکراو</label>
                <input type="number" id="newTreatmentPrice" min="0" step="1000" placeholder="0" style="width:100%; padding:8px 10px; border:1.5px solid #cfd8dc; border-radius:6px; font-size:13px; direction:ltr; text-align:left;">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display:block; margin-bottom:4px; font-weight:600; font-size:13px;"><i class="fas fa-layer-group"></i> جۆر</label>
                <select id="newTreatmentCategory" style="width:100%; padding:8px 10px; border:1.5px solid #cfd8dc; border-radius:6px; font-size:13px;">
                    <option value="other">تر</option>
                    <option value="examination">پشکنین</option>
                    <option value="cleaning">پاککردنەوە</option>
                    <option value="filling">پڕکردنەوە</option>
                    <option value="extraction">دەرهێنان</option>
                    <option value="root_canal">گەڕی ڕەگ</option>
                    <option value="crown">تاج</option>
                    <option value="bridge">پرد</option>
                    <option value="implant">چاندن</option>
                    <option value="orthodontic">پەلکەزێرینە</option>
                    <option value="whitening">سپیکردنەوە</option>
                    <option value="cosmetic">جوانکاری</option>
                    <option value="surgery">نەشتەرگەری</option>
                </select>
            </div>
            <div id="newTreatmentError" style="color:#f44336; font-size:12px; margin-top:6px; display:none;"></div>
        </div>
        <div class="dialog-footer" style="padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0; display: flex; gap: 8px; flex-direction: row-reverse;">
            <button type="button" onclick="submitNewTreatment()" id="submitNewTreatmentBtn"
                    style="padding: 8px 16px; background: linear-gradient(135deg, #0078d4, #005a9e); color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px;">
                <i class="fas fa-check"></i> زیادکردن
            </button>
            <button type="button" onclick="closeDialog('addNewTreatmentDialog')"
                    style="padding: 8px 16px; background:#fff; color:#546e7a; border:1.5px solid #e0e0e0; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px;">
                <i class="fas fa-times"></i> هەڵوەشاندنەوە
            </button>
        </div>
    </div>
</div>

<!-- Edit Treatment Dialog -->
<div class="dialog-overlay" id="editTreatmentDialog">
    <div class="dialog" style="max-width: 420px;">
        <div class="dialog-header installment-dialog-header">
            <h4 class="dialog-title"><i class="fas fa-edit"></i> دەستکاری چارەسەر</h4>
            <button class="dialog-close" onclick="closeDialog('editTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_treatment">
            <input type="hidden" name="treatment_id" id="edit_treatment_id">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی چارەسەر</label>
                    <input type="text" name="treatment_name" id="edit_treatment_name" class="form-control" required>
                </div>
                <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label">ژمارە</label>
                        <input type="number" name="quantity" id="edit_quantity" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">نرخ</label>
                        <input type="number" name="unit_price" id="edit_unit_price" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">داشکاندن</label>
                        <input type="number" name="discount" id="edit_discount" class="form-control" value="0">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">تێبینی</label>
                    <input type="text" name="notes" id="edit_notes" class="form-control" placeholder="تێبینی...">
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('editTreatmentDialog')">داخستن</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوت</button>
            </div>
        </form>
    </div>
</div>

<!-- Arch Treatment Dialog -->
<div class="dialog-overlay" id="archTreatmentDialog">
    <div class="dialog arch-dialog-modern">
        <div class="arch-dialog-header">
            <h4><i class="fas fa-teeth"></i> زیادکردنی چارەسەری ئەرک</h4>
            <button type="button" class="arch-dialog-close" onclick="closeDialog('archTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="archTreatmentForm" onsubmit="return validateArchForm()">
            <input type="hidden" name="action" value="add_arch_treatment">
            <input type="hidden" name="arch_type" id="archTreatmentArchType" value="">
            <div class="arch-dialog-body">
              <div class="pl-grid">
                <div class="pl-col">
                <!-- Arch Selection -->
                <div class="arch-form-field">
                    <label><i class="fas fa-teeth-open"></i> هەڵبژاردنی ئەرک <span class="required">*</span></label>
                    <div class="arch-select-chart-modern">
                        <!-- Upper Arch -->
                        <div class="arch-select-panel" data-arch="upper" onclick="selectArchForTreatment('upper')">
                            <div class="arch-select-visual">
                                <svg viewBox="0 0 280 80" class="arch-select-svg">
                                    <path d="M 15 65 Q 140 -10 265 65" fill="none" stroke="#0078d4" stroke-width="2.5" class="arch-select-outline"/>
                                    <g class="arch-teeth-indicators">
                                        <rect x="20" y="42" width="14" height="20" rx="2"/>
                                        <rect x="38" y="34" width="14" height="24" rx="2"/>
                                        <rect x="56" y="26" width="14" height="28" rx="2"/>
                                        <rect x="74" y="20" width="12" height="30" rx="2"/>
                                        <rect x="90" y="16" width="11" height="32" rx="2"/>
                                        <rect x="105" y="13" width="10" height="34" rx="2"/>
                                        <rect x="119" y="11" width="9" height="35" rx="2"/>
                                        <rect x="132" y="10" width="8" height="36" rx="2"/>
                                        <rect x="144" y="10" width="8" height="36" rx="2"/>
                                        <rect x="156" y="11" width="9" height="35" rx="2"/>
                                        <rect x="169" y="13" width="10" height="34" rx="2"/>
                                        <rect x="183" y="16" width="11" height="32" rx="2"/>
                                        <rect x="198" y="20" width="12" height="30" rx="2"/>
                                        <rect x="214" y="26" width="14" height="28" rx="2"/>
                                        <rect x="232" y="34" width="14" height="24" rx="2"/>
                                        <rect x="250" y="42" width="14" height="20" rx="2"/>
                                    </g>
                                </svg>
                            </div>
                            <div class="arch-select-label">
                                <i class="fas fa-check-circle arch-select-check"></i>
                                <span>ئەرکی سەرەوە (Maxilla)</span>
                                <span class="arch-select-count">یەک کاری سەرانسەری ئەرک</span>
                            </div>
                        </div>

                        <!-- Lower Arch -->
                        <div class="arch-select-panel" data-arch="lower" onclick="selectArchForTreatment('lower')">
                            <div class="arch-select-visual">
                                <svg viewBox="0 0 280 80" class="arch-select-svg">
                                    <path d="M 20 15 Q 140 90 260 15" fill="none" stroke="#0078d4" stroke-width="2.5" class="arch-select-outline"/>
                                    <g class="arch-teeth-indicators">
                                        <rect x="25" y="18" width="13" height="18" rx="2"/>
                                        <rect x="42" y="24" width="13" height="22" rx="2"/>
                                        <rect x="59" y="30" width="13" height="25" rx="2"/>
                                        <rect x="76" y="36" width="11" height="27" rx="2"/>
                                        <rect x="91" y="40" width="10" height="29" rx="2"/>
                                        <rect x="105" y="44" width="9" height="30" rx="2"/>
                                        <rect x="118" y="46" width="8" height="31" rx="2"/>
                                        <rect x="130" y="48" width="7" height="32" rx="2"/>
                                        <rect x="141" y="48" width="7" height="32" rx="2"/>
                                        <rect x="152" y="46" width="8" height="31" rx="2"/>
                                        <rect x="164" y="44" width="9" height="30" rx="2"/>
                                        <rect x="177" y="40" width="10" height="29" rx="2"/>
                                        <rect x="191" y="36" width="11" height="27" rx="2"/>
                                        <rect x="206" y="30" width="13" height="25" rx="2"/>
                                        <rect x="223" y="24" width="13" height="22" rx="2"/>
                                        <rect x="240" y="18" width="13" height="18" rx="2"/>
                                    </g>
                                </svg>
                            </div>
                            <div class="arch-select-label">
                                <i class="fas fa-check-circle arch-select-check"></i>
                                <span>ئەرکی خوارەوە (Mandible)</span>
                                <span class="arch-select-count">یەک کاری سەرانسەری ئەرک</span>
                            </div>
                        </div>
                    </div>
                    <p class="arch-select-hint-modern"><i class="fas fa-info-circle"></i> کرتە بکە لەسەر ئەرکەکان بۆ هەڵبژاردن. دەتوانیت هەردووکیان هەڵبژێریت.</p>
                </div>

                <div class="arch-form-field">
                    <label><i class="fas fa-briefcase-medical"></i> چارەسەر <span class="required">*</span></label>
                    <select name="treatment_id" id="archTreatmentSelect" onchange="updateArchTreatmentPrice()">
                        <option value="">هەڵبژێرە...</option>
                        <?php foreach ($archTreatments as $t): ?>
                        <option value="<?= $t['id'] ?>" data-price="<?= $t['default_price'] ?>" data-name="<?= htmlspecialchars($t['treatment_name']) ?>">
                            <?= htmlspecialchars($t['treatment_name']) ?> (<?= number_format($t['default_price']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="arch-form-field">
                    <label><i class="fas fa-pen"></i> ناوی چارەسەر <span class="required">*</span></label>
                    <input type="text" name="treatment_name" id="archTreatmentName" required placeholder="ناوی چارەسەر...">
                </div>
                </div><!-- /pl-col left -->

                <div class="pl-col">
                <!-- Pricing Mode Selection -->
                <div class="arch-form-field">
                    <label><i class="fas fa-tags"></i> شێوازی نرخدانان <span class="required">*</span></label>
                    <div class="arch-pricing-mode-selector">
                        <label class="arch-pricing-mode-option selected" data-mode="per_arch">
                            <input type="radio" name="pricing_mode" value="per_arch" checked>
                            <i class="fas fa-teeth-open"></i>
                            <span>نرخ بۆ هەر ئەرکێک</span>
                        </label>
                        <label class="arch-pricing-mode-option" data-mode="total">
                            <input type="radio" name="pricing_mode" value="total">
                            <i class="fas fa-calculator"></i>
                            <span>کۆی گشتی</span>
                        </label>
                        <label class="arch-pricing-mode-option" data-mode="separate" id="separatePriceOption" style="display: none;">
                            <input type="radio" name="pricing_mode" value="separate">
                            <i class="fas fa-divide"></i>
                            <span>نرخی جیاواز</span>
                        </label>
                    </div>
                </div>

                <!-- Per arch job price section -->
                <div class="arch-pricing-section" id="perToothPricing">
                    <div class="arch-pricing-header orange">
                        <i class="fas fa-coins"></i> نرخ بۆ هەر ئەرکێک
                    </div>
                    <div class="arch-pricing-content">
                        <div class="arch-price-row">
                            <span class="arch-price-label">نرخی پێشنیارکراو:</span>
                            <span class="arch-price-value" id="archDefaultPriceDisplay">0</span>
                            <span class="arch-price-currency"></span>
                        </div>
                        <div class="arch-price-row">
                            <span class="arch-price-label">نرخی ڕێککەوتن:</span>
                            <input type="number" name="unit_price" id="archUnitPrice" oninput="calculateArchTotal()" placeholder="نرخ بنووسە...">
                            <span class="arch-price-currency"></span>
                            <button type="button" class="arch-use-default-btn" onclick="useArchDefaultPrice()" title="بەکارهێنانی نرخی پێشنیارکراو">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Total price section (shared only across selected arches) -->
                <div class="arch-pricing-section" id="totalPricing" style="display: none;">
                    <div class="arch-pricing-header green">
                        <i class="fas fa-hand-holding-usd"></i> کۆی نرخی ڕێککەوتن
                    </div>
                    <div class="arch-pricing-content">
                        <div class="arch-price-row">
                            <span class="arch-price-label" style="color: #2e7d32; font-weight: 600;">کۆی پارەی ڕێککەوتن:</span>
                            <input type="number" name="total_price" id="archTotalAgreedPrice" oninput="calculateFromTotal()" placeholder="کۆی نرخ..." class="green-input">
                            <span class="arch-price-currency"></span>
                        </div>
                        <div class="arch-price-row calculated">
                            <span class="arch-price-label">نرخی هەر ئەرکێک:</span>
                            <span class="arch-price-value green" id="calculatedPerToothPrice">0</span>
                            <span class="arch-price-currency"></span>
                        </div>
                    </div>
                </div>

                <!-- Separate Prices Section (upper/lower different) -->
                <div class="arch-pricing-section" id="separatePricing" style="display: none;">
                    <div class="arch-pricing-header purple">
                        <i class="fas fa-layer-group"></i> نرخی جیاواز بۆ هەر ئەرکێک
                    </div>
                    <div class="arch-pricing-content">
                        <div class="arch-price-row">
                            <span class="arch-price-label" style="color: #0078d4; font-weight: 600;">
                                <i class="fas fa-arrow-up"></i> ماکزیلا / سەرەوە:
                            </span>
                            <input type="number" id="upperArchPrice" oninput="calculateSeparateTotal()" placeholder="کۆی نرخ..." class="blue-input">
                            <span class="arch-price-currency"></span>
                        </div>
                        <div class="arch-price-row">
                            <span class="arch-price-label" style="color: #9c27b0; font-weight: 600;">
                                <i class="fas fa-arrow-down"></i> ماندیبل / خوارەوە:
                            </span>
                            <input type="number" id="lowerArchPrice" oninput="calculateSeparateTotal()" placeholder="کۆی نرخ..." class="purple-input">
                            <span class="arch-price-currency"></span>
                        </div>
                    </div>
                </div>

                <!-- Hidden fields for calculated prices -->
                <input type="hidden" name="upper_unit_price" id="hiddenUpperPrice" value="">
                <input type="hidden" name="lower_unit_price" id="hiddenLowerPrice" value="">

                <!-- Arch Total Display -->
                <div class="arch-total-display" id="archTotalDisplay">
                    <span class="arch-total-label">کۆی گشتی (<span id="archTeethCountDisplay">0</span> ئەرک):</span>
                    <span class="arch-total-value" id="archTotalPriceDisplay">0</span>
                    <span class="arch-total-currency"></span>
                </div>

                <div class="arch-form-field">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <input type="text" name="notes" placeholder="تێبینی ئارەزوومەندانە...">
                </div>
                </div><!-- /pl-col right -->
              </div><!-- /pl-grid -->
            </div>
            <div class="arch-dialog-footer">
                <button type="submit" class="arch-btn-primary"><i class="fas fa-plus-circle"></i> زیادکردن بۆ ئەرکی هەڵبژێردراو</button>
                <button type="button" class="arch-btn-secondary" onclick="closeDialog('archTreatmentDialog')"><i class="fas fa-times"></i> داخستن</button>
            </div>
        </form>
    </div>
</div>

<?php if ($isArchCase): ?>
<!-- Procedure Log Dialog -->
<div class="dialog-overlay" id="procedureLogDialog">
    <div class="dialog procedure-log-dialog">
        <div class="procedure-log-dialog-header">
            <h4><i class="fas fa-clipboard-list"></i> <span id="procedureLogDialogTitle">زیادکردنی کار</span></h4>
            <button type="button" class="procedure-log-dialog-close" onclick="closeDialog('procedureLogDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="procedureLogForm">
            <input type="hidden" name="action" id="procedureLogAction" value="add_procedure_log">
            <input type="hidden" name="log_id" id="procedureLogId" value="">
            <div class="procedure-log-dialog-body">
              <div class="pl-grid">
                <div class="pl-col">
                <!-- Procedure Type -->
                <div class="procedure-log-form-group">
                    <label><i class="fas fa-tag"></i> جۆری کار <span class="required">*</span></label>
                    <select name="procedure_type_id" id="procedureTypeSelect" onchange="handleProcedureTypeChange()" required>
                        <option value="">-- هەڵبژاردن --</option>
                        <?php foreach ($procedureTypes as $pt): ?>
                        <option value="<?= $pt['id'] ?>" data-price="<?= $pt['default_price'] ?>">
                            <?= htmlspecialchars($pt['type_name']) ?>
                            <?php if ($pt['type_name_en']): ?>(<?= htmlspecialchars($pt['type_name_en']) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Custom Procedure Name (shown when 'other' is selected) -->
                <div class="procedure-log-form-group" id="customProcedureNameGroup" style="display: none;">
                    <label><i class="fas fa-edit"></i> ناوی کار <span class="required">*</span></label>
                    <input type="text" name="custom_procedure_name" id="customProcedureName" placeholder="ناوی کارەکە بنووسە...">
                </div>

                <!-- Arch Affected -->
                <div class="procedure-log-form-group">
                    <label><i class="fas fa-teeth"></i> ئەرکی کارلێکراو</label>
                    <div class="procedure-log-arch-selector">
                        <label class="procedure-log-arch-option selected" data-arch="both" onclick="selectProcedureLogArch('both')">
                            <input type="radio" name="arch_affected" value="both" checked>
                            <i class="fas fa-teeth"></i>
                            <span>هەردووک</span>
                        </label>
                        <label class="procedure-log-arch-option" data-arch="upper" onclick="selectProcedureLogArch('upper')">
                            <input type="radio" name="arch_affected" value="upper">
                            <i class="fas fa-teeth"></i>
                            <span>سەرەوە</span>
                        </label>
                        <label class="procedure-log-arch-option" data-arch="lower" onclick="selectProcedureLogArch('lower')">
                            <input type="radio" name="arch_affected" value="lower">
                            <i class="fas fa-teeth"></i>
                            <span>خوارەوە</span>
                        </label>
                    </div>
                </div>

                <!-- Date and Time -->
                <div class="procedure-log-form-row">
                    <div class="procedure-log-form-group">
                        <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                        <input type="date" name="procedure_date" id="procedureDate" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="procedure-log-form-group">
                        <label><i class="fas fa-clock"></i> کات</label>
                        <input type="time" name="procedure_time" id="procedureTime" value="<?= date('H:i') ?>">
                    </div>
                </div>

                <!-- Notes -->
                <div class="procedure-log-form-group">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <textarea name="notes" id="procedureNotes" placeholder="تێبینیەکان لێرە بنووسە..."></textarea>
                </div>

                <input type="hidden" name="price" id="procedurePrice" value="0">
                </div><!-- /pl-col left -->

                <div class="pl-col">
                <!-- Next Appointment -->
                <div class="procedure-log-form-group procedure-log-next-appointment" style="background: #f8f9fa; padding: 10px; border-radius: 8px; margin-top: 0;">
                    <label style="color: #0078d4; margin-bottom: 12px;"><i class="fas fa-calendar-plus"></i> جاری داهاتوو (مەوعید)</label>
                    <!-- Bound to the same appointment engine as the standalone booking:
                         real availability + conflict prevention, not a blind time entry. -->
                    <input type="hidden" name="next_appointment_time" id="procedureNextAppointmentTime" value="">
                    <div class="procedure-log-form-row">
                        <div class="procedure-log-form-group" style="margin-bottom: 0;">
                            <label style="font-size: 12px;">بەرواری ئەوجارە</label>
                            <input type="date" name="next_appointment_date" id="procedureNextAppointmentDate" min="<?= date('Y-m-d') ?>" onchange="loadProcedureLogSlots()">
                        </div>
                        <div class="procedure-log-form-group" style="margin-bottom: 0;">
                            <label style="font-size: 12px;">ماوە</label>
                            <select name="next_appointment_duration" id="procedureNextAppointmentDuration" onchange="loadProcedureLogSlots()">
                                <option value="30">30 خولەک</option>
                                <option value="45">45 خولەک</option>
                                <option value="60" selected>1 کاتژمێر</option>
                                <option value="90">1.5 کاتژمێر</option>
                                <option value="120">2 کاتژمێر</option>
                            </select>
                        </div>
                    </div>
                    <div class="procedure-log-form-group" style="margin: 10px 0 0;">
                        <label style="font-size: 12px;"><i class="fas fa-clock"></i> کات</label>
                        <div id="procedureLogSlotsBox" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:8px;max-height:150px;overflow-y:auto;">
                            <div style="text-align:center;color:#888;font-size:12px;padding:8px;">تکایە بەروار هەڵبژێرە بۆ پیشاندانی کاتە بەردەستەکان</div>
                        </div>
                        <div style="display:flex;gap:12px;margin-top:6px;font-size:11px;color:#78909c;">
                            <span><i class="fas fa-circle" style="color:#4caf50;font-size:7px;"></i> بەردەست</span>
                            <span><i class="fas fa-circle" style="color:#f44336;font-size:7px;"></i> گیراو</span>
                            <span><i class="fas fa-circle" style="color:#ff9800;font-size:7px;"></i> پشوو</span>
                        </div>
                    </div>
                    <div class="procedure-log-form-group" style="margin: 10px 0 0;">
                        <label style="font-size: 12px;">تێبینی</label>
                        <input type="text" name="next_appointment_notes" id="procedureNextAppointmentNotes" placeholder="چی دەکرێت...">
                    </div>
                </div>
                </div><!-- /pl-col right -->
              </div><!-- /pl-grid -->
            </div>
            <div class="procedure-log-dialog-footer">
                <button type="submit" class="procedure-log-btn procedure-log-btn-primary">
                    <i class="fas fa-paper-plane" id="procedureLogSubmitIcon"></i> <span id="procedureLogSubmitText">تۆمارکردن و ناردن بۆ ژمێریار</span>
                </button>
                <button type="button" class="procedure-log-btn procedure-log-btn-secondary" onclick="closeDialog('procedureLogDialog')">
                    <i class="fas fa-times"></i> داخستن
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
/* Modern Arch Dialog Styles */
.arch-dialog-modern {
    max-width: 780px;
    width: 96%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
    background: #fff;
}

.arch-dialog-header {
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.arch-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.arch-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.arch-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.arch-dialog-body {
    padding: 16px 20px;
    max-height: calc(100vh - 130px);
    overflow-y: auto;
}

.arch-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 14px;
}

.arch-form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 6px;
}

.arch-form-field label i {
    color: #9c27b0;
    font-size: 11px;
}

.arch-form-field label .required {
    color: #f44336;
}

.arch-form-field input,
.arch-form-field select {
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fff;
}

.arch-form-field input:focus,
.arch-form-field select:focus {
    border-color: #9c27b0;
    box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
    outline: none;
}

/* Arch Selection Chart */
.arch-select-chart-modern {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.arch-select-panel.selected,
.arch-select-panel:hover {
    border-color: #9c27b0;
    background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
}

.arch-select-hint-modern {
    font-size: 12px;
    color: #78909c;
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.arch-select-hint-modern i {
    color: #9c27b0;
}

/* Pricing Mode Selector */
.arch-pricing-mode-selector {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.arch-pricing-mode-option {
    flex: 1;
    min-width: 120px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    background: #fafafa;
}

.arch-pricing-mode-option input {
    display: none;
}

.arch-pricing-mode-option i {
    color: #9c27b0;
}

.arch-pricing-mode-option:hover {
    border-color: #ce93d8;
    background: #faf5fc;
}

.arch-pricing-mode-option.selected {
    border-color: #9c27b0;
    background: linear-gradient(135deg, #f3e5f5, #e1bee7);
    font-weight: 600;
}

/* Pricing Sections */
.arch-pricing-section {
    background: #f8f9fa;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 14px;
}

.arch-pricing-header {
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    color: white;
}

.arch-pricing-header.orange {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
}

.arch-pricing-header.green {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
}

.arch-pricing-header.purple {
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
}

.arch-pricing-content {
    padding: 14px;
}

.arch-price-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.arch-price-row:last-child {
    margin-bottom: 0;
}

.arch-price-row.calculated {
    background: #e8f5e9;
    margin: 10px -14px -14px;
    padding: 10px 14px;
}

.arch-price-label {
    font-size: 12px;
    color: #546e7a;
    min-width: 110px;
}

.arch-price-value {
    font-size: 14px;
    font-weight: 700;
    color: #ff9800;
}

.arch-price-value.green {
    color: #2e7d32;
}

.arch-price-currency {
    font-size: 12px;
    color: #78909c;
}

.arch-pricing-content input {
    flex: 1;
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    max-width: 140px;
}

.arch-pricing-content input:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
}

.arch-pricing-content input.green-input {
    border-color: #4caf50;
}

.arch-pricing-content input.green-input:focus {
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.arch-pricing-content input.blue-input {
    border-color: #0078d4;
}

.arch-pricing-content input.purple-input {
    border-color: #9c27b0;
}

.arch-use-default-btn {
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.arch-use-default-btn:hover {
    transform: rotate(180deg);
}

/* Arch Total Display */
.arch-total-display {
    background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
    border: 1px solid #ce93d8;
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 14px;
}

.arch-total-display .arch-total-label {
    font-size: 13px;
    font-weight: 600;
    color: #7b1fa2;
}

.arch-total-display .arch-total-value {
    font-size: 20px;
    font-weight: 700;
    color: #4a148c;
}

.arch-total-display .arch-total-currency {
    font-size: 14px;
    color: #9c27b0;
}

/* Dialog Footer */
.arch-dialog-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    flex-direction: row-reverse;
}

.arch-btn-primary,
.arch-btn-secondary {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.arch-btn-primary {
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
    color: white;
}

.arch-btn-primary:hover {
    background: linear-gradient(135deg, #7b1fa2 0%, #6a1b9a 100%);
    transform: translateY(-1px);
}

.arch-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.arch-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}

@media (max-width: 480px) {
    .arch-pricing-mode-selector {
        flex-direction: column;
    }
    .arch-pricing-mode-option {
        min-width: auto;
    }
}

/* Procedure Log Styles */
.procedure-stats-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.procedure-stat-card {
    background: white;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border: 1px solid #e8e8e8;
    flex: 1;
    min-width: 150px;
}

.procedure-stat-card .stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.procedure-stat-card .stat-icon.blue { background: #e3f2fd; color: #1976d2; }
.procedure-stat-card .stat-icon.green { background: #e8f5e9; color: #388e3c; }
.procedure-stat-card .stat-icon.orange { background: #fff3e0; color: #f57c00; }
.procedure-stat-card .stat-icon.purple { background: #f3e5f5; color: #7b1fa2; }
.procedure-stat-card .stat-icon.red { background: #ffebee; color: #c62828; }

.procedure-stat-card .stat-info {
    display: flex;
    flex-direction: column;
}

.procedure-stat-card .stat-value {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
}

.procedure-stat-card .stat-label {
    font-size: 11px;
    color: #888;
}

/* وەسڵەکانی کارە ئەنجامدراوەکان — the documents already sent to accounting.
   Deliberately chip-sized: they are a trail, not the working surface. */
.awr-strip { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.awr-chip {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 6px 11px;
    border: 1px solid #cfe0f0; border-radius: 999px;
    background: #f4f9fe; color: #1a56a8;
    font-size: 12px; font-weight: 700; text-decoration: none;
}
.awr-chip:hover { background: #e8f2fc; }
.awr-chip small { font-weight: 600; font-size: 10.5px; color: #6a7787; }
.awr-chip small.awr-paid { color: #14713b; }
.awr-chip.is-approved { border-color: #bfe3cd; background: #f2fbf5; color: #14713b; }
.awr-chip.is-returned { border-color: #f3c8c8; background: #fdf5f5; color: #b42318; }

.procedure-logs-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.procedure-log-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #e8e8e8;
    overflow: hidden;
    transition: all 0.2s;
}

.procedure-log-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.procedure-log-card .log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    background: linear-gradient(135deg, #f8f9fa, #f5f5f5);
    border-bottom: 1px solid #e8e8e8;
}

.procedure-log-card .log-type {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.log-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.log-type-badge.monthly_adjustment { background: #e3f2fd; color: #1565c0; }
.log-type-badge.wire_change { background: #fff3e0; color: #e65100; }
.log-type-badge.bracket_repair { background: #fce4ec; color: #c2185b; }
.log-type-badge.emergency_visit { background: #ffebee; color: #c62828; }
.log-type-badge.elastic_change { background: #f3e5f5; color: #7b1fa2; }
.log-type-badge.retainer_check { background: #e8f5e9; color: #2e7d32; }
.log-type-badge.other { background: #eceff1; color: #546e7a; }

.log-arch-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.log-arch-badge.upper { background: #e3f2fd; color: #1976d2; }
.log-arch-badge.lower { background: #f3e5f5; color: #7b1fa2; }
.log-arch-badge.both { background: #e8f5e9; color: #388e3c; }

.procedure-log-card .log-date {
    font-size: 12px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 8px;
}

.procedure-log-card .log-time {
    color: #888;
}

.procedure-log-card .log-notes {
    padding: 12px 16px;
    font-size: 13px;
    color: #555;
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
}

.procedure-log-card .log-notes i {
    color: #888;
    margin-left: 6px;
}

.procedure-log-card .log-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    flex-wrap: wrap;
    gap: 10px;
}

.procedure-log-card .log-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12px;
}

.procedure-log-card .log-price {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 8px;
}

.procedure-log-card .log-price.paid { background: #e8f5e9; color: #2e7d32; }
.procedure-log-card .log-price.unpaid { background: #fff3e0; color: #e65100; }

.procedure-log-card .log-next-appointment {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #0078d4;
}

.procedure-log-card .log-next-appointment small {
    color: #888;
}

.procedure-log-card .log-performed-by {
    color: #666;
}

.procedure-log-card .log-actions {
    display: flex;
    gap: 6px;
}

.procedure-log-card .btn-icon {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.procedure-log-card .btn-edit {
    background: #e3f2fd;
    color: #1976d2;
}

.procedure-log-card .btn-edit:hover {
    background: #1976d2;
    color: white;
}

.procedure-log-card .btn-delete {
    background: #ffebee;
    color: #c62828;
}

.procedure-log-card .btn-delete:hover {
    background: #c62828;
    color: white;
}

/* Procedure Log Dialog */
.procedure-log-dialog {
    max-width: 780px;
    width: 96%;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

/* Two-column body so the form fits without scrolling: main info left,
   next appointment right. Collapses to one column on narrow screens. */
.pl-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
}
.pl-col { min-width: 0; }
@media (max-width: 680px) {
    .pl-grid { grid-template-columns: 1fr; gap: 8px; }
}

.procedure-log-dialog-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    padding: 12px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.procedure-log-dialog-header h4 {
    margin: 0;
    color: white;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.procedure-log-dialog-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.procedure-log-dialog-close:hover {
    background: rgba(255,255,255,0.3);
}

.procedure-log-dialog-body {
    padding: 14px 18px;
    max-height: calc(100vh - 130px);
    overflow-y: auto;
}

.procedure-log-form-group {
    margin-bottom: 8px;
}

.procedure-log-form-group label {
    display: block;
    margin-bottom: 4px;
    font-weight: 600;
    font-size: 12px;
    color: #333;
}

.procedure-log-form-group label .required {
    color: #c62828;
}

.procedure-log-form-group input,
.procedure-log-form-group select,
.procedure-log-form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    box-sizing: border-box;
}

.procedure-log-form-group input:focus,
.procedure-log-form-group select:focus,
.procedure-log-form-group textarea:focus {
    border-color: #0078d4;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,120,212,0.1);
}

.procedure-log-form-group textarea {
    min-height: 54px;
    resize: vertical;
}

.procedure-log-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.procedure-log-arch-selector {
    display: flex;
    gap: 6px;
}

.procedure-log-arch-option {
    flex: 1;
    padding: 8px 10px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s;
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.procedure-log-arch-option:hover {
    border-color: #0078d4;
    background: #f0f7ff;
}

.procedure-log-arch-option.selected {
    border-color: #0078d4;
    background: #e3f2fd;
}

.procedure-log-arch-option input {
    display: none;
}

.procedure-log-arch-option i {
    font-size: 14px;
    color: #0078d4;
}

.procedure-log-arch-option span {
    font-size: 11px;
    font-weight: 600;
}

.procedure-log-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.procedure-log-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.procedure-log-dialog-footer {
    padding: 10px 18px;
    background: #f8f9fa;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    border-top: 1px solid #e8e8e8;
}

.procedure-log-btn {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    border: none;
}

.procedure-log-next-appointment {
    padding: 10px !important;
    border-radius: 8px !important;
    margin-top: 6px !important;
}

.procedure-log-next-appointment > label {
    margin-bottom: 8px !important;
}

.procedure-log-btn-primary {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
}

.procedure-log-btn-primary:hover {
    background: linear-gradient(135deg, #0063b1, #005299);
    transform: translateY(-1px);
}

.procedure-log-btn-secondary {
    background: #e0e0e0;
    color: #333;
}

.procedure-log-btn-secondary:hover {
    background: #d0d0d0;
}

@media (max-width: 600px) {
    .procedure-stats-row {
        flex-direction: column;
    }
    .procedure-log-form-row {
        grid-template-columns: 1fr;
    }
    .procedure-log-arch-selector {
        flex-wrap: wrap;
    }
}
</style>

<!-- New visit tab: opens another draft receipt, for today or a past day -->
<div class="dialog-overlay" id="newVisitTabDialog">
    <div class="dialog" style="max-width: 420px;">
        <div class="dialog-header">
            <h4><i class="fas fa-receipt"></i> وەسڵێکی نوێ</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('newVisitTabDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_invoice_tab">
            <div class="dialog-body">
                <div class="form-group">
                    <label>ڕۆژی سەردان</label>
                    <input type="date" name="work_date" id="newVisitDate" class="form-control"
                           max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                    <small class="text-secondary" style="display:block;margin-top:6px;">
                        هەر وەسڵێک ژمارەی تایبەتی خۆی وەردەگرێت و بە جیا دەنێردرێت بۆ ژمێریار.
                    </small>
                </div>
                <?php if (!$isDoctor): ?>
                <div class="form-group">
                    <label>دکتۆر <span style="color:#dc2626;">*</span></label>
                    <select name="doctor_id" class="form-control" required>
                        <option value="">— دکتۆر هەڵبژێرە —</option>
                        <?php foreach ($invoiceDoctors as $invDoc): ?>
                        <option value="<?= (int)$invDoc['id'] ?>"<?= (int)$invDoc['id'] === (int)($case['doctor_id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars($invDoc['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-secondary" style="display:block;margin-top:6px;">
                        پارەی ئەم وەسڵە دەچێتە سەر ئەم دکتۆرە (سندوق و ڕێژە). وەسڵەکە پیشان دەدات کە تۆ دروستت کردووە.
                    </small>
                </div>
                <?php endif; ?>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('newVisitTabDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> دروستکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Send ONE visit tab to the accountant -->
<style>
/* Intent radios on the send-invoice dialog — same "pick one" card pattern as
   xray/request.php's payment_type radios (.payment-btn/.payment-type-btns),
   under fresh class names so nothing here collides with this page's own
   .payment-btn-primary/-secondary FOOTER buttons. */
.si-intent-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.si-intent-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px; background: #fff; border: 2px solid #e0e0e0; border-radius: 8px;
    cursor: pointer; transition: all 0.2s; font-size: 13px; font-weight: 600; flex: 1 1 auto; justify-content: center;
}
.si-intent-btn input { width: 15px; height: 15px; margin: 0; }
.si-intent-btn.cash:hover, .si-intent-btn.cash.selected { border-color: #4caf50; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); color: #2e7d32; }
.si-intent-btn.cash input { accent-color: #4caf50; }
.si-intent-btn.debt:hover, .si-intent-btn.debt.selected { border-color: #c62828; background: linear-gradient(135deg, #ffebee, #ffcdd2); color: #c62828; }
.si-intent-btn.debt input { accent-color: #c62828; }
.si-intent-btn.installment:hover, .si-intent-btn.installment.selected { border-color: #0078d4; background: linear-gradient(135deg, #e3f2fd, #bbdefb); color: #0d5aa7; }
.si-intent-btn.installment input { accent-color: #0078d4; }
.si-intent-btn.is-disabled { opacity: .48; cursor: not-allowed; filter: grayscale(.35); }
.send-invoice-dialog { width:min(820px,calc(100vw - 28px));max-width:820px!important; }
.si-finance-summary { display:flex;flex-direction:column;gap:7px;margin:14px 0;padding:14px;background:#f7f9fb;border:1px solid #dbe3ea;border-radius:10px; }
.si-finance-row { display:grid;grid-template-columns:minmax(0,1fr) 170px;align-items:center;gap:9px;direction:ltr; }
.si-finance-label { color:#21364c;font-size:13px;font-weight:800;text-align:right;direction:rtl;white-space:nowrap; }
.si-finance-value { position:relative;display:flex;align-items:center;justify-content:center;min-height:37px;padding:5px 42px;background:#fff;border:1px solid #344556;color:#111;font-size:15px;font-weight:900;line-height:1.2;direction:ltr; }
.si-finance-value .si-currency { position:absolute;left:9px;color:#62748a;font-size:13px;font-weight:800; }
.si-finance-value.is-editable { padding:0;overflow:hidden; }
.si-finance-input { flex:1 1 auto;width:100%;min-width:0;height:35px;padding:5px 10px;border:0!important;outline:0!important;background:#fff!important;box-shadow:none!important;color:#5f6974;font-family:inherit;font-size:15px;font-weight:800;line-height:1.2;text-align:center;direction:ltr; }
.si-finance-input:focus { background:#f7fbff!important;box-shadow:inset 0 0 0 2px #7ab8e8!important; }
.si-discount-switch { flex:0 0 118px;align-self:stretch;display:grid;grid-template-columns:1fr 1fr;border-right:1px solid #c7d0d9;direction:ltr; }
.si-discount-mode { border:0;border-right:1px solid #d7dee5;background:#f4f6f8;color:#65768a;font-family:inherit;font-size:12px;font-weight:900;cursor:pointer;transition:background .12s,color .12s; }
.si-discount-mode:last-child { border-right:0; }
.si-discount-mode:hover { background:#e7f1fb;color:#0f6cbd; }
.si-discount-mode.is-active { background:#0f6cbd;color:#fff;box-shadow:inset 0 0 0 1px #095a9e; }
.si-discount-mode:focus-visible { position:relative;z-index:1;outline:2px solid #6cb4ee;outline-offset:-2px; }
.si-finance-hint { padding:2px 4px 0;color:#68788a;font-size:11px;line-height:1.65;text-align:right;direction:rtl; }
.si-finance-row.is-muted .si-finance-value { color:#68717a; }
.si-finance-row.is-remaining .si-finance-value { color:#d62828; }
.si-mode-help { margin-top:7px;padding:7px 10px;border-radius:8px;background:#f8fafc;color:#536273;font-size:11.5px;line-height:1.7; }
.si-installment-box { display:none;margin-top:10px;padding:12px;background:#f0f7ff;border:1px solid #b8d9f5;border-radius:10px; }
.si-installment-grid { display:grid;grid-template-columns:1fr 1fr;gap:9px; }
.si-plan-preview { margin-top:8px;padding:8px 10px;background:#fff;border-radius:8px;color:#0d5aa7;font-size:12px;font-weight:700; }
@media (max-width:620px) {
    .send-invoice-dialog { width:calc(100vw - 16px); }
    .si-finance-summary { padding:9px; }
    .si-finance-row { grid-template-columns:minmax(0,1fr) 125px;gap:6px; }
    .si-finance-label { font-size:11px;white-space:normal; }
    .si-finance-value { min-height:34px;padding-inline:38px;font-size:14px; }
    .si-installment-grid { grid-template-columns:1fr; }
}

.chart-service-work { display: none; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0 0 12px; }
.chart-service-work.has-items { display: grid; }
.chart-service-lane { min-width: 0; padding: 9px; border: 1px solid #dbe7f3; border-radius: 9px; background: #f8fbff; }
.chart-service-lane.is-upper { border-color: #bfdbfe; background: #eff6ff; }
.chart-service-lane.is-lower { border-color: #ddd6fe; background: #f5f3ff; }
.chart-service-lane.is-service { border-color: #99f6e4; background: #f0fdfa; }
.chart-service-title { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; color: #526579; font-size: 11px; font-weight: 800; }
.chart-service-list { display: flex; flex-wrap: wrap; gap: 5px; }
.chart-service-card { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 7px; border: 1px solid rgba(100,116,139,.2); border-radius: 7px; background: #fff; font-size: 10.5px; }
.chart-service-card strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chart-service-card .qty { color: #64748b; }
.chart-service-card .price { color: #b91c1c; font-weight: 800; white-space: nowrap; }
@media (max-width: 760px) { .chart-service-work { grid-template-columns: 1fr; } }
#sendInvoiceDialog .required { color: #c62828; font-weight: 700; }
</style>
<?php if ($canDeleteInvoices): ?>
<div class="dialog-overlay" id="deleteInvoiceDialog" role="dialog" aria-modal="true" aria-labelledby="deleteInvoiceTitle">
    <div class="dialog" style="max-width:550px;">
        <div class="dialog-header">
            <h4 id="deleteInvoiceTitle"><i class="fas fa-trash"></i> سڕینەوەی پسووڵە</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('deleteInvoiceDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete_invoice">
            <input type="hidden" name="invoice_id" id="deleteInvoiceId">
            <div class="dialog-body">
                <p><strong id="deleteInvoiceNumber"></strong></p>
                <div class="alert alert-danger">ئەم پسووڵەیە و چارەسەرەکان، پارەدانەکان، قیستەکان و هەموو تۆمارە پەیوەندیدارەکانی بە هەمیشەیی دەسڕێنەوە. بەشی پارەدانە هاوبەشەکان کە بۆ پسووڵەکانی ترە دەپارێزرێت. ناتوانرێت بگەڕێنرێتەوە.</div>
                <div class="form-group">
                    <label class="form-label required" for="deleteInvoiceReason">هۆکاری سڕینەوە</label>
                    <textarea class="form-control" id="deleteInvoiceReason" name="deletion_reason" required maxlength="2000" rows="4"></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('deleteInvoiceDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> سڕینەوەی هەمیشەیی</button>
            </div>
        </form>
    </div>
</div>
<script>
function openDeleteInvoiceDialog(invoiceId, invoiceNumber) {
    const dialog = document.getElementById('deleteInvoiceDialog');
    dialog.querySelector('form').reset();
    dialog.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = false; });
    document.getElementById('deleteInvoiceId').value = invoiceId;
    document.getElementById('deleteInvoiceNumber').textContent = invoiceNumber;
    openDialog('deleteInvoiceDialog');
    document.getElementById('deleteInvoiceReason').focus();
}
</script>
<?php endif; ?>
<?php if (!empty($canToggleHistorical)): ?>
<!-- نوێ / کۆن for an already-open case -->
<div class="dialog-overlay" id="caseHistoricalDialog">
    <div class="dialog" style="max-width: 460px;">
        <div class="dialog-header">
            <h4><i class="fas fa-history"></i> جۆری کەیس</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('caseHistoricalDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="set_case_historical">
            <div class="dialog-body">
                <?php if (!empty($case['is_historical'])): ?>
                <input type="hidden" name="is_historical" value="0">
                <p style="margin:0; line-height:1.8;">
                    ئەم کەیسە دەگۆڕدرێت بۆ <strong>نوێ</strong>. بڕی <strong>دراوی پێشوو</strong> لەسەر وەسڵە نەنێردراوەکان سفر دەکرێتەوە.
                </p>
                <?php else: ?>
                <input type="hidden" name="is_historical" value="1">
                <div class="form-group">
                    <label class="form-label required">بەرواری کاری کۆن</label>
                    <input type="date" name="historical_date" class="form-control" required max="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars(substr((string)($case['created_at'] ?? date('Y-m-d')), 0, 10)) ?>">
                </div>
                <div style="font-size: 12px; color: #7a4a12; background: #fff8ee; border-radius: 6px; padding: 8px 10px; line-height: 1.8;">
                    وەسڵە نەنێردراوەکانی ئەم کەیسە دەچنە سەر ئەو بەروارە. دواتر لە وەسڵەکەدا بڕی <strong>دراوی پێشوو</strong> بنووسە؛
                    ئەو پارەیە قەرز کەم دەکاتەوە بەڵام ناچێتە ناو داهاتی سندوق، و ماوەکەی دەبێتە قەرز.
                </div>
                <?php endif; ?>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('caseHistoricalDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> خەزنکردن</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="dialog-overlay" id="sendInvoiceDialog">
    <div class="dialog send-invoice-dialog">
        <div class="dialog-header">
            <h4><i class="fas fa-paper-plane"></i> ناردنی وەسڵ بۆ ژمێریار</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('sendInvoiceDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="sendInvoiceForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="send_invoice">
            <input type="hidden" name="invoice_id" id="sendInvoiceId" value="">
            <div class="dialog-body">
                <p style="margin:0 0 12px;font-size:13px;">
                    وەسڵی <strong id="sendInvoiceNo" dir="ltr"></strong> دەنێردرێت بۆ ژمێریار.
                    دوای ناردن ناتوانیت چارەسەرەکانی بگۆڕیت.
                </p>
                <div class="si-finance-summary">
                    <div class="si-finance-row">
                        <div class="si-finance-value" id="sendInvoiceGross">0</div>
                        <div class="si-finance-label">کۆی گشتی :</div>
                    </div>
                    <div class="si-finance-row is-muted">
                        <div class="si-finance-value is-editable">
                            <input type="hidden" name="discount_mode" id="sendDiscountMode" value="amount">
                            <div class="si-discount-switch" role="group" aria-label="جۆری داشکاندن">
                                <button type="button" class="si-discount-mode is-active" data-discount-mode="amount"
                                        aria-pressed="true" onclick="setSendDiscountMode('amount')">IQD</button>
                                <button type="button" class="si-discount-mode" data-discount-mode="percent"
                                        aria-pressed="false" onclick="setSendDiscountMode('percent')">%</button>
                            </div>
                            <input type="number" name="discount_value" id="sendDiscountValue" class="si-finance-input"
                                   min="0" step="0.01" value="0" inputmode="decimal" aria-label="بڕی داشکاندن">
                        </div>
                        <div class="si-finance-label">داشکاندن :</div>
                    </div>
                    <div class="si-finance-row">
                        <div class="si-finance-value" id="sendInvoiceNet">0</div>
                        <div class="si-finance-label">دوای داشکاندن :</div>
                    </div>
                    <div class="si-finance-row is-muted">
                        <div class="si-finance-value is-editable">
                            <input type="number" name="received_amount" id="sendReceivedAmount" class="si-finance-input"
                                   min="0" step="0.01" value="0" inputmode="decimal" aria-label="بڕی وەرگیراو">
                        </div>
                        <div class="si-finance-label">وەرگیراو :</div>
                    </div>
                    <div class="si-finance-row is-muted" id="sendPriorRow" style="display:none;">
                        <div class="si-finance-value" id="sendInvoicePrior" style="color:#7a4a12;">0</div>
                        <div class="si-finance-label" style="color:#7a4a12;"><i class="fas fa-history"></i> دراوی پێشوو :</div>
                    </div>
                    <div class="si-finance-row is-remaining">
                        <div class="si-finance-value" id="sendInvoiceRemaining">0</div>
                        <div class="si-finance-label">ماوە :</div>
                    </div>
                    <div class="si-finance-hint" id="sendMoneyHint">
                        بڕی وەرگیراو بەشێکە لەم وەسڵە و لەگەڵ کۆی گشتی و داشکاندن بەیەکەوە بۆ ژمێریار دەنێردرێت؛ قەبزی پارەدانێکی جیا دروست ناکات.
                    </div>
                </div>
                <div class="form-group">
                    <label>جۆری پارەدانی چاوەڕوانکراو <span class="required">*</span></label>
                    <div class="si-intent-btns" id="sendIntentBtns">
                        <label class="si-intent-btn cash selected">
                            <input type="radio" name="payment_intent" value="cash" checked required>
                            <i class="fas fa-money-bill-wave"></i><span>نەقد / بەشەکی</span>
                        </label>
                        <label class="si-intent-btn debt">
                            <input type="radio" name="payment_intent" value="debt" required>
                            <i class="fas fa-hand-holding-usd"></i><span>قەرزی تەواو</span>
                        </label>
                        <label class="si-intent-btn installment">
                            <input type="radio" name="payment_intent" value="installment" required>
                            <i class="fas fa-credit-card"></i><span>قیست</span>
                        </label>
                    </div>
                    <div class="si-mode-help" id="sendIntentHelp"></div>
                </div>
                <div class="form-row" id="sendDebtPromiseGroup" style="display:none;gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>بەرواری بەڵێنکراو (ئارەزوومەندانە)</label>
                        <input type="date" name="promised_date" id="sendPromisedDate" class="form-control" min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>بڕی بەڵێنکراو (ئارەزوومەندانە)</label>
                        <input type="number" name="promised_amount" id="sendPromisedAmount" class="form-control" min="0" step="0.01" placeholder="0">
                    </div>
                </div>
                <div class="si-installment-box" id="sendInstallmentGroup">
                    <div style="font-weight:800;color:#0d5aa7;margin-bottom:8px;"><i class="fas fa-calendar-check"></i> مەرجەکانی پلانی قیست</div>
                    <div class="si-installment-grid">
                        <div class="form-group" style="margin:0;">
                            <label>ژمارەی قیست <span class="required">*</span></label>
                            <input type="number" name="installment_count" id="sendInstallmentCount" class="form-control" min="2" max="60" value="4">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>بەرواری دەستپێک <span class="required">*</span></label>
                            <input type="date" name="installment_start_date" id="sendInstallmentStart" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="si-plan-preview" id="sendInstallmentPreview"></div>
                </div>
                <div class="form-group">
                    <label>تێبینی وەسڵ (ئارەزوومەندانە)</label>
                    <textarea name="notes" id="sendInvoiceNotes" class="form-control" rows="3"
                              placeholder="نموونە: داشکاندنی ٪١٠ ڕەزامەندی لەسەر دراوە"></textarea>
                </div>
                <div class="form-group">
                    <label style="color:#8d6e00;">
                        <i class="fas fa-lock"></i> تێبینی تایبەت بۆ ژمێریار (ناوخۆیی)
                    </label>
                    <textarea name="doctor_note" id="sendDoctorNote" class="form-control" rows="3"
                              maxlength="2000"
                              style="background:#fff8e1;border-color:#ffe082;"
                              placeholder="نموونە: نەخۆش ٣٠،٠٠٠ی دا، ماوەکەی هەفتەی داهاتوو دەدات"></textarea>
                    <div class="form-hint" style="font-size:11.5px;color:#8d6e00;">
                        تەنها ژمێریار دەیبینێت — لەسەر وەسڵی چاپکراو دەرناکەوێت
                    </div>
                </div>
                <?php if ($installmentPlan): ?>
                <div class="form-hint" style="font-size:12px;color:#8a5a00;background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:8px 12px;">
                    <i class="fas fa-info-circle"></i> ئەم کەیسە پێشتر پلانی قیستی هەیە — قیست هەڵبژێرە.
                </div>
                <?php endif; ?>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('sendInvoiceDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> ناردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Receipt print dialog — the A5 sheet from cases/receipt.php shown inline in an
     iframe, so printing never leaves the case page. Printing calls print() on
     the iframe's own window, which prints ONLY the sheet (its @page rule sets
     A5), not the case page around it. -->
<style>
.receipt-dialog {
    max-width: 760px;
    width: calc(100% - 32px);
    max-height: calc(100vh - 48px);
    padding: 0;
    overflow: hidden;
}
.receipt-dialog-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #1b2b3a, #2c4257);
    color: #fff;
}
.receipt-dialog-header h4 { margin: 0; flex: 1 1 auto; font-size: 15px; font-weight: 700; }
.receipt-dialog-header .rd-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: 0;
    border-radius: 7px;
    background: #29a3dc;
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}
.receipt-dialog-header .rd-btn:hover { background: #1e8fc4; }
.receipt-dialog-header .rd-btn-ghost { background: rgba(255,255,255,.14); }
.receipt-dialog-header .rd-btn-ghost:hover { background: rgba(255,255,255,.24); }
.receipt-dialog-header .rd-close {
    width: 32px;
    height: 32px;
    padding: 0;
    justify-content: center;
    border-radius: 8px;
    background: rgba(255,255,255,.14);
}
.receipt-dialog-tabs {
    display: flex;
    gap: 5px;
    padding: 8px 12px;
    background: #22364a;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.receipt-dialog-tabs .rd-tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 999px;
    background: transparent;
    color: #cfe0ef;
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
}
.receipt-dialog-tabs .rd-tab:hover { background: rgba(255,255,255,.08); }
.receipt-dialog-tabs .rd-tab.is-active { background: #29a3dc; border-color: #29a3dc; color: #fff; }
.receipt-dialog-body {
    position: relative;
    flex: 1 1 auto;
    min-height: 0;
    background: #eceff3;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
}
#receiptFrame {
    display: block;
    width: 100%;
    height: 70vh;
    min-height: 320px;
    border: 0;
    background: #eceff3;
}
.receipt-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #eceff3;
    color: #5a6672;
    font-size: 13px;
}
.receipt-loading[hidden] { display: none; }
@media (max-width: 767px) {
    .receipt-dialog { width: calc(100% - 12px); max-height: calc(100vh - 16px); }
    .receipt-dialog-header { flex-wrap: wrap; padding: 10px; }
    .receipt-dialog-header h4 { flex: 1 0 100%; font-size: 14px; }
    #receiptFrame { height: 62vh; }
}
</style>

<div class="dialog-overlay" id="receiptDialog" onclick="if (event.target === this) closeReceiptDialog();">
    <div class="dialog receipt-dialog">
        <div class="receipt-dialog-header">
            <h4><i class="fas fa-receipt"></i> پسوولەی وەرگرتنی پارە</h4>
            <button type="button" class="rd-btn" onclick="printReceipt()">
                <i class="fas fa-print"></i> چاپکردن
            </button>
            <a class="rd-btn rd-btn-ghost" id="receiptNewTabLink" href="<?= APP_URL ?>/cases/receipt.php?id=<?= $caseId ?>&autoprint=0"
               target="_blank" rel="noopener" title="کردنەوە لە پەڕەیەکی نوێ">
                <i class="fas fa-up-right-from-square"></i>
            </a>
            <button type="button" class="rd-btn rd-close" onclick="closeReceiptDialog()" title="داخستن">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php if (!empty($invoiceTabs)): ?>
        <!-- Which receipt: one visit invoice, or the whole case on one sheet -->
        <div class="receipt-dialog-tabs" id="receiptTabs">
            <?php foreach ($invoiceTabs as $inv): ?>
            <button type="button" class="rd-tab" data-invoice="<?= (int)$inv['id'] ?>" onclick="switchReceipt(<?= (int)$inv['id'] ?>)"
                    title="<?= htmlspecialchars($inv['work_date']) ?> — <?= number_format($inv['total_amount']) ?>">
                <span dir="ltr"><?= htmlspecialchars($inv['invoice_no']) ?></span>
                <?php if ($inv['status'] === 'draft'): ?><i class="fas fa-pen-ruler" style="opacity:.7;"></i>
                <?php elseif ($inv['status'] === 'processed'): ?><i class="fas fa-check-double" style="color:#4ade80;"></i><?php endif; ?>
            </button>
            <?php endforeach; ?>
            <button type="button" class="rd-tab" data-invoice="all" onclick="switchReceipt('all')">
                <i class="fas fa-layer-group"></i> هەموو
            </button>
        </div>
        <?php endif; ?>
        <div class="receipt-dialog-body">
            <div class="receipt-loading" id="receiptLoading">
                <i class="fas fa-spinner fa-spin"></i> وەسڵ ئامادە دەکرێت...
            </div>
            <!-- src is set on open, so the receipt is not fetched with every page
                 load. No inline onload: a src-less iframe fires load for
                 about:blank before this page's script has run. -->
            <iframe id="receiptFrame" title="پسوولە"></iframe>
        </div>
    </div>
</div>

<!-- Payment Dialog -->
<div class="dialog-overlay" id="paymentDialog">
    <div class="dialog payment-dialog-modern">
        <div class="payment-dialog-header">
            <h4><i class="fas fa-money-bill-wave"></i> <?= $canReceiveMoney ? 'وەرگرتنی پارە' : 'داواکاری پارەدان' ?></h4>
            <button type="button" class="payment-dialog-close" onclick="closeDialog('paymentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="paymentForm">
            <?= Csrf::field() ?>
            <!-- Doctors send for approval or optionally save a draft;
                 money collection remains restricted by the POST handler. -->
            <input type="hidden" name="action" value="<?= $canReceiveMoney ? 'direct_payment' : 'send_payment_request' ?>">
            <?php if (!$canReceiveMoney): ?>
            <input type="hidden" name="draft_id" value="<?= (int)($doctorPaymentDraft['id'] ?? 0) ?>">
            <?php endif; ?>
            <input type="hidden" name="idempotency_key" value="<?= 'pay-dialog-' . (int)$caseId . '-' . (int)$currentUser['id'] . '-' . bin2hex(random_bytes(16)) ?>">
            <input type="hidden" name="installment_id" id="payment_installment_id" value="">
            <div class="payment-dialog-body">
                <!-- Remaining Balance Display -->
                <?php
                // Doctor requests cannot exceed the balance left after pending requests.
                $paymentDialogMax = $unrequestedBalance;
                // Resume an existing draft, otherwise prefill the available balance.
                $paymentDialogPrefill = (!$canReceiveMoney && $doctorPaymentDraft)
                    ? (float)$doctorPaymentDraft['amount']
                    : $paymentDialogMax;
                ?>
                <div class="payment-remaining-box">
                    <div class="remaining-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="remaining-info">
                        <span class="remaining-label"><?= $canReceiveMoney ? 'بڕی ماوە' : 'قەرزی بەردەست بۆ قەبزی نوێ' ?></span>
                        <span class="remaining-value"><?= number_format($paymentDialogMax) ?> <small></small></span>
                    </div>
                </div>
                <?php if (!$canReceiveMoney && $pendingPaymentsTotal > 0): ?>
                <div style="background:#fff4ce;border:1px solid #ffc107;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:#856404;">
                    <i class="fas fa-hourglass-half"></i> داواکاری چاوەڕوان: <?= number_format($pendingPaymentsTotal) ?> — لە ماوەکە کەمکراوەتەوە
                </div>
                <?php endif; ?>
                <?php if (!$canReceiveMoney): ?>
                <div style="background:#eef6ff;border:1px solid #9ecaf3;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:12px;color:#164b75;">
                    <i class="fas fa-file-pen"></i>
                    دوگمەی «ناردنی داواکاری» قەبزەکە ڕاستەوخۆ بۆ پەسەندکردنی ژمێریار دەنێرێت. دەتوانیت بە دوگمەی «خەزنکردنی ڕەشنووس» بۆ دواتر بیپارێزیت.
                </div>
                <?php endif; ?>

                <div class="payment-form-grid<?= $canReceiveMoney ? '' : ' payment-form-grid-doctor' ?>">
                    <?php if ($canReceiveMoney): ?>
                    <div class="payment-form-field">
                        <label><i class="fas fa-tag"></i> جۆر <span class="required">*</span></label>
                        <select name="payment_type" id="payment_type_select" onchange="toggleInstallmentSelector()" required>
                            <option value="cash">نەقد</option>
                            <option value="credit">قەرز</option>
                            <?php if ($installmentPlan): ?>
                            <option value="installment">قیست</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="payment-form-field">
                        <label><i class="fas fa-coins"></i> بڕ <span class="required">*</span></label>
                        <input type="number" name="amount" id="payment_amount_input" required min="0.01" step="0.01" max="<?= max(0.01, $paymentDialogMax) ?>" value="<?= $paymentDialogPrefill > 0 ? $paymentDialogPrefill : '' ?>" placeholder="بڕی پارە...">
                    </div>
                    <?php if ($canReceiveMoney): ?>
                    <div class="payment-form-field">
                        <label><i class="fas fa-credit-card"></i> شێواز</label>
                        <select name="payment_method">
                            <option value="cash">نەقد</option>
                            <option value="card">کارت</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($installmentPlan && $canReceiveMoney): ?>
                <!-- Installment Selector -->
                <div class="payment-form-field" id="installment_selector_wrapper" style="display: none;">
                    <label><i class="fas fa-calendar-check"></i> هەڵبژاردنی قیست <span class="required">*</span></label>
                    <select id="installment_selector" onchange="selectInstallment()">
                        <option value="">-- هەڵبژاردنی قیست --</option>
                        <?php
                        $schedule = $installmentManager->getSchedule($installmentPlan['id']);
                        foreach ($schedule as $sch):
                            $schRemaining = $sch['amount'] - $sch['paid_amount'];
                            if ($schRemaining > 0):
                        ?>
                        <option value="<?= $sch['id'] ?>"
                                data-amount="<?= $sch['amount'] ?>"
                                data-paid="<?= $sch['paid_amount'] ?>"
                                data-remaining="<?= $schRemaining ?>">
                            قیست #<?= $sch['installment_number'] ?> -
                            <?= date('Y-m-d', strtotime($sch['due_date'])) ?> -
                            ماوە: <?= number_format($schRemaining) ?>
                        </option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if (!$installmentPlan && ($pendingOrRejectedPlan['status'] ?? '') !== 'pending'): ?>
                <?php require __DIR__ . '/partials/payment_discount.php'; ?>
                <?php endif; ?>
                <div class="payment-form-field">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <input type="text" name="notes" placeholder="تێبینی ئارەزوومەندانە...">
                </div>
            </div>
            <div class="payment-dialog-footer">
                <button type="submit" class="payment-btn-primary"><i class="fas <?= $canReceiveMoney ? 'fa-check' : 'fa-paper-plane' ?>"></i> <?= $canReceiveMoney ? 'وەرگرتن' : 'ناردنی داواکاری' ?></button>
                <?php if (!$canReceiveMoney): ?>
                <button type="submit" name="save_only" value="1" class="payment-btn-secondary"><i class="fas fa-save"></i> خەزنکردنی ڕەشنووس</button>
                <?php endif; ?>
                <button type="button" class="payment-btn-secondary" onclick="closeDialog('paymentDialog')"><i class="fas fa-times"></i> داخستن</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modern Payment Dialog Styles */
.payment-dialog-modern {
    max-width: 450px;
    width: 95%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
    background: #fff;
}

.payment-dialog-header {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.payment-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.payment-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.payment-dialog-body {
    padding: 20px;
}

/* Remaining Balance Box */
.payment-remaining-box {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 1px solid #90caf9;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
}

.payment-remaining-box .remaining-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.payment-remaining-box .remaining-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.payment-remaining-box .remaining-label {
    font-size: 12px;
    color: #1565c0;
    font-weight: 500;
}

.payment-remaining-box .remaining-value {
    font-size: 22px;
    font-weight: 700;
    color: #0d47a1;
}

.payment-remaining-box .remaining-value small {
    font-size: 14px;
    font-weight: 500;
}

/* Form Grid */
.payment-form-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 12px;
}

.payment-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
}

.payment-form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 6px;
}

.payment-form-field label i {
    color: #2e7d32;
    font-size: 11px;
}

.payment-form-field label .required {
    color: #f44336;
}

.payment-form-field input,
.payment-form-field select {
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fff;
}

.payment-form-field input:focus,
.payment-form-field select:focus {
    border-color: #2e7d32;
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    outline: none;
}

/* Dialog Footer */
.payment-dialog-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    flex-direction: row-reverse;
}

.payment-btn-primary,
.payment-btn-secondary {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.payment-btn-primary {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
}

.payment-btn-primary:hover {
    background: linear-gradient(135deg, #1b5e20 0%, #0d3d12 100%);
    transform: translateY(-1px);
}

.payment-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.payment-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}

.installment-overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.installment-overview-card {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 14px;
    padding: 14px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    overflow: hidden;
}

.installment-overview-card::before {
    content: '';
    position: absolute;
    inset-inline-start: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--accent-color, #0078d4);
}

.installment-overview-card.accent-primary { --accent-color: #0078d4; }
.installment-overview-card.accent-warning { --accent-color: #ff9800; }
.installment-overview-card.accent-success { --accent-color: #2e7d32; }
.installment-overview-card.accent-danger { --accent-color: #d32f2f; }
.installment-overview-card.accent-neutral { --accent-color: #7b1fa2; }

.installment-overview-label,
.installment-overview-meta {
    font-size: 12px;
    color: var(--text-secondary);
}

.installment-overview-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
}

.installment-finance-note {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 14px 16px;
    margin: 12px 0 16px;
    border-radius: 14px;
    background: linear-gradient(135deg, #fff8e1 0%, #fffdf6 100%);
    border: 1px solid #ffe0b2;
}

.installment-finance-note-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 152, 0, 0.12);
    color: #ef6c00;
    flex-shrink: 0;
}

.installment-finance-note-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.installment-finance-note-content strong {
    font-size: 15px;
    color: var(--text-primary);
}

.installment-finance-note-content span {
    font-size: 12px;
    line-height: 1.8;
    color: var(--text-secondary);
}

.installment-overview-grid + h5.mt-4 {
    display: none;
}

.installment-dialog-modern {
    max-width: 560px;
}

.installment-dialog-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.installment-dialog-title {
    margin-bottom: 4px;
}

.installment-dialog-subtitle {
    margin: 0;
    font-size: 12px;
    color: rgba(255,255,255,0.82);
}

.installment-dialog-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.installment-hero {
    border-radius: 16px;
    padding: 16px 18px;
    background: linear-gradient(135deg, #fff3e0 0%, #fffaf2 100%);
    border: 1px solid #ffe0b2;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.installment-hero-label,
.installment-hero-note,
.installment-field-help,
.installment-preview-label {
    font-size: 12px;
    color: var(--text-secondary);
}

.installment-hero-value,
.installment-preview-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
}

.installment-month-input {
    direction: ltr;
    text-align: left;
    font-variant-numeric: tabular-nums;
}

.installment-visual-box {
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    padding: 12px;
    background: #f8fafc;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.installment-visual-box-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #e2e8f0;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.installment-visual-box-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.installment-visual-box-content strong {
    font-size: 13px;
    color: var(--text-primary);
}

.installment-visual-box-content span {
    font-size: 12px;
    line-height: 1.7;
    color: var(--text-secondary);
}

.installment-preview-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.installment-preview-card {
    border-radius: 14px;
    padding: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

#editTreatmentDialog .installment-preview-grid {
    display: none !important;
}

@media (max-width: 480px) {
    .payment-form-grid {
        grid-template-columns: 1fr;
    }

    .installment-preview-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Installment Dialog -->
<div class="dialog-overlay" id="installmentDialog">
    <div class="dialog installment-dialog-modern">
        <div class="dialog-header installment-dialog-header">
            <h4 class="dialog-title"><i class="fas fa-credit-card"></i> پلانی قیست</h4>
            <button class="dialog-close installment-dialog-close" onclick="closeDialog('installmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="installmentPlanForm">
            <input type="hidden" name="action" value="create_installment">
            <div class="dialog-body installment-dialog-body">
                <div class="installment-hero">
                    <span class="installment-hero-label">بڕی ماوەی کەیس</span>
                    <strong class="installment-hero-value"><?= number_format($remaining) ?></strong>
                    <span class="installment-hero-note">پلانی قیست لەسەر ئەم بڕە دروست دەکرێت</span>
                </div>
                <div class="form-row" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label required">کۆ</label>
                        <input type="number" name="total_amount" id="installment_total_amount" class="form-control" value="<?= $remaining ?>" min="0" required oninput="updateInstallmentPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">پێشەکی</label>
                        <?php if ($canRecordDownPayment): ?>
                        <input type="number" name="down_payment" id="installment_down_payment" class="form-control" value="0" min="0" oninput="updateInstallmentPreview()">
                        <?php if ($canReceiveMoney): ?>
                        <div class="installment-field-help">ئەم بڕە دەستبەجێ وەک پارە تۆمار دەکرێت.</div>
                        <?php else: ?>
                        <div class="installment-field-help">هەموو پلانەکە — لەگەڵ ئەم پێشەکییە — وەک داواکاری بۆ ژمێریار دەنێردرێت؛ لێرە هیچ واصل/پارەدان تۆمار ناکرێت.</div>
                        <?php endif; ?>
                        <?php else: ?>
                        <input type="hidden" name="down_payment" id="installment_down_payment" value="0">
                        <div class="installment-visual-box">
                            <div class="installment-visual-box-icon"><i class="fas fa-lock"></i></div>
                            <div class="installment-visual-box-content">
                                <strong>تەنها بۆ بینین</strong>
                                <span>پێشەکی لەلایەن ئادمین/هەژمار تۆمار دەکرێت. دکتۆر لێرە پارە وەرناگرێت.</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-row" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label required">قیستەکان</label>
                        <input type="text" name="number_of_installments" id="installment_number_of_installments" class="form-control installment-month-input" value="2" required inputmode="numeric" pattern="[0-9]*" min="1" step="1" dir="ltr" lang="en" autocomplete="off" oninput="sanitizeInstallmentCountInput(this); updateInstallmentPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">دەستپێک</label>
                        <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="installment-preview-grid">
                    <div class="installment-preview-card">
                        <span class="installment-preview-label">دوای پێشەکی</span>
                        <strong class="installment-preview-value" id="installmentPreviewRemaining"><?= number_format($remaining) ?></strong>
                    </div>
                    <div class="installment-preview-card">
                        <span class="installment-preview-label">بڕی هەر قیست</span>
                        <strong class="installment-preview-value" id="installmentPreviewMonthly"><?= number_format(ceil($remaining / 2)) ?></strong>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">تێبینی</label>
                    <input type="text" name="notes" class="form-control" placeholder="تێبینی...">
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('installmentDialog')">داخستن</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-check"></i> دروستکردن</button>
            </div>
        </form>
    </div>
</div>

<?php if ($installmentPlan): ?>
<!-- Installment Collection Request Dialog (doctor: monthly قیست collection request) -->
<div class="dialog-overlay" id="installmentCollectionDialog">
    <div class="dialog installment-dialog-modern">
        <div class="dialog-header installment-dialog-header">
            <h4 class="dialog-title"><i class="fas fa-money-bill-wave"></i> داواکاری وەرگرتنی قیست</h4>
            <button class="dialog-close installment-dialog-close" onclick="closeDialog('installmentCollectionDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="flexible_installment_payment">
            <input type="hidden" name="plan_id" value="<?= $installmentPlan['id'] ?>">
            <input type="hidden" name="payment_method" value="cash">
            <div class="dialog-body installment-dialog-body">
                <div class="installment-hero">
                    <span class="installment-hero-label">ماوەی پلان</span>
                    <strong class="installment-hero-value"><?= number_format($installmentSummary['remaining_balance']) ?></strong>
                    <span class="installment-hero-note">
                        <?php if ($installmentNextDue): ?>
                        بەرواری داهاتووی قیست: <?= date('Y-m-d', strtotime($installmentNextDue)) ?>
                        <?php else: ?>
                        هیچ قیستێکی داهاتوو نییە
                        <?php endif; ?>
                    </span>
                </div>
                <div class="form-group">
                    <label class="form-label required">بڕی داواکراو</label>
                    <input type="number" name="amount" class="form-control" required min="1"
                           max="<?= max(1, $installmentAvailableToRequest) ?>"
                           value="<?= $installmentCollectionPrefill > 0 ? $installmentCollectionPrefill : '' ?>">
                    <div class="installment-field-help">زۆرترین بڕی داواکردن: <?= number_format($installmentAvailableToRequest) ?> (ماوەی پلان کەم داواکارییە چاوەڕوانەکان)</div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">تێبینی</label>
                    <input type="text" name="notes" class="form-control" placeholder="تێبینی (ئارەزوومەندانە)...">
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('installmentCollectionDialog')">داخستن</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane"></i> ناردنی داواکاری</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Close Case Dialog -->
<div class="dialog-overlay" id="closeCaseDialog">
    <div class="dialog close-dialog-modern">
        <div class="close-dialog-header">
            <h4><i class="fas fa-check-circle"></i> داخستنی کەیس</h4>
            <button type="button" class="close-dialog-close" onclick="closeDialog('closeCaseDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="close_case">
            <div class="close-dialog-body">
                <div class="close-warning">
                    <i class="fas fa-info-circle"></i>
                    <span>تکایە تێبینی کۆتایی سەبارەت بە ئەم کەیسە بنووسە</span>
                </div>
                <div class="close-form-field">
                    <label><i class="fas fa-comment"></i> تێبینی کۆتایی</label>
                    <textarea name="closing_comment" rows="4" placeholder="تێبینی کۆتایی سەبارەت بە کەیسەکە..."></textarea>
                </div>
            </div>
            <div class="close-dialog-footer">
                <button type="submit" class="close-btn-primary"><i class="fas fa-check-circle"></i> داخستنی کەیس</button>
                <button type="button" class="close-btn-secondary" onclick="closeDialog('closeCaseDialog')"><i class="fas fa-arrow-right"></i> پاشگەزبوونەوە</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Discount Dialog Styles — case-level discount is retired (per-invoice
   discount, cases/partials/invoice_pane.php, supersedes it); markup removed,
   these rules kept since discount-* classes appear nowhere else. */
.discount-dialog-modern {
    max-width: 420px;
    width: 95%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
    background: #fff;
}

.discount-dialog-header {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.discount-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.discount-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.discount-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.discount-dialog-body {
    padding: 20px;
}

.discount-summary-box {
    background: #f5f5f5;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 16px;
}

.discount-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
}

.discount-summary-row span:first-child {
    color: #666;
}

.discount-amount {
    font-weight: 600;
    color: #333;
    font-size: 16px;
}

.discount-type-selector {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.discount-type-option {
    flex: 1;
    cursor: pointer;
}

.discount-type-option input {
    display: none;
}

.discount-type-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 14px 12px;
    background: #f9f9f9;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    transition: all 0.2s;
}

.discount-type-label i {
    font-size: 20px;
    color: #666;
}

.discount-type-label span {
    font-size: 13px;
    color: #666;
}

.discount-type-option input:checked + .discount-type-label {
    background: #fff3e0;
    border-color: #ff9800;
}

.discount-type-option input:checked + .discount-type-label i,
.discount-type-option input:checked + .discount-type-label span {
    color: #f57c00;
}

.discount-input-group {
    margin-bottom: 16px;
}

.discount-input-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #666;
    margin-bottom: 8px;
}

.discount-input-hint {
    font-size: 11px;
    color: #999;
}

.discount-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.discount-input {
    width: 100%;
    padding: 12px 50px 12px 16px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 18px;
    font-weight: 600;
    text-align: center;
    transition: all 0.2s;
}

.discount-input:focus {
    outline: none;
    border-color: #ff9800;
    box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.15);
}

.discount-input-suffix {
    position: absolute;
    left: 16px;
    color: #999;
    font-size: 14px;
    font-weight: 500;
}

.discount-preview-box {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 12px;
}

.discount-preview-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    color: #2e7d32;
    margin-bottom: 8px;
}

.discount-preview-row:last-child {
    margin-bottom: 0;
}

.discount-preview-total {
    padding-top: 10px;
    border-top: 1px dashed #81c784;
    font-weight: 600;
    font-size: 15px;
}

.discount-preview-value {
    font-weight: 600;
}

.discount-validation-message {
    background: #ffebee;
    border: 1px solid #ffcdd2;
    border-radius: 8px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #c62828;
    font-size: 13px;
}

.discount-dialog-footer {
    padding: 16px 20px;
    background: #fafafa;
    display: flex;
    gap: 12px;
    flex-direction: row-reverse;
}

.discount-btn-primary {
    flex: 1;
    padding: 12px 20px;
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.discount-btn-primary:hover {
    background: linear-gradient(135deg, #ffa726 0%, #fb8c00 100%);
    transform: translateY(-1px);
}

.discount-btn-primary:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
}

.discount-btn-secondary {
    padding: 12px 20px;
    background: white;
    color: #666;
    border: 1.5px solid #e0e0e0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.discount-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}

/* Small icon button for edit */
.btn-icon-sm {
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    background: rgba(255, 152, 0, 0.1);
    color: #f57c00;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    font-size: 11px;
}

.btn-icon-sm:hover {
    background: rgba(255, 152, 0, 0.2);
    color: #e65100;
}

@media (max-width: 480px) {
    .discount-type-selector {
        flex-direction: column;
    }

    .discount-dialog-footer {
        flex-direction: column;
    }
}
</style>

<style>
/* Modern Close Dialog Styles */
.close-dialog-modern {
    max-width: 450px;
    width: 95%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
    background: #fff;
}

.close-dialog-header {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.close-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.close-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.close-dialog-body {
    padding: 20px;
}

.close-warning {
    background: #e8f5e9;
    border: 1px solid #c8e6c9;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-warning i {
    color: #4caf50;
    font-size: 20px;
}

.close-warning span {
    color: #2e7d32;
    font-size: 13px;
}

.close-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.close-form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 6px;
}

.close-form-field label i {
    color: #4caf50;
    font-size: 11px;
}

.close-form-field textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    resize: vertical;
}

.close-form-field textarea:focus {
    border-color: #4caf50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
    outline: none;
}

.close-dialog-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    flex-direction: row-reverse;
}

.close-btn-primary,
.close-btn-secondary {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.close-btn-primary {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    color: white;
}

.close-btn-primary:hover {
    background: linear-gradient(135deg, #388e3c 0%, #2e7d32 100%);
    transform: translateY(-1px);
}

.close-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.close-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}
</style>

<!-- Tooth Detail Panel -->
<div class="tooth-detail-overlay" id="toothDetailOverlay" onclick="closeToothDetail()"></div>
<div class="tooth-detail-panel" id="toothDetailPanel">
    <div class="tooth-detail-header">
        <div class="tooth-detail-info">
            <div class="tooth-big-icon" id="toothDetailSvg">
                <i class="fas fa-tooth"></i>
            </div>
            <div class="tooth-detail-title">
                <h3 id="toothDetailNumber">ددان #18</h3>
                <p id="toothDetailName">عەقڵی سەرەوەی چەپ</p>
            </div>
        </div>
        <button class="close-detail-btn" onclick="closeToothDetail()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="tooth-detail-body">
        <div class="tooth-quick-stats" id="toothQuickStats">
            <div class="quick-stat">
                <div class="quick-stat-value" id="toothTreatmentCount">0</div>
                <div class="quick-stat-label">چارەسەر</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-value" id="toothTotalCost">0</div>
                <div class="quick-stat-label">کۆی تێچوو</div>
            </div>
        </div>
        <div class="tooth-detail-section">
            <h4><i class="fas fa-history"></i> مێژووی چارەسەرەکان</h4>
            <div id="toothTreatmentsList">
                <!-- Treatments will be loaded here -->
            </div>
        </div>
    </div>
    <?php if ($case['status'] === 'open'): ?>
    <div class="tooth-detail-footer">
        <button class="btn btn-primary add-treatment-btn-panel" onclick="addTreatmentForTooth()">
            <i class="fas fa-plus"></i> زیادکردنی چارەسەر بۆ ئەم ددانە
        </button>
    </div>
    <?php endif; ?>
</div>

<script>
console.log('=== SCRIPT STARTING ===');

// CSRF Token - properly encoded to prevent syntax errors
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
console.log('CSRF token loaded');

// Tooth names and Palmer notation data - using safe JSON encoding
const toothNames = <?= json_encode($toothNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
console.log('toothNames loaded');

const palmerNumbers = <?= json_encode($palmerNumbers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
console.log('palmerNumbers loaded');

// The teeth of each quadrant in chart order, per tooth set. Everything that
// builds a tooth grid reads these instead of hardcoding 11-48, so the child
// chart works everywhere the adult one does.
const DENTITION_TEETH = <?= json_encode([
    'permanent' => [
        'UR' => array_values($teethSets['permanent']['upper_right']),
        'UL' => array_values($teethSets['permanent']['upper_left']),
        'LL' => array_values($teethSets['permanent']['lower_left']),
        'LR' => array_values($teethSets['permanent']['lower_right']),
    ],
    'primary' => [
        'UR' => array_values($teethSets['primary']['upper_right']),
        'UL' => array_values($teethSets['primary']['upper_left']),
        'LL' => array_values($teethSets['primary']['lower_left']),
        'LR' => array_values($teethSets['primary']['lower_right']),
    ],
]) ?>;
// Mixed dentition is the norm, so every quadrant offers BOTH sets at once:
// permanent (1-8) first, then primary (A-E). FDI keeps them apart — permanent is
// quadrants 1-4, primary is quadrants 5-8 — so a tooth number is never ambiguous.
const DENTITION_MODES = ['permanent', 'primary'];
const QUADRANT_TEETH = (function () {
    const merged = {};
    ['UR', 'UL', 'LL', 'LR'].forEach(q => {
        merged[q] = DENTITION_MODES.reduce((acc, m) => acc.concat(DENTITION_TEETH[m][q] || []), []);
    });
    return merged;
})();

let toothTreatmentMap = <?= json_encode($toothTreatmentMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let serviceTreatmentRows = <?= json_encode($serviceTreatmentRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
console.log('toothTreatmentMap loaded');

// Treatment catalog for the searchable picker (id, name, category, price)
let treatmentCatalog = <?= json_encode(array_map(function ($t) {
    return [
        'id' => (int)$t['id'],
        'name' => $t['treatment_name'],
        'category' => $t['category'] ?? 'other',
        'price' => (float)$t['default_price'],
        'requiresTooth' => (int)($t['requires_tooth_number'] ?? 1) === 1
    ];
}, $toothTreatments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

const CASE_IS_OPEN = <?= $case['status'] === 'open' ? 'true' : 'false' ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
// invoice_id → {no, status, date} for labeling day sections in the treatments table
const CASE_INVOICE_META = <?= json_encode($invoiceMetaById, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

// ═══════════════════════════════════════════════════════════════════
// Smart Dental Chart — anatomical SVG teeth with per-surface states
// ═══════════════════════════════════════════════════════════════════
const CHART_COLORS = {
    amalgam:   '#51b56b',   // پڕکردنەوەی ئاسایی/ئامالگام
    composite: '#f06a94',   // پڕکردنەوەی کۆمپۆزیت
    canal:     '#e8637f',   // چارەسەری دەمار
    crown:     '#dfa32b',   // تاج / پرد
    implant:   '#78909c',   // ئیمپلانت
    extract:   '#4a5560',   // ڕاکێشان / جراحی
    cleaning:  '#2bb3c9',   // پاککردنەوە
    whitening: '#4dabf7',   // سپیکردنەوە
    cosmetic:  '#9775fa',   // ڤینیر / لامینیت
    ortho:     '#f76707',   // ڕاستکردنەوە
    other:     '#4dabf7',   // پشکنین / تر
    outline:   '#b8c3d1',   // tooth outline
    body:      '#ffffff'
};

const SURFACE_LABELS = {
    occlusal: 'سەری ددان',
    mesial:   'لای ناوەند (M)',
    distal:   'لای دواوە (D)',
    buccal:   'ڕووی دەرەوە (B)',
    lingual:  'ڕووی ناوەوە (L)',
    root:     'ڕەگ'
};

const CATEGORY_LABELS = {
    examination: 'پشکنین', cleaning: 'پاککردنەوە', filling: 'پڕکردنەوە',
    extraction: 'ڕاکێشان', root_canal: 'چارەسەری دەمار', crown: 'تاج',
    bridge: 'پرد', implant: 'ئیمپلانت', orthodontic: 'ڕاستکردنەوە',
    whitening: 'سپیکردنەوە', cosmetic: 'جوانکاری', surgery: 'جراحی', other: 'تر'
};
// Categories whose treatments are applied to a specific tooth surface
const SURFACE_CATEGORIES = ['filling', 'cosmetic', 'other', 'examination'];

// Tooth shapes by type (viewBox 0 0 44 60 — crown up, roots down).
// crown = the gold cap overlay, canal = pulp chamber + canals,
// zones = ellipse geometry for the 5 surface areas.
const TOOTH_SHAPES = {
    molar: {
        body: 'M5,17 C5,6 11,3 22,3 C33,3 39,6 39,17 C39,23 37,27 34,30 C34.5,36 33,44 30.5,51 C29.5,54 27,54 26.5,51 C25.8,45 25,39 24.5,35 Q22,32 19.5,35 C19,39 18.2,45 17.5,51 C17,54 14.5,54 13.5,51 C11,44 9.5,36 10,30 C7,27 5,23 5,17 Z',
        crown: 'M6,16 C6,6 12,3 22,3 C32,3 38,6 38,16 C38,20 37,23.5 35.5,26.5 L8.5,26.5 C7,23.5 6,20 6,16 Z',
        chamber: {cx: 22, cy: 25, rx: 4.5, ry: 3},
        canals: ['M16,30 C15.2,36 15,42.5 16.5,48.5', 'M28,30 C28.8,36 29,42.5 27.5,48.5'],
        zones: {
            occlusal: {star: [22, 14.5, 1]},
            left:  {cx: 10, cy: 15, rx: 4.5, ry: 7},
            right: {cx: 34, cy: 15, rx: 4.5, ry: 7},
            buccal:  {cx: 22, cy: 6.5, rx: 9, ry: 4},
            lingual: {cx: 22, cy: 23.5, rx: 9, ry: 4}
        }
    },
    premolar: {
        body: 'M8,16 C8,7 13,4 22,4 C31,4 36,7 36,16 C36,22 34,26 31.5,29 C31,37 29,46 24.5,52.5 Q22,55.5 19.5,52.5 C15,46 13,37 12.5,29 C10,26 8,22 8,16 Z',
        crown: 'M9,15 C9,7 14,4 22,4 C30,4 35,7 35,15 C35,19 34,22.5 32.5,25.5 L11.5,25.5 C10,22.5 9,19 9,15 Z',
        chamber: {cx: 22, cy: 24.5, rx: 3.8, ry: 2.8},
        canals: ['M22,28 C21.8,36 21.8,43 22,49'],
        zones: {
            occlusal: {star: [22, 14, 0.88]},
            left:  {cx: 12, cy: 14.5, rx: 4, ry: 6.2},
            right: {cx: 32, cy: 14.5, rx: 4, ry: 6.2},
            buccal:  {cx: 22, cy: 7, rx: 8, ry: 3.6},
            lingual: {cx: 22, cy: 22.5, rx: 8, ry: 3.6}
        }
    },
    canine: {
        body: 'M22,2.5 C29,4 35,7 35,14.5 C35,20 33,25 30,28.5 C29.5,38 27,47 23.8,53.5 Q22,56.5 20.2,53.5 C17,47 14.5,38 14,28.5 C11,25 9,20 9,14.5 C9,7 15,4 22,2.5 Z',
        crown: 'M22,2.5 C29,4 35,7 35,14.5 C35,18.5 34,22 32,25 L12,25 C10,22 9,18.5 9,14.5 C9,7 15,4 22,2.5 Z',
        chamber: {cx: 22, cy: 24, rx: 3.2, ry: 2.6},
        canals: ['M22,27 C21.8,35 21.9,43 22,50'],
        zones: {
            occlusal: {star: [22, 13.5, 0.82]},
            left:  {cx: 12.5, cy: 14, rx: 3.8, ry: 6},
            right: {cx: 31.5, cy: 14, rx: 3.8, ry: 6},
            buccal:  {cx: 22, cy: 6.5, rx: 7.5, ry: 3.4},
            lingual: {cx: 22, cy: 21.5, rx: 7.5, ry: 3.4}
        }
    },
    incisor: {
        body: 'M11,3.5 C18,2 26,2 33,3.5 C33.5,10 32.5,17 30.5,23.5 C30,32 27,44 23.5,52 Q22,55 20.5,52 C17,44 14,32 13.5,23.5 C11.5,17 10.5,10 11,3.5 Z',
        crown: 'M11,3.5 C18,2 26,2 33,3.5 C33.3,9 32.7,15 31.3,20.5 L12.7,20.5 C11.3,15 10.7,9 11,3.5 Z',
        chamber: {cx: 22, cy: 24, rx: 3, ry: 2.5},
        canals: ['M22,26.5 C21.8,34 21.9,42 22,49.5'],
        zones: {
            occlusal: {star: [22, 12, 0.78]},
            left:  {cx: 13, cy: 12, rx: 3.5, ry: 6},
            right: {cx: 31, cy: 12, rx: 3.5, ry: 6},
            buccal:  {cx: 22, cy: 4.8, rx: 8, ry: 2.8},
            lingual: {cx: 22, cy: 19, rx: 7.5, ry: 3.2}
        }
    }
};

// Fine anatomy lines give each type a soft, clinical three-dimensional form.
// They are drawn underneath treatment overlays, so treatment data remains the
// visual priority and surface picking keeps the same SVG hit targets.
const TOOTH_ANATOMY = {
    molar: [
        'M10.5,13.5 C14.5,17.5 18,17.5 22,13.5 C26,17.5 29.5,17.5 33.5,13.5',
        'M12.5,22 C16.5,19 19.5,20 22,22 C24.5,20 27.5,19 31.5,22'
    ],
    premolar: [
        'M13.5,13 C17.5,17 19.5,17 22,13 C24.5,17 26.5,17 30.5,13',
        'M15,22 C18.5,19.5 21,20.5 22,22 C23,20.5 25.5,19.5 29,22'
    ],
    canine: [
        'M14,14 C17.5,17.5 20,17.5 22,14 C24,17.5 26.5,17.5 30,14',
        'M16,22 C18.5,20 20.5,20 22,22 C23.5,20 25.5,20 28,22'
    ],
    incisor: [
        'M13,11.5 Q22,15.5 31,11.5',
        'M14.5,19.5 Q22,17.2 29.5,19.5'
    ]
};

// Primary teeth live in quadrants 5-8 and run A-E (positions 1-5).
function isPrimaryTooth(fdi) {
    const q = Math.floor(fdi / 10);
    return q >= 5 && q <= 8;
}
function toothShapeKey(fdi) {
    const p = fdi % 10;
    if (p <= 2) return 'incisor';
    if (p === 3) return 'canine';
    // There are no primary premolars: D and E (positions 4-5) are molars.
    if (isPrimaryTooth(fdi)) return 'molar';
    if (p <= 5) return 'premolar';
    return 'molar';
}
function fdiQuadrant(fdi) {
    const q = Math.floor(fdi / 10);
    return {1: 'UR', 2: 'UL', 3: 'LL', 4: 'LR',
            5: 'UR', 6: 'UL', 7: 'LL', 8: 'LR'}[q] || 'UR';
}
// Which visual side is "mesial"? Teeth are laid out palmer 1→8 (or A→E) from the
// midline out on each half, so the right-hand quadrants have mesial on the left.
function mesialIsLeft(fdi) {
    const q = Math.floor(fdi / 10);
    return q === 1 || q === 4 || q === 5 || q === 8;
}

// 4-lobed occlusal patch (like the mockup's composite star), centered/scaled
function starPath(cx, cy, s) {
    const p = [
        [0,-7],[1.6,-4.6],[4.9,-5.4],[4.2,-2.1],[7,0],[4.2,2.1],[4.9,5.4],[1.6,4.6],
        [0,7],[-1.6,4.6],[-4.9,5.4],[-4.2,2.1],[-7,0],[-4.2,-2.1],[-4.9,-5.4],[-1.6,-4.6]
    ].map(([x, y]) => (cx + x * s).toFixed(1) + ',' + (cy + y * s).toFixed(1));
    return 'M' + p.join(' L') + ' Z';
}

// Compute the render state of one tooth from its treatments
function toothState(fdi) {
    const st = {surfaces: {}, rootZone: null, canal: false, crown: false, implant: false,
                extract: false, sparkle: null, bracket: false, dot: false, count: 0, treatments: []};
    const d = toothTreatmentMap[fdi];
    if (!d || !d.treatments) return st;
    st.count = d.treatments.length;
    st.treatments = d.treatments;
    d.treatments.forEach(t => {
        const cat = t.category || 'other';
        const surfs = String(t.tooth_surface || '').split(',').filter(Boolean)
            .map(s => s === 'incisal' ? 'occlusal' : s);
        const paint = (color, fallback) => {
            (surfs.length ? surfs : fallback).forEach(s => {
                if (s === 'root') st.rootZone = color; else st.surfaces[s] = color;
            });
        };
        if (cat === 'filling') {
            paint(/کۆمپۆزیت|كۆمپۆزیت|composite/i.test(t.treatment_name) ? CHART_COLORS.composite : CHART_COLORS.amalgam, ['occlusal']);
        } else if (cat === 'cosmetic') {
            paint(CHART_COLORS.cosmetic, ['buccal']);
        } else if (cat === 'root_canal') { st.canal = true; }
        else if (cat === 'crown' || cat === 'bridge') { st.crown = true; }
        else if (cat === 'implant') { st.implant = true; }
        else if (cat === 'extraction' || cat === 'surgery') { st.extract = true; }
        else if (cat === 'cleaning') { st.sparkle = CHART_COLORS.cleaning; }
        else if (cat === 'whitening') { st.sparkle = CHART_COLORS.whitening; }
        else if (cat === 'orthodontic') { st.bracket = true; }
        else { // examination / other
            if (surfs.length) paint(CHART_COLORS.other, []);
            else st.dot = true;
        }
    });
    return st;
}

/**
 * Render one tooth as an SVG string.
 * opts: { idPrefix, state, interactive (zones clickable), selectedSurfaces (Set),
 *         showAllZones (ghost zones visible, for the picker) }
 */
function toothSVG(fdi, opts) {
    opts = opts || {};
    const st = opts.state || toothState(fdi);
    const shapeKey = toothShapeKey(fdi);
    const shape = TOOTH_SHAPES[shapeKey];
    const uid = (opts.idPrefix || 't') + '-' + fdi;
    const zoneOf = side => (mesialIsLeft(fdi) === (side === 'left')) ? 'mesial' : 'distal';
    const selected = opts.selectedSurfaces || null;

    let defs = `
        <linearGradient id="tooth-base-${uid}" x1="18%" y1="0%" x2="82%" y2="100%">
            <stop offset="0%" stop-color="#ffffff"/>
            <stop offset="58%" stop-color="#fbfcfe"/>
            <stop offset="100%" stop-color="#edf2f7"/>
        </linearGradient>
        <linearGradient id="tooth-root-${uid}" x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stop-color="#f8fafc"/>
            <stop offset="100%" stop-color="#e8eef5"/>
        </linearGradient>
        <clipPath id="clip-${uid}"><path d="${shape.body}"/></clipPath>`;
    let parts = [];

    // ── tooth body ──
    const ghost = st.extract ? ' opacity="0.18"' : '';
    if (!st.implant) {
        parts.push(`<g${ghost}>`);
        parts.push(`<path d="${shape.body}" fill="url(#tooth-base-${uid})" stroke="${CHART_COLORS.outline}" stroke-width="1.95" stroke-linejoin="round"/>`);
        const anatomy = TOOTH_ANATOMY[shapeKey] || [];
        if (anatomy.length) {
            parts.push(`<g fill="none" stroke="#d5dee8" stroke-width="1.05" stroke-linecap="round" stroke-linejoin="round" opacity="0.72">`);
            anatomy.forEach(path => parts.push(`<path d="${path}"/>`));
            parts.push('</g>');
        }

        // clipped layers (zones, root highlight)
        parts.push(`<g clip-path="url(#clip-${uid})">`);
        // root zone highlight
        const rootFill = selected ? (selected.has('root') ? CHART_COLORS.other : null) : st.rootZone;
        if (opts.showAllZones) {
            parts.push(`<rect x="0" y="29" width="44" height="31" fill="${rootFill || '#eceff3'}" fill-opacity="${rootFill ? 0.75 : 0.55}" data-surface="root" style="cursor:pointer"/>`);
        } else if (rootFill) {
            parts.push(`<rect x="0" y="29" width="44" height="31" fill="${rootFill}" fill-opacity="0.55"/>`);
        }
        // surface zones
        const zoneEls = [];
        const zdef = shape.zones;
        const zoneGeo = {
            occlusal: null, // star, handled below
            mesial: zdef[mesialIsLeft(fdi) ? 'left' : 'right'],
            distal: zdef[mesialIsLeft(fdi) ? 'right' : 'left'],
            buccal: zdef.buccal,
            lingual: zdef.lingual
        };
        ['mesial', 'distal', 'buccal', 'lingual'].forEach(surf => {
            const g = zoneGeo[surf];
            const fill = selected ? (selected.has(surf) ? CHART_COLORS.other : null) : st.surfaces[surf];
            if (opts.showAllZones) {
                zoneEls.push(`<ellipse cx="${g.cx}" cy="${g.cy}" rx="${(g.rx * 1.06).toFixed(2)}" ry="${(g.ry * 1.06).toFixed(2)}" fill="${fill || '#dfe5ea'}" fill-opacity="${fill ? 0.9 : 0.6}" stroke="${fill ? '#00000033' : '#c3ccd4'}" stroke-width="0.7" data-surface="${surf}" style="cursor:pointer"/>`);
            } else if (fill) {
                zoneEls.push(`<ellipse cx="${g.cx}" cy="${g.cy}" rx="${(g.rx * 1.08).toFixed(2)}" ry="${(g.ry * 1.08).toFixed(2)}" fill="${fill}" fill-opacity="0.94" stroke="#00000022" stroke-width="0.7"/>`);
            }
        });
        // occlusal star patch
        const [scx, scy, ss] = zdef.occlusal.star;
        const oFill = selected ? (selected.has('occlusal') ? CHART_COLORS.other : null) : st.surfaces.occlusal;
        if (opts.showAllZones) {
            zoneEls.push(`<path d="${starPath(scx, scy, ss * 1.12)}" fill="${oFill || '#dfe5ea'}" fill-opacity="${oFill ? 0.95 : 0.65}" stroke="${oFill ? '#00000033' : '#c3ccd4'}" stroke-width="0.7" data-surface="occlusal" style="cursor:pointer"/>`);
        } else if (oFill) {
            zoneEls.push(`<path d="${starPath(scx, scy, ss * 1.16)}" fill="${oFill}" fill-opacity="0.96" stroke="#00000022" stroke-width="0.7"/>`);
        }
        parts.push(zoneEls.join(''));
        parts.push('</g>'); // end clip

        // root canal indicator
        if (st.canal) {
            const c = shape.chamber;
            parts.push(`<ellipse cx="${c.cx}" cy="${c.cy}" rx="${c.rx}" ry="${c.ry}" fill="${CHART_COLORS.canal}" fill-opacity="0.9"/>`);
            shape.canals.forEach(p => {
                parts.push(`<path d="${p}" fill="none" stroke="${CHART_COLORS.canal}" stroke-width="2.6" stroke-linecap="round"/>`);
            });
        }
        parts.push('</g>'); // end ghost group
    } else {
        // ── implant: screw instead of tooth ──
        parts.push(`<g fill="${CHART_COLORS.implant}" stroke="${CHART_COLORS.implant}">`);
        parts.push(`<rect x="17.5" y="6" width="9" height="6" rx="1.2"/>`);
        parts.push(`<path d="M14.5,13.5 L29.5,13.5 L27.8,23 L16.2,23 Z"/>`);
        parts.push(`<path d="M16.5,25 L27.5,25 L25.8,49 Q22,54 18.2,49 Z"/>`);
        [29, 34, 39, 44].forEach((y, i) => {
            const inset = 16.8 + i * 0.55;
            parts.push(`<line x1="${inset}" y1="${y}" x2="${44 - inset}" y2="${y}" stroke="#fff" stroke-width="1.6"/>`);
        });
        parts.push('</g>');
    }

    // crown / bridge overlay (also valid on implants)
    if (st.crown) {
        parts.push(`<path d="${shape.crown}" fill="${CHART_COLORS.crown}" fill-opacity="0.18" stroke="${CHART_COLORS.crown}" stroke-width="2.65" stroke-linejoin="round"/>`);
    }
    // orthodontic bracket
    if (st.bracket) {
        parts.push(`<line x1="5" y1="14.5" x2="39" y2="14.5" stroke="${CHART_COLORS.ortho}" stroke-width="1.4"/>`);
        parts.push(`<rect x="18" y="10.5" width="8" height="8" rx="1.5" fill="#fff" stroke="${CHART_COLORS.ortho}" stroke-width="1.8"/>`);
    }
    // cleaning / whitening sparkle
    if (st.sparkle) {
        parts.push(`<path d="M34,1 L35.6,5 L39.5,6.5 L35.6,8 L34,12 L32.4,8 L28.5,6.5 L32.4,5 Z" fill="${st.sparkle}"/>`);
    }
    // examination / generic marker
    if (st.dot) {
        parts.push(`<circle cx="34" cy="23" r="3.2" fill="${CHART_COLORS.other}" stroke="#fff" stroke-width="1.2"/>`);
    }
    // extraction X on top of everything
    if (st.extract) {
        parts.push(`<g stroke="${CHART_COLORS.extract}" stroke-width="3.6" stroke-linecap="round">`);
        parts.push(`<line x1="12" y1="14" x2="32" y2="44"/><line x1="32" y1="14" x2="12" y2="44"/>`);
        parts.push('</g>');
    }

    return `<svg viewBox="0 0 44 60" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="${toothNames[fdi] || ('Tooth ' + fdi)}" shape-rendering="geometricPrecision"><defs>${defs}</defs>${parts.join('')}</svg>`;
}

function renderServiceWork() {
    const root = document.getElementById('chartServiceWork');
    if (!root) return;
    const grouped = {};
    (serviceTreatmentRows || []).forEach(function (row) {
        const arch = ['upper', 'lower'].includes(row.arch_type) ? row.arch_type : 'none';
        const key = arch + '|' + row.treatment_name + '|' + row.unit_price;
        if (!grouped[key]) grouped[key] = { arch: arch, name: row.treatment_name, qty: 0, total: 0 };
        grouped[key].qty += Number(row.quantity) || 0;
        grouped[key].total += Number(row.final_price) || 0;
    });
    const rows = Object.values(grouped);
    root.classList.toggle('has-items', rows.length > 0);
    if (!rows.length) { root.innerHTML = ''; return; }
    const lanes = [
        ['upper', 'ماکزیلا / ئەرکی سەرەوە', 'fa-arrow-up', 'is-upper'],
        ['none', 'کارەکانی بێ ژمارەی ددان', 'fa-briefcase-medical', 'is-service'],
        ['lower', 'ماندیبل / ئەرکی خوارەوە', 'fa-arrow-down', 'is-lower']
    ];
    root.innerHTML = lanes.map(function (lane) {
        const items = rows.filter(x => x.arch === lane[0]);
        if (!items.length) return '';
        return `<section class="chart-service-lane ${lane[3]}">
            <div class="chart-service-title"><i class="fas ${lane[2]}"></i>${lane[1]}</div>
            <div class="chart-service-list">${items.map(item => `<span class="chart-service-card">
                <strong>${escapeHtml(item.name)}</strong><span class="qty">× ${numberFormat(item.qty)}</span><span class="price">${numberFormat(item.total)}</span>
            </span>`).join('')}</div>
        </section>`;
    }).join('');
}

// Render / re-render the whole chart from toothTreatmentMap
function renderSmartChart() {
    document.querySelectorAll('.tooth[data-quadrant]').forEach(el => {
        const fdi = parseInt(el.dataset.tooth, 10);
        const st = toothState(fdi);
        const shapeClass = 'tooth--' + toothShapeKey(fdi);
        el.classList.remove('tooth--incisor', 'tooth--canine', 'tooth--premolar', 'tooth--molar');
        el.classList.add(shapeClass);
        const holder = el.querySelector('.tooth-svg');
        if (holder) holder.innerHTML = toothSVG(fdi, {state: st, idPrefix: 'chart'});
        el.classList.toggle('has-treatments', st.count > 0);

        // treatment count badge
        let badge = el.querySelector('.treatment-count');
        if (st.count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'treatment-count';
                el.appendChild(badge);
            }
            badge.textContent = st.count;
        } else if (badge) {
            badge.remove();
        }

        // tooltip: tooth name + first treatment
        const tip = el.querySelector('.tooth-tooltip');
        if (tip) {
            let txt = toothNames[fdi] || ('ددان ' + fdi);
            if (st.count > 0) {
                txt += ' — ' + st.treatments[0].treatment_name + (st.count > 1 ? ' +' + (st.count - 1) : '');
            }
            tip.textContent = txt;
        }
    });
    renderChartLegend();
    updateArchSummaryCounts();
    renderDentitionCounts();
    renderArchCharts();
    renderServiceWork();
}

function legendSample(kind, color) {
    const wrap = c => `<svg viewBox="0 0 44 60" style="width:20px;height:25px;display:block;">${c}</svg>`;
    const molar = TOOTH_SHAPES.molar;
    switch (kind) {
        case 'chip':
            return `<span class="legend-color" style="background:${color};border-color:${color};"></span>`;
        case 'canal':
            return wrap(`<path d="${molar.body}" fill="#fff" stroke="${CHART_COLORS.outline}" stroke-width="2"/>` +
                `<ellipse cx="22" cy="25" rx="4.5" ry="3" fill="${CHART_COLORS.canal}"/>` +
                molar.canals.map(p => `<path d="${p}" fill="none" stroke="${CHART_COLORS.canal}" stroke-width="3.4" stroke-linecap="round"/>`).join(''));
        case 'crown':
            return wrap(`<path d="${molar.body}" fill="#fff" stroke="${CHART_COLORS.outline}" stroke-width="2"/>` +
                `<path d="${molar.crown}" fill="${CHART_COLORS.crown}" fill-opacity="0.15" stroke="${CHART_COLORS.crown}" stroke-width="3.4"/>`);
        case 'implant':
            return wrap(`<g fill="${CHART_COLORS.implant}"><rect x="17.5" y="6" width="9" height="6" rx="1.2"/><path d="M14.5,13.5 L29.5,13.5 L27.8,23 L16.2,23 Z"/><path d="M16.5,25 L27.5,25 L25.8,49 Q22,54 18.2,49 Z"/></g>`);
        case 'extract':
            return wrap(`<g stroke="${CHART_COLORS.extract}" stroke-width="5" stroke-linecap="round"><line x1="12" y1="16" x2="32" y2="44"/><line x1="32" y1="16" x2="12" y2="44"/></g>`);
        case 'sparkle':
            return wrap(`<path d="M22,10 L26,24 L40,30 L26,36 L22,50 L18,36 L4,30 L18,24 Z" fill="${color}"/>`);
        default:
            return `<span class="legend-color" style="background:${color};"></span>`;
    }
}

function renderChartLegend() {
    const el = document.getElementById('smartChartLegend');
    if (!el) return;
    const items = [
        ['chip', CHART_COLORS.amalgam, 'پڕکردنەوە (ئاسایی)'],
        ['chip', CHART_COLORS.composite, 'پڕکردنەوە (کۆمپۆزیت)'],
        ['canal', null, 'چارەسەری دەمار'],
        ['crown', null, 'تاج / پرد'],
        ['implant', null, 'ئیمپلانت'],
        ['extract', null, 'ڕاکێشان'],
        ['sparkle', CHART_COLORS.cleaning, 'پاککردنەوە'],
        ['chip', CHART_COLORS.cosmetic, 'جوانکاری']
    ];
    el.innerHTML = items.map(([kind, color, label]) =>
        `<div class="smart-chart-legend-item"><span class="smart-chart-legend-icon">${legendSample(kind, color)}</span><span>${label}</span></div>`
    ).join('');
}

// Keep the arch summary counters in sync (total treatments shown under chart)
function updateArchSummaryCounts() {
    const el = document.getElementById('chartTreatmentTotal');
    if (!el) return;
    let n = 0;
    Object.values(toothTreatmentMap).forEach(d => { n += (d.treatments || []).length; });
    n += (serviceTreatmentRows || []).length;
    el.textContent = n;
}

function toast(type, message) {
    if (typeof showToast === 'function') showToast(type, message);
    else alert(message);
}

// ── Receipt print dialog ───────────────────────────────────────────
// The A5 sheet lives in cases/receipt.php and is shown inline in an iframe, so
// the doctor never leaves the case page. embed=1 strips the sheet's own toolbar.
// A case has one receipt per visit day (وەسڵی سەردان) — the dialog's pills pick
// which one: an invoice id, 'unbilled' (work not yet sent), or 'all'.
const RECEIPT_SRC = '<?= APP_URL ?>/cases/receipt.php?id=<?= $caseId ?>&embed=1';
// Default = the tab the page opened on (newest draft, else newest invoice).
const RECEIPT_DEFAULT = <?= $activeInvoiceId ? (int)$activeInvoiceId : json_encode('all') ?>;
let receiptCurrent = null;

function receiptSrcFor(invoice) {
    let src = RECEIPT_SRC;
    const s = String(invoice == null ? '' : invoice);
    if (s !== 'all' && s !== '') {
        src += '&invoice=' + s;
    }
    return src + '&t=' + Date.now();
}

function loadReceipt(invoice) {
    const frame = document.getElementById('receiptFrame');
    const loading = document.getElementById('receiptLoading');
    if (!frame) return;
    receiptCurrent = invoice;
    document.querySelectorAll('#receiptTabs .rd-tab').forEach(btn => {
        btn.classList.toggle('is-active', String(btn.dataset.invoice) === String(invoice));
    });
    if (loading) loading.hidden = false;
    frame.addEventListener('load', function onLoad() {
        frame.removeEventListener('load', onLoad);
        if (loading) loading.hidden = true;
    });
    frame.src = receiptSrcFor(invoice);
    // Keep the open-in-new-tab fallback pointed at the same receipt
    const nt = document.getElementById('receiptNewTabLink');
    if (nt) nt.href = receiptSrcFor(invoice).replace('&embed=1', '').replace(/&t=\d+/, '') + '&autoprint=0';
}

function openReceiptDialog(invoice) {
    openDialog('receiptDialog');
    // Always (re)load on open, with a cache-buster, so the sheet shows the
    // totals as they are right now.
    loadReceipt(invoice != null ? invoice : RECEIPT_DEFAULT);
}

function switchReceipt(invoice) {
    if (String(invoice) === String(receiptCurrent)) return;
    loadReceipt(invoice);
}

function closeReceiptDialog() {
    closeDialog('receiptDialog');
    receiptCurrent = null;
}

function printReceipt() {
    const frame = document.getElementById('receiptFrame');
    const win = frame && frame.contentWindow;
    if (!win) { toast('error', 'وەسڵەکە هێشتا ئامادە نییە'); return; }
    try {
        // focus() first: without it Safari prints the parent page instead.
        win.focus();
        win.print();
    } catch (e) {
        // Cross-origin or a browser that refuses iframe printing — fall back to
        // the standalone page (same invoice), which prints itself on load.
        window.open(receiptSrcFor(receiptCurrent).replace('&embed=1', ''), '_blank', 'noopener');
    }
}

// ── Adult / child chart panes ──────────────────────────────────────
// Both tooth sets are always active: a patient can be in mixed dentition, so
// adult and child teeth are selectable and treatable in the same case. These
// buttons are a VIEW filter only — folding a pane away never disables its teeth,
// never hides their treatments and never changes any total. At least one pane
// stays open so the chart is never blank.
const DENTITION_STORE_KEY = 'dentHisab.caseChartPanes';

function dentitionPaneVisibility() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(DENTITION_STORE_KEY) || 'null'); } catch (e) { saved = null; }
    const vis = {};
    DENTITION_MODES.forEach(m => { vis[m] = !(saved && saved[m] === false); });
    if (!DENTITION_MODES.some(m => vis[m])) DENTITION_MODES.forEach(m => { vis[m] = true; });
    return vis;
}

// How many treatments sit on each tooth set — shown as a badge on the pane
// heading so work on a folded-away pane is still visible at a glance.
function dentitionTreatmentCount(mode) {
    const set = DENTITION_TEETH[mode];
    if (!set) return 0;
    let n = 0;
    ['UR', 'UL', 'LL', 'LR'].forEach(q => {
        (set[q] || []).forEach(fdi => {
            const d = toothTreatmentMap[fdi];
            if (d && d.treatments) n += d.treatments.length;
        });
    });
    return n;
}

// ── Fit the odontogram to whatever column it lands in ──────────────
// The natural arch is ~964px wide; the column is anything from a phone to an
// ultrawide. Rather than let it scroll sideways, shrink every tooth dimension by
// one factor until the widest visible row fits exactly. Below MIN_SCALE the
// teeth would stop being tappable, so we stop there and let .jaw-section scroll.
const CHART_MIN_SCALE = 0.55;

// Width the row would need at --chart-scale:1. scrollWidth is not usable here:
// on the stacked (wrapped) layout the quadrant overflows to the LEFT in RTL and
// scrollWidth does not see it, so add the boxes up instead. Everything scales
// linearly, so measuring at the current scale and dividing gives the natural size.
function rowNaturalWidth(row, scale) {
    const quads = Array.prototype.slice.call(row.children).filter(el => el.classList.contains('quadrant'));
    if (!quads.length) return 0;
    const widths = quads.map(q => {
        const gap = parseFloat(getComputedStyle(q).columnGap) || 0;
        const teeth = Array.prototype.slice.call(q.children);
        const sum = teeth.reduce((s, t) => s + t.getBoundingClientRect().width, 0);
        return sum + gap * Math.max(0, teeth.length - 1);
    });
    if (getComputedStyle(row).flexWrap === 'wrap') {
        return Math.max.apply(null, widths) / scale;   // stacked: widest quadrant
    }
    let extra = 0;
    const div = row.querySelector('.quadrant-divider');
    if (div) {
        const ds = getComputedStyle(div);
        extra = div.getBoundingClientRect().width
              + (parseFloat(ds.marginLeft) || 0) + (parseFloat(ds.marginRight) || 0);
    }
    return (widths.reduce((a, b) => a + b, 0) + extra) / scale;
}

function fitChartScale() {
    document.querySelectorAll('.smart-dental-chart').forEach(chart => {
        const current = parseFloat(getComputedStyle(chart).getPropertyValue('--chart-scale')) || 1;
        let scale = 1;
        chart.querySelectorAll('.dentition-pane:not([hidden]) .jaw-section').forEach(sec => {
            const row = sec.querySelector('.teeth-row');
            // Headroom for sub-pixel rounding plus the drop-shadow ink of the
            // outermost teeth, which counts toward scrollable overflow.
            const avail = sec.clientWidth - 12;
            if (!row || avail <= 0) return;
            const natural = rowNaturalWidth(row, current);
            if (natural > 0 && natural > avail) scale = Math.min(scale, avail / natural);
        });
        scale = Math.max(CHART_MIN_SCALE, Math.min(1, scale));
        chart.style.setProperty('--chart-scale', scale.toFixed(4));
    });
}

// When a jaw row is still wider than its column (below CHART_MIN_SCALE) it
// scrolls sideways. Left alone it parks at one end, so the arch looks cut in
// half; park it on the midline instead. RTL scrollers use a negative scrollLeft
// range in some engines and a positive one in others, so probe which first.
let _rtlScrollNegative = null;
function centerJawScroll(root) {
    (root || document).querySelectorAll('.smart-dental-chart .jaw-section').forEach(sec => {
        const max = sec.scrollWidth - sec.clientWidth;
        if (max <= 1) return;
        if (_rtlScrollNegative === null) {
            const before = sec.scrollLeft;
            sec.scrollLeft = -1;
            _rtlScrollNegative = sec.scrollLeft < 0;
            sec.scrollLeft = before;
        }
        sec.scrollLeft = _rtlScrollNegative ? -max / 2 : max / 2;
    });
}

function applyDentitionPanes(vis) {
    const chart = document.getElementById('smartDentalChart');
    if (chart) {
        chart.querySelectorAll('.dentition-pane').forEach(pane => {
            pane.hidden = (vis[pane.dataset.dentition] === false);
        });
        // A pane that was hidden had no measurable width, so re-fit and center
        // now that it is laid out.
        requestAnimationFrame(() => { fitChartScale(); centerJawScroll(chart); });
    }
    document.querySelectorAll('#dentitionForm .dentition-opt').forEach(btn => {
        const on = vis[btn.dataset.dentition] !== false;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    renderDentitionCounts();
}

function renderDentitionCounts() {
    DENTITION_MODES.forEach(mode => {
        const n = dentitionTreatmentCount(mode);
        document.querySelectorAll('[data-dentition-count="' + mode + '"]').forEach(el => {
            el.textContent = n > 0 ? n + ' چارەسەر' : '';
        });
    });
}

function toggleDentitionPane(mode) {
    if (DENTITION_MODES.indexOf(mode) === -1) return;
    const vis = dentitionPaneVisibility();
    vis[mode] = !vis[mode];
    // Never fold both away — the chart would disappear with no way back.
    if (!DENTITION_MODES.some(m => vis[m])) {
        toast('error', 'لانیکەم یەک چارت دەبێت پیشان بدرێت');
        return;
    }
    try { localStorage.setItem(DENTITION_STORE_KEY, JSON.stringify(vis)); } catch (e) { /* private mode */ }
    applyDentitionPanes(vis);
    if (!vis[mode]) {
        // Selections on a folded-away pane would be invisible but still applied.
        clearBulkSelection();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const chart = document.getElementById('smartDentalChart');
    if (!chart) return;
    applyDentitionPanes(dentitionPaneVisibility());
    // Re-fit when the column width changes (rotate, sidebar collapse, resize,
    // switching to the chart tab after it was display:none and unmeasurable).
    const refit = function () { fitChartScale(); centerJawScroll(); };
    let t;
    const debounced = function () { clearTimeout(t); t = setTimeout(refit, 120); };
    window.addEventListener('resize', debounced);
    if (window.ResizeObserver) {
        new ResizeObserver(debounced).observe(chart.parentElement || chart);
    }
    refit();
});

// ═══════════════════════════════════════════════════════════════════
// Occlusal arch chart (top view: gums + teeth around the arc)
// ═══════════════════════════════════════════════════════════════════
const ARCH_COLORS = {
    filling: '#9775fa', canal: '#f59f00', crown: '#339af0',
    implant: '#2f9e44', extract: '#e03131', sparkle: '#22b8cf',
    other: '#4dabf7', gum: '#f8b1b1', gumEdge: '#ee9a9a',
    body: '#ffffff', outline: '#b9c2cf', num: '#0ca678', numBorder: '#cde3d6'
};
const ARCH_ORDER = {
    upper: [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28],
    lower: [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38]
};

// Occlusal (biting-surface) tooth shapes, drawn pointing up; body + fissure lines
function occlusalShape(fdi) {
    const p = fdi % 10;
    if (p === 1) return {
        body: 'M-15,-8 Q-15,-9 -13,-9 L13,-9 Q15,-9 15,-8 Q15,8 0,9 Q-15,8 -15,-8 Z',
        detail: 'M-11,-4 Q0,-8 11,-4', w: 30, h: 18
    };
    if (p === 2) return {
        body: 'M-13,-7 Q-13,-8 -11,-8 L11,-8 Q13,-8 13,-7 Q13,7 0,8 Q-13,7 -13,-7 Z',
        detail: 'M-9,-3 Q0,-7 9,-3', w: 26, h: 16
    };
    if (p === 3) return {
        body: 'M0,-13 Q12,-9 12,0 Q12,10 0,13 Q-12,10 -12,0 Q-12,-9 0,-13 Z',
        detail: 'M0,-8 Q2,0 0,8', w: 24, h: 26
    };
    if (p === 4 || p === 5) return {
        body: 'M0,-16 Q14,-14 14,0 Q14,14 0,16 Q-14,14 -14,0 Q-14,-14 0,-16 Z',
        detail: 'M0,-9 Q-3,0 0,9 M-6,-2 Q0,1 6,-2', w: 28, h: 32
    };
    const s = p === 6 ? 1 : (p === 7 ? 0.92 : 0.82); // molars shrink toward the back
    const w = 19 * s, h = 21 * s, r = 9 * s;
    return {
        body: `M${-w + r},${-h} L${w - r},${-h} Q${w},${-h} ${w},${-h + r} L${w},${h - r} Q${w},${h} ${w - r},${h} L${-w + r},${h} Q${-w},${h} ${-w},${h - r} L${-w},${-h + r} Q${-w},${-h} ${-w + r},${-h} Z`,
        detail: `M${-9 * s},${-11 * s} Q${-4 * s},${-3 * s} ${-9 * s},${7 * s} M${9 * s},${-11 * s} Q${4 * s},${-2 * s} ${8 * s},${8 * s} M${-8 * s},${-2 * s} Q0,${2 * s} ${8 * s},${-2 * s}`,
        w: w * 2, h: h * 2
    };
}

// Which visual states does this tooth have (arch palette)?
function archToothState(fdi) {
    const st = {filling: false, canal: false, crown: false, implant: false,
                extract: false, sparkle: false, dot: false, count: 0};
    const d = toothTreatmentMap[fdi];
    if (!d || !d.treatments) return st;
    st.count = d.treatments.length;
    d.treatments.forEach(t => {
        const cat = t.category || 'other';
        if (cat === 'filling' || cat === 'cosmetic') st.filling = true;
        else if (cat === 'root_canal') st.canal = true;
        else if (cat === 'crown' || cat === 'bridge') st.crown = true;
        else if (cat === 'implant') st.implant = true;
        else if (cat === 'extraction' || cat === 'surgery') st.extract = true;
        else if (cat === 'cleaning' || cat === 'whitening') st.sparkle = true;
        else st.dot = true;
    });
    return st;
}

// Small upright glyphs drawn over teeth (and reused in stats/legend)
function archGlyph(kind, scale) {
    const s = scale || 1;
    const g = c => `<g transform="scale(${s})">${c}</g>`;
    switch (kind) {
        case 'filling':
            return g(`<path d="${starPath(0, 0, 0.95)}" fill="${ARCH_COLORS.filling}" fill-opacity="0.95" stroke="#00000022" stroke-width="0.6"/>`);
        case 'canal': {
            const m = TOOTH_SHAPES.molar;
            return g(`<g transform="scale(0.42) translate(-22,-30)">
                <path d="${m.body}" fill="#fff" stroke="${ARCH_COLORS.canal}" stroke-width="3"/>
                <ellipse cx="22" cy="25" rx="4.5" ry="3" fill="${ARCH_COLORS.canal}"/>
                ${m.canals.map(p => `<path d="${p}" fill="none" stroke="${ARCH_COLORS.canal}" stroke-width="3.6" stroke-linecap="round"/>`).join('')}
            </g>`);
        }
        case 'crown':
            return g(`<path d="M-9,6 L-9,-3 L-4.5,1 L0,-7 L4.5,1 L9,-3 L9,6 Q0,9 -9,6 Z" fill="${ARCH_COLORS.crown}" stroke="#1c7ed6" stroke-width="1"/>`);
        case 'implant':
            return g(`<g><rect x="-7" y="-13" width="14" height="5" rx="1.5" fill="${ARCH_COLORS.implant}"/>
                <path d="M-5,-7 L5,-7 L4,9 Q0,13 -4,9 Z" fill="${ARCH_COLORS.implant}"/>
                <line x1="-4.5" y1="-3" x2="4.5" y2="-3" stroke="#fff" stroke-width="1.4"/>
                <line x1="-4" y1="1" x2="4" y2="1" stroke="#fff" stroke-width="1.4"/>
                <line x1="-3.2" y1="5" x2="3.2" y2="5" stroke="#fff" stroke-width="1.4"/></g>`);
        case 'extract':
            return g(`<g stroke="${ARCH_COLORS.extract}" stroke-width="3.8" stroke-linecap="round">
                <line x1="-8" y1="-8" x2="8" y2="8"/><line x1="8" y1="-8" x2="-8" y2="8"/></g>`);
        case 'sparkle':
            return g(`<path d="M0,-9 L2.4,-2.4 L9,0 L2.4,2.4 L0,9 L-2.4,2.4 L-9,0 L-2.4,-2.4 Z" fill="${ARCH_COLORS.sparkle}"/>`);
        case 'tooth':
            return g(`<path d="M-8,-7 C-8,-11 8,-11 8,-7 C8,-2 6,1 4,3 C4,7 2,10 0,10 C-2,10 -4,7 -4,3 C-6,1 -8,-2 -8,-7 Z" fill="#495057" transform="translate(0,-1)"/>`);
        default:
            return g(`<circle r="4.5" fill="${ARCH_COLORS.other}"/>`);
    }
}

function renderArchChart(holder) {
    const arch = holder.dataset.arch;
    const upper = arch === 'upper';
    const teethOrder = ARCH_ORDER[arch];
    const cx = 260, Rx = 185, Ry = 284;
    const cy = upper ? 352 : 48;
    const yOf = (r, t) => upper ? cy - r * Math.sin(t) : cy + r * Math.sin(t);

    let parts = [];
    // ── gums ──
    if (upper) {
        const gx = Rx + 22, gy = Ry + 26, by = cy - 4;
        parts.push(`<path d="M${cx - gx},${by} A${gx},${gy} 0 0 1 ${cx + gx},${by}
            Q${cx + gx - 55},${by - 28} ${cx + 85},${by - 16} Q${cx},${by - 42} ${cx - 85},${by - 16} Q${cx - gx + 55},${by - 28} ${cx - gx},${by} Z"
            fill="${ARCH_COLORS.gum}" stroke="${ARCH_COLORS.gumEdge}" stroke-width="2"/>`);
        // palate ridges
        parts.push(`<path d="M${cx - 60},130 Q${cx},100 ${cx + 60},130 M${cx - 78},170 Q${cx},136 ${cx + 78},170"
            fill="none" stroke="#e88f8f" stroke-width="2" stroke-linecap="round" opacity="0.7"/>`);
        parts.push(`<line x1="${cx}" y1="72" x2="${cx}" y2="96" stroke="#e88f8f" stroke-width="2" opacity="0.7"/>`);
    } else {
        const gx = Rx + 22, gy = Ry + 26, by = cy + 4;
        const ix = Rx - 48, iy = Ry - 64;
        parts.push(`<path d="M${cx - gx},${by} A${gx},${gy} 0 0 0 ${cx + gx},${by}
            L${cx + ix},${by} A${ix},${iy} 0 0 1 ${cx - ix},${by} Z"
            fill="${ARCH_COLORS.gum}" stroke="${ARCH_COLORS.gumEdge}" stroke-width="2"/>`);
    }

    // ── teeth + number bubbles ──
    teethOrder.forEach((fdi, i) => {
        const t = (174 - i * (168 / 15)) * Math.PI / 180;
        const x = cx + Rx * Math.cos(t);
        const y = yOf(Ry, t);
        const deg = t * 180 / Math.PI;
        const rot = upper ? (90 - deg) : (270 - deg);
        const shape = occlusalShape(fdi);
        const st = archToothState(fdi);

        const bodyStroke = st.crown ? ARCH_COLORS.crown : ARCH_COLORS.outline;
        const bodyFill = st.crown ? '#e7f3fd' : ARCH_COLORS.body;
        const bodyW = st.crown ? 2.4 : 1.6;
        const ghost = st.extract ? ' opacity="0.35"' : '';

        let overlays = [];
        if (st.filling) overlays.push(archGlyph('filling'));
        if (st.canal) overlays.push(`<g transform="translate(${st.filling ? 9 : 0},${st.filling ? 7 : 0})">${archGlyph('canal')}</g>`);
        if (st.implant) overlays.push(archGlyph('implant'));
        if (st.sparkle) overlays.push(`<g transform="translate(9,-9)">${archGlyph('sparkle', 0.7)}</g>`);
        if (st.dot && !st.filling && !st.canal && !st.implant) overlays.push(archGlyph('dot'));
        if (st.extract) overlays.push(archGlyph('extract'));

        parts.push(`<g class="arch-tooth${st.count ? ' has-treatments' : ''}" data-tooth="${fdi}" onclick="showToothDetail(${fdi})">
            <g transform="translate(${x.toFixed(1)},${y.toFixed(1)}) rotate(${rot.toFixed(1)})"${ghost}>
                <path class="at-body" d="${shape.body}" fill="${bodyFill}" stroke="${bodyStroke}" stroke-width="${bodyW}"/>
                <path d="${shape.detail}" fill="none" stroke="#b9cdd9" stroke-width="1.3" stroke-linecap="round"/>
            </g>
            <g transform="translate(${x.toFixed(1)},${y.toFixed(1)})">${overlays.join('')}</g>
        </g>`);

        // FDI number bubble outside the arch
        const bx = cx + (Rx + 48) * Math.cos(t);
        const by2 = yOf(Ry + 44, t);
        parts.push(`<g class="arch-num" onclick="showToothDetail(${fdi})" style="cursor:pointer">
            <rect x="${(bx - 15).toFixed(1)}" y="${(by2 - 11).toFixed(1)}" width="30" height="22" rx="11"
                  fill="#fff" stroke="${ARCH_COLORS.numBorder}" stroke-width="1.5"/>
            <text x="${bx.toFixed(1)}" y="${(by2 + 4.5).toFixed(1)}" text-anchor="middle"
                  font-size="13" font-weight="700" fill="${ARCH_COLORS.num}" font-family="inherit">${fdi}</text>
        </g>`);
    });

    holder.innerHTML = `<svg viewBox="0 0 520 400" xmlns="http://www.w3.org/2000/svg">${parts.join('')}</svg>`;
}

function renderArchStats(arch) {
    const statsEl = document.querySelector(`[data-arch-stats="${arch}"]`);
    const statusEl = document.querySelector(`[data-arch-status="${arch}"]`);
    if (!statsEl) return;
    const counts = {filling: 0, extract: 0, crown: 0, canal: 0, implant: 0};
    let treatments = 0, price = 0;
    ARCH_ORDER[arch].forEach(fdi => {
        const st = archToothState(fdi);
        if (st.filling) counts.filling++;
        if (st.extract) counts.extract++;
        if (st.crown) counts.crown++;
        if (st.canal) counts.canal++;
        if (st.implant) counts.implant++;
        const d = toothTreatmentMap[fdi];
        if (d) { treatments += d.treatments.length; price += parseFloat(d.total_price) || 0; }
    });
    (serviceTreatmentRows || []).forEach(function (row) {
        if (row.arch_type === arch) {
            treatments += 1;
            price += Number(row.final_price) || 0;
        }
    });
    const icon = (kind, s) => `<svg viewBox="-12 -14 24 26" style="width:${s || 20}px;height:${(s || 20) * 1.1}px;">${archGlyph(kind)}</svg>`;
    const card = (value, label, ic) =>
        `<div class="arch-occ-stat"><span class="arch-occ-stat-icon">${ic}</span><b>${value}</b><small>${label}</small></div>`;
    statsEl.innerHTML =
        card(16, 'کۆی ددان', icon('tooth')) +
        card(counts.filling, 'پڕکردنەوە', icon('filling')) +
        card(counts.extract, 'ڕاکێشان', icon('extract')) +
        card(counts.crown, 'تاج', icon('crown')) +
        card(counts.canal, 'چارەسەری دەمار', icon('canal', 22)) +
        card(counts.implant, 'ئیمپلانت', icon('implant'));
    if (statusEl) {
        statusEl.innerHTML = treatments > 0
            ? `<i class="fas fa-check-circle"></i> کۆی چارەسەر: <b>${treatments}</b> — <b>${numberFormat(price)}</b>`
            : `<i class="fas fa-info-circle"></i> هیچ چارەسەرێک تۆمار نەکراوە`;
        statusEl.classList.toggle('is-empty', treatments === 0);
    }
}

function renderArchLegend() {
    const el = document.getElementById('archOccLegend');
    if (!el) return;
    const icon = kind => `<svg viewBox="-12 -14 24 26" style="width:22px;height:24px;">${archGlyph(kind)}</svg>`;
    el.innerHTML = [
        ['filling', 'پڕکردنەوە'],
        ['extract', 'ڕاکێشان'],
        ['crown', 'تاج'],
        ['canal', 'چارەسەری دەمار'],
        ['implant', 'ئیمپلانت']
    ].map(([k, label]) => `<div class="arch-occ-legend-item">${icon(k)}<span>${label}</span></div>`).join('');
}

function renderArchCharts() {
    const holders = document.querySelectorAll('.arch-occ-chart[data-arch]');
    if (!holders.length) return;
    holders.forEach(h => {
        renderArchChart(h);
        renderArchStats(h.dataset.arch);
    });
    renderArchLegend();
}

// ═══ Surface picker (inside the add-treatment dialog) ═══
let pickedSurfaces = new Set();

function currentDialogTooth() {
    const v = document.getElementById('selectedToothNumber')?.value;
    return v ? parseInt(v, 10) : 26;
}

function renderSurfacePicker() {
    const holder = document.getElementById('surfaceToothPreview');
    if (!holder) return;
    const fdi = currentDialogTooth();
    holder.innerHTML = toothSVG(fdi, {
        idPrefix: 'pick',
        showAllZones: true,
        selectedSurfaces: pickedSurfaces,
        state: {surfaces: {}, count: 0, treatments: []}
    });
    const chips = document.getElementById('surfaceChips');
    if (chips) {
        chips.innerHTML = Object.keys(SURFACE_LABELS).map(s =>
            `<button type="button" class="surface-chip ${pickedSurfaces.has(s) ? 'on' : ''}" data-surface="${s}">${SURFACE_LABELS[s]}</button>`
        ).join('');
    }
    const inp = document.getElementById('toothSurfaceInput');
    if (inp) inp.value = Array.from(pickedSurfaces).join(',');
}

// Toggle a surface by clicking either the tooth zones or the chips
document.addEventListener('click', function (e) {
    const z = e.target.closest('#surfaceToothPreview [data-surface], #surfaceChips [data-surface]');
    if (!z) return;
    const s = z.dataset.surface;
    if (pickedSurfaces.has(s)) pickedSurfaces.delete(s); else pickedSurfaces.add(s);
    renderSurfacePicker();
});

function selectedTreatmentCategory() {
    const sel = document.getElementById('treatmentSelect');
    if (!sel || !sel.value) return null;
    const opt = sel.options[sel.selectedIndex];
    return (opt && opt.dataset.category) || 'other';
}

// Every treatment accepts a tooth number or none, so the tooth field is never locked.
function selectedTreatmentRequiresTooth() {
    return true;
}

function updateTreatmentToothRule() {
    const requiresTooth = selectedTreatmentRequiresTooth();
    const field = document.getElementById('treatmentToothField');
    const select = document.getElementById('selectedToothNumber');
    const hint = document.getElementById('treatmentToothRuleHint');
    if (field) {
        field.classList.toggle('is-not-required', !requiresTooth);
        field.style.opacity = requiresTooth ? '' : '.78';
    }
    if (select) {
        select.disabled = !requiresTooth;
        if (!requiresTooth) select.value = '';
    }
    if (hint) hint.style.display = requiresTooth ? 'none' : 'block';
    updateSurfaceSection();
}

function updateSurfaceSection() {
    const sec = document.getElementById('surfaceSection');
    if (!sec) return;
    const cat = selectedTreatmentCategory();
    const hasTooth = !!document.getElementById('selectedToothNumber')?.value;
    const show = selectedTreatmentRequiresTooth() && hasTooth && cat !== null && SURFACE_CATEGORIES.includes(cat);
    sec.style.display = show ? '' : 'none';
    if (!show) {
        pickedSurfaces.clear();
        const inp = document.getElementById('toothSurfaceInput');
        if (inp) inp.value = '';
    } else {
        renderSurfacePicker();
    }
}

function onDialogToothChange() {
    updateSurfaceSection();
    renderSurfacePicker();
}

// ═══ Searchable, categorized treatment picker ═══
let tpActiveCat = 'all';

function categoryIconFor(t) {
    switch (t.category) {
        case 'filling':
            return legendSample('chip', /کۆمپۆزیت|كۆمپۆزیت|composite/i.test(t.name) ? CHART_COLORS.composite : CHART_COLORS.amalgam);
        case 'root_canal': return legendSample('canal');
        case 'crown':
        case 'bridge': return legendSample('crown');
        case 'implant': return legendSample('implant');
        case 'extraction':
        case 'surgery': return legendSample('extract');
        case 'cleaning': return legendSample('sparkle', CHART_COLORS.cleaning);
        case 'whitening': return legendSample('sparkle', CHART_COLORS.whitening);
        case 'cosmetic': return legendSample('chip', CHART_COLORS.cosmetic);
        default: return legendSample('chip', CHART_COLORS.other);
    }
}

function openTreatmentPicker() {
    const ov = document.getElementById('treatmentPicker');
    if (!ov) return;
    ov.classList.add('open');
    renderTreatmentPicker();
    setTimeout(() => document.getElementById('tpSearch')?.focus(), 60);
}
function closeTreatmentPicker() {
    document.getElementById('treatmentPicker')?.classList.remove('open');
}

function renderTreatmentPicker() {
    const listEl = document.getElementById('tpList');
    const catsEl = document.getElementById('tpCats');
    if (!listEl || !catsEl) return;
    const q = (document.getElementById('tpSearch')?.value || '').trim().toLowerCase();

    // category chips: only categories that actually exist in the catalog
    const present = [];
    treatmentCatalog.forEach(t => {
        const c = t.category || 'other';
        if (!present.includes(c)) present.push(c);
    });
    const cats = ['all'].concat(present);
    if (!cats.includes(tpActiveCat)) tpActiveCat = 'all';
    catsEl.innerHTML = cats.map(c =>
        `<button type="button" class="tp-cat ${tpActiveCat === c ? 'on' : ''}" data-cat="${c}">${c === 'all' ? 'هەموو' : (CATEGORY_LABELS[c] || c)}</button>`
    ).join('');

    const rows = treatmentCatalog.filter(t =>
        (tpActiveCat === 'all' || (t.category || 'other') === tpActiveCat) &&
        (!q || t.name.toLowerCase().includes(q))
    );
    listEl.innerHTML = rows.length ? rows.map(t =>
        `<button type="button" class="tp-item" data-id="${t.id}">
            <span class="tp-item-icon">${categoryIconFor(t)}</span>
            <span class="tp-item-name">${escapeHtml(t.name)}</span>
            <span class="tp-item-price">${numberFormat(t.price)}</span>
        </button>`
    ).join('') : '<div class="tp-empty">هیچ چارەسەرێک نەدۆزرایەوە</div>';
}

document.addEventListener('click', function (e) {
    const cat = e.target.closest('#tpCats .tp-cat');
    if (cat) { tpActiveCat = cat.dataset.cat; renderTreatmentPicker(); return; }
    const item = e.target.closest('#tpList .tp-item');
    if (item) { chooseTreatment(parseInt(item.dataset.id, 10)); return; }
    const ov = document.getElementById('treatmentPicker');
    if (ov && e.target === ov) closeTreatmentPicker();
});
document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'tpSearch') renderTreatmentPicker();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('treatmentPicker')?.classList.contains('open')) {
        e.stopPropagation();
        closeTreatmentPicker();
    }
}, true);

function chooseTreatment(id) {
    const sel = document.getElementById('treatmentSelect');
    if (!sel) return;
    sel.value = String(id);
    if (typeof updateTreatmentPrice === 'function') updateTreatmentPrice();
    updatePickerBtn();
    updateTreatmentToothRule();
    closeTreatmentPicker();
}

function updatePickerBtn() {
    const btn = document.getElementById('treatmentPickerBtn');
    const sel = document.getElementById('treatmentSelect');
    if (!btn || !sel) return;
    const t = treatmentCatalog.find(x => String(x.id) === sel.value);
    btn.innerHTML = t
        ? `<span class="tp-btn-icon">${categoryIconFor(t)}</span><span class="tp-btn-name">${escapeHtml(t.name)}</span><span class="tp-btn-price">${numberFormat(t.price)}</span>`
        : '<i class="fas fa-hand-pointer"></i> هەڵبژاردنی چارەسەر...';
}

// ═══ AJAX save / delete → update page in place ═══
let currentLiveRevision = <?= json_encode(case_view_live_revision($caseId, $caseTreatments, $accountingLedger->getSummary($caseId), $case)) ?>;
let liveSnapshotBusy = false;
let lastLiveInvoiceId = 0;

async function refreshLiveCase(force) {
    if (liveSnapshotBusy || window.invoiceCorrectionOpen || (!force && document.hidden)) return;
    const focusedQuickRow = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest('.rt-add-row, form')
        : null;
    const dirtyQuickRow = Array.from(document.querySelectorAll('.rt-add-row')).some(row =>
        Array.from(row.querySelectorAll('.rt-combo-id, .rt-tooth-in')).some(input => String(input.value || '').trim() !== '')
    );
    if (focusedQuickRow || dirtyQuickRow || document.querySelector('.dialog-overlay.open, form[data-busy="1"]')) return;
    liveSnapshotBusy = true;
    try {
        const u = new URL(window.location.href);
        u.searchParams.set('ajax', 'case_snapshot');
        const requestedInvoiceId = (typeof activeVisitTab !== 'undefined' && activeVisitTab) ? activeVisitTab : 0;
        u.searchParams.set('invoice_id', requestedInvoiceId);
        const response = await fetch(u.toString(), {
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            cache: 'no-store'
        });
        const data = await response.json();
        if (!window.invoiceCorrectionOpen && data.success && data.revision
            && (data.revision !== currentLiveRevision || requestedInvoiceId !== lastLiveInvoiceId)) {
            applyCaseSnapshot(data);
            lastLiveInvoiceId = requestedInvoiceId;
        }
    } catch (e) {
        // A temporary network interruption must not disturb the doctor's work;
        // the next interval retries automatically.
    } finally {
        liveSnapshotBusy = false;
    }
}

setInterval(function () { refreshLiveCase(false); }, 4000);
window.addEventListener('focus', function () { refreshLiveCase(true); });

function setFormLoading(form, on) {
    form.querySelectorAll('button[type="submit"]').forEach(btn => {
        btn.disabled = on;
        if (on && !btn.dataset.origHtml) {
            btn.dataset.origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...';
        } else if (!on && btn.dataset.origHtml) {
            btn.innerHTML = btn.dataset.origHtml;
            delete btn.dataset.origHtml;
        }
    });
}

function applyCaseSnapshot(d) {
    if (d.revision) currentLiveRevision = d.revision;
    if (d.tooth_map) toothTreatmentMap = d.tooth_map;
    if (Array.isArray(d.service_treatments)) serviceTreatmentRows = d.service_treatments;
    if (d.tooth_map || Array.isArray(d.service_treatments)) renderSmartChart();
    if (d.case_totals) updateCaseTotalsUI(d.case_totals);
    if (d.pane_html && d.invoice_id) {
        applyInvoiceSnapshot(d, parseInt(d.invoice_id, 10));
    }
    updateInvoiceCount(d.invoice_count);
    // refresh the tooth panel if it is open
    const panel = document.getElementById('toothDetailPanel');
    if (panel && panel.classList.contains('open') && typeof currentSelectedTooth !== 'undefined' && currentSelectedTooth) {
        showToothDetail(currentSelectedTooth);
    }
}

function updateCaseTotalsUI(t) {
    document.querySelectorAll('[data-case-bind]').forEach(el => {
        const k = el.dataset.caseBind;
        if (t[k] === undefined) return;
        const dec = parseInt(el.dataset.decimals || '0', 10);
        el.textContent = new Intl.NumberFormat('en-US', {minimumFractionDigits: dec, maximumFractionDigits: dec}).format(t[k]);
    });
}

function fmt2(n) {
    return new Intl.NumberFormat('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(n || 0);
}

// Count visit invoices, including empty drafts, rather than treatment rows.
function updateInvoiceCount(count) {
    if (!Number.isInteger(count) || count < 0) return;
    document.querySelectorAll('[data-invoice-count]').forEach(el => {
        el.textContent = count;
    });
}

// Reset the add-treatment dialog for the next entry
function resetTreatmentForm(keepTooth) {
    const form = document.getElementById('addTreatmentForm');
    if (!form) return;
    const sel = document.getElementById('treatmentSelect');
    if (sel) sel.value = '';
    const name = document.getElementById('treatmentName');
    if (name) name.value = '';
    const notes = form.querySelector('input[name="notes"]');
    if (notes) notes.value = '';
    const unitPrice = document.getElementById('unitPrice');
    if (unitPrice) unitPrice.value = '';
    const qty = document.getElementById('treatmentQuantity');
    if (qty) qty.value = '1';
    const disc = document.getElementById('treatmentDiscount');
    if (disc) disc.value = '0';
    const defPrice = document.getElementById('defaultPriceDisplay');
    if (defPrice) defPrice.textContent = '0';
    const totalPrice = document.getElementById('totalPriceDisplay');
    if (totalPrice) totalPrice.textContent = '0';
    if (!keepTooth) {
        const tooth = document.getElementById('selectedToothNumber');
        if (tooth) tooth.value = '';
    }
    pickedSurfaces.clear();
    updatePickerBtn();
    updateTreatmentToothRule();
}

// Intercept the add-treatment form: save via AJAX and update the page in place
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('addTreatmentForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (form.dataset.busy === '1') return;
        form.dataset.busy = '1';
        setFormLoading(form, true);
        const fd = new FormData(form);
        fd.set('ajax', '1');
        const saveAndNew = fd.get('save_and_new') === '1';
        fetch(window.location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(d => {
                form.dataset.busy = '';
                setFormLoading(form, false);
                if (!d.success) { toast('error', d.message || 'هەڵەیەک ڕوویدا'); return; }
                applyCaseSnapshot(d);
                toast('success', 'چارەسەر زیادکرا و چارتەکە نوێکرایەوە');
                if (saveAndNew) {
                    resetTreatmentForm(true);
                } else {
                    closeDialog('addTreatmentDialog');
                    resetTreatmentForm(false);
                }
            })
            .catch(() => {
                form.dataset.busy = '';
                setFormLoading(form, false);
                toast('error', 'کێشەی پەیوەندی — دووبارە هەوڵبدەرەوە');
            });
    }, true);
});

// Delete a single treatment from the tooth panel (AJAX, no reload)
function deleteToothTreatment(id) {
    if (!confirm('ئایا دڵنیایت لە سڕینەوەی ئەم چارەسەرە؟')) return;
    const fd = new FormData();
    fd.set('action', 'delete_treatment');
    fd.set('treatment_id', id);
    fd.set('ajax', '1');
    fd.set('csrf_token', window.CSRF_TOKEN || CSRF_TOKEN);
    fetch(window.location.href, {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(d => {
            if (!d.success) { toast('error', d.message || 'هەڵەیەک ڕوویدا'); return; }
            applyCaseSnapshot(d);
            toast('success', 'چارەسەرەکە سڕایەوە');
        })
        .catch(() => toast('error', 'کێشەی پەیوەندی — دووبارە هەوڵبدەرەوە'));
}
window.deleteToothTreatment = deleteToothTreatment;
window.openTreatmentPicker = openTreatmentPicker;
window.closeTreatmentPicker = closeTreatmentPicker;
window.onDialogToothChange = onDialogToothChange;

// Initial paint
document.addEventListener('DOMContentLoaded', function () {
    renderSmartChart();
    updatePickerBtn();
    updateSurfaceSection();
});

let currentSelectedTooth = null;
console.log('=== DATA LOADED ===');

// Discount calculation variables
const caseTotalAmount = <?= $case['total_amount'] ?? 0 ?>;
const currencySymbol = '';

// Update discount preview in real-time
window.updateDiscountPreview = function() {
    const discountType = document.querySelector('input[name="discount_type"]:checked')?.value || 'fixed';
    const discountValue = parseFloat(document.getElementById('discountValueInput')?.value) || 0;

    const inputHint = document.getElementById('discountInputHint');
    const inputSuffix = document.getElementById('discountSuffix');
    const amountPreview = document.getElementById('discountAmountPreview');
    const remainingPreview = document.getElementById('discountRemainingPreview');
    const validationMsg = document.getElementById('discountValidationMsg');
    const validationText = document.getElementById('discountValidationText');
    const submitBtn = document.getElementById('discountSubmitBtn');

    let discountAmount = 0;
    let isValid = true;
    let validationMessage = '';

    if (discountType === 'percentage') {
        // Update UI for percentage mode
        if (inputHint) inputHint.textContent = '(%)';
        if (inputSuffix) inputSuffix.textContent = '%';

        // Validate percentage
        if (discountValue < 0 || discountValue > 100) {
            isValid = false;
            validationMessage = 'ڕێژەی سەدی دەبێت لە نێوان 0 و 100 دا بێت';
        } else {
            discountAmount = caseTotalAmount * (discountValue / 100);
        }
    } else {
        // Update UI for fixed amount mode
        if (inputHint) inputHint.textContent = '';
        if (inputSuffix) inputSuffix.textContent = '';

        // Validate fixed amount
        if (discountValue < 0) {
            isValid = false;
            validationMessage = 'بڕی داشکاندن ناتوانێت کەمتر لە سفر بێت';
        } else if (discountValue > caseTotalAmount) {
            isValid = false;
            validationMessage = 'بڕی داشکاندن ناتوانێت لە کۆی گشتی (' + formatNumber(caseTotalAmount) + ') زیاتر بێت';
        } else {
            discountAmount = discountValue;
        }
    }

    const remainingAmount = caseTotalAmount - discountAmount;

    // Update preview displays
    if (amountPreview) {
        amountPreview.textContent = formatNumber(discountAmount) + ' ' + currencySymbol;
    }
    if (remainingPreview) {
        remainingPreview.textContent = formatNumber(remainingAmount) + ' ' + currencySymbol;
    }

    // Show/hide validation message
    if (validationMsg) {
        validationMsg.style.display = isValid ? 'none' : 'flex';
    }
    if (validationText) {
        validationText.textContent = validationMessage;
    }

    // Enable/disable submit button
    if (submitBtn) {
        submitBtn.disabled = !isValid;
    }
};

// Format number with thousand separators
function formatNumber(num) {
    return Math.round(num).toLocaleString('en-US');
}

function normalizeLocalizedDigits(value) {
    const digitMap = {
        '٠': '0', '۰': '0',
        '١': '1', '۱': '1',
        '٢': '2', '۲': '2',
        '٣': '3', '۳': '3',
        '٤': '4', '۴': '4',
        '٥': '5', '۵': '5',
        '٦': '6', '۶': '6',
        '٧': '7', '۷': '7',
        '٨': '8', '۸': '8',
        '٩': '9', '۹': '9'
    };

    return String(value || '').replace(/[٠-٩۰-۹]/g, digit => digitMap[digit] || digit);
}

window.sanitizeInstallmentCountInput = function(input) {
    if (!input) {
        return;
    }

    input.value = normalizeLocalizedDigits(input.value).replace(/\D/g, '');
};

window.updateInstallmentPreview = function() {
    const totalInput = document.getElementById('installment_total_amount');
    const downInput = document.getElementById('installment_down_payment');
    const countInput = document.getElementById('installment_number_of_installments');
    const remainingEl = document.querySelector('#installmentDialog #installmentPreviewRemaining');
    const monthlyEl = document.querySelector('#installmentDialog #installmentPreviewMonthly');

    if (!totalInput || !countInput || !remainingEl || !monthlyEl) {
        return;
    }

    sanitizeInstallmentCountInput(countInput);

    const totalAmount = Math.max(0, parseFloat(normalizeLocalizedDigits(totalInput.value)) || 0);
    const rawDownPayment = downInput ? Math.max(0, parseFloat(normalizeLocalizedDigits(downInput.value)) || 0) : 0;
    const downPayment = Math.min(rawDownPayment, totalAmount);
    const installmentsCount = Math.max(1, parseInt(countInput.value, 10) || 1);
    const remainingAfterDownPayment = totalAmount;
    const installmentAmount = installmentsCount > 0 ? Math.ceil(remainingAfterDownPayment / installmentsCount) : 0;

    remainingEl.textContent = formatNumber(remainingAfterDownPayment) + ' ' + currencySymbol;
    monthlyEl.textContent = formatNumber(installmentAmount) + ' ' + currencySymbol;

    if (downInput && downInput.type !== 'hidden' && rawDownPayment !== downPayment) {
        downInput.value = downPayment;
    }
};

// Initialize discount preview on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize discount preview if dialog exists
    if (document.getElementById('discountDialog')) {
        updateDiscountPreview();
    }

    updateInstallmentPreview();

    const installmentPlanForm = document.getElementById('installmentPlanForm');
    if (installmentPlanForm) {
        installmentPlanForm.addEventListener('submit', function(event) {
            const countInput = document.getElementById('installment_number_of_installments');
            sanitizeInstallmentCountInput(countInput);

            if (!countInput || !countInput.value || parseInt(countInput.value, 10) < 1) {
                event.preventDefault();
                if (countInput) {
                    countInput.focus();
                }
            }
        });
    }
});

// Make functions globally accessible
window.showTab = function(tabName, button, openPaymentDialog = false) {
    const content = document.getElementById('tab-' + tabName);
    if (!content) return;
    document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    content.style.display = 'block';
    if (button) {
        button.classList.add('active');
        // The strip scrolls on a narrow screen, so a tab opened by ?tab= or a
        // redirect hash can sit off to the side. Bring it in.
        if (button.scrollIntoView) button.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    // A doctor tab click opens the request. Redirects show the payment history.
    if (openPaymentDialog && tabName === 'payments' && <?= ($isDoctor && $canRequestPayment) ? 'true' : 'false' ?>) {
        window.openDialog('paymentDialog');
    }
};

// Switch tab by name (finds its button) — usable from links/redirect hashes
window.switchTab = function(tabName) {
    const btn = document.querySelector('.tab[onclick*="\'' + tabName + '\'"]');
    showTab(tabName, btn);
};

// Open the tab named in the URL hash (#tab-logs, #tab-images, #tab-payments…)
// or in ?tab= so redirects after add/update/delete land the user on the right tab.
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash;
    if (hash && hash.startsWith('#tab-')) {
        switchTab(hash.substring(5));
        return;
    }
    const wanted = new URLSearchParams(window.location.search).get('tab');
    if (wanted) switchTab(wanted);
});

<?php if ($canPrescribe || $canSeePrescriptions): ?>
/* ---------------------------------------------------------------------------
 * دەرمان tab — the clinic's paper prescription sheet on screen. Six columns
 * (Antibiotic, Analgesic, Toothpaste, Brush, Mouth Wash, Other), each with its
 * types as circles in the header and its items as tick boxes, in the paper's
 * order. Ticking adds the line below (quantity, dose); a header circle is the
 * form/brand for that group's ticked items that have no form of their own —
 * «Amoxicillin 250 mg» + ○Syrup is sent as Amoxicillin 250 mg (Syrup).
 *
 * Every active item is on the sheet, as on paper. What the main warehouse
 * lacks is marked, and sending it is allowed with a warning, so the counter
 * knows what to bring in. Talks to erp2/api/prescriptions.php.
 * ------------------------------------------------------------------------ */
(function () {
    const RX_API     = '<?= APP_URL ?>/erp2/api/prescriptions.php';
    const RX_CASE_ID = <?= (int)$caseId ?>;
    const RX_PATIENT = <?= (int)$case['patient_id'] ?>;
    const RX_DOCTOR  = <?= json_encode((string)($case['doctor_name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CAN_WRITE  = <?= $canPrescribe ? 'true' : 'false' ?>;
    const RX_CAN_DELETE = <?= Permission::getInstance()->can('erp2.delete') ? 'true' : 'false' ?>;
    const RX_ME      = <?= (int)($_SESSION['user_id'] ?? 0) ?>;

    let lines = [];        // [{product_id, group_id, name, type_name, quantity, dosage, stock, unit}]
    let groups = [];       // the sheet, from ?action=sheet
    const groupForm = {};  // group id → chosen header type id

    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nf = n => new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(Number(n) || 0);

    function msg(text, kind) {
        const box = $('rxMsg');
        if (!box) return;
        box.className = 'rx-msg ' + (kind || '');
        box.textContent = text || '';
    }

    /** The header circle chosen for a group, as {id, name} or null. */
    function formOf(gid) {
        const g = groups.find(x => x.id === gid);
        return g ? (g.types.find(t => t.id === groupForm[gid]) || null) : null;
    }
    /** What a line is sent as: its own form, else the group's circled one. */
    function lineForm(l) { return l.type_name || (formOf(l.group_id) || {}).name || ''; }

    /* ---------- the sheet ---------- */
    async function loadSheet() {
        const grid = $('rxGrid');
        if (!grid) return;
        try {
            const res  = await fetch(RX_API + '?action=sheet');
            const data = await res.json();
            if (!data || !data.ok) { grid.innerHTML = '<div class="rx-empty">' + esc((data && data.error) || 'نەتوانرا بارببرێت') + '</div>'; return; }
            groups = data.groups || [];
            renderSheet();
        } catch (e) {
            grid.innerHTML = '<div class="rx-empty">نەتوانرا پەڕەکە بارببرێت</div>';
        }
    }

    function renderSheet() {
        const grid = $('rxGrid');
        if (!grid) return;
        if (!groups.length) { grid.innerHTML = '<div class="rx-empty">هیچ کاڵایەک لە دەرمانخانە نییە</div>'; return; }
        const lineOf = {};
        lines.forEach(l => { lineOf[l.product_id] = l; });
        grid.innerHTML = groups.map((g, gi) => {
            const chosen = groupForm[g.id] || 0;
            const types = g.types.length
                ? '<div class="rxs-types">' + g.types.map(t =>
                    '<button type="button" class="rxs-t' + (t.id === chosen ? ' on' : '') + '" data-g="' + g.id + '" data-t="' + t.id + '"'
                    + ' role="radio" aria-checked="' + (t.id === chosen) + '">' + esc(t.name) + '</button>').join('') + '</div>'
                : '';
            const items = g.items.map(p => {
                const l = lineOf[p.id], on = !!l;
                // a circled form fades the items that are known to be another form
                const dim = !on && chosen && p.type_id && p.type_id !== chosen;
                // How many are on the pharmacy shelf, right beside the name.
                const qty = '<span class="rxs-q' + (p.stock <= 0 ? ' zero' : '') + '" title="'
                    + esc(p.stock <= 0 ? 'لە کۆگا نییە' : 'بەردەست لە کۆگا: ' + nf(p.stock) + (p.unit_name ? ' ' + p.unit_name : '')) + '">'
                    + nf(p.stock) + '</span>';
                return '<label class="rxs-it' + (on ? ' on' : '') + (p.stock <= 0 ? ' out' : '') + (dim ? ' dim' : '') + '"'
                    + ' data-name="' + esc(((p.name || '') + ' ' + (p.name_ku || '')).toLowerCase()) + '"'
                    + ' title="' + esc(p.name + (p.type_name ? ' — ' + p.type_name : '') + (p.stock <= 0 ? ' — لە کۆگا نییە' : ' — لە کۆگا: ' + nf(p.stock))) + '">'
                    + '<input type="checkbox" data-p="' + p.id + '" data-g="' + g.id + '"' + (on ? ' checked' : '') + (CAN_WRITE ? '' : ' disabled') + '>'
                    + '<span class="bx">' + (on ? '<i class="fas fa-check"></i>' : '') + '</span>'
                    // ticked: the dose under the name, and the small dose button
                    // beside it — the doctor changes it right here, one click
                    + (on
                        ? '<span class="nmw"><span class="nm">' + esc(p.name) + '</span>'
                          + '<small class="ds' + (l.dosage ? '' : ' none') + '" title="' + esc(l.dosage) + '">'
                          + esc(l.dosage || 'دۆز دانەنراوە') + (Number(l.quantity) !== 1 ? ' · ×' + nf(l.quantity) : '') + '</small></span>'
                          + qty
                          + '<button type="button" class="rxs-dose' + (l.dosage ? ' set' : '') + '" data-dose="' + p.id + '"'
                          + ' title="گۆڕینی دۆز و بڕ" aria-label="گۆڕینی دۆزی ' + esc(p.name) + '"><i class="fas fa-prescription"></i></button>'
                        : '<span class="nm">' + esc(p.name) + '</span>' + qty)
                    // once ticked the type moves to the panel and the table, so the
                    // name keeps its room next to the dose button
                    + (p.type_name && !on ? '<span class="tp">' + esc(p.type_name) + '</span>' : '')
                    + '</label>';
            }).join('');
            // The paper signs off under its last column.
            const doctor = gi === groups.length - 1
                ? '<div class="rxs-doc"><h5>Doctor</h5><div>' + (RX_DOCTOR ? 'د. ' + esc(RX_DOCTOR) : '&nbsp;') + '</div></div>'
                : '';
            return '<section class="rxs-col" aria-label="' + esc(g.name) + '">'
                + '<div class="rxs-head"><h5>' + esc(g.name) + '</h5>' + types + '</div>'
                + '<div class="rxs-body">' + items + doctor + '</div></section>';
        }).join('');
        applyFind();
    }

    function applyFind() {
        const box = $('rxFind');
        const q = box ? box.value.trim().toLowerCase() : '';
        document.querySelectorAll('#rxGrid .rxs-it').forEach(el => {
            el.classList.toggle('gone', q !== '' && !el.dataset.name.includes(q));
        });
    }

    function findItem(pid) {
        for (const g of groups) {
            const p = g.items.find(x => x.id === pid);
            if (p) return { g, p };
        }
        return null;
    }

    function toggleItem(pid) {
        if (popPid === pid) { $('rxPop').hidden = true; popPid = 0; }
        const at = lines.findIndex(l => l.product_id === pid);
        if (at >= 0) { lines.splice(at, 1); }
        else {
            const hit = findItem(pid);
            if (!hit) return;
            // the admin's default dose (erp2/dosages.php) fills the line; the
            // other saved doses stay a pick away in the line's dropdown
            const doses = hit.p.doses || [];
            lines.push({
                product_id: pid, group_id: hit.g.id, name: hit.p.name, type_name: hit.p.type_name || '',
                quantity: 1, dosage: doses.length ? doses[0].text : '', doses: doses,
                stock: Number(hit.p.stock) || 0, unit: hit.p.unit_name || ''
            });
        }
        renderSheet();
        render();
    }

    /* ---------- the dose panel of a ticked item ----------
     * Opened by the small button beside the name. A saved dose is one click;
     * a dose of the doctor's own is typed and Enter; the quantity is − / +. */
    let popPid = 0;

    function popAnchor() { return document.querySelector('#rxGrid .rxs-dose[data-dose="' + popPid + '"]'); }

    function placeDose() {
        const el = $('rxPop'), a = popAnchor();
        if (!el || el.hidden) return;
        if (!a) { closeDose(); return; }
        const r = a.getBoundingClientRect();
        const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
        const w = el.offsetWidth, h = el.offsetHeight;
        el.style.left = Math.max(8, Math.min(r.left - 12, vw - w - 8)) + 'px';
        el.style.top = (r.bottom + 6 + h > vh - 8 && r.top - 6 - h > 8 ? r.top - 6 - h : Math.min(r.bottom + 6, vh - h - 8)) + 'px';
    }

    function openDose(pid, anchor) {
        const l = lines.find(x => x.product_id === pid);
        if (!l) return;
        let el = $('rxPop');
        if (!el) {
            el = document.createElement('div');
            el.id = 'rxPop';
            el.className = 'rxs-pop';
            el.setAttribute('role', 'dialog');
            document.body.appendChild(el);
            el.addEventListener('click', onDoseClick);
            el.addEventListener('keydown', e => {
                if (e.key === 'Escape') { e.preventDefault(); closeDose(); }
                if (e.key === 'Enter' && e.target.id === 'rxPopDose') { e.preventDefault(); applyTyped(); }
            });
        }
        popPid = pid;
        const form = lineForm(l), presets = l.doses || [];
        el.innerHTML =
            '<div class="hd"><i class="fas fa-prescription"></i><bdi>' + esc(l.name) + '</bdi>'
            + (form ? '<span class="tp">' + esc(form) + '</span>' : '')
            + '<button type="button" class="x" data-act="close" aria-label="داخستن">×</button></div>'
            + (presets.length
                ? '<div class="ps">' + presets.map((d, k) =>
                    '<button type="button" class="pr' + (d.text === l.dosage ? ' on' : '') + '" data-preset="' + k + '">'
                    + (d.label ? '<span class="lb">' + esc(d.label) + '</span>' : '')
                    + '<span class="tx">' + esc(d.text) + '</span>'
                    + (d.text === l.dosage ? '<i class="fas fa-check"></i>' : '') + '</button>').join('') + '</div>'
                : '<div class="nops">دۆزی پاشەکەوتکراو نییە — لێرە بینووسە</div>')
            + '<div class="cu"><input type="text" id="rxPopDose" value="' + esc(l.dosage) + '" placeholder="دۆزی تایبەت بەم نەخۆشە…" autocomplete="off">'
            + '<button type="button" class="ok" data-act="ok" title="پاشەکەوت (Enter)"><i class="fas fa-check"></i></button></div>'
            + '<div class="qt"><span>بڕ</span><button type="button" data-act="dec" aria-label="کەمکردنەوە">−</button>'
            + '<b id="rxPopQty">' + nf(l.quantity) + '</b><button type="button" data-act="inc" aria-label="زیادکردن">+</button>'
            + (l.unit ? '<small>' + esc(l.unit) + '</small>' : '') + '</div>';
        el.hidden = false;
        placeDose();
        // no saved dose: straight into typing one
        const box = $('rxPopDose');
        if (!presets.length && box) { box.focus(); box.select(); }
    }

    // Update a ticked item's dose line and button in place. Never a redraw of
    // the sheet here: closing runs on mousedown, and replacing the item under
    // the pointer would swallow the click that follows (a tick needing two).
    function refreshItem(pid) {
        const l = lines.find(x => x.product_id === pid);
        const btn = document.querySelector('#rxGrid .rxs-dose[data-dose="' + pid + '"]');
        if (!l || !btn) return;
        const ds = btn.closest('.rxs-it').querySelector('.ds');
        if (ds) {
            ds.textContent = (l.dosage || 'دۆز دانەنراوە') + (Number(l.quantity) !== 1 ? ' · ×' + nf(l.quantity) : '');
            ds.classList.toggle('none', !l.dosage);
        }
        btn.classList.toggle('set', !!l.dosage);
    }

    function closeDose() {
        const el = $('rxPop'), pid = popPid;
        if (el) el.hidden = true;
        popPid = 0;
        if (pid) refreshItem(pid);
    }

    function applyTyped() {
        const l = lines.find(x => x.product_id === popPid), box = $('rxPopDose');
        if (l && box) l.dosage = box.value.trim();
        render();
        closeDose();
    }

    function onDoseClick(e) {
        const l = lines.find(x => x.product_id === popPid);
        if (!l) { closeDose(); return; }
        const pr = e.target.closest('[data-preset]');
        if (pr) {
            const d = (l.doses || [])[Number(pr.dataset.preset)];
            if (d) l.dosage = d.text;
            render();
            closeDose();
            return;
        }
        const act = (e.target.closest('[data-act]') || {}).dataset;
        if (!act) return;
        if (act.act === 'close') closeDose();
        else if (act.act === 'ok') applyTyped();
        else if (act.act === 'inc' || act.act === 'dec') {
            l.quantity = Math.max(1, (Number(l.quantity) || 1) + (act.act === 'inc' ? 1 : -1));
            $('rxPopQty').textContent = nf(l.quantity);
            render();
        }
    }

    // a click anywhere else closes it; the page scrolling carries it along
    document.addEventListener('mousedown', e => {
        const el = $('rxPop');
        if (el && !el.hidden && !el.contains(e.target) && !e.target.closest('.rxs-dose')) closeDose();
    });
    window.addEventListener('scroll', placeDose, true);
    window.addEventListener('resize', placeDose);

    /* ---------- lines ---------- */
    function removeLine(i) { lines.splice(i, 1); renderSheet(); render(); }

    function render() {
        const body = $('rxRows');
        if (!body) return;
        body.innerHTML = '';
        lines.forEach((l, i) => {
            const form = lineForm(l);
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td style="color:#999">' + (i + 1) + '</td>' +
                '<td>' + esc(l.name) + (form ? '<span class="rx-form">' + esc(form) + '</span>' : '') + '</td>' +
                '<td><input type="number" min="0.001" step="any" value="' + l.quantity + '" data-i="' + i + '" data-f="quantity"></td>' +
                '<td><input type="text" value="' + esc(l.dosage) + '" data-i="' + i + '" data-f="dosage" placeholder="ڕۆژانە ٢ جار…">' +
                    ((l.doses || []).length > 1
                        ? '<select class="rx-preset" data-i="' + i + '" data-f="preset" title="دۆزە پاشەکەوتکراوەکان">' +
                          l.doses.map((d, k) => '<option value="' + k + '"' + (d.text === l.dosage ? ' selected' : '') + '>' +
                              esc((d.label ? d.label + ': ' : '') + d.text) + '</option>').join('') + '</select>'
                        : '') + '</td>' +
                '<td class="rx-stock">' + (l.stock > 0 ? nf(l.stock) + ' ' + esc(l.unit) : '<span class="rx-out">نییە</span>') + '</td>' +
                '<td class="rx-del" data-del="' + i + '" title="لابردن"><i class="fas fa-times"></i></td>';
            body.appendChild(tr);
        });
        const empty = $('rxEmpty');
        if (empty) empty.style.display = lines.length ? 'none' : 'block';
        const btn = $('rxSendBtn');
        if (btn) btn.disabled = !lines.length;
        stockWarning();
    }

    /** Not a block — on paper the doctor may ask for anything — but say so. */
    function stockWarning() {
        const short = lines.filter(l => Number(l.quantity) > Number(l.stock)).map(l => l.name);
        if (short.length) {
            msg('ئاگاداری: ئەمانە لە کۆگا نین یان کەمن — دەرمانخانە دەبێت دابینیان بکات: ' + short.join('، '), 'warn');
        } else if ($('rxMsg') && /\b(warn|err)\b/.test($('rxMsg').className)) {
            msg('');
        }
    }

    /* ---------- send ---------- */
    window.rxSend = async function () {
        if (!lines.length) return;
        const bad = lines.find(l => !(Number(l.quantity) > 0));
        if (bad) { msg('بڕی «' + bad.name + '» دروست نییە', 'err'); return; }
        const btn = $('rxSendBtn');
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ناردن…';
        try {
            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('patient_id', RX_PATIENT);
            fd.append('case_id', RX_CASE_ID);
            fd.append('notes', $('rxNotes') ? $('rxNotes').value : '');
            // notes per line = the circled form/brand, when the item has none of its own
            fd.append('items', JSON.stringify(lines.map(l => ({
                product_id: l.product_id, quantity: l.quantity, dosage: l.dosage,
                notes: l.type_name ? '' : lineForm(l)
            }))));
            const res  = await fetch(RX_API, { method: 'POST', body: fd });
            const data = await res.json();
            if (data && data.ok) {
                lines = [];
                Object.keys(groupForm).forEach(k => delete groupForm[k]);
                if ($('rxNotes')) $('rxNotes').value = '';
                renderSheet();
                render();
                msg('نێردرا بۆ دەرمانخانە ✓', 'ok');
                loadHistory();
            } else {
                msg((data && (data.error || data.message)) || 'هەڵەیەک ڕوویدا', 'err');
            }
        } catch (e) {
            msg('هەڵەی تەکنیکی ڕوویدا', 'err');
        }
        btn.innerHTML = original;
        btn.disabled = !lines.length;
    };

    /* ---------- history ---------- */
    async function loadHistory() {
        const box = $('rxHistory');
        if (!box) return;
        try {
            const res  = await fetch(RX_API + '?action=case_list&case_id=' + RX_CASE_ID);
            const data = await res.json();
            const rows = (data && data.results) || [];
            const labels = { pending: 'چاوەڕوانی فرۆشتن', sold: 'فرۆشراوە', outside: 'دەرەوەی کلینیک', cancelled: 'ڕەتکراوە' };
            const pending = rows.filter(r => r.status === 'pending').length;
            const count = $('rxTabCount');
            if (count) count.textContent = pending;
            if (!rows.length) {
                box.innerHTML = '<div class="rx-empty">هیچ داواکارییەکی دەرمان بۆ ئەم کەیسە نییە.</div>';
                return;
            }
            // delete: an admin (erp2.delete) any time; the doctor who wrote it
            // while it is still pending — the server checks the same rule
            const mayDelete = r => RX_CAN_DELETE || (r.status === 'pending' && CAN_WRITE &&
                (Number(r.created_by) === RX_ME || Number(r.doctor_id) === RX_ME));
            box.innerHTML = rows.map(r =>
                '<div class="rx-card"><div class="top">' +
                    '<span><b>#' + r.id + '</b> · ' + esc((r.created_at || '').substring(0, 16)) +
                        ' · ' + esc(r.doctor_name || '—') + '</span>' +
                    '<span style="display:flex;align-items:center;gap:6px">' +
                    '<span class="rx-badge ' + esc(r.status) + '">' + esc(labels[r.status] || r.status) + '</span>' +
                    '<a class="rx-card-print" href="<?= APP_URL ?>/erp2/prescription-print.php?id=' + r.id + '" target="_blank" title="چاپی ڕەچەتە"><i class="fas fa-print"></i></a>' +
                    (mayDelete(r) ? '<button type="button" class="rx-card-del" data-rxdel="' + r.id + '" title="سڕینەوە"><i class="fas fa-trash-can"></i></button>' : '') +
                    '</span>' +
                '</div><div class="items" dir="auto">' + esc(r.items_summary || '') + '</div></div>'
            ).join('');
        } catch (e) {
            box.innerHTML = '<div class="rx-empty">نەتوانرا بارببرێت</div>';
        }
    }

    /* ---------- wiring ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        const grid = $('rxGrid');
        if (grid && CAN_WRITE) {
            grid.addEventListener('change', e => {
                const cb = e.target.closest('input[data-p]');
                if (cb) toggleItem(Number(cb.dataset.p));
            });
            grid.addEventListener('click', e => {
                const db = e.target.closest('.rxs-dose');
                if (db) {
                    // inside the item's label: keep the click from ticking it off
                    e.preventDefault();
                    e.stopPropagation();
                    if (popPid === Number(db.dataset.dose)) closeDose(); else openDose(Number(db.dataset.dose), db);
                    return;
                }
                const t = e.target.closest('.rxs-t');
                if (!t) return;
                const gid = Number(t.dataset.g), tid = Number(t.dataset.t);
                // the circled one again clears it
                if (groupForm[gid] === tid) delete groupForm[gid]; else groupForm[gid] = tid;
                renderSheet();
                render();
            });
            const find = $('rxFind');
            if (find) find.addEventListener('input', applyFind);
            loadSheet();
        }
        const body = $('rxRows');
        if (body) {
            body.addEventListener('input', e => {
                const el = e.target;
                if (!el.dataset || el.dataset.i === undefined) return;
                const i = Number(el.dataset.i), f = el.dataset.f;
                if (f === 'preset') {
                    // a saved dose picked from the line's dropdown
                    const d = (lines[i].doses || [])[Number(el.value)];
                    if (!d) return;
                    lines[i].dosage = d.text;
                    const box = el.closest('td').querySelector('input[data-f="dosage"]');
                    if (box) box.value = d.text;
                    return;
                }
                lines[i][f] = f === 'quantity' ? Number(el.value) : el.value;
                if (f === 'quantity') stockWarning();
            });
            body.addEventListener('click', e => {
                const cell = e.target.closest('[data-del]');
                if (cell) removeLine(Number(cell.dataset.del));
            });
            // a request withdrawn from the history list
            const hist = $('rxHistory');
            if (hist) hist.addEventListener('click', async e => {
                const b = e.target.closest('[data-rxdel]');
                if (!b) return;
                const id = Number(b.dataset.rxdel);
                if (!confirm('داواکاری دەرمانی #' + id + ' بسڕدرێتەوە؟')) return;
                const fd = new FormData();
                fd.append('action', 'prescription');
                fd.append('id', id);
                try {
                    const res = await fetch('<?= APP_URL ?>/erp2/api/delete.php', { method: 'POST', body: fd });
                    const d = await res.json();
                    if (d && d.ok) { msg('داواکاری #' + id + ' سڕایەوە', 'ok'); loadHistory(); }
                    else msg((d && (d.error || d.message)) || 'هەڵە', 'err');
                } catch (err) { msg('هەڵەی تەکنیکی ڕوویدا', 'err'); }
            });
            // a dose or quantity finished in the table shows under the name too
            body.addEventListener('change', () => lines.forEach(l => refreshItem(l.product_id)));
        }
        loadHistory();
    });
})();
<?php endif; ?>

window.switchTreatView = function(view) {
    const tableDiv = document.getElementById('treatmentTableView');
    const chartDiv = document.getElementById('treatmentChartView');
    const btnTable = document.getElementById('btnTreatTable');
    const btnChart = document.getElementById('btnTreatChart');
    if (!tableDiv || !chartDiv) return;
    if (view === 'chart') {
        tableDiv.style.display = 'none';
        chartDiv.style.display = 'block';
        btnChart.style.background = '#0078d4';
        btnChart.style.color = '#fff';
        btnTable.style.background = 'var(--bg-color)';
        btnTable.style.color = 'var(--text-secondary)';
    } else {
        chartDiv.style.display = 'none';
        tableDiv.style.display = 'block';
        btnTable.style.background = '#0078d4';
        btnTable.style.color = '#fff';
        btnChart.style.background = 'var(--bg-color)';
        btnChart.style.color = 'var(--text-secondary)';
    }
};

// Auto-switch to treatments tab when sent_to_accounting=1
(function() {
    const p = new URLSearchParams(window.location.search);
    if (p.get('sent_to_accounting') === '1') {
        const btn = document.querySelector('.tab[onclick*="treatments"]');
        if (btn) showTab('treatments', btn);
    }
})();

window.selectTooth = function(num) {
    document.querySelectorAll('.tooth.selected, .arch-tooth.selected').forEach(t => t.classList.remove('selected'));
    const el = document.querySelector('.tooth[data-tooth="' + num + '"], .arch-tooth[data-tooth="' + num + '"]');
    if (el) el.classList.add('selected');
    const sel = document.getElementById('selectedToothNumber');
    if (sel) sel.value = num;
    currentSelectedTooth = num;
};

window.showToothDetail = function(num) {
    // In bulk (multi-select) mode a click toggles selection instead of
    // opening the single-tooth detail panel.
    if (window.bulkMode) {
        toggleBulkTooth(num);
        return;
    }
    selectTooth(num);
    currentSelectedTooth = num;

    // Update panel header with Palmer notation
    const palmerNum = palmerNumbers[num] || (num % 10);
    const detailNumberEl = document.getElementById('toothDetailNumber');
    const detailNameEl = document.getElementById('toothDetailName');

    if (!detailNumberEl || !detailNameEl) {
        console.error('Tooth detail elements not found!');
        return;
    }

    detailNumberEl.textContent = 'ددان #' + palmerNum + ' (FDI: ' + num + ')';
    detailNameEl.textContent = toothNames[num] || 'ددان ' + num;

    // Anatomical preview of the tooth's current state
    const svgHolder = document.getElementById('toothDetailSvg');
    if (svgHolder) svgHolder.innerHTML = toothSVG(num, {idPrefix: 'panel'});

    // Get tooth data
    const toothData = toothTreatmentMap[num];
    const treatments = toothData ? toothData.treatments : [];
    const totalCost = toothData ? toothData.total_price : 0;

    // Update quick stats
    document.getElementById('toothTreatmentCount').textContent = treatments.length;
    document.getElementById('toothTotalCost').textContent = numberFormat(totalCost);

    // Build treatments list
    const listContainer = document.getElementById('toothTreatmentsList');

    if (treatments.length === 0) {
        listContainer.innerHTML = `
            <div class="no-treatments">
                <i class="fas fa-tooth"></i>
                <p>هیچ چارەسەرێک بۆ ئەم ددانە تۆمار نەکراوە</p>
            </div>
        `;
    } else {
        let html = '';
        treatments.forEach(t => {
            const category = t.category || 'treated';
            const date = t.created_at ? new Date(t.created_at).toLocaleDateString('ku') : '-';
            const surfaces = String(t.tooth_surface || '').split(',').filter(Boolean);
            const surfaceTags = surfaces.length
                ? `<div class="treatment-surface-tags">${surfaces.map(s => `<span class="treatment-surface-tag">${SURFACE_LABELS[s] || s}</span>`).join('')}</div>`
                : '';
            const delBtn = CASE_IS_OPEN
                ? `<button type="button" class="treatment-del-btn" onclick="deleteToothTreatment(${parseInt(t.id, 10)})" title="سڕینەوە"><i class="fas fa-trash"></i></button>`
                : '';
            html += `
                <div class="treatment-history-item ${category}">
                    ${delBtn}
                    <div class="treatment-name">${escapeHtml(t.treatment_name)}
                        <span style="font-weight: 400; font-size: 10px; color: var(--text-secondary);">(${CATEGORY_LABELS[category] || 'چارەسەر'})</span>
                    </div>
                    <div class="treatment-meta">
                        <span><i class="fas fa-calendar"></i> ${date}</span>
                        <span class="treatment-price"><i class="fas fa-money-bill"></i> ${numberFormat(t.final_price)}</span>
                        ${t.quantity > 1 ? `<span><i class="fas fa-times"></i> ${t.quantity}</span>` : ''}
                    </div>
                    ${surfaceTags}
                    ${t.notes ? `<div style="margin-top: 6px; font-size: 11px; color: var(--text-secondary);"><i class="fas fa-sticky-note"></i> ${escapeHtml(t.notes)}</div>` : ''}
                </div>
            `;
        });
        listContainer.innerHTML = html;
    }

    // Show panel
    document.getElementById('toothDetailOverlay').classList.add('open');
    document.getElementById('toothDetailPanel').classList.add('open');
}

function closeToothDetail() {
    document.getElementById('toothDetailOverlay').classList.remove('open');
    document.getElementById('toothDetailPanel').classList.remove('open');
}

// ═══════════════════════════════════════════════════════════════════
// Bulk (multi-tooth) treatment — pick several teeth, one treatment for all
// ═══════════════════════════════════════════════════════════════════
window.bulkMode = false;
let bulkSelected = [];               // FDI tooth numbers, in click order
let bulkTreatmentId = null;
let bulkTreatmentName = '';

function toggleBulkMode() {
    window.bulkMode = !window.bulkMode;
    const btn  = document.getElementById('bulkModeBtn');
    const hint = document.getElementById('bulkModeHint');
    document.body.classList.toggle('bulk-mode-on', window.bulkMode);
    if (window.bulkMode) {
        // Leaving single-select state so the two highlights never mix
        document.querySelectorAll('.tooth.selected').forEach(t => t.classList.remove('selected'));
        if (btn) { btn.style.background = '#0f766e'; btn.innerHTML = '<i class="fas fa-times"></i> وەستاندنی هەڵبژاردن'; }
        if (hint) hint.style.display = 'block';
    } else {
        if (btn) { btn.style.background = '#0d9488'; btn.innerHTML = '<i class="fas fa-object-group"></i> هەڵبژاردنی چەند ددان'; }
        if (hint) hint.style.display = 'none';
        clearBulkSelection();
    }
}

let lastBulkClicked = null;

function toggleBulkTooth(num) {
    num = parseInt(num, 10);
    const i = bulkSelected.indexOf(num);
    if (i === -1) bulkSelected.push(num);
    else bulkSelected.splice(i, 1);
    lastBulkClicked = num;
    renderBulkSelection();
}

// Shift+click: select every tooth between the last click and this one (DOM order)
function selectBulkRange(num) {
    num = parseInt(num, 10);
    // Only the visible tooth set — the other dentition's pane is in the DOM too
    const order = [...document.querySelectorAll('#smartDentalChart .dentition-pane:not([hidden]) .tooth')]
        .map(t => parseInt(t.dataset.tooth, 10));
    if (lastBulkClicked == null || order.indexOf(lastBulkClicked) === -1) {
        toggleBulkTooth(num);
        return;
    }
    let a = order.indexOf(lastBulkClicked), b = order.indexOf(num);
    if (a > b) { const t = a; a = b; b = t; }
    for (let i = a; i <= b; i++) {
        if (!bulkSelected.includes(order[i])) bulkSelected.push(order[i]);
    }
    renderBulkSelection();
}

// ── Right-click context menu ──
function openToothContextMenu(x, y) {
    const menu = document.getElementById('toothContextMenu');
    if (!menu) return;
    const n = bulkSelected.length;
    if (!n) return;

    let html = '<div class="ctx-title"><i class="fas fa-tooth"></i> ' + n + ' ددان هەڵبژێردراوە</div>';
    html += '<button type="button" class="tooth-ctx-item" onclick="ctxAddTreatment()">'
          + '<i class="fas fa-plus-circle"></i> زیادکردنی چارەسەر'
          + '<span class="ctx-sub">' + n + ' ددان</span></button>';
    if (n === 1) {
        html += '<button type="button" class="tooth-ctx-item" onclick="ctxViewDetail()">'
              + '<i class="fas fa-eye"></i> بینینی وردەکاری</button>';
    }
    html += '<div class="tooth-ctx-sep"></div>';
    html += '<button type="button" class="tooth-ctx-item danger" onclick="clearBulkSelection(); closeToothContextMenu();">'
          + '<i class="fas fa-eraser"></i> پاککردنەوەی هەڵبژاردن</button>';
    menu.innerHTML = html;

    // Show then clamp inside the viewport
    menu.style.display = 'block';
    const rect = menu.getBoundingClientRect();
    let left = x, top = y;
    if (left + rect.width > window.innerWidth - 8)  left = window.innerWidth - rect.width - 8;
    if (top + rect.height > window.innerHeight - 8) top = window.innerHeight - rect.height - 8;
    menu.style.left = Math.max(8, left) + 'px';
    menu.style.top  = Math.max(8, top) + 'px';
}

function closeToothContextMenu() {
    const menu = document.getElementById('toothContextMenu');
    if (menu) menu.style.display = 'none';
}

// Context-menu actions
function ctxAddTreatment() {
    closeToothContextMenu();
    if (bulkSelected.length === 1) {
        const num = bulkSelected[0];
        selectTooth(num);
        currentSelectedTooth = num;
        const sel = document.getElementById('selectedToothNumber');
        if (sel) sel.value = num;
        clearBulkSelection();
        openDialog('addTreatmentDialog');
        onDialogToothChange();
        const tsel = document.getElementById('treatmentSelect');
        if (tsel && !tsel.value) setTimeout(openTreatmentPicker, 120);
    } else {
        openBulkTreatmentDialog();
    }
}

function ctxViewDetail() {
    closeToothContextMenu();
    if (bulkSelected.length === 1) {
        const num = bulkSelected[0];
        clearBulkSelection();
        showToothDetail(num);
    }
}

// Wire modifier-click selection + right-click menu onto the chart
function initChartSelection() {
    const chart = document.getElementById('smartDentalChart');
    if (!chart) return;

    // Capture phase so this beats the tooth's inline onclick="showToothDetail(...)"
    chart.addEventListener('click', function(e) {
        const toothEl = e.target.closest('.tooth');
        if (!toothEl) return;
        const num = parseInt(toothEl.dataset.tooth, 10);
        if (e.ctrlKey || e.metaKey || window.bulkMode) {
            e.stopPropagation(); e.preventDefault();
            toggleBulkTooth(num);
        } else if (e.shiftKey) {
            e.stopPropagation(); e.preventDefault();
            selectBulkRange(num);
        } else if (bulkSelected.length) {
            // A plain click abandons a multi-selection and falls through to
            // the normal single-tooth detail (inline onclick still runs).
            clearBulkSelection();
        }
    }, true);

    chart.addEventListener('contextmenu', function(e) {
        const toothEl = e.target.closest('.tooth');
        if (!toothEl) return;
        e.preventDefault();
        const num = parseInt(toothEl.dataset.tooth, 10);
        // Right-clicking a tooth outside the selection targets just that tooth
        if (!bulkSelected.includes(num)) {
            bulkSelected = [num];
            lastBulkClicked = num;
            renderBulkSelection();
        }
        openToothContextMenu(e.clientX, e.clientY);
    });

    // Dismiss the menu on any outside click, scroll, or Escape
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#toothContextMenu')) closeToothContextMenu();
    });
    document.addEventListener('scroll', closeToothContextMenu, true);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeToothContextMenu();
            clearBulkSelection();
            if (document.getElementById('receiptDialog')?.classList.contains('open')) {
                closeReceiptDialog();
            }
        }
    });
}
document.addEventListener('DOMContentLoaded', initChartSelection);

// ═══════════════════════════════════════════════════════════════════
// Manage treatment types — add / edit / delete (live via api/treatments.php)
// ═══════════════════════════════════════════════════════════════════
let mtData = <?= json_encode(array_values(array_map(function ($t) {
    return [
        'id'       => (int)$t['id'],
        'name'     => $t['treatment_name'],
        'price'    => (float)$t['default_price'],
        'category' => $t['category'] ?? 'other',
        'scope'    => $t['scope'] ?? 'both',
        'requiresTooth' => (int)($t['requires_tooth_number'] ?? 1) === 1,
    ];
}, array_filter($treatments, fn($t) => (int)($t['is_active'] ?? 1) === 1))),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let mtEditingId = null;
const MT_SCOPE_LABELS = { tooth: 'ددان', arch: 'ئەرک', both: 'هەردوو' };
const CASE_TREATMENT_SCOPE = <?= json_encode(($case['scope_type'] ?? 'tooth') === 'arch' ? 'arch' : 'tooth') ?>;

// Keep every treatment picker on this page in sync with an add/edit response.
// Previously the management dialog only updated mtData and relied on a full
// page reload when it closed. Until that reload, the quick-entry search still
// held the old requiresTooth value, so a type changed to "no tooth number"
// could remain missing/stale or keep the tooth boxes enabled.
function mtSyncTreatmentCatalog(raw) {
    const id = parseInt(raw.id, 10);
    if (!id) return;
    const name = raw.treatment_name != null ? String(raw.treatment_name) : String(raw.name || '');
    const price = parseFloat(raw.default_price != null ? raw.default_price : raw.price) || 0;
    const category = raw.category || 'other';
    const scope = raw.scope || 'tooth';
    const requiresTooth = raw.requires_tooth_number != null
        ? Number(raw.requires_tooth_number) === 1
        : raw.requiresTooth !== false;
    const item = { id: id, name: name, price: price, category: category, scope: scope, requiresTooth: requiresTooth };
    const appliesHere = scope === CASE_TREATMENT_SCOPE || scope === 'both';
    const at = treatmentCatalog.findIndex(t => Number(t.id) === id);

    if (appliesHere) {
        if (at === -1) treatmentCatalog.push(item);
        else treatmentCatalog[at] = item;
    } else if (at !== -1) {
        treatmentCatalog.splice(at, 1);
    }

    // The older single-treatment dialog still uses a native select; keep it
    // aligned with the same catalog so every entry point behaves identically.
    const select = document.getElementById('treatmentSelect');
    if (select) {
        let option = Array.from(select.options).find(opt => Number(opt.value) === id);
        if (!appliesHere) {
            if (option) option.remove();
        } else {
            if (!option) {
                option = document.createElement('option');
                option.value = id;
                select.appendChild(option);
            }
            option.dataset.price = price;
            option.dataset.category = category;
            option.dataset.name = name;
            option.dataset.requiresTooth = requiresTooth ? '1' : '0';
            option.textContent = name + ' (' + numberFormat(price) + ')';
        }
    }

    // If this treatment was already picked in an unsaved table row, apply the
    // new rule immediately instead of leaving four stale tooth inputs visible.
    document.querySelectorAll('.rt-add-row').forEach(row => {
        const chosenId = Number((row.querySelector('.rt-combo-id') || {}).value);
        if (chosenId !== id) return;
        if (!appliesHere) {
            rtClear(row.dataset.addFor, true);
            return;
        }
        const input = row.querySelector('.rt-combo-input');
        if (input) input.value = name;
        rtApplyToothRule(row.dataset.addFor, item);
        rtCalcTotal(row.dataset.addFor);
    });

    // Repaint an open search list as well (normally the management dialog is
    // on top, but this also covers keyboard-driven/embedded uses).
    if (rtComboOwner) rtComboOpen(rtComboOwner, rtComboOwner.value);
}

function mtPost(payload) {
    return fetch('<?= APP_URL ?>/api/treatments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(payload)
    }).then(r => r.json());
}

function mtFillCategorySelect(sel, current) {
    if (!sel) return;
    sel.innerHTML = Object.keys(CATEGORY_LABELS).map(k =>
        '<option value="' + k + '"' + (k === current ? ' selected' : '') + '>' + CATEGORY_LABELS[k] + '</option>'
    ).join('');
}

function openManageTreatmentsDialog() {
    mtEditingId = null;
    document.getElementById('mtNewName').value = '';
    document.getElementById('mtNewPrice').value = 0;
    document.getElementById('mtSearch').value = '';
    mtFillCategorySelect(document.getElementById('mtNewCategory'), 'other');
    document.getElementById('mtNewScope').value = '<?= ($case['scope_type'] ?? 'tooth') === 'arch' ? 'arch' : 'tooth' ?>';
    mtSyncScopeRule(null);
    const err = document.getElementById('mtAddErr'); if (err) err.style.display = 'none';
    mtRender();
    openDialog('manageTreatmentsDialog');
}

function closeManageTreatmentsDialog() {
    closeDialog('manageTreatmentsDialog');
    // Catalogs are synchronized as each save succeeds; keep the current visit
    // and any unsaved quick-entry values instead of forcing a full reload.
}

function mtRender() {
    const list = document.getElementById('mtList');
    if (!list) return;
    const f = (document.getElementById('mtSearch').value || '').trim().toLowerCase();
    const rows = mtData
        .filter(t => !f || String(t.name).toLowerCase().indexOf(f) !== -1)
        .sort((a, b) => String(a.name).localeCompare(String(b.name), 'ar'));

    document.getElementById('mtCount').textContent = mtData.length + ' جۆر';

    if (!rows.length) {
        list.innerHTML = '<div class="mt-empty"><i class="fas fa-inbox"></i> هیچ جۆرێک نەدۆزرایەوە</div>';
        return;
    }
    list.innerHTML = rows.map(t => {
        if (t.id === mtEditingId) {
            return (
                '<div class="mt-row editing" data-id="' + t.id + '">' +
                  '<div class="mt-edit-fields">' +
                    '<input type="text" class="mt-en-name" id="mtEditName_' + t.id + '" value="' + escapeHtml(t.name) + '" placeholder="ناوی چارەسەر">' +
                    '<input type="number" class="mt-en-price" id="mtEditPrice_' + t.id + '" value="' + t.price + '" min="0" step="0.01">' +
                    '<select class="mt-en-cat" id="mtEditCategory_' + t.id + '"></select>' +
                    '<select class="mt-en-scope" id="mtEditScope_' + t.id + '" onchange="mtSyncScopeRule(' + t.id + ')">' +
                      '<option value="tooth">ددان</option><option value="arch">ئەرک</option><option value="both">هەردوو</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="mt-edit-acts">' +
                    '<button type="button" class="mt-save" onclick="mtSaveEdit(' + t.id + ')"><i class="fas fa-check"></i> پاشەکەوت</button>' +
                    '<button type="button" class="mt-cancel" onclick="mtCancelEdit()"><i class="fas fa-times"></i> پاشگەزبوونەوە</button>' +
                  '</div>' +
                '</div>'
            );
        }
        return (
            '<div class="mt-row" data-id="' + t.id + '">' +
              '<span class="mt-icon">' + categoryIconFor(t) + '</span>' +
              '<span class="mt-name" title="' + escapeHtml(t.name) + '">' + escapeHtml(t.name) + '</span>' +
              '<span class="mt-cat">' + (CATEGORY_LABELS[t.category] || 'تر') + '</span>' +
              '<span class="mt-price">' + numberFormat(t.price) + '</span>' +
              '<button type="button" class="mt-edit" onclick="mtStartEdit(' + t.id + ')" title="دەستکاری"><i class="fas fa-pen"></i></button>' +
              '<button type="button" class="mt-del" onclick="mtDelete(' + t.id + ')" title="سڕینەوە"><i class="fas fa-trash"></i></button>' +
            '</div>'
        );
    }).join('');

    // Populate the edit-row selects (can't set <select> value via string)
    if (mtEditingId != null) {
        const t = mtData.find(x => x.id === mtEditingId);
        if (t) {
            mtFillCategorySelect(document.getElementById('mtEditCategory_' + t.id), t.category);
            const sc = document.getElementById('mtEditScope_' + t.id); if (sc) sc.value = t.scope;
        }
    }
}

function mtSyncScopeRule(id) {
    const scope = document.getElementById(id == null ? 'mtNewScope' : ('mtEditScope_' + id));
    const rule = document.getElementById(id == null ? 'mtNewRequiresTooth' : ('mtEditRequires_' + id));
    if (!scope || !rule) return;
    const archOnly = scope.value === 'arch';
    if (archOnly) rule.value = '0';
    rule.disabled = archOnly;
    rule.title = archOnly ? 'کاری ئەرک هەمیشە بێ ژمارەی تاکی ددانە' : 'ئایا ژمارەی ددان پێویستە؟';
}

function mtAdd() {
    const name = (document.getElementById('mtNewName').value || '').trim();
    const price = document.getElementById('mtNewPrice').value || 0;
    const category = document.getElementById('mtNewCategory').value || 'other';
    const scope = document.getElementById('mtNewScope').value || 'tooth';
    const requiresTooth = true;   // tooth number is optional for every treatment
    const err = document.getElementById('mtAddErr');
    const btn = document.getElementById('mtAddBtn');
    err.style.display = 'none';
    if (!name) { err.textContent = 'تکایە ناوی چارەسەر بنووسە'; err.style.display = 'block'; return; }

    btn.disabled = true;
    mtPost({ action: 'add', treatment_name: name, default_price: price, category: category, scope: scope, requires_tooth_number: requiresTooth })
        .then(d => {
            btn.disabled = false;
            if (!d.success) { err.textContent = d.message || 'هەڵەیەک ڕوویدا'; err.style.display = 'block'; return; }
            mtData.push({ id: parseInt(d.id, 10), name: d.treatment_name, price: parseFloat(d.default_price) || 0, category: d.category, scope: d.scope, requiresTooth: Number(d.requires_tooth_number) === 1 });
            mtSyncTreatmentCatalog(d);
            document.getElementById('mtNewName').value = '';
            document.getElementById('mtNewPrice').value = 0;
            mtRender();
        })
        .catch(e => { btn.disabled = false; err.textContent = 'کێشەی پەیوەندی: ' + e.message; err.style.display = 'block'; });
}

function mtStartEdit(id) { mtEditingId = id; mtRender(); }
function mtCancelEdit() { mtEditingId = null; mtRender(); }

function mtSaveEdit(id) {
    const name = (document.getElementById('mtEditName_' + id).value || '').trim();
    const price = document.getElementById('mtEditPrice_' + id).value || 0;
    const category = document.getElementById('mtEditCategory_' + id).value || 'other';
    const scope = document.getElementById('mtEditScope_' + id).value || 'tooth';
    const requiresTooth = true;   // tooth number is optional for every treatment
    if (!name) { alert('تکایە ناوی چارەسەر بنووسە'); return; }

    mtPost({ action: 'update', id: id, treatment_name: name, default_price: price, category: category, scope: scope, requires_tooth_number: requiresTooth })
        .then(d => {
            if (!d.success) { alert(d.message || 'هەڵەیەک ڕوویدا'); return; }
            const t = mtData.find(x => x.id === id);
            if (t) { t.name = d.treatment_name; t.price = parseFloat(d.default_price) || 0; t.category = d.category; t.scope = d.scope; t.requiresTooth = Number(d.requires_tooth_number) === 1; }
            mtSyncTreatmentCatalog(d);
            mtEditingId = null;
            mtRender();
        })
        .catch(e => alert('کێشەی پەیوەندی: ' + e.message));
}

/**
 * Remove a treatment type from the pickers.
 *
 * api/treatments.php?action=delete is a SOFT delete (is_active = 0): every
 * case_treatments row keeps its treatment_id, name and price, so past work and
 * already-printed invoices are unaffected. Only the pickers stop offering the
 * type. The confirm text says exactly that, so nobody expects history to go.
 */
function mtDelete(id) {
    const t = mtData.find(x => x.id === id);
    if (!t) return;

    Swal.fire({
        icon: 'warning',
        title: 'لابردنی جۆری چارەسەر',
        html: '<b>' + escapeHtml(t.name) + '</b><br><br>'
            + 'لە لیستی هەڵبژاردن لادەبرێت.<br>'
            + 'کارە تۆمارکراوەکانی پێشوو وەک خۆیان دەمێننەوە.',
        showCancelButton: true,
        confirmButtonText: 'بەڵێ، لایبە',
        cancelButtonText: 'پاشگەزبوونەوە',
        confirmButtonColor: '#c62026',
        reverseButtons: true
    }).then(res => {
        if (!res.isConfirmed) return;
        mtPost({ action: 'delete', id: id })
            .then(d => {
                if (!d.success) {
                    Swal.fire({ icon: 'error', title: d.message || 'هەڵەیەک ڕوویدا', confirmButtonText: 'باشە' });
                    return;
                }
                mtData = mtData.filter(x => x.id !== id);
                mtDropFromCatalog(id);
                if (mtEditingId === id) mtEditingId = null;
                mtRender();
            })
            .catch(e => Swal.fire({ icon: 'error', title: 'کێشەی پەیوەندی: ' + e.message, confirmButtonText: 'باشە' }));
    });
}

/** Drop a removed type from every in-page picker (mirror of mtSyncTreatmentCatalog). */
function mtDropFromCatalog(id) {
    const at = treatmentCatalog.findIndex(t => Number(t.id) === Number(id));
    if (at !== -1) treatmentCatalog.splice(at, 1);

    const select = document.getElementById('treatmentSelect');
    if (select) {
        const option = Array.from(select.options).find(opt => Number(opt.value) === Number(id));
        if (option) option.remove();
    }
    // Repaint an open quick-entry combo so the removed type cannot still be picked.
    if (typeof rtComboOwner !== 'undefined' && rtComboOwner) rtComboOpen(rtComboOwner, rtComboOwner.value);
}

function renderBulkSelection() {
    // Highlight the teeth on the chart
    document.querySelectorAll('.smart-dental-chart .tooth.bulk-selected').forEach(t => t.classList.remove('bulk-selected'));
    bulkSelected.forEach(num => {
        const el = document.querySelector('.smart-dental-chart .tooth[data-tooth="' + num + '"]');
        if (el) el.classList.add('bulk-selected');
    });

    const bar = document.getElementById('bulkActionBar');
    if (!bar) return;
    if (!bulkSelected.length) { bar.style.display = 'none'; return; }
    bar.style.display = 'block';

    document.getElementById('bulkCount').textContent = bulkSelected.length;
    const listEl = document.getElementById('bulkTeethList');
    listEl.innerHTML = bulkSelected.map(num => {
        const palmer = palmerNumbers[num] || (num % 10);
        return '<span class="bt-chip">' + palmer +
               ' <span class="bt-x" onclick="toggleBulkTooth(' + num + ')">&times;</span></span>';
    }).join('');
}

function clearBulkSelection() {
    bulkSelected = [];
    renderBulkSelection();
}

function openBulkTreatmentDialog() {
    if (!bulkSelected.length) { alert('تکایە لانیکەم یەک ددان هەڵبژێرە'); return; }

    // Reset the picker each time
    bulkTreatmentId = null;
    bulkTreatmentName = '';
    document.getElementById('bulkTreatmentId').value = '';
    document.getElementById('bulkTreatmentName').value = '';
    document.getElementById('bulkTrInput').value = '';
    document.getElementById('bulkUnitPrice').value = 0;
    document.getElementById('bulkQuantity').value = 1;

    // Show the chosen teeth inside the dialog
    document.getElementById('bulkDialogTeeth').innerHTML = bulkSelected.map(num => {
        const palmer = palmerNumbers[num] || (num % 10);
        const name = (toothNames[num] || ('ددان ' + num));
        return '<span class="bd-chip" title="' + escapeHtml(name) + '">' + palmer + '</span>';
    }).join('');
    document.getElementById('bulkTeethCountLabel').textContent = bulkSelected.length;

    document.getElementById('bulkToothNumbers').value = JSON.stringify(bulkSelected);
    bulkCalcTotal();
    openDialog('bulkTreatmentDialog');
}

// ── searchable treatment combo inside the bulk dialog ──
function bulkTrRender(filter) {
    const dd = document.getElementById('bulkTrDropdown');
    if (!dd) return;
    dd.innerHTML = '';
    // Same ranked, spelling-tolerant matcher as the receipt row, so both places
    // to pick a treatment behave identically: recents first on an empty box,
    // best match first while typing.
    const rows = rtComboMatches(filter);
    const terms = rtComboNorm(filter).split(' ').filter(Boolean);
    if (!rows.length) {
        const em = document.createElement('div');
        em.className = 'city-combo-empty';
        em.textContent = 'هیچ چارەسەرێک نەدۆزرایەوە';
        dd.appendChild(em);
        return;
    }
    rows.forEach(r => {
        const t = r.t;
        const it = document.createElement('div');
        it.className = 'city-combo-item';
        it.style.display = 'flex';
        it.style.justifyContent = 'space-between';
        it.innerHTML = '<span>' + rtComboLabel(t.name, terms) + '</span>' +
                       '<span style="color:#0d9488;font-weight:700;">' + numberFormat(t.price) + '</span>';
        it.addEventListener('mousedown', function(ev) { ev.preventDefault(); bulkTrPick(t); });
        dd.appendChild(it);
    });
}
function bulkTrOpen() {
    bulkTrRender(document.getElementById('bulkTrInput').value);
    document.getElementById('bulkTrDropdown').style.display = 'block';
}
function bulkTrFilter() {
    const v = document.getElementById('bulkTrInput').value;
    // typing invalidates a previous pick until an exact match is chosen
    bulkTreatmentId = null;
    document.getElementById('bulkTreatmentId').value = '';
    bulkTrRender(v);
    document.getElementById('bulkTrDropdown').style.display = 'block';
}
function bulkTrPick(t) {
    bulkTreatmentId = t.id;
    bulkTreatmentName = t.name;
    rtRecentPush(t.id);
    document.getElementById('bulkTreatmentId').value = t.id;
    document.getElementById('bulkTreatmentName').value = t.name;
    document.getElementById('bulkTrInput').value = t.name;
    if (Number(document.getElementById('bulkUnitPrice').value) === 0) {
        document.getElementById('bulkUnitPrice').value = t.price;
    }
    document.getElementById('bulkTrDropdown').style.display = 'none';
    bulkCalcTotal();
}
document.addEventListener('click', function(ev) {
    if (!(ev.target.closest && ev.target.closest('#bulkTreatmentDialog .city-combo'))) {
        const dd = document.getElementById('bulkTrDropdown');
        if (dd) dd.style.display = 'none';
    }
});

function bulkCalcTotal() {
    const qty = parseInt(document.getElementById('bulkQuantity').value) || 1;
    const price = parseFloat(document.getElementById('bulkUnitPrice').value) || 0;
    const total = qty * price * bulkSelected.length;
    document.getElementById('bulkTotalDisplay').textContent = numberFormat(total);
}

document.getElementById('bulkTreatmentForm')?.addEventListener('submit', function(e) {
    if (!bulkTreatmentId) {
        e.preventDefault();
        alert('تکایە چارەسەرێک هەڵبژێرە');
        return;
    }
    if (!bulkSelected.length) {
        e.preventDefault();
        alert('تکایە لانیکەم یەک ددان هەڵبژێرە');
        return;
    }
    const btn = document.getElementById('bulkSubmitBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> پاشەکەوت دەکرێت...'; }
});

function addTreatmentForTooth() {
    closeToothDetail();
    if (currentSelectedTooth) {
        document.getElementById('selectedToothNumber').value = currentSelectedTooth;
    }
    openDialog('addTreatmentDialog');
    onDialogToothChange();
    // No treatment picked yet → jump straight into the picker for fewer clicks
    const sel = document.getElementById('treatmentSelect');
    if (sel && !sel.value) {
        setTimeout(openTreatmentPicker, 120);
    }
}

// Store current default price for use by useDefaultPrice function
let currentDefaultPrice = 0;

function updateTreatmentPrice() {
    const select = document.getElementById('treatmentSelect');
    const option = select.options[select.selectedIndex];
    if (!option || !select.value) {
        updateTreatmentToothRule();
        return;
    }
    const price = parseFloat(option.dataset.price) || 0;

    // Update default price display
    currentDefaultPrice = price;
    document.getElementById('defaultPriceDisplay').textContent = numberFormat(price);

    // Set agreement price to default price initially
    document.getElementById('unitPrice').value = price;

    // Update treatment name
    document.getElementById('treatmentName').value = option.text.split(' (')[0];

    calculateTotal();
    updateTreatmentToothRule();
}

// ── Add-new-treatment dialog ──
function openAddTreatmentDialog() {
    document.getElementById('newTreatmentName').value = '';
    document.getElementById('newTreatmentPrice').value = '';
    document.getElementById('newTreatmentCategory').value = 'other';
    const err = document.getElementById('newTreatmentError');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    openDialog('addNewTreatmentDialog');
    setTimeout(function() {
        var el = document.getElementById('newTreatmentName');
        if (el) el.focus();
    }, 60);
}

function submitNewTreatment() {
    const nameEl  = document.getElementById('newTreatmentName');
    const priceEl = document.getElementById('newTreatmentPrice');
    const catEl   = document.getElementById('newTreatmentCategory');
    const err     = document.getElementById('newTreatmentError');
    const btn     = document.getElementById('submitNewTreatmentBtn');

    const name = (nameEl.value || '').trim();
    const price = priceEl.value || '0';

    err.style.display = 'none';
    if (!name) { err.textContent = 'تکایە ناوی چارەسەر بنووسە'; err.style.display = 'block'; return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> پاشەکەوت دەکرێت...';

    fetch('<?= APP_URL ?>/api/treatments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({
            action: 'add',
            treatment_name: name,
            default_price: price,
            scope: 'tooth',
            category: catEl.value || 'other',
            requires_tooth_number: true
        })
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> زیادکردن';
        if (!d.success) {
            err.textContent = d.message || 'هەڵەیەک ڕوویدا';
            err.style.display = 'block';
            return;
        }
        // Inject the new option into the treatmentSelect and select it
        const sel = document.getElementById('treatmentSelect');
        if (sel) {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.dataset.price = d.default_price;
            opt.dataset.name  = d.treatment_name;
            opt.dataset.category = d.category || catEl.value || 'other';
            opt.dataset.requiresTooth = Number(d.requires_tooth_number) === 1 ? '1' : '0';
            opt.textContent = d.treatment_name + ' (' + Number(d.default_price).toLocaleString() + ')';
            opt.selected = true;
            sel.appendChild(opt);
            if (typeof updateTreatmentPrice === 'function') updateTreatmentPrice();
        }
        // Keep the searchable picker in sync
        treatmentCatalog.push({
            id: parseInt(d.id, 10),
            name: d.treatment_name,
            category: d.category || catEl.value || 'other',
            price: parseFloat(d.default_price) || 0,
            requiresTooth: Number(d.requires_tooth_number) === 1
        });
        updatePickerBtn();
        updateSurfaceSection();
        closeDialog('addNewTreatmentDialog');
    })
    .catch(e => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> زیادکردن';
        err.textContent = 'کێشەی پەیوەندی: ' + e.message;
        err.style.display = 'block';
    });
}

function useDefaultPrice() {
    document.getElementById('unitPrice').value = currentDefaultPrice;
    calculateTotal();

    // Add visual feedback
    const btn = document.querySelector('.use-default-btn');
    btn.style.transform = 'rotate(360deg) scale(1.2)';
    setTimeout(() => {
        btn.style.transform = '';
    }, 300);
}

function calculateTotal() {
    const quantity = parseFloat(document.getElementById('treatmentQuantity')?.value) || 1;
    const unitPrice = parseFloat(document.getElementById('unitPrice').value) || 0;
    const discount = parseFloat(document.getElementById('treatmentDiscount')?.value) || 0;
    const total = (quantity * unitPrice) - discount;

    // Update total display
    const totalDisplay = document.getElementById('totalPriceDisplay');
    if (totalDisplay) {
        totalDisplay.textContent = numberFormat(total);
    }
}

// Arch Treatment Functions
let archDefaultPrice = 0;
let selectedArches = [];

function openArchTreatmentDialog() {
    const form = document.getElementById('archTreatmentForm');
    if (form) form.reset();
    // Reset selection
    selectedArches = [];
    document.querySelectorAll('.arch-select-panel').forEach(p => p.classList.remove('selected'));
    document.getElementById('archTreatmentArchType').value = '';
    document.getElementById('archTeethCountDisplay').textContent = '0';
    document.getElementById('archTotalPriceDisplay').textContent = '0';
    const separate = document.getElementById('separatePriceOption');
    if (separate) separate.style.display = 'none';
    ['archUnitPrice', 'archTotalAgreedPrice', 'upperArchPrice', 'lowerArchPrice', 'hiddenUpperPrice', 'hiddenLowerPrice'].forEach(function (id) {
        const input = document.getElementById(id); if (input) input.value = '';
    });
    switchPricingMode('per_arch');
    openDialog('archTreatmentDialog');
}

function selectArchForTreatment(arch) {
    const panel = document.querySelector(`.arch-select-panel[data-arch="${arch}"]`);
    panel.classList.toggle('selected');

    // Update selected arches array
    if (panel.classList.contains('selected')) {
        if (!selectedArches.includes(arch)) {
            selectedArches.push(arch);
        }
    } else {
        selectedArches = selectedArches.filter(a => a !== arch);
    }

    // Update hidden input
    if (selectedArches.length === 2) {
        document.getElementById('archTreatmentArchType').value = 'both';
    } else if (selectedArches.length === 1) {
        document.getElementById('archTreatmentArchType').value = selectedArches[0];
    } else {
        document.getElementById('archTreatmentArchType').value = '';
    }

    // An arch is one job; never multiply its price by sixteen teeth.
    document.getElementById('archTeethCountDisplay').textContent = selectedArches.length;

    // Show/hide separate prices option (only when both arches selected)
    const separateOption = document.getElementById('separatePriceOption');
    if (selectedArches.length === 2) {
        separateOption.style.display = 'flex';
    } else {
        separateOption.style.display = 'none';
        // If separate mode was selected, switch back to the per-arch price.
        const currentMode = document.querySelector('input[name="pricing_mode"]:checked')?.value;
        if (currentMode === 'separate') {
            switchPricingMode('per_arch');
        }
    }

    calculateArchTotal();
}

function validateArchForm() {
    const archType = document.getElementById('archTreatmentArchType').value;
    if (!archType) {
        alert('تکایە لانیکەم یەک ئەرک هەڵبژێرە');
        return false;
    }
    return true;
}

function updateArchTreatmentPrice() {
    const select = document.getElementById('archTreatmentSelect');
    const option = select.options[select.selectedIndex];
    const price = parseFloat(option.dataset.price) || 0;
    const name = option.dataset.name || '';

    archDefaultPrice = price;
    document.getElementById('archDefaultPriceDisplay').textContent = numberFormat(price);
    document.getElementById('archUnitPrice').value = price;
    document.getElementById('archTreatmentName').value = name;

    calculateArchTotal();
}

function useArchDefaultPrice() {
    document.getElementById('archUnitPrice').value = archDefaultPrice;
    calculateArchTotal();

    const btn = document.querySelector('.arch-use-default-btn');
    if (btn) {
        btn.style.transform = 'rotate(360deg) scale(1.2)';
        setTimeout(() => {
            btn.style.transform = '';
        }, 300);
    }
}

function calculateArchTotal() {
    const pricingMode = document.querySelector('input[name="pricing_mode"]:checked')?.value || 'per_arch';
    const archCount = selectedArches.length;
    let total = 0;

    if (pricingMode === 'per_arch') {
        const unitPrice = parseFloat(document.getElementById('archUnitPrice').value) || 0;
        total = unitPrice * archCount;
        document.getElementById('hiddenUpperPrice').value = unitPrice;
        document.getElementById('hiddenLowerPrice').value = unitPrice;
    } else if (pricingMode === 'total') {
        total = parseFloat(document.getElementById('archTotalAgreedPrice').value) || 0;
        const perArchPrice = archCount > 0 ? total / archCount : 0;
        document.getElementById('calculatedPerToothPrice').textContent = numberFormat(perArchPrice);
        document.getElementById('hiddenUpperPrice').value = perArchPrice;
        document.getElementById('hiddenLowerPrice').value = perArchPrice;
    } else if (pricingMode === 'separate') {
        const upperPrice = parseFloat(document.getElementById('upperArchPrice').value) || 0;
        const lowerPrice = parseFloat(document.getElementById('lowerArchPrice').value) || 0;

        // Total depends on which arches are selected
        if (selectedArches.includes('upper') && selectedArches.includes('lower')) {
            total = upperPrice + lowerPrice;
        } else if (selectedArches.includes('upper')) {
            total = upperPrice;
        } else if (selectedArches.includes('lower')) {
            total = lowerPrice;
        }

        // Set hidden prices
        document.getElementById('hiddenUpperPrice').value = upperPrice;
        document.getElementById('hiddenLowerPrice').value = lowerPrice;
    }

    const totalDisplay = document.getElementById('archTotalPriceDisplay');
    if (totalDisplay) {
        totalDisplay.textContent = numberFormat(total);
    }
}

// Pricing Mode Functions
function switchPricingMode(mode) {
    // Update radio buttons and visual selection
    document.querySelectorAll('.arch-pricing-mode-option').forEach(opt => {
        opt.classList.remove('selected');
        if (opt.dataset.mode === mode) {
            opt.classList.add('selected');
            opt.querySelector('input[type="radio"]').checked = true;
        }
    });

    // Show/hide pricing sections
    document.getElementById('perToothPricing').style.display = mode === 'per_arch' ? 'block' : 'none';
    document.getElementById('totalPricing').style.display = mode === 'total' ? 'block' : 'none';
    document.getElementById('separatePricing').style.display = mode === 'separate' ? 'block' : 'none';

    // Recalculate total
    calculateArchTotal();
}

// Initialize pricing mode event listeners
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.arch-pricing-mode-option').forEach(opt => {
        opt.addEventListener('click', function() {
            switchPricingMode(this.dataset.mode);
        });
    });
});

function calculateFromTotal() {
    const totalPrice = parseFloat(document.getElementById('archTotalAgreedPrice').value) || 0;
    const archCount = selectedArches.length;

    if (archCount > 0) {
        const perArchPrice = totalPrice / archCount;
        document.getElementById('calculatedPerToothPrice').textContent = numberFormat(perArchPrice);
        document.getElementById('hiddenUpperPrice').value = perArchPrice;
        document.getElementById('hiddenLowerPrice').value = perArchPrice;
    }

    // Update total display
    document.getElementById('archTotalPriceDisplay').textContent = numberFormat(totalPrice);
}

function calculateSeparateTotal() {
    const upperPrice = parseFloat(document.getElementById('upperArchPrice').value) || 0;
    const lowerPrice = parseFloat(document.getElementById('lowerArchPrice').value) || 0;

    document.getElementById('hiddenUpperPrice').value = upperPrice;
    document.getElementById('hiddenLowerPrice').value = lowerPrice;

    // Calculate total based on selected arches
    let total = 0;
    if (selectedArches.includes('upper') && selectedArches.includes('lower')) {
        total = upperPrice + lowerPrice;
    } else if (selectedArches.includes('upper')) {
        total = upperPrice;
    } else if (selectedArches.includes('lower')) {
        total = lowerPrice;
    }

    document.getElementById('archTotalPriceDisplay').textContent = numberFormat(total);
}

function editTreatment(t) {
    document.getElementById('edit_treatment_id').value = t.id;
    document.getElementById('edit_treatment_name').value = t.treatment_name;
    document.getElementById('edit_quantity').value = t.quantity;
    document.getElementById('edit_unit_price').value = t.unit_price;
    document.getElementById('edit_discount').value = t.discount || 0;
    document.getElementById('edit_notes').value = t.notes || '';
    openDialog('editTreatmentDialog');
}

// Helper functions
function numberFormat(num) {
    return new Intl.NumberFormat('en-US').format(num || 0);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close panel on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeToothDetail();
    }
});

// Highlight teeth with treatments on load
document.addEventListener('DOMContentLoaded', function() {
    // Add animation to teeth with treatments
    document.querySelectorAll('.tooth.filling, .tooth.crown, .tooth.root_canal, .tooth.extraction, .tooth.implant, .tooth.cleaning, .tooth.treated').forEach(tooth => {
        tooth.style.animation = 'none';
        setTimeout(() => {
            tooth.style.animation = '';
        }, 100);
    });
});

// ===== Visit tabs + per-tab quick entry =====
// Each tab is one receipt. selectedTeeth is shared with the tooth-picker modal
// (openToothSelector) and always belongs to the ACTIVE tab's add row.
let selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
let currentQuadrantForSelection = null;
let activeVisitTab = <?= (int)$activeInvoiceId ?>;

function switchVisitTab(invoiceId) {
    if (window.invoiceCorrectionOpen && Number(invoiceId) !== Number(activeVisitTab)) {
        if (typeof showToast === 'function') showToast('info', 'سەرەتا گۆڕانکارییەکان پاشەکەوت بکە یان هەڵبوەشێنەوە');
        return;
    }
    activeVisitTab = parseInt(invoiceId, 10);
    document.querySelectorAll('[data-invoice-pane]').forEach(p => {
        p.hidden = parseInt(p.dataset.invoicePane, 10) !== activeVisitTab;
    });
    document.querySelectorAll('[data-invoice-tab]').forEach(t => {
        t.classList.toggle('is-active', parseInt(t.dataset.invoiceTab, 10) === activeVisitTab);
    });
    // Teeth picked for another tab must not leak into this one
    rtClear(activeVisitTab, true);
    // Keep the URL shareable/reloadable on the same tab
    try {
        const u = new URL(window.location.href);
        u.searchParams.set('tab', 'treatments');
        u.searchParams.set('inv', activeVisitTab);
        history.replaceState(null, '', u.toString());
    } catch (e) { /* older browsers — cosmetic only */ }
    // Pull this receipt's newest server-rendered rows immediately. The
    // revision may match the chart already, while this particular pane was
    // hidden and has not been refreshed yet.
    refreshLiveCase(true);
}

// The add row of a given tab (null when the tab is already sent)
function rtRow(tid) {
    return document.querySelector('.rt-add-row[data-add-for="' + (tid || activeVisitTab) + '"]');
}

// Palmer label + quadrant → FDI, so a typed "5,7" in the UR box becomes real
// teeth. Built from the same maps the chart uses, so it covers A–E as well.
const PALMER_TO_FDI = (function () {
    const map = { UR: {}, UL: {}, LR: {}, LL: {} };
    Object.keys(palmerNumbers).forEach(fdi => {
        const q = fdiQuadrant(parseInt(fdi, 10));
        if (map[q]) map[q][String(palmerNumbers[fdi]).toUpperCase()] = parseInt(fdi, 10);
    });
    return map;
})();

function rtUpdatePrice(tid) {
    const row = rtRow(tid); if (!row) return;
    const id = parseInt((row.querySelector('.rt-combo-id') || {}).value, 10);
    const t = treatmentCatalog.find(x => x.id === id);
    row.querySelector('.rt-in-price').value = t ? t.price : 0;
    rtApplyToothRule(tid, t || null);
    rtCalcTotal(tid);
}

function rtApplyToothRule(tid, treatment) {
    const row = rtRow(tid); if (!row) return true;
    // Tooth numbers are optional for every treatment, so the boxes stay open.
    const requiresTooth = true;
    const wrap = row.querySelector('.rt-teeth-wrap');
    if (wrap) wrap.classList.toggle('is-not-required', !requiresTooth);
    row.querySelectorAll('.rt-tooth-in').forEach(function (input) {
        input.disabled = !requiresTooth;
        if (!requiresTooth) { input.value = ''; input.classList.remove('is-bad'); }
    });
    const picker = row.querySelector('.rt-teeth-btn');
    if (picker) picker.disabled = !requiresTooth;
    const qty = row.querySelector('.rt-in-qty');
    if (qty) {
        if (!requiresTooth) {
            qty.dataset.serviceRule = '1';
            qty.dataset.manual = '1';
            qty.classList.remove('is-auto');
            qty.title = 'ژمارەی ئەم کارە';
        } else if (qty.dataset.serviceRule === '1') {
            delete qty.dataset.serviceRule;
            delete qty.dataset.manual;
            qty.classList.add('is-auto');
            qty.title = 'خۆکار لە ژمارەی ددانەکانەوە — دەتوانیت بیگۆڕیت';
        }
    }
    if (!requiresTooth) selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
    return requiresTooth;
}

function rtCalcTotal(tid) {
    const row = rtRow(tid); if (!row) return;
    const q = parseFloat(row.querySelector('.rt-in-qty').value) || 0;
    const p = parseFloat(row.querySelector('.rt-in-price').value) || 0;
    row.querySelector('.rt-in-total').textContent = numberFormat(q * p);
}

// The doctor typed their own quantity — stop overwriting it from the teeth.
function rtQtyTouched(tid) {
    const row = rtRow(tid); if (!row) return;
    const qty = row.querySelector('.rt-in-qty');
    qty.dataset.manual = '1';
    qty.classList.remove('is-auto');
    rtCalcTotal(tid);
}

// Every tooth is ONE character in Palmer notation — 1-8 permanent, A-E primary —
// so each character the doctor types is a whole tooth. Insert the separators as
// they type («678» reads back as 6,7,8) instead of making them reach for the
// comma key mid-entry. Arabic-Indic digits fold to ASCII so a Kurdish keyboard
// works the same. Characters that are not teeth are kept as their own token so
// rtReadTeeth() still paints the box red.
const RT_DIGIT_MAP = {
    '\u0660': '0', '\u06F0': '0', '\u0661': '1', '\u06F1': '1', '\u0662': '2', '\u06F2': '2',
    '\u0663': '3', '\u06F3': '3', '\u0664': '4', '\u06F4': '4', '\u0665': '5', '\u06F5': '5',
    '\u0666': '6', '\u06F6': '6', '\u0667': '7', '\u06F7': '7', '\u0668': '8', '\u06F8': '8',
    '\u0669': '9', '\u06F9': '9'
};

function rtToothTokens(text) {
    return String(text || '')
        .replace(/[\u0660-\u0669\u06F0-\u06F9]/g, function (ch) { return RT_DIGIT_MAP[ch] || ch; })
        .toUpperCase()
        .replace(/[^0-9A-Z]/g, '');
}

function rtFormatToothInput(inp) {
    const raw = String(inp.value || '');
    const caret = inp.selectionStart === null ? raw.length : inp.selectionStart;
    const out = rtToothTokens(raw).split('').join(',');
    if (out === raw) return;
    const atEnd = caret >= raw.length;
    const before = rtToothTokens(raw.slice(0, caret)).length;
    inp.value = out;
    // Each tooth occupies two characters ("6,") so the nth token ends at 2n-1.
    const pos = atEnd ? out.length : (before === 0 ? 0 : Math.min(before * 2 - 1, out.length));
    try { inp.setSelectionRange(pos, pos); } catch (e) { /* detached input */ }
}

// Read the four tooth boxes into selectedTeeth. "5,7" / "5 7" / "5-7" all work;
// anything that is not a tooth of that quadrant marks the box red.
function rtReadTeeth(tid) {
    const row = rtRow(tid); if (!row) return 0;
    selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
    let count = 0;
    row.querySelectorAll('.rt-tooth-in').forEach(inp => {
        const q = inp.dataset.q;
        let bad = false;
        String(inp.value || '').split(/[^0-9A-Za-z]+/).forEach(tok => {
            if (!tok) return;
            const fdi = PALMER_TO_FDI[q][tok.toUpperCase()];
            if (fdi && selectedTeeth[q].indexOf(fdi) === -1) {
                selectedTeeth[q].push(fdi);
                count++;
            } else if (!fdi) {
                bad = true;
            }
        });
        inp.classList.toggle('is-bad', bad);
    });
    return count;
}

// Teeth changed → quantity follows the count (one row per tooth), unless the
// doctor typed a quantity of their own.
function rtSyncQty(tid) {
    const row = rtRow(tid); if (!row) return;
    const count = rtReadTeeth(tid);
    const qty = row.querySelector('.rt-in-qty');
    if (qty.dataset.manual !== '1') {
        qty.value = count > 0 ? count : 1;
        qty.classList.add('is-auto');
    }
    rtCalcTotal(tid);
}

// Clicking the chart button opens the visual picker for that quadrant.
function openVisitToothPicker(tid, quadrant) {
    if (tid && parseInt(tid, 10) !== activeVisitTab) switchVisitTab(tid);
    rtReadTeeth(tid);   // keep what is already typed
    openToothSelector(quadrant || 'UR');
}

// Renders the picked teeth back into the active tab's boxes (called by the picker)
function updateQuadrantDisplays() {
    const row = rtRow(activeVisitTab);
    if (!row) return;
    row.querySelectorAll('.rt-tooth-in').forEach(inp => {
        const q = inp.dataset.q;
        inp.value = selectedTeeth[q].length
            ? selectedTeeth[q].map(t => palmerNumbers[t] || (t % 10)).join(',')
            : '';
        inp.classList.remove('is-bad');
    });
    const qty = row.querySelector('.rt-in-qty');
    if (qty && qty.dataset.manual !== '1') {
        const n = ['UR', 'UL', 'LR', 'LL'].reduce((s, q) => s + selectedTeeth[q].length, 0);
        qty.value = n > 0 ? n : 1;
        qty.classList.add('is-auto');
    }
    rtCalcTotal(activeVisitTab);
}

function rtClear(tid, keepFocus) {
    selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
    const row = rtRow(tid); if (!row) return;
    const sel = row.querySelector('.rt-combo-input');
    if (sel) { sel.value = ''; sel.classList.remove('is-chosen'); }
    const idField = row.querySelector('.rt-combo-id');
    if (idField) idField.value = '';
    rtApplyToothRule(tid, null);
    rtComboClose();
    row.querySelectorAll('.rt-tooth-in').forEach(i => { i.value = ''; i.classList.remove('is-bad'); });
    const qty = row.querySelector('.rt-in-qty');
    qty.value = '1';
    delete qty.dataset.manual;
    qty.classList.add('is-auto');
    row.querySelector('.rt-in-price').value = '0';
    row.querySelector('.rt-in-total').textContent = '0';
    if (!keepFocus && sel) {
        sel.focus();
        // focus() fires the combo's focus handler, which would re-open the menu
        // right after Escape closed it — shut it again.
        rtComboClose();
    }
}

let rtSaveBusy = false;
function rtSave(tid) {
    if (rtSaveBusy) return;
    const invoiceId = parseInt(tid, 10) || activeVisitTab;
    const row = rtRow(invoiceId);
    if (!row) return;

    const search = row.querySelector('.rt-combo-input');
    const treatmentId = (row.querySelector('.rt-combo-id') || {}).value || '';
    const chosen = treatmentCatalog.find(x => String(x.id) === String(treatmentId));
    const treatmentName = chosen ? chosen.name : '';
    if (!treatmentId) { toast('error', 'تکایە چارەسەرێک هەڵبژێرە'); if (search) search.focus(); return; }

    // Re-read the boxes so typed teeth count even if the picker was never opened.
    // Teeth are optional: with teeth → one row per tooth, empty → one job row.
    rtReadTeeth(invoiceId);
    const bad = row.querySelector('.rt-tooth-in.is-bad');
    if (bad) {
        toast('error', 'ژمارەی ددان نادروستە');
        bad.focus();
        return;
    }
    const teeth = [].concat(selectedTeeth.UR, selectedTeeth.UL, selectedTeeth.LR, selectedTeeth.LL);
    const requiresTooth = teeth.length > 0;

    rtSaveBusy = true;
    const btn = row.querySelector('.rt-save-btn');
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

    const fd = new FormData();
    fd.set('csrf_token', CSRF_TOKEN);
    fd.set('action', requiresTooth ? 'add_treatment_bulk' : 'add_treatment');
    fd.set('ajax', '1');
    fd.set('invoice_id', invoiceId);          // file the work onto THIS receipt
    fd.set('treatment_id', treatmentId);
    fd.set('treatment_name', treatmentName);
    fd.set('quantity', row.querySelector('.rt-in-qty').value);
    fd.set('unit_price', row.querySelector('.rt-in-price').value);
    if (requiresTooth) fd.set('tooth_numbers', JSON.stringify(teeth));
    else fd.set('tooth_number', '');

    fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { toast('error', d.message || 'هەڵەیەک ڕوویدا'); return; }
            toast('success', requiresTooth
                ? ((d.added || teeth.length) + ' چارەسەر زیادکرا')
                : 'کارەکە بێ ژمارەی ددان زیادکرا');
            // Swap only this receipt's pane — no page reload.
            applyInvoiceSnapshot(d, invoiceId);
        })
        .catch(() => toast('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن، تکایە دووبارە هەوڵبدەرەوە'))
        .finally(() => {
            rtSaveBusy = false;
            if (btn) { btn.disabled = false; btn.innerHTML = orig; }
        });
}

// Delete a whole grouped row without leaving the page.
function rtDeleteGroup(invoiceId, treatmentIds, treatmentName) {
    if (!confirm('سڕینەوەی هەموو چارەسەرەکانی ' + treatmentName + '؟')) return;
    const fd = new FormData();
    fd.set('csrf_token', CSRF_TOKEN);
    fd.set('action', 'delete_grouped_treatments');
    fd.set('ajax', '1');
    (treatmentIds || []).forEach(id => fd.append('treatment_ids[]', id));

    fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { toast('error', d.message || 'هەڵەیەک ڕوویدا'); return; }
            toast('success', (d.deleted || treatmentIds.length) + ' چارەسەر سڕایەوە');
            applyInvoiceSnapshot(d, invoiceId);
        })
        .catch(() => toast('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن'));
}

/**
 * Apply an AJAX result in place: swap the affected receipt's pane with the
 * server-rendered HTML, refresh its tab button + sidebar row, and let the chart
 * and case totals update. Nothing reloads.
 */
function applyInvoiceSnapshot(d, invoiceId) {
    if (window.invoiceCorrectionOpen) return;
    if (d.revision) currentLiveRevision = d.revision;
    updateInvoiceCount(d.invoice_count);
    // Chart + case totals (never touches the treatments table)
    if (d.tooth_map) toothTreatmentMap = d.tooth_map;
    if (Array.isArray(d.service_treatments)) serviceTreatmentRows = d.service_treatments;
    if (d.tooth_map || Array.isArray(d.service_treatments)) renderSmartChart();
    if (d.case_totals) updateCaseTotalsUI(d.case_totals);

    // Fresh tab strip: the receipt this mutation landed on may have been created
    // by the mutation itself (first work of the day), so its button does not
    // exist on the page yet.
    const bar = document.querySelector('.vt-bar');
    if (d.tabs_html !== undefined && bar) {
        bar.innerHTML = d.tabs_html;
        if (bar.querySelector('[data-invoice-tab]')) {
            const placeholder = document.querySelector('#treatmentTableView .vt-empty');
            if (placeholder) placeholder.remove();
        }
    }

    if (d.pane_html && invoiceId) {
        let pane = document.querySelector('[data-invoice-pane="' + invoiceId + '"]');
        let isNewPane = false;
        if (!pane) {
            // Brand-new receipt: build its pane rather than leaving the doctor
            // on the "no receipt yet" placeholder until a manual reload.
            const host = document.getElementById('treatmentTableView');
            if (host) {
                const holder = document.createElement('div');
                holder.innerHTML = d.pane_html;
                pane = holder.firstElementChild;
                if (pane) {
                    host.insertBefore(pane, host.querySelector('.vt-loose'));
                    wireAddRowPickers(pane);
                    isNewPane = true;
                }
            }
        } else {
            const wasHidden = pane.hidden;
            pane.outerHTML = d.pane_html;
            pane = document.querySelector('[data-invoice-pane="' + invoiceId + '"]');
            if (pane) {
                pane.hidden = wasHidden;
                wireAddRowPickers(pane);
            }
        }
        // Work always lands on the receipt the server chose — show it, so the
        // doctor sees where it went.
        if (isNewPane) {
            switchVisitTab(invoiceId);
        }
    }
    if (d.invoice) {
        const total = Number(d.invoice.total_amount) || 0;
        const fmt = new Intl.NumberFormat('en-US').format(total);
        // Sidebar «وەسڵەکان» row for this invoice
        document.querySelectorAll('[data-invoice-amount="' + invoiceId + '"]').forEach(el => { el.textContent = fmt; });
        document.querySelectorAll('[data-invoice-count="' + invoiceId + '"]').forEach(el => {
            el.textContent = (d.invoice.treatment_count || 0) + ' چارەسەر';
        });
    }
}

// (Re)bind the quick-entry row inside a freshly injected pane: typed teeth keep
// the quantity in sync, and Enter walks the row instead of doing nothing.
function wireAddRowPickers(root) {
    (root || document).querySelectorAll('.rt-add-row').forEach(row => {
        const tid = row.dataset.addFor;

        // ── treatment combo ──
        const combo = row.querySelector('.rt-combo-input');
        if (combo) {
            combo.addEventListener('input', function () {
                // Typing invalidates the previous pick until something is chosen
                row.querySelector('.rt-combo-id').value = '';
                this.classList.remove('is-chosen');
                rtApplyToothRule(tid, null);
                rtComboOpen(this, this.value);
            });
            combo.addEventListener('focus', function () { rtComboOpen(this, this.value); });
            combo.addEventListener('blur', function () {
                // Delay so a click on an item still registers
                setTimeout(function () { if (rtComboOwner === combo) rtComboClose(); }, 150);
            });
            combo.addEventListener('keydown', function (e) {
                const open = rtComboMenu && !rtComboMenu.hidden && rtComboOwner === this;
                const items = open ? rtComboMenu.querySelectorAll('.rt-combo-item') : [];
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!open) { rtComboOpen(this, this.value); return; }
                    if (!items.length) return;
                    rtComboIndex = (rtComboIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    rtComboHighlight();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (open && items.length && rtComboIndex >= 0) {
                        rtComboPick(this, items[rtComboIndex].dataset.id);
                    } else {
                        rtFocusNext(row, this);
                    }
                } else if (e.key === 'Escape') {
                    if (open) { e.preventDefault(); rtComboClose(); }
                }
            });
            const caret = row.querySelector('.rt-combo-caret');
            if (caret) {
                caret.addEventListener('mousedown', function (e) { e.preventDefault(); });
                caret.addEventListener('click', function () {
                    if (rtComboMenu && !rtComboMenu.hidden && rtComboOwner === combo) { rtComboClose(); return; }
                    combo.focus();
                    rtComboOpen(combo, '');   // caret always shows the whole list
                });
            }
        }

        row.querySelectorAll('.rt-tooth-in').forEach(inp => {
            inp.addEventListener('input', function () { rtFormatToothInput(this); rtSyncQty(tid); });
            // Typing a comma is how you separate teeth; keep the caret moving
            // forward with Enter rather than submitting from inside a box.
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); rtFocusNext(row, this); }
            });
        });

        // Enter anywhere in the row: move on, and save from the last field.
        row.querySelectorAll('.rt-field').forEach(el => {
            el.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (this.classList.contains('rt-in-price')) rtSave(tid);
                else rtFocusNext(row, this);
            });
        });
    });
}

// ── Treatment combo: search field + dropdown list ──────────────────
// One shared menu element, moved to whichever row is open. It is position:fixed
// because .table-container scrolls horizontally and would clip an absolutely
// positioned menu.
let rtComboMenu = null;
let rtComboOwner = null;   // the input the menu currently belongs to
let rtComboIndex = -1;     // highlighted item

function rtComboEnsureMenu() {
    if (!rtComboMenu) {
        rtComboMenu = document.createElement('ul');
        rtComboMenu.className = 'rt-combo-list';
        rtComboMenu.hidden = true;
        document.body.appendChild(rtComboMenu);
    }
    return rtComboMenu;
}

function rtComboClose() {
    if (rtComboMenu) rtComboMenu.hidden = true;
    rtComboOwner = null;
    rtComboIndex = -1;
}

// Kurdish/Arabic letters have several spellings for the same sound, so a
// literal indexOf misses obvious matches (کرۆن vs كرون, ڕیشە vs ریشه). Fold
// them onto one form, plus Arabic/Persian digits, before comparing. Every
// entry maps ONE character to ONE character, which keeps the highlighter's
// offsets lined up with the original name.
const RT_FOLD = (function () {
    const map = {};
    const pairs = [
        ['أإآاٱ', 'ا'], ['يیۍئ', 'ی'], ['كک', 'ک'], ['گڰ', 'گ'],
        ['ۆۊوؤ', 'و'], ['هھۀەةۃ', 'ه'], ['ڕ', 'ر'], ['ڵ', 'ل'],
        ['ژ', 'ژ'], ['ڤڥ', 'ڤ'], ['ىي', 'ی'], ['ﻻ', 'ل']
    ];
    pairs.forEach(function (p) {
        for (const ch of p[0]) map[ch] = p[1];
    });
    '٠١٢٣٤٥٦٧٨٩'.split('').forEach(function (d, i) { map[d] = String(i); });
    '۰۱۲۳۴۵۶۷۸۹'.split('').forEach(function (d, i) { map[d] = String(i); });
    return map;
})();

function rtFoldChar(ch) {
    const lower = ch.toLowerCase();
    return RT_FOLD[lower] || lower;
}

// Search key: folded, stripped of harakat/tatweel, whitespace collapsed.
function rtComboNorm(s) {
    let out = '';
    for (const ch of String(s == null ? '' : s)) {
        if (/[\u064B-\u065F\u0670\u0640]/.test(ch)) continue;   // harakat, superscript alef, tatweel
        out += rtFoldChar(ch);
    }
    return out.replace(/\s+/g, ' ').trim();
}

// Cache the folded name/category on each catalog entry, once.
function rtComboIndexCatalog() {
    for (let i = 0; i < treatmentCatalog.length; i++) {
        const t = treatmentCatalog[i];
        if (t._n === undefined) {
            t._n = rtComboNorm(t.name);
            t._c = rtComboNorm(t.category || '');
        }
    }
}

// ── Recently used ─────────────────────────────────────────────────
// A clinic reaches for the same handful of treatments all day. Putting those
// at the top of an empty box is what removes the scrolling: the usual work is
// one keystroke away, and the full catalogue is still right underneath.
const RT_RECENT_KEY = 'lezan.treatmentPicker.recent';
const RT_RECENT_MAX = 8;

function rtRecentIds() {
    try {
        const parsed = JSON.parse(localStorage.getItem(RT_RECENT_KEY) || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];   // private window / storage blocked — the picker still works
    }
}

function rtRecentPush(id) {
    try {
        const ids = rtRecentIds().filter(function (x) { return String(x) !== String(id); });
        ids.unshift(Number(id));
        localStorage.setItem(RT_RECENT_KEY, JSON.stringify(ids.slice(0, RT_RECENT_MAX)));
    } catch (e) { /* nothing to do — recency is a convenience, not state */ }
}

// Score one treatment against every typed term. Terms may come in any order
// and match any word, so "کرۆن زیرکۆنیا" and "زیرکۆنیا کرۆن" both land. A term
// that matches nothing at all disqualifies the row.
function rtComboRank(t, terms) {
    let score = 0;
    for (let i = 0; i < terms.length; i++) {
        const term = terms[i];
        const at = t._n.indexOf(term);
        if (at === 0) score += 100;                          // name starts with it
        else if (at > 0 && t._n[at - 1] === ' ') score += 60; // a word starts with it
        else if (at > 0) score += 30;                        // somewhere inside
        else if (t._c.indexOf(term) !== -1) score += 10;     // only the category
        else return -1;
    }
    // Among equals, the shorter (more exact) name wins.
    return score - Math.min(20, t._n.length / 4);
}

function rtComboMatches(query) {
    rtComboIndexCatalog();
    const terms = rtComboNorm(query).split(' ').filter(Boolean);

    if (!terms.length) {
        const byId = {};
        treatmentCatalog.forEach(function (t) { byId[t.id] = t; });
        const seen = {};
        const head = [];
        rtRecentIds().forEach(function (id) {
            const t = byId[id];
            if (t && !seen[t.id]) { seen[t.id] = 1; head.push({ t: t, recent: true }); }
        });
        const rest = treatmentCatalog
            .filter(function (t) { return !seen[t.id]; })
            .map(function (t) { return { t: t, recent: false }; });
        return head.concat(rest);
    }

    const scored = [];
    treatmentCatalog.forEach(function (t) {
        const s = rtComboRank(t, terms);
        if (s >= 0) scored.push({ t: t, s: s });
    });
    scored.sort(function (a, b) { return b.s - a.s || a.t.name.localeCompare(b.t.name); });
    return scored.map(function (x) { return { t: x.t, recent: false }; });
}

// Wrap every matched stretch in <mark>. Folding is 1:1 per character, so the
// offsets found in the folded string point at the same characters here.
function rtComboLabel(name, terms) {
    const chars = Array.from(String(name));
    if (!terms.length) return escapeHtml(chars.join(''));
    const folded = chars.map(rtFoldChar).join('');
    const hit = new Array(chars.length).fill(false);
    terms.forEach(function (term) {
        let from = 0, at;
        while ((at = folded.indexOf(term, from)) !== -1) {
            for (let i = at; i < at + term.length && i < hit.length; i++) hit[i] = true;
            from = at + term.length;
        }
    });
    let html = '', open = false;
    for (let i = 0; i < chars.length; i++) {
        if (hit[i] && !open) { html += '<mark>'; open = true; }
        else if (!hit[i] && open) { html += '</mark>'; open = false; }
        html += escapeHtml(chars[i]);
    }
    return open ? html + '</mark>' : html;
}

function rtComboOpen(input, query) {
    const menu = rtComboEnsureMenu();
    rtComboOwner = input;
    const rows = rtComboMatches(query);
    const terms = rtComboNorm(query).split(' ').filter(Boolean);

    if (!rows.length) {
        menu.innerHTML = '<li class="rt-combo-empty">هیچ چارەسەرێک نەدۆزرایەوە</li>';
    } else {
        let html = '';
        let groupedRecent = false, groupedAll = false;
        rows.forEach(function (r, i) {
            // Group captions only when the empty box is showing recents first.
            if (r.recent && !groupedRecent) {
                html += '<li class="rt-combo-group"><i class="fas fa-clock-rotate-left"></i> دواجار بەکارهاتوو</li>';
                groupedRecent = true;
            } else if (!r.recent && groupedRecent && !groupedAll) {
                html += '<li class="rt-combo-group">هەموو چارەسەرەکان</li>';
                groupedAll = true;
            }
            const t = r.t;
            html += '<li class="rt-combo-item' + (r.recent ? ' is-recent' : '') + '"' +
                    ' data-id="' + t.id + '" data-i="' + i + '">' +
                    '<span>' + rtComboLabel(t.name, terms) +
                    '</span>' +
                    '<span class="rt-combo-price">' + numberFormat(t.price) + '</span></li>';
        });
        menu.innerHTML = html;
    }

    // Place it under the field, flipping above when there is no room below
    const r = input.getBoundingClientRect();
    menu.hidden = false;
    menu.scrollTop = 0;
    menu.style.width = Math.max(r.width, 280) + 'px';
    menu.style.left = Math.max(6, Math.min(r.left, window.innerWidth - menu.offsetWidth - 6)) + 'px';
    const below = window.innerHeight - r.bottom;
    menu.style.top = (below < 180 && r.top > below)
        ? Math.max(6, r.top - menu.offsetHeight - 4) + 'px'
        : (r.bottom + 4) + 'px';

    rtComboIndex = rows.length ? 0 : -1;
    rtComboHighlight();
}

function rtComboHighlight() {
    if (!rtComboMenu) return;
    rtComboMenu.querySelectorAll('.rt-combo-item').forEach((el, i) => {
        const on = i === rtComboIndex;
        el.classList.toggle('is-active', on);
        if (on) el.scrollIntoView({ block: 'nearest' });
    });
}

function rtComboPick(input, id) {
    const t = treatmentCatalog.find(x => String(x.id) === String(id));
    if (!t) return;
    const row = input.closest('.rt-add-row');
    input.value = t.name;
    input.classList.add('is-chosen');
    row.querySelector('.rt-combo-id').value = t.id;
    rtRecentPush(t.id);
    rtComboClose();
    rtUpdatePrice(row.dataset.addFor);
    // Tooth-based work continues into the cross; service work goes straight
    // to quantity because it intentionally has no chart position.
    rtFocusNext(row, input);
}

// Menu clicks and dismissal are bound once, on the shared menu.
document.addEventListener('DOMContentLoaded', function () {
    const menu = rtComboEnsureMenu();
    menu.addEventListener('mousedown', function (e) { e.preventDefault(); });  // keep focus
    menu.addEventListener('click', function (e) {
        const item = e.target.closest('.rt-combo-item');
        if (item && rtComboOwner) rtComboPick(rtComboOwner, item.dataset.id);
    });
    // Hovering moves the selection, so Enter always takes the row under the
    // pointer instead of a stale keyboard position.
    menu.addEventListener('mousemove', function (e) {
        const item = e.target.closest('.rt-combo-item');
        if (!item) return;
        const all = menu.querySelectorAll('.rt-combo-item');
        for (let i = 0; i < all.length; i++) {
            if (all[i] === item) {
                if (rtComboIndex !== i) {
                    rtComboIndex = i;
                    all.forEach((el, j) => el.classList.toggle('is-active', j === i));
                }
                return;
            }
        }
    });
    document.addEventListener('click', function (e) {
        if (!rtComboOwner) return;
        if (e.target.closest('.rt-combo') || e.target.closest('.rt-combo-list')) return;
        rtComboClose();
    });
    // A fixed menu does not follow its field, so close it when things move —
    // but scrolling INSIDE the list is how the list is read. Closing on that
    // made the menu snap shut under the mouse wheel.
    window.addEventListener('resize', rtComboClose);
    document.addEventListener('scroll', function (e) {
        if (!rtComboOwner) return;
        const target = e.target;
        if (target === rtComboMenu) return;
        if (target && target.nodeType === 1 && target.closest && target.closest('.rt-combo-list')) return;
        rtComboClose();
    }, true);
});

// Walk to the next focusable field of the quick-entry row.
function rtFocusNext(row, current) {
    const order = [].slice.call(row.querySelectorAll('.rt-combo-input, .rt-tooth-in, .rt-in-qty, .rt-in-price'));
    const i = order.indexOf(current);
    const next = order[i + 1];
    if (next) { next.focus(); if (next.select) next.select(); }
}

// ── New visit tab ──
function openNewVisitTab() {
    const input = document.getElementById('newVisitDate');
    if (input) input.value = new Date().toISOString().slice(0, 10);
    openDialog('newVisitTabDialog');
}

// Whose work a receipt is — the header's دکتۆر box (admin/accountant only).
function setInvoiceDoctor(invoiceId, doctorId, select) {
    if (!doctorId) return;
    const fd = new FormData();
    fd.set('csrf_token', CSRF_TOKEN);
    fd.set('action', 'set_invoice_doctor');
    fd.set('ajax', '1');
    fd.set('invoice_id', invoiceId);
    fd.set('doctor_id', doctorId);
    if (select) select.disabled = true;
    fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { toast('error', d.message || 'هەڵەیەک ڕوویدا'); if (select) { select.disabled = false; select.value = select.dataset.current || ''; } return; }
            toast('success', 'دکتۆری وەسڵ گۆڕدرا');
            applyInvoiceSnapshot(d, invoiceId);
        })
        .catch(() => { toast('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن'); if (select) select.disabled = false; });
}

// Per-receipt داشکاندن + وەرگیراو are edited in place by
// assets/js/invoice-pane.js, shared with the accountant screen. After a
// discount save this page swaps the pane instead of reloading:
window.onInvoicePaneSaved = applyInvoiceSnapshot;
window.onInvoicePaidSaved = applyInvoiceSnapshot;

// ── Send ONE tab to the accountant ──
let sendInvoiceFinance = { gross: 0, net: 0, approved: 0, declared: 0, prior: 0 };
const sendHasExistingInstallment = <?= json_encode((bool)($installmentPlan || (($pendingOrRejectedPlan['status'] ?? '') === 'pending'))) ?>;

function openSendInvoiceDialog(invoiceId, invoiceNo, treatmentCount, gross, discountMode, discountValue, approved, declared, prior) {
    if (!treatmentCount) { toast('error', 'ئەم وەسڵە بەتاڵە — سەرەتا چارەسەر زیاد بکە'); return; }
    document.getElementById('sendInvoiceId').value = invoiceId;
    document.getElementById('sendInvoiceNo').textContent = invoiceNo;
    document.getElementById('sendInvoiceNotes').value = '';
    sendInvoiceFinance = {
        gross: Math.max(0, Number(gross) || 0),
        net: Math.max(0, Number(gross) || 0),
        approved: Math.max(0, Number(approved) || 0),
        declared: Math.max(0, Number(declared) || 0),
        // دراوی پێشوو (historical cases) — read-only here, typed on the receipt
        prior: Math.max(0, Number(prior) || 0)
    };
    const priorRow = document.getElementById('sendPriorRow');
    if (priorRow) priorRow.style.display = sendInvoiceFinance.prior > 0.009 ? '' : 'none';
    const priorValueEl = document.getElementById('sendInvoicePrior');
    if (priorValueEl) priorValueEl.textContent = numberFormat(sendInvoiceFinance.prior);
    document.getElementById('sendDiscountMode').value = discountMode === 'percent' ? 'percent' : 'amount';
    syncSendDiscountSwitch();
    document.getElementById('sendDiscountValue').value = Math.max(0, Number(discountValue) || 0);
    document.getElementById('sendReceivedAmount').value = Math.max(sendInvoiceFinance.approved, sendInvoiceFinance.declared);
    updateSendMoneyPreview();
    resetSendIntentRadios();
    openDialog('sendInvoiceDialog');
}

function syncSendDiscountSwitch() {
    const mode = (document.getElementById('sendDiscountMode') || {}).value === 'percent' ? 'percent' : 'amount';
    document.querySelectorAll('#sendInvoiceDialog [data-discount-mode]').forEach(function (button) {
        const active = button.dataset.discountMode === mode;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

function setSendDiscountMode(mode) {
    const input = document.getElementById('sendDiscountMode');
    if (!input) return;
    input.value = mode === 'percent' ? 'percent' : 'amount';
    syncSendDiscountSwitch();
    updateSendMoneyPreview();
    const value = document.getElementById('sendDiscountValue');
    if (value) value.focus();
}

function updateSendMoneyPreview() {
    const mode = (document.getElementById('sendDiscountMode') || {}).value === 'percent' ? 'percent' : 'amount';
    const rawDiscount = Math.max(0, Number((document.getElementById('sendDiscountValue') || {}).value) || 0);
    const discount = mode === 'percent'
        ? Math.min(sendInvoiceFinance.gross, sendInvoiceFinance.gross * Math.min(100, rawDiscount) / 100)
        : Math.min(sendInvoiceFinance.gross, rawDiscount);
    sendInvoiceFinance.net = Math.max(0, sendInvoiceFinance.gross - discount);
    const receivedInput = document.getElementById('sendReceivedAmount');
    const received = Math.max(0, Number((receivedInput || {}).value) || 0);
    if (receivedInput) receivedInput.max = String(Math.max(0, sendInvoiceFinance.net - sendInvoiceFinance.prior));
    document.getElementById('sendInvoiceGross').textContent = numberFormat(sendInvoiceFinance.gross);
    document.getElementById('sendInvoiceNet').textContent = numberFormat(sendInvoiceFinance.net);
    document.getElementById('sendInvoiceRemaining').textContent = numberFormat(Math.max(0, sendInvoiceFinance.net - received - sendInvoiceFinance.prior));
    updateSendInstallmentPreview();
}

// نەقد/قەرز/قیست on the send-invoice dialog. Debt reveals its promise;
// installment exposes the proposed terms right here unless this case already
// has a plan, in which case the invoice simply joins that existing plan flow.
function resetSendIntentRadios() {
    const btns = document.getElementById('sendIntentBtns');
    if (!btns) return;
    btns.querySelectorAll('input[name="payment_intent"]').forEach(function (r) { r.checked = r.value === 'cash'; });
    btns.querySelectorAll('.si-intent-btn').forEach(function (b) { b.classList.toggle('selected', b.querySelector('input').checked); });
    const group = document.getElementById('sendDebtPromiseGroup');
    if (group) group.style.display = 'none';
    const pd = document.getElementById('sendPromisedDate'); if (pd) pd.value = '';
    const pa = document.getElementById('sendPromisedAmount'); if (pa) pa.value = '';
    const debt = btns.querySelector('input[value="debt"]');
    const hasReceived = Math.max(sendInvoiceFinance.approved, sendInvoiceFinance.declared) > 0.009;
    if (debt) {
        debt.disabled = hasReceived;
        debt.closest('.si-intent-btn').classList.toggle('is-disabled', hasReceived);
    }
    const count = document.getElementById('sendInstallmentCount'); if (count) count.value = '4';
    const start = document.getElementById('sendInstallmentStart'); if (start) start.value = <?= json_encode(date('Y-m-d')) ?>;
    updateSendIntentUI('cash');
}

function updateSendIntentUI(intent) {
    const debtGroup = document.getElementById('sendDebtPromiseGroup');
    const installmentGroup = document.getElementById('sendInstallmentGroup');
    if (debtGroup) debtGroup.style.display = intent === 'debt' ? 'grid' : 'none';
    if (installmentGroup) installmentGroup.style.display = intent === 'installment' && !sendHasExistingInstallment ? 'block' : 'none';
    const received = document.getElementById('sendReceivedAmount');
    if (received) {
        received.disabled = intent === 'debt';
        if (intent === 'debt') received.value = '0';
    }
    const moneyHint = document.getElementById('sendMoneyHint');
    if (moneyHint) moneyHint.textContent = intent === 'installment'
        ? 'بڕی وەرگیراو لە ناو هەمان وەسڵدا پارەی پێشەکییە؛ پلان و وەسڵ بۆ وردبینی ژمێریار دەنێردرێن.'
        : (intent === 'debt'
            ? 'بۆ قەرزی تەواو هیچ بڕێک وەک وەرگیراو تۆمار ناکرێت.'
            : 'دەتوانیت پارەی تەواو یان بەشێک بنووسیت؛ ئەم بڕە لە خودی وەسڵدا دەمێنێتەوە و بۆ پارەدانەکان نانێردرێت.');
    const help = document.getElementById('sendIntentHelp');
    if (help) {
        const messages = {
            cash: 'کۆ، داشکاندن و بڕی وەرگیراو هەموویان لە یەک وەسڵدا بۆ ژمێریار دەنێردرێن.',
            debt: 'هەموو بڕی ماوە وەک قەرز دەچێتە بەشی قەرزەکان.',
            installment: sendHasExistingInstallment
                ? 'ئەم کەیسە پلانی قیستی هەیە؛ وەسڵەکە بە هەمان ڕەوتی قیست دەنێردرێت.'
                : 'بڕی وەرگیراوی ناو وەسڵ دەبێتە پێشەکی؛ ماوەکە بەسەر قیستەکان دابەش دەکرێت.'
        };
        help.textContent = messages[intent] || '';
    }
    updateSendMoneyPreview();
}

function updateSendInstallmentPreview() {
    const preview = document.getElementById('sendInstallmentPreview');
    if (!preview) return;
    const count = Math.max(2, parseInt((document.getElementById('sendInstallmentCount') || {}).value || '4', 10));
    const planTotal = Math.max(0, sendInvoiceFinance.net);
    const entered = Math.max(0, Number((document.getElementById('sendReceivedAmount') || {}).value) || 0);
    const down = Math.min(planTotal, entered + sendInvoiceFinance.prior);
    const remaining = Math.max(0, planTotal - down);
    const each = count > 0 ? Math.ceil(remaining / count) : 0;
    preview.innerHTML = 'کۆی پلان: <strong>' + numberFormat(planTotal) + '</strong> · پێشەکی: <strong>'
        + numberFormat(down) + '</strong> · نزیکەی هەر قیست: <strong>' + numberFormat(each) + '</strong>';
}

document.addEventListener('change', function (e) {
    if (!e.target || e.target.name !== 'payment_intent' || !e.target.closest('#sendIntentBtns')) return;
    document.querySelectorAll('#sendIntentBtns .si-intent-btn').forEach(function (b) {
        b.classList.toggle('selected', b.querySelector('input').checked);
    });
    const group = document.getElementById('sendDebtPromiseGroup');
    if (group) group.style.display = e.target.value === 'debt' ? 'grid' : 'none';
    updateSendIntentUI(e.target.value);
});

document.addEventListener('DOMContentLoaded', function () {
    const count = document.getElementById('sendInstallmentCount');
    if (count) count.addEventListener('input', updateSendInstallmentPreview);
    const received = document.getElementById('sendReceivedAmount');
    const discountValue = document.getElementById('sendDiscountValue');
    if (received) received.addEventListener('input', updateSendMoneyPreview);
    if (discountValue) discountValue.addEventListener('input', updateSendMoneyPreview);
    const form = document.getElementById('sendInvoiceForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (form.dataset.busy === '1') return;
        form.dataset.busy = '1';
        const submit = form.querySelector('button[type="submit"]');
        const original = submit ? submit.innerHTML : '';
        if (submit) { submit.disabled = true; submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ناردن...'; }
        const fd = new FormData(form);
        fd.set('ajax', '1');
        fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                if (!d.success) { toast('error', d.message || 'ناردن سەرنەکەوت'); return; }
                applyCaseSnapshot(d);
                closeDialog('sendInvoiceDialog');
                toast('success', d.installment_plan_created
                    ? 'وەسڵ و پلانی قیست نێردران بۆ ژمێریار'
                    : (d.payment_intent === 'installment'
                        ? 'وەسڵەکە بە پلانی قیستەوە نێردرا بۆ ژمێریار'
                        : 'وەسڵەکە نێردرا بۆ ژمێریار'));
            })
            .catch(() => toast('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن'))
            .finally(() => {
                form.dataset.busy = '';
                if (submit) { submit.disabled = false; submit.innerHTML = original; }
            });
    });
});

// Keyboard: Ctrl+Enter / Enter saves the active tab's add row, Escape clears it
let inlineKeyHandler = null;
function addInlineKeyboardShortcuts() {
    removeInlineKeyboardShortcuts();
    inlineKeyHandler = function (e) {
        const row = rtRow(activeVisitTab);
        if (!row || !row.contains(document.activeElement)) return;
        // Plain Enter walks the row (wired per field in wireAddRowPickers);
        // Ctrl+Enter saves from wherever you are, Escape clears.
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); rtSave(activeVisitTab); }
        else if (e.key === 'Escape') { e.preventDefault(); rtClear(activeVisitTab); }
    };
    document.addEventListener('keydown', inlineKeyHandler);
}

function removeInlineKeyboardShortcuts() {
    if (inlineKeyHandler) {
        document.removeEventListener('keydown', inlineKeyHandler);
        inlineKeyHandler = null;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (rtRow(activeVisitTab)) addInlineKeyboardShortcuts();
    wireAddRowPickers(document);
});

let toothSelectorKeyHandler = null;

function openToothSelector(quadrant) {
    currentQuadrantForSelection = quadrant;

    // Open the tooth detail overlay for selection
    const overlay = document.createElement('div');
    overlay.id = 'tooth-selector-overlay';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';

    // Close on overlay click (click outside) — selection is already applied live
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeToothSelector();
        }
    });

    const modal = document.createElement('div');
    modal.style.cssText = 'background: white; padding: 20px; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);';

    // Both tooth sets, each under its own heading — adult and child teeth are
    // pickable in the same quadrant for a mixed-dentition patient.
    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0;">${getQuadrantName(quadrant)}</h4>
            <span style="font-size: 11px; color: #666; background: #f0f0f0; padding: 4px 10px; border-radius: 12px;">
                <i class="fas fa-mouse-pointer"></i> چەند ددانێک هەڵبژێرە
            </span>
        </div>
    `;

    html += buildQuadrantToothGrid(quadrant, selectedTeeth[quadrant],
                                   'tooth-sel-btn', 'tooth-selector-', 'toggleToothSelectionFast');

    html += `
        <div style="display: flex; gap: 10px; margin-top: 16px;">
            <button type="button" class="btn btn-primary" onclick="closeToothSelector()"
                    style="flex: 1; padding: 10px; font-size: 14px; font-weight: 600;">
                <i class="fas fa-check"></i> تەواو (<span id="tooth-selector-count">${selectedTeeth[quadrant].length}</span>)
            </button>
            <button type="button" class="btn btn-secondary" onclick="clearToothSelection('${quadrant}')" style="padding: 10px 16px;">
                <i class="fas fa-eraser"></i> پاککردنەوە
            </button>
        </div>`;

    modal.innerHTML = html;
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Keyboard: 1-8 toggles an adult tooth, A-E a child tooth, Enter/Escape closes
    toothSelectorKeyHandler = function(e) {
        if (e.key === 'Enter' || e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeToothSelector();
            return;
        }
        const fdi = toothKeyToFdi(e.key, quadrant);
        if (fdi) toggleToothSelectionFast(fdi, quadrant);
    };
    document.addEventListener('keydown', toothSelectorKeyHandler, true);
}

function clearToothSelection(quadrant) {
    selectedTeeth[quadrant] = [];
    document.querySelectorAll('.tooth-sel-btn').forEach(btn => {
        btn.style.border = '2px solid #ddd';
        btn.style.background = 'white';
    });
    updateQuadrantDisplays();
    const cnt = document.getElementById('tooth-selector-count');
    if (cnt) cnt.textContent = '0';
}

function getQuadrantName(quadrant) {
    const names = {
        'UL': 'Upper Left',
        'UR': 'Upper Right',
        'LL': 'Lower Left',
        'LR': 'Lower Right'
    };
    return names[quadrant] || quadrant;
}

// ── Mixed-dentition tooth pickers ──────────────────────────────────
// The same quadrant holds an adult tooth set and a child one. FDI keeps them
// apart (permanent = quadrants 1-4, primary = 5-8), so both can be offered side
// by side without any chance of a collision.
const QUADRANT_DIGIT = {
    permanent: { UR: 1, UL: 2, LL: 3, LR: 4 },
    primary:   { UR: 5, UL: 6, LL: 7, LR: 8 }
};
const DENTITION_LABELS = { permanent: 'گەورە (١-٨)', primary: 'منداڵ (A-E)' };

// A keypress → FDI inside this quadrant: 1-8 hits an adult tooth, A-E a child one.
function toothKeyToFdi(key, quadrant) {
    if (/^[1-8]$/.test(key)) {
        return QUADRANT_DIGIT.permanent[quadrant] * 10 + parseInt(key, 10);
    }
    const pos = 'ABCDE'.indexOf(String(key || '').toUpperCase());
    if (pos !== -1) {
        return QUADRANT_DIGIT.primary[quadrant] * 10 + pos + 1;
    }
    return null;
}

// One quadrant's buttons, split into a labelled adult block and a child block so
// an adult "1" is never mistaken for a child "A".
function buildQuadrantToothGrid(quadrant, selected, btnClass, idPrefix, onclickFn) {
    let html = '';
    DENTITION_MODES.forEach(mode => {
        const teeth = (DENTITION_TEETH[mode] || {})[quadrant] || [];
        if (!teeth.length) return;
        html += '<div style="margin-bottom: 6px; font-size: 11px; font-weight: 700; color: #7a8b9d;">'
              + DENTITION_LABELS[mode] + '</div>'
              + '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px;">';
        teeth.forEach(toothNum => {
            const palmerNum = palmerNumbers[toothNum] != null ? palmerNumbers[toothNum] : (toothNum % 10);
            const isSelected = (selected || []).includes(toothNum);
            html += `<button type="button" class="${btnClass}"
                    onclick="${onclickFn}(${toothNum}, '${quadrant}')"
                    id="${idPrefix}${toothNum}"
                    title="${escapeHtml(toothNames[toothNum] || ('ددان ' + toothNum))}"
                    style="padding: 15px; border: 2px solid ${isSelected ? '#0078D4' : '#ddd'};
                           background: ${isSelected ? '#E3F2FD' : 'white'};
                           border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600;
                           transition: all 0.2s;">${palmerNum}</button>`;
        });
        html += '</div>';
    });
    return html;
}

function toggleToothSelection(toothNum, quadrant) {
    const index = selectedTeeth[quadrant].indexOf(toothNum);
    const btn = document.getElementById('tooth-selector-' + toothNum);

    if (index > -1) {
        // Remove
        selectedTeeth[quadrant].splice(index, 1);
        btn.style.border = '2px solid #ddd';
        btn.style.background = 'white';
    } else {
        // Add
        selectedTeeth[quadrant].push(toothNum);
        btn.style.border = '2px solid #0078D4';
        btn.style.background = '#E3F2FD';
    }
}

// Fast toggle - keeps the selector open so several teeth can be picked together
function toggleToothSelectionFast(toothNum, quadrant) {
    const index = selectedTeeth[quadrant].indexOf(toothNum);
    const btn = document.getElementById('tooth-selector-' + toothNum);

    if (index > -1) {
        // Remove
        selectedTeeth[quadrant].splice(index, 1);
        if (btn) {
            btn.style.border = '2px solid #ddd';
            btn.style.background = 'white';
        }
    } else {
        // Add
        selectedTeeth[quadrant].push(toothNum);
        if (btn) {
            btn.style.border = '2px solid #0078D4';
            btn.style.background = '#E3F2FD';
        }
    }

    // Auto-update the row cell + the Done-button counter immediately
    updateQuadrantDisplays();
    const cnt = document.getElementById('tooth-selector-count');
    if (cnt) cnt.textContent = selectedTeeth[quadrant].length;
}

function confirmToothSelection() {
    closeToothSelector();
    updateQuadrantDisplays();
}

function closeToothSelector() {
    const overlay = document.getElementById('tooth-selector-overlay');
    if (overlay) {
        overlay.remove();
    }
    if (toothSelectorKeyHandler) {
        document.removeEventListener('keydown', toothSelectorKeyHandler, true);
        toothSelectorKeyHandler = null;
    }
    focusActiveAddRowQty();
}

// The tooth picker lands on the active tab's quantity field so Enter saves at once.
function focusActiveAddRowQty() {
    const row = rtRow(activeVisitTab);
    const qty = row && row.querySelector('.rt-in-qty');
    if (qty) { qty.focus(); qty.select(); }
}

// ===== Inline Edit Functions =====
let editingRow = null;
let originalRowContent = null;
let editKeyHandler = null;
let editSelectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
let editingTreatmentData = null;

function editGroupedTreatment(treatmentData) {
    if (editingRow) cancelEditTreatment();
    const targetRow = document.querySelector('[data-treatment-group="' + treatmentData.group_uid + '"]');
    if (!targetRow) return;
    editingRow = targetRow;
    editingTreatmentData = treatmentData;
    originalRowContent = targetRow.innerHTML;
    const quantity = treatmentData.total_quantity;
    const unitPrice = treatmentData.unit_price;
    editSelectedTeeth = {
        UL: treatmentData.upper_left_fdi || [],
        UR: treatmentData.upper_right_fdi || [],
        LL: treatmentData.lower_left_fdi || [],
        LR: treatmentData.lower_right_fdi || []
    };
    const rowNum = targetRow.querySelector('td:first-child').textContent;
    const treatmentName = treatmentData.treatment_name;
    const needsTooth = treatmentData.arch_type === 'none';
    let locationEditor = '';
    if (needsTooth) {
        locationEditor = `<div class="tooth-cross is-input edit-tooth-cross">
            <button type="button" class="tc-ur" onclick="openEditToothSelector('UR')" id="edit-cell-UR"><span id="edit-teeth-UR">${(treatmentData.upper_right || []).join(',')}</span></button>
            <button type="button" class="tc-ul" onclick="openEditToothSelector('UL')" id="edit-cell-UL"><span id="edit-teeth-UL">${(treatmentData.upper_left || []).join(',')}</span></button>
            <button type="button" class="tc-lr" onclick="openEditToothSelector('LR')" id="edit-cell-LR"><span id="edit-teeth-LR">${(treatmentData.lower_right || []).join(',')}</span></button>
            <button type="button" class="tc-ll" onclick="openEditToothSelector('LL')" id="edit-cell-LL"><span id="edit-teeth-LL">${(treatmentData.lower_left || []).join(',')}</span></button>
        </div>`;
    } else {
        const isUpper = treatmentData.arch_type === 'upper';
        const isLower = treatmentData.arch_type === 'lower';
        const cls = isUpper ? 'is-upper' : (isLower ? 'is-lower' : 'is-service');
        const label = isUpper ? 'ماکزیلا / سەرەوە' : (isLower ? 'ماندیبل / خوارەوە' : 'بێ ژمارەی ددان');
        locationEditor = `<span class="rt-location-badge ${cls}"><i class="fas fa-briefcase-medical"></i> ${label}</span>`;
    }

    targetRow.innerHTML = `
        <td class="rt-c-no is-editing">${rowNum}</td>
        <td class="rt-c-desc is-editing"><strong>${escapeHtml(treatmentName)}</strong></td>
        <td class="rt-c-teeth is-editing">${locationEditor}</td>
        <td class="rt-c-qty is-editing"><input type="number" id="edit-quantity" class="rt-field" value="${quantity}" step="1" min="1" oninput="updateEditTotal()"></td>
        <td class="rt-c-unit is-editing"><input type="number" id="edit-price" class="rt-field" value="${unitPrice}" step="0.01" min="0" oninput="updateEditTotal()"></td>
        <td class="rt-c-total is-editing"><span id="edit-total">${numberFormat(quantity * unitPrice)}</span></td>
        <td class="rt-c-act is-editing"><span class="rt-row-actions">
            <button type="button" class="rt-row-action is-edit" onclick="saveEditedTreatment()" title="پاشەکەوت (Ctrl+Enter)"><i class="fas fa-check"></i></button>
            <button type="button" class="rt-row-action is-delete" onclick="cancelEditTreatment()" title="هەڵوەشاندنەوە (Esc)"><i class="fas fa-times"></i></button>
        </span></td>
    `;

    // Auto-focus on quantity field for faster editing
    setTimeout(() => {
        const qtyInput = document.getElementById('edit-quantity');
        if (qtyInput) {
            qtyInput.focus();
            qtyInput.select();
        }
    }, 100);

    // Add keyboard shortcuts for fast editing
    addEditKeyboardShortcuts(treatmentData);
}

function updateEditTotal() {
    const quantity = parseFloat(document.getElementById('edit-quantity').value) || 0;
    const price = parseFloat(document.getElementById('edit-price').value) || 0;
    const total = quantity * price;
    document.getElementById('edit-total').textContent = numberFormat(total);
}

function saveEditedTreatment(treatmentData) {
    treatmentData = treatmentData || editingTreatmentData;
    if (!treatmentData) return;
    const quantity = document.getElementById('edit-quantity').value;
    const unitPrice = document.getElementById('edit-price').value;

    // Validation
    if (!quantity || quantity <= 0) {
        alert('تکایە ژمارەیەکی دروست داخڵ بکە');
        return;
    }

    if (!unitPrice || unitPrice < 0) {
        alert('تکایە نرخێکی دروست داخڵ بکە');
        return;
    }

    // Collect all selected teeth from edit mode
    const allSelectedTeeth = [
        ...editSelectedTeeth.UL,
        ...editSelectedTeeth.UR,
        ...editSelectedTeeth.LL,
        ...editSelectedTeeth.LR
    ];

    // Empty teeth is allowed: the group is saved as one job without a tooth.
    const needsTooth = treatmentData.arch_type === 'none';

    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';

    // Add CSRF
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = CSRF_TOKEN;
    form.appendChild(csrfInput);

    // Add action
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_grouped_treatment';
    form.appendChild(actionInput);

    // Add original treatment IDs to delete
    treatmentData.treatment_ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'old_treatment_ids[]';
        input.value = id;
        form.appendChild(input);
    });

    // Add treatment data
    const treatmentIdInput = document.createElement('input');
    treatmentIdInput.type = 'hidden';
    treatmentIdInput.name = 'treatment_id';
    treatmentIdInput.value = treatmentData.treatment_id || '';
    form.appendChild(treatmentIdInput);

    const treatmentNameInput = document.createElement('input');
    treatmentNameInput.type = 'hidden';
    treatmentNameInput.name = 'treatment_name';
    treatmentNameInput.value = treatmentData.treatment_name;
    form.appendChild(treatmentNameInput);

    // Add new values
    const qtyInput = document.createElement('input');
    qtyInput.type = 'hidden';
    qtyInput.name = 'new_quantity';
    qtyInput.value = quantity;
    form.appendChild(qtyInput);

    const priceInput = document.createElement('input');
    priceInput.type = 'hidden';
    priceInput.name = 'new_unit_price';
    priceInput.value = unitPrice;
    form.appendChild(priceInput);

    // Add new teeth selections
    const teethInput = document.createElement('input');
    teethInput.type = 'hidden';
    teethInput.name = 'new_tooth_numbers';
    teethInput.value = JSON.stringify(needsTooth ? allSelectedTeeth : []);
    form.appendChild(teethInput);

    document.body.appendChild(form);
    form.submit();
}

function cancelEditTreatment() {
    if (editingRow && originalRowContent) {
        editingRow.innerHTML = originalRowContent;
    }
    editingRow = null;
    editingTreatmentData = null;
    originalRowContent = null;
    removeEditKeyboardShortcuts();
}

function addEditKeyboardShortcuts(treatmentData) {
    removeEditKeyboardShortcuts();

    editKeyHandler = function(e) {
        if (editingRow) {
            // Ctrl+Enter or Cmd+Enter to save
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveEditedTreatment(treatmentData);
            }
            // Escape to cancel
            else if (e.key === 'Escape') {
                e.preventDefault();
                cancelEditTreatment();
            }
        }
    };

    document.addEventListener('keydown', editKeyHandler);
}

function removeEditKeyboardShortcuts() {
    if (editKeyHandler) {
        document.removeEventListener('keydown', editKeyHandler);
        editKeyHandler = null;
    }
}

let editToothSelectorKeyHandler = null;

function openEditToothSelector(quadrant) {
    currentQuadrantForSelection = quadrant;

    // Open the tooth detail overlay for selection
    const overlay = document.createElement('div');
    overlay.id = 'edit-tooth-selector-overlay';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';

    // Close on overlay click — selection is already applied live
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeEditToothSelector();
        }
    });

    const modal = document.createElement('div');
    modal.style.cssText = 'background: white; padding: 20px; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);';

    // Both tooth sets, each under its own heading — an existing treatment group
    // can span adult and child teeth, so the editor must offer both.
    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0;">${getQuadrantName(quadrant)}</h4>
            <span style="font-size: 11px; color: #666; background: #f0f0f0; padding: 4px 10px; border-radius: 12px;">
                <i class="fas fa-mouse-pointer"></i> چەند ددانێک هەڵبژێرە
            </span>
        </div>
    `;

    html += buildQuadrantToothGrid(quadrant, editSelectedTeeth[quadrant],
                                   'edit-tooth-sel-btn', 'edit-tooth-selector-', 'toggleEditToothSelectionFast');

    html += `
        <div style="display: flex; gap: 10px; margin-top: 16px;">
            <button type="button" class="btn btn-primary" onclick="closeEditToothSelector()"
                    style="flex: 1; padding: 10px; font-size: 14px; font-weight: 600;">
                <i class="fas fa-check"></i> تەواو (<span id="edit-tooth-selector-count">${editSelectedTeeth[quadrant].length}</span>)
            </button>
            <button type="button" class="btn btn-secondary" onclick="clearEditToothSelection('${quadrant}')" style="padding: 10px 16px;">
                <i class="fas fa-eraser"></i> پاککردنەوە
            </button>
        </div>`;

    modal.innerHTML = html;
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Keyboard: 1-8 toggles an adult tooth, A-E a child tooth, Enter/Escape closes
    editToothSelectorKeyHandler = function(e) {
        if (e.key === 'Enter' || e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeEditToothSelector();
            return;
        }
        const fdi = toothKeyToFdi(e.key, quadrant);
        if (fdi) toggleEditToothSelectionFast(fdi, quadrant);
    };
    document.addEventListener('keydown', editToothSelectorKeyHandler, true);
}

function toggleEditToothSelectionFast(toothNum, quadrant) {
    const index = editSelectedTeeth[quadrant].indexOf(toothNum);
    const btn = document.getElementById('edit-tooth-selector-' + toothNum);

    if (index > -1) {
        // Remove
        editSelectedTeeth[quadrant].splice(index, 1);
        if (btn) {
            btn.style.border = '2px solid #ddd';
            btn.style.background = 'white';
        }
    } else {
        // Add
        editSelectedTeeth[quadrant].push(toothNum);
        if (btn) {
            btn.style.border = '2px solid #0078D4';
            btn.style.background = '#E3F2FD';
        }
    }

    // Keep the selector open for multi-select; update displays live
    updateEditQuadrantDisplays();
    const cnt = document.getElementById('edit-tooth-selector-count');
    if (cnt) cnt.textContent = editSelectedTeeth[quadrant].length;
}

function clearEditToothSelection(quadrant) {
    editSelectedTeeth[quadrant] = [];
    document.querySelectorAll('.edit-tooth-sel-btn').forEach(btn => {
        btn.style.border = '2px solid #ddd';
        btn.style.background = 'white';
    });
    updateEditQuadrantDisplays();
    const cnt = document.getElementById('edit-tooth-selector-count');
    if (cnt) cnt.textContent = '0';
}

function updateEditQuadrantDisplays() {
    ['UL', 'UR', 'LL', 'LR'].forEach(quadrant => {
        const span = document.getElementById('edit-teeth-' + quadrant);
        if (span) {
            const palmerNums = editSelectedTeeth[quadrant].map(fdi => palmerNumbers[fdi] || (fdi % 10));
            span.textContent = palmerNums.join(', ');
        }
    });
}

function closeEditToothSelector() {
    document.getElementById('edit-tooth-selector-overlay')?.remove();
    if (editToothSelectorKeyHandler) {
        document.removeEventListener('keydown', editToothSelectorKeyHandler, true);
        editToothSelectorKeyHandler = null;
    }
    const qty = document.getElementById('edit-quantity');
    if (qty) {
        qty.focus();
        qty.select();
    }
}

// Make all functions globally accessible
window.closeToothDetail = closeToothDetail;
window.addTreatmentForTooth = addTreatmentForTooth;
window.updateTreatmentPrice = updateTreatmentPrice;
window.calculateTotal = calculateTotal;
window.editTreatment = editTreatment;
window.numberFormat = numberFormat;
window.escapeHtml = escapeHtml;
window.switchVisitTab = switchVisitTab;
window.openNewVisitTab = openNewVisitTab;
window.openSendInvoiceDialog = openSendInvoiceDialog;
window.rtDeleteGroup = rtDeleteGroup;
window.openVisitToothPicker = openVisitToothPicker;
window.rtUpdatePrice = rtUpdatePrice;
window.rtCalcTotal = rtCalcTotal;
window.rtQtyTouched = rtQtyTouched;
window.rtSyncQty = rtSyncQty;
window.rtComboOpen = rtComboOpen;
window.rtComboClose = rtComboClose;
window.openToothSelector = openToothSelector;
window.toggleToothSelection = toggleToothSelection;
window.toggleToothSelectionFast = toggleToothSelectionFast;
window.confirmToothSelection = confirmToothSelection;
window.closeToothSelector = closeToothSelector;
window.clearToothSelection = clearToothSelection;
window.clearEditToothSelection = clearEditToothSelection;
window.rtSave = rtSave;
window.rtClear = rtClear;
window.editGroupedTreatment = editGroupedTreatment;
window.updateEditTotal = updateEditTotal;
window.saveEditedTreatment = saveEditedTreatment;
window.cancelEditTreatment = cancelEditTreatment;
window.openEditToothSelector = openEditToothSelector;
window.toggleEditToothSelectionFast = toggleEditToothSelectionFast;
window.updateEditQuadrantDisplays = updateEditQuadrantDisplays;
window.closeEditToothSelector = closeEditToothSelector;

// Payment & Installment Functions
function toggleInstallmentSelector() {
    const paymentType = document.getElementById('payment_type_select').value;
    const wrapper = document.getElementById('installment_selector_wrapper');
    const selector = document.getElementById('installment_selector');
    const amountInput = document.getElementById('payment_amount_input');

    if (paymentType === 'installment' && wrapper) {
        wrapper.style.display = 'block';
        selector.required = true;
    } else if (wrapper) {
        wrapper.style.display = 'none';
        selector.required = false;
        selector.value = '';
        amountInput.value = '';
        amountInput.max = <?= max(1, $canReceiveMoney ? $remaining : $doctorCap) ?>; // back to the case cap
        document.getElementById('payment_installment_id').value = '';
    }
}

function selectInstallment() {
    const selector = document.getElementById('installment_selector');
    const selected = selector.options[selector.selectedIndex];
    const amountInput = document.getElementById('payment_amount_input');
    const installmentIdInput = document.getElementById('payment_installment_id');

    if (selected && selected.value) {
        const remaining = parseFloat(selected.getAttribute('data-remaining'));
        amountInput.value = remaining;
        amountInput.max = remaining;
        installmentIdInput.value = selected.value;
    } else {
        amountInput.value = '';
        installmentIdInput.value = '';
    }
}

function payInstallmentDirect(scheduleId, remaining, installmentNumber) {
    // Set payment type to installment
    document.getElementById('payment_type_select').value = 'installment';

    // Trigger toggle to show installment selector
    toggleInstallmentSelector();

    // Select the specific installment
    const selector = document.getElementById('installment_selector');
    selector.value = scheduleId;

    // Trigger amount update
    selectInstallment();

    // Open payment dialog
    openDialog('paymentDialog');
}

window.toggleInstallmentSelector = toggleInstallmentSelector;
window.selectInstallment = selectInstallment;
window.payInstallmentDirect = payInstallmentDirect;

// ===== Procedure Log Functions =====
function openProcedureLogDialog() {
    // Reset form
    document.getElementById('procedureLogForm').reset();
    document.getElementById('procedureLogAction').value = 'add_procedure_log';
    document.getElementById('procedureLogId').value = '';
    document.getElementById('procedureLogDialogTitle').textContent = 'زیادکردنی کار';
    document.getElementById('procedureLogSubmitIcon').className = 'fas fa-paper-plane';
    document.getElementById('procedureLogSubmitText').textContent = 'تۆمارکردن و ناردن بۆ ژمێریار';

    // Reset arch selector
    document.querySelectorAll('.procedure-log-arch-option').forEach(opt => {
        opt.classList.remove('selected');
        if (opt.dataset.arch === 'both') {
            opt.classList.add('selected');
            opt.querySelector('input').checked = true;
        }
    });

    // Set today's date
    document.getElementById('procedureDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('procedureTime').value = new Date().toTimeString().slice(0, 5);
    document.getElementById('procedureNextAppointmentDate').value = '';
    document.getElementById('procedureNextAppointmentTime').value = '';
    document.getElementById('procedureNextAppointmentDuration').value = '60';
    document.getElementById('procedureNextAppointmentNotes').value = '';
    document.getElementById('procedurePrice').value = '0';
    resetProcedureLogSlots();

    // Hide custom name field
    document.getElementById('customProcedureNameGroup').style.display = 'none';
    document.getElementById('customProcedureName').removeAttribute('required');

    openDialog('procedureLogDialog');
}

function editProcedureLog(log) {
    document.getElementById('procedureLogAction').value = 'update_procedure_log';
    document.getElementById('procedureLogId').value = log.id;
    document.getElementById('procedureLogDialogTitle').textContent = 'دەستکاری کار';
    document.getElementById('procedureLogSubmitIcon').className = 'fas fa-save';
    document.getElementById('procedureLogSubmitText').textContent = 'نوێکردنەوە';

    // Set procedure type
    const typeSelect = document.getElementById('procedureTypeSelect');
    typeSelect.value = log.procedure_type_id || '';
    handleProcedureTypeChange();

    // Set custom name if needed
    if (log.custom_procedure_name) {
        document.getElementById('customProcedureName').value = log.custom_procedure_name;
    }

    // Set arch selector
    selectProcedureLogArch(log.arch_affected || 'both');

    // Set date and time
    document.getElementById('procedureDate').value = log.procedure_date || '';
    document.getElementById('procedureTime').value = log.procedure_time || '';

    // Set notes
    document.getElementById('procedureNotes').value = log.notes || '';

    // Set price
    document.getElementById('procedurePrice').value = log.price || 0;

    // Set next appointment
    document.getElementById('procedureNextAppointmentDate').value = log.next_appointment_date || '';
    document.getElementById('procedureNextAppointmentTime').value = '';
    document.getElementById('procedureNextAppointmentDuration').value = '60';
    document.getElementById('procedureNextAppointmentNotes').value = log.next_appointment_notes || '';
    // Load the availability grid if this log already has a next date
    resetProcedureLogSlots();
    if (log.next_appointment_date) { loadProcedureLogSlots(); }

    openDialog('procedureLogDialog');
}

// ── Smart availability picker for the procedure-log next appointment ──
// Bound to the same appointment engine (api/schedule.php) as the standalone
// booking dialog, so the doctor sees real free slots and can't double-book.
(function() {
    var PL_DOCTOR_ID = <?= (int)($case['doctor_id'] ?? 0) ?: (int)$currentUser['id'] ?>;
    var PL_API = '<?= APP_URL ?>/api/schedule.php';

    window.resetProcedureLogSlots = function() {
        var box = document.getElementById('procedureLogSlotsBox');
        if (box) box.innerHTML = '<div style="text-align:center;color:#888;font-size:12px;padding:8px;">تکایە بەروار هەڵبژێرە بۆ پیشاندانی کاتە بەردەستەکان</div>';
    };

    window.loadProcedureLogSlots = async function() {
        var box = document.getElementById('procedureLogSlotsBox');
        if (!box) return;
        var date = document.getElementById('procedureNextAppointmentDate').value;
        var duration = document.getElementById('procedureNextAppointmentDuration').value || 30;
        document.getElementById('procedureNextAppointmentTime').value = '';
        if (!date) { resetProcedureLogSlots(); return; }
        box.innerHTML = '<div style="text-align:center;color:#888;padding:8px;"><i class="fas fa-spinner fa-spin"></i></div>';
        try {
            var res = await fetch(PL_API + '?action=get_all_slots&doctor_id=' + PL_DOCTOR_ID + '&date=' + date + '&duration=' + duration);
            var data = await res.json();
            if (!data.success) { box.innerHTML = '<div style="text-align:center;color:#c62828;padding:8px;">' + (escapeHtml(data.message) || 'هەڵەیەک ڕوویدا') + '</div>'; return; }
            var d = data.data;
            if (!d.working) { box.innerHTML = '<div style="text-align:center;color:#e65100;padding:8px;"><i class="fas fa-bed"></i> ئەم ڕۆژە پشووە</div>'; return; }
            if (!d.slots || !d.slots.length) { box.innerHTML = '<div style="text-align:center;color:#888;padding:8px;">هیچ کاتێک بەردەست نییە</div>'; return; }
            var html = '';
            d.slots.forEach(function(s) {
                var cls = s.status === 'available' ? '' : s.status;
                var click = (s.status === 'available' || s.status === 'booked') ? ' onclick="selectProcedureLogSlot(\'' + escapeHtml(s.time) + '\', this)"' : '';
                var t = (s.info || s.reason) ? ' title="' + escapeHtml(s.info || s.reason) + '"' : '';
                html += '<span class="apt-slot ' + cls + '"' + click + t + '>' + escapeHtml(s.time) + '</span>';
            });
            box.innerHTML = html;
        } catch (e) {
            box.innerHTML = '<div style="text-align:center;color:#c62828;padding:8px;">هەڵە لە بارکردنی کاتەکان</div>';
        }
    };

    window.selectProcedureLogSlot = function(time, el) {
        document.getElementById('procedureNextAppointmentTime').value = time;
        document.querySelectorAll('#procedureLogSlotsBox .apt-slot').forEach(function(s) { s.classList.remove('selected'); });
        if (el) el.classList.add('selected');
    };
})();

function selectProcedureLogArch(arch) {
    document.querySelectorAll('.procedure-log-arch-option').forEach(opt => {
        opt.classList.remove('selected');
        opt.querySelector('input').checked = false;
    });

    const option = document.querySelector(`.procedure-log-arch-option[data-arch="${arch}"]`);
    if (option) {
        option.classList.add('selected');
        option.querySelector('input').checked = true;
    }
}

function handleProcedureTypeChange() {
    const select = document.getElementById('procedureTypeSelect');
    const selectedOption = select.options[select.selectedIndex];
    const customNameGroup = document.getElementById('customProcedureNameGroup');
    const customNameInput = document.getElementById('customProcedureName');

    // Auto-fill the price from the selected type's default price.
    // (Previously the hidden price field stayed 0 and every logged work was
    // sent to accounting as "price: 0".)
    const priceInput = document.getElementById('procedurePrice');
    if (priceInput && selectedOption && selectedOption.dataset.price !== undefined) {
        priceInput.value = parseFloat(selectedOption.dataset.price) || 0;
    }

    // Check if "other" type is selected (look for type_code in the text)
    const isOther = selectedOption.text.toLowerCase().includes('other') ||
                    selectedOption.text.includes('کاری تر');

    if (isOther) {
        customNameGroup.style.display = 'block';
        customNameInput.setAttribute('required', 'required');
    } else {
        customNameGroup.style.display = 'none';
        customNameInput.removeAttribute('required');
        customNameInput.value = '';
    }
}

document.getElementById('procedureLogForm')?.addEventListener('submit', function(event) {
    const action = document.getElementById('procedureLogAction')?.value;
    const nextDate = document.getElementById('procedureNextAppointmentDate');
    const nextTime = document.getElementById('procedureNextAppointmentTime');

    if (action === 'add_procedure_log' && nextDate?.value && !nextTime?.value) {
        event.preventDefault();
        nextTime.focus();
        if (typeof showToast === 'function') {
            showToast('warning', 'بۆ زیادکردنی مەوعید، تکایە کاتیش دیاری بکە');
        } else {
            alert('بۆ زیادکردنی مەوعید، تکایە کاتیش دیاری بکە');
        }
    }
});

// Export functions to window for onclick handlers
window.openProcedureLogDialog = openProcedureLogDialog;
window.editProcedureLog = editProcedureLog;
window.selectProcedureLogArch = selectProcedureLogArch;
window.handleProcedureTypeChange = handleProcedureTypeChange;

// Image Upload Dialog Functions
let currentImageSlot = 1;

window.openImageUploadDialog = function(slotNumber) {
    currentImageSlot = slotNumber;
    document.getElementById('imageSlotNumber').value = slotNumber;
    document.getElementById('imageUploadSlotLabel').textContent = slotNumber;

    // Reset the form
    document.getElementById('caseImageForm').reset();
    document.getElementById('imageSlotNumber').value = slotNumber;
    document.getElementById('imagePreviewContainer').innerHTML = '';
    document.getElementById('imagePreviewContainer').style.display = 'none';

    // Show dialog
    document.getElementById('imageUploadDialog').classList.add('show');
};

window.closeImageUploadDialog = function() {
    document.getElementById('imageUploadDialog').classList.remove('show');
    document.getElementById('caseImageForm').reset();
    document.getElementById('imagePreviewContainer').innerHTML = '';
    document.getElementById('imagePreviewContainer').style.display = 'none';
};

window.previewSelectedImage = function(input) {
    const preview = document.getElementById('imagePreviewContainer');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileSize = (file.size / (1024 * 1024)).toFixed(2);

        // Check file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            Swal.fire({
                icon: 'error',
                title: 'قەبارەی فایل زۆرە!',
                text: 'قەبارەی فایل دەبێت کەمتر بێت لە 10MB',
                confirmButtonText: 'باشە'
            });
            input.value = '';
            preview.style.display = 'none';
            return;
        }

        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            Swal.fire({
                icon: 'error',
                title: 'جۆری فایل نادروستە!',
                text: 'تەنها JPG, PNG, GIF, WEBP, و PDF قبوڵ دەکرێت',
                confirmButtonText: 'باشە'
            });
            input.value = '';
            preview.style.display = 'none';
            return;
        }

        preview.style.display = 'block';

        if (file.type === 'application/pdf') {
            preview.innerHTML = `
                <div class="pdf-preview">
                    <i class="fas fa-file-pdf"></i>
                    <span class="file-name">${file.name}</span>
                    <span class="file-size">${fileSize} MB</span>
                </div>
            `;
        } else {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `
                    <div class="image-preview-wrapper">
                        <img src="${e.target.result}" alt="Preview">
                        <div class="file-info">
                            <span class="file-name">${file.name}</span>
                            <span class="file-size">${fileSize} MB</span>
                        </div>
                    </div>
                `;
            };
            reader.readAsDataURL(file);
        }
    } else {
        preview.style.display = 'none';
        preview.innerHTML = '';
    }
};

// Close modal when clicking outside
document.getElementById('imageUploadDialog')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeImageUploadDialog();
    }
});

// A file must be chosen before upload (the file input is hidden, so the
// native `required` bubble can't show — validate here with a visible message)
document.getElementById('caseImageForm')?.addEventListener('submit', function(e) {
    const fileInput = document.getElementById('caseImageFile');
    if (!fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'warning', title: 'تکایە فایلێک هەڵبژێرە', confirmButtonText: 'باشە' });
        } else {
            alert('تکایە فایلێک هەڵبژێرە');
        }
    }
});

// ═══════════════════════════════════════════════════════════════════
// Page-wide UX layer: dialog manager, double-submit guard, shortcuts,
// and failed-POST form restore. Runs after the footer defines the base
// openDialog/closeDialog, hence the DOMContentLoaded wrapper.
// ═══════════════════════════════════════════════════════════════════
const CASE_FORM_RESTORE = <?= json_encode($formRestore ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null' ?>;

document.addEventListener('DOMContentLoaded', function() {
    // ── Dialog manager: one dialog at a time, scroll lock, autofocus ──
    const baseOpenDialog = window.openDialog;
    const baseCloseDialog = window.closeDialog;

    window.openDialog = function(dialogId) {
        // Close any other standard dialog first — stacked dialogs trap the user
        document.querySelectorAll('.dialog-overlay.open').forEach(d => {
            if (d.id !== dialogId) d.classList.remove('open');
        });
        baseOpenDialog(dialogId);
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const dlg = document.getElementById(dialogId);
            const field = dlg?.querySelector('select:not([disabled]), input:not([type="hidden"]):not([disabled]), textarea:not([disabled])');
            if (field) field.focus();
        }, 80);
    };

    window.closeDialog = function(dialogId) {
        baseCloseDialog(dialogId);
        if (!document.querySelector('.dialog-overlay.open')) {
            document.body.style.overflow = '';
        }
    };

    // Click on the dark backdrop closes the dialog
    document.querySelectorAll('.dialog-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeDialog(overlay.id);
        });
    });

    // ── Double-submit guard + loading state for every POST form ──
    // Runs in the bubble phase so form-level validation handlers (which may
    // preventDefault) have already had their say.
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        if (e.defaultPrevented) return;
        if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        // Disable after the submission data is captured, so button values still post
        setTimeout(() => {
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
                btn.disabled = true;
                if (btn.tagName === 'BUTTON' && !btn.dataset.origHtml) {
                    btn.dataset.origHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...';
                }
            });
        }, 0);
        // Safety net: re-enable if navigation didn't happen (e.g. download response)
        setTimeout(() => resetFormSubmitState(form), 15000);
    });

    function resetFormSubmitState(form) {
        delete form.dataset.submitting;
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
            btn.disabled = false;
            if (btn.dataset.origHtml) {
                btn.innerHTML = btn.dataset.origHtml;
                delete btn.dataset.origHtml;
            }
        });
    }

    // bfcache restore (back button): clear stuck "submitting" states
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            document.querySelectorAll('form[data-submitting]').forEach(resetFormSubmitState);
        }
    });

    // ── Keyboard shortcuts ──
    document.addEventListener('keydown', function(e) {
        const openOverlay = document.querySelector('.dialog-overlay.open');

        // Escape closes the open dialog
        if (e.key === 'Escape' && openOverlay) {
            closeDialog(openOverlay.id);
            return;
        }
        // Ctrl+Enter submits the form inside the open dialog
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && openOverlay) {
            const form = openOverlay.querySelector('form');
            if (form) {
                e.preventDefault();
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
            return;
        }
        // Alt+N: new treatment / work · Alt+P: payment (skip when typing in a field)
        if (e.altKey && !e.ctrlKey && !e.shiftKey) {
            const tag = (document.activeElement?.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            const key = e.key.toLowerCase();
            if (key === 'n') {
                e.preventDefault();
                <?php if ($case['status'] === 'open'): ?>
                <?php if ($isArchCase): ?>
                if (typeof openProcedureLogDialog === 'function') openProcedureLogDialog();
                <?php else: ?>
                openDialog('addTreatmentDialog');
                <?php endif; ?>
                <?php endif; ?>
            } else if (key === 'p') {
                e.preventDefault();
                if (document.getElementById('paymentDialog')) openDialog('paymentDialog');
            } else if (key === 'a') {
                e.preventDefault();
                if (typeof openAppointmentDialog === 'function') openAppointmentDialog();
            }
        }
    });

    // ── Failed POST: reopen the dialog pre-filled with what was typed ──
    if (CASE_FORM_RESTORE && CASE_FORM_RESTORE.dialog && document.getElementById(CASE_FORM_RESTORE.dialog)) {
        const dlg = document.getElementById(CASE_FORM_RESTORE.dialog);
        const values = CASE_FORM_RESTORE.values || {};
        Object.keys(values).forEach(name => {
            const fields = dlg.querySelectorAll('[name="' + CSS.escape(name) + '"]');
            fields.forEach(field => {
                if (field.type === 'radio') {
                    field.checked = (field.value === values[name]);
                } else if (field.type === 'checkbox') {
                    field.checked = !!values[name];
                } else if (field.type !== 'file') {
                    field.value = values[name];
                }
            });
        });
        // Keep edit-mode dialogs in edit mode (e.g. update_procedure_log)
        if (CASE_FORM_RESTORE.dialog === 'procedureLogDialog' && values.log_id) {
            const actionField = document.getElementById('procedureLogAction');
            if (actionField) actionField.value = 'update_procedure_log';
        }
        setTimeout(() => {
            openDialog(CASE_FORM_RESTORE.dialog);
            ['calculateTotal', 'updateDiscountPreview', 'updateInstallmentPreview'].forEach(fn => {
                if (typeof window[fn] === 'function') { try { window[fn](); } catch (err) {} }
            });
        }, 300);
    }

    // ── ?reopen=<dialogId>: "save & add another" flow ──
    const reopenParam = new URLSearchParams(window.location.search).get('reopen');
    if (reopenParam && /^[A-Za-z][A-Za-z0-9_-]*$/.test(reopenParam) && document.getElementById(reopenParam)) {
        setTimeout(() => openDialog(reopenParam), 300);
    }
});

console.log('✓ All functions loaded - Fast mode enabled');
</script>

<!-- Case Completion Button (Only for doctors) -->
<?php if ($auth->isDoctor() && $showLegacyCaseAccountingActions): ?>
<div class="case-completion-floating-btn">
<?php if ($canSendToAccounting): ?>
    <button type="button" class="btn-complete-case" onclick="openCompleteCaseDialog()">
        <i class="fas fa-check-circle"></i>
        <span>تەواوکردنی کەیس و ناردن بۆ ژمێریار</span>
    </button>
<?php elseif (!empty($accountingReport) && $accountingReport['status'] === 'processed'): ?>
    <span class="btn-complete-case" style="cursor:default;background:linear-gradient(135deg,#107c10,#0e6b0e);opacity:0.85;" title="ژمێریار کاری تێکردووە">
        <i class="fas fa-check-double"></i>
        <span>جێبەجێکراوە لەلایەن ژمێریار</span>
    </span>
<?php elseif (!empty($accountingReport)): ?>
    <?php // Already sent and still waiting: keep it actionable so the doctor can
          // send a follow-up note/update to the accountant (requires a comment). ?>
    <button type="button" class="btn-complete-case" style="background:linear-gradient(135deg,#ff9800,#f57c00);" onclick="openDialog('resendAccountingDialog')" title="ناردنی تێبینی نوێ بۆ ژمێریار">
        <i class="fas fa-comment-dots"></i>
        <span>ناردنی تێبینی بۆ ژمێریار</span>
    </button>
<?php endif; ?>
</div>

<style>
/* Floating Complete Case Button */
.case-completion-floating-btn {
    position: fixed;
    bottom: 80px;
    left: 30px;
    z-index: 1000;
}

.btn-complete-case {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border: none;
    padding: 14px 24px;
    border-radius: 50px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    transition: all 0.3s ease;
    font-family: var(--app-font-family);
}

.btn-complete-case:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(40, 167, 69, 0.5);
}

.btn-complete-case i {
    font-size: 20px;
}

/* Complete Case Modal */
.complete-case-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 2000;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(5px);
}

.complete-case-modal.show {
    display: flex;
}

.complete-case-dialog {
    background: white;
    border-radius: 16px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.complete-case-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    padding: 20px 24px;
    border-radius: 16px 16px 0 0;
}

.complete-case-header h3 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.complete-case-header .patient-info {
    margin-top: 10px;
    opacity: 0.9;
    font-size: 14px;
}

.complete-case-body {
    padding: 24px;
}

.complete-case-body .form-group {
    margin-bottom: 20px;
}

.complete-case-body label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.complete-case-body textarea {
    width: 100%;
    padding: 12px 14px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 14px;
    font-family: var(--app-font-family);
    resize: vertical;
    min-height: 100px;
    transition: border-color 0.2s;
}

.complete-case-body textarea:focus {
    outline: none;
    border-color: #28a745;
}

.complete-case-body input[type="datetime-local"] {
    width: 100%;
    padding: 12px 14px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 14px;
    font-family: var(--app-font-family);
}

.complete-case-body input[type="datetime-local"]:focus {
    outline: none;
    border-color: #28a745;
}

.complete-case-footer {
    padding: 16px 24px;
    background: #f8f9fa;
    border-radius: 0 0 16px 16px;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.complete-case-footer .btn {
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.complete-case-footer .btn-cancel {
    background: #e0e0e0;
    color: #333;
}

.complete-case-footer .btn-cancel:hover {
    background: #d0d0d0;
}

.complete-case-footer .btn-submit {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.complete-case-footer .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
}

.complete-case-footer .btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.form-hint {
    font-size: 12px;
    color: #666;
    margin-top: 6px;
}

/* Appointment Section Styles */
.appointment-section {
    margin-top: 16px;
}

.appointment-row {
    display: flex;
    gap: 16px;
    margin-top: 8px;
}

.appointment-field {
    flex: 1;
}

.appointment-field label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 4px;
}

.appointment-field label i {
    margin-left: 4px;
    color: #28a745;
}

.appointment-field input[type="date"] {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s;
}

.appointment-field input[type="date"]:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

.time-picker-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #eee;
}

.time-picker-section > label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    margin-bottom: 12px;
}

.time-picker-section > label i {
    margin-left: 6px;
    color: #28a745;
}

.case-time-picker {
    min-height: 60px;
}

.time-picker-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 20px;
    color: #666;
    font-size: 13px;
    background: #f8f9fa;
    border-radius: 8px;
}

.no-working-day {
    text-align: center;
    padding: 20px;
    color: #f44336;
    background: #ffebee;
    border-radius: 8px;
    font-size: 13px;
}

.no-working-day i {
    font-size: 24px;
    display: block;
    margin-bottom: 8px;
}

.smart-time-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(65px, 1fr));
    gap: 6px;
    max-height: 200px;
    overflow-y: auto;
    padding: 4px;
    background: #f8f9fa;
    border-radius: 8px;
}

.smart-time-slot {
    padding: 8px 4px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    font-weight: 600;
    background: white;
    color: #37474f;
}

.smart-time-slot:hover:not(.busy):not(.break) {
    border-color: #0078d4;
    background: #e3f2fd;
    color: #0078d4;
    transform: translateY(-1px);
}

.smart-time-slot.selected {
    background: #0078d4;
    color: white;
    border-color: #0078d4;
}

.smart-time-slot.busy {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: not-allowed;
    opacity: 0.7;
}

/* Booked slots stay selectable (overlapping bookings allowed) */
.smart-time-slot.booked {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: pointer;
}

.smart-time-slot.booked.selected {
    background: #0078d4;
    color: white;
    border-color: #0078d4;
}

.smart-time-slot.break {
    background: #fff3e0;
    border-color: #ffe0b2;
    color: #e65100;
    cursor: not-allowed;
    opacity: 0.7;
}

.smart-time-picker-legend {
    display: flex;
    gap: 16px;
    margin-top: 10px;
    font-size: 11px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.legend-item::before {
    content: '';
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.legend-item.available::before {
    background: white;
    border: 1px solid #e0e0e0;
}

.legend-item.busy::before {
    background: #ffebee;
    border: 1px solid #f44336;
}

.legend-item.break::before {
    background: #fff3e0;
    border: 1px solid #ff9800;
}

.selected-appointment-info {
    display: none;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 8px;
    color: #2e7d32;
    font-size: 13px;
    border: 1px solid #a5d6a7;
}

.selected-appointment-info i {
    font-size: 16px;
}

.selected-appointment-info strong {
    font-weight: 600;
}

/* Existing Bookings Section */
.existing-bookings-section {
    margin: 16px 0;
    padding: 12px;
    background: #fff8e1;
    border-radius: 8px;
    border: 1px solid #ffecb3;
}

.existing-bookings-section label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #f57c00;
    margin-bottom: 10px;
}

.existing-bookings-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.existing-booking-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: white;
    border: 1px solid #ffcc80;
    border-radius: 20px;
    font-size: 12px;
    color: #e65100;
}

.existing-booking-item i {
    font-size: 10px;
}

.existing-booking-item .patient-name {
    font-weight: 600;
    color: #bf360c;
}

.no-bookings-message {
    padding: 10px;
    text-align: center;
    color: #7cb342;
    font-size: 13px;
}

.no-bookings-message i {
    margin-left: 6px;
}

/* Booking Summary */
.booking-summary {
    margin-top: 16px;
    padding: 16px;
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border-radius: 10px;
    border: 1px solid #90caf9;
}

.booking-summary .summary-title {
    font-weight: 600;
    font-size: 14px;
    color: #1565c0;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.booking-summary .summary-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.booking-summary .summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: white;
    border-radius: 6px;
    font-size: 13px;
}

.booking-summary .summary-label {
    color: #546e7a;
    font-weight: 500;
}

.booking-summary .summary-value {
    color: #1565c0;
    font-weight: 700;
}

/* Duration Select Styling */
#appointmentDuration {
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    width: 100%;
    background: white;
    cursor: pointer;
}

#appointmentDuration:focus {
    border-color: #0078d4;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
}

/* Appointment Row Grid */
.appointment-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.appointment-field label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 6px;
}

.appointment-field label i {
    margin-left: 4px;
}

.appointment-field input[type="date"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
}

.appointment-field input[type="date"]:focus {
    border-color: #0078d4;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
}
</style>

<!-- Complete Case Modal -->
<div class="complete-case-modal" id="completeCaseModal">
    <div class="complete-case-dialog">
        <div class="complete-case-header">
            <h3>
                <i class="fas fa-check-circle"></i>
                تەواوکردنی کەیس و ناردن بۆ ژمێریار
            </h3>
            <div class="patient-info">
                <i class="fas fa-user"></i> <?= htmlspecialchars($case['patient_name'] ?? '') ?>
                &nbsp;|&nbsp;
                <i class="fas fa-hashtag"></i> کەیس #<?= $caseId ?>
            </div>
        </div>
        <div class="complete-case-body">
            <div class="form-group">
                <label for="completionNotes">
                    <i class="fas fa-sticky-note"></i>
                    تێبینی کۆتایی (ئارەزوومەندانە)
                </label>
                <textarea id="completionNotes" placeholder="تێبینی لەسەر ئەو کارانەی کە ئەمڕۆ ئەنجام دراون..."></textarea>
                <div class="form-hint">ئەم تێبینییە بۆ ژمێریار دەنێردرێت</div>
            </div>
            <div class="form-group appointment-section">
                <label>
                    <i class="fas fa-calendar-alt"></i>
                    مەوعیدی داهاتوو (ئارەزوومەندانە)
                </label>
                <div class="appointment-row">
                    <div class="appointment-field">
                        <label for="nextAppointmentDate"><i class="fas fa-calendar"></i> بەروار</label>
                        <input type="date" id="nextAppointmentDate" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" onchange="loadCaseTimeSlots()">
                    </div>
                    <div class="appointment-field">
                        <label for="appointmentDuration"><i class="fas fa-hourglass-half"></i> ماوە (کاتژمێر)</label>
                        <select id="appointmentDuration" onchange="calculateEndTime()">
                            <option value="0.5">٣٠ خولەک</option>
                            <option value="1" selected>١ کاتژمێر</option>
                            <option value="1.5">١.٥ کاتژمێر</option>
                            <option value="2">٢ کاتژمێر</option>
                            <option value="2.5">٢.٥ کاتژمێر</option>
                            <option value="3">٣ کاتژمێر</option>
                            <option value="4">٤ کاتژمێر</option>
                            <option value="5">٥ کاتژمێر</option>
                        </select>
                    </div>
                </div>

                <!-- Existing Bookings Display -->
                <div class="existing-bookings-section" id="existingBookingsSection" style="display: none;">
                    <label><i class="fas fa-calendar-check"></i> مەوعیدە گیراوەکانی ئەم ڕۆژە</label>
                    <div id="existingBookingsList" class="existing-bookings-list">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <div class="time-picker-section" id="timePickerSection" style="display: none;">
                    <label><i class="fas fa-clock"></i> کاتی دەستپێکردن</label>
                    <input type="hidden" id="nextAppointmentTime">
                    <input type="hidden" id="nextAppointmentEndTime">
                    <div id="caseTimePickerContainer" class="case-time-picker">
                        <div class="time-picker-loading">
                            <i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...
                        </div>
                    </div>
                    <div class="smart-time-picker-legend">
                        <span class="legend-item available">بەردەست</span>
                        <span class="legend-item busy">گیراو</span>
                        <span class="legend-item break">پشوو</span>
                    </div>
                </div>

                <!-- Booking Summary -->
                <div class="booking-summary" id="bookingSummary" style="display: none;">
                    <div class="summary-title"><i class="fas fa-info-circle"></i> پوختەی مەوعید</div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">بەروار:</span>
                            <span class="summary-value" id="summaryDate">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">کاتی دەستپێکردن:</span>
                            <span class="summary-value" id="summaryStartTime">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">کاتی کۆتایی:</span>
                            <span class="summary-value" id="summaryEndTime">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">ماوە:</span>
                            <span class="summary-value" id="summaryDuration">-</span>
                        </div>
                    </div>
                </div>

                <div class="form-hint">ئەگەر مەوعیدی داهاتوو هەیە بۆ نەخۆشەکە دایبنێ</div>
            </div>
        </div>
        <div class="complete-case-footer">
            <button type="button" class="btn btn-cancel" onclick="closeCompleteCaseDialog()">
                <i class="fas fa-times"></i> پاشگەزبوونەوە
            </button>
            <button type="button" class="btn btn-submit" id="btnSubmitComplete" onclick="submitCaseCompletion()">
                <i class="fas fa-paper-plane"></i> ناردن و تەواوکردن
            </button>
        </div>
    </div>
</div>

<script>
// Complete Case Functions
function openCompleteCaseDialog() {
    document.getElementById('completeCaseModal').classList.add('show');
    document.getElementById('completionNotes').focus();
}

function closeCompleteCaseDialog() {
    document.getElementById('completeCaseModal').classList.remove('show');
    document.getElementById('completionNotes').value = '';
    document.getElementById('nextAppointmentDate').value = '';
    document.getElementById('nextAppointmentTime').value = '';
    document.getElementById('nextAppointmentEndTime').value = '';
    document.getElementById('appointmentDuration').value = '1';
    document.getElementById('timePickerSection').style.display = 'none';
    document.getElementById('caseTimePickerContainer').innerHTML = '';
    document.getElementById('existingBookingsSection').style.display = 'none';
    document.getElementById('bookingSummary').style.display = 'none';
    // Hide selected appointment info
    const infoEl = document.getElementById('selectedAppointmentInfo');
    if (infoEl) infoEl.style.display = 'none';
}

// Store existing bookings for the selected date
let existingBookings = [];

// Load time slots for appointment
async function loadCaseTimeSlots() {
    const dateInput = document.getElementById('nextAppointmentDate');
    const container = document.getElementById('caseTimePickerContainer');
    const section = document.getElementById('timePickerSection');
    const existingSection = document.getElementById('existingBookingsSection');
    const existingList = document.getElementById('existingBookingsList');
    const doctorId = '<?= $case['doctor_id'] ?? $currentUser['id'] ?>';

    // Clear previous time selection when date changes
    document.getElementById('nextAppointmentTime').value = '';
    document.getElementById('nextAppointmentEndTime').value = '';
    document.getElementById('bookingSummary').style.display = 'none';
    const infoEl = document.getElementById('selectedAppointmentInfo');
    if (infoEl) infoEl.style.display = 'none';
    existingBookings = [];

    if (!dateInput.value) {
        section.style.display = 'none';
        existingSection.style.display = 'none';
        return;
    }

    section.style.display = 'block';
    container.innerHTML = '<div class="time-picker-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

    try {
        // Fetch time slots and existing bookings in parallel
        const [slotsResponse, bookingsResponse] = await Promise.all([
            fetch(`<?= APP_URL ?>/api/schedule.php?action=get_all_slots&doctor_id=${doctorId}&date=${dateInput.value}&duration=30`),
            fetch(`<?= APP_URL ?>/api/schedule.php?action=get_bookings&doctor_id=${doctorId}&date=${dateInput.value}`)
        ]);

        const slotsResult = await slotsResponse.json();
        const bookingsResult = await bookingsResponse.json();

        // Display existing bookings
        if (bookingsResult.success && bookingsResult.data && bookingsResult.data.length > 0) {
            existingBookings = bookingsResult.data;
            existingSection.style.display = 'block';
            existingList.innerHTML = existingBookings.map(b => `
                <div class="existing-booking-item">
                    <i class="fas fa-clock"></i>
                    <span>${escapeHtml(b.start_time)} - ${escapeHtml(b.end_time)}</span>
                    <span class="patient-name">${escapeHtml(b.patient_name || 'مەوعید')}</span>
                </div>
            `).join('');
        } else {
            existingSection.style.display = 'block';
            existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هیچ مەوعیدێک نییە بۆ ئەم ڕۆژە</div>';
            existingBookings = [];
        }

        if (!slotsResult.success) {
            container.innerHTML = `<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>${slotsResult.message || 'هەڵەیەک ڕوویدا'}</div>`;
            return;
        }

        const data = slotsResult.data;

        if (!data.working) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i>ئەم ڕۆژە ڕۆژی پشووە</div>';
            return;
        }

        if (!data.slots || data.slots.length === 0) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i>هیچ کاتێک بەردەست نییە</div>';
            return;
        }

        // Render slots exactly like appointments.php
        let html = '<div class="smart-time-picker-grid">';
        data.slots.forEach(slot => {
            const statusClass = slot.status === 'available' ? '' : slot.status;
            const clickHandler = (slot.status === 'available' || slot.status === 'booked') ? `onclick="selectCaseTimeSlot('${escapeHtml(slot.time)}')"` : '';
            const titleText = slot.info || slot.reason || '';
            const title = titleText ? `title="${escapeHtml(titleText)}"` : '';

            html += `<div class="smart-time-slot ${statusClass}" ${clickHandler} ${title}>${escapeHtml(slot.time)}</div>`;
        });
        html += '</div>';

        container.innerHTML = html;

    } catch (error) {
        console.error('Error loading slots:', error);
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i>هەڵەیەک ڕوویدا</div>';
    }
}

function selectCaseTimeSlot(time) {
    document.getElementById('nextAppointmentTime').value = time;

    // Update visual selection
    document.querySelectorAll('#caseTimePickerContainer .smart-time-slot').forEach(slot => {
        slot.classList.remove('selected');
        if (slot.textContent.trim() === time) {
            slot.classList.add('selected');
        }
    });

    // Calculate end time
    calculateEndTime();
}

// Calculate end time based on start time and duration
function calculateEndTime() {
    const startTime = document.getElementById('nextAppointmentTime').value;
    const duration = parseFloat(document.getElementById('appointmentDuration').value);
    const selectedDate = document.getElementById('nextAppointmentDate').value;

    if (!startTime || !duration || !selectedDate) {
        document.getElementById('bookingSummary').style.display = 'none';
        return;
    }

    // Parse start time
    const [hours, minutes] = startTime.split(':').map(Number);
    const startMinutes = hours * 60 + minutes;
    const endMinutes = startMinutes + (duration * 60);

    const endHours = Math.floor(endMinutes / 60);
    const endMins = endMinutes % 60;
    const endTime = `${String(endHours).padStart(2, '0')}:${String(endMins).padStart(2, '0')}`;

    document.getElementById('nextAppointmentEndTime').value = endTime;

    // Update booking summary
    updateBookingSummary(selectedDate, startTime, endTime, duration);
}

// Update the booking summary display
function updateBookingSummary(date, startTime, endTime, duration) {
    const summary = document.getElementById('bookingSummary');

    // Format date for display
    const dateObj = new Date(date);
    const dateFormatted = dateObj.toLocaleDateString('ku-Arab', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    // Format duration for display
    let durationText = '';
    if (duration < 1) {
        durationText = `${duration * 60} خولەک`;
    } else if (duration === 1) {
        durationText = '١ کاتژمێر';
    } else {
        durationText = `${duration} کاتژمێر`;
    }

    document.getElementById('summaryDate').textContent = dateFormatted;
    document.getElementById('summaryStartTime').textContent = startTime;
    document.getElementById('summaryEndTime').textContent = endTime;
    document.getElementById('summaryDuration').textContent = durationText;

    summary.style.display = 'block';
}

// Check if the new booking overlaps with existing bookings
function checkOverlap(startTime, endTime) {
    const newStart = timeToMinutes(startTime);
    const newEnd = timeToMinutes(endTime);

    for (const booking of existingBookings) {
        const existingStart = timeToMinutes(booking.start_time);
        const existingEnd = timeToMinutes(booking.end_time);

        // Check for overlap
        if (newStart < existingEnd && newEnd > existingStart) {
            return {
                hasOverlap: true,
                conflictWith: booking
            };
        }
    }

    return { hasOverlap: false };
}

// Convert time string (HH:MM) to minutes
function timeToMinutes(time) {
    const [hours, minutes] = time.split(':').map(Number);
    return hours * 60 + minutes;
}

async function submitCaseCompletion() {
    const notes = document.getElementById('completionNotes').value.trim();
    const appointmentDate = document.getElementById('nextAppointmentDate').value;
    const appointmentTime = document.getElementById('nextAppointmentTime').value;
    const appointmentEndTime = document.getElementById('nextAppointmentEndTime').value;
    const duration = parseFloat(document.getElementById('appointmentDuration').value);
    const submitBtn = document.getElementById('btnSubmitComplete');

    // Validate: if date is selected, time must also be selected
    if (appointmentDate && !appointmentTime) {
        Swal.fire({
            icon: 'warning',
            title: 'تکایە کاتێک هەڵبژێرە',
            text: 'بەرواری مەوعید هەڵبژێردووە بەڵام کاتی مەوعید هەڵنەبژێردووە. تکایە کاتێکی بەردەست هەڵبژێرە.',
            confirmButtonText: 'باشە',
            confirmButtonColor: '#ffc107'
        });
        return;
    }

    // Overlapping bookings are allowed by clinic policy — no overlap check

    // Combine date and time if both provided
    let nextAppointment = '';
    if (appointmentDate && appointmentTime) {
        nextAppointment = appointmentDate + ' ' + appointmentTime + ':00';
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...';

    try {
        const formData = new FormData();
        formData.append('action', 'complete_case');
        formData.append('case_id', '<?= $caseId ?>');
        formData.append('patient_id', '<?= $case['patient_id'] ?>');
        formData.append('notes', notes);
        formData.append('next_appointment', nextAppointment);
        formData.append('end_time', appointmentEndTime);
        formData.append('duration', duration * 60); // Send in minutes

        const response = await fetch('<?= APP_URL ?>/api/notifications.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            closeCompleteCaseDialog();

            // Show success or warning message based on appointment conflict
            Swal.fire({
                icon: data.appointment_conflict ? 'warning' : 'success',
                title: data.appointment_conflict ? 'تەواو بوو بەڵام...' : 'سەرکەوتوو بوو!',
                html: data.message.replace(/\n/g, '<br>'),
                confirmButtonText: 'باشە',
                confirmButtonColor: data.appointment_conflict ? '#ffc107' : '#28a745'
            }).then(() => {
                // Reload to update floating button state (sent → pending indicator)
                window.location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'هەڵە!',
                text: data.error || 'هەڵەیەک ڕوویدا',
                confirmButtonText: 'باشە'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'هەڵە!',
            text: 'هەڵەیەک لە پەیوەندیکردن ڕوویدا',
            confirmButtonText: 'باشە'
        });
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> ناردن و تەواوکردن';
    }
}

// Close modal when clicking outside
document.getElementById('completeCaseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCompleteCaseDialog();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('completeCaseModal').classList.contains('show')) {
        closeCompleteCaseDialog();
    }
});
</script>
<?php endif; ?>

<!-- Image Upload Dialog -->
<div class="modal-overlay" id="imageUploadDialog">
    <div class="modal-dialog image-upload-modal">
        <div class="modal-header">
            <h4><i class="fas fa-cloud-upload-alt"></i> بارکردنی وێنە - شوێن <span id="imageUploadSlotLabel">1</span></h4>
            <button type="button" class="modal-close" onclick="closeImageUploadDialog()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="caseImageForm">
            <input type="hidden" name="action" value="upload_case_image">
            <input type="hidden" name="slot_number" id="imageSlotNumber" value="1">
            <div class="modal-body">
                <div class="upload-zone" onclick="document.getElementById('caseImageFile').click()">
                    <i class="fas fa-cloud-upload-alt upload-icon"></i>
                    <p class="upload-text">کرتە بکە یان وێنە ڕابکێشە بۆ ئێرە</p>
                    <span class="upload-hint">JPG, PNG, GIF, WEBP, PDF (زۆرترین قەبارە: 10MB)</span>
                </div>

                <!-- NOTE: no `required` here — a required control that is display:none
                     makes the browser refuse submission silently. JS validates instead. -->
                <input type="file" name="case_image" id="caseImageFile" accept="image/*,.pdf" style="display: none;" onchange="previewSelectedImage(this)">

                <div id="imagePreviewContainer" class="image-preview-container" style="display: none;"></div>

                <div class="form-group mt-3">
                    <label class="form-label"><i class="fas fa-comment"></i> تێبینی (ئارەزوومەندانە)</label>
                    <input type="text" name="image_description" class="form-control" placeholder="وەسفی وێنە...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeImageUploadDialog()">
                    <i class="fas fa-times"></i> داخستن
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i> بارکردن
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Image Upload Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
}

.modal-overlay.show {
    display: flex;
}

.modal-dialog.image-upload-modal {
    background: white;
    border-radius: 16px;
    width: 95%;
    max-width: 500px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-dialog .modal-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    padding: 18px 24px;
    border-radius: 16px 16px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-dialog .modal-header h4 {
    margin: 0;
    color: white;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-dialog .modal-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.modal-dialog .modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
}

.modal-dialog .modal-body {
    padding: 24px;
}

.modal-dialog .modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Upload Zone */
.upload-zone {
    border: 2px dashed #d0d0d0;
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background: #f9f9f9;
}

.upload-zone:hover {
    border-color: #0078d4;
    background: #f0f7ff;
}

.upload-zone .upload-icon {
    font-size: 48px;
    color: #0078d4;
    margin-bottom: 12px;
}

.upload-zone .upload-text {
    font-size: 14px;
    color: #333;
    margin: 0 0 8px 0;
    font-weight: 500;
}

.upload-zone .upload-hint {
    font-size: 12px;
    color: #888;
}

/* Image Preview Container */
.image-preview-container {
    margin-top: 16px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.image-preview-wrapper {
    position: relative;
}

.image-preview-wrapper img {
    width: 100%;
    max-height: 300px;
    object-fit: contain;
    background: #f5f5f5;
}

.image-preview-wrapper .file-info,
.pdf-preview .file-name,
.pdf-preview .file-size {
    padding: 10px 12px;
    background: #f5f5f5;
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #666;
}

.pdf-preview {
    padding: 30px;
    text-align: center;
    background: #fff5f5;
}

.pdf-preview i {
    font-size: 48px;
    color: #e74c3c;
    margin-bottom: 12px;
    display: block;
}

.pdf-preview .file-name {
    display: block;
    color: #333;
    font-weight: 500;
    background: transparent;
    padding: 4px 0;
}

.pdf-preview .file-size {
    display: block;
    color: #888;
    font-size: 11px;
    background: transparent;
    padding: 0;
}
</style>

<script src="<?= APP_URL ?>/assets/js/invoice-pane.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/invoice-pane.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/invoice-correction.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/invoice-correction.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
