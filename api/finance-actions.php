<?php
/**
 * Finance Actions API — the Approval Center's write side.
 * ئایپیئای کردارەکانی دارایی — بەشی نووسینی ناوەندی پەسەندکردن
 *
 * Pure POST dispatch onto the Task 6/7/8 services (Payment, Installment,
 * CaseInvoice, XrayManager) — no SQL and no business logic here beyond what
 * those services already provide. Every action answers HTTP 200 with either
 * {ok:true, ...} or {ok:false, error:'...kurdish...'[, stale:true]}; only
 * the auth/CSRF gate below uses a non-200 status.
 *
 * "Already processed" detection: several services throw or return
 * ['success'=>false] both for real validation failures AND for the
 * optimistic-guard case (someone else already decided the row). Rather than
 * string-matching the message, fa_fail() re-reads the row's current status
 * after a failure and reports {stale:true} whenever it has moved off the
 * status this action expected.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseInvoice.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/Installment.php';
require_once __DIR__ . '/../classes/PatientAdvance.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$auth->isAccountant() && !$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
Csrf::require(); // answers its own 403 JSON + exits on failure

/** A failed action might be a real business rejection, or someone else may
 * have already decided this row (the optimistic-guard case). Re-check the
 * row's current status; if it moved off what this action expected, tell the
 * UI to refresh instead of surfacing the raw (often stale-sounding) message. */
function fa_fail(array $result, $currentRow, string $statusKey, string $expectedStatus): array {
    $stillExpected = $currentRow && ($currentRow[$statusKey] ?? null) === $expectedStatus;
    if (!$stillExpected) {
        return ['ok' => false, 'error' => 'پێشتر جێبەجێکراوە', 'stale' => true];
    }
    return ['ok' => false, 'error' => $result['message'] ?? 'کردارەکە سەرنەکەوت'];
}

/** Domain exceptions carry safe, already-Kurdish messages meant for display
 * (Payment::updatePendingAmount, Installment::activatePlan/rejectPlan use
 * InvalidArgumentException/RuntimeException this way). PDOException is a
 * RuntimeException subclass in PHP but must NEVER be treated as domain — its
 * message can contain raw SQL/schema detail (Database.php runs PDO in
 * ERRMODE_EXCEPTION). Anything else (plain Exception from a DB/transport
 * failure, etc.) is treated the same way: not safe to echo verbatim. */
function fa_is_domain_exception(Throwable $e): bool {
    if ($e instanceof PDOException) {
        return false;
    }
    return $e instanceof InvalidArgumentException || $e instanceof RuntimeException;
}

/** Same idea for the throw-style services (Payment::updatePendingAmount,
 * Installment::activatePlan/rejectPlan) — catch, re-check, map to stale. */
