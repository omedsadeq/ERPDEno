<?php
/**
 * Quick Payment API
 * پرۆسێسکردنی خێرای پارەدان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/Patient.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// This endpoint creates an AUTO-APPROVED payment — only roles that are
// allowed to collect money may use it (not doctors / x-ray staff).
if (!$auth->isAdmin() && !$auth->isAccountant() && !$auth->isReceptionist()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ڕێگەپێدان نییە بۆ وەرگرتنی پارە']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
Csrf::require();

$db = Database::getInstance();
$payment = new Payment();
$patientManager = new Patient();
$currentUser = $auth->getCurrentUser();

try {
    // Get input data
    $patientId = $_POST['patient_id'] ?? 0;
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $notes = $_POST['notes'] ?? '';

    // Validate
    if (!$patientId || $amount <= 0) {
        throw new Exception('Invalid input data');
    }

    // Get patient info
    $patient = $patientManager->getById($patientId);
    if (!$patient) {
        throw new Exception('Patient not found');
    }

    // Get ALL of the patient's open cases with debt, oldest first — the payment
    // is allocated across them so one case is never driven past its balance
    $sql = "SELECT c.id, c.case_number, c.total_amount, c.paid_amount, c.discount_amount,
            (c.total_amount - c.paid_amount - c.discount_amount) as remaining
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            WHERE pf.patient_id = :patient_id
            AND (c.total_amount - c.paid_amount - c.discount_amount) > 0
            AND c.status != 'closed'
            ORDER BY c.created_at ASC";

    $cases = $db->fetchAll($sql, ['patient_id' => $patientId]);

    if (empty($cases)) {
        throw new Exception('No active case with debt found for this patient');
    }

    $totalRemaining = 0;
    foreach ($cases as $c) { $totalRemaining += (float)$c['remaining']; }
    if ($amount > $totalRemaining) {
        throw new Exception('بڕی پارە لە کۆی قەرزی نەخۆش زیاترە (' . number_format($totalRemaining) . ')');
    }

    // Allocate oldest-first; each directPayment is atomic on its own (it manages
    // its own transaction internally — do NOT wrap it in another one)
    $amountLeft   = $amount;
    $firstResult  = null;
    $firstCase    = null;
    $paymentIds   = [];
    foreach ($cases as $c) {
        if ($amountLeft <= 0) { break; }
        $chunk = min($amountLeft, (float)$c['remaining']);
        $result = $payment->directPayment([
            'case_id' => $c['id'],
            'payment_type' => 'cash',
            'amount' => $chunk,
            'payment_method' => $paymentMethod,
            'notes' => $notes ?: 'پارەدانی خێرا لە بەشی قەرزەکانەوە'
        ], $currentUser['id']);

        if (!$result['success']) {
            if ($firstResult === null) {
                throw new Exception($result['message']);
            }
            break; // keep what was already collected; report the collected amount
        }
        if ($firstResult === null) { $firstResult = $result; $firstCase = $c; }
        $paymentIds[] = $result['payment_id'];
        $amountLeft -= $chunk;
    }

    // Return success with payment details
    echo json_encode([
        'success' => true,
        'message' => 'Payment received successfully',
        'payment_id' => $firstResult['payment_id'],
        'payment_ids' => $paymentIds,
        'payment_number' => $firstResult['payment_number'],
        'case_number' => $firstCase['case_number'],
        'patient_name' => $patient['full_name'],
        'amount' => $amount - $amountLeft,
        // cases/receipt.php is the receipt endpoint that actually exists
        'receipt_url' => APP_URL . '/cases/receipt.php?id=' . $firstCase['id']
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
