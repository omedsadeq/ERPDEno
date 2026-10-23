<?php
/**
 * Invoice Details API
 * Returns detailed information about a case/invoice
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Payment.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get case ID
$caseId = intval($_GET['case_id'] ?? 0);

if (!$caseId) {
    echo json_encode(['success' => false, 'message' => 'Case ID is required']);
    exit;
}

// Doctor isolation: a doctor may only see invoices for cases they own.
AccessGuard::requireCaseWork($caseId, true);

// Initialize managers
$db = Database::getInstance();
$caseManager = new CaseManager();
$paymentManager = new Payment();

// Get case details
$case = $caseManager->getById($caseId);

if (!$case) {
    echo json_encode(['success' => false, 'message' => 'Case not found']);
    exit;
}

// Get treatments
$treatments = $caseManager->getTreatments($caseId);

// Get payments
$payments = $paymentManager->getByCase($caseId);

// Build response
$response = [
    'success' => true,
    'invoice' => [
        'id' => $case['id'],
        'case_number' => $case['case_number'],
        'patient_name' => $case['patient_name'],
        'patient_phone' => $case['patient_phone'],
        'doctor_name' => $case['doctor_name'],
        'total_amount' => $case['total_amount'],
        'discount_amount' => $case['discount_amount'],
        'paid_amount' => $case['paid_amount'],
        'status' => $case['status'],
        'created_at' => date('Y-m-d H:i', strtotime($case['created_at'])),
        'treatment_count' => count($treatments),
        'payment_count' => count($payments),
        'treatments' => array_map(function($t) {
            return [
                'treatment_name' => $t['treatment_name'],
                'tooth_number' => $t['tooth_number'],
                'quantity' => $t['quantity'],
                'unit_price' => $t['unit_price'],
                'final_price' => $t['final_price']
            ];
        }, $treatments),
        'payments' => array_map(function($p) {
            return [
                'payment_number' => $p['payment_number'],
                'amount' => $p['amount'],
                'payment_type' => $p['payment_type'],
                'payment_method' => $p['payment_method'],
                'payment_date' => date('Y-m-d H:i', strtotime($p['payment_date'])),
                'status' => $p['status']
            ];
        }, $payments)
    ]
];

echo json_encode($response);