function fa_fail_thrown(Throwable $e, $currentRow, string $statusKey, string $expectedStatus): array {
    $stillExpected = $currentRow && ($currentRow[$statusKey] ?? null) === $expectedStatus;
    if (!$stillExpected) {
        return ['ok' => false, 'error' => 'پێشتر جێبەجێکراوە', 'stale' => true];
    }
    if (fa_is_domain_exception($e)) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    error_log('finance-actions api thrown [' . $statusKey . '=' . $expectedStatus . ']: ' . $e->getMessage());
    return ['ok' => false, 'error' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'];
}

$db = Database::getInstance();
$invoiceManager = new CaseInvoice();
$paymentManager = new Payment();
$installmentManager = new Installment();

$currentUser = $auth->getCurrentUser();
$actorId = (int)($currentUser['id'] ?? 0);
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ------------------------------------------------------- invoices

        case 'approve_invoice': {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $result = array_key_exists('received_cash', $_POST) || !empty($_POST['advance_amount'])
                ? $invoiceManager->approveWithAdvance($caseId, $invoiceId, $actorId,
                    $_POST['advance_amount'] ?? '0', $_POST['received_cash'] ?? '0', $_POST['accounting_notes'] ?? '')
                : $invoiceManager->approve($caseId, $invoiceId, $actorId, $_POST['accounting_notes'] ?? '');
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $invoiceManager->getById($invoiceId), 'accounting_status', 'sent'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'invoice' => $result['invoice'] ?? null], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'return_invoice': {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode(['ok' => false, 'error' => 'هۆکار پێویستە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = $invoiceManager->returnForCorrection($caseId, $invoiceId, $actorId, $reason);
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $invoiceManager->getById($invoiceId), 'accounting_status', 'sent'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ------------------------------------------------- arch work receipt

        case 'approve_arch_work': {
            require_once __DIR__ . '/../classes/ArchWorkReceipt.php';
            $service = new ArchWorkReceipt();
            $receiptId = (int)($_POST['receipt_id'] ?? 0);
            $result = $service->approve(
                $receiptId,
                $actorId,
                (float)($_POST['paid_amount'] ?? 0),
                (string)($_POST['payment_method'] ?? 'cash'),
                trim((string)($_POST['accounting_notes'] ?? ''))
            );
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $service->getById($receiptId), 'status', 'sent'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'receipt' => $result['receipt'] ?? null], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'return_arch_work': {
            require_once __DIR__ . '/../classes/ArchWorkReceipt.php';
            $service = new ArchWorkReceipt();
            $receiptId = (int)($_POST['receipt_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode(['ok' => false, 'error' => 'هۆکار پێویستە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = $service->returnForCorrection($receiptId, $actorId, $reason);
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $service->getById($receiptId), 'status', 'sent'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'update_invoice_accounting': {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $invoice = $invoiceManager->getById($invoiceId);
            if (!$invoice) {
                echo json_encode(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            // An approved invoice is edited in place — it stays approved and
            // keeps its approval date. editApproved() refuses unless the admin
            // switch (Settings::allowEditApprovedInvoice) is ON, and saves the
            // discount and the note as one audited change.
            if ($invoice['accounting_status'] === 'approved') {
                $result = $invoiceManager->editApproved($invoiceId, [
                    'discount_mode'    => ($_POST['discount_mode'] ?? '') === 'percent' ? 'percent' : 'amount',
                    'discount_value'   => max(0, (float)($_POST['discount_value'] ?? 0)),
                    'accounting_notes' => (string)($_POST['accounting_notes'] ?? ''),
                ], $actorId);
                if (empty($result['success'])) {
                    echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'داشکاندن نوێ نەکرایەوە'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                echo json_encode(['ok' => true, 'invoice_id' => $invoiceId], JSON_UNESCAPED_UNICODE);
                break;
            }
            if ($invoice['accounting_status'] !== 'sent') {
                echo json_encode(['ok' => false, 'error' => 'پێشتر جێبەجێکراوە', 'stale' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = $invoiceManager->setDiscount(
                (int)$invoice['case_id'],
                $invoiceId,
                ($_POST['discount_mode'] ?? '') === 'percent' ? 'percent' : 'amount',
                max(0, (float)($_POST['discount_value'] ?? 0)),
                $actorId
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'داشکاندن نوێ نەکرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $invoiceManager->updateAccountingNotes($invoiceId, $_POST['accounting_notes'] ?? '', $actorId);
            echo json_encode(['ok' => true, 'invoice_id' => $invoiceId], JSON_UNESCAPED_UNICODE);
            break;
        }

        /**
         * Accountant corrections on a sent invoice: the received figure, the
         * cash/debt/installment choice and the promise.
         *
         * The accountant settles the invoice, so they own these values once it
         * reaches them — the doctor's own edit path stays closed after sending.
         * Only the fields actually present in the POST are touched, so the
         * screen can save one control at a time without clearing the others.
         */
        case 'adjust_invoice_settlement': {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $invoice   = $invoiceManager->getById($invoiceId);
            if (!$invoice) {
                echo json_encode(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $fields = [];
            foreach (['received_amount', 'payment_intent', 'promised_date', 'promised_amount'] as $k) {
                if (array_key_exists($k, $_POST)) {
                    $fields[$k] = $_POST[$k];
                }
            }

            // Approved → edited in place (stays approved, same approval date;
            // setting-gated inside editApproved). Sent → the usual path.
            $isApproved = ($invoice['accounting_status'] ?? '') === 'approved';
            $result = $isApproved
                ? $invoiceManager->editApproved($invoiceId, $fields, $actorId)
                : $invoiceManager->accountantAdjust($invoiceId, $fields, $actorId);
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'نوێ نەکرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $inv = $result['invoice'];
            echo json_encode([
                'ok'             => true,
                'invoice_id'     => $invoiceId,
                'payment_intent' => $inv['payment_intent'] ?? null,
                'received'       => (float)($inv['draft_received_amount'] ?? 0),
                // On an approved invoice later receipts settle it too, which
                // only the ledger's remaining_amount accounts for.
                'remaining'      => $isApproved
                    ? (float)($inv['remaining_amount'] ?? 0)
                    : CaseInvoice::remainingOf($inv),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // -------------------------------------------------------- payments

        case 'approve_payment': {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $result = $paymentManager->approve(
                $paymentId, $actorId,
                array_key_exists('amount', $_POST) ? (float)$_POST['amount'] : null,
                trim((string)($_POST['note'] ?? '')),
                !empty($_POST['use_advance'])
            );
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $paymentManager->getById($paymentId), 'status', 'pending'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'payment_id' => $paymentId], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'adjust_payment': {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            try {
                $paymentManager->updatePendingAmount($paymentId, (float)($_POST['amount'] ?? 0), $actorId, trim((string)($_POST['note'] ?? '')));
                echo json_encode(['ok' => true, 'payment_id' => $paymentId], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(fa_fail_thrown($e, $paymentManager->getById($paymentId), 'status', 'pending'), JSON_UNESCAPED_UNICODE);
            }
            break;
        }

        case 'reject_payment': {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode(['ok' => false, 'error' => 'هۆکار پێویستە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = $paymentManager->reject($paymentId, $reason, $actorId);
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $paymentManager->getById($paymentId), 'status', 'pending'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'receive_payment': {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $allocations = [];
            foreach ((array)($_POST['allocations'] ?? []) as $invoiceId => $amount) {
                $invoiceId = (int)$invoiceId;
                $amount = round((float)$amount, 2);
                if ($invoiceId > 0 && $amount > 0) $allocations[$invoiceId] = $amount;
            }
            $result = $paymentManager->directPayment([
                'case_id'         => $caseId,
                'payment_type'    => 'cash',
                'amount'          => (float)($_POST['amount'] ?? 0),
                'payment_method'  => $_POST['payment_method'] ?? 'cash',
                'from_advance'    => ($_POST['payment_method'] ?? '') === 'advance',
                'payment_date'    => $_POST['payment_date'] ?? date('Y-m-d'),
                'notes'           => trim((string)($_POST['notes'] ?? '')),
                'allocations'     => $allocations,
                'idempotency_key' => trim((string)($_POST['idempotency_key'] ?? '')),
                'received_by'     => $actorId,
                'allow_draft'     => !empty($_POST['allow_draft']),
            ], $actorId);
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'پارەدان تۆمار نەکرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode([
                'ok'             => true,
                'payment_id'     => $result['payment_id'] ?? null,
                'payment_number' => $result['payment_number'] ?? null,
                'duplicate'      => !empty($result['duplicate']),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'reverse_payment': {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode(['ok' => false, 'error' => 'هۆکار پێویستە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = $paymentManager->refund($paymentId, $reason, $actorId);
            if (empty($result['success'])) {
                echo json_encode(fa_fail($result, $paymentManager->getById($paymentId), 'status', 'approved'), JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        // «تەعدیل» on an APPROVED receipt — its amount corrected in place; it
        // stays approved with its number and date (Payment::editApproved,
        // gated there on the approved-records switch).
        case 'edit_approved_payment': {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $result = $paymentManager->editApproved($paymentId, (float)($_POST['amount'] ?? 0), $actorId, trim((string)($_POST['note'] ?? '')));
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'قەبزەکە نەگۆڕدرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'payment_id' => $paymentId], JSON_UNESCAPED_UNICODE);
            break;
        }

        // The case's پارەدان tab: amount, date and time, method and discount
        // of an approved receipt together (Payment::editApprovedDetails).
        case 'edit_approved_payment_details': {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $fields = [];
            foreach (['amount', 'payment_date', 'payment_method', 'discount_mode', 'discount_value', 'discount_reason'] as $key) {
                if (isset($_POST[$key]) && is_string($_POST[$key])) {
                    $fields[$key] = $_POST[$key];
                }
            }
            $result = $paymentManager->editApprovedDetails($paymentId, $fields, $actorId, trim((string)($_POST['note'] ?? '')));
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'قەبزەکە نەگۆڕدرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'payment_id' => $paymentId, 'unchanged' => !empty($result['unchanged'])], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ----------------------------------------------------- installments

        case 'activate_installment': {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $editsIn = (array)($_POST['edits'] ?? []);
            $edits = [];
            // Whitelist only — installment_amount is deliberately dropped:
            // activatePlan() always recomputes it (Task 6 decision).
            foreach (['total_amount', 'number_of_installments', 'start_date', 'down_payment'] as $key) {
                if (array_key_exists($key, $editsIn)) {
                    $edits[$key] = $editsIn[$key];
                }
            }
            try {
                $plan = $installmentManager->activatePlan($planId, $edits, $actorId);
                echo json_encode(['ok' => true, 'plan' => $plan], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(fa_fail_thrown($e, $installmentManager->getPlanById($planId), 'status', 'pending'), JSON_UNESCAPED_UNICODE);
            }
            break;
        }

        case 'reject_installment': {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                echo json_encode(['ok' => false, 'error' => 'هۆکار پێویستە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            try {
                $installmentManager->rejectPlan($planId, $reason, $actorId);
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(fa_fail_thrown($e, $installmentManager->getPlanById($planId), 'status', 'pending'), JSON_UNESCAPED_UNICODE);
            }
            break;
        }

        case 'create_installment': {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $terms = (array)($_POST['terms'] ?? []);
            $eligibility = $invoiceManager->installmentEligibility($caseId, $invoiceId);
            if (empty($eligibility['allowed'])) {
                echo json_encode(['ok' => false, 'error' => $eligibility['message'] ?? 'ناتوانرێت پلانی قیست بۆی دروست بکرێت'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = $installmentManager->createPlan([
                'case_id'                => $caseId,
                'invoice_id'             => $invoiceId,
                'total_amount'           => (float)$eligibility['remaining'],
                'down_payment'           => max(0, (float)($terms['down_payment'] ?? 0)),
                'number_of_installments' => max(1, (int)($terms['number_of_installments'] ?? 1)),
                'start_date'             => $terms['start_date'] ?? date('Y-m-d'),
                'notes'                  => trim((string)($terms['notes'] ?? '')),
                'created_by'             => $actorId,
            ]);
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'پلانی قیست دروست نەکرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'plan_id' => $result['plan_id'] ?? null], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'receive_installment': {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $result = $installmentManager->makeFlexiblePayment($planId, [
                'amount'         => (float)($_POST['amount'] ?? 0),
                'payment_method' => $_POST['method'] ?? 'cash',
                'notes'          => trim((string)($_POST['note'] ?? '')),
            ], $actorId);
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'پارەدانی قیست تۆمار نەکرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'payment_id' => $result['payment_id'] ?? null], JSON_UNESCAPED_UNICODE);
            break;
        }

        // «تەعدیل» on an APPROVED plan — its terms corrected in place, the
        // schedule rebuilt, every collection kept (Installment::
        // editApprovedPlan, gated there on the approved-records switch).
        case 'edit_approved_installment': {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $editsIn = (array)($_POST['edits'] ?? []);
            $edits = [];
            foreach (['total_amount', 'number_of_installments', 'start_date', 'down_payment'] as $key) {
                if (array_key_exists($key, $editsIn)) {
                    $edits[$key] = $editsIn[$key];
                }
            }
            try {
                $plan = $installmentManager->editApprovedPlan($planId, $edits, $actorId);
                echo json_encode(['ok' => true, 'plan' => $plan], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                if (fa_is_domain_exception($e)) {
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                } else {
                    error_log('edit_approved_installment: ' . $e->getMessage());
                    echo json_encode(['ok' => false, 'error' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'], JSON_UNESCAPED_UNICODE);
                }
            }
            break;
        }

        // -------------------------------------------------------------- xray

        case 'xray_settle': {
            require_once __DIR__ . '/../classes/XrayManager.php';
            $xrayManager = new XrayManager();
            $role = $_SESSION['role'] ?? ROLE_ACCOUNTANT;
            $xrayId = (int)($_POST['id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $note = trim((string)($_POST['note'] ?? ''));

            $request = $xrayId > 0
                ? $db->fetch("SELECT id, request_status, amount FROM xray_requests WHERE id = :id", ['id' => $xrayId])
                : null;
            if (!$request || !in_array($request['request_status'], ['pending_accountant', 'returned_to_accountant'], true)) {
                echo json_encode(['ok' => false, 'error' => 'پێشتر جێبەجێکراوە', 'stale' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (!in_array($decision, ['approve_payment', 'approve_debt'], true)) {
                echo json_encode(['ok' => false, 'error' => 'کردارێکی نادروست'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $failed = false;
            $result = ['success' => false];

            // Optional base-amount correction
            $newAmountRaw = trim((string)($_POST['new_amount'] ?? ''));
            if ($newAmountRaw !== '') {
                $newAmount = filter_var($newAmountRaw, FILTER_VALIDATE_FLOAT);
                if ($newAmount === false || $newAmount < 0) {
                    echo json_encode(['ok' => false, 'error' => 'بڕی نادروست'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                if (abs($newAmount - (float)$request['amount']) > 0.0001) {
                    $result = $xrayManager->correctAmount($xrayId, $newAmount, $actorId, $role, $note);
                    $failed = empty($result['success']);
                }
            }

            // Optional discount
            $discountRaw = trim((string)($_POST['discount_amount'] ?? ''));
            if (!$failed && $discountRaw !== '') {
                $discountAmount = filter_var($discountRaw, FILTER_VALIDATE_FLOAT);
                if ($discountAmount === false || $discountAmount < 0) {
                    echo json_encode(['ok' => false, 'error' => 'داشکاندنی نادروست'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                // The dialog now PREFILLS داشکاندن with the stored discount, so
                // "apply whenever > 0" would re-apply the same discount on every
                // submit and could never clear one. Compare against what is
                // stored instead: only a real change writes, and typing 0 over
                // an existing discount removes it. Read after the correction
                // above, which resets the discount to 0 when the base amount
                // changed.
                $storedDiscount = (float)($db->fetch(
                    "SELECT discount_amount FROM xray_requests WHERE id = :id",
                    ['id' => $xrayId]
                )['discount_amount'] ?? 0);
                if (abs($discountAmount - $storedDiscount) > 0.0001) {
                    $discountReason = trim((string)($_POST['discount_reason'] ?? ''));
                    $result = $xrayManager->applyDiscount($xrayId, $discountAmount, $discountReason, $actorId);
                    $failed = empty($result['success']);
                }
            }

            // بڕی وەرگیراوە — how much cash is actually taken at the counter.
            // It, not the نەقد/قەرز button, decides the settlement once it is
            // sent: full → نەقد, nothing → قەرز, anything between → part cash
            // + the rest as x-ray debt. The button is still honoured when the
            // field is absent (an older cached page) and whenever there is no
            // money on the request at all (a free/dont_cash x-ray, where
            // "received 0" means nothing was owed, not "make it debt").
            if (!$failed) {
                $current = $db->fetch(
                    "SELECT amount, final_amount FROM xray_requests WHERE id = :id",
                    ['id' => $xrayId]
                ) ?: [];
                $finalAmount = round((float)(($current['final_amount'] ?? 0) ?: ($current['amount'] ?? 0)), 2);

                $receivedRaw = trim((string)($_POST['received_amount'] ?? ''));
                $received = null;
                if ($receivedRaw !== '' && $finalAmount > 0.009) {
                    $received = filter_var($receivedRaw, FILTER_VALIDATE_FLOAT);
                    if ($received === false || $received < 0) {
                        echo json_encode(['ok' => false, 'error' => 'بڕی وەرگیراوی نادروست'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    $received = round((float)$received, 2);
                    if ($received > $finalAmount + 0.009) {
                        echo json_encode(['ok' => false, 'error' => 'بڕی وەرگیراو لە کۆی کۆتایی زیاترە'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }

                if ($received === null) {
                    $result = $decision === 'approve_payment'
                        ? $xrayManager->approvePaymentAndSendToXray($xrayId, $actorId, $role, $note)
                        : $xrayManager->approveDebtAndSendToXray($xrayId, $actorId, $role, $note);
                } elseif ($received >= $finalAmount - 0.009) {
                    $result = $xrayManager->approvePaymentAndSendToXray($xrayId, $actorId, $role, $note);
                } elseif ($received <= 0.009) {
                    $result = $xrayManager->approveDebtAndSendToXray($xrayId, $actorId, $role, $note);
                } else {
                    $result = $xrayManager->approvePartialAndSendToXray($xrayId, $received, $actorId, $role, $note);
                }
            }

            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'کردارەکە جێبەجێ نەکرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        // «تەعدیل» on an APPROVED X-ray — price, discount and counter cash
        // corrected in place; it stays approved with its approval date and its
        // X-ray workflow status (XrayManager::editApprovedSettlement, gated
        // there on the approved-records switch).
        case 'edit_approved_xray': {
            require_once __DIR__ . '/../classes/XrayManager.php';
            $xrayManager = new XrayManager();
            $fields = [];
            foreach (['new_amount', 'discount_amount', 'discount_reason', 'received_amount', 'note'] as $key) {
                if (array_key_exists($key, $_POST)) {
                    $fields[$key] = $_POST[$key];
                }
            }
            $result = $xrayManager->editApprovedSettlement(
                (int)($_POST['id'] ?? 0), $fields, $actorId, $_SESSION['role'] ?? ROLE_ACCOUNTANT
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'داواکارییەکە نەگۆڕدرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ------------------------------- reversing an approved decision
        // The پەسەندکراو tab's two buttons. Both undo the money first and are
        // implemented by ApprovalReversal, which owns the per-source rules
        // (the admin lock on approved invoices, an active instalment plan
        // blocking its receipt, the optimistic status guard on every write).
        // kind = invoice|archwork|payment|installment|xray.

        case 'reopen_approved': {
            require_once __DIR__ . '/../classes/ApprovalReversal.php';
            $result = (new ApprovalReversal())->reopen(
                (string)($_POST['kind'] ?? ''),
                (int)($_POST['id'] ?? 0),
                $actorId,
                (string)($_POST['reason'] ?? '')
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'کردنەوە سەرنەکەوت'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'kind' => $result['kind'], 'id' => $result['id']], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'delete_approved': {
            require_once __DIR__ . '/../classes/ApprovalReversal.php';
            $result = (new ApprovalReversal())->remove(
                (string)($_POST['kind'] ?? ''),
                (int)($_POST['id'] ?? 0),
                $actorId,
                (string)($_POST['reason'] ?? '')
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'سڕینەوە سەرنەکەوت'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'kind' => $result['kind'], 'id' => $result['id']], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ----------------------------------------------- patient advances
        // ئامانەت — money taken at the counter before a case exists to put it
        // on. Only the RECEIVE, REFUND and DELETE ends live here: all move real
        // cash and all belong to the accountant. Putting an advance onto an invoice
        // is the doctor's job and runs from cases/view.php, which is where the
        // doctor is allowed to post (this file is accountant/admin only).

        case 'advance_apply': {
            $result = (new PatientAdvance())->applyToInvoice(
                (int)($_POST['invoice_id'] ?? 0), (float)($_POST['amount'] ?? 0), $actorId,
                trim((string)($_POST['idempotency_key'] ?? '')),
                trim((string)($_POST['notes'] ?? ''))
            );
            echo json_encode(empty($result['success'])
                ? ['ok' => false, 'error' => $result['message'] ?? 'بەکارهێنانی ئامانەت سەرنەکەوت']
                : ['ok' => true, 'payment_id' => $result['payment_id'], 'duplicate' => !empty($result['duplicate'])], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'advance_receive': {
            require_once __DIR__ . '/../classes/PatientAdvance.php';
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $amount = filter_var($amountRaw, FILTER_VALIDATE_FLOAT);
            if ($patientId <= 0 || $amount === false || $amount <= 0) {
                echo json_encode(['ok' => false, 'error' => 'بڕی نادروست'], JSON_UNESCAPED_UNICODE);
                break;
            }
            // Whose patient this old balance is. Required by the screen so the
            // receipt can name a doctor; the service still accepts null for the
            // receipts written before the column existed.
            $doctorId = (int)($_POST['doctor_id'] ?? 0);
            if ($doctorId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'دکتۆر هەڵبژێرە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = (new PatientAdvance())->receive(
                $patientId,
                (float)$amount,
                (string)($_POST['payment_method'] ?? 'cash'),
                (string)($_POST['notes'] ?? ''),
                $actorId,
                $doctorId
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'ئامانەت تۆمار نەکرا'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode([
                'ok'         => true,
                'advance_id' => $result['advance_id'],
                'advance_no' => $result['advance_no'],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'advance_refund': {
            require_once __DIR__ . '/../classes/PatientAdvance.php';
            $advanceId = (int)($_POST['advance_id'] ?? 0);
            $amount = filter_var(trim((string)($_POST['amount'] ?? '')), FILTER_VALIDATE_FLOAT);
            if ($advanceId <= 0 || $amount === false || $amount <= 0) {
                echo json_encode(['ok' => false, 'error' => 'بڕی نادروست'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $result = (new PatientAdvance())->refund(
                $advanceId, (float)$amount, (string)($_POST['reason'] ?? ''), $actorId
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'گەڕاندنەوە سەرنەکەوت'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'refunded' => $result['refunded']], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ڕەشکردنەوەی قەبزی قەرزی کۆن — the row's 🗑 on the قەرزی کۆن tab.
        case 'advance_delete': {
            require_once __DIR__ . '/../classes/PatientAdvance.php';
            $result = (new PatientAdvance())->delete(
                (int)($_POST['advance_id'] ?? 0), (string)($_POST['reason'] ?? ''), $actorId
            );
            if (empty($result['success'])) {
                echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'سڕینەوە سەرنەکەوت'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['ok' => true, 'advance_no' => $result['advance_no']], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ------------------------------------------------- patient statement

        case 'set_opening_balance': {
            require_once __DIR__ . '/../classes/PatientLedger.php';
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $asOfDate = trim((string)($_POST['as_of_date'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $amount = filter_var($amountRaw, FILTER_VALIDATE_FLOAT);
            if ($patientId <= 0 || $amount === false || $amount < 0) {
                echo json_encode(['ok' => false, 'error' => 'بڕی نادروست'], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
                echo json_encode(['ok' => false, 'error' => 'بەرواری نادروست'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $patientExists = $db->fetch('SELECT id FROM patients WHERE id = :id', ['id' => $patientId]);
            if (!$patientExists) {
                echo json_encode(['ok' => false, 'error' => 'نەخۆش نەدۆزرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            (new PatientLedger())->setOpeningBalance($patientId, (float)$amount, $asOfDate, $notes ?: null, $actorId);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'کردارێکی نادروست'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    // Never echo $e->getMessage() here — an unexpected PDOException (or any
    // other uncaught throw) can carry raw SQL/schema detail. Mirrors
    // api/approval-center.php's outer catch: generic Kurdish message to the
    // client, full detail to the log.
    error_log('finance-actions api error [' . $action . ']: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'], JSON_UNESCAPED_UNICODE);
}
