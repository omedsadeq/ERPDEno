<?php
/**
 * Approval Center API — read-only feed for the accounting approval queues.
 * ئایپیئای وەرگرتنی داواکارییە چاوەڕوانەکانی ژمێریاری
 *
 * Pure GET dispatch onto ApprovalQueue (Task 9) — no SQL and no business
 * logic here beyond what ApprovalQueue itself already provides.
 *
 * Actions:
 *   counts     — badge/tile counts across the five queues.
 *   list       — one queue's rows (tab=all|invoices|payments|installments|xray|station|history|approved),
 *                optionally narrowed to one source with kind=invoice|archwork|payment|installment|xray|station.
 *   new_since  — how many/which rows arrived after the client's last poll.
 *   detail     — full drawer payload for one row.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/ApprovalQueue.php';

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

function ac_get_str($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

$db = Database::getInstance();
$queue = new ApprovalQueue();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'counts':
            // Always live — this is the polling-facing read path, and each
            // request is its own PHP process anyway (no cross-request
            // staleness risk), so there's no reason to trust a memo here.
            echo json_encode(['ok' => true, 'counts' => $queue->counts(true)], JSON_UNESCAPED_UNICODE);
            break;

        case 'list':
            $tab = ac_get_str('tab', 'all');
            // kind= is the جۆر combobox. Whitelisted here as well as in
            // ApprovalQueue::wantsKind() so an unknown value is dropped on the
            // way in rather than quietly carried through the filter array.
            $kind = ac_get_str('kind');
            $filters = [
                'q'         => ac_get_str('q'),
                'doctor_id' => (int)($_GET['doctor_id'] ?? 0),
                'date_from' => ac_get_str('date_from'),
                'date_to'   => ac_get_str('date_to'),
                'kind'      => in_array($kind, ApprovalQueue::KINDS, true) ? $kind : '',
                // ⇅ نوێ↔کۆن. Anything but an explicit 'asc' means newest first,
                // which is the default every view is built around.
                'sort'      => ac_get_str('sort') === 'asc' ? 'asc' : 'desc',
                // پەسەندکراو tab: 🤖 خۆکار / ✋ دەستی ('' = both).
                'approval'  => in_array(ac_get_str('approval'), ['auto', 'manual'], true) ? ac_get_str('approval') : '',
            ];
            echo json_encode([
                'ok'          => true,
                'rows'        => $queue->list($tab, $filters),
                'counts'      => $queue->counts(true), // live — see the 'counts' case above
                'server_time' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            break;

        case 'new_since':
            $since = ac_get_str('since');
            if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
                echo json_encode(['ok' => false, 'error' => 'بەرواری نادروست'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $since = $queue->newSince($since);
            echo json_encode(['ok' => true, 'count' => $since['count'], 'uids' => $since['uids']], JSON_UNESCAPED_UNICODE);
            break;

        case 'detail':
            $kind = ac_get_str('kind');
            $id = (int)($_GET['id'] ?? 0);
            $detail = $queue->detail($kind, $id);
            if (empty($detail['success'])) {
                echo json_encode(['ok' => false, 'error' => $detail['message'] ?? 'نەدۆزرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            unset($detail['success']);
            echo json_encode(['ok' => true] + $detail, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            break;

        // «چی هەڵدەوەشێتەوە؟» — read-only preview of the transactions a
        // reopen/delete on an APPROVED row would reverse. The پەسەندکراو tab's
        // confirm dialog shows nothing but this, so the accountant sees the
        // money before pressing the button, not after
        // (classes/ApprovalReversal.php does the reversing itself).
        case 'reversal_preview': {
            require_once __DIR__ . '/../classes/ApprovalReversal.php';
            $kind = ac_get_str('kind');
            $id = (int)($_GET['id'] ?? 0);
            $preview = (new ApprovalReversal())->preview($kind, $id);
            if (empty($preview['success'])) {
                echo json_encode(['ok' => false, 'error' => $preview['message'] ?? 'نەدۆزرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            unset($preview['success']);
            echo json_encode(['ok' => true] + $preview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            break;
        }

        // ئامانەت — the counter takes money in the morning from a patient who
        // has no request in any queue yet, so the Approval Center's own ?q=
        // (which filters ROWS of existing requests) can never find them. This
        // searches PATIENTS instead.
        case 'patient_search': {
            require_once __DIR__ . '/../classes/PatientAdvance.php';
            $q = ac_get_str('q');
            if (mb_strlen($q) < 2) {
                echo json_encode(['ok' => true, 'rows' => []], JSON_UNESCAPED_UNICODE);
                break;
            }
            $like = '%' . $q . '%';
            $rows = $db->fetchAll(
                "SELECT p.id, p.full_name, p.phone, p.patient_code
                 FROM patients p
                 WHERE p.full_name LIKE ? OR p.phone LIKE ? OR p.patient_code LIKE ?
                 ORDER BY p.full_name
                 LIMIT 20",
                [$like, $like, $like]
            );
            $advance = new PatientAdvance();
            foreach ($rows as &$row) {
                $row['id'] = (int)$row['id'];
                $row['advance_available'] = $advance->balanceOf((int)$row['id']);
            }
            unset($row);
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            break;
        }

        case 'advance_summary': {
            require_once __DIR__ . '/../classes/PatientAdvance.php';
            $patientId = (int)($_GET['patient_id'] ?? 0);
            if ($patientId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'نەخۆش دیاری نەکراوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $advance = new PatientAdvance();
            $patient = $db->fetch(
                'SELECT id, full_name, phone, patient_code FROM patients WHERE id = :id',
                ['id' => $patientId]
            );
            if (!$patient) {
                echo json_encode(['ok' => false, 'error' => 'نەخۆش نەدۆزرایەوە'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode([
                'ok'        => true,
                'patient'   => $patient,
                'summary'   => $advance->summaryFor($patientId),
                'advances'  => $advance->listFor($patientId),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            break;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'کردارێکی نادروست'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('approval-center api error [' . $action . ']: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'], JSON_UNESCAPED_UNICODE);
}
