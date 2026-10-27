<?php
/**
 * An employee's documents: the only door to the files.
 *
 *   GET  ?id=N           the file, inline (hr.documents.view); &dl=1 downloads it
 *   POST action=upload   employee_id, doc_type, title, expiry_date, files[]
 *                        (hr.documents.manage). AJAX answers JSON.
 *   POST action=delete   doc_id (hr.documents.manage)
 *
 * After a POST it goes back to the page it came from (from=edit → the edit
 * page, else the profile), on its «بەڵگەنامەکان» tab.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/HrDocument.php';

$docs = new HrDocument();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hr_require('hr.documents.view');
    $doc = $docs->find((int)($_GET['id'] ?? 0));
    $path = $doc ? HrDocument::path($doc) : null;
    if (!$path) {
        http_response_code(404);
        exit('بەڵگەنامە نەدۆزرایەوە');
    }
    $ext = HrDocument::MIME[$doc['mime_type']] ?? 'bin';
    $base = trim(preg_replace('/[^\p{L}\p{N}_.-]+/u', '-', pathinfo((string)$doc['original_name'], PATHINFO_FILENAME)), '-');
    $filename = ($base !== '' ? $base : HrDocument::typeLabel($doc['doc_type'])) . '.' . $ext;
    // config.php's gzip buffer must not wrap a binary file.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline')
        . '; filename="' . $doc['doc_type'] . '.' . $ext . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    // A document never changes: a new upload is a new id.
    header('Cache-Control: private, max-age=604800');
    header_remove('Pragma');
    header_remove('Expires');
    readfile($path);
    exit;
}

Csrf::require();
hr_require('hr.documents.manage');
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$action = (string)($_POST['action'] ?? '');
$employeeId = (int)($_POST['employee_id'] ?? 0);
$message = '';
$ok = false;

try {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Over post_max_size PHP drops the whole request, fields and all
        // (the CSRF token still arrives in the fetch() header).
        throw new InvalidArgumentException('فایلەکان زۆر گەورەن (زۆرترین: ' . HrDocument::formatSize(HrDocument::uploadLimit()) . ')');
    }
    if ($action === 'upload') {
        $files = $_FILES['files'] ?? null;
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        if ($count === 0) {
            throw new InvalidArgumentException('فایلێک هەڵبژێرە');
        }
        $type = (string)($_POST['doc_type'] ?? '');
        $expiry = hr_date($_POST['expiry_date'] ?? null);
        for ($i = 0; $i < $count; $i++) {
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                $file[$k] = $files[$k][$i] ?? null;
            }
            $docs->store($employeeId, $type, $file, $_POST['title'] ?? null, $expiry, $hrUserId);
        }
        $ok = true;
        $message = $count === 1
            ? '«' . HrDocument::typeLabel($type) . '» بارکرا'
            : $count . ' فایلی «' . HrDocument::typeLabel($type) . '» بارکران';
    } elseif ($action === 'delete') {
        $doc = $docs->delete((int)($_POST['doc_id'] ?? 0));
        if (!$doc) {
            throw new InvalidArgumentException('بەڵگەنامە نەدۆزرایەوە');
        }
        $employeeId = (int)$doc['employee_id'];
        $ok = true;
        $message = '«' . HrDocument::typeLabel($doc['doc_type']) . '» سڕایەوە';
    } else {
        throw new InvalidArgumentException('کردارێکی نەناسراو');
    }
} catch (InvalidArgumentException | RuntimeException $ex) {
    $message = $ex->getMessage();
}

if ($isAjax) {
    if ($ok) {
        hr_flash('success', $message);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
hr_flash($ok ? 'success' : 'error', $message);
$page = ($_POST['from'] ?? '') === 'edit' ? 'employee-edit.php' : 'employee.php';
hr_redirect(APP_URL . '/hr/' . $page . '?id=' . $employeeId . '#docs');
