<?php
/**
 * Debt Payment API Endpoint
 * Handles flexible debt payment distribution
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DebtPayment.php';

header('Content-Type: application/json; charset=utf-8');

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'نەچووە ژوورەوە']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$debtPayment = new DebtPayment();

// Get action from request
$action = $_REQUEST['action'] ?? '';

// Doctor isolation: every action here is keyed on patient_id and exposes
// financial data. A doctor may only touch debt/credit/history for a patient
// whose folders they own (financial data is never shared cross-doctor).
if ($auth->isDoctor() && !$auth->isAdmin()) {
    $guardPatientId = intval($_REQUEST['patient_id'] ?? 0);
    AccessGuard::requirePatientWork($guardPatientId, true);
}

// State-changing actions record auto-approved money — require CSRF and a
// money-handling role (front desk / accountant / admin), not doctors or x-ray.
if (in_array($action, ['make_payment', 'use_credit'], true)) {
    Csrf::require();
    if (!$auth->isAdmin() && !$auth->isAccountant() && !$auth->isReceptionist()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'ڕێگەپێدان نییە بۆ وەرگرتنی پارە'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    switch ($action) {

        // Get debt items for a patient
        case 'get_debt_items':
            $patientId = intval($_REQUEST['patient_id'] ?? 0);
            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }

            $summary = $debtPayment->getDebtSummary($patientId);
            echo json_encode([
                'success' => true,
                'data' => $summary
            ], JSON_UNESCAPED_UNICODE);
            break;

        // Preview payment distribution
        case 'preview_payment':
            $patientId = intval($_REQUEST['patient_id'] ?? 0);
            $amount = floatval($_REQUEST['amount'] ?? 0);

            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }
            if ($amount <= 0) {
                throw new Exception('بڕی پارە دەبێت گەورەتر بێت لە سفر');
            }

            $preview = $debtPayment->previewDistribution($patientId, $amount);
            echo json_encode([
                'success' => true,
                'data' => $preview
            ], JSON_UNESCAPED_UNICODE);
            break;

        // Make payment
        case 'make_payment':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('تەنها POST قبووڵە');
            }

            $patientId = intval($_POST['patient_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['notes'] ?? '');

            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }
            if ($amount <= 0) {
                throw new Exception('بڕی پارە دەبێت گەورەتر بێت لە سفر');
            }

            // Validate payment method
            $validMethods = ['cash', 'card', 'transfer', 'other'];
            if (!in_array($paymentMethod, $validMethods)) {
                $paymentMethod = 'cash';
            }

            $result = $debtPayment->distributePayment(
                $patientId,
                $amount,
                $paymentMethod,
                $currentUser['id'],
                $notes
            );

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // Get patient credit balance
        case 'get_credit':
            $patientId = intval($_REQUEST['patient_id'] ?? 0);
            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }

            $credit = $debtPayment->getPatientCredit($patientId);
            echo json_encode([
                'success' => true,
                'credit' => $credit
            ], JSON_UNESCAPED_UNICODE);
            break;

        // Use credit toward debt
        case 'use_credit':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('تەنها POST قبووڵە');
            }

            $patientId = intval($_POST['patient_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }

            $result = $debtPayment->useCredit($patientId, $amount, $currentUser['id'], $notes);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // Get payment history
        case 'get_history':
            $patientId = intval($_REQUEST['patient_id'] ?? 0);
            $limit = intval($_REQUEST['limit'] ?? 50);

            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }

            $history = $debtPayment->getPaymentHistory($patientId, $limit);
            echo json_encode([
                'success' => true,
                'data' => $history
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception('کردار نەناسراوە');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
