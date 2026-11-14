<?php
/**
 * The visits behind one cell of the visits report's matrix, as JSON for the
 * dialog on visits-report.php: {ok, title, html}. Same access and filters as
 * that page (includes/VisitReportDetail.php has the rules).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/VisitReportFilters.php';
require_once __DIR__ . '/includes/VisitReportDetail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'تکایە دووبارە بچۆرە ژوورەوە'], JSON_UNESCAPED_UNICODE);
    exit;
}
// As on visits-report.php: a doctor must not see other doctors' visits.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دەستگەیشتن نییە'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filters = dh_visit_report_filters($_GET);
$cell = dh_visit_report_detail_cell((string) ($_GET['row'] ?? ''), (string) ($_GET['doc'] ?? ''));
if ($cell === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'خانەکە نەناسرایەوە'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$visits = dh_visit_report_cell_visits($db, $filters['from'], $filters['to'], $filters['visit_type'], $cell);
echo json_encode([
    'ok' => true,
    'title' => dh_visit_report_detail_title($db, $cell),
    'count' => count($visits),
    'html' => dh_visit_report_detail_body($visits, $filters['from'], $filters['to']),
], JSON_UNESCAPED_UNICODE);
