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

$case = $caseManager->getById($caseId);
if (!$case) {
    header('Location: ' . APP_URL . '/cases/index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$isDoctor = $auth->isDoctor();
$canRecordDownPayment = true;
$db = Database::getInstance();

// Check access — accountants can view any case (read-only financial view)
if (!$isAdmin && !$isAccountant && $case['doctor_id'] != $currentUser['id']) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Accountants are redirected to their own report-detail view
if ($isAccountant) {
    header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId);
    exit;
}

// Get related data
$chartEntries = $dentalChart->getByCase($caseId);
$caseTreatments = $caseManager->getTreatments($caseId);
$payments = $paymentManager->getByCase($caseId);
$installmentPlan = $installmentManager->getPlanByCase($caseId);

// Get arch procedure logs (for arch cases)
$isArchCase = ($case['scope_type'] ?? 'tooth') === 'arch';
$procedureLogs = [];
$procedureTypes = [];
$procedureStats = [];
if ($isArchCase) {
    $procedureLogs = $archProcedureLog->getLogsByCase($caseId);
    $procedureTypes = $archProcedureLog->getProcedureTypes();
    $procedureStats = $archProcedureLog->getCaseStats($caseId);
}

// Get treatments filtered by scope
$caseScope = $case['scope_type'] ?? 'tooth';
$treatments = $treatmentManager->getAll(); // All treatments for backward compatibility
$toothTreatments = $treatmentManager->getByScope('tooth'); // For individual tooth treatments
$archTreatments = $treatmentManager->getByScope('arch'); // For arch treatments
$teeth = $dentalChart->getAllTeeth();
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

// Get tooth names from DentalChart class
$toothNames = [];
$palmerNumbers = [];
foreach (array_merge($teeth['upper_right'], $teeth['upper_left'], $teeth['lower_right'], $teeth['lower_left']) as $num) {
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

function create_accounting_report($db, $caseId, $sentBy, $notes = '') {
    return $db->insert('accounting_reports', [
        'case_id' => $caseId,
        'sent_by' => $sentBy,
        'status'  => 'pending',
        'notes'   => trim($notes),
        'sent_at' => date('Y-m-d H:i:s')
    ]);
}

function append_to_accounting_report($db, $report, $notes) {
    $existingNotes = trim($report['notes'] ?? '');
    $newNotes = trim($notes);
    $combinedNotes = $existingNotes === '' ? $newNotes : $existingNotes . "\n\n" . $newNotes;

    return $db->update('accounting_reports', [
        'status' => 'pending',
        'notes' => $combinedNotes,
        'sent_at' => date('Y-m-d H:i:s')
    ], 'id = :id', ['id' => $report['id']]);
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

// Valid FDI tooth numbers (11-18, 21-28, 31-38, 41-48)
function is_valid_fdi_tooth($num) {
    $num = intval($num);
    $q = intdiv($num, 10);
    $p = $num % 10;
    return $q >= 1 && $q <= 4 && $p >= 1 && $p <= 8;
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

// Fresh chart + totals snapshot for AJAX mutations, so the client can update
// the page in place. Success responses always exit here.
function case_view_ajax_success($caseManager, $caseId, $extra = []) {
    $freshCase = $caseManager->getById($caseId);
    $freshTreatments = $caseManager->getTreatments($caseId);
    $remaining = (float)$freshCase['total_amount'] - (float)$freshCase['paid_amount'] - (float)$freshCase['discount_amount'];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => true,
        'case_totals' => [
            'total_amount' => (float)$freshCase['total_amount'],
            'paid_amount' => (float)$freshCase['paid_amount'],
            'discount_amount' => (float)$freshCase['discount_amount'],
            'remaining' => max(0, $remaining)
        ],
        'treatments' => $freshTreatments,
        'tooth_map' => build_tooth_treatment_map($freshTreatments)
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];
    $caseRemaining = (float)$case['total_amount'] - (float)$case['paid_amount'] - (float)$case['discount_amount'];

    // A closed case is read-only server-side — only reopening is allowed.
    // (The UI already hides these buttons; this enforces it against direct/stale POSTs.)
    if (($case['status'] ?? '') === 'closed' && $action !== 'reopen_case') {
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&error=case_closed');
        exit;
    }

    if ($action === 'add_treatment') {
        $toothNumber = intval($_POST['tooth_number'] ?? 0) ?: null;
        if ($toothNumber !== null && !is_valid_fdi_tooth($toothNumber)) {
            case_view_fail($caseId, 'ژمارەی ددان نادروستە', 'addTreatmentDialog');
        }
        $data = [
            'treatment_id' => intval($_POST['treatment_id']) ?: null,
            'tooth_number' => $toothNumber,
            'tooth_surface' => $toothNumber !== null ? sanitize_tooth_surface($_POST['tooth_surface'] ?? '') : null,
            'treatment_name' => trim($_POST['treatment_name']),
            'quantity' => intval($_POST['quantity']) ?: 1,
            'unit_price' => floatval($_POST['unit_price']),
            'discount' => floatval($_POST['discount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];
        $result = $caseManager->addTreatment($caseId, $data);
        if ($result['success']) {
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId, ['treatment_id' => $result['treatment_id']]);
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
        // Add treatment for multiple teeth at once — all-or-nothing
        $toothNumbers = json_decode($_POST['tooth_numbers'] ?? '[]', true);
        if (!is_array($toothNumbers) || empty($toothNumbers) || count($toothNumbers) > 32) {
            case_view_fail($caseId, 'تکایە لانیکەم ددانێک هەڵبژێرە');
        }
        foreach ($toothNumbers as $toothNumber) {
            if (!is_valid_fdi_tooth($toothNumber)) {
                case_view_fail($caseId, 'ژمارەی ددان نادروستە');
            }
        }
        $treatmentData = [
            'treatment_id' => intval($_POST['treatment_id']) ?: null,
            'treatment_name' => trim($_POST['treatment_name']),
            'quantity' => intval($_POST['quantity']) ?: 1,
            'unit_price' => floatval($_POST['unit_price']),
            'discount' => floatval($_POST['discount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];

        $db->beginTransaction();
        $successCount = 0;
        $failMessage = '';
        foreach ($toothNumbers as $toothNumber) {
            $data = array_merge($treatmentData, ['tooth_number' => intval($toothNumber)]);
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
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatments_added=' . $successCount . '&work_added=1');
            exit;
        }
        $db->rollback();
        case_view_fail($caseId, $failMessage);
    } elseif ($action === 'add_arch_treatment') {
        // Add treatment for entire arch (all teeth in the selected arch)
        $archType = $_POST['arch_type'] ?? '';
        if (empty($archType)) {
            // Redirect back with error
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&error=no_arch_selected');
            exit;
        }

        $pricingMode = $_POST['pricing_mode'] ?? 'per_tooth';

        $treatmentData = [
            'treatment_id' => intval($_POST['treatment_id']) ?: null,
            'treatment_name' => trim($_POST['treatment_name']),
            'quantity' => 1,
            'discount' => 0,
            'notes' => trim($_POST['notes'] ?? ''),
            'performed_by' => $currentUser['id']
        ];

        // Handle different pricing modes
        if ($pricingMode === 'per_tooth') {
            // Simple per-tooth pricing
            $treatmentData['unit_price'] = floatval($_POST['unit_price']);
        } elseif ($pricingMode === 'total') {
            // Total price divided among teeth - use calculated per-tooth price
            $treatmentData['unit_price'] = floatval($_POST['upper_unit_price'] ?: 0);
        } elseif ($pricingMode === 'separate') {
            // Separate prices for upper and lower arches
            $treatmentData['upper_unit_price'] = floatval($_POST['upper_unit_price'] ?: 0);
            $treatmentData['lower_unit_price'] = floatval($_POST['lower_unit_price'] ?: 0);
            // Set default unit_price as fallback (not used when separate prices are set)
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
        $result = $caseManager->addArchTreatment($caseId, $treatmentData, $archType);
        if ($result['success']) {
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
    } elseif ($action === 'request_payment' && !$isAdmin) {
        $amount = floatval(normalize_localized_digits($_POST['amount'] ?? 0));
        if ($amount <= 0) {
            case_view_fail($caseId, 'بڕی پارە دەبێت گەورەتر بێت لە سفر', 'paymentDialog');
        }
        // Check if this is an installment payment
        if (!empty($_POST['installment_id'])) {
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
            // Balance validation (incl. already-pending requests) is enforced
            // inside createRequest() — it returns a precise message if the
            // amount exceeds the still-open balance.
            // UNIFIED PAYMENT ENGINE: createRequest handles payment, auto-allocation happens on approval
            $data = [
                'case_id' => $caseId,
                'payment_type' => $_POST['payment_type'],
                'amount' => $amount,
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'notes' => trim($_POST['notes'] ?? ''),
                'received_by' => $currentUser['id']
            ];
            $result = $paymentManager->createRequest($data);
            if (empty($result['success'])) {
                case_view_fail($caseId, $result['message'] ?? 'داواکاری پارەدان سەرنەکەوت', 'paymentDialog');
            }

            // Unified workflow: a payment request also puts the case in the
            // accountant's queue and notifies them (previously the request was
            // invisible to the accountant — only admins were pinged).
            if (!empty($result['success'])) {
                $requestNote = 'داواکاری پارەدان لە دکتۆر: ' . number_format((float)$data['amount']);
                $existingReport = $db->fetch(
                    "SELECT id, status, sent_at, processed_at, notes FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1",
                    ['cid' => $caseId]
                );
                if (empty($existingReport) || ($existingReport['status'] ?? '') === 'processed') {
                    create_accounting_report($db, $caseId, $currentUser['id'], $requestNote);
                } else {
                    append_to_accounting_report($db, $existingReport, $requestNote);
                }
                require_once __DIR__ . '/../classes/Notification.php';
                (new Notification())->notifyCaseCompleted(
                    $case['patient_name'] ?? '',
                    $case['doctor_name'] ?? '',
                    $caseId,
                    (int)($case['patient_id'] ?? 0),
                    $requestNote,
                    null,
                    (int)$currentUser['id']
                );
            }
        }
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&payment_requested=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'paymentDialog');
    } elseif ($action === 'direct_payment' && $isAdmin) {
        $amount = floatval(normalize_localized_digits($_POST['amount'] ?? 0));
        if ($amount <= 0) {
            case_view_fail($caseId, 'بڕی پارە دەبێت گەورەتر بێت لە سفر', 'paymentDialog');
        }
        // Check if this is an installment payment
        if (!empty($_POST['installment_id'])) {
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
            if ($amount > $caseRemaining + 0.01) {
                case_view_fail($caseId, 'بڕی پارە لە ماوەی کەیسەکە زیاترە (' . number_format($caseRemaining) . ')', 'paymentDialog');
            }
            // UNIFIED PAYMENT ENGINE: directPayment handles payment + auto-allocation internally
            $data = [
                'case_id' => $caseId,
                'payment_type' => $_POST['payment_type'],
                'amount' => $amount,
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'notes' => trim($_POST['notes'] ?? '')
            ];
            $result = $paymentManager->directPayment($data, $currentUser['id']);

            // Store allocation details for display if returned
            if ($result['success'] && isset($result['allocation'])) {
                $_SESSION['last_allocation'] = $result['allocation'];
            }
        }
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&payment_added=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'paymentDialog');
    } elseif ($action === 'create_installment') {
        $totalAmount = max(0, floatval(normalize_localized_digits($_POST['total_amount'] ?? 0)));
        $requestedDownPayment = min(max(0, floatval(normalize_localized_digits($_POST['down_payment'] ?? 0))), $totalAmount);
        $planNotes = trim($_POST['notes'] ?? '');
        $numberOfInstallments = max(1, intval(normalize_localized_digits($_POST['number_of_installments'] ?? 1)));
        $data = [
            'case_id' => $caseId,
            'total_amount' => $totalAmount,
            'down_payment' => 0,
            'number_of_installments' => $numberOfInstallments,
            'start_date' => $_POST['start_date'],
            'notes' => $requestedDownPayment > 0
                ? trim($planNotes . "\nپێشەکی داواکراو لە ژمێریار: " . number_format($requestedDownPayment))
                : $planNotes,
            'created_by' => $currentUser['id']
        ];
        $result = $installmentManager->createPlan($data);
        if ($result['success']) {
            if ($requestedDownPayment > 0) {
                $accountingNotes = implode("\n", [
                    'داواکاری وەرگرتنی پێشەکی بۆ پلانی قیست',
                    'پێشەکی داواکراو: ' . number_format($requestedDownPayment),
                    'کۆی پلان: ' . number_format($totalAmount),
                    'ژمارەی قیستەکان: ' . $numberOfInstallments,
                    'دەستپێک: ' . ($_POST['start_date'] ?? date('Y-m-d')),
                    $planNotes !== '' ? 'تێبینی: ' . $planNotes : ''
                ]);
                $existing = $db->fetch("SELECT id, status, sent_at, processed_at, notes FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
                if (empty($existing) || ($existing['status'] ?? '') === 'processed') {
                    create_accounting_report($db, $caseId, $currentUser['id'], $accountingNotes);
                } elseif (!empty($existing) && in_array($existing['status'], ['pending', 'reviewed'], true)) {
                    append_to_accounting_report($db, $existing, $accountingNotes);
                }
                // Bell notification for accounting — same as the other send paths
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
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&installment_created=1' . ($requestedDownPayment > 0 ? '&sent_to_accounting=1' : ''));
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'installmentDialog');
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
            // counts, exactly like a regular payment. Route it to the station +
            // pending-reports so the accountant sees it.
            $result = $installmentManager->requestFlexiblePayment($planId, $data, $currentUser['id']);
            if (!empty($result['success'])) {
                $requestNote = 'داواکاری پارەدانی قیست لە دکتۆر: ' . number_format($flexAmount);
                $existingReport = $db->fetch(
                    "SELECT id, status, sent_at, processed_at, notes FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1",
                    ['cid' => $caseId]
                );
                if (empty($existingReport) || ($existingReport['status'] ?? '') === 'processed') {
                    create_accounting_report($db, $caseId, $currentUser['id'], $requestNote);
                } else {
                    append_to_accounting_report($db, $existingReport, $requestNote);
                }
                require_once __DIR__ . '/../classes/Notification.php';
                (new Notification())->notifyCaseCompleted(
                    $case['patient_name'] ?? '',
                    $case['doctor_name'] ?? '',
                    $caseId,
                    (int)($case['patient_id'] ?? 0),
                    $requestNote,
                    null,
                    (int)$currentUser['id']
                );
                header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&payment_requested=1');
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
    } elseif ($action === 'delete_treatment') {
        $treatmentId = intval($_POST['treatment_id']);
        $result = $caseManager->deleteTreatment($caseId, $treatmentId);
        if ($result['success']) {
            if (($_POST['ajax'] ?? '') === '1') {
                case_view_ajax_success($caseManager, $caseId);
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
        $result = $caseManager->updateTreatment($caseId, $treatmentId, $data);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&treatment_updated=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '');
    } elseif ($action === 'update_grouped_treatment') {
        // Update multiple treatments at once (grouped by treatment name).
        // Deletes the old rows and creates new ones with the updated
        // teeth/quantity/price — ALL inside one transaction, so a failure
        // mid-way can never wipe the doctor's treatments.
        $oldTreatmentIds = $_POST['old_treatment_ids'] ?? [];
        $catalogTreatmentId = intval($_POST['treatment_id']) ?: null;
        $treatmentName = trim($_POST['treatment_name']);
        $newQuantity = max(1, intval($_POST['new_quantity']) ?: 1);
        $newUnitPrice = max(0, floatval($_POST['new_unit_price']) ?: 0);
        $newToothNumbers = json_decode($_POST['new_tooth_numbers'] ?? '[]', true);

        if ($treatmentName === '') {
            case_view_fail($caseId, 'ناوی چارەسەر پێویستە');
        }
        if (!is_array($oldTreatmentIds) || empty($oldTreatmentIds)
            || !is_array($newToothNumbers) || empty($newToothNumbers) || count($newToothNumbers) > 32) {
            case_view_fail($caseId, 'تکایە لانیکەم ددانێک هەڵبژێرە');
        }
        foreach ($newToothNumbers as $toothNumber) {
            if (!is_valid_fdi_tooth($toothNumber)) {
                case_view_fail($caseId, 'ژمارەی ددان نادروستە');
            }
        }

        $treatmentData = [
            'treatment_id' => $catalogTreatmentId,
            'treatment_name' => $treatmentName,
            'quantity' => $newQuantity,
            'unit_price' => $newUnitPrice,
            'discount' => 0,
            'notes' => '',
            'performed_by' => $currentUser['id']
        ];

        $db->beginTransaction();
        $failMessage = '';
        $ok = true;

        foreach ($oldTreatmentIds as $tid) {
            $result = $caseManager->deleteTreatment($caseId, intval($tid));
            if (!$result['success']) {
                $failMessage = $result['message'] ?? '';
                $ok = false;
                break;
            }
        }

        $createCount = 0;
        if ($ok) {
            foreach ($newToothNumbers as $toothNumber) {
                $data = array_merge($treatmentData, ['tooth_number' => intval($toothNumber)]);
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
            $existing = $db->fetch("SELECT id, status, sent_at, processed_at, notes FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
            $latestCaseWorkAt = get_latest_case_work_at($db, $caseId);
            $sentToAccounting = false;
            $accountingNotes = build_procedure_log_accounting_notes($archProcedureLog, $logData);
            if (!empty($appointmentResult['created'])) {
                $accountingNotes .= "\nمەوعید زیادکرا: " . $logData['next_appointment_date'] . ' ' . $logData['next_appointment_time'];
            } elseif (!empty($logData['next_appointment_date']) && !empty($logData['next_appointment_time'])) {
                $accountingNotes .= "\nمەوعید زیادنەکرا: " . ($appointmentResult['message'] ?: 'کێشەیەک ڕوویدا');
            }
            if (can_send_case_to_accounting($existing, $latestCaseWorkAt)) {
                create_accounting_report($db, $caseId, $currentUser['id'], $accountingNotes);
                $sentToAccounting = true;
            } elseif (!empty($existing) && in_array($existing['status'], ['pending', 'reviewed'], true)) {
                append_to_accounting_report($db, $existing, $accountingNotes);
                $sentToAccounting = true;
            }
            if ($sentToAccounting) {
                // Every added work (first or later) pings accounting — the report
                // row stays ONE per case; the notes accumulate all the works
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
            }
            $appointmentFlag = !empty($appointmentResult['created']) ? '&appointment_created=1' : ((!empty($logData['next_appointment_date']) && !empty($logData['next_appointment_time'])) ? '&appointment_conflict=1' : '');
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&log_added=1' . ($sentToAccounting ? '&sent_to_accounting=1' : '&accounting_already=1') . $appointmentFlag . '#tab-logs');
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
    } elseif ($action === 'apply_discount') {
        // Apply discount to case (percentage or fixed amount)
        $discountType = $_POST['discount_type'] ?? 'fixed';
        $discountValue = floatval(normalize_localized_digits($_POST['discount_value'] ?? 0));

        // Validation
        $case = $caseManager->getById($caseId);
        $totalAmount = (float)$case['total_amount'];
        // The discount may never exceed what is still UNPAID — otherwise the
        // remaining goes negative while money already collected stays collected.
        // (Same rule the accountant's report-detail page enforces.)
        $maxDiscount = max(0, $totalAmount - (float)$case['paid_amount']);
        $calculatedDiscount = $discountType === 'percentage'
            ? $totalAmount * ($discountValue / 100)
            : $discountValue;

        if ($discountType === 'percentage' && ($discountValue < 0 || $discountValue > 100)) {
            case_view_fail($caseId, 'ڕێژەی سەدی دەبێت لە نێوان 0 و 100 دا بێت', 'discountDialog');
        }
        if ($calculatedDiscount < 0 || $calculatedDiscount > $maxDiscount + 0.01) {
            case_view_fail($caseId, 'بڕی داشکاندن ناتوانێت لە بڕی نەدراو (' . number_format($maxDiscount) . ') زیاتر بێت', 'discountDialog');
        }

        $result = $caseManager->applyDiscount($caseId, $discountType, $discountValue);
        if ($result['success']) {
            // Keep an active installment plan in sync with the new balance —
            // same behavior as the accountant's discount flow.
            $activePlan = $installmentManager->getPlanByCase($caseId);
            if ($activePlan) {
                $updatedCase = $caseManager->getById($caseId);
                $newRemaining = max(0, (float)$updatedCase['total_amount'] - (float)$updatedCase['paid_amount'] - (float)$updatedCase['discount_amount']);
                $installmentManager->recalculateAfterDiscount($activePlan['id'], $newRemaining);
            }
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&discount_applied=1');
            exit;
        }
        case_view_fail($caseId, $result['message'] ?? '', 'discountDialog');
    } elseif ($action === 'send_to_accounting') {
        $existing = $db->fetch("SELECT id, status, sent_at, processed_at FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
        $latestCaseWorkAt = get_latest_case_work_at($db, $caseId);
        if (!can_send_case_to_accounting($existing, $latestCaseWorkAt)) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&accounting_already=1');
            exit;
        }
        $db->insert('accounting_reports', [
            'case_id' => $caseId,
            'sent_by' => $currentUser['id'],
            'status'  => 'pending',
            'notes'   => trim($_POST['notes'] ?? ''),
            'sent_at' => date('Y-m-d H:i:s')
        ]);

        // Tell accountants + admins the case report is waiting for them
        require_once __DIR__ . '/../classes/Notification.php';
        (new Notification())->notifyCaseCompleted(
            $case['patient_name'] ?? '',
            $case['doctor_name'] ?? '',
            $caseId,
            (int)($case['patient_id'] ?? 0),
            trim($_POST['notes'] ?? ''),
            null,
            (int)$currentUser['id']
        );

        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&sent_to_accounting=1');
        exit;
    } elseif ($action === 'resend_to_accounting') {
        // Follow-up note / resend to the accountant AFTER a case was already
        // sent. Requires a comment (a resend with nothing to add is pointless).
        // Appends to the open report (or opens a fresh one if the last was
        // processed) and re-pings the accountant — the "second notification".
        $note = trim($_POST['notes'] ?? '');
        if ($note === '') {
            case_view_fail($caseId, 'تکایە تێبینیەک بنووسە بۆ ناردن بۆ ژمێریار', 'resendAccountingDialog');
        }
        $existing = $db->fetch("SELECT id, status, sent_at, processed_at, notes FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
        if (empty($existing) || ($existing['status'] ?? '') === 'processed') {
            // Nothing open — start a fresh report carrying the note
            create_accounting_report($db, $caseId, $currentUser['id'], $note);
        } else {
            // Open report (pending/reviewed) — append the note and bump sent_at
            append_to_accounting_report($db, $existing, $note);
        }
        require_once __DIR__ . '/../classes/Notification.php';
        (new Notification())->notifyCaseCompleted(
            $case['patient_name'] ?? '',
            $case['doctor_name'] ?? '',
            $caseId,
            (int)($case['patient_id'] ?? 0),
            $note,
            null,
            (int)$currentUser['id']
        );
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
    }
}

// Check latest accounting report and whether newer billable work needs a fresh send.
$accountingReport = $db->fetch("SELECT id, status, sent_at, processed_at FROM accounting_reports WHERE case_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $caseId]);
$latestCaseWorkAt = get_latest_case_work_at($db, $caseId);
$canSendToAccounting = can_send_case_to_accounting($accountingReport, $latestCaseWorkAt);

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

$remaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];

// Pending payment requests awaiting approval. These do NOT count as paid, but
// they DO reserve part of the balance — so a doctor can't stack requests past
// the total, and the "collect payment" button hides once the outstanding
// balance is fully covered by pending requests.
$pendingPaymentsTotal = 0.0;
foreach ($payments as $pay) {
    if (($pay['status'] ?? '') === 'pending') {
        $pendingPaymentsTotal += (float)$pay['amount'];
    }
}
$unrequestedBalance = max(0, $remaining - $pendingPaymentsTotal); // still needs a request
$canRequestPayment = $case['status'] === 'open' && $unrequestedBalance > 0 && !$installmentPlan;
?>

<style>
/* Windows 10 Style Case View */

/* Windows 10 Style Panels */
.panel {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
    overflow: hidden;
    margin-bottom: 16px;
    background: white;
    transition: all 0.3s ease;
}
.panel:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
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

/* Windows 10 Style Tabs */
.tabs-container {
    display: flex;
    gap: 4px;
    background: #f5f5f5;
    padding: 6px;
    border-radius: 10px 10px 0 0;
    border-bottom: 2px solid #e0e0e0;
}
.tab {
    flex: 1;
    padding: 12px 16px;
    background: transparent;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #546e7a;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.tab:hover {
    background: rgba(255, 255, 255, 0.6);
    color: #0078d4;
}
.tab.active {
    background: white;
    color: #0078d4;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.tab i {
    font-size: 15px;
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

.panel {
    animation: fadeIn 0.3s ease;
}

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
.tooth-tooltip {
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
    opacity: 0;
    visibility: hidden;
    transition: all 0.15s;
    z-index: 1000;
    margin-bottom: 4px;
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
.tooth:hover .tooth-tooltip {
    opacity: 1;
    visibility: visible;
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
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    box-sizing: border-box;
    padding: 18px 22px 16px;
    background: linear-gradient(145deg, #ffffff 0%, #f9fbfe 62%, #f2f7fc 100%);
    border: 1px solid #dfe8f1;
    border-radius: 16px;
    box-shadow: 0 10px 28px rgba(36, 66, 97, 0.07), inset 0 1px 0 rgba(255,255,255,0.9);
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
.smart-dental-chart .teeth-row {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-width: 964px;
}
.smart-dental-chart .quadrant {
    display: flex;
    align-items: flex-start;
    gap: 4px;
    padding: 3px 0 5px;
    background: transparent;
    border-radius: 0;
}
.smart-dental-chart .quadrant-divider {
    width: 1px;
    height: 102px;
    margin: 0 clamp(10px, 1.15vw, 18px);
    background: repeating-linear-gradient(to bottom, #cfd9e5 0 5px, transparent 5px 10px);
    border-radius: 1px;
    opacity: 0.96;
}

/* Tooth proportions intentionally vary by anatomy instead of making every
   tooth look like the same icon. */
.smart-dental-chart .tooth {
    --tooth-width: 56px;
    --tooth-art-width: 48px;
    width: var(--tooth-width);
    min-width: var(--tooth-width);
    min-height: 112px;
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
    transition: transform 160ms ease, background 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
    -webkit-tap-highlight-color: transparent;
}
.smart-dental-chart .tooth--incisor { --tooth-width: 45px; --tooth-art-width: 42px; }
.smart-dental-chart .tooth--canine { --tooth-width: 50px; --tooth-art-width: 44px; }
.smart-dental-chart .tooth--premolar { --tooth-width: 54px; --tooth-art-width: 47px; }
.smart-dental-chart .tooth--molar { --tooth-width: 59px; --tooth-art-width: 51px; }
.smart-dental-chart .tooth:hover {
    transform: translateY(-3px);
    background: #f5f9ff;
    border-color: #c9dbf1;
    box-shadow: 0 9px 18px rgba(39, 87, 136, 0.14);
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
    height: 82px;
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
@media (max-width: 767px) {
    .smart-dental-chart { padding: 13px 10px 12px; border-radius: 13px; }
    .smart-dental-chart .jaw-section { padding-inline: 2px; }
    .smart-dental-chart .jaw-label { font-size: 12px; }
    .smart-dental-chart .jaw-label::before,
    .smart-dental-chart .jaw-label::after { width: 34px; }
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
    'treatments_deleted'   => intval($_GET['treatments_deleted'] ?? 0) . ' چارەسەر سڕایەوە',
    'payment_added'        => 'پارەدان تۆمارکرا',
    'payment_requested'    => 'داواکاری پارەدان نێردرا بۆ پەسەندکردن',
    'installment_created'  => 'پلانی قیست دروستکرا',
    'installment_paid'     => 'پارەدانی قیست تۆمارکرا',
    'month_skipped'        => 'مانگ ڕاپەڕێندرا - ماوە گوازرایەوە بۆ مانگی داهاتوو',
    'log_added'            => 'کارەکە تۆمارکرا',
    'log_updated'          => 'کارەکە نوێکرایەوە',
    'log_deleted'          => 'کارەکە سڕایەوە',
    'appointment_created'  => 'مەوعیدی داهاتوو زیادکرا',
    'discount_applied'     => 'داشکاندن جێبەجێکرا',
    'case_closed_ok'       => 'کەیسەکە داخرا',
    'case_reopened'        => 'کەیسەکە کرایەوە',
    'note_sent'            => 'تێبینی بۆ ژمێریار نێردرا',
];
foreach ($successFlags as $flag => $message):
    if (isset($_GET[$flag])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> <?= $message ?></div>
<?php endif; endforeach; ?>

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
                    </p>
                </div>
            </div>
            <div class="d-flex gap-2 align-center flex-wrap">
                <?php if ($canRequestPayment): ?>
                <button class="btn btn-success" style="padding: 8px 16px;" onclick="openDialog('paymentDialog')" title="وەرگرتنی پارە (Alt+P)">
                    <i class="fas fa-money-bill"></i> پارەدان
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
                <a href="<?= APP_URL ?>/cases/receipt.php?id=<?= $caseId ?>" target="_blank" class="btn btn-primary" style="padding: 8px 16px;" title="چاپکردنی وەسڵ">
                    <i class="fas fa-print"></i> چاپکردنی وەسڵ
                </a>
                <?php // Accounting control — ONE per role. Doctors use the green
                      // "complete case" floating button (bottom); admins send here. ?>
                <?php if ($isAdmin): ?>
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

        <!-- Key figures at a glance -->
        <div class="case-header-stats">
            <div class="case-stat">
                <span class="case-stat-label">کۆی گشتی</span>
                <strong class="case-stat-value" data-case-bind="total_amount"><?= number_format($case['total_amount']) ?></strong>
            </div>
            <div class="case-stat">
                <span class="case-stat-label">پارەی دراو</span>
                <strong class="case-stat-value" style="color: var(--success-color, #107c10);" data-case-bind="paid_amount"><?= number_format($case['paid_amount']) ?></strong>
            </div>
            <?php if ($case['discount_amount'] > 0): ?>
            <div class="case-stat">
                <span class="case-stat-label">داشکاندن</span>
                <strong class="case-stat-value" style="color: #f57c00;" data-case-bind="discount_amount"><?= number_format($case['discount_amount']) ?></strong>
            </div>
            <?php endif; ?>
            <div class="case-stat <?= $remaining > 0 ? 'case-stat-danger' : 'case-stat-success' ?>">
                <span class="case-stat-label">ماوە</span>
                <strong class="case-stat-value" data-case-bind="remaining"><?= number_format(max(0, $remaining)) ?></strong>
            </div>
            <div class="case-stat">
                <span class="case-stat-label"><i class="fas fa-calendar-alt"></i> مەوعیدی داهاتوو</span>
                <strong class="case-stat-value" style="font-size: 14px;">
                    <?php if ($nextAppointment): ?>
                        <?= date('Y/m/d', strtotime($nextAppointment['appointment_date'])) ?>
                        - <?= date('H:i', strtotime($nextAppointment['appointment_time'])) ?>
                    <?php else: ?>
                        <span style="color: var(--text-secondary, #757575); font-weight: 400;">نییە</span>
                    <?php endif; ?>
                </strong>
            </div>
        </div>
    </div>
</div>

<style>
.case-header-stats {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border-light, #eee);
}
.case-stat {
    flex: 1;
    min-width: 120px;
    background: var(--bg-main, #f8f9fa);
    border: 1px solid var(--border-light, #e8e8e8);
    border-radius: 10px;
    padding: 8px 14px;
    text-align: center;
}
.case-stat-label {
    display: block;
    font-size: 11px;
    color: var(--text-secondary, #757575);
    margin-bottom: 2px;
}
.case-stat-value {
    font-size: 17px;
}
.case-stat-danger {
    background: #fdecea;
    border-color: #f5c6cb;
}
.case-stat-danger .case-stat-value { color: #c62828; }
.case-stat-success {
    background: #e8f5e9;
    border-color: #a5d6a7;
}
.case-stat-success .case-stat-value { color: #2e7d32; }
@media (max-width: 767px) {
    .case-stat { min-width: calc(50% - 10px); }
    .tabs-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .tabs-container .tab { flex: 0 0 auto; white-space: nowrap; }
}
</style>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 1fr 320px; gap: 24px;">
    <div>
        <!-- Tabs -->
        <div class="panel">
            <div class="tabs-container">
                <button class="tab active" onclick="showTab('chart', this)">
                    <i class="fas fa-tooth"></i> چارتی ددان
                </button>
                <button class="tab" onclick="showTab('treatments', this)">
                    <i class="fas fa-syringe"></i> چارەسەرەکان (<?= count($caseTreatments) ?>)
                </button>
                <button class="tab" onclick="showTab('payments', this)">
                    <i class="fas fa-money-bill"></i> پارەدان (<?= count($payments) ?>)
                </button>
                <button class="tab" onclick="showTab('images', this)">
                    <i class="fas fa-images"></i> وێنەکان (<?= count($caseImages) ?>/6)
                </button>
                <?php if ($isArchCase): ?>
                <button class="tab" onclick="showTab('logs', this)">
                    <i class="fas fa-clipboard-list"></i> کارە ئەنجامدراوەکان (<?= count($procedureLogs) ?>)
                </button>
                <?php endif; ?>
            </div>

            <!-- Dental Chart Tab -->
            <div class="tab-content active" id="tab-chart" style="display: block;">
                <div class="panel-body" style="padding: 12px;">
                    <div class="d-flex justify-between align-center mb-2">
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
                        <?php if ($case['status'] === 'open'): ?>
                        <button class="btn btn-primary btn-sm" onclick="<?= ($case['scope_type'] ?? 'tooth') === 'arch' ? 'openArchTreatmentDialog()' : 'openDialog(\'addTreatmentDialog\')' ?>">
                            <i class="fas fa-plus"></i> زیادکردن
                        </button>
                        <?php else: ?>
                        <button class="btn btn-secondary btn-sm" disabled title="کەیسەکە داخراوە">
                            <i class="fas fa-plus"></i> زیادکردن
                        </button>
                        <?php endif; ?>
                    </div>

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
                        <!-- Upper Jaw -->
                        <div class="jaw-section jaw-section--upper">
                            <div class="jaw-label">سەرەوە</div>
                            <div class="teeth-row">
                                <div class="quadrant">
                                    <?php foreach ($teeth['upper_left'] as $num): ?>
                                    <div class="tooth"
                                         data-tooth="<?= $num ?>"
                                         data-palmer="<?= $palmerNumbers[$num] ?>"
                                         data-quadrant="UL"
                                         onclick="showToothDetail(<?= $num ?>)">
                                        <span class="tooth-number"><?= $palmerNumbers[$num] ?></span>
                                        <span class="tooth-svg"></span>
                                        <div class="tooth-tooltip"><?= htmlspecialchars($toothNames[$num] ?? "ددان $num") ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="quadrant-divider"></div>
                                <div class="quadrant">
                                    <?php foreach ($teeth['upper_right'] as $num): ?>
                                    <div class="tooth"
                                         data-tooth="<?= $num ?>"
                                         data-palmer="<?= $palmerNumbers[$num] ?>"
                                         data-quadrant="UR"
                                         onclick="showToothDetail(<?= $num ?>)">
                                        <span class="tooth-number"><?= $palmerNumbers[$num] ?></span>
                                        <span class="tooth-svg"></span>
                                        <div class="tooth-tooltip"><?= htmlspecialchars($toothNames[$num] ?? "ددان $num") ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Lower Jaw -->
                        <div class="jaw-section jaw-section--lower">
                            <div class="jaw-label">خوارەوە</div>
                            <div class="teeth-row">
                                <div class="quadrant">
                                    <?php foreach ($teeth['lower_left'] as $num): ?>
                                    <div class="tooth lower"
                                         data-tooth="<?= $num ?>"
                                         data-palmer="<?= $palmerNumbers[$num] ?>"
                                         data-quadrant="LL"
                                         onclick="showToothDetail(<?= $num ?>)">
                                        <span class="tooth-number"><?= $palmerNumbers[$num] ?></span>
                                        <span class="tooth-svg"></span>
                                        <div class="tooth-tooltip"><?= htmlspecialchars($toothNames[$num] ?? "ددان $num") ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="quadrant-divider"></div>
                                <div class="quadrant">
                                    <?php foreach ($teeth['lower_right'] as $num): ?>
                                    <div class="tooth lower"
                                         data-tooth="<?= $num ?>"
                                         data-palmer="<?= $palmerNumbers[$num] ?>"
                                         data-quadrant="LR"
                                         onclick="showToothDetail(<?= $num ?>)">
                                        <span class="tooth-number"><?= $palmerNumbers[$num] ?></span>
                                        <span class="tooth-svg"></span>
                                        <div class="tooth-tooltip"><?= htmlspecialchars($toothNames[$num] ?? "ددان $num") ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

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
                        <?php if ($case['status'] === 'open'): ?>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-primary" onclick="openDialog('addTreatmentDialog')">
                                <i class="fas fa-plus"></i> زیادکردنی چارەسەر
                            </button>
                            <button class="btn btn-success" onclick="showInlineAddRow()" title="زیادکردنی خێرا لە خشتەدا">
                                <i class="fas fa-edit"></i> زیادکردنی خێرا
                            </button>
                        </div>
                        <?php else: ?>
                        <button class="btn btn-secondary" disabled title="کەیسەکە داخراوە">
                            <i class="fas fa-plus"></i> زیادکردنی چارەسەر
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($caseTreatments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-syringe empty-state-icon"></i>
                        <p class="empty-state-title">هیچ چارەسەرێک نییە</p>
                    </div>
                    <?php else: ?>
                    <div id="treatmentTableView">
                    <?php
                    // Group treatments by treatment name
                    $groupedTreatments = [];
                    foreach ($caseTreatments as $ct) {
                        $key = $ct['treatment_name'] . '_' . $ct['unit_price'];
                        if (!isset($groupedTreatments[$key])) {
                            $groupedTreatments[$key] = [
                                'treatment_name' => $ct['treatment_name'],
                                'unit_price' => $ct['unit_price'],
                                'upper_left' => [],
                                'upper_right' => [],
                                'lower_left' => [],
                                'lower_right' => [],
                                'upper_left_fdi' => [],
                                'upper_right_fdi' => [],
                                'lower_left_fdi' => [],
                                'lower_right_fdi' => [],
                                'total_quantity' => 0,
                                'total_price' => 0,
                                'discount' => 0,
                                'treatment_ids' => []
                            ];
                        }

                        // Determine quadrant
                        if ($ct['tooth_number']) {
                            $quadrant = $dentalChart->getQuadrant($ct['tooth_number']);
                            $palmerNum = $palmerNumbers[$ct['tooth_number']];

                            if ($quadrant === 'UL') {
                                $groupedTreatments[$key]['upper_left'][] = $palmerNum;
                                $groupedTreatments[$key]['upper_left_fdi'][] = $ct['tooth_number'];
                            } elseif ($quadrant === 'UR') {
                                $groupedTreatments[$key]['upper_right'][] = $palmerNum;
                                $groupedTreatments[$key]['upper_right_fdi'][] = $ct['tooth_number'];
                            } elseif ($quadrant === 'LL') {
                                $groupedTreatments[$key]['lower_left'][] = $palmerNum;
                                $groupedTreatments[$key]['lower_left_fdi'][] = $ct['tooth_number'];
                            } elseif ($quadrant === 'LR') {
                                $groupedTreatments[$key]['lower_right'][] = $palmerNum;
                                $groupedTreatments[$key]['lower_right_fdi'][] = $ct['tooth_number'];
                            }
                        }

                        $groupedTreatments[$key]['total_quantity'] += $ct['quantity'];
                        $groupedTreatments[$key]['total_price'] += $ct['final_price'];
                        $groupedTreatments[$key]['discount'] += $ct['discount'];
                        $groupedTreatments[$key]['treatment_ids'][] = $ct['id'];
                    }
                    ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th>ناوی چارەسەر</th>
                                    <th style="text-align: center; width: 80px;">Upper Left</th>
                                    <th style="text-align: center; width: 80px;">Upper Right</th>
                                    <th style="text-align: center; width: 80px;">Lower Left</th>
                                    <th style="text-align: center; width: 80px;">Lower Right</th>
                                    <th style="text-align: center; width: 60px;">ژمارە</th>
                                    <th style="text-align: right;">نرخی یەکە</th>
                                    <th style="text-align: right;">کۆی نرخ</th>
                                    <?php if ($case['status'] === 'open'): ?>
                                    <th style="width: 120px;">کردارەکان</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNum = 1; ?>
                                <?php foreach ($groupedTreatments as $gt): ?>
                                <tr>
                                    <td style="text-align: center; font-weight: 600;"><?= $rowNum++ ?></td>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($gt['treatment_name']) ?></td>
                                    <td style="text-align: center; background: <?= !empty($gt['upper_left']) ? '#E8F5E9' : '#f5f5f5' ?>; font-weight: 600;">
                                        <?= !empty($gt['upper_left']) ? implode(', ', $gt['upper_left']) : '' ?>
                                    </td>
                                    <td style="text-align: center; background: <?= !empty($gt['upper_right']) ? '#E3F2FD' : '#f5f5f5' ?>; font-weight: 600;">
                                        <?= !empty($gt['upper_right']) ? implode(', ', $gt['upper_right']) : '' ?>
                                    </td>
                                    <td style="text-align: center; background: <?= !empty($gt['lower_left']) ? '#F3E5F5' : '#f5f5f5' ?>; font-weight: 600;">
                                        <?= !empty($gt['lower_left']) ? implode(', ', $gt['lower_left']) : '' ?>
                                    </td>
                                    <td style="text-align: center; background: <?= !empty($gt['lower_right']) ? '#FFF3E0' : '#f5f5f5' ?>; font-weight: 600;">
                                        <?= !empty($gt['lower_right']) ? implode(', ', $gt['lower_right']) : '' ?>
                                    </td>
                                    <td style="text-align: center; font-weight: 600;"><?= number_format($gt['total_quantity'], 2) ?></td>
                                    <td style="text-align: right;"><?= number_format($gt['unit_price'], 2) ?></td>
                                    <td style="text-align: right; font-weight: 700; color: #d32f2f;"><?= number_format($gt['total_price'], 2) ?></td>
                                    <?php if ($case['status'] === 'open'): ?>
                                    <td style="text-align: center;">
                                        <button type="button" class="btn btn-sm btn-primary" style="padding: 4px 8px; margin-left: 4px;"
                                                onclick="editGroupedTreatment(<?= htmlspecialchars(json_encode($gt)) ?>)"
                                                title="دەستکاریکردن (خێرا)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('ئایا دڵنیایت لە سڕینەوەی هەموو چارەسەرەکانی <?= htmlspecialchars($gt['treatment_name']) ?>؟')">
                                            <input type="hidden" name="action" value="delete_grouped_treatments">
                                            <?php foreach ($gt['treatment_ids'] as $tid): ?>
                                            <input type="hidden" name="treatment_ids[]" value="<?= $tid ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($case['status'] === 'open'): ?>
                                <!-- Inline Add Row -->
                                <tr id="inline-add-row" style="background: #f9f9f9; display: none;">
                                    <td style="text-align: center;"><i class="fas fa-plus" style="color: #0078D4;"></i></td>
                                    <td>
                                        <select id="inline-treatment" class="form-control" style="width: 100%; font-size: 12px;" onchange="inlineUpdatePrice()">
                                            <option value="">چارەسەر هەڵبژێرە...</option>
                                            <?php foreach ($toothTreatments as $t): ?>
                                            <option value="<?= $t['id'] ?>" data-price="<?= $t['default_price'] ?>" data-name="<?= htmlspecialchars($t['treatment_name']) ?>">
                                                <?= htmlspecialchars($t['treatment_name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td style="text-align: center; background: #E8F5E9; cursor: pointer; padding: 8px;" onclick="openToothSelector('UL')" id="cell-UL">
                                        <span id="teeth-UL" style="font-weight: 600; font-size: 12px;"></span>
                                    </td>
                                    <td style="text-align: center; background: #E3F2FD; cursor: pointer; padding: 8px;" onclick="openToothSelector('UR')" id="cell-UR">
                                        <span id="teeth-UR" style="font-weight: 600; font-size: 12px;"></span>
                                    </td>
                                    <td style="text-align: center; background: #F3E5F5; cursor: pointer; padding: 8px;" onclick="openToothSelector('LL')" id="cell-LL">
                                        <span id="teeth-LL" style="font-weight: 600; font-size: 12px;"></span>
                                    </td>
                                    <td style="text-align: center; background: #FFF3E0; cursor: pointer; padding: 8px;" onclick="openToothSelector('LR')" id="cell-LR">
                                        <span id="teeth-LR" style="font-weight: 600; font-size: 12px;"></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="number" id="inline-quantity" class="form-control" value="1" min="1" step="1" style="width: 60px; font-size: 12px; text-align: center;" onchange="inlineCalculateTotal()">
                                    </td>
                                    <td style="text-align: right;">
                                        <input type="number" id="inline-price" class="form-control" value="0" min="0" step="0.01" style="width: 100px; font-size: 12px; text-align: right;" onchange="inlineCalculateTotal()">
                                    </td>
                                    <td style="text-align: right;">
                                        <span id="inline-total" style="font-weight: 700; color: #d32f2f; font-size: 13px;">0.00</span>
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="button" class="btn btn-sm btn-success" onclick="saveInlineTreatment()" style="padding: 4px 8px; margin-right: 4px;" title="پاشەکەوت (Ctrl+Enter)">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelInlineAdd()" style="padding: 4px 8px;" title="هەڵوەشاندنەوە (Esc)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight: 700; background: var(--bg-main);">
                                    <td colspan="8" style="text-align: right;">کۆی گشتی</td>
                                    <td style="text-align: right; font-size: 16px; color: #d32f2f;" data-case-bind="total_amount" data-decimals="2"><?= number_format($case['total_amount'], 2) ?></td>
                                    <?php if ($case['status'] === 'open'): ?>
                                    <td></td>
                                    <?php endif; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
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
                            <i class="fas fa-money-bill"></i> وەرگرتنی پارە
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
                                    <td><strong><?= number_format($pay['amount']) ?></strong></td>
                                    <td><?= $pay['payment_method'] === 'cash' ? 'نەقد' : 'کارت' ?></td>
                                    <td>
                                        <?php
                                        $statuses = [
                                            'pending' => ['badge-orange', 'چاوەڕوان'],
                                            'approved' => ['badge-green', 'پەسەندکراو'],
                                            'rejected' => ['badge-red', 'ڕەتکراوە']
                                        ];
                                        $st = $statuses[$pay['status']] ?? ['', $pay['status']];
                                        ?>
                                        <span class="badge <?= $st[0] ?>"><?= $st[1] ?></span>
                                    </td>
                                    <td><?= date('Y-m-d H:i', strtotime($pay['payment_date'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if ($installmentPlan):
                        // Get flexible installment summary
                        $installmentSummary = $installmentManager->getPlanSummary($installmentPlan['id']);
                        $installmentPayments = $installmentManager->getPlanPayments($installmentPlan['id']);
                        $installmentCollectedAfterPlan = max(0, ($installmentSummary['total_paid'] ?? 0) - ($installmentSummary['down_payment'] ?? 0));
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

                    <!-- Flexible Payment Form -->
                    <?php if ($case['status'] === 'open' && $installmentSummary['remaining_balance'] > 0): ?>
                    <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                        <h6 style="margin: 0 0 12px;"><i class="fas fa-money-bill-wave"></i> <?= $isAdmin ? 'پارەدانی نەرم' : 'داواکاری پارەدانی قیست' ?></h6>
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
                                <i class="fas fa-check"></i> <?= $isAdmin ? 'پارەدان' : 'ناردنی داواکاری' ?>
                            </button>
                        </form>
                        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 8px;">
                            <i class="fas fa-info-circle"></i>
                            <?php if ($isAdmin): ?>
                            دەتوانیت هەر بڕێک بدەیت - سیستەم بەشێوەی خۆکار دابەشی دەکات بەسەر قیستەکان
                            <?php else: ?>
                            داواکارییەکە دەچێتە لای ژمێریار بۆ پەسەندکردن — دوای پەسەندکردن دابەش دەکرێت بەسەر قیستەکان
                            <?php endif; ?>
                        </div>
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
                                    $methods = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'تر'];
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
        <!-- Summary -->
        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-calculator"></i> پوختەی دارایی</h4>
            </div>
            <div class="panel-body">
                <table style="width: 100%;">
                    <tr>
                        <td class="text-secondary" style="padding: 8px 0;">کۆی گشتی</td>
                        <td style="text-align: left; font-weight: 600;" data-case-bind="total_amount"><?= number_format($case['total_amount']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-secondary" style="padding: 8px 0;">
                            داشکاندن
                            <?php if ($case['discount_value'] > 0): ?>
                                <span style="font-size: 11px; color: var(--text-muted);">
                                    (<?= $case['discount_type'] === 'percentage' ? $case['discount_value'] . '%' : number_format($case['discount_value']) ?>)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: left; color: var(--status-warning); display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                            <span><?= number_format($case['discount_amount']) ?></span>
                            <?php if ($case['status'] === 'open'): ?>
                            <button type="button" class="btn-icon-sm" onclick="openDialog('discountDialog')" title="دەستکاری داشکاندن">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-secondary" style="padding: 8px 0;">پارەی دراو</td>
                        <td style="text-align: left; color: var(--success-color);" data-case-bind="paid_amount"><?= number_format($case['paid_amount']) ?></td>
                    </tr>
                    <tr style="border-top: 2px solid var(--border-color);">
                        <td style="padding: 12px 0; font-weight: 600;">ماوە</td>
                        <td style="text-align: left; font-weight: 700; font-size: 18px; color: <?= $remaining > 0 ? 'var(--danger-color)' : 'var(--success-color)' ?>;" data-case-bind="remaining">
                            <?= number_format($remaining) ?>
                        </td>
                    </tr>
                </table>

                <?php
                // Progress measured against what is actually owed (total − discount), capped 0–100
                $effectiveTotal = max(0, (float)$case['total_amount'] - (float)$case['discount_amount']);
                if ($effectiveTotal > 0):
                    $progressPct = min(100, max(0, round(((float)$case['paid_amount'] / $effectiveTotal) * 100)));
                ?>
                <div class="mt-3">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                        <span>پێشکەوتن</span>
                        <span><?= $progressPct ?>%</span>
                    </div>
                    <div style="background: var(--border-light); border-radius: 8px; height: 10px; overflow: hidden;">
                        <div style="width: <?= $progressPct ?>%; height: 100%; background: var(--success-color);"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

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
<?php if (isset($_GET['work_added']) && $canSendToAccounting): ?>
<script>
// After adding work, nudge toward the single send flow for this role:
// doctors get the "complete case" modal (captures next appointment),
// admins get the quick send-to-accounting dialog.
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        <?php if ($isDoctor && !$isAdmin): ?>
        if (typeof openCompleteCaseDialog === 'function') openCompleteCaseDialog();
        else openDialog('sendAccountingDialog');
        <?php else: ?>
        openDialog('sendAccountingDialog');
        <?php endif; ?>
    }, 250);
});
</script>
<?php endif; ?>

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
                    <div class="treatment-form-field">
                        <label><i class="fas fa-tooth"></i> ددان</label>
                        <select name="tooth_number" id="selectedToothNumber" onchange="onDialogToothChange()">
                            <option value="">هەڵبژێرە...</option>
                            <optgroup label="سەرەوەی ڕاست (UR)">
                                <?php foreach ($teeth['upper_right'] as $num): ?>
                                <option value="<?= $num ?>"><?= $palmerNumbers[$num] ?> - <?= $toothNames[$num] ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="سەرەوەی چەپ (UL)">
                                <?php foreach ($teeth['upper_left'] as $num): ?>
                                <option value="<?= $num ?>"><?= $palmerNumbers[$num] ?> - <?= $toothNames[$num] ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="خوارەوەی چەپ (LL)">
                                <?php foreach ($teeth['lower_left'] as $num): ?>
                                <option value="<?= $num ?>"><?= $palmerNumbers[$num] ?> - <?= $toothNames[$num] ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="خوارەوەی ڕاست (LR)">
                                <?php foreach ($teeth['lower_right'] as $num): ?>
                                <option value="<?= $num ?>"><?= $palmerNumbers[$num] ?> - <?= $toothNames[$num] ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="treatment-form-field treatment-field-full">
                        <label><i class="fas fa-briefcase-medical"></i> چارەسەر <span class="required">*</span></label>
                        <div style="display: flex; gap: 6px;">
                            <!-- Hidden select keeps the existing form wiring; the picker drives it -->
                            <select name="treatment_id" id="treatmentSelect" onchange="updateTreatmentPrice()" style="display: none;">
                                <option value="">هەڵبژێرە...</option>
                                <?php foreach ($toothTreatments as $t): ?>
                                <option value="<?= $t['id'] ?>" data-price="<?= $t['default_price'] ?>" data-category="<?= htmlspecialchars($t['category'] ?? 'other') ?>" data-name="<?= htmlspecialchars($t['treatment_name']) ?>">
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
                                <span class="arch-select-count">16 ددان</span>
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
                                <span class="arch-select-count">16 ددان</span>
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
                        <label class="arch-pricing-mode-option selected" data-mode="per_tooth">
                            <input type="radio" name="pricing_mode" value="per_tooth" checked>
                            <i class="fas fa-tooth"></i>
                            <span>نرخ بۆ هەر ددانێک</span>
                        </label>
                        <label class="arch-pricing-mode-option" data-mode="total">
                            <input type="radio" name="pricing_mode" value="total">
                            <i class="fas fa-calculator"></i>
                            <span>کۆی گشتی (دابەشکراو)</span>
                        </label>
                        <label class="arch-pricing-mode-option" data-mode="separate" id="separatePriceOption" style="display: none;">
                            <input type="radio" name="pricing_mode" value="separate">
                            <i class="fas fa-divide"></i>
                            <span>نرخی جیاواز</span>
                        </label>
                    </div>
                </div>

                <!-- Per Tooth Price Section -->
                <div class="arch-pricing-section" id="perToothPricing">
                    <div class="arch-pricing-header orange">
                        <i class="fas fa-coins"></i> نرخ بۆ هەر ددانێک
                    </div>
                    <div class="arch-pricing-content">
                        <div class="arch-price-row">
                            <span class="arch-price-label">نرخی پێشنیارکراو:</span>
                            <span class="arch-price-value" id="archDefaultPriceDisplay">0</span>
                            <span class="arch-price-currency"></span>
                        </div>
                        <div class="arch-price-row">
                            <span class="arch-price-label">نرخی ڕێککەوتن:</span>
                            <input type="number" name="unit_price" id="archUnitPrice" onchange="calculateArchTotal()" placeholder="نرخ بنووسە...">
                            <span class="arch-price-currency"></span>
                            <button type="button" class="arch-use-default-btn" onclick="useArchDefaultPrice()" title="بەکارهێنانی نرخی پێشنیارکراو">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Total Price Section (divide among teeth) -->
                <div class="arch-pricing-section" id="totalPricing" style="display: none;">
                    <div class="arch-pricing-header green">
                        <i class="fas fa-hand-holding-usd"></i> کۆی نرخی ڕێککەوتن
                    </div>
                    <div class="arch-pricing-content">
                        <div class="arch-price-row">
                            <span class="arch-price-label" style="color: #2e7d32; font-weight: 600;">کۆی پارەی ڕێککەوتن:</span>
                            <input type="number" id="archTotalAgreedPrice" onchange="calculateFromTotal()" placeholder="کۆی نرخ..." class="green-input">
                            <span class="arch-price-currency"></span>
                        </div>
                        <div class="arch-price-row calculated">
                            <span class="arch-price-label">نرخی هەر ددانێک:</span>
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
                                <i class="fas fa-arrow-up"></i> سەرەوە (16 ددان):
                            </span>
                            <input type="number" id="upperArchPrice" onchange="calculateSeparateTotal()" placeholder="کۆی نرخ..." class="blue-input">
                            <span class="arch-price-currency"></span>
                        </div>
                        <div class="arch-price-row">
                            <span class="arch-price-label" style="color: #9c27b0; font-weight: 600;">
                                <i class="fas fa-arrow-down"></i> خوارەوە (16 ددان):
                            </span>
                            <input type="number" id="lowerArchPrice" onchange="calculateSeparateTotal()" placeholder="کۆی نرخ..." class="purple-input">
                            <span class="arch-price-currency"></span>
                        </div>
                    </div>
                </div>

                <!-- Hidden fields for calculated prices -->
                <input type="hidden" name="upper_unit_price" id="hiddenUpperPrice" value="">
                <input type="hidden" name="lower_unit_price" id="hiddenLowerPrice" value="">

                <!-- Arch Total Display -->
                <div class="arch-total-display" id="archTotalDisplay">
                    <span class="arch-total-label">کۆی گشتی (<span id="archTeethCountDisplay">0</span> ددان):</span>
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

<!-- Payment Dialog -->
<div class="dialog-overlay" id="paymentDialog">
    <div class="dialog payment-dialog-modern">
        <div class="payment-dialog-header">
            <h4><i class="fas fa-money-bill-wave"></i> وەرگرتنی پارە</h4>
            <button type="button" class="payment-dialog-close" onclick="closeDialog('paymentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="paymentForm">
            <input type="hidden" name="action" value="<?= $isAdmin ? 'direct_payment' : 'request_payment' ?>">
            <input type="hidden" name="installment_id" id="payment_installment_id" value="">
            <div class="payment-dialog-body">
                <!-- Remaining Balance Display -->
                <?php
                // Admin collects directly (cap = balance vs approved). A doctor
                // files a request (cap = balance minus what is already pending),
                // so two requests can't together exceed the total.
                $paymentDialogMax = $isAdmin ? $remaining : $unrequestedBalance;
                ?>
                <div class="payment-remaining-box">
                    <div class="remaining-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="remaining-info">
                        <span class="remaining-label"><?= $isAdmin ? 'بڕی ماوە' : 'بڕی داواکراوی کراوە' ?></span>
                        <span class="remaining-value"><?= number_format($paymentDialogMax) ?> <small></small></span>
                    </div>
                </div>
                <?php if (!$isAdmin && $pendingPaymentsTotal > 0): ?>
                <div style="background:#fff4ce;border:1px solid #ffc107;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:#856404;">
                    <i class="fas fa-hourglass-half"></i> داواکاری چاوەڕوان: <?= number_format($pendingPaymentsTotal) ?> — لە ماوەکە کەمکراوەتەوە
                </div>
                <?php endif; ?>

                <div class="payment-form-grid">
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
                    <div class="payment-form-field">
                        <label><i class="fas fa-coins"></i> بڕ <span class="required">*</span></label>
                        <input type="number" name="amount" id="payment_amount_input" required min="1" max="<?= max(1, $paymentDialogMax) ?>" value="<?= $paymentDialogMax > 0 ? $paymentDialogMax : '' ?>" placeholder="بڕی پارە...">
                    </div>
                    <div class="payment-form-field">
                        <label><i class="fas fa-credit-card"></i> شێواز</label>
                        <select name="payment_method">
                            <option value="cash">نەقد</option>
                            <option value="card">کارت</option>
                        </select>
                    </div>
                </div>

                <?php if ($installmentPlan): ?>
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

                <div class="payment-form-field">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <input type="text" name="notes" placeholder="تێبینی ئارەزوومەندانە...">
                </div>
            </div>
            <div class="payment-dialog-footer">
                <button type="submit" class="payment-btn-primary"><i class="fas fa-check-circle"></i> <?= $isAdmin ? 'وەرگرتن' : 'داواکاری' ?></button>
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
                        <div class="installment-field-help">ئەم بڕە وەک داواکاری بۆ ژمێریار دەنێردرێت؛ لێرە هیچ واصل/پارەدان تۆمار ناکرێت.</div>
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

<!-- Discount Dialog -->
<div class="dialog-overlay" id="discountDialog">
    <div class="dialog discount-dialog-modern">
        <div class="discount-dialog-header">
            <h4><i class="fas fa-percent"></i> داشکاندن لەسەر کۆی گشتی</h4>
            <button type="button" class="discount-dialog-close" onclick="closeDialog('discountDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="discountForm">
            <input type="hidden" name="action" value="apply_discount">
            <div class="discount-dialog-body">
                <div class="discount-summary-box">
                    <div class="discount-summary-row">
                        <span>کۆی گشتی چارەسەرەکان</span>
                        <span class="discount-amount" id="discountTotalDisplay"><?= number_format($case['total_amount']) ?></span>
                    </div>
                </div>

                <div class="discount-type-selector">
                    <label class="discount-type-option">
                        <input type="radio" name="discount_type" value="percentage" <?= ($case['discount_type'] ?? 'fixed') === 'percentage' ? 'checked' : '' ?> onchange="updateDiscountPreview()">
                        <span class="discount-type-label">
                            <i class="fas fa-percent"></i>
                            <span>ڕێژەی سەدی</span>
                        </span>
                    </label>
                    <label class="discount-type-option">
                        <input type="radio" name="discount_type" value="fixed" <?= ($case['discount_type'] ?? 'fixed') === 'fixed' ? 'checked' : '' ?> onchange="updateDiscountPreview()">
                        <span class="discount-type-label">
                            <i class="fas fa-money-bill"></i>
                            <span>بڕی دیاریکراو</span>
                        </span>
                    </label>
                </div>

                <div class="discount-input-group">
                    <label class="discount-input-label">
                        <span id="discountInputLabel">بڕی داشکاندن</span>
                        <span class="discount-input-hint" id="discountInputHint"></span>
                    </label>
                    <div class="discount-input-wrapper">
                        <input type="number"
                               name="discount_value"
                               id="discountValueInput"
                               class="discount-input"
                               value="<?= $case['discount_value'] ?? 0 ?>"
                               min="0"
                               step="0.01"
                               oninput="updateDiscountPreview()"
                               placeholder="0">
                        <span class="discount-input-suffix" id="discountSuffix"></span>
                    </div>
                </div>

                <div class="discount-preview-box">
                    <div class="discount-preview-row">
                        <span>بڕی داشکاندن</span>
                        <span class="discount-preview-value" id="discountAmountPreview">0</span>
                    </div>
                    <div class="discount-preview-row discount-preview-total">
                        <span>بڕی دوای داشکاندن</span>
                        <span class="discount-preview-value" id="discountRemainingPreview"><?= number_format($case['total_amount']) ?></span>
                    </div>
                </div>

                <div class="discount-validation-message" id="discountValidationMsg" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="discountValidationText"></span>
                </div>
            </div>
            <div class="discount-dialog-footer">
                <button type="submit" class="discount-btn-primary" id="discountSubmitBtn"><i class="fas fa-check"></i> جێبەجێکردن</button>
                <button type="button" class="discount-btn-secondary" onclick="closeDialog('discountDialog')"><i class="fas fa-times"></i> پاشگەزبوونەوە</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Discount Dialog Styles */
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

let toothTreatmentMap = <?= json_encode($toothTreatmentMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
console.log('toothTreatmentMap loaded');

// Treatment catalog for the searchable picker (id, name, category, price)
let treatmentCatalog = <?= json_encode(array_map(function ($t) {
    return [
        'id' => (int)$t['id'],
        'name' => $t['treatment_name'],
        'category' => $t['category'] ?? 'other',
        'price' => (float)$t['default_price']
    ];
}, $toothTreatments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

const CASE_IS_OPEN = <?= $case['status'] === 'open' ? 'true' : 'false' ?>;

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

function toothShapeKey(fdi) {
    const p = fdi % 10;
    if (p <= 2) return 'incisor';
    if (p === 3) return 'canine';
    if (p <= 5) return 'premolar';
    return 'molar';
}
function fdiQuadrant(fdi) {
    const q = Math.floor(fdi / 10);
    return {1: 'UR', 2: 'UL', 3: 'LL', 4: 'LR'}[q] || 'UR';
}
// Which visual side is "mesial"? Teeth are laid out palmer 1→8 from the
// outside in on each half, so quadrants 1/4 have mesial on the left.
function mesialIsLeft(fdi) {
    const q = Math.floor(fdi / 10);
    return q === 1 || q === 4;
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
    renderArchCharts();
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
    el.textContent = n;
}

function toast(type, message) {
    if (typeof showToast === 'function') showToast(type, message);
    else alert(message);
}

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

function updateSurfaceSection() {
    const sec = document.getElementById('surfaceSection');
    if (!sec) return;
    const cat = selectedTreatmentCategory();
    const hasTooth = !!document.getElementById('selectedToothNumber')?.value;
    const show = hasTooth && cat !== null && SURFACE_CATEGORIES.includes(cat);
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
    updateSurfaceSection();
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
    if (d.tooth_map) {
        toothTreatmentMap = d.tooth_map;
        renderSmartChart();
    }
    if (d.case_totals) updateCaseTotalsUI(d.case_totals);
    if (d.treatments) rebuildTreatmentsTable(d.treatments, d.case_totals);
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

// Rebuild the grouped treatments table + bar chart from a fresh treatments list
function rebuildTreatmentsTable(treatments, totals) {
    const view = document.getElementById('treatmentTableView');
    if (!view) {
        // The page rendered the empty-state (no table markup at all).
        // A one-off reload swaps it for the real table.
        window.location.reload();
        return;
    }
    const tbody = view.querySelector('tbody');
    if (!tbody) return;

    // group by treatment name + unit price (same as the server)
    const groups = {};
    const order = [];
    treatments.forEach(ct => {
        const key = ct.treatment_name + '_' + ct.unit_price;
        if (!groups[key]) {
            groups[key] = {
                treatment_name: ct.treatment_name, unit_price: ct.unit_price,
                upper_left: [], upper_right: [], lower_left: [], lower_right: [],
                upper_left_fdi: [], upper_right_fdi: [], lower_left_fdi: [], lower_right_fdi: [],
                total_quantity: 0, total_price: 0, discount: 0, treatment_ids: []
            };
            order.push(key);
        }
        const g = groups[key];
        const toothNum = parseInt(ct.tooth_number, 10);
        if (toothNum) {
            const quadKey = {UL: 'upper_left', UR: 'upper_right', LL: 'lower_left', LR: 'lower_right'}[fdiQuadrant(toothNum)];
            g[quadKey].push(palmerNumbers[toothNum] || (toothNum % 10));
            g[quadKey + '_fdi'].push(toothNum);
        }
        g.total_quantity += parseFloat(ct.quantity) || 0;
        g.total_price += parseFloat(ct.final_price) || 0;
        g.discount += parseFloat(ct.discount) || 0;
        g.treatment_ids.push(ct.id);
    });

    tbody.querySelectorAll('tr').forEach(tr => { if (tr.id !== 'inline-add-row') tr.remove(); });
    const inlineRow = document.getElementById('inline-add-row');
    const quadCells = [
        ['upper_left', '#E8F5E9'], ['upper_right', '#E3F2FD'],
        ['lower_left', '#F3E5F5'], ['lower_right', '#FFF3E0']
    ];
    let rowNum = 1;
    order.forEach(key => {
        const gt = groups[key];
        const tr = document.createElement('tr');
        let html = `<td style="text-align: center; font-weight: 600;">${rowNum++}</td>` +
            `<td style="font-weight: 600;">${escapeHtml(gt.treatment_name)}</td>`;
        quadCells.forEach(([qk, color]) => {
            const has = gt[qk].length > 0;
            html += `<td style="text-align: center; background: ${has ? color : '#f5f5f5'}; font-weight: 600;">${has ? gt[qk].join(', ') : ''}</td>`;
        });
        html += `<td style="text-align: center; font-weight: 600;">${fmt2(gt.total_quantity)}</td>` +
            `<td style="text-align: right;">${fmt2(gt.unit_price)}</td>` +
            `<td style="text-align: right; font-weight: 700; color: #d32f2f;">${fmt2(gt.total_price)}</td>`;
        if (CASE_IS_OPEN) html += '<td style="text-align: center;"></td>';
        tr.innerHTML = html;

        if (CASE_IS_OPEN) {
            const actions = tr.lastElementChild;
            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-sm btn-primary';
            editBtn.style.cssText = 'padding: 4px 8px; margin-left: 4px;';
            editBtn.title = 'دەستکاریکردن (خێرا)';
            editBtn.innerHTML = '<i class="fas fa-edit"></i>';
            editBtn.addEventListener('click', () => editGroupedTreatment(gt));
            const delForm = document.createElement('form');
            delForm.method = 'POST';
            delForm.style.display = 'inline';
            delForm.addEventListener('submit', function (e) {
                if (!confirm('ئایا دڵنیایت لە سڕینەوەی هەموو چارەسەرەکانی ' + gt.treatment_name + '؟')) e.preventDefault();
            });
            let inputs = '<input type="hidden" name="action" value="delete_grouped_treatments">';
            gt.treatment_ids.forEach(tid => { inputs += `<input type="hidden" name="treatment_ids[]" value="${tid}">`; });
            delForm.innerHTML = inputs + '<button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px;"><i class="fas fa-trash"></i></button>';
            actions.appendChild(editBtn);
            actions.appendChild(delForm);
        }
        tbody.insertBefore(tr, inlineRow);
    });

    // bar-chart view (money per treatment)
    const chartView = document.getElementById('treatmentChartView');
    if (chartView && totals) {
        const inner = chartView.querySelector('div');
        if (inner) {
            const colors = ['#0078d4', '#107c10', '#8764b8', '#ff8c00', '#c50f1f', '#038387', '#d13438', '#00b7c3', '#69797e', '#e3008c'];
            const totalForChart = Math.max(parseFloat(totals.total_amount) || 0, 0.01);
            const sorted = order.map(k => groups[k]).slice().sort((a, b) => b.total_price - a.total_price);
            let html = `<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <i class="fas fa-chart-bar" style="color:#0078d4;font-size:18px;"></i>
                <strong style="font-size:15px;">تەوەری چارەسەرەکان بە بڕی پارە</strong>
                <span style="margin-right:auto;font-size:12px;color:var(--text-secondary);">کۆی گشتی: ${fmt2(totals.total_amount)}</span>
            </div>`;
            sorted.forEach((cg, i) => {
                const pct = Math.min(100, Math.round((cg.total_price / totalForChart) * 1000) / 10);
                const color = colors[i % colors.length];
                html += `<div style="margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="width:12px;height:12px;border-radius:3px;background:${color};display:inline-block;flex-shrink:0;"></span>
                            <span style="font-size:13px;font-weight:600;">${escapeHtml(cg.treatment_name)}</span>
                            <span style="font-size:11px;color:var(--text-secondary);">× ${fmt2(cg.total_quantity)}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:12px;font-weight:700;color:${color};">${fmt2(cg.total_price)}</span>
                            <span style="font-size:11px;color:var(--text-secondary);width:38px;text-align:left;">${pct}%</span>
                        </div>
                    </div>
                    <div style="height:10px;background:var(--bg-secondary);border-radius:6px;overflow:hidden;">
                        <div style="height:100%;width:${pct}%;background:${color};border-radius:6px;transition:width 0.6s ease;"></div>
                    </div>
                </div>`;
            });
            inner.innerHTML = html;
        }
    }
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
    updateSurfaceSection();
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
window.showTab = function(tabName, button) {
    const content = document.getElementById('tab-' + tabName);
    if (!content) return;
    document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    content.style.display = 'block';
    if (button) button.classList.add('active');
};

// Switch tab by name (finds its button) — usable from links/redirect hashes
window.switchTab = function(tabName) {
    const btn = document.querySelector('.tab[onclick*="\'' + tabName + '\'"]');
    showTab(tabName, btn);
};

// Open the tab named in the URL hash (#tab-logs, #tab-images, #tab-payments…)
// so redirects after add/update/delete land the user on the right tab.
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash;
    if (hash && hash.startsWith('#tab-')) {
        switchTab(hash.substring(5));
    }
});

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
    const price = parseFloat(option.dataset.price) || 0;

    // Update default price display
    currentDefaultPrice = price;
    document.getElementById('defaultPriceDisplay').textContent = numberFormat(price);

    // Set agreement price to default price initially
    document.getElementById('unitPrice').value = price;

    // Update treatment name
    document.getElementById('treatmentName').value = option.text.split(' (')[0];

    calculateTotal();
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
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add',
            treatment_name: name,
            default_price: price,
            scope: 'tooth',
            category: catEl.value || 'other'
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
            price: parseFloat(d.default_price) || 0
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
    // Reset selection
    selectedArches = [];
    document.querySelectorAll('.arch-select-panel').forEach(p => p.classList.remove('selected'));
    document.getElementById('archTreatmentArchType').value = '';
    document.getElementById('archTeethCountDisplay').textContent = '0';
    document.getElementById('archTotalPriceDisplay').textContent = '0';
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

    // Update teeth count display
    const teethCount = selectedArches.length === 2 ? 32 : (selectedArches.length === 1 ? 16 : 0);
    document.getElementById('archTeethCountDisplay').textContent = teethCount;

    // Show/hide separate prices option (only when both arches selected)
    const separateOption = document.getElementById('separatePriceOption');
    if (selectedArches.length === 2) {
        separateOption.style.display = 'flex';
    } else {
        separateOption.style.display = 'none';
        // If separate mode was selected, switch back to per_tooth
        const currentMode = document.querySelector('input[name="pricing_mode"]:checked')?.value;
        if (currentMode === 'separate') {
            switchPricingMode('per_tooth');
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

    const btn = document.querySelectorAll('.use-default-btn')[1];
    if (btn) {
        btn.style.transform = 'rotate(360deg) scale(1.2)';
        setTimeout(() => {
            btn.style.transform = '';
        }, 300);
    }
}

function calculateArchTotal() {
    const pricingMode = document.querySelector('input[name="pricing_mode"]:checked')?.value || 'per_tooth';
    const teethCount = selectedArches.length === 2 ? 32 : (selectedArches.length === 1 ? 16 : 0);
    let total = 0;

    if (pricingMode === 'per_tooth') {
        const unitPrice = parseFloat(document.getElementById('archUnitPrice').value) || 0;
        total = unitPrice * teethCount;
        // Set hidden prices
        document.getElementById('hiddenUpperPrice').value = unitPrice;
        document.getElementById('hiddenLowerPrice').value = unitPrice;
    } else if (pricingMode === 'total') {
        total = parseFloat(document.getElementById('archTotalAgreedPrice').value) || 0;
        // Calculate per-tooth price
        const perToothPrice = teethCount > 0 ? Math.round(total / teethCount) : 0;
        document.getElementById('calculatedPerToothPrice').textContent = numberFormat(perToothPrice);
        // Set hidden prices (same for both arches)
        document.getElementById('hiddenUpperPrice').value = perToothPrice;
        document.getElementById('hiddenLowerPrice').value = perToothPrice;
    } else if (pricingMode === 'separate') {
        const upperPrice = parseFloat(document.getElementById('upperArchPrice').value) || 0;
        const lowerPrice = parseFloat(document.getElementById('lowerArchPrice').value) || 0;

        // Calculate per-tooth prices for each arch
        const upperPerTooth = Math.round(upperPrice / 16);
        const lowerPerTooth = Math.round(lowerPrice / 16);

        // Total depends on which arches are selected
        if (selectedArches.includes('upper') && selectedArches.includes('lower')) {
            total = upperPrice + lowerPrice;
        } else if (selectedArches.includes('upper')) {
            total = upperPrice;
        } else if (selectedArches.includes('lower')) {
            total = lowerPrice;
        }

        // Set hidden prices
        document.getElementById('hiddenUpperPrice').value = upperPerTooth;
        document.getElementById('hiddenLowerPrice').value = lowerPerTooth;
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
    document.getElementById('perToothPricing').style.display = mode === 'per_tooth' ? 'block' : 'none';
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
    const teethCount = selectedArches.length === 2 ? 32 : (selectedArches.length === 1 ? 16 : 0);

    if (teethCount > 0) {
        const perToothPrice = Math.round(totalPrice / teethCount);
        document.getElementById('calculatedPerToothPrice').textContent = numberFormat(perToothPrice);
        document.getElementById('hiddenUpperPrice').value = perToothPrice;
        document.getElementById('hiddenLowerPrice').value = perToothPrice;
    }

    // Update total display
    document.getElementById('archTotalPriceDisplay').textContent = numberFormat(totalPrice);
}

function calculateSeparateTotal() {
    const upperPrice = parseFloat(document.getElementById('upperArchPrice').value) || 0;
    const lowerPrice = parseFloat(document.getElementById('lowerArchPrice').value) || 0;

    // Calculate per-tooth prices
    const upperPerTooth = Math.round(upperPrice / 16);
    const lowerPerTooth = Math.round(lowerPrice / 16);

    // Set hidden prices
    document.getElementById('hiddenUpperPrice').value = upperPerTooth;
    document.getElementById('hiddenLowerPrice').value = lowerPerTooth;

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

// ===== Inline Table Editing Functions =====
let selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
let currentQuadrantForSelection = null;

function showInlineAddRow() {
    // Hide the row first to reset any previous state
    const row = document.getElementById('inline-add-row');
    row.style.display = 'table-row';

    // Reset form
    document.getElementById('inline-treatment').value = '';
    document.getElementById('inline-quantity').value = '1';
    document.getElementById('inline-price').value = '0';
    document.getElementById('inline-total').textContent = '0';

    // Clear selected teeth
    selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
    updateQuadrantDisplays();

    // Auto-focus on treatment select for faster input
    setTimeout(() => {
        document.getElementById('inline-treatment').focus();
    }, 100);

    // Add keyboard shortcut - Enter to submit
    addInlineKeyboardShortcuts();
}

function inlineUpdatePrice() {
    const select = document.getElementById('inline-treatment');
    const option = select.options[select.selectedIndex];
    const price = option?.dataset.price || 0;
    document.getElementById('inline-price').value = price;
    inlineCalculateTotal();
}

function inlineCalculateTotal() {
    const quantity = parseFloat(document.getElementById('inline-quantity').value) || 0;
    const price = parseFloat(document.getElementById('inline-price').value) || 0;
    const total = quantity * price;
    document.getElementById('inline-total').textContent = numberFormat(total);
}

function openToothSelector(quadrant) {
    currentQuadrantForSelection = quadrant;

    // Open the tooth detail overlay for selection
    const overlay = document.createElement('div');
    overlay.id = 'tooth-selector-overlay';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';

    // Close on overlay click (click outside)
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeToothSelector();
        }
    });

    const modal = document.createElement('div');
    modal.style.cssText = 'background: white; padding: 20px; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);';

    // Build tooth selection grid based on quadrant
    let teeth = [];
    if (quadrant === 'UR') teeth = [18, 17, 16, 15, 14, 13, 12, 11];
    else if (quadrant === 'UL') teeth = [21, 22, 23, 24, 25, 26, 27, 28];
    else if (quadrant === 'LL') teeth = [31, 32, 33, 34, 35, 36, 37, 38];
    else if (quadrant === 'LR') teeth = [48, 47, 46, 45, 44, 43, 42, 41];

    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0;">${getQuadrantName(quadrant)}</h4>
            <span style="font-size: 11px; color: #666; background: #f0f0f0; padding: 4px 10px; border-radius: 12px;">
                <i class="fas fa-mouse-pointer"></i> کلیک بکە
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;">
    `;

    teeth.forEach(toothNum => {
        const palmerNum = palmerNumbers[toothNum] || (toothNum % 10);
        const isSelected = selectedTeeth[quadrant].includes(toothNum);
        html += `
            <button type="button"
                    onclick="toggleToothSelectionFast(${toothNum}, '${quadrant}')"
                    id="tooth-selector-${toothNum}"
                    style="padding: 15px; border: 2px solid ${isSelected ? '#0078D4' : '#ddd'};
                           background: ${isSelected ? '#E3F2FD' : 'white'};
                           border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600;
                           transition: all 0.2s;">
                ${palmerNum}
            </button>
        `;
    });

    html += `</div>`;

    modal.innerHTML = html;
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
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

// Fast toggle - updates immediately and closes selector
function toggleToothSelectionFast(toothNum, quadrant) {
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

    // Auto-update display immediately
    updateQuadrantDisplays();

    // Auto-close selector after selection
    setTimeout(() => {
        closeToothSelector();
    }, 300); // Small delay for visual feedback
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
}

function updateQuadrantDisplays() {
    ['UL', 'UR', 'LL', 'LR'].forEach(quadrant => {
        const cell = document.getElementById('teeth-' + quadrant);
        if (selectedTeeth[quadrant].length > 0) {
            // Show Palmer numbers
            const palmerNums = selectedTeeth[quadrant].map(tooth => palmerNumbers[tooth] || (tooth % 10));
            cell.textContent = palmerNums.join(', ');
        } else {
            cell.textContent = '';
        }
    });
}

function saveInlineTreatment() {
    const treatmentSelect = document.getElementById('inline-treatment');
    const treatmentId = treatmentSelect.value;
    const treatmentName = treatmentSelect.options[treatmentSelect.selectedIndex]?.dataset.name || '';
    const quantity = document.getElementById('inline-quantity').value;
    const unitPrice = document.getElementById('inline-price').value;

    // Validation
    if (!treatmentId) {
        alert('تکایە چارەسەرێک هەڵبژێرە');
        return;
    }

    // Collect all selected teeth
    const allSelectedTeeth = [
        ...selectedTeeth.UL,
        ...selectedTeeth.UR,
        ...selectedTeeth.LL,
        ...selectedTeeth.LR
    ];

    if (allSelectedTeeth.length === 0) {
        alert('تکایە لانیکەم ددانێک هەڵبژێرە');
        return;
    }

    // Create form and submit for each tooth
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';

    // Add CSRF and action
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = CSRF_TOKEN;
    form.appendChild(csrfInput);

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'add_treatment_bulk';
    form.appendChild(actionInput);

    // Add treatment data
    const fields = {
        'treatment_id': treatmentId,
        'treatment_name': treatmentName,
        'quantity': quantity,
        'unit_price': unitPrice,
        'tooth_numbers': JSON.stringify(allSelectedTeeth)
    };

    Object.keys(fields).forEach(key => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

function cancelInlineAdd() {
    document.getElementById('inline-add-row').style.display = 'none';
    selectedTeeth = { UL: [], UR: [], LL: [], LR: [] };
    document.getElementById('inline-treatment').value = '';
    document.getElementById('inline-quantity').value = '1';
    document.getElementById('inline-price').value = '0';
    document.getElementById('inline-total').textContent = '0';
    updateQuadrantDisplays();

    // Remove keyboard shortcuts
    removeInlineKeyboardShortcuts();
}

// Keyboard shortcuts for faster data entry
let inlineKeyHandler = null;

function addInlineKeyboardShortcuts() {
    removeInlineKeyboardShortcuts(); // Remove any existing handler

    inlineKeyHandler = function(e) {
        const row = document.getElementById('inline-add-row');
        if (row && row.style.display !== 'none') {
            // Ctrl+Enter or Cmd+Enter to submit
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveInlineTreatment();
            }
            // Escape to cancel
            else if (e.key === 'Escape') {
                e.preventDefault();
                cancelInlineAdd();
            }
        }
    };

    document.addEventListener('keydown', inlineKeyHandler);
}

function removeInlineKeyboardShortcuts() {
    if (inlineKeyHandler) {
        document.removeEventListener('keydown', inlineKeyHandler);
        inlineKeyHandler = null;
    }
}

// ===== Inline Edit Functions =====
let editingRow = null;
let originalRowContent = null;
let editKeyHandler = null;
let editSelectedTeeth = { UL: [], UR: [], LL: [], LR: [] };

function editGroupedTreatment(treatmentData) {
    // If already editing, cancel first
    if (editingRow) {
        cancelEditTreatment();
    }

    // Find the row that contains this edit button
    const rows = document.querySelectorAll('#tab-treatments tbody tr:not(#inline-add-row)');
    let targetRow = null;

    // Find the row by matching treatment name
    rows.forEach(row => {
        const nameCell = row.querySelector('td:nth-child(2)');
        if (nameCell && nameCell.textContent.trim() === treatmentData.treatment_name.trim()) {
            targetRow = row;
        }
    });

    if (!targetRow) return;

    // Store original content
    editingRow = targetRow;
    originalRowContent = targetRow.innerHTML;

    // Get current values
    const quantity = treatmentData.total_quantity;
    const unitPrice = treatmentData.unit_price;

    // Initialize edit selected teeth from treatmentData
    editSelectedTeeth = {
        UL: treatmentData.upper_left_fdi || [],
        UR: treatmentData.upper_right_fdi || [],
        LL: treatmentData.lower_left_fdi || [],
        LR: treatmentData.lower_right_fdi || []
    };

    // Build editable row
    const rowNum = targetRow.querySelector('td:first-child').textContent;
    const treatmentName = treatmentData.treatment_name;

    targetRow.innerHTML = `
        <td style="text-align: center; font-weight: 600; background: #FFF9E6;">${rowNum}</td>
        <td style="background: #FFF9E6; font-weight: 600;">${escapeHtml(treatmentName)}</td>
        <td style="text-align: center; background: #E8F5E9; cursor: pointer; padding: 8px;" onclick="openEditToothSelector('UL')" id="edit-cell-UL">
            <span id="edit-teeth-UL" style="font-weight: 600; font-size: 12px;">${treatmentData.upper_left ? treatmentData.upper_left.join(', ') : ''}</span>
        </td>
        <td style="text-align: center; background: #E3F2FD; cursor: pointer; padding: 8px;" onclick="openEditToothSelector('UR')" id="edit-cell-UR">
            <span id="edit-teeth-UR" style="font-weight: 600; font-size: 12px;">${treatmentData.upper_right ? treatmentData.upper_right.join(', ') : ''}</span>
        </td>
        <td style="text-align: center; background: #F3E5F5; cursor: pointer; padding: 8px;" onclick="openEditToothSelector('LL')" id="edit-cell-LL">
            <span id="edit-teeth-LL" style="font-weight: 600; font-size: 12px;">${treatmentData.lower_left ? treatmentData.lower_left.join(', ') : ''}</span>
        </td>
        <td style="text-align: center; background: #FFF3E0; cursor: pointer; padding: 8px;" onclick="openEditToothSelector('LR')" id="edit-cell-LR">
            <span id="edit-teeth-LR" style="font-weight: 600; font-size: 12px;">${treatmentData.lower_right ? treatmentData.lower_right.join(', ') : ''}</span>
        </td>
        <td style="text-align: center; background: #FFF9E6;">
            <input type="number" id="edit-quantity" class="form-control"
                   value="${quantity}" step="1" min="1"
                   style="width: 100%; padding: 4px 8px; font-size: 12px; text-align: center;"
                   onchange="updateEditTotal()">
        </td>
        <td style="text-align: right; background: #FFF9E6;">
            <input type="number" id="edit-price" class="form-control"
                   value="${unitPrice}" step="0.01" min="0"
                   style="width: 100%; padding: 4px 8px; font-size: 12px; text-align: right;"
                   onchange="updateEditTotal()">
        </td>
        <td style="text-align: right; background: #FFF9E6; font-weight: 700; color: #d32f2f;">
            <span id="edit-total">${numberFormat(quantity * unitPrice)}</span>
        </td>
        <td style="text-align: center; background: #FFF9E6;">
            <button type="button" class="btn btn-sm btn-success" onclick="saveEditedTreatment(${JSON.stringify(treatmentData).replace(/"/g, '&quot;')})"
                    style="padding: 4px 8px; margin-left: 4px;"
                    title="پاشەکەوت (Ctrl+Enter)">
                <i class="fas fa-check"></i>
            </button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEditTreatment()"
                    style="padding: 4px 8px;"
                    title="هەڵوەشاندنەوە (Esc)">
                <i class="fas fa-times"></i>
            </button>
        </td>
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

    if (allSelectedTeeth.length === 0) {
        alert('تکایە لانیکەم ددانێک هەڵبژێرە');
        return;
    }

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
    teethInput.value = JSON.stringify(allSelectedTeeth);
    form.appendChild(teethInput);

    document.body.appendChild(form);
    form.submit();
}

function cancelEditTreatment() {
    if (editingRow && originalRowContent) {
        editingRow.innerHTML = originalRowContent;
    }
    editingRow = null;
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

function openEditToothSelector(quadrant) {
    currentQuadrantForSelection = quadrant;

    // Open the tooth detail overlay for selection
    const overlay = document.createElement('div');
    overlay.id = 'edit-tooth-selector-overlay';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';

    // Close on overlay click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeEditToothSelector();
        }
    });

    const modal = document.createElement('div');
    modal.style.cssText = 'background: white; padding: 20px; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);';

    // Build tooth selection grid based on quadrant
    let teeth = [];
    if (quadrant === 'UR') teeth = [18, 17, 16, 15, 14, 13, 12, 11];
    else if (quadrant === 'UL') teeth = [21, 22, 23, 24, 25, 26, 27, 28];
    else if (quadrant === 'LL') teeth = [31, 32, 33, 34, 35, 36, 37, 38];
    else if (quadrant === 'LR') teeth = [48, 47, 46, 45, 44, 43, 42, 41];

    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="margin: 0;">${getQuadrantName(quadrant)}</h4>
            <span style="font-size: 11px; color: #666; background: #f0f0f0; padding: 4px 10px; border-radius: 12px;">
                <i class="fas fa-mouse-pointer"></i> کلیک بکە
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;">
    `;

    teeth.forEach(toothNum => {
        const palmerNum = palmerNumbers[toothNum] || (toothNum % 10);
        const isSelected = editSelectedTeeth[quadrant].includes(toothNum);
        html += `
            <button type="button"
                    onclick="toggleEditToothSelectionFast(${toothNum}, '${quadrant}')"
                    id="edit-tooth-selector-${toothNum}"
                    style="padding: 15px; border: 2px solid ${isSelected ? '#0078D4' : '#ddd'};
                           background: ${isSelected ? '#E3F2FD' : 'white'};
                           border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600;
                           transition: all 0.2s;">
                ${palmerNum}
            </button>
        `;
    });

    html += `</div>`;

    modal.innerHTML = html;
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
}

function toggleEditToothSelectionFast(toothNum, quadrant) {
    const index = editSelectedTeeth[quadrant].indexOf(toothNum);
    const btn = document.getElementById('edit-tooth-selector-' + toothNum);

    if (index > -1) {
        // Remove
        editSelectedTeeth[quadrant].splice(index, 1);
        btn.style.border = '2px solid #ddd';
        btn.style.background = 'white';
    } else {
        // Add
        editSelectedTeeth[quadrant].push(toothNum);
        btn.style.border = '2px solid #0078D4';
        btn.style.background = '#E3F2FD';
    }

    updateEditQuadrantDisplays();

    // Auto-close after selection
    setTimeout(() => {
        closeEditToothSelector();
    }, 300);
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
}

// Make all functions globally accessible
window.closeToothDetail = closeToothDetail;
window.addTreatmentForTooth = addTreatmentForTooth;
window.updateTreatmentPrice = updateTreatmentPrice;
window.calculateTotal = calculateTotal;
window.editTreatment = editTreatment;
window.numberFormat = numberFormat;
window.escapeHtml = escapeHtml;
window.showInlineAddRow = showInlineAddRow;
window.inlineUpdatePrice = inlineUpdatePrice;
window.inlineCalculateTotal = inlineCalculateTotal;
window.openToothSelector = openToothSelector;
window.toggleToothSelection = toggleToothSelection;
window.toggleToothSelectionFast = toggleToothSelectionFast;
window.confirmToothSelection = confirmToothSelection;
window.closeToothSelector = closeToothSelector;
window.saveInlineTreatment = saveInlineTreatment;
window.cancelInlineAdd = cancelInlineAdd;
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
        amountInput.max = <?= max(1, $isAdmin ? $remaining : $unrequestedBalance) ?>; // back to the case cap
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
<?php if ($auth->isDoctor()): ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
