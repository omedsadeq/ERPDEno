<?php
/**
 * مەواد بۆ دکتۆر API — save and cancel a voucher.
 *
 * All stock and expense logic lives in ErpDoctorIssue, which owns the
 * transaction; this endpoint only authenticates, checks permissions and the
 * warehouse, decodes and reports.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/ErpDoctorIssue.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp_di_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erp_di_json(['ok' => false, 'error' => 'POST required'], 405);
}
Csrf::require();

/** The voucher arrives as JSON so its nested lines survive intact. */
function erp_di_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

try {
    switch ($action) {
        case 'save': {
            if (!erp_can('erp.doctor_issue.create')) {
                erp_di_json(['ok' => false, 'error' => 'ڕێگەت پێنەدراوە مەواد بە دکتۆر بدەیت'], 403);
            }
            $data = erp_di_payload();
            // The warehouse came from a list this user may not have been
            // offered; checked again so a hand-written POST cannot empty a
            // store the user does not work in.
            try {
                erp_assert_warehouse((int)($data['warehouse_id'] ?? 0));
            } catch (RuntimeException $e) {
                erp_di_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            $res = ErpDoctorIssue::create($data);
            erp_di_json(['ok' => true] + $res);
        }

        case 'set_price_mode': {
            // The clinic's one saved choice: sale price or purchase price.
            if (!erp_can('erp.settings')) {
                erp_di_json(['ok' => false, 'error' => 'ڕێگەت پێنەدراوە ڕێکخستنەکانی کۆگا بگۆڕیت'], 403);
            }
            ErpDoctorIssue::setPriceMode((string)($_POST['mode'] ?? ''));
            erp_di_json(['ok' => true, 'mode' => ErpDoctorIssue::priceMode()]);
        }

        case 'cancel': {
            if (!erp_can('erp.doctor_issue.cancel')) {
                erp_di_json(['ok' => false, 'error' => 'ڕێگەت پێنەدراوە وەسڵ هەڵبوەشێنیتەوە'], 403);
            }
            $res = ErpDoctorIssue::cancel((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
            erp_di_json(['ok' => true] + $res);
        }

        default:
            erp_di_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (RuntimeException $e) {
    // Business refusals (shortage, wrong doctor, already cancelled) are for
    // the operator to read.
    erp_di_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[erp doctor-issues] ' . $e->getMessage());
    erp_di_json(['ok' => false, 'error' => 'هەڵەیەک ڕوویدا لە پاشەکەوتکردن'], 500);
}
