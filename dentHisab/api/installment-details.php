<?php
/**
 * API Endpoint: Get Installment Plan Details
 * Returns detailed information about an installment plan including all schedule items
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Installment.php';

// Initialize auth
$auth = new Auth();

// Check if user is authenticated
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$installmentManager = new Installment();

// Get plan ID from request
$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;

if ($planId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
    exit;
}

try {
    // Get plan details
    $plan = $installmentManager->getPlanById($planId);

    if (!$plan) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Plan not found']);
        exit;
    }

    // Doctor isolation: a doctor may only see installment plans for cases they own.
    AccessGuard::requireCaseWork((int)($plan['case_id'] ?? 0), true);

    // Get schedule
    $schedule = $installmentManager->getSchedule($planId);

    // Calculate totals
    $totalPaid = $plan['down_payment'];
    $remaining = $plan['remaining_amount'];

    foreach ($schedule as $sch) {
        $totalPaid += $sch['paid_amount'];
    }

    $remaining = $plan['total_amount'] - $totalPaid;

    // Prepare response
    $response = [
        'success' => true,
        'plan_id' => $plan['id'],
        'case_id' => $plan['case_id'],
        'case_number' => $plan['case_number'],
        'patient_name' => $plan['patient_name'],
        'patient_phone' => $plan['patient_phone'],
        'total_amount' => $plan['total_amount'],
        'down_payment' => $plan['down_payment'],
        'remaining_amount' => $plan['remaining_amount'],
        'number_of_installments' => $plan['number_of_installments'],
        'installment_amount' => $plan['installment_amount'],
        'start_date' => $plan['start_date'],
        'status' => $plan['status'],
        'total_paid' => $totalPaid,
        'remaining' => max(0, $remaining),
        'schedule' => []
    ];

    // Add schedule details
    foreach ($schedule as $sch) {
        $response['schedule'][] = [
            'id' => $sch['id'],
            'installment_number' => $sch['installment_number'],
            'due_date' => $sch['due_date'],
            'amount' => $sch['amount'],
            'paid_amount' => $sch['paid_amount'],
            'status' => $sch['status'],
            'payment_id' => $sch['payment_id'],
            'payment_number' => $sch['payment_number'] ?? null,
            'paid_date' => $sch['paid_date'] ?? null
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
