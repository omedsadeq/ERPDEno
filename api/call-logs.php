<?php
/**
 * Call Logs API Endpoint
 * ئی‌پی‌ئایی تۆمارکردنی پەیوەندیکردن
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DebtCollection.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'نەچووە ژوورەوە']);
    exit;
}

// Admin, accountant and doctors may use debt collection. A doctor is scoped to
// their OWN patients: every patient_id action below is ownership-checked, and
// the auto-assign queue (start_call) is filtered to their patients.
if (!$auth->isAdmin() && !$auth->isAccountant() && !$auth->isDoctor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دەستگەیشتنت نییە']);
    exit;
}
$isDoctorScoped = $auth->isDoctor() && !$auth->isAdmin();

$debtCollection = new DebtCollection();
$currentUser = $auth->getCurrentUser();
$action = $_REQUEST['action'] ?? '';

// CSRF: protect state-changing actions (reads stay open).
if (!in_array($action, ['list', 'get', 'get_logs', 'get_history', 'search', 'view', 'get_locks', 'live_status', ''], true)) {
    Csrf::require();
}

// Doctor scope: a doctor may only act on their OWN patients. Any action that
// carries a patient_id is ownership-checked here (log_call, lock/release,
// toggle_optout, get_history, ...). start_call is scoped separately.
if ($isDoctorScoped) {
    $guardPid = intval($_REQUEST['patient_id'] ?? 0);
    if ($guardPid > 0) {
        AccessGuard::requirePatientWork($guardPid, true);
    }
}

try {
    switch ($action) {
        case 'log_call':
            $patientId = intval($_POST['patient_id'] ?? 0);
            $callStatus = trim($_POST['call_status'] ?? '');
            $reportNote = trim($_POST['report_note'] ?? '');
            $nextFollowUp = trim($_POST['next_follow_up'] ?? '') ?: null;
            $promisedAmount = trim($_POST['promised_amount'] ?? '');
            $promisedDate = trim($_POST['promised_date'] ?? '') ?: null;

            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }

            $validStatuses = array_keys(DebtCollection::getCallStatusLabels());
            if (!in_array($callStatus, $validStatuses)) {
                throw new Exception('دۆخی پەیوەندی نادروستە');
            }

            // Promise fields only make sense with a "promised to pay" outcome
            if ($callStatus !== 'promised_to_pay') {
                $promisedAmount = '';
                $promisedDate = null;
            }
            if ($promisedDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $promisedDate)) {
                $promisedDate = null;
            }

            $logId = $debtCollection->logCall(
                $patientId,
                $currentUser['id'],
                $callStatus,
                $reportNote,
                $nextFollowUp,
                $promisedAmount !== '' ? $promisedAmount : null,
                $promisedDate
            );

            // Call finished — free the patient for colleagues
            $debtCollection->releaseLock($patientId, $currentUser['id']);

            echo json_encode([
                'success' => true,
                'message' => 'پەیوەندی تۆمارکرا',
                'log_id' => $logId,
                'call_status' => $callStatus
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'start_call':
            // "Start Calling": assign the highest-priority unlocked debtor to me
            // (a doctor only ever gets one of their OWN patients).
            $next = $debtCollection->getNextToCall($currentUser['id'], $isDoctorScoped ? (int)$currentUser['id'] : null);
            if (!$next) {
                echo json_encode([
                    'success' => true,
                    'patient' => null,
                    'message' => 'هیچ نەخۆشێک لە لیستی ئەمڕۆدا نەماوە — دەستخۆش! 🎉'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode([
                'success' => true,
                'patient' => [
                    'id' => (int)$next['patient_id'],
                    'name' => $next['patient_name'],
                    'phone' => $next['phone'] ?? '',
                    'debt' => (float)$next['total_debt'],
                    'priority' => DebtCollection::callPriority($next)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'lock_patient':
            $patientId = intval($_POST['patient_id'] ?? 0);
            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }
            $ok = $debtCollection->acquireLock($patientId, $currentUser['id']);
            echo json_encode([
                'success' => $ok,
                'message' => $ok ? '' : 'هاوکارێکت ئێستا خەریکی ئەم نەخۆشەیە'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'release_lock':
            $patientId = intval($_POST['patient_id'] ?? 0);
            if ($patientId) {
                $debtCollection->releaseLock($patientId, $currentUser['id']);
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_locks':
            echo json_encode([
                'success' => true,
                'locks' => $debtCollection->getActiveLocks(),
                'me' => (int)$currentUser['id']
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'live_status':
            echo json_encode(array_merge(
                ['success' => true],
                $debtCollection->getLiveStatus()
            ), JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle_optout':
            $patientId = intval($_POST['patient_id'] ?? 0);
            $optOut = intval($_POST['opt_out'] ?? 0) === 1;
            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }
            $debtCollection->setCallOptOut($patientId, $optOut);
            AuditLog::log($optOut ? 'debt_call_optout' : 'debt_call_optin', 'patients', $patientId,
                null, ['debt_call_optout' => $optOut ? 1 : 0]);
            echo json_encode([
                'success' => true,
                'message' => $optOut ? 'نەخۆش لە لیستی پەیوەندی لابرا' : 'نەخۆش گەڕایەوە بۆ لیستی پەیوەندی'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_history':
            $patientId = intval($_GET['patient_id'] ?? 0);
            if (!$patientId) {
                throw new Exception('ناسنامەی نەخۆش پێویستە');
            }

            $history = $debtCollection->getCallHistory($patientId);
            $labels = DebtCollection::getCallStatusLabels();
            $colors = DebtCollection::getCallStatusColors();

            echo json_encode([
                'success' => true,
                'data' => $history,
                'labels' => $labels,
                'colors' => $colors
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception('کرداری نادیار');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
